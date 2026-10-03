<?php
// app/Services/FefoService.php - First Expiry First Out (FEFO) Allocation & Concurrency Engine

namespace Pharmacy\Services;

use PDO;
use Exception;
use InvalidArgumentException;

class FefoService
{
    private PDO $pdo;
    private StockLedgerService $ledgerService;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ledgerService = new StockLedgerService($pdo);
    }

    /**
     * Preview FEFO allocation for counter sale, prescription, or IPD dispensing.
     * READ-ONLY: strictly DOES NOT modify any database stock.
     *
     * @param int $medicineId
     * @param int $requiredQty
     * @return array Array of allocated batches: [['batch_id' => X, 'batch_number' => Y, 'quantity' => N, 'sale_price' => P, ...]]
     * @throws Exception If insufficient non-expired stock exists
     */
    public function previewAllocation(int $medicineId, int $requiredQty, ?int $preferredBatchId = null): array
    {
        return $this->allocate($medicineId, $requiredQty, false, $preferredBatchId);
    }

    /**
     * Get active, non-expired batches for a medicine ordered by earliest expiry first (FEFO).
     */
    public function getAvailableBatches(int $medicineId): array
    {
        $batchSql = "
            SELECT batch_id, batch_number, manufacturing_date, expiry_date,
                   purchase_price, mrp, sale_price, quantity_available, shelf_location
            FROM medicine_batches
            WHERE medicine_id = ?
              AND status = 'Active'
              AND quantity_available > 0
              AND expiry_date >= CURDATE()
            ORDER BY expiry_date ASC, batch_id ASC
        ";
        $bStmt = $this->pdo->prepare($batchSql);
        $bStmt->execute([$medicineId]);
        return $bStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lock and compute FEFO allocation with ACID row-level locking (SELECT ... FOR UPDATE).
     * Must be called inside an active database transaction if $forUpdate is true.
     * DOES NOT decrement stock yet; returns the allocation map for execution.
     *
     * @param int $medicineId
     * @param int $requiredQty
     * @param bool $forUpdate
     * @param int|null $preferredBatchId
     * @return array
     * @throws Exception
     */
    public function allocate(int $medicineId, int $requiredQty, bool $forUpdate = true, ?int $preferredBatchId = null): array
    {
        if ($requiredQty <= 0) {
            throw new InvalidArgumentException("Allocation quantity must be greater than zero.");
        }

        // 1. Verify master medicine is active
        $medSql = "SELECT medicine_id, medicine_name, stock_quantity, status FROM medicines WHERE medicine_id = ? AND deleted_at IS NULL";
        if ($forUpdate) {
            $medSql .= " FOR UPDATE";
        }
        $mStmt = $this->pdo->prepare($medSql);
        $mStmt->execute([$medicineId]);
        $med = $mStmt->fetch(PDO::FETCH_ASSOC);

        if (!$med) {
            throw new Exception("Medicine #{$medicineId} not found or has been archived.");
        }

        if ($med['status'] !== 'Active') {
            throw new Exception("Medicine '{$med['medicine_name']}' is not Active and cannot be dispensed.");
        }

        // 2. Fetch active, non-expired batches ordered by earliest expiry first
        $batchSql = "
            SELECT batch_id, batch_number, manufacturing_date, expiry_date,
                   purchase_price, mrp, sale_price, quantity_available, shelf_location
            FROM medicine_batches
            WHERE medicine_id = ?
              AND status = 'Active'
              AND quantity_available > 0
              AND expiry_date >= CURDATE()
            ORDER BY expiry_date ASC, batch_id ASC
        ";
        if ($forUpdate) {
            $batchSql .= " FOR UPDATE";
        }

        $bStmt = $this->pdo->prepare($batchSql);
        $bStmt->execute([$medicineId]);
        $batches = $bStmt->fetchAll(PDO::FETCH_ASSOC);

        // If a specific batch is preferred, prioritize it first while keeping remaining in FEFO order
        if ($preferredBatchId !== null && $preferredBatchId > 0) {
            usort($batches, function($a, $b) use ($preferredBatchId) {
                if ((int)$a['batch_id'] === $preferredBatchId) return -1;
                if ((int)$b['batch_id'] === $preferredBatchId) return 1;
                $expCmp = strcmp($a['expiry_date'], $b['expiry_date']);
                if ($expCmp !== 0) return $expCmp;
                return (int)$a['batch_id'] <=> (int)$b['batch_id'];
            });
        }

        $remainingNeeded = $requiredQty;
        $allocations = [];
        $totalValidAvailable = 0;

        foreach ($batches as $b) {
            $avail = (int)$b['quantity_available'];
            $totalValidAvailable += $avail;

            if ($remainingNeeded > 0) {
                $take = min($avail, $remainingNeeded);
                $allocations[] = [
                    'batch_id'           => (int)$b['batch_id'],
                    'medicine_id'        => $medicineId,
                    'medicine_name'      => $med['medicine_name'],
                    'batch_number'       => $b['batch_number'],
                    'expiry_date'        => $b['expiry_date'],
                    'allocated_quantity' => $take,
                    'quantity'           => $take,
                    'purchase_price'     => (float)$b['purchase_price'],
                    'mrp'                => (float)$b['mrp'],
                    'sale_price'         => (float)$b['sale_price'],
                    'shelf_location'     => $b['shelf_location'],
                    'quantity_available' => $avail
                ];
                $remainingNeeded -= $take;
            }
        }

        if ($remainingNeeded > 0) {
            throw new Exception("Insufficient non-expired stock for '{$med['medicine_name']}'. Requested: {$requiredQty}, Available: {$totalValidAvailable}.");
        }

        return $allocations;
    }

    /**
     * Atomically execute stock deduction for an array of pre-computed FEFO allocations.
     * MUST be called within an active transaction.
     *
     * @param array $allocations Array returned by allocate()
     * @param string $transactionType e.g. 'COUNTER_SALE', 'PRESCRIPTION_SALE', 'IPD_SALE', 'IPD_INDENT'
     * @param int|null $refId
     * @param string|null $refNo
     * @param int|null $userId
     * @param string $reason
     * @throws Exception
     */
    public function executeDeduction(
        array $allocations,
        string $transactionType,
        ?int $refId,
        ?string $refNo,
        ?int $userId,
        string $reason
    ): void {
        foreach ($allocations as $item) {
            $batchId = (int)$item['batch_id'];
            $medicineId = (int)$item['medicine_id'];
            $qty = (int)($item['allocated_quantity'] ?? $item['quantity'] ?? 0);

            if ($qty <= 0) {
                continue;
            }

            // Lock batch and verify stock
            $stmt = $this->pdo->prepare("
                SELECT quantity_available, purchase_price, sale_price 
                FROM medicine_batches 
                WHERE batch_id = ? 
                FOR UPDATE
            ");
            $stmt->execute([$batchId]);
            $b = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$b) {
                throw new Exception("Batch #{$batchId} not found during stock deduction.");
            }

            $currentAvail = (int)$b['quantity_available'];
            if ($currentAvail < $qty) {
                throw new Exception("Stock race condition detected on Batch #{$batchId}. Requested: {$qty}, Available: {$currentAvail}.");
            }

            $newAvail = $currentAvail - $qty;
            $newStatus = ($newAvail === 0) ? 'Depleted' : 'Active';

            // Decrement batch
            $updBatch = $this->pdo->prepare("
                UPDATE medicine_batches 
                SET quantity_available = ?, status = ?, updated_at = NOW() 
                WHERE batch_id = ?
            ");
            $updBatch->execute([$newAvail, $newStatus, $batchId]);

            // Record immutable ledger entry (captures true balance_before)
            $this->ledgerService->recordEntry(
                $medicineId,
                $batchId,
                $transactionType,
                -$qty,
                (float)$b['purchase_price'],
                (float)$b['sale_price'],
                $refId,
                $refNo,
                $userId,
                $reason
            );

            // Decrement master medicine aggregate stock
            $updMed = $this->pdo->prepare("
                UPDATE medicines 
                SET stock_quantity = stock_quantity - ?, updated_at = NOW() 
                WHERE medicine_id = ?
            ");
            $updMed->execute([$qty, $medicineId]);
        }
    }
}
