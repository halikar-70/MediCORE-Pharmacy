<?php
// app/Services/DispensingService.php - IPD & Clinical Dispensing Engine with Strict FEFO & Batch Traceability

namespace Pharmacy\Services;

use PDO;
use Exception;
use InvalidArgumentException;

class DispensingService
{
    private PDO $pdo;
    private FefoService $fefoService;
    private DocumentSequenceService $seqService;
    private AuditService $auditService;
    private PrescriptionService $rxService;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->fefoService = new FefoService($pdo);
        $this->seqService = new DocumentSequenceService($pdo);
        $this->auditService = new AuditService($pdo);
        $this->rxService = new PrescriptionService($pdo);
    }

    /**
     * Fetch IPD Dispensing Queue items.
     * Shows pending, partial, and urgent medications across IPD admissions and prescriptions.
     */
    public function getDispensingQueue(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ["rx.status IN ('PENDING', 'VERIFIED', 'PARTIALLY_DISPENSED', 'ACTIVE')"];
        $where[] = "rxi.status = 'ACTIVE'";
        $where[] = "(rxi.prescribed_qty - rxi.dispensed_qty) > 0";
        $params = [];

        if (!empty($filters['status'])) {
            if ($filters['status'] === 'PENDING') {
                $where[] = "rxi.dispensed_qty = 0";
            } elseif ($filters['status'] === 'PARTIAL') {
                $where[] = "rxi.dispensed_qty > 0";
            }
        }

        if (!empty($filters['patient_type'])) {
            $where[] = "rx.patient_type = ?";
            $params[] = $filters['patient_type'];
        }

        if (!empty($filters['ward'])) {
            $where[] = "rx.ward = ?";
            $params[] = $filters['ward'];
        }

        if (!empty($filters['ipd_admission_no'])) {
            $where[] = "rx.ipd_admission_no = ?";
            $params[] = $filters['ipd_admission_no'];
        }

        if (!empty($filters['medicine_id'])) {
            $where[] = "rxi.medicine_id = ?";
            $params[] = (int)$filters['medicine_id'];
        }

        if (!empty($filters['date'])) {
            $where[] = "rx.prescription_date = ?";
            $params[] = $filters['date'];
        }

        if (!empty($filters['search'])) {
            $s = '%' . trim($filters['search']) . '%';
            $where[] = "(rx.prescription_number LIKE ? OR rx.patient_name LIKE ? OR rx.ipd_admission_no LIKE ? OR m.medicine_name LIKE ?)";
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
        }

        $whereSql = implode(' AND ', $where);
        $sql = "
            SELECT rxi.item_id, rxi.prescription_id, rxi.medicine_id, rxi.dose, rxi.dose_unit,
                   rxi.route, rxi.schedule, rxi.prescribed_qty, rxi.dispensed_qty,
                   (rxi.prescribed_qty - rxi.dispensed_qty) as remaining_qty,
                   rxi.dosage_instructions, rxi.frequency, rxi.duration_days,
                   rx.prescription_number, rx.prescription_date, rx.patient_type,
                   rx.patient_id, rx.patient_name, rx.ipd_admission_no, rx.ward, rx.bed_number,
                   rx.doctor_name, rx.status as prescription_status,
                   m.medicine_name, m.generic_name, m.dosage_form, m.strength,
                   COALESCE((
                       SELECT SUM(mb.quantity_available)
                       FROM medicine_batches mb
                       WHERE mb.medicine_id = m.medicine_id
                         AND mb.status = 'Active'
                         AND mb.quantity_available > 0
                         AND mb.expiry_date >= CURDATE()
                   ), 0) as available_stock
            FROM pharmacy_prescription_items rxi
            JOIN pharmacy_prescriptions rx ON rxi.prescription_id = rx.prescription_id
            JOIN medicines m ON rxi.medicine_id = m.medicine_id
            WHERE {$whereSql}
            ORDER BY rx.prescription_id ASC, rxi.item_id ASC
            LIMIT {$limit} OFFSET {$offset}
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Atomically dispense medication against a prescription using FEFO.
     * Enforces:
     * - Row locking on prescription items and batches
     * - Requested quantity <= remaining quantity
     * - Batch-level traceability in pharmacy_dispensing_records and pharmacy_dispensing_batches
     * - Single stock deduction in medicine_batches and pharmacy_stock_ledger
     * - Idempotency protection
     *
     * @param int $prescriptionId
     * @param array $itemsToDispense Array of ['item_id' => int, 'quantity' => int]
     * @param array $meta Additional metadata (notes, ward, bed, idempotency_key, etc.)
     * @param int $userId
     * @return array Dispensing result summary
     * @throws Exception
     */
    public function dispensePrescription(int $prescriptionId, array $itemsToDispense, array $meta = [], int $userId = 1): array
    {
        if (empty($itemsToDispense)) {
            throw new InvalidArgumentException("At least one item must be selected for dispensing.");
        }

        // Check idempotency
        $idempotencyKey = !empty($meta['idempotency_key']) ? trim($meta['idempotency_key']) : null;
        if ($idempotencyKey !== null) {
            $chk = $this->pdo->prepare("SELECT dispensing_id FROM pharmacy_dispensing_records WHERE idempotency_key = ?");
            $chk->execute([$idempotencyKey]);
            $existingDispId = $chk->fetchColumn();
            if ($existingDispId) {
                return $this->getDispensingRecord((int)$existingDispId);
            }
        }

        $this->pdo->beginTransaction();
        try {
            // 1. Lock prescription header
            $rxStmt = $this->pdo->prepare("
                SELECT * FROM pharmacy_prescriptions 
                WHERE prescription_id = ? FOR UPDATE
            ");
            $rxStmt->execute([$prescriptionId]);
            $rx = $rxStmt->fetch(PDO::FETCH_ASSOC);

            if (!$rx) {
                throw new Exception("Prescription #{$prescriptionId} not found.");
            }

            if (in_array($rx['status'], ['DISPENSED', 'COMPLETED'], true)) {
                throw new Exception("Prescription '{$rx['prescription_number']}' has already been FULLY DISPENSED.");
            }
            if ($rx['status'] === 'CANCELLED') {
                throw new Exception("Prescription '{$rx['prescription_number']}' is CANCELLED. Dispensing blocked.");
            }
            if ($rx['status'] === 'DISCONTINUED') {
                throw new Exception("Prescription '{$rx['prescription_number']}' is DISCONTINUED. Dispensing blocked.");
            }

            // 2. Lock and validate items
            $itemLockStmt = $this->pdo->prepare("
                SELECT rxi.*, m.medicine_name, m.status as med_status
                FROM pharmacy_prescription_items rxi
                JOIN medicines m ON rxi.medicine_id = m.medicine_id
                WHERE rxi.item_id = ? AND rxi.prescription_id = ? FOR UPDATE
            ");

            $allAllocations = [];
            $processedItems = [];

            foreach ($itemsToDispense as $dItem) {
                $itemId = (int)($dItem['item_id'] ?? 0);
                $qty = (int)($dItem['quantity'] ?? 0);

                if ($qty <= 0) {
                    continue;
                }

                $itemLockStmt->execute([$itemId, $prescriptionId]);
                $item = $itemLockStmt->fetch(PDO::FETCH_ASSOC);

                if (!$item) {
                    throw new Exception("Prescription item #{$itemId} does not belong to Prescription {$rx['prescription_number']}.");
                }

                if ($item['status'] === 'DISCONTINUED') {
                    throw new Exception("Prescription item '{$item['medicine_name']}' is DISCONTINUED.");
                }

                $prescribed = (int)$item['prescribed_qty'];
                $alreadyDispensed = (int)$item['dispensed_qty'];
                $remaining = $prescribed - $alreadyDispensed;

                if ($qty > $remaining) {
                    throw new Exception("Requested quantity ({$qty}) exceeds remaining prescribed quantity ({$remaining}) for '{$item['medicine_name']}'. Over-dispensing blocked.");
                }

                // 3. Allocate batches via FEFO (with FOR UPDATE locks)
                $medId = (int)$item['medicine_id'];
                $allocations = $this->fefoService->allocate($medId, $qty, true);

                $allAllocations[] = [
                    'item'        => $item,
                    'quantity'    => $qty,
                    'allocations' => $allocations
                ];

                $processedItems[] = [
                    'item_id'     => $itemId,
                    'medicine_id' => $medId,
                    'quantity'    => $qty,
                    'new_dispensed' => $alreadyDispensed + $qty
                ];
            }

            if (empty($allAllocations)) {
                throw new InvalidArgumentException("No valid medication items with quantity > 0 were specified.");
            }

            // 4. Generate dispensing sequence number (e.g. DSP-000001)
            $dispensingNumber = $this->seqService->generate('DISPENSING');

            // 5. Create dispensing header record
            $ward = !empty($meta['ward']) ? trim($meta['ward']) : $rx['ward'];
            $bed = !empty($meta['bed']) ? trim($meta['bed']) : $rx['bed_number'];
            $notes = !empty($meta['notes']) ? trim($meta['notes']) : null;
            $patientType = $rx['patient_type'] ?? 'IPD';

            $insDisp = $this->pdo->prepare("
                INSERT INTO pharmacy_dispensing_records (
                    dispensing_number, prescription_id, indent_id, sale_id,
                    patient_type, patient_id, patient_name, ipd_admission_no,
                    ward, bed, dispensing_date, status, notes, dispensed_by,
                    idempotency_key, created_at
                ) VALUES (
                    ?, ?, NULL, NULL,
                    ?, ?, ?, ?,
                    ?, ?, NOW(), 'COMPLETED', ?, ?,
                    ?, NOW()
                )
            ");
            $insDisp->execute([
                $dispensingNumber,
                $prescriptionId,
                $patientType,
                $rx['patient_id'],
                $rx['patient_name'],
                $rx['ipd_admission_no'],
                $ward,
                $bed,
                $notes,
                $userId,
                $idempotencyKey
            ]);
            $dispensingId = (int)$this->pdo->lastInsertId();

            // 6. Execute stock deduction and record batch allocations
            $insBatch = $this->pdo->prepare("
                INSERT INTO pharmacy_dispensing_batches (
                    dispensing_id, prescription_item_id, indent_item_id,
                    medicine_id, batch_id, batch_number, expiry_date,
                    dispensed_qty, unit_cost, unit_price, stock_ledger_id, created_at
                ) VALUES (
                    ?, ?, NULL,
                    ?, ?, ?, ?,
                    ?, ?, ?, NULL, NOW()
                )
            ");

            $updItem = $this->pdo->prepare("
                UPDATE pharmacy_prescription_items
                SET dispensed_qty = ?, updated_at = NOW()
                WHERE item_id = ?
            ");

            foreach ($allAllocations as $allocGroup) {
                $item = $allocGroup['item'];
                $allocList = $allocGroup['allocations'];

                // Deduct stock once via FEFO service under 'DISPENSING'
                $this->fefoService->executeDeduction(
                    $allocList,
                    'DISPENSING',
                    $dispensingId,
                    $dispensingNumber,
                    $userId,
                    "Clinical Dispensing #{$dispensingNumber} for Prescription {$rx['prescription_number']}"
                );

                // Record batch-level traceability
                foreach ($allocList as $bAlloc) {
                    $insBatch->execute([
                        $dispensingId,
                        $item['item_id'],
                        $item['medicine_id'],
                        $bAlloc['batch_id'],
                        $bAlloc['batch_number'],
                        $bAlloc['expiry_date'],
                        $bAlloc['allocated_quantity'],
                        $bAlloc['purchase_price'] ?? 0.00,
                        $bAlloc['sale_price'] ?? 0.00
                    ]);
                }

                // Update item dispensed count
                $newDispQty = (int)$item['dispensed_qty'] + $allocGroup['quantity'];
                $updItem->execute([$newDispQty, $item['item_id']]);
            }

            // 7. Update overall prescription status
            $checkAllStmt = $this->pdo->prepare("
                SELECT prescribed_qty, dispensed_qty, status
                FROM pharmacy_prescription_items
                WHERE prescription_id = ?
            ");
            $checkAllStmt->execute([$prescriptionId]);
            $allItems = $checkAllStmt->fetchAll(PDO::FETCH_ASSOC);

            $allFulfilled = true;
            $anyDispensed = false;
            foreach ($allItems as $ai) {
                if ($ai['status'] !== 'DISCONTINUED' && (int)$ai['dispensed_qty'] < (int)$ai['prescribed_qty']) {
                    $allFulfilled = false;
                }
                if ((int)$ai['dispensed_qty'] > 0) {
                    $anyDispensed = true;
                }
            }

            $newRxStatus = 'PENDING';
            if ($allFulfilled) {
                $newRxStatus = 'DISPENSED';
            } elseif ($anyDispensed) {
                $newRxStatus = 'PARTIALLY_DISPENSED';
            }

            $updRx = $this->pdo->prepare("
                UPDATE pharmacy_prescriptions
                SET status = ?, updated_at = NOW()
                WHERE prescription_id = ?
            ");
            $updRx->execute([$newRxStatus, $prescriptionId]);

            // 8. Audit logging
            $this->auditService->logAction(
                $userId,
                'DISPENSING_COMPLETED',
                'pharmacy_dispensing_records',
                (string)$dispensingId,
                ['prescription_id' => $prescriptionId, 'rx_status' => $rx['status']],
                ['dispensing_number' => $dispensingNumber, 'new_rx_status' => $newRxStatus, 'items_count' => count($allAllocations)]
            );

            $this->pdo->commit();
            return $this->getDispensingRecord($dispensingId);

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Get a full dispensing record with its batch allocations.
     */
    public function getDispensingRecord(int $dispensingId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT d.*, u.full_name as dispensed_by_name,
                   rx.prescription_number, rx.doctor_name
            FROM pharmacy_dispensing_records d
            LEFT JOIN pharmacy_users u ON d.dispensed_by = u.id
            LEFT JOIN pharmacy_prescriptions rx ON d.prescription_id = rx.prescription_id
            WHERE d.dispensing_id = ?
        ");
        $stmt->execute([$dispensingId]);
        $rec = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rec) {
            return null;
        }

        $bStmt = $this->pdo->prepare("
            SELECT db.*, m.medicine_name, m.generic_name, m.dosage_form, m.strength
            FROM pharmacy_dispensing_batches db
            JOIN medicines m ON db.medicine_id = m.medicine_id
            WHERE db.dispensing_id = ?
            ORDER BY db.id ASC
        ");
        $bStmt->execute([$dispensingId]);
        $rec['batches'] = $bStmt->fetchAll(PDO::FETCH_ASSOC);

        return $rec;
    }

    /**
     * List dispensing history records with filters.
     */
    public function listDispensingRecords(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ["1=1"];
        $params = [];

        if (!empty($filters['patient_type'])) {
            $where[] = "d.patient_type = ?";
            $params[] = $filters['patient_type'];
        }

        if (!empty($filters['ward'])) {
            $where[] = "d.ward = ?";
            $params[] = $filters['ward'];
        }

        if (!empty($filters['ipd_admission_no'])) {
            $where[] = "d.ipd_admission_no = ?";
            $params[] = $filters['ipd_admission_no'];
        }

        if (!empty($filters['start_date'])) {
            $where[] = "DATE(d.dispensing_date) >= ?";
            $params[] = $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $where[] = "DATE(d.dispensing_date) <= ?";
            $params[] = $filters['end_date'];
        }

        if (!empty($filters['search'])) {
            $s = '%' . trim($filters['search']) . '%';
            $where[] = "(d.dispensing_number LIKE ? OR d.patient_name LIKE ? OR d.ipd_admission_no LIKE ? OR rx.prescription_number LIKE ?)";
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
        }

        $whereSql = implode(' AND ', $where);
        $sql = "
            SELECT d.*, u.full_name as dispensed_by_name,
                   rx.prescription_number, rx.doctor_name,
                   (SELECT COUNT(*) FROM pharmacy_dispensing_batches WHERE dispensing_id = d.dispensing_id) as batch_count,
                   (SELECT SUM(dispensed_qty) FROM pharmacy_dispensing_batches WHERE dispensing_id = d.dispensing_id) as total_units
            FROM pharmacy_dispensing_records d
            LEFT JOIN pharmacy_users u ON d.dispensed_by = u.id
            LEFT JOIN pharmacy_prescriptions rx ON d.prescription_id = rx.prescription_id
            WHERE {$whereSql}
            ORDER BY d.dispensing_id DESC
            LIMIT {$limit} OFFSET {$offset}
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
