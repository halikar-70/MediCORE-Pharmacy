<?php
// app/Services/StockLedgerService.php - Immutable Inventory Ledger & Stock Reconciliation

namespace Pharmacy\Services;

use PDO;
use Exception;
use InvalidArgumentException;

class StockLedgerService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Append an immutable stock transaction to the ledger.
     * Guaranteed forward auditability. Direct UPDATE or DELETE on ledger is strictly forbidden.
     *
     * @param int $medicineId
     * @param int|null $batchId
     * @param string $transactionType e.g. 'OPENING_STOCK', 'PURCHASE', 'PURCHASE_RETURN', 'COUNTER_SALE', 'PRESCRIPTION_SALE', 'IPD_SALE', 'IPD_INDENT', 'ADJUSTMENT', 'DAMAGE', 'EXPIRY'
     * @param int $quantityChange Positive for inward stock, negative for outward stock
     * @param float $unitCost
     * @param float $unitPrice
     * @param int|null $refId
     * @param string|null $refNo
     * @param int|null $userId
     * @param string|null $reason
     * @return int Ledger record ID
     */
    public function recordEntry(
        int $medicineId,
        ?int $batchId,
        string $transactionType,
        int $quantityChange,
        float $unitCost = 0.00,
        float $unitPrice = 0.00,
        ?int $refId = null,
        ?string $refNo = null,
        ?int $userId = null,
        ?string $reason = null
    ): int {
        if ($quantityChange === 0) {
            throw new InvalidArgumentException("Ledger entry cannot have a zero quantity change.");
        }

        // Get current aggregate balance for this medicine
        $balStmt = $this->pdo->prepare("SELECT stock_quantity FROM medicines WHERE medicine_id = ?");
        $balStmt->execute([$medicineId]);
        $currentBalance = (int)$balStmt->fetchColumn();

        $balanceBefore = $currentBalance;
        $balanceAfter = $currentBalance + $quantityChange;

        $stmt = $this->pdo->prepare("
            INSERT INTO pharmacy_stock_ledger (
                medicine_id, batch_id, transaction_type, reference_type, reference_id,
                reference_no, quantity_change, balance_before, balance_after,
                unit_cost, unit_price, reason, created_by, created_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, NOW()
            )
        ");

        $stmt->execute([
            $medicineId,
            $batchId,
            strtoupper($transactionType),
            $transactionType,
            $refId,
            $refNo,
            $quantityChange,
            $balanceBefore,
            $balanceAfter,
            $unitCost,
            $unitPrice,
            $reason,
            $userId
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Audit and reconcile aggregate medicine stock against physical batch inventory.
     * Checks whether medicines.stock_quantity == SUM(medicine_batches.quantity_available).
     */
    public function reconcileMedicine(int $medicineId): array
    {
        $mStmt = $this->pdo->prepare("SELECT stock_quantity, medicine_name FROM medicines WHERE medicine_id = ?");
        $mStmt->execute([$medicineId]);
        $med = $mStmt->fetch(PDO::FETCH_ASSOC);

        if (!$med) {
            throw new Exception("Medicine #{$medicineId} not found.");
        }

        $bStmt = $this->pdo->prepare("
            SELECT COALESCE(SUM(quantity_available), 0) 
            FROM medicine_batches 
            WHERE medicine_id = ? AND status != 'Disposed'
        ");
        $bStmt->execute([$medicineId]);
        $batchSum = (int)$bStmt->fetchColumn();

        $currentStock = (int)$med['stock_quantity'];
        $isReconciled = ($currentStock === $batchSum);
        $discrepancy = $currentStock - $batchSum;

        return [
            'medicine_id'    => $medicineId,
            'medicine_name'  => $med['medicine_name'],
            'medicine_stock' => $currentStock,
            'batch_sum'      => $batchSum,
            'is_reconciled'  => $isReconciled,
            'discrepancy'    => $discrepancy
        ];
    }

    /**
     * Re-synchronize aggregate stock_quantity from batches if any drift was detected.
     */
    public function syncAggregateStock(int $medicineId): int
    {
        $stmt = $this->pdo->prepare("
            UPDATE medicines m
            SET m.stock_quantity = (
                SELECT COALESCE(SUM(mb.quantity_available), 0)
                FROM medicine_batches mb
                WHERE mb.medicine_id = m.medicine_id
                  AND mb.status != 'Disposed'
            ),
            m.updated_at = NOW()
            WHERE m.medicine_id = ?
        ");
        $stmt->execute([$medicineId]);

        $checkStmt = $this->pdo->prepare("SELECT stock_quantity FROM medicines WHERE medicine_id = ?");
        $checkStmt->execute([$medicineId]);
        return (int)$checkStmt->fetchColumn();
    }

    /**
     * Fetch filtered ledger entries for reporting and auditing.
     */
    public function getLedgerHistory(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ["1=1"];
        $params = [];

        if (!empty($filters['medicine_id'])) {
            $where[] = "l.medicine_id = ?";
            $params[] = (int)$filters['medicine_id'];
        }

        if (!empty($filters['batch_id'])) {
            $where[] = "l.batch_id = ?";
            $params[] = (int)$filters['batch_id'];
        }

        if (!empty($filters['transaction_type'])) {
            $where[] = "l.transaction_type = ?";
            $params[] = $filters['transaction_type'];
        }

        if (!empty($filters['start_date'])) {
            $where[] = "DATE(l.created_at) >= ?";
            $params[] = $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $where[] = "DATE(l.created_at) <= ?";
            $params[] = $filters['end_date'];
        }

        $whereSql = implode(' AND ', $where);
        $sql = "
            SELECT l.*, m.medicine_name, m.generic_name, mb.batch_number, u.full_name as user_name
            FROM pharmacy_stock_ledger l
            JOIN medicines m ON l.medicine_id = m.medicine_id
            LEFT JOIN medicine_batches mb ON l.batch_id = mb.batch_id
            LEFT JOIN pharmacy_users u ON l.created_by = u.id
            WHERE {$whereSql}
            ORDER BY l.ledger_id DESC
            LIMIT {$limit} OFFSET {$offset}
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
