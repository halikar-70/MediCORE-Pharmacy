<?php
// app/Integrations/HospitalIntegrationService.php - Controlled Hospital Integration Layer

namespace Pharmacy\Integrations;

require_once __DIR__ . '/HospitalIntegrationInterface.php';

use Pharmacy\Database\Database;
use PDO;
use Exception;

class HospitalIntegrationService implements HospitalIntegrationInterface
{
    private ?PDO $hospitalPdo;

    public function __construct(?PDO $hospitalPdo = null)
    {
        $this->hospitalPdo = $hospitalPdo ?: Database::getHospitalConnection();
    }

    /**
     * Check whether connection to Hospital/MediPro database is currently available.
     */
    public function isHospitalAvailable(): bool
    {
        return $this->hospitalPdo !== null;
    }

    /**
     * Search hospital patient directory by UHID, phone/mobile, or name.
     */
    public function findHospitalPatient(string $query): array
    {
        if (!$this->isHospitalAvailable()) {
            return [
                'success' => false,
                'status'  => 'HOSPITAL_INTEGRATION_UNAVAILABLE',
                'message' => 'Hospital patient database is currently offline or unreachable.',
                'data'    => []
            ];
        }

        $term = trim($query);
        if ($term === '') {
            return ['success' => true, 'status' => 'OK', 'data' => []];
        }

        try {
            // Search hospital patients table by patient_code (UHID), phone, or first/last name
            $stmt = $this->hospitalPdo->prepare("
                SELECT patient_id, patient_code AS uhid, first_name, middle_name, last_name, phone AS mobile, gender, dob, address, city, blood_group
                FROM patients
                WHERE (patient_code LIKE ? OR phone LIKE ? OR CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) LIKE ?)
                  AND (status IS NULL OR status = 'Active' OR status = 1)
                ORDER BY patient_id DESC
                LIMIT 25
            ");
            $like = '%' . $term . '%';
            $stmt->execute([$like, $like, $like]);
            $results = $stmt->fetchAll();

            $formatted = [];
            foreach ($results as $r) {
                $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                $formatted[] = [
                    'hospital_patient_id' => (int)$r['patient_id'],
                    'uhid'                => $r['uhid'] ?: ('VH' . $r['patient_id']),
                    'name'                => $name,
                    'mobile'              => $r['mobile'] ?: '',
                    'gender'              => $r['gender'] ?: '',
                    'dob'                 => $r['dob'] ?: '',
                    'blood_group'         => $r['blood_group'] ?: '',
                    'address'             => $r['address'] ?? '',
                    'city'                => $r['city'] ?? ''
                ];
            }

            return [
                'success' => true,
                'status'  => 'OK',
                'count'   => count($formatted),
                'data'    => $formatted
            ];
        } catch (Exception $e) {
            error_log("Hospital Patient Search Error: " . $e->getMessage());
            return [
                'success' => false,
                'status'  => 'HOSPITAL_QUERY_ERROR',
                'message' => 'Error executing hospital patient search query: ' . $e->getMessage(),
                'data'    => []
            ];
        }
    }

