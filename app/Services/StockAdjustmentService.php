<?php
// app/Services/StockAdjustmentService.php - Controlled Stock Adjustments & Reconciliations

namespace Pharmacy\Services;

use PDO;
use Exception;
use InvalidArgumentException;

class StockAdjustmentService
{
    private PDO $pdo;
    private DocumentSequenceService $seqService;
    private StockLedgerService $ledgerService;
    private AuditService $auditService;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->seqService = new DocumentSequenceService($pdo);
        $this->ledgerService = new StockLedgerService($pdo);
        $this->auditService = new AuditService($pdo);
    }

    /**
     * Create and commit a controlled stock adjustment.
     *
     * @param int $medicineId
     * @param int $batchId
     * @param string $adjustmentType 'Increase' | 'Decrease' | 'Damage' | 'Expired' | 'Count Reconciliation' | 'Found Stock'
     * @param int $quantity Absolute adjustment quantity (> 0)
     * @param string $reason Mandatory justification for audit
     * @param int $userId Performing user ID
     * @return array ['adjustment_id' => int, 'adjustment_no' => string, 'old_quantity' => int, 'new_quantity' => int]
     * @throws Exception
     */
    public function adjustStock(
        int $medicineId,
        int $batchId,
        string $adjustmentType,
        int $quantity,
        string $reason,
        int $userId
    ): array {
        if ($quantity <= 0) {
            throw new InvalidArgumentException("Adjustment quantity must be greater than zero.");
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException("Adjustment reason is mandatory for auditing purposes.");
        }

        $validTypes = ['Increase', 'Decrease', 'Damage', 'Expired', 'Count Reconciliation', 'Found Stock'];
        if (!in_array($adjustmentType, $validTypes, true)) {
            throw new InvalidArgumentException("Invalid adjustment type '{$adjustmentType}'.");
        }

        $isIncrease = in_array($adjustmentType, ['Increase', 'Found Stock'], true);

        $this->pdo->beginTransaction();
        try {
            // 1. Lock batch record
            $bStmt = $this->pdo->prepare("
                SELECT batch_id, medicine_id, batch_number, quantity_available, purchase_price, sale_price, status 
                FROM medicine_batches 
                WHERE batch_id = ? AND medicine_id = ? 
                FOR UPDATE
            ");
            $bStmt->execute([$batchId, $medicineId]);
            $batch = $bStmt->fetch(PDO::FETCH_ASSOC);

            if (!$batch) {
                throw new Exception("Batch #{$batchId} not found for Medicine #{$medicineId}.");
            }

            $oldQty = (int)$batch['quantity_available'];

            if (!$isIncrease && $oldQty < $quantity) {
                throw new Exception("Cannot decrease batch stock below zero. Current stock: {$oldQty}, requested reduction: {$quantity}.");
            }

            $newQty = $isIncrease ? ($oldQty + $quantity) : ($oldQty - $quantity);
            $qtyDelta = $isIncrease ? $quantity : -$quantity;

            $newStatus = $batch['status'];
            if ($newQty === 0) {
                $newStatus = ($adjustmentType === 'Expired') ? 'Expired' : 'Depleted';
            } elseif ($newQty > 0 && $batch['status'] === 'Depleted') {
                $newStatus = 'Active';
            }

            // 2. Generate unique adjustment sequence number
            $adjNo = $this->seqService->getNextNumber('STOCK_ADJUSTMENT');

            // 3. Update batch stock
            $updBatch = $this->pdo->prepare("
                UPDATE medicine_batches 
                SET quantity_available = ?, status = ?, updated_at = NOW() 
                WHERE batch_id = ?
            ");
            $updBatch->execute([$newQty, $newStatus, $batchId]);

            // 4. Update medicine aggregate stock
            $updMed = $this->pdo->prepare("
                UPDATE medicines 
                SET stock_quantity = stock_quantity + ?, updated_at = NOW() 
                WHERE medicine_id = ?
            ");
            $updMed->execute([$qtyDelta, $medicineId]);

            // 5. Insert audit adjustment row
            $adjStmt = $this->pdo->prepare("
                INSERT INTO pharmacy_stock_adjustments (
                    adjustment_no, medicine_id, batch_id, adjustment_type,
                    quantity, old_quantity, new_quantity, reason, created_by, created_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, NOW()
                )
            ");
            $adjStmt->execute([
                $adjNo,
                $medicineId,
                $batchId,
                $adjustmentType,
                $quantity,
                $oldQty,
                $newQty,
                $reason,
                $userId
            ]);
            $adjId = (int)$this->pdo->lastInsertId();

            // 6. Record immutable stock ledger movement
            $ledgerType = ($adjustmentType === 'Damage') ? 'DAMAGE' : (($adjustmentType === 'Expired') ? 'EXPIRY' : 'ADJUSTMENT');
            $this->ledgerService->recordEntry(
                $medicineId,
                $batchId,
                $ledgerType,
                $qtyDelta,
                (float)$batch['purchase_price'],
                (float)$batch['sale_price'],
                $adjId,
                $adjNo,
                $userId,
                "Stock Adjustment [{$adjustmentType}] #{$adjNo}: {$reason}"
            );

            // 7. Audit trail
            $this->auditService->log(
                'STOCK_ADJUSTMENT',
                'pharmacy_stock_adjustments',
                $adjId,
                ['quantity' => $oldQty],
                ['quantity' => $newQty, 'type' => $adjustmentType, 'reason' => $reason],
                $userId
            );

            $this->pdo->commit();

            return [
                'adjustment_id' => $adjId,
                'adjustment_no' => $adjNo,
                'old_quantity'  => $oldQty,
                'new_quantity'  => $newQty,
                'delta'         => $qtyDelta
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Direct Stock In: Inward stock directly into inventory WITHOUT requiring a Purchase Invoice/Bill.
     * Can use an existing batch OR provision a brand new batch.
     * Creates immutable stock ledger entry (DIRECT_STOCK_IN) and audit record.
     */
    public function directStockIn(array $data, int $userId): array
    {
        $medicineId = (int)($data['medicine_id'] ?? 0);
        if ($medicineId <= 0) {
            throw new InvalidArgumentException("Please select a valid medicine.");
        }

        $quantity = (int)($data['quantity'] ?? 0);
        if ($quantity <= 0) {
            throw new InvalidArgumentException("Direct Stock In quantity must be greater than zero.");
        }

        $reason = trim($data['reason'] ?? 'Direct stock entry (Without purchase invoice)');
        if ($reason === '') {
            throw new InvalidArgumentException("Reason/justification is required for Direct Stock In.");
        }
        $refNo = !empty($data['reference_no']) ? trim($data['reference_no']) : null;

        $isNewBatch = !empty($data['is_new_batch']) || (!empty($data['batch_number']) && empty($data['batch_id']));

        $this->pdo->beginTransaction();
        try {
            // Lock medicine
            $mStmt = $this->pdo->prepare("SELECT medicine_id, medicine_name, stock_quantity, status FROM medicines WHERE medicine_id = ? FOR UPDATE");
            $mStmt->execute([$medicineId]);
            $medicine = $mStmt->fetch(PDO::FETCH_ASSOC);
            if (!$medicine) {
                throw new Exception("Medicine #{$medicineId} not found.");
            }

            $batchId = 0;
            $batchNumber = '';
            $purchasePrice = 0.00;
            $salePrice = 0.00;
            $oldBatchQty = 0;
            $newBatchQty = 0;

            if ($isNewBatch) {
                $batchNumber = trim($data['batch_number'] ?? '');
                if ($batchNumber === '') {
                    throw new InvalidArgumentException("Batch number cannot be blank for new batch.");
                }

                $expiryDate = trim($data['expiry_date'] ?? '');
                if (!BatchService::isValidDate($expiryDate)) {
                    throw new InvalidArgumentException("Invalid expiry date. Must be formatted YYYY-MM-DD.");
                }

                $mfgDate = !empty($data['manufacturing_date']) && BatchService::isValidDate($data['manufacturing_date']) ? $data['manufacturing_date'] : null;
                $purchasePrice = max(0.0, (float)($data['purchase_price'] ?? $data['unit_cost'] ?? 0.00));
                $mrp = max(0.0, (float)($data['mrp'] ?? $data['sale_price'] ?? 0.00));
                $salePrice = max(0.0, (float)($data['sale_price'] ?? $mrp));
                $shelf = !empty($data['shelf_location']) ? trim($data['shelf_location']) : null;
                $supplierId = !empty($data['supplier_id']) ? (int)$data['supplier_id'] : null;

                // Check if batch already exists for this medicine
                $chkStmt = $this->pdo->prepare("SELECT batch_id, quantity_available FROM medicine_batches WHERE medicine_id = ? AND batch_number = ? FOR UPDATE");
                $chkStmt->execute([$medicineId, $batchNumber]);
                $existing = $chkStmt->fetch(PDO::FETCH_ASSOC);

                if ($existing) {
                    $batchId = (int)$existing['batch_id'];
                    $oldBatchQty = (int)$existing['quantity_available'];
                    $newBatchQty = $oldBatchQty + $quantity;
                    $updB = $this->pdo->prepare("UPDATE medicine_batches SET quantity_available = ?, status = 'Active', updated_at = NOW() WHERE batch_id = ?");
                    $updB->execute([$newBatchQty, $batchId]);
                } else {
                    $status = BatchService::calculateStatus($expiryDate, $quantity);
                    $insB = $this->pdo->prepare("
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
                    $insB->execute([
                        $medicineId, $batchNumber, $mfgDate, $expiryDate,
                        $purchasePrice, $mrp, $salePrice, $quantity, $quantity,
                        $supplierId, $shelf, $status
                    ]);
                    $batchId = (int)$this->pdo->lastInsertId();
                    $oldBatchQty = 0;
                    $newBatchQty = $quantity;
                }
            } else {
                $batchId = (int)($data['batch_id'] ?? 0);
                if ($batchId <= 0) {
                    throw new InvalidArgumentException("Please select an existing batch or check 'Create New Batch'.");
                }

                $bStmt = $this->pdo->prepare("
                    SELECT batch_id, batch_number, quantity_available, purchase_price, sale_price, status 
                    FROM medicine_batches 
                    WHERE batch_id = ? AND medicine_id = ? 
                    FOR UPDATE
                ");
                $bStmt->execute([$batchId, $medicineId]);
                $batch = $bStmt->fetch(PDO::FETCH_ASSOC);
                if (!$batch) {
                    throw new Exception("Selected batch #{$batchId} not found for Medicine #{$medicineId}.");
                }

                $batchNumber = $batch['batch_number'];
                $purchasePrice = (float)$batch['purchase_price'];
                $salePrice = (float)$batch['sale_price'];
                $oldBatchQty = (int)$batch['quantity_available'];
                $newBatchQty = $oldBatchQty + $quantity;

                $updB = $this->pdo->prepare("UPDATE medicine_batches SET quantity_available = ?, status = 'Active', updated_at = NOW() WHERE batch_id = ?");
                $updB->execute([$newBatchQty, $batchId]);
            }

            // Increment medicine aggregate stock
            $updMed = $this->pdo->prepare("UPDATE medicines SET stock_quantity = stock_quantity + ?, updated_at = NOW() WHERE medicine_id = ?");
            $updMed->execute([$quantity, $medicineId]);

            // Generate adjustment number
            $adjNo = $this->seqService->getNextNumber('DIRECT_STOCK_IN');

            // Insert into pharmacy_stock_adjustments
            $insAdj = $this->pdo->prepare("
                INSERT INTO pharmacy_stock_adjustments (
                    adjustment_no, medicine_id, batch_id, adjustment_type,
                    quantity, old_quantity, new_quantity, reason, reference_no, created_by, created_at
                ) VALUES (
                    ?, ?, ?, 'Direct Stock In',
                    ?, ?, ?, ?, ?, ?, NOW()
                )
            ");
            $insAdj->execute([
                $adjNo, $medicineId, $batchId,
                $quantity, $oldBatchQty, $newBatchQty, $reason, $refNo, $userId
            ]);
            $adjId = (int)$this->pdo->lastInsertId();

            // Record in pharmacy_stock_ledger with transaction_type = DIRECT_STOCK_IN
            $this->ledgerService->recordEntry(
                $medicineId,
                $batchId,
                'DIRECT_STOCK_IN',
                $quantity,
                $purchasePrice,
                $salePrice,
                $adjId,
                $adjNo,
                $userId,
                "Direct Stock In #{$adjNo}: {$reason}"
            );

            // Audit log
            $this->auditService->log(
                'DIRECT_STOCK_IN',
                'pharmacy_stock_adjustments',
                $adjId,
                ['quantity' => $oldBatchQty],
                ['quantity' => $newBatchQty, 'delta' => $quantity, 'batch' => $batchNumber, 'reason' => $reason, 'reference_no' => $refNo],
                $userId
            );

            $this->pdo->commit();

            return [
                'success'       => true,
                'adjustment_id' => $adjId,
                'adjustment_no' => $adjNo,
                'medicine_id'   => $medicineId,
                'batch_id'      => $batchId,
                'batch_number'  => $batchNumber,
                'old_quantity'  => $oldBatchQty,
                'new_quantity'  => $newBatchQty,
                'quantity'      => $quantity
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Direct Stock Out: Outward stock directly without requiring a normal sale or purchase return.
     * Enforces STRICT validation: requested quantity CANNOT exceed available batch stock.
     * Creates immutable stock ledger entry (DIRECT_STOCK_OUT) and audit record.
     */
    public function directStockOut(array $data, int $userId): array
    {
        $medicineId = (int)($data['medicine_id'] ?? 0);
        if ($medicineId <= 0) {
            throw new InvalidArgumentException("Please select a valid medicine.");
        }

        $batchId = (int)($data['batch_id'] ?? 0);
        if ($batchId <= 0) {
            throw new InvalidArgumentException("Please select a valid physical batch.");
        }

        $quantity = (int)($data['quantity'] ?? 0);
        if ($quantity <= 0) {
            throw new InvalidArgumentException("Direct Stock Out quantity must be greater than zero.");
        }

        $reason = trim($data['reason'] ?? '');
        if ($reason === '') {
            throw new InvalidArgumentException("Reason/justification is required for Direct Stock Out.");
        }
        $refNo = !empty($data['reference_no']) ? trim($data['reference_no']) : null;

        $this->pdo->beginTransaction();
        try {
            // Lock batch record
            $bStmt = $this->pdo->prepare("
                SELECT batch_id, medicine_id, batch_number, quantity_available, purchase_price, sale_price, status 
                FROM medicine_batches 
                WHERE batch_id = ? AND medicine_id = ? 
                FOR UPDATE
            ");
            $bStmt->execute([$batchId, $medicineId]);
            $batch = $bStmt->fetch(PDO::FETCH_ASSOC);

            if (!$batch) {
                throw new Exception("Batch #{$batchId} not found for Medicine #{$medicineId}.");
            }

            $availableQty = (int)$batch['quantity_available'];

            // STRICT BACKEND VALIDATION: NEVER ALLOW NEGATIVE INVENTORY
            if ($quantity > $availableQty) {
                throw new Exception("Stock Out Rejected: Requested {$quantity} units exceeds current available batch stock of {$availableQty} units for batch '{$batch['batch_number']}'.");
            }

            $oldBatchQty = $availableQty;
            $newBatchQty = $oldBatchQty - $quantity;
            $newStatus = ($newBatchQty === 0) ? 'Depleted' : $batch['status'];

            // Update batch stock
            $updB = $this->pdo->prepare("UPDATE medicine_batches SET quantity_available = ?, status = ?, updated_at = NOW() WHERE batch_id = ?");
            $updB->execute([$newBatchQty, $newStatus, $batchId]);

            // Decrement medicine aggregate stock
            $updMed = $this->pdo->prepare("UPDATE medicines SET stock_quantity = stock_quantity - ?, updated_at = NOW() WHERE medicine_id = ?");
            $updMed->execute([$quantity, $medicineId]);

            // Generate adjustment number
            $adjNo = $this->seqService->getNextNumber('DIRECT_STOCK_OUT');

            // Insert into pharmacy_stock_adjustments
            $insAdj = $this->pdo->prepare("
                INSERT INTO pharmacy_stock_adjustments (
                    adjustment_no, medicine_id, batch_id, adjustment_type,
                    quantity, old_quantity, new_quantity, reason, reference_no, created_by, created_at
                ) VALUES (
                    ?, ?, ?, 'Direct Stock Out',
                    ?, ?, ?, ?, ?, ?, NOW()
                )
            ");
            $insAdj->execute([
                $adjNo, $medicineId, $batchId,
                $quantity, $oldBatchQty, $newBatchQty, $reason, $refNo, $userId
            ]);
            $adjId = (int)$this->pdo->lastInsertId();

            // Record in pharmacy_stock_ledger with transaction_type = DIRECT_STOCK_OUT
            $this->ledgerService->recordEntry(
                $medicineId,
                $batchId,
                'DIRECT_STOCK_OUT',
                -$quantity,
                (float)$batch['purchase_price'],
                (float)$batch['sale_price'],
                $adjId,
                $adjNo,
                $userId,
                "Direct Stock Out #{$adjNo}: {$reason}"
            );

            // Audit log
            $this->auditService->log(
                'DIRECT_STOCK_OUT',
                'pharmacy_stock_adjustments',
                $adjId,
                ['quantity' => $oldBatchQty],
                ['quantity' => $newBatchQty, 'delta' => -$quantity, 'batch' => $batch['batch_number'], 'reason' => $reason, 'reference_no' => $refNo],
                $userId
            );

            $this->pdo->commit();

            return [
                'success'       => true,
                'adjustment_id' => $adjId,
                'adjustment_no' => $adjNo,
                'medicine_id'   => $medicineId,
                'batch_id'      => $batchId,
                'batch_number'  => $batch['batch_number'],
                'old_quantity'  => $oldBatchQty,
                'new_quantity'  => $newBatchQty,
                'quantity'      => $quantity
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Update adjustment justification or reference without altering stock movements.
     */
    public function updateAdjustmentNotes(int $adjustmentId, string $newReason, ?string $newRefNo, int $userId): bool
    {
        $newReason = trim($newReason);
        if ($newReason === '') {
            throw new InvalidArgumentException("Reason cannot be blank.");
        }

        $stmt = $this->pdo->prepare("SELECT * FROM pharmacy_stock_adjustments WHERE adjustment_id = ?");
        $stmt->execute([$adjustmentId]);
        $adj = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$adj) {
            throw new Exception("Adjustment record #{$adjustmentId} not found.");
        }

        $upd = $this->pdo->prepare("UPDATE pharmacy_stock_adjustments SET reason = ?, reference_no = ? WHERE adjustment_id = ?");
        $upd->execute([$newReason, $newRefNo, $adjustmentId]);

        $this->auditService->log(
            'ADJUSTMENT_NOTE_UPDATED',
            'pharmacy_stock_adjustments',
            $adjustmentId,
            ['reason' => $adj['reason'], 'reference_no' => $adj['reference_no']],
            ['reason' => $newReason, 'reference_no' => $newRefNo],
            $userId
        );

        return true;
    }

    /**
     * Retrieve single adjustment with medicine and batch info.
     */
    public function getAdjustmentById(int $adjustmentId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT a.*, m.medicine_name, m.generic_name, mb.batch_number, mb.expiry_date, mb.shelf_location,
                   u.full_name as user_name
            FROM pharmacy_stock_adjustments a
            JOIN medicines m ON a.medicine_id = m.medicine_id
            JOIN medicine_batches mb ON a.batch_id = mb.batch_id
            LEFT JOIN pharmacy_users u ON a.created_by = u.id
            WHERE a.adjustment_id = ?
        ");
        $stmt->execute([$adjustmentId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
