<?php
// app/Services/PrescriptionService.php - Prescription Verification, Lifecycle & Dispensing Engine

namespace Pharmacy\Services;

use PDO;
use Exception;
use InvalidArgumentException;

class PrescriptionService
{
    private PDO $pdo;
    private DocumentSequenceService $seqService;
    private AuditService $auditService;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->seqService = new DocumentSequenceService($pdo);
        $this->auditService = new AuditService($pdo);
    }

    /**
     * Create a new standalone electronic prescription with duplicate protection and idempotency.
     */
    public function createPrescription(array $data, array $items, ?int $userId = null): array
    {
        if (empty($items)) {
            throw new InvalidArgumentException("Prescription must have at least one medication item.");
        }

        $patientName = trim($data['patient_name'] ?? '');
        if ($patientName === '') {
            throw new InvalidArgumentException("Patient name is required.");
        }

        $doctorName = trim($data['doctor_name'] ?? '');
        if ($doctorName === '') {
            throw new InvalidArgumentException("Doctor name is required.");
        }

        // Validate items first
        $medCheckStmt = $this->pdo->prepare("SELECT medicine_id, medicine_name, status FROM medicines WHERE medicine_id = ? AND deleted_at IS NULL");
        foreach ($items as $it) {
            $medId = (int)($it['medicine_id'] ?? 0);
            $prescribedQty = (int)($it['prescribed_qty'] ?? 0);

            if ($medId <= 0 || $prescribedQty <= 0) {
                throw new InvalidArgumentException("Each prescription item must have a valid medicine and prescribed quantity > 0.");
            }

            $medCheckStmt->execute([$medId]);
            $medRow = $medCheckStmt->fetch(PDO::FETCH_ASSOC);
            if (!$medRow) {
                throw new InvalidArgumentException("Medicine #{$medId} does not exist or has been archived.");
            }
            if ($medRow['status'] !== 'Active') {
                throw new InvalidArgumentException("Medicine '{$medRow['medicine_name']}' is not Active and cannot be prescribed.");
            }
        }

        // Idempotency protection
        $idempotencyKey = !empty($data['idempotency_key']) ? trim($data['idempotency_key']) : null;
        if ($idempotencyKey !== null) {
            $checkStmt = $this->pdo->prepare("SELECT prescription_id FROM pharmacy_prescriptions WHERE idempotency_key = ?");
            $checkStmt->execute([$idempotencyKey]);
            $existingId = $checkStmt->fetchColumn();
            if ($existingId) {
                return $this->getPrescription((int)$existingId);
            }
        }

        // Accidental rapid double-submission protection
        $dupCheck = $this->pdo->prepare("
            SELECT prescription_id FROM pharmacy_prescriptions 
            WHERE patient_name = ? AND doctor_name = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 15 SECOND)
              AND status NOT IN ('CANCELLED', 'DISCONTINUED')
            ORDER BY prescription_id DESC LIMIT 1
        ");
        $dupCheck->execute([$patientName, $doctorName]);
        $duplicateId = $dupCheck->fetchColumn();
        if ($duplicateId && empty($data['allow_duplicate'])) {
            // Check if items match exactly (both medicines and prescribed quantities)
            $existingRx = $this->getPrescription((int)$duplicateId);
            if ($existingRx && count($existingRx['items']) === count($items)) {
                $existingItemsSig = array_map(fn($i) => (int)$i['medicine_id'] . ':' . (int)$i['prescribed_qty'], $existingRx['items']);
                $newItemsSig = array_map(fn($i) => (int)$i['medicine_id'] . ':' . (int)$i['prescribed_qty'], $items);
                sort($existingItemsSig);
                sort($newItemsSig);
                if ($existingItemsSig === $newItemsSig) {
                    return $existingRx;
                }
            }
        }

        $patientMobile = !empty($data['patient_mobile']) ? trim($data['patient_mobile']) : null;
        $patientId = !empty($data['patient_id']) ? (int)$data['patient_id'] : null;
        $patientType = in_array($data['patient_type'] ?? '', ['OPD', 'IPD', 'EXTERNAL'], true) ? $data['patient_type'] : 'OPD';
        $department = !empty($data['department']) ? trim($data['department']) : null;
        $doctorRegNo = !empty($data['doctor_registration_no']) ? trim($data['doctor_registration_no']) : null;
        $ipdAdmissionNo = !empty($data['ipd_admission_no']) ? trim($data['ipd_admission_no']) : null;
        $ward = !empty($data['ward']) ? trim($data['ward']) : null;
        $bedNumber = !empty($data['bed_number']) ? trim($data['bed_number']) : null;
        $notes = !empty($data['notes']) ? trim($data['notes']) : null;
        $startDate = !empty($data['start_date']) ? $data['start_date'] : date('Y-m-d');
        $endDate = !empty($data['end_date']) ? $data['end_date'] : null;

        if ($endDate !== null && $startDate > $endDate) {
            throw new InvalidArgumentException("End date cannot be prior to start date.");
        }

        $initialStatus = in_array($data['status'] ?? '', ['DRAFT', 'ACTIVE', 'PENDING', 'VERIFIED'], true) 
            ? $data['status'] 
            : 'PENDING';

        $this->pdo->beginTransaction();
        try {
            // Generate prescription number (e.g. RX-000001)
            $rxSeq = $this->seqService->generate('PATIENT');
            $prescriptionNumber = 'RX-' . substr($rxSeq, 3);

            $insRx = $this->pdo->prepare("
                INSERT INTO pharmacy_prescriptions (
                    prescription_number, prescription_date, start_date, end_date,
                    patient_type, patient_id, patient_name, patient_mobile,
                    doctor_name, doctor_registration_no, department,
                    ipd_admission_no, ward, bed_number, status, notes,
                    idempotency_key, created_by, created_at
                ) VALUES (
                    ?, CURDATE(), ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, NOW()
                )
            ");
            $insRx->execute([
                $prescriptionNumber,
                $startDate,
                $endDate,
                $patientType,
                $patientId,
                $patientName,
                $patientMobile,
                $doctorName,
                $doctorRegNo,
                $department,
                $ipdAdmissionNo,
                $ward,
                $bedNumber,
                $initialStatus,
                $notes,
                $idempotencyKey,
                $userId
            ]);

            $prescriptionId = (int)$this->pdo->lastInsertId();

            $insItem = $this->pdo->prepare("
                INSERT INTO pharmacy_prescription_items (
                    prescription_id, medicine_id, dose, dose_unit, route, schedule,
                    prescribed_qty, dispensed_qty, dosage_instructions, frequency,
                    duration_days, start_date, end_date, status, notes, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, 0, ?, ?,
                    ?, ?, ?, 'ACTIVE', ?, NOW()
                )
            ");

            $medCheckStmt = $this->pdo->prepare("SELECT medicine_id, medicine_name, status FROM medicines WHERE medicine_id = ? AND deleted_at IS NULL");

            foreach ($items as $it) {
                $medId = (int)($it['medicine_id'] ?? 0);
                $prescribedQty = (int)($it['prescribed_qty'] ?? 0);

                if ($medId <= 0 || $prescribedQty <= 0) {
                    throw new InvalidArgumentException("Each prescription item must have a valid medicine and prescribed quantity > 0.");
                }

                $medCheckStmt->execute([$medId]);
                $medRow = $medCheckStmt->fetch(PDO::FETCH_ASSOC);
                if (!$medRow) {
                    throw new InvalidArgumentException("Medicine #{$medId} does not exist or has been archived.");
                }
                if ($medRow['status'] !== 'Active') {
                    throw new InvalidArgumentException("Medicine '{$medRow['medicine_name']}' is not Active and cannot be prescribed.");
                }

                $dose = isset($it['dose']) && $it['dose'] !== '' ? (float)$it['dose'] : null;
                $doseUnit = !empty($it['dose_unit']) ? trim($it['dose_unit']) : 'mg';
                $route = !empty($it['route']) ? trim($it['route']) : 'ORAL';
                $schedule = !empty($it['schedule']) ? trim($it['schedule']) : null;
                $dosage = $it['dosage_instructions'] ?? null;
                $freq = $it['frequency'] ?? null;
                $dur = !empty($it['duration_days']) ? (int)$it['duration_days'] : null;
                $itemStart = !empty($it['start_date']) ? $it['start_date'] : $startDate;
                $itemEnd = !empty($it['end_date']) ? $it['end_date'] : $endDate;
                $itNotes = $it['notes'] ?? null;

                $insItem->execute([
                    $prescriptionId,
                    $medId,
                    $dose,
                    $doseUnit,
                    $route,
                    $schedule,
                    $prescribedQty,
                    $dosage,
                    $freq,
                    $dur,
                    $itemStart,
                    $itemEnd,
                    $itNotes
                ]);
            }

            $this->auditService->logAction(
                $userId,
                'PRESCRIPTION_CREATED',
                'pharmacy_prescriptions',
                (string)$prescriptionId,
                null,
                ['patient_name' => $patientName, 'items_count' => count($items), 'status' => $initialStatus]
            );

            $this->pdo->commit();
            return $this->getPrescription($prescriptionId);

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Pharmacist verification of prescription.
     */
    public function verifyPrescription(int $prescriptionId, ?int $userId = null): array
    {
        $rx = $this->getPrescription($prescriptionId);
        if (!$rx) {
            throw new Exception("Prescription #{$prescriptionId} not found.");
        }
        if (in_array($rx['status'], ['CANCELLED', 'DISCONTINUED', 'EXPIRED'], true)) {
            throw new Exception("Cannot verify a {$rx['status']} prescription.");
        }

        $upd = $this->pdo->prepare("
            UPDATE pharmacy_prescriptions
            SET status = 'VERIFIED', verified_by = ?, verified_at = NOW(), updated_at = NOW()
            WHERE prescription_id = ?
        ");
        $upd->execute([$userId, $prescriptionId]);

        $this->auditService->logAction(
            $userId,
            'PRESCRIPTION_VERIFIED',
            'pharmacy_prescriptions',
            (string)$prescriptionId,
            ['status' => $rx['status']],
            ['status' => 'VERIFIED']
        );

        return $this->getPrescription($prescriptionId);
    }

    /**
     * Auditable amendment of an active prescription without destructive history overwrites.
     */
    public function amendPrescription(int $prescriptionId, array $changes, string $reason, int $userId): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException("Amendment reason is required.");
        }

        $rx = $this->getPrescription($prescriptionId);
        if (!$rx) {
            throw new Exception("Prescription #{$prescriptionId} not found.");
        }

        if (in_array($rx['status'], ['CANCELLED', 'DISCONTINUED', 'COMPLETED'], true)) {
            throw new Exception("Cannot amend a {$rx['status']} prescription.");
        }

        $this->pdo->beginTransaction();
        try {
            $insAmend = $this->pdo->prepare("
                INSERT INTO pharmacy_prescription_amendments (
                    prescription_id, prescription_item_id, field_name, old_value, new_value, amendment_reason, amended_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            // Process item updates
            if (!empty($changes['items'])) {
                $itemMap = [];
                foreach ($rx['items'] as $it) {
                    $itemMap[(int)$it['item_id']] = $it;
                }

                foreach ($changes['items'] as $cItem) {
                    $itemId = (int)($cItem['item_id'] ?? 0);
                    if (!isset($itemMap[$itemId])) {
                        continue;
                    }
                    $curItem = $itemMap[$itemId];

                    // Check fields to update
                    $updFields = [];
                    $params = [];

                    if (isset($cItem['prescribed_qty']) && (int)$cItem['prescribed_qty'] != (int)$curItem['prescribed_qty']) {
                        $newQty = (int)$cItem['prescribed_qty'];
                        if ($newQty < (int)$curItem['dispensed_qty']) {
                            throw new InvalidArgumentException("Cannot reduce prescribed quantity below already dispensed quantity ({$curItem['dispensed_qty']}).");
                        }
                        $insAmend->execute([$prescriptionId, $itemId, 'prescribed_qty', (string)$curItem['prescribed_qty'], (string)$newQty, $reason, $userId]);
                        $updFields[] = "prescribed_qty = ?";
                        $params[] = $newQty;
                    }

                    if (isset($cItem['dosage_instructions']) && trim($cItem['dosage_instructions']) !== (string)$curItem['dosage_instructions']) {
                        $newDosage = trim($cItem['dosage_instructions']);
                        $insAmend->execute([$prescriptionId, $itemId, 'dosage_instructions', $curItem['dosage_instructions'], $newDosage, $reason, $userId]);
                        $updFields[] = "dosage_instructions = ?";
                        $params[] = $newDosage;
                    }

                    if (isset($cItem['frequency']) && trim($cItem['frequency']) !== (string)$curItem['frequency']) {
                        $newFreq = trim($cItem['frequency']);
                        $insAmend->execute([$prescriptionId, $itemId, 'frequency', $curItem['frequency'], $newFreq, $reason, $userId]);
                        $updFields[] = "frequency = ?";
                        $params[] = $newFreq;
                    }

                    if (isset($cItem['dose']) && (float)$cItem['dose'] != (float)$curItem['dose']) {
                        $newDose = (float)$cItem['dose'];
                        $insAmend->execute([$prescriptionId, $itemId, 'dose', (string)$curItem['dose'], (string)$newDose, $reason, $userId]);
                        $updFields[] = "dose = ?";
                        $params[] = $newDose;
                    }

                    if (!empty($updFields)) {
                        $updFields[] = "updated_at = NOW()";
                        $params[] = $itemId;
                        $sql = "UPDATE pharmacy_prescription_items SET " . implode(', ', $updFields) . " WHERE item_id = ?";
                        $this->pdo->prepare($sql)->execute($params);
                    }
                }
            }

            // Header level notes or updates
            if (!empty($changes['notes']) && $changes['notes'] !== $rx['notes']) {
                $insAmend->execute([$prescriptionId, null, 'notes', $rx['notes'], $changes['notes'], $reason, $userId]);
                $this->pdo->prepare("UPDATE pharmacy_prescriptions SET notes = ?, updated_at = NOW() WHERE prescription_id = ?")
                    ->execute([$changes['notes'], $prescriptionId]);
            }

            $this->auditService->logAction(
                $userId,
                'PRESCRIPTION_AMENDED',
                'pharmacy_prescriptions',
                (string)$prescriptionId,
                null,
                ['reason' => $reason]
            );

            $this->pdo->commit();
            return $this->getPrescription($prescriptionId);

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Discontinue a prescription: preserves historical dispensing/MAR administration,
     * blocks future dispensing, and cancels future scheduled MAR doses.
     */
    public function discontinuePrescription(int $prescriptionId, string $reason, int $userId): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException("Discontinuation reason is required.");
        }

        $rx = $this->getPrescription($prescriptionId);
        if (!$rx) {
            throw new Exception("Prescription #{$prescriptionId} not found.");
        }

        if ($rx['status'] === 'DISCONTINUED') {
            throw new Exception("Prescription is already DISCONTINUED.");
        }

        $this->pdo->beginTransaction();
        try {
            // 1. Update prescription header
            $updRx = $this->pdo->prepare("
                UPDATE pharmacy_prescriptions
                SET status = 'DISCONTINUED', discontinued_by = ?, discontinued_at = NOW(),
                    discontinue_reason = ?, updated_at = NOW()
                WHERE prescription_id = ?
            ");
            $updRx->execute([$userId, $reason, $prescriptionId]);

            // 2. Mark active items as discontinued
            $updItems = $this->pdo->prepare("
                UPDATE pharmacy_prescription_items
                SET status = 'DISCONTINUED', discontinued_at = NOW(), discontinue_reason = ?
                WHERE prescription_id = ? AND status = 'ACTIVE'
            ");
            $updItems->execute([$reason, $prescriptionId]);

            // 3. Cancel any future scheduled MAR records (leave historical GIVEN, HELD, REFUSED intact)
            $cancelMar = $this->pdo->prepare("
                UPDATE pharmacy_mar_records
                SET status = 'CANCELLED', notes = CONCAT(COALESCE(notes, ''), ' [Cancelled due to Rx Discontinuation]'), updated_at = NOW()
                WHERE prescription_id = ? AND status = 'SCHEDULED'
            ");
            $cancelMar->execute([$prescriptionId]);

            $this->auditService->logAction(
                $userId,
                'PRESCRIPTION_DISCONTINUED',
                'pharmacy_prescriptions',
                (string)$prescriptionId,
                ['old_status' => $rx['status']],
                ['new_status' => 'DISCONTINUED', 'reason' => $reason]
            );

            $this->pdo->commit();
            return $this->getPrescription($prescriptionId);

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Cancel an un-dispensed prescription.
     */
    public function cancelPrescription(int $prescriptionId, string $reason, int $userId): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException("Cancellation reason is required.");
        }

        $rx = $this->getPrescription($prescriptionId);
        if (!$rx) {
            throw new Exception("Prescription #{$prescriptionId} not found.");
        }

        // Verify no dispensing has occurred
        foreach ($rx['items'] as $it) {
            if ((int)$it['dispensed_qty'] > 0) {
                throw new Exception("Cannot cancel prescription with dispensed medication. Please use Discontinuation instead.");
            }
        }

        $this->pdo->beginTransaction();
        try {
            $updRx = $this->pdo->prepare("
                UPDATE pharmacy_prescriptions
                SET status = 'CANCELLED', cancelled_by = ?, cancelled_at = NOW(),
                    cancellation_reason = ?, updated_at = NOW()
                WHERE prescription_id = ?
            ");
            $updRx->execute([$userId, $reason, $prescriptionId]);

            $updItems = $this->pdo->prepare("
                UPDATE pharmacy_prescription_items
                SET status = 'CANCELLED'
                WHERE prescription_id = ?
            ");
            $updItems->execute([$prescriptionId]);

            // Cancel any scheduled MAR records
            $this->pdo->prepare("
                UPDATE pharmacy_mar_records
                SET status = 'CANCELLED', notes = 'Cancelled with prescription', updated_at = NOW()
                WHERE prescription_id = ? AND status = 'SCHEDULED'
            ")->execute([$prescriptionId]);

            $this->auditService->logAction(
                $userId,
                'PRESCRIPTION_CANCELLED',
                'pharmacy_prescriptions',
                (string)$prescriptionId,
                ['old_status' => $rx['status']],
                ['new_status' => 'CANCELLED', 'reason' => $reason]
            );

            $this->pdo->commit();
            return $this->getPrescription($prescriptionId);

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Validate dispensing request against a prescription.
     * Enforces that requested quantity <= remaining quantity and prescription is eligible.
     */
    public function validateDispensingEligibility(int $prescriptionId, array $dispenseItems): array
    {
        $rx = $this->getPrescription($prescriptionId);
        if (!$rx) {
            throw new Exception("Prescription #{$prescriptionId} not found.");
        }

        if (in_array($rx['status'], ['DISPENSED', 'COMPLETED'], true)) {
            throw new Exception("Prescription '{$rx['prescription_number']}' has already been FULLY DISPENSED. Additional dispensing blocked.");
        }
        if ($rx['status'] === 'CANCELLED') {
            throw new Exception("Prescription '{$rx['prescription_number']}' has been CANCELLED.");
        }
        if ($rx['status'] === 'DISCONTINUED') {
            throw new Exception("Prescription '{$rx['prescription_number']}' has been DISCONTINUED. Future dispensing blocked.");
        }

        $itemMap = [];
        foreach ($rx['items'] as $item) {
            $itemMap[(int)$item['medicine_id']] = $item;
        }

        $validated = [];
        foreach ($dispenseItems as $dItem) {
            $medId = (int)$dItem['medicine_id'];
            $qty = (int)$dItem['quantity'];

            if (!isset($itemMap[$medId])) {
                throw new Exception("Medicine #{$medId} was not prescribed in Prescription {$rx['prescription_number']}.");
            }

            $prescribed = (int)$itemMap[$medId]['prescribed_qty'];
            $alreadyDispensed = (int)$itemMap[$medId]['dispensed_qty'];
            $remaining = $prescribed - $alreadyDispensed;

            if ($itemMap[$medId]['status'] === 'DISCONTINUED') {
                throw new Exception("Item '{$itemMap[$medId]['medicine_name']}' was DISCONTINUED. Dispensing blocked.");
            }

            if ($qty > $remaining) {
                throw new Exception("Requested quantity ({$qty}) exceeds remaining prescribed quantity ({$remaining}) for '{$itemMap[$medId]['medicine_name']}'. Over-dispensing blocked.");
            }

            $validated[] = [
                'item_id'     => (int)$itemMap[$medId]['item_id'],
                'medicine_id' => $medId,
                'medicine_name' => $itemMap[$medId]['medicine_name'],
                'quantity'    => $qty,
                'remaining'   => $remaining
            ];
        }

        return [
            'prescription' => $rx,
            'validated'    => $validated
        ];
    }

    /**
     * Fetch prescription details with item status, stock availability, and amendment audit history.
     */
    public function getPrescription(int $prescriptionId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT rx.*, u.full_name as created_by_name, v.full_name as verified_by_name,
                   d.full_name as discontinued_by_name, c.full_name as cancelled_by_name
            FROM pharmacy_prescriptions rx
            LEFT JOIN pharmacy_users u ON rx.created_by = u.id
            LEFT JOIN pharmacy_users v ON rx.verified_by = v.id
            LEFT JOIN pharmacy_users d ON rx.discontinued_by = d.id
            LEFT JOIN pharmacy_users c ON rx.cancelled_by = c.id
            WHERE rx.prescription_id = ?
        ");
        $stmt->execute([$prescriptionId]);
        $rx = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rx) {
            return null;
        }

        $itemStmt = $this->pdo->prepare("
            SELECT rxi.*, m.medicine_name, m.generic_name, m.dosage_form, m.strength,
                   m.price as mrp, m.gst_percent,
                   (rxi.prescribed_qty - rxi.dispensed_qty) as remaining_qty,
                   COALESCE((
                       SELECT SUM(mb.quantity_available)
                       FROM medicine_batches mb
                       WHERE mb.medicine_id = m.medicine_id
                         AND mb.status = 'Active'
                         AND mb.quantity_available > 0
                         AND mb.expiry_date >= CURDATE()
                   ), 0) as available_stock
            FROM pharmacy_prescription_items rxi
            JOIN medicines m ON rxi.medicine_id = m.medicine_id
            WHERE rxi.prescription_id = ?
            ORDER BY rxi.item_id ASC
        ");
        $itemStmt->execute([$prescriptionId]);
        $rx['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        // Amendments history
        $amendStmt = $this->pdo->prepare("
            SELECT a.*, u.full_name as amended_by_name
            FROM pharmacy_prescription_amendments a
            LEFT JOIN pharmacy_users u ON a.amended_by = u.id
            WHERE a.prescription_id = ?
            ORDER BY a.amendment_id DESC
        ");
        $amendStmt->execute([$prescriptionId]);
        $rx['amendments'] = $amendStmt->fetchAll(PDO::FETCH_ASSOC);

        return $rx;
    }

    /**
     * List prescriptions for dispensing queue and management.
     */
    public function listPrescriptions(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ["1=1"];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "rx.status = ?";
            $params[] = $filters['status'];
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

        if (!empty($filters['start_date'])) {
            $where[] = "rx.prescription_date >= ?";
            $params[] = $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $where[] = "rx.prescription_date <= ?";
            $params[] = $filters['end_date'];
        }

        if (!empty($filters['search'])) {
            $search = '%' . trim($filters['search']) . '%';
            $where[] = "(rx.prescription_number LIKE ? OR rx.patient_name LIKE ? OR rx.doctor_name LIKE ? OR rx.ipd_admission_no LIKE ?)";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $whereSql = implode(' AND ', $where);
        $sql = "
            SELECT rx.*,
                   (SELECT COUNT(*) FROM pharmacy_prescription_items WHERE prescription_id = rx.prescription_id) as item_count,
                   (SELECT SUM(prescribed_qty - dispensed_qty) FROM pharmacy_prescription_items WHERE prescription_id = rx.prescription_id) as total_undispensed_qty
            FROM pharmacy_prescriptions rx
            WHERE {$whereSql}
            ORDER BY rx.prescription_id DESC
            LIMIT {$limit} OFFSET {$offset}
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
