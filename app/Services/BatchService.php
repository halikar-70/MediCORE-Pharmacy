<?php
// app/Services/BatchService.php - Batch Master & Expiry Management

namespace Pharmacy\Services;

use PDO;
use Exception;
use InvalidArgumentException;

class BatchService
{
    private PDO $pdo;
    private AuditService $auditService;
    private StockLedgerService $ledgerService;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->auditService = new AuditService($pdo);
        $this->ledgerService = new StockLedgerService($pdo);
    }

    /**
     * Check if a batch number is already taken for a given medicine (scoped uniqueness).
     */
    public function isBatchTaken(int $medicineId, string $batchNumber, ?int $excludeBatchId = null): bool
    {
        $batchNumber = trim($batchNumber);
        $sql = "SELECT COUNT(*) FROM medicine_batches WHERE medicine_id = ? AND batch_number = ?";
        $params = [$medicineId, $batchNumber];

        if ($excludeBatchId !== null) {
            $sql .= " AND batch_id != ?";
            $params[] = $excludeBatchId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Validate expiry date. Must be valid YYYY-MM-DD.
     */
    public static function isValidDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    /**
     * Determine batch status based on expiry date.
     */
    public static function calculateStatus(string $expiryDate, int $quantity = 1): string
    {
        if ($quantity <= 0) {
            return 'Depleted';
        }

        $today = date('Y-m-d');
        if ($expiryDate < $today) {
            return 'Expired';
        }

        return 'Active';
    }

    /**
     * Create a new physical inventory batch.
     */
    public function createBatch(array $data, int $userId): int
    {
        $medicineId = (int)($data['medicine_id'] ?? 0);
        if ($medicineId <= 0) {
            throw new InvalidArgumentException("Invalid medicine ID.");
        }

        $batchNumber = trim($data['batch_number'] ?? '');
        if ($batchNumber === '') {
            throw new InvalidArgumentException("Batch number cannot be blank.");
        }

        if ($this->isBatchTaken($medicineId, $batchNumber)) {
            throw new InvalidArgumentException("Batch '{$batchNumber}' already exists for this medicine.");
        }

        $expiryDate = trim($data['expiry_date'] ?? '');
        if (!self::isValidDate($expiryDate)) {
            throw new InvalidArgumentException("Invalid expiry date. Must be formatted YYYY-MM-DD.");
        }

        $mfgDate = !empty($data['manufacturing_date']) && self::isValidDate($data['manufacturing_date']) ? $data['manufacturing_date'] : null;
        if ($mfgDate && $mfgDate > $expiryDate) {
            throw new InvalidArgumentException("Manufacturing date cannot be after expiry date.");
        }

        $qtyReceived = max(0, (int)($data['quantity_received'] ?? $data['quantity'] ?? 0));
        $qtyAvailable = max(0, (int)($data['quantity_available'] ?? $qtyReceived));
        $purchasePrice = max(0.0, (float)($data['purchase_price'] ?? 0.00));
        $mrp = max(0.0, (float)($data['mrp'] ?? $data['sale_price'] ?? 0.00));
        $salePrice = max(0.0, (float)($data['sale_price'] ?? $mrp));
        $shelf = !empty($data['shelf_location']) ? trim($data['shelf_location']) : (!empty($data['shelf']) ? trim($data['shelf']) : null);
        $supplierId = !empty($data['supplier_id']) ? (int)$data['supplier_id'] : null;

        $status = self::calculateStatus($expiryDate, $qtyAvailable);

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO medicine_batches (
                    medicine_id, batch_number, manufacturing_date, expiry_date,
                    purchase_price, mrp, sale_price, quantity_received, quantity_available,
                    supplier_id, shelf_location, status, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, NOW(), NOW()
                )
            ");

            $stmt->execute([
                $medicineId,
                $batchNumber,
                $mfgDate,
                $expiryDate,
                $purchasePrice,
                $mrp,
                $salePrice,
                $qtyReceived,
                $qtyAvailable,
                $supplierId,
                $shelf,
                $status
            ]);

            $batchId = (int)$this->pdo->lastInsertId();

            // If initial inward quantity > 0, record in ledger and synchronize medicine aggregate
            if ($qtyAvailable > 0) {
                $txType = !empty($data['transaction_type']) ? $data['transaction_type'] : 'OPENING_STOCK';
                $reason = !empty($data['reason']) ? $data['reason'] : "Initial batch creation: {$batchNumber}";

                // Increment medicine master stock
                $medUpd = $this->pdo->prepare("UPDATE medicines SET stock_quantity = stock_quantity + ? WHERE medicine_id = ?");
                $medUpd->execute([$qtyAvailable, $medicineId]);

                $this->ledgerService->recordEntry(
                    $medicineId,
                    $batchId,
                    $txType,
                    $qtyAvailable,
                    $purchasePrice,
                    $salePrice,
                    $batchId,
                    $batchNumber,
                    $userId,
                    $reason
                );
            }

            $this->pdo->commit();

            $this->auditService->log(
                'BATCH_CREATED',
                'medicine_batches',
                $batchId,
                null,
                ['medicine_id' => $medicineId, 'batch_number' => $batchNumber, 'qty' => $qtyAvailable, 'expiry' => $expiryDate],
                $userId
            );

            return $batchId;
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Retrieve single batch by ID.
     */
    public function getBatchById(int $batchId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT mb.*, m.medicine_name, m.generic_name, m.unit, m.pack_size, m.schedule_type,
                   DATEDIFF(mb.expiry_date, CURDATE()) AS days_to_expiry
            FROM medicine_batches mb
            JOIN medicines m ON mb.medicine_id = m.medicine_id
            WHERE mb.batch_id = ?
        ");
        $stmt->execute([$batchId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Get all batches for a specific medicine.
     */
    public function getBatchesForMedicine(int $medicineId, bool $activeOnly = false): array
    {
        $sql = "
            SELECT mb.*, 
                   DATEDIFF(mb.expiry_date, CURDATE()) AS days_to_expiry
            FROM medicine_batches mb
            WHERE mb.medicine_id = ?
        ";

        if ($activeOnly) {
            $sql .= " AND mb.status = 'Active' AND mb.quantity_available > 0 AND mb.expiry_date >= CURDATE()";
        }

        $sql .= " ORDER BY mb.expiry_date ASC, mb.batch_id ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$medicineId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update physical storage location (shelf/rack).
     */
    public function updateBatchLocation(int $batchId, string $shelfLocation, int $userId): bool
    {
        $stmt = $this->pdo->prepare("UPDATE medicine_batches SET shelf_location = ?, updated_at = NOW() WHERE batch_id = ?");
        $stmt->execute([trim($shelfLocation), $batchId]);

        $this->auditService->log('BATCH_SHELF_UPDATED', 'medicine_batches', $batchId, null, ['shelf' => $shelfLocation], $userId);
        return true;
    }

    /**
     * Query batches for Expiry Management and Alert widgets.
     * Supports thresholds: 30, 60, 90 days, or Expired.
     */
    public function getExpiryAlerts(string $window = '30d'): array
    {
        $where = "mb.quantity_available > 0";

        switch ($window) {
            case 'expired':
                $where .= " AND mb.expiry_date < CURDATE()";
                break;
            case '30d':
                $where .= " AND mb.expiry_date >= CURDATE() AND mb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
                break;
            case '60d':
                $where .= " AND mb.expiry_date >= CURDATE() AND mb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)";
                break;
            case '90d':
                $where .= " AND mb.expiry_date >= CURDATE() AND mb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)";
                break;
            case 'all_near':
                $where .= " AND mb.expiry_date >= CURDATE() AND mb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)";
                break;
        }

        $sql = "
            SELECT mb.*, m.medicine_name, m.generic_name, m.shelf, m.rack_location,
                   DATEDIFF(mb.expiry_date, CURDATE()) AS days_to_expiry
            FROM medicine_batches mb
            JOIN medicines m ON mb.medicine_id = m.medicine_id
            WHERE {$where}
            ORDER BY mb.expiry_date ASC
        ";

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Summary metrics for Inventory Dashboard widgets.
     */
    public function getInventoryMetrics(): array
    {
        $totalMedicines = (int)$this->pdo->query("SELECT COUNT(*) FROM medicines WHERE status = 'Active' AND deleted_at IS NULL")->fetchColumn();
        $totalBatches = (int)$this->pdo->query("SELECT COUNT(*) FROM medicine_batches WHERE quantity_available > 0")->fetchColumn();
        $totalUnits = (int)$this->pdo->query("SELECT COALESCE(SUM(quantity_available), 0) FROM medicine_batches WHERE status != 'Disposed'")->fetchColumn();

        $outOfStock = (int)$this->pdo->query("
            SELECT COUNT(*) FROM medicines m
            WHERE m.status = 'Active' AND m.deleted_at IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM medicine_batches mb 
                  WHERE mb.medicine_id = m.medicine_id AND mb.quantity_available > 0 AND mb.expiry_date >= CURDATE()
              )
        ")->fetchColumn();

        $lowStock = (int)$this->pdo->query("
            SELECT COUNT(*) FROM medicines m
            WHERE m.status = 'Active' AND m.deleted_at IS NULL
              AND m.stock_quantity <= m.reorder_level
              AND m.stock_quantity > 0
        ")->fetchColumn();

        $nearExpiry90 = (int)$this->pdo->query("
            SELECT COUNT(*) FROM medicine_batches 
            WHERE quantity_available > 0 
              AND expiry_date >= CURDATE() 
              AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
        ")->fetchColumn();

        $expiredBatches = (int)$this->pdo->query("
            SELECT COUNT(*) FROM medicine_batches 
            WHERE quantity_available > 0 
              AND expiry_date < CURDATE()
        ")->fetchColumn();

        return [
            'total_medicines'  => $totalMedicines,
            'total_batches'    => $totalBatches,
            'total_units'      => $totalUnits,
            'out_of_stock'     => $outOfStock,
            'low_stock'        => $lowStock,
            'near_expiry_90'   => $nearExpiry90,
            'expired_batches'  => $expiredBatches
        ];
    }
}
