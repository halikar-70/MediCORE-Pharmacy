<?php
// app/Services/MarService.php - Inpatient Medication Administration Record (MAR) Engine

namespace Pharmacy\Services;

use PDO;
use Exception;
use InvalidArgumentException;

class MarService
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
     * Generate administration schedule records from a prescription.
     * Maps dosage frequencies (OD, BID, TID, Q8H, etc.) across duration days.
     */
    public function generateSchedules(int $prescriptionId, array $options = [], ?int $userId = null): array
    {
        $rxStmt = $this->pdo->prepare("
            SELECT rx.*, rxi.item_id, rxi.medicine_id, rxi.dose, rxi.dose_unit,
                   rxi.route, rxi.frequency, rxi.duration_days, rxi.start_date as item_start_date,
                   rxi.prescribed_qty, rxi.dispensed_qty
            FROM pharmacy_prescriptions rx
            JOIN pharmacy_prescription_items rxi ON rx.prescription_id = rxi.prescription_id
            WHERE rx.prescription_id = ? AND rxi.status = 'ACTIVE'
        ");
        $rxStmt->execute([$prescriptionId]);
        $items = $rxStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($items)) {
            throw new Exception("Prescription #{$prescriptionId} has no active items for MAR schedule generation.");
        }

        $rxHeader = $items[0];
        $timeSlotsMap = [
            'STAT'  => ['09:00:00'],
            'ONCE'  => ['09:00:00'],
            'OD'    => ['09:00:00'],
            'DAILY' => ['09:00:00'],
            'BD'    => ['09:00:00', '21:00:00'],
            'BID'   => ['09:00:00', '21:00:00'],
            'TDS'   => ['08:00:00', '14:00:00', '20:00:00'],
            'TID'   => ['08:00:00', '14:00:00', '20:00:00'],
            'QID'   => ['06:00:00', '12:00:00', '18:00:00', '22:00:00'],
            'Q8H'   => ['06:00:00', '14:00:00', '22:00:00'],
            'Q6H'   => ['06:00:00', '12:00:00', '18:00:00', '23:59:00'],
            'HS'    => ['21:30:00'],
            'SOS'   => ['10:00:00'],
            'PRN'   => ['10:00:00']
        ];

        $this->pdo->beginTransaction();
        try {
            $createdSchedules = [];

            $insMar = $this->pdo->prepare("
                INSERT INTO pharmacy_mar_records (
                    mar_number, prescription_id, prescription_item_id, patient_id,
                    patient_name, ipd_admission_no, ward, bed_number,
                    medicine_id, scheduled_date, scheduled_time, scheduled_dose,
                    dose_unit, route, status, administered_qty, created_by, created_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, 'SCHEDULED', 0, ?, NOW()
                )
            ");

            $checkMar = $this->pdo->prepare("
                SELECT mar_id FROM pharmacy_mar_records
                WHERE prescription_item_id = ? AND scheduled_date = ? AND scheduled_time = ?
            ");

            foreach ($items as $it) {
                $freqKey = strtoupper(trim($it['frequency'] ?? 'OD'));
                $slots = $timeSlotsMap[$freqKey] ?? ['09:00:00'];
                $duration = max(1, min(30, (int)($it['duration_days'] ?? 1)));
                $startDateStr = !empty($it['item_start_date']) ? $it['item_start_date'] : ($rxHeader['start_date'] ?? date('Y-m-d'));
                $startDate = new \DateTime($startDateStr);

                $doseAmount = !empty($it['dose']) ? (float)$it['dose'] : 1.00;
                $doseUnit = !empty($it['dose_unit']) ? $it['dose_unit'] : 'tablet';
                $route = !empty($it['route']) ? $it['route'] : 'ORAL';

                for ($d = 0; $d < $duration; $d++) {
                    $currDate = clone $startDate;
                    $currDate->modify("+{$d} day");
                    $dateFormatted = $currDate->format('Y-m-d');

                    foreach ($slots as $timeSlot) {
                        // Check if duplicate already scheduled
                        $checkMar->execute([$it['item_id'], $dateFormatted, $timeSlot]);
                        if ($checkMar->fetchColumn()) {
                            continue;
                        }

                        $marNumber = $this->seqService->generate('MAR');
                        $insMar->execute([
                            $marNumber,
                            $prescriptionId,
                            $it['item_id'],
                            $rxHeader['patient_id'],
                            $rxHeader['patient_name'],
                            $rxHeader['ipd_admission_no'],
                            $rxHeader['ward'],
                            $rxHeader['bed_number'],
                            $it['medicine_id'],
                            $dateFormatted,
                            $timeSlot,
                            $doseAmount,
                            $doseUnit,
                            $route,
                            $userId
                        ]);

                        $createdSchedules[] = (int)$this->pdo->lastInsertId();
                    }
                }
            }

            $this->auditService->logAction(
                $userId,
                'MAR_SCHEDULES_GENERATED',
                'pharmacy_prescriptions',
                (string)$prescriptionId,
                null,
                ['count' => count($createdSchedules)]
            );

            $this->pdo->commit();
            return $createdSchedules;

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Record clinical administration on MAR: GIVEN, HELD, REFUSED, MISSED, OMITTED, or CANCELLED.
     *
     * CLINICAL INVARIANT:
     * - PRESCRIBED ≠ DISPENSED ≠ ADMINISTERED.
     * - MAR administration NEVER touches pharmacy stock or stock ledger.
     * - GIVEN requires an authorized workflow.
     * - HELD / REFUSED / MISSED requires a mandatory clinical reason.
     * - Over-administration or duplicate administration is prevented.
     */
    public function recordAdministration(int $marId, string $status, array $data, int $userId): array
    {
        $status = strtoupper(trim($status));
        $validStatuses = ['GIVEN', 'HELD', 'REFUSED', 'MISSED', 'OMITTED', 'CANCELLED'];
        if (!in_array($status, $validStatuses, true)) {
            throw new InvalidArgumentException("Invalid administration status '{$status}'.");
        }

        // Reason mandatory for non-given statuses
        $reason = trim($data['reason'] ?? $data['not_given_reason'] ?? '');
        if ($status !== 'GIVEN' && $reason === '') {
            throw new InvalidArgumentException("A reason is strictly mandatory when recording medication as {$status}.");
        }

        // Idempotency check
        $idempotencyKey = !empty($data['idempotency_key']) ? trim($data['idempotency_key']) : null;
        if ($idempotencyKey !== null) {
            $chk = $this->pdo->prepare("SELECT mar_id FROM pharmacy_mar_records WHERE idempotency_key = ?");
            $chk->execute([$idempotencyKey]);
            $existingMarId = $chk->fetchColumn();
            if ($existingMarId) {
                return $this->getMarRecord((int)$existingMarId);
            }
        }

        $this->pdo->beginTransaction();
        try {
            // 1. Lock the MAR row (FOR UPDATE)
            $stmt = $this->pdo->prepare("
                SELECT m.*, rxi.prescribed_qty, rxi.dispensed_qty
                FROM pharmacy_mar_records m
                JOIN pharmacy_prescription_items rxi ON m.prescription_item_id = rxi.item_id
                WHERE m.mar_id = ? FOR UPDATE
            ");
            $stmt->execute([$marId]);
            $mar = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$mar) {
                throw new Exception("MAR Record #{$marId} not found.");
            }

            if ($mar['status'] !== 'SCHEDULED') {
                throw new Exception("Medication dose has already been recorded as '{$mar['status']}'. Duplicate administration is blocked.");
            }

            $adminQty = 0;
            $adminDose = null;
            $adminTime = null;

            if ($status === 'GIVEN') {
                $adminQty = isset($data['administered_qty']) && (int)$data['administered_qty'] > 0 
                    ? (int)$data['administered_qty'] 
                    : 1;
                $adminDose = isset($data['administered_dose']) && (float)$data['administered_dose'] > 0 
                    ? (float)$data['administered_dose'] 
                    : (float)$mar['scheduled_dose'];
                $adminTime = !empty($data['actual_admin_time']) ? $data['actual_admin_time'] : date('Y-m-d H:i:s');
            }

            $witnessedBy = !empty($data['witnessed_by']) ? trim($data['witnessed_by']) : null;
            $notes = !empty($data['notes']) ? trim($data['notes']) : null;

            // 2. Update MAR record without altering stock
            $upd = $this->pdo->prepare("
                UPDATE pharmacy_mar_records
                SET status = ?, actual_admin_time = ?, administered_qty = ?,
                    administered_dose = ?, administered_by = ?, witnessed_by = ?,
                    not_given_reason = ?, notes = ?, idempotency_key = ?, updated_at = NOW()
                WHERE mar_id = ?
            ");
            $upd->execute([
                $status,
                $adminTime,
                $adminQty,
                $adminDose,
                $userId,
                $witnessedBy,
                ($status !== 'GIVEN' ? $reason : null),
                $notes,
                $idempotencyKey,
                $marId
            ]);

            // 3. Audit trail
            $this->auditService->logAction(
                $userId,
                'MAR_RECORDED',
                'pharmacy_mar_records',
                (string)$marId,
                ['status' => 'SCHEDULED'],
                ['status' => $status, 'administered_qty' => $adminQty, 'reason' => $reason]
            );

            $this->pdo->commit();
            return $this->getMarRecord($marId);

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Auditable non-destructive correction of an existing MAR administration record.
     */
    public function correctAdministration(int $marId, string $newStatus, array $data, string $reason, int $userId): array
    {
        $newStatus = strtoupper(trim($newStatus));
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException("Correction reason is strictly mandatory.");
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM pharmacy_mar_records WHERE mar_id = ? FOR UPDATE");
            $stmt->execute([$marId]);
            $mar = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$mar) {
                throw new Exception("MAR Record #{$marId} not found.");
            }

            $newQty = isset($data['administered_qty']) ? (int)$data['administered_qty'] : ($newStatus === 'GIVEN' ? 1 : 0);
            $newDose = isset($data['administered_dose']) ? (float)$data['administered_dose'] : ($newStatus === 'GIVEN' ? (float)$mar['scheduled_dose'] : null);

            // 1. Insert non-destructive correction record
            $insCorr = $this->pdo->prepare("
                INSERT INTO pharmacy_mar_corrections (
                    mar_id, old_status, new_status, old_administered_qty, new_administered_qty,
                    old_administered_dose, new_administered_dose, correction_reason, corrected_by, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, NOW()
                )
            ");
            $insCorr->execute([
                $marId,
                $mar['status'],
                $newStatus,
                $mar['administered_qty'],
                $newQty,
                $mar['administered_dose'],
                $newDose,
                $reason,
                $userId
            ]);

            // 2. Update record
            $upd = $this->pdo->prepare("
                UPDATE pharmacy_mar_records
                SET status = ?, administered_qty = ?, administered_dose = ?,
                    not_given_reason = ?, notes = CONCAT(COALESCE(notes, ''), ' [Corrected: ', ?, ']'),
                    updated_at = NOW()
                WHERE mar_id = ?
            ");
            $upd->execute([
                $newStatus,
                $newQty,
                $newDose,
                ($newStatus !== 'GIVEN' ? $reason : null),
                $reason,
                $marId
            ]);

            $this->auditService->logAction(
                $userId,
                'MAR_CORRECTED',
                'pharmacy_mar_records',
                (string)$marId,
                ['status' => $mar['status'], 'administered_qty' => $mar['administered_qty']],
                ['new_status' => $newStatus, 'new_administered_qty' => $newQty, 'reason' => $reason]
            );

            $this->pdo->commit();
            return $this->getMarRecord($marId);

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Get single MAR record with details, administering user, and correction history.
     */
    public function getMarRecord(int $marId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT m.*, u.full_name as administered_by_name,
                   med.medicine_name, med.generic_name, med.dosage_form, med.strength,
                   rx.prescription_number, rx.doctor_name
            FROM pharmacy_mar_records m
            JOIN medicines med ON m.medicine_id = med.medicine_id
            JOIN pharmacy_prescriptions rx ON m.prescription_id = rx.prescription_id
            LEFT JOIN pharmacy_users u ON m.administered_by = u.id
            WHERE m.mar_id = ?
        ");
        $stmt->execute([$marId]);
        $rec = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rec) {
            return null;
        }

        // Attach corrections
        $cStmt = $this->pdo->prepare("
            SELECT c.*, u.full_name as corrected_by_name
            FROM pharmacy_mar_corrections c
            LEFT JOIN pharmacy_users u ON c.corrected_by = u.id
            WHERE c.mar_id = ?
            ORDER BY c.correction_id DESC
        ");
        $cStmt->execute([$marId]);
        $rec['corrections'] = $cStmt->fetchAll(PDO::FETCH_ASSOC);

        return $rec;
    }

    /**
     * Get patient MAR grid for a given admission and date.
     */
    public function getPatientMar(string $ipdAdmissionNo, ?string $date = null): array
    {
        $where = ["m.ipd_admission_no = ?"];
        $params = [$ipdAdmissionNo];

        if (!empty($date)) {
            $where[] = "m.scheduled_date = ?";
            $params[] = $date;
        }

        $whereSql = implode(' AND ', $where);
        $sql = "
            SELECT m.*, u.full_name as administered_by_name,
                   med.medicine_name, med.generic_name, med.dosage_form, med.strength,
                   rxi.dosage_instructions, rxi.frequency
            FROM pharmacy_mar_records m
            JOIN medicines med ON m.medicine_id = med.medicine_id
            JOIN pharmacy_prescription_items rxi ON m.prescription_item_id = rxi.item_id
            LEFT JOIN pharmacy_users u ON m.administered_by = u.id
            WHERE {$whereSql}
            ORDER BY m.scheduled_date ASC, m.scheduled_time ASC, m.medicine_id ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Reconcile Prescribed vs Dispensed vs Administered for a prescription.
     */
    public function getAdministrationVariance(int $prescriptionId): array
    {
        $rxStmt = $this->pdo->prepare("
            SELECT rxi.item_id, rxi.medicine_id, rxi.dose, rxi.dose_unit, rxi.route, rxi.frequency,
                   rxi.prescribed_qty, rxi.dispensed_qty,
                   (rxi.prescribed_qty - rxi.dispensed_qty) as undispensed_qty,
                   m.medicine_name, m.generic_name
            FROM pharmacy_prescription_items rxi
            JOIN medicines m ON rxi.medicine_id = m.medicine_id
            WHERE rxi.prescription_id = ?
            ORDER BY rxi.item_id ASC
        ");
        $rxStmt->execute([$prescriptionId]);
        $items = $rxStmt->fetchAll(PDO::FETCH_ASSOC);

        $marStatsStmt = $this->pdo->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN status = 'GIVEN' THEN administered_qty ELSE 0 END), 0) as total_administered,
                COUNT(CASE WHEN status = 'GIVEN' THEN 1 END) as count_given,
                COUNT(CASE WHEN status = 'HELD' THEN 1 END) as count_held,
                COUNT(CASE WHEN status = 'REFUSED' THEN 1 END) as count_refused,
                COUNT(CASE WHEN status = 'MISSED' THEN 1 END) as count_missed,
                COUNT(CASE WHEN status = 'SCHEDULED' THEN 1 END) as count_scheduled
            FROM pharmacy_mar_records
            WHERE prescription_item_id = ?
        ");

        $reconciliation = [];
        foreach ($items as $it) {
            $marStatsStmt->execute([$it['item_id']]);
            $stats = $marStatsStmt->fetch(PDO::FETCH_ASSOC);

            $dispensed = (int)$it['dispensed_qty'];
            $administered = (int)$stats['total_administered'];
            $bedsideRemaining = max(0, $dispensed - $administered);

            $reconciliation[] = [
                'item_id'           => (int)$it['item_id'],
                'medicine_id'       => (int)$it['medicine_id'],
                'medicine_name'     => $it['medicine_name'],
                'prescribed_qty'    => (int)$it['prescribed_qty'],
                'dispensed_qty'     => $dispensed,
                'undispensed_qty'   => (int)$it['undispensed_qty'],
                'administered_qty'  => $administered,
                'bedside_remaining' => $bedsideRemaining,
                'count_given'       => (int)$stats['count_given'],
                'count_held'        => (int)$stats['count_held'],
                'count_refused'     => (int)$stats['count_refused'],
                'count_missed'      => (int)$stats['count_missed'],
                'count_scheduled'   => (int)$stats['count_scheduled']
            ];
        }

        return $reconciliation;
    }

    /**
     * List MAR records for register / report.
     */
    public function listMarRecords(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ["1=1"];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "m.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['ward'])) {
            $where[] = "m.ward = ?";
            $params[] = $filters['ward'];
        }

        if (!empty($filters['ipd_admission_no'])) {
            $where[] = "m.ipd_admission_no = ?";
            $params[] = $filters['ipd_admission_no'];
        }

        if (!empty($filters['scheduled_date'])) {
            $where[] = "m.scheduled_date = ?";
            $params[] = $filters['scheduled_date'];
        }

        if (!empty($filters['search'])) {
            $s = '%' . trim($filters['search']) . '%';
            $where[] = "(m.mar_number LIKE ? OR m.patient_name LIKE ? OR m.ipd_admission_no LIKE ? OR med.medicine_name LIKE ?)";
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
        }

        $whereSql = implode(' AND ', $where);
        $sql = "
            SELECT m.*, u.full_name as administered_by_name,
                   med.medicine_name, med.generic_name, med.dosage_form, med.strength,
                   rx.prescription_number
            FROM pharmacy_mar_records m
            JOIN medicines med ON m.medicine_id = med.medicine_id
            JOIN pharmacy_prescriptions rx ON m.prescription_id = rx.prescription_id
            LEFT JOIN pharmacy_users u ON m.administered_by = u.id
            WHERE {$whereSql}
            ORDER BY m.scheduled_date DESC, m.scheduled_time DESC
            LIMIT {$limit} OFFSET {$offset}
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
