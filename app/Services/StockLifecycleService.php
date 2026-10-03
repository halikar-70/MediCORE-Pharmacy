<?php
// app/Services/StockLifecycleService.php - Expiry, Damage, Quarantine, Disposal & Traceability Engine

namespace Pharmacy\Services;

use PDO;
use Exception;
use InvalidArgumentException;
use RuntimeException;

class StockLifecycleService
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
     * Move available batch quantity into Quarantine isolation.
     * Decrements quantity_available (immediately excluding it from FEFO and POS)
     * and increments quarantined_quantity.
     */
    public function quarantineStock(int $batchId, int $quantity, string $reason, string $notes = '', int $userId = 1): array
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException("Quarantine quantity must be greater than zero.");
        }

        $validReasons = ['EXPIRY_SUSPECT', 'PHYSICAL_DAMAGE', 'CUSTOMER_RETURN_INSPECTION', 'COLD_CHAIN_BREACH', 'MANUFACTURER_RECALL', 'OTHER'];
        if (!in_array($reason, $validReasons, true)) {
            $reason = 'OTHER';
        }

        $this->pdo->beginTransaction();
        try {
            $bStmt = $this->pdo->prepare("
                SELECT batch_id, medicine_id, batch_number, quantity_available, quarantined_quantity,
                       purchase_price, sale_price, expiry_date, status
                FROM medicine_batches
                WHERE batch_id = ? FOR UPDATE
            ");
            $bStmt->execute([$batchId]);
            $batch = $bStmt->fetch(PDO::FETCH_ASSOC);

            if (!$batch) {
                throw new InvalidArgumentException("Batch #{$batchId} not found.");
            }

            $avail = (int)$batch['quantity_available'];
            if ($avail < $quantity) {
                throw new InvalidArgumentException("Cannot quarantine {$quantity} units. Only {$avail} units available in batch '{$batch['batch_number']}'.");
            }

            $medId = (int)$batch['medicine_id'];
            $newAvail = $avail - $quantity;
            $newStatus = ($newAvail === 0) ? 'Quarantined' : $batch['status'];

            // 1. Update batch stock
            $updBatch = $this->pdo->prepare("
                UPDATE medicine_batches
                SET quantity_available = ?, quarantined_quantity = quarantined_quantity + ?,
                    status = ?, updated_at = NOW()
                WHERE batch_id = ?
            ");
            $updBatch->execute([$newAvail, $quantity, $newStatus, $batchId]);

            // 2. Decrement medicine master sellable stock
            $updMed = $this->pdo->prepare("
                UPDATE medicines
                SET stock_quantity = stock_quantity - ?, updated_at = NOW()
                WHERE medicine_id = ?
            ");
            $updMed->execute([$quantity, $medId]);

            // 3. Generate Quarantine Number
            $qrnNo = $this->seqService->generate('QUARANTINE', 'QRN-');

            // 4. Insert quarantine record
            $insQrn = $this->pdo->prepare("
                INSERT INTO pharmacy_quarantine_records (
                    quarantine_no, medicine_id, batch_id, quantity, reason,
                    source_type, source_id, status, notes, created_by, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    'INVENTORY', NULL, 'QUARANTINED', ?, ?, NOW()
                )
            ");
            $insQrn->execute([
                $qrnNo,
                $medId,
                $batchId,
                $quantity,
                $reason,
                $notes ?: "Isolated under reason: {$reason}",
                $userId
            ]);
            $qrnId = (int)$this->pdo->lastInsertId();

            // 5. Append ledger movement
            $this->ledgerService->recordEntry(
                $medId,
                $batchId,
                'ADJUSTMENT',
                -$quantity,
                (float)$batch['purchase_price'],
                (float)$batch['sale_price'],
                $qrnId,
                $qrnNo,
                $userId,
                "Quarantine Isolation #{$qrnNo}: {$reason}"
            );

            // 6. Audit log
            $this->auditService->logAction(
                $userId,
                'QUARANTINE_STOCK',
                'pharmacy_quarantine_records',
                $qrnId,
                null,
                [
                    'quarantine_no' => $qrnNo,
                    'batch_id'      => $batchId,
                    'quantity'      => $quantity,
                    'reason'        => $reason
                ]
            );

            $this->pdo->commit();

            return [
                'quarantine_id' => $qrnId,
                'quarantine_no' => $qrnNo,
                'batch_id'      => $batchId,
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
     * Authoritative Quarantine Release / Decision Workflow.
     * Decisions: 'RELEASE_TO_STOCK' | 'SEND_TO_DISPOSAL' | 'MARK_NON_SELLABLE'
     */
    public function releaseQuarantine(int $quarantineId, string $decision, string $notes = '', int $userId = 1): array
    {
        $validDecisions = ['RELEASE_TO_STOCK', 'SEND_TO_DISPOSAL', 'MARK_NON_SELLABLE'];
        if (!in_array($decision, $validDecisions, true)) {
            throw new InvalidArgumentException("Invalid quarantine decision '{$decision}'.");
        }

        $this->pdo->beginTransaction();
        try {
            $qStmt = $this->pdo->prepare("
                SELECT * FROM pharmacy_quarantine_records
                WHERE quarantine_id = ? FOR UPDATE
            ");
            $qStmt->execute([$quarantineId]);
            $qrn = $qStmt->fetch(PDO::FETCH_ASSOC);

            if (!$qrn) {
                throw new InvalidArgumentException("Quarantine record #{$quarantineId} not found.");
            }
            if ($qrn['status'] !== 'QUARANTINED') {
                throw new InvalidArgumentException("Quarantine record #{$quarantineId} is already resolved ({$qrn['status']}).");
            }

            $batchId = (int)$qrn['batch_id'];
            $medId = (int)$qrn['medicine_id'];
            $qty = (int)$qrn['quantity'];

            $bStmt = $this->pdo->prepare("
                SELECT batch_id, medicine_id, batch_number, quantity_available, quarantined_quantity,
                       damaged_quantity, purchase_price, sale_price, expiry_date, status
                FROM medicine_batches
                WHERE batch_id = ? FOR UPDATE
            ");
            $bStmt->execute([$batchId]);
            $batch = $bStmt->fetch(PDO::FETCH_ASSOC);

            if (!$batch) {
                throw new InvalidArgumentException("Batch #{$batchId} not found.");
            }

            if ($decision === 'RELEASE_TO_STOCK') {
                // Must not be expired
                if (strtotime($batch['expiry_date']) < strtotime(date('Y-m-d'))) {
                    throw new InvalidArgumentException("Batch '{$batch['batch_number']}' has expired and cannot be released back to active stock. Send to disposal instead.");
                }

                // Decrement quarantined, increment available
                $updBatch = $this->pdo->prepare("
                    UPDATE medicine_batches
                    SET quarantined_quantity = GREATEST(0, quarantined_quantity - ?),
                        quantity_available = quantity_available + ?,
                        status = 'Active',
                        updated_at = NOW()
                    WHERE batch_id = ?
                ");
                $updBatch->execute([$qty, $qty, $batchId]);

                // Increment medicine master stock
                $updMed = $this->pdo->prepare("
                    UPDATE medicines
                    SET stock_quantity = stock_quantity + ?, updated_at = NOW()
                    WHERE medicine_id = ?
                ");
                $updMed->execute([$qty, $medId]);

                // Record compensating ledger movement
                $this->ledgerService->recordEntry(
                    $medId,
                    $batchId,
                    'ADJUSTMENT',
                    $qty,
                    (float)$batch['purchase_price'],
                    (float)$batch['sale_price'],
                    $quarantineId,
                    $qrn['quarantine_no'],
                    $userId,
                    "Released from Quarantine #{$qrn['quarantine_no']} to active stock: {$notes}"
                );

                $newQrnStatus = 'RELEASED_TO_STOCK';
            } elseif ($decision === 'SEND_TO_DISPOSAL') {
                // Decrement quarantined quantity
                $updBatch = $this->pdo->prepare("
                    UPDATE medicine_batches
                    SET quarantined_quantity = GREATEST(0, quarantined_quantity - ?),
                        updated_at = NOW()
                    WHERE batch_id = ?
                ");
                $updBatch->execute([$qty, $batchId]);

                // Create pending disposal
                $dispNo = $this->seqService->generate('DISPOSAL', 'DISP-');
                $insDisp = $this->pdo->prepare("
                    INSERT INTO pharmacy_disposals (
                        disposal_no, disposal_date, medicine_id, batch_id, quantity,
                        reason, source_type, source_id, status, notes, created_by, created_at
                    ) VALUES (
                        ?, CURDATE(), ?, ?, ?,
                        'QUARANTINE_REJECTED', 'QUARANTINE', ?, 'PENDING', ?, ?, NOW()
                    )
                ");
                $insDisp->execute([
                    $dispNo,
                    $medId,
                    $batchId,
                    $qty,
                    $quarantineId,
                    "Referred for destruction from Quarantine #{$qrn['quarantine_no']}: {$notes}",
                    $userId
                ]);

                $newQrnStatus = 'SENT_TO_DISPOSAL';
            } else { // MARK_NON_SELLABLE
                // Decrement quarantined, increment damaged
                $updBatch = $this->pdo->prepare("
                    UPDATE medicine_batches
                    SET quarantined_quantity = GREATEST(0, quarantined_quantity - ?),
                        damaged_quantity = damaged_quantity + ?,
                        updated_at = NOW()
                    WHERE batch_id = ?
                ");
                $updBatch->execute([$qty, $qty, $batchId]);

                $newQrnStatus = 'CANCELLED';
            }

            // Update quarantine record
            $updQrn = $this->pdo->prepare("
                UPDATE pharmacy_quarantine_records
                SET status = ?, released_by = ?, released_at = NOW(),
                    release_decision = ?, release_notes = ?, updated_at = NOW()
                WHERE quarantine_id = ?
            ");
            $updQrn->execute([$newQrnStatus, $userId, $decision, $notes, $quarantineId]);

            // Audit log
            $this->auditService->logAction(
                $userId,
                'RELEASE_QUARANTINE',
                'pharmacy_quarantine_records',
                $quarantineId,
                ['status' => 'QUARANTINED'],
                [
                    'status'   => $newQrnStatus,
                    'decision' => $decision,
                    'notes'    => $notes
                ]
            );

            $this->pdo->commit();

            return [
                'quarantine_id' => $quarantineId,
                'decision'      => $decision,
                'status'        => $newQrnStatus
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Record physical damage to a batch.
     * Moves available stock to damaged_quantity, immediately removing it from dispensing.
     */
    public function recordDamage(int $batchId, int $quantity, string $reason, int $userId = 1): array
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException("Damage quantity must be greater than zero.");
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException("Damage justification is mandatory.");
        }

        $this->pdo->beginTransaction();
        try {
            $bStmt = $this->pdo->prepare("
                SELECT batch_id, medicine_id, batch_number, quantity_available, damaged_quantity,
                       purchase_price, sale_price, status
                FROM medicine_batches
                WHERE batch_id = ? FOR UPDATE
            ");
            $bStmt->execute([$batchId]);
            $batch = $bStmt->fetch(PDO::FETCH_ASSOC);

            if (!$batch) {
                throw new InvalidArgumentException("Batch #{$batchId} not found.");
            }

            $avail = (int)$batch['quantity_available'];
            if ($avail < $quantity) {
                throw new InvalidArgumentException("Cannot record damage for {$quantity} units. Only {$avail} units available.");
            }

            $medId = (int)$batch['medicine_id'];
            $newAvail = $avail - $quantity;
            $newStatus = ($newAvail === 0) ? 'Blocked' : $batch['status'];

            // 1. Update batch stock
            $updBatch = $this->pdo->prepare("
                UPDATE medicine_batches
                SET quantity_available = ?, damaged_quantity = damaged_quantity + ?,
                    status = ?, updated_at = NOW()
                WHERE batch_id = ?
            ");
            $updBatch->execute([$newAvail, $quantity, $newStatus, $batchId]);

            // 2. Decrement medicine master sellable stock
            $updMed = $this->pdo->prepare("
                UPDATE medicines
                SET stock_quantity = stock_quantity - ?, updated_at = NOW()
                WHERE medicine_id = ?
            ");
            $updMed->execute([$quantity, $medId]);

            // 3. Append DAMAGE to stock ledger
            $this->ledgerService->recordEntry(
                $medId,
                $batchId,
                'DAMAGE',
                -$quantity,
                (float)$batch['purchase_price'],
                (float)$batch['sale_price'],
                null,
                null,
                $userId,
                "Damaged inventory write-off: {$reason}"
            );

            // 4. Audit log
            $this->auditService->logAction(
                $userId,
                'RECORD_DAMAGE',
                'medicine_batches',
                $batchId,
                ['quantity_available' => $avail],
                [
                    'quantity_available' => $newAvail,
                    'damage_quantity'    => $quantity,
                    'reason'             => $reason
                ]
            );

            $this->pdo->commit();

            return [
                'batch_id'           => $batchId,
                'damage_quantity'    => $quantity,
                'quantity_available' => $newAvail
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Quarantine an entire expired batch.
     * Moves all remaining available stock into quarantine and sets status to 'Expired'.
     */
    public function quarantineExpiredBatch(int $batchId, int $userId = 1): array
    {
        $this->pdo->beginTransaction();
        try {
            $bStmt = $this->pdo->prepare("
                SELECT batch_id, medicine_id, batch_number, quantity_available, quarantined_quantity,
                       purchase_price, sale_price, expiry_date, status
                FROM medicine_batches
                WHERE batch_id = ? FOR UPDATE
            ");
            $bStmt->execute([$batchId]);
            $batch = $bStmt->fetch(PDO::FETCH_ASSOC);

            if (!$batch) {
                throw new InvalidArgumentException("Batch #{$batchId} not found.");
            }

            $avail = (int)$batch['quantity_available'];
            $medId = (int)$batch['medicine_id'];

            if ($avail > 0) {
                // Update batch stock
                $updBatch = $this->pdo->prepare("
                    UPDATE medicine_batches
                    SET quantity_available = 0, quarantined_quantity = quarantined_quantity + ?,
                        status = 'Expired', updated_at = NOW()
                    WHERE batch_id = ?
                ");
                $updBatch->execute([$avail, $batchId]);

                // Decrement master medicine stock
                $updMed = $this->pdo->prepare("
                    UPDATE medicines
                    SET stock_quantity = stock_quantity - ?, updated_at = NOW()
                    WHERE medicine_id = ?
                ");
                $updMed->execute([$avail, $medId]);

                // Create quarantine record
                $qrnNo = $this->seqService->generate('QUARANTINE', 'QRN-');
                $insQrn = $this->pdo->prepare("
                    INSERT INTO pharmacy_quarantine_records (
                        quarantine_no, medicine_id, batch_id, quantity, reason,
                        source_type, source_id, status, notes, created_by, created_at
                    ) VALUES (
                        ?, ?, ?, ?, 'EXPIRY_SUSPECT',
                        'INVENTORY', NULL, 'QUARANTINED', ?, ?, NOW()
                    )
                ");
                $insQrn->execute([
                    $qrnNo,
                    $medId,
                    $batchId,
                    $avail,
                    "Auto-quarantined on expiry date ({$batch['expiry_date']})",
                    $userId
                ]);

                // Append EXPIRY to stock ledger
                $this->ledgerService->recordEntry(
                    $medId,
                    $batchId,
                    'EXPIRY',
                    -$avail,
                    (float)$batch['purchase_price'],
                    (float)$batch['sale_price'],
                    $batchId,
                    $batch['batch_number'],
                    $userId,
                    "Expired batch #{$batch['batch_number']} quarantined for disposal"
                );
            } else {
                // Just mark status Expired
                $updStatus = $this->pdo->prepare("UPDATE medicine_batches SET status = 'Expired', updated_at = NOW() WHERE batch_id = ?");
                $updStatus->execute([$batchId]);
            }

            // Audit
            $this->auditService->logAction(
                $userId,
                'QUARANTINE_EXPIRED',
                'medicine_batches',
                $batchId,
                ['status' => $batch['status']],
                ['status' => 'Expired', 'quarantined_quantity' => $avail]
            );

            $this->pdo->commit();

            return [
                'batch_id'             => $batchId,
                'quarantined_quantity' => $avail,
                'status'               => 'Expired'
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Authoritatively record permanent disposal/destruction of a batch.
     * Decrements physical stock from quarantined/damaged/available pools,
     * sets batch status to 'Disposed' if depleted, and creates immutable DISPOSAL ledger entry.
     */
    public function recordDisposal(array $data, int $userId = 1): array
    {
        $batchId = (int)($data['batch_id'] ?? 0);
        $qty = (int)($data['quantity'] ?? 0);
        $reason = trim($data['reason'] ?? 'EXPIRED');
        $method = trim($data['disposal_method'] ?? 'INCINERATION');
        $witness = trim($data['witness_name'] ?? '');
        $notes = trim($data['notes'] ?? '');
        $sourceType = trim($data['source_type'] ?? 'MANUAL');
        $sourceId = !empty($data['source_id']) ? (int)$data['source_id'] : null;
        $idempotencyKey = trim($data['idempotency_key'] ?? '');

        if ($batchId <= 0) {
            throw new InvalidArgumentException("Valid Batch ID is required for disposal.");
        }
        if ($qty <= 0) {
            throw new InvalidArgumentException("Disposal quantity must be greater than zero.");
        }

        if ($idempotencyKey !== '') {
            $dupCheck = $this->pdo->prepare("SELECT disposal_id, disposal_no FROM pharmacy_disposals WHERE idempotency_key = ?");
            $dupCheck->execute([$idempotencyKey]);
            $existing = $dupCheck->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                return [
                    'disposal_id'       => (int)$existing['disposal_id'],
                    'disposal_no'       => $existing['disposal_no'],
                    'already_processed' => true
                ];
            }
        }

        $this->pdo->beginTransaction();
        try {
            $bStmt = $this->pdo->prepare("
                SELECT batch_id, medicine_id, batch_number, quantity_available, quarantined_quantity,
                       damaged_quantity, disposed_quantity, purchase_price, sale_price, status
                FROM medicine_batches
                WHERE batch_id = ? FOR UPDATE
            ");
            $bStmt->execute([$batchId]);
            $batch = $bStmt->fetch(PDO::FETCH_ASSOC);

            if (!$batch) {
                throw new InvalidArgumentException("Batch #{$batchId} not found.");
            }

            $medId = (int)$batch['medicine_id'];
            $avail = (int)$batch['quantity_available'];
            $quarantined = (int)$batch['quarantined_quantity'];
            $damaged = (int)$batch['damaged_quantity'];

            // Determine stock pool to deduct from
            $ledgerQuantityChange = 0;
            if ($quarantined >= $qty) {
                // Deduct from quarantine pool (already decremented from available stock previously)
                $updBatch = $this->pdo->prepare("
                    UPDATE medicine_batches
                    SET quarantined_quantity = quarantined_quantity - ?,
                        disposed_quantity = disposed_quantity + ?,
                        updated_at = NOW()
                    WHERE batch_id = ?
                ");
                $updBatch->execute([$qty, $qty, $batchId]);
                $ledgerQuantityChange = -$qty;
            } elseif ($damaged >= $qty) {
                // Deduct from damaged pool
                $updBatch = $this->pdo->prepare("
                    UPDATE medicine_batches
                    SET damaged_quantity = damaged_quantity - ?,
                        disposed_quantity = disposed_quantity + ?,
                        updated_at = NOW()
                    WHERE batch_id = ?
                ");
                $updBatch->execute([$qty, $qty, $batchId]);
                $ledgerQuantityChange = -$qty;
            } elseif ($avail >= $qty) {
                // Deduct from available stock directly
                $updBatch = $this->pdo->prepare("
                    UPDATE medicine_batches
                    SET quantity_available = quantity_available - ?,
                        disposed_quantity = disposed_quantity + ?,
                        updated_at = NOW()
                    WHERE batch_id = ?
                ");
                $updBatch->execute([$qty, $qty, $batchId]);

                // Also decrement medicine master stock
                $updMed = $this->pdo->prepare("
                    UPDATE medicines
                    SET stock_quantity = stock_quantity - ?, updated_at = NOW()
                    WHERE medicine_id = ?
                ");
                $updMed->execute([$qty, $medId]);
                $ledgerQuantityChange = -$qty;
            } else {
                throw new InvalidArgumentException(
                    "Cannot dispose {$qty} units. Insufficient stock across all pools (Quarantined: {$quarantined}, Damaged: {$damaged}, Available: {$avail})."
                );
            }

            // Check if batch is completely consumed/disposed
            $bRecheckStmt = $this->pdo->prepare("
                SELECT quantity_available, quarantined_quantity, damaged_quantity
                FROM medicine_batches WHERE batch_id = ?
            ");
            $bRecheckStmt->execute([$batchId]);
            $recheck = $bRecheckStmt->fetch(PDO::FETCH_ASSOC);

            if ((int)$recheck['quantity_available'] === 0 && (int)$recheck['quarantined_quantity'] === 0 && (int)$recheck['damaged_quantity'] === 0) {
                $pdoUpdStatus = $this->pdo->prepare("UPDATE medicine_batches SET status = 'Disposed', updated_at = NOW() WHERE batch_id = ?");
                $pdoUpdStatus->execute([$batchId]);
            }

            // Generate Disposal Number
            $dispNo = $this->seqService->generate('DISPOSAL', 'DISP-');

            // Insert into pharmacy_disposals
            $insDisp = $this->pdo->prepare("
                INSERT INTO pharmacy_disposals (
                    disposal_no, disposal_date, medicine_id, batch_id, quantity,
                    reason, source_type, source_id, disposal_method, witness_name,
                    status, idempotency_key, notes, created_by, approved_by, executed_by, created_at
                ) VALUES (
                    ?, CURDATE(), ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    'DISPOSED', ?, ?, ?, ?, ?, NOW()
                )
            ");
            $insDisp->execute([
                $dispNo,
                $medId,
                $batchId,
                $qty,
                $reason,
                $sourceType,
                $sourceId,
                $method,
                $witness ?: null,
                $idempotencyKey ?: null,
                $notes ?: null,
                $userId,
                $userId,
                $userId
            ]);
            $disposalId = (int)$this->pdo->lastInsertId();

            // Append immutable DISPOSAL entry to stock ledger
            $this->ledgerService->recordEntry(
                $medId,
                $batchId,
                'DISPOSAL',
                $ledgerQuantityChange,
                (float)$batch['purchase_price'],
                (float)$batch['sale_price'],
                $disposalId,
                $dispNo,
                $userId,
                "Authorized destruction #{$dispNo} via {$method}: {$reason}"
            );

            // Audit log
            $this->auditService->logAction(
                $userId,
                'DISPOSE_STOCK',
                'pharmacy_disposals',
                $disposalId,
                null,
                [
                    'disposal_no' => $dispNo,
                    'batch_id'    => $batchId,
                    'quantity'    => $qty,
                    'method'      => $method,
                    'reason'      => $reason
                ]
            );

            $this->pdo->commit();

            return [
                'disposal_id'       => $disposalId,
                'disposal_no'       => $dispNo,
                'batch_id'          => $batchId,
                'quantity'          => $qty,
                'already_processed' => false
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Complete 360-degree Batch Traceability Timeline.
     * Traces: Procurement / GRN -> Inward -> Dispensed Sales -> Customer Returns ->
     *         Purchase Returns -> Damages -> Quarantine -> Disposal.
     */
    public function getBatchLifecycle(int $batchId): array
    {
        // Batch & Medicine Info
        $bStmt = $this->pdo->prepare("
            SELECT mb.*, m.medicine_name, m.generic_name, m.dosage_form, m.strength,
                   s.supplier_name, s.phone AS supplier_phone, s.contact_person
            FROM medicine_batches mb
            JOIN medicines m ON mb.medicine_id = m.medicine_id
            LEFT JOIN pharmacy_suppliers s ON mb.supplier_id = s.supplier_id
            WHERE mb.batch_id = ?
        ");
        $bStmt->execute([$batchId]);
        $batch = $bStmt->fetch(PDO::FETCH_ASSOC);

        if (!$batch) {
            throw new InvalidArgumentException("Batch #{$batchId} not found.");
        }

        // Ledger History
        $lStmt = $this->pdo->prepare("
            SELECT sl.*, u.username AS performed_by_user
            FROM pharmacy_stock_ledger sl
            LEFT JOIN pharmacy_users u ON sl.created_by = u.id
            WHERE sl.batch_id = ?
            ORDER BY sl.ledger_id ASC
        ");
        $lStmt->execute([$batchId]);
        $ledgerEntries = $lStmt->fetchAll(PDO::FETCH_ASSOC);

        // Sales Allocations
        $sStmt = $this->pdo->prepare("
            SELECT sib.allocated_quantity, sib.unit_price, s.sale_id, s.sale_number,
                   s.sale_type, s.sale_date, s.customer_name, s.status AS sale_status
            FROM pharmacy_sale_item_batches sib
            JOIN pharmacy_sales s ON sib.sale_id = s.sale_id
            WHERE sib.batch_id = ?
            ORDER BY s.sale_id DESC
        ");
        $sStmt->execute([$batchId]);
        $sales = $sStmt->fetchAll(PDO::FETCH_ASSOC);

        // Sales Returns
        $srStmt = $this->pdo->prepare("
            SELECT sri.return_quantity, sri.refund_amount, sri.condition_status, sri.restock_decision,
                   sr.return_id, sr.return_number, sr.return_date, sr.sale_number
            FROM pharmacy_sales_return_items sri
            JOIN pharmacy_sales_returns sr ON sri.return_id = sr.return_id
            WHERE sri.batch_id = ?
            ORDER BY sr.return_id DESC
        ");
        $srStmt->execute([$batchId]);
        $salesReturns = $srStmt->fetchAll(PDO::FETCH_ASSOC);

        // Purchase Returns
        $prStmt = $this->pdo->prepare("
            SELECT pri.quantity, pri.refund_rate, pri.amount, pri.reason,
                   pr.return_id, pr.return_number, pr.return_date
            FROM pharmacy_purchase_return_items pri
            JOIN pharmacy_purchase_returns pr ON pri.return_id = pr.return_id
            WHERE pri.batch_id = ?
            ORDER BY pr.return_id DESC
        ");
        $prStmt->execute([$batchId]);
        $purchaseReturns = $prStmt->fetchAll(PDO::FETCH_ASSOC);

        // Quarantine History
        $qStmt = $this->pdo->prepare("
            SELECT * FROM pharmacy_quarantine_records
            WHERE batch_id = ?
            ORDER BY quarantine_id DESC
        ");
        $qStmt->execute([$batchId]);
        $quarantines = $qStmt->fetchAll(PDO::FETCH_ASSOC);

        // Disposal History
        $dStmt = $this->pdo->prepare("
            SELECT * FROM pharmacy_disposals
            WHERE batch_id = ?
            ORDER BY disposal_id DESC
        ");
        $dStmt->execute([$batchId]);
        $disposals = $dStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'batch'            => $batch,
            'ledger_entries'   => $ledgerEntries,
            'sales'            => $sales,
            'sales_returns'    => $salesReturns,
            'purchase_returns' => $purchaseReturns,
            'quarantines'      => $quarantines,
            'disposals'        => $disposals
        ];
    }

    /**
     * Mathematical reconciliation of physical batch inventory against the append-only stock ledger.
     */
    public function reconcileBatchStock(int $batchId): array
    {
        $bStmt = $this->pdo->prepare("
            SELECT mb.*, m.medicine_name
            FROM medicine_batches mb
            JOIN medicines m ON mb.medicine_id = m.medicine_id
            WHERE mb.batch_id = ?
        ");
        $bStmt->execute([$batchId]);
        $batch = $bStmt->fetch(PDO::FETCH_ASSOC);

        if (!$batch) {
            throw new InvalidArgumentException("Batch #{$batchId} not found.");
        }

        // Aggregate ledger movements for this batch
        $lStmt = $this->pdo->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN transaction_type IN ('PURCHASE', 'OPENING_STOCK', 'INITIAL_STOCK') THEN quantity_change ELSE 0 END), 0) AS total_inward,
                COALESCE(SUM(CASE WHEN transaction_type IN ('COUNTER_SALE', 'PRESCRIPTION_SALE', 'IPD_SALE', 'IPD_INDENT', 'SALE') THEN ABS(quantity_change) ELSE 0 END), 0) AS total_sales,
                COALESCE(SUM(CASE WHEN transaction_type = 'SALE_RETURN' THEN quantity_change ELSE 0 END), 0) AS total_sale_returns,
                COALESCE(SUM(CASE WHEN transaction_type = 'PURCHASE_RETURN' THEN ABS(quantity_change) ELSE 0 END), 0) AS total_purchase_returns,
                COALESCE(SUM(CASE WHEN transaction_type = 'DAMAGE' THEN ABS(quantity_change) ELSE 0 END), 0) AS total_damage,
                COALESCE(SUM(CASE WHEN transaction_type = 'EXPIRY' THEN ABS(quantity_change) ELSE 0 END), 0) AS total_expiry,
                COALESCE(SUM(CASE WHEN transaction_type = 'DISPOSAL' THEN ABS(quantity_change) ELSE 0 END), 0) AS total_disposed,
                COALESCE(SUM(CASE WHEN transaction_type = 'ADJUSTMENT' THEN quantity_change ELSE 0 END), 0) AS total_adjustments,
                COALESCE(SUM(quantity_change), 0) AS net_ledger_balance
            FROM pharmacy_stock_ledger
            WHERE batch_id = ?
        ");
        $lStmt->execute([$batchId]);
        $agg = $lStmt->fetch(PDO::FETCH_ASSOC);

        $netLedgerBalance = (int)$agg['net_ledger_balance'];
        $actualPhysicalAvailable = (int)$batch['quantity_available'];
        $quarantined = (int)$batch['quarantined_quantity'];
        $damaged = (int)$batch['damaged_quantity'];
        $disposed = (int)$batch['disposed_quantity'];
        $reserved = (int)$batch['reserved_quantity'];

        $difference = $actualPhysicalAvailable - $netLedgerBalance;
        $isReconciled = ($difference === 0);

        return [
            'batch_id'             => $batchId,
            'batch_number'         => $batch['batch_number'],
            'medicine_name'        => $batch['medicine_name'],
            'physical_available'   => $actualPhysicalAvailable,
            'reserved'             => $reserved,
            'quarantined'          => $quarantined,
            'damaged'              => $damaged,
            'disposed'             => $disposed,
            'net_ledger_balance'   => $netLedgerBalance,
            'ledger_inward'        => (int)$agg['total_inward'],
            'ledger_sales'         => (int)$agg['total_sales'],
            'ledger_sale_returns'  => (int)$agg['total_sale_returns'],
            'ledger_purchase_returns' => (int)$agg['total_purchase_returns'],
            'ledger_damage'        => (int)$agg['total_damage'],
            'ledger_expiry'        => (int)$agg['total_expiry'],
            'ledger_disposal'      => (int)$agg['total_disposed'],
            'ledger_adjustments'   => (int)$agg['total_adjustments'],
            'difference'           => $difference,
            'is_reconciled'        => $isReconciled
        ];
    }
}