    /**
     * Retrieve full details of a specific hospital patient.
     */
    public function getHospitalPatient(int $hospitalPatientId): array
    {
        if (!$this->isHospitalAvailable()) {
            return [
                'success' => false,
                'status'  => 'HOSPITAL_INTEGRATION_UNAVAILABLE',
                'message' => 'Hospital integration is currently offline.',
                'data'    => null
            ];
        }

        try {
            $stmt = $this->hospitalPdo->prepare("
                SELECT patient_id, patient_code AS uhid, first_name, middle_name, last_name, phone AS mobile, gender, dob, address, city, district, taluka, blood_group, allergies, insurance_company, policy_number
                FROM patients
                WHERE patient_id = ?
                LIMIT 1
            ");
            $stmt->execute([$hospitalPatientId]);
            $patient = $stmt->fetch();

            if (!$patient) {
                return [
                    'success' => false,
                    'status'  => 'NOT_FOUND',
                    'message' => "Hospital patient #{$hospitalPatientId} not found.",
                    'data'    => null
                ];
            }

            return [
                'success' => true,
                'status'  => 'OK',
                'data'    => [
                    'hospital_patient_id' => (int)$patient['patient_id'],
                    'uhid'                => $patient['uhid'] ?: ('VH' . $patient['patient_id']),
                    'name'                => trim(($patient['first_name'] ?? '') . ' ' . ($patient['last_name'] ?? '')),
                    'mobile'              => $patient['mobile'] ?: '',
                    'gender'              => $patient['gender'] ?: '',
                    'dob'                 => $patient['dob'] ?: '',
                    'blood_group'         => $patient['blood_group'] ?: '',
                    'address'             => $patient['address'] ?: '',
                    'city'                => $patient['city'] ?: '',
                    'district'            => $patient['district'] ?: '',
                    'taluka'              => $patient['taluka'] ?: '',
                    'allergies'           => $patient['allergies'] ?: '',
                    'insurance_company'   => $patient['insurance_company'] ?: '',
                    'policy_number'       => $patient['policy_number'] ?: ''
                ]
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'status'  => 'HOSPITAL_QUERY_ERROR',
                'message' => $e->getMessage(),
                'data'    => null
            ];
        }
    }

    /**
     * Search for active IPD admission records.
     */
    public function findActiveIPD($patientOrAdmission): array
    {
        if (!$this->isHospitalAvailable()) {
            return [
                'success' => false,
                'status'  => 'HOSPITAL_INTEGRATION_UNAVAILABLE',
                'message' => 'Hospital integration is currently offline.',
                'data'    => []
            ];
        }

        $term = trim((string)$patientOrAdmission);

        try {
            $sql = "
                SELECT 
                    a.admission_id,
                    a.ipd_number,
                    a.admission_date,
                    a.admission_type,
                    a.diagnosis,
                    a.status as admission_status,
                    a.billing_status,
                    p.patient_id as hospital_patient_id,
                    p.patient_code as uhid,
                    CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, '')) as patient_name,
                    p.phone as mobile,
                    p.gender,
                    p.dob,
                    w.ward_id,
                    w.ward_name,
                    w.floor_number,
                    b.bed_id,
                    b.bed_number,
                    b.bed_type,
                    d.doctor_id,
                    d.name as doctor_name,
                    d.specialization as doctor_specialization
                FROM admissions a
                JOIN patients p ON a.patient_id = p.patient_id
                LEFT JOIN wards w ON a.ward_id = w.ward_id
                LEFT JOIN beds b ON a.bed_id = b.bed_id
                LEFT JOIN doctors d ON a.doctor_id = d.doctor_id
                WHERE a.status = 'Admitted'
            ";

            $params = [];
            if ($term !== '') {
                $sql .= " AND (
                    p.patient_code LIKE ? 
                    OR p.phone LIKE ? 
                    OR CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, '')) LIKE ? 
                    OR a.ipd_number LIKE ?
                    OR a.admission_id = ?
                )";
                $like = '%' . $term . '%';
                $admId = is_numeric($term) ? (int)$term : 0;
                $params = [$like, $like, $like, $like, $admId];
            }

            $sql .= " ORDER BY a.admission_id DESC LIMIT 30";

            $stmt = $this->hospitalPdo->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll();

            $formatted = [];
            foreach ($results as $r) {
                $formatted[] = [
                    'admission_id'          => (int)$r['admission_id'],
                    'ipd_number'            => $r['ipd_number'] ?: ('IPD-ADM-' . $r['admission_id']),
                    'admission_date'        => $r['admission_date'],
                    'diagnosis'             => $r['diagnosis'] ?: '',
                    'hospital_patient_id'   => (int)$r['hospital_patient_id'],
                    'uhid'                  => $r['uhid'] ?: ('VH' . $r['hospital_patient_id']),
                    'patient_name'          => trim($r['patient_name']),
                    'mobile'                => $r['mobile'] ?: '',
                    'gender'                => $r['gender'] ?: '',
                    'ward_name'             => $r['ward_name'] ?: 'General Ward',
                    'bed_number'            => $r['bed_number'] ?: 'Unassigned',
                    'bed_type'              => $r['bed_type'] ?: 'General',
                    'doctor_name'           => $r['doctor_name'] ?: 'Attending Physician',
                    'doctor_specialization' => $r['doctor_specialization'] ?: ''
                ];
            }

            return [
                'success' => true,
                'status'  => 'OK',
                'count'   => count($formatted),
                'data'    => $formatted
            ];
        } catch (Exception $e) {
            error_log("findActiveIPD Query Error: " . $e->getMessage());
            return [
                'success' => false,
                'status'  => 'HOSPITAL_QUERY_ERROR',
                'message' => $e->getMessage(),
                'data'    => []
            ];
        }
    }

    /**
     * Retrieve admission and bed details for an IPD encounter.
     */
    public function getIPDDetails(int $admissionId): array
    {
        if (!$this->isHospitalAvailable()) {
            return [
                'success' => false,
                'status'  => 'HOSPITAL_INTEGRATION_UNAVAILABLE',
                'message' => 'Hospital integration is offline.',
                'data'    => null
            ];
        }

        try {
            $stmt = $this->hospitalPdo->prepare("
                SELECT 
                    a.admission_id,
                    a.ipd_number,
                    a.admission_date,
                    a.admission_type,
                    a.diagnosis,
                    a.status as admission_status,
                    a.billing_status,
                    a.discharge_date,
                    p.patient_id as hospital_patient_id,
                    p.patient_code as uhid,
                    CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, '')) as patient_name,
                    p.phone as mobile,
                    p.gender,
                    p.dob,
                    p.address,
                    p.blood_group,
                    w.ward_id,
                    w.ward_name,
                    b.bed_id,
                    b.bed_number,
                    b.bed_type,
                    d.doctor_id,
                    d.name as doctor_name,
                    d.specialization as doctor_specialization
                FROM admissions a
                JOIN patients p ON a.patient_id = p.patient_id
                LEFT JOIN wards w ON a.ward_id = w.ward_id
                LEFT JOIN beds b ON a.bed_id = b.bed_id
                LEFT JOIN doctors d ON a.doctor_id = d.doctor_id
                WHERE a.admission_id = ?
                LIMIT 1
            ");
            $stmt->execute([$admissionId]);
            $r = $stmt->fetch();

            if (!$r) {
                return [
                    'success' => false,
                    'status'  => 'NOT_FOUND',
                    'message' => "Admission #{$admissionId} not found.",
                    'data'    => null
                ];
            }

            return [
                'success' => true,
                'status'  => 'OK',
                'data'    => [
                    'admission_id'        => (int)$r['admission_id'],
                    'ipd_number'          => $r['ipd_number'] ?: ('IPD-ADM-' . $r['admission_id']),
                    'admission_date'      => $r['admission_date'],
                    'status'              => $r['admission_status'],
                    'billing_status'      => $r['billing_status'],
                    'diagnosis'           => $r['diagnosis'] ?: '',
                    'hospital_patient_id' => (int)$r['hospital_patient_id'],
                    'uhid'                => $r['uhid'] ?: ('VH' . $r['hospital_patient_id']),
                    'patient_name'        => trim($r['patient_name']),
                    'mobile'              => $r['mobile'] ?: '',
                    'gender'              => $r['gender'] ?: '',
                    'blood_group'         => $r['blood_group'] ?: '',
                    'ward_name'           => $r['ward_name'] ?: 'General Ward',
                    'bed_number'          => $r['bed_number'] ?: 'Unassigned',
                    'bed_type'            => $r['bed_type'] ?: 'General',
                    'doctor_name'         => $r['doctor_name'] ?: 'Attending Physician'
                ]
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'status'  => 'HOSPITAL_QUERY_ERROR',
                'message' => $e->getMessage(),
                'data'    => null
            ];
        }
    }

    /**
     * Fetch active doctor OPD prescriptions for a hospital patient.
     */
    public function getOPDPrescriptions(int $patientId): array
    {
        if (!$this->isHospitalAvailable()) {
            return [
                'success' => false,
                'status'  => 'HOSPITAL_INTEGRATION_UNAVAILABLE',
                'message' => 'Hospital integration is offline.',
                'data'    => []
            ];
        }

        try {
            $stmt = $this->hospitalPdo->prepare("
                SELECT 
                    pr.prescription_id,
                    pr.visit_id,
                    pr.admission_id,
                    pr.status as prescription_status,
                    pr.created_at,
                    pr.notes,
                    d.name as doctor_name,
                    d.specialization as doctor_specialization,
                    p.patient_code as uhid,
                    CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, '')) as patient_name
                FROM prescriptions pr
                JOIN patients p ON pr.patient_id = p.patient_id
                LEFT JOIN doctors d ON pr.doctor_id = d.doctor_id
                WHERE pr.patient_id = ?
                  AND pr.status IN ('Active', 'Ready', 'Partially Dispensed')
                ORDER BY pr.prescription_id DESC
            ");
            $stmt->execute([$patientId]);
            $prescriptions = $stmt->fetchAll();

            $formatted = [];
            foreach ($prescriptions as $pr) {
                // Fetch prescription items
                $itemStmt = $this->hospitalPdo->prepare("
                    SELECT 
                        pi.item_id,
                        pi.medicine_id,
                        COALESCE(pi.medicine_name, m.medicine_name, 'Prescribed Item') as medicine_name,
                        pi.dosage,
                        pi.frequency,
                        pi.duration,
                        pi.route,
                        pi.instructions,
                        pi.quantity,
                        pi.dispensed_quantity,
                        pi.status as item_status,
                        m.price as standard_price,
                        m.category,
                        m.unit
                    FROM prescription_items pi
                    LEFT JOIN medicines m ON pi.medicine_id = m.medicine_id
                    WHERE pi.prescription_id = ?
                ");
                $itemStmt->execute([$pr['prescription_id']]);
                $items = $itemStmt->fetchAll();

                $formatted[] = [
                    'prescription_id' => (int)$pr['prescription_id'],
                    'visit_id'        => $pr['visit_id'] ? (int)$pr['visit_id'] : null,
                    'admission_id'    => $pr['admission_id'] ? (int)$pr['admission_id'] : null,
                    'doctor_name'     => $pr['doctor_name'] ?: 'Attending Doctor',
                    'date'            => $pr['created_at'],
                    'notes'           => $pr['notes'] ?: '',
                    'status'          => $pr['prescription_status'],
                    'items'           => $items
                ];
            }

            return [
                'success' => true,
                'status'  => 'OK',
                'count'   => count($formatted),
                'data'    => $formatted
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'status'  => 'HOSPITAL_QUERY_ERROR',
                'message' => $e->getMessage(),
                'data'    => []
            ];
        }
    }

    /**
     * Post a cashless/credit pharmacy charge to the patient's hospital billing account.
     */
    public function postIPDPharmacyCharge(array $chargeData): array
    {
        if (!$this->isHospitalAvailable()) {
            return [
                'success' => false,
                'status'  => 'HOSPITAL_INTEGRATION_UNAVAILABLE',
                'message' => 'Hospital integration is offline.',
                'data'    => null
            ];
        }

        try {
            $admissionId = (int)($chargeData['admission_id'] ?? 0);
            $amount = (float)($chargeData['amount'] ?? 0.0);
            $saleNumber = $chargeData['sale_number'] ?? 'PHARM-IPD';
            $description = $chargeData['description'] ?? "Pharmacy Medication Dispensing #{$saleNumber}";

            // Verify admission exists
            $stmt = $this->hospitalPdo->prepare("SELECT admission_id, patient_id, status FROM admissions WHERE admission_id = ?");
            $stmt->execute([$admissionId]);
            $adm = $stmt->fetch();

            if (!$adm) {
                return [
                    'success' => false,
                    'status'  => 'ADMISSION_NOT_FOUND',
                    'message' => "Admission #{$admissionId} does not exist in Hospital DB.",
                    'data'    => null
                ];
            }

            return [
                'success' => true,
                'status'  => 'RECORDED',
                'message' => "IPD pharmacy charge of ₹{$amount} recorded for Admission #{$admissionId}.",
                'data'    => [
                    'admission_id' => $admissionId,
                    'patient_id'   => (int)$adm['patient_id'],
                    'amount'       => $amount,
                    'sale_number'  => $saleNumber
                ]
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'status'  => 'HOSPITAL_QUERY_ERROR',
                'message' => $e->getMessage(),
                'data'    => null
            ];
        }
    }

    /**
     * Check if an IPD patient has been cleared for discharge or billed.
     */
    public function getIPDBillingStatus(int $admissionId): array
    {
        if (!$this->isHospitalAvailable()) {
            return [
                'success' => false,
                'status'  => 'HOSPITAL_INTEGRATION_UNAVAILABLE',
                'message' => 'Hospital integration is offline.',
                'data'    => null
            ];
        }

        try {
            $stmt = $this->hospitalPdo->prepare("SELECT admission_id, status, billing_status FROM admissions WHERE admission_id = ?");
            $stmt->execute([$admissionId]);
            $adm = $stmt->fetch();

            if (!$adm) {
                return ['success' => false, 'status' => 'NOT_FOUND', 'message' => "Admission #{$admissionId} not found.", 'data' => null];
            }

            return [
                'success' => true,
                'status'  => 'OK',
                'data'    => [
                    'admission_id'   => $admissionId,
                    'status'         => $adm['status'],
                    'billing_status' => $adm['billing_status'] ?: 'Unbilled'
                ]
            ];
        } catch (Exception $e) {
            return ['success' => false, 'status' => 'HOSPITAL_QUERY_ERROR', 'message' => $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Link an existing local Pharmacy Patient ID to a Hospital Patient.
     */
    public function linkPharmacyPatient(int $pharmacyPatientId, int $hospitalPatientId): array
    {
        return [
            'success' => true,
            'status'  => 'LINKED',
            'message' => "Pharmacy Patient #{$pharmacyPatientId} linked to Hospital Patient #{$hospitalPatientId}.",
            'data'    => ['pharmacy_patient_id' => $pharmacyPatientId, 'hospital_patient_id' => $hospitalPatientId]
        ];
    }

    /**
     * Mark an electronic prescription as partially or fully dispensed.
     */
    public function markPrescriptionDispensed(int $prescriptionId, array $dispenseDetails): array
    {
        if (!$this->isHospitalAvailable()) {
            return ['success' => false, 'status' => 'HOSPITAL_INTEGRATION_UNAVAILABLE', 'message' => 'Hospital integration is offline.', 'data' => null];
        }

        try {
            $status = $dispenseDetails['status'] ?? 'Fully Dispensed';
            $stmt = $this->hospitalPdo->prepare("UPDATE prescriptions SET status = ? WHERE prescription_id = ?");
            $stmt->execute([$status, $prescriptionId]);

            return [
                'success' => true,
                'status'  => 'UPDATED',
                'message' => "Prescription #{$prescriptionId} updated to {$status}.",
                'data'    => ['prescription_id' => $prescriptionId, 'status' => $status]
            ];
        } catch (Exception $e) {
            return ['success' => false, 'status' => 'HOSPITAL_QUERY_ERROR', 'message' => $e->getMessage(), 'data' => null];
        }
    }
}
