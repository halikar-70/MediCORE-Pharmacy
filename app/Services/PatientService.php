<?php
// app/Services/PatientService.php - Patient Identity & Registration Service

namespace Pharmacy\Services;

require_once __DIR__ . '/DocumentSequenceService.php';
require_once __DIR__ . '/AuditService.php';
require_once __DIR__ . '/../Integrations/HospitalIntegrationInterface.php';
require_once __DIR__ . '/../Integrations/HospitalIntegrationService.php';

use Pharmacy\Integrations\HospitalIntegrationInterface;
use Pharmacy\Integrations\HospitalIntegrationService;
use PDO;
use Exception;

class PatientService
{
    private PDO $pdo;
    private ?PDO $hospitalPdo;
    private DocumentSequenceService $sequenceService;
    private AuditService $auditService;
    private HospitalIntegrationInterface $hospitalIntegration;

    public function __construct(
        PDO $pdo,
        ?DocumentSequenceService $sequenceService = null,
        ?AuditService $auditService = null,
        ?HospitalIntegrationInterface $hospitalIntegration = null,
        ?PDO $hospitalPdo = null
    ) {
        $this->pdo = $pdo;
        $this->hospitalPdo = $hospitalPdo ?: \Pharmacy\Database\Database::getHospitalConnection();
        $this->sequenceService = $sequenceService ?: new DocumentSequenceService($pdo);
        $this->auditService = $auditService ?: new AuditService($pdo);
        $this->hospitalIntegration = $hospitalIntegration ?: new HospitalIntegrationService();
    }

    /**
     * Search Hospital Directory First (Enforces STEP 5).
     *
     * @param string $term
     * @return array
     */
    public function searchHospitalPatients(string $term): array
    {
        return $this->hospitalIntegration->findHospitalPatient($term);
    }

    /**
     * Search local Pharmacy Patients.
     *
     * @param string $term
     * @return array
     */
    public function searchLocalPatients(string $term): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        $stmt = $this->pdo->prepare("
            SELECT * 
            FROM pharmacy_patients 
            WHERE (pharmacy_patient_no LIKE ? OR mobile LIKE ? OR name LIKE ? OR hospital_uhid LIKE ?)
              AND status = 'Active'
            ORDER BY id DESC
            LIMIT 20
        ");
        $like = '%' . $term . '%';
        $stmt->execute([$like, $like, $like, $like]);
        return $stmt->fetchAll();
    }

    /**
     * Register a local Pharmacy Patient.
     * Generates a unique Pharmacy Patient ID (e.g. PP-000001).
     * Keeps hospital_patient_id and hospital_uhid nullable.
     *
     * @param array $data
     * @return array Created patient record
     * @throws Exception
     */
    public function registerLocalPatient(array $data): array
    {
        $name = trim($data['name'] ?? '');
        if ($name === '') {
            throw new Exception("Patient name is required.");
        }

        $mobile = trim($data['mobile'] ?? '');
        $gender = in_array($data['gender'] ?? '', ['Male', 'Female', 'Other']) ? $data['gender'] : 'Other';
        $dob = !empty($data['date_of_birth']) ? $data['date_of_birth'] : null;
        $address = trim($data['address'] ?? '');
        $city = trim($data['city'] ?? '');
        $state = trim($data['state'] ?? '');
        $pincode = trim($data['pincode'] ?? '');
        $hospitalPatientId = !empty($data['hospital_patient_id']) ? (int)$data['hospital_patient_id'] : null;
        $hospitalUhid = !empty($data['hospital_uhid']) ? trim($data['hospital_uhid']) : null;
        $doctorId = !empty($data['doctor_id']) ? (int)$data['doctor_id'] : null;

        // Bidirectional Interdependence: Auto-sync / find customer in Hospital directory
        $hospConn = $this->hospitalPdo;
        if (!$hospitalPatientId && $hospConn) {
            try {
                if ($hospitalUhid) {
                    $chkUhid = $hospConn->prepare("SELECT patient_id, patient_code FROM patients WHERE patient_code = ? AND deleted_at IS NULL LIMIT 1");
                    $chkUhid->execute([$hospitalUhid]);
                    $hMatch = $chkUhid->fetch(PDO::FETCH_ASSOC);
                    if ($hMatch) {
                        $hospitalPatientId = (int)$hMatch['patient_id'];
                        $hospitalUhid = $hMatch['patient_code'];
                    }
                }
                if (!$hospitalPatientId && $mobile !== '') {
                    $chkMob = $hospConn->prepare("SELECT patient_id, patient_code FROM patients WHERE phone = ? AND deleted_at IS NULL LIMIT 1");
                    $chkMob->execute([$mobile]);
                    $hMatch = $chkMob->fetch(PDO::FETCH_ASSOC);
                    if ($hMatch) {
                        $hospitalPatientId = (int)$hMatch['patient_id'];
                        $hospitalUhid = $hMatch['patient_code'] ?: ('VH' . str_pad((string)$hospitalPatientId, 4, '0', STR_PAD_LEFT));
                    }
                }

                // If not yet existing in hospital, automatically register into Hospital MediPro patients table
                if (!$hospitalPatientId) {
                    $nameParts = explode(' ', $name, 2);
                    $firstName = trim($nameParts[0]);
                    $lastName = trim($nameParts[1] ?? '');
                    if ($lastName === '') {
                        $lastName = 'Customer';
                    }

                    if (empty($hospitalUhid)) {
                        $maxPatId = (int)$hospConn->query("SELECT MAX(patient_id) FROM patients")->fetchColumn();
                        $nextId = $maxPatId + 1;
                        $hospitalUhid = 'VH-' . date('Y') . '-' . str_pad((string)$nextId, 4, '0', STR_PAD_LEFT);
                    }

                    $insHosp = $hospConn->prepare("
                        INSERT INTO patients 
                            (patient_code, first_name, last_name, phone, gender, dob, address, city, status, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active', NOW(), NOW())
                    ");
                    $insHosp->execute([
                        $hospitalUhid,
                        $firstName,
                        $lastName,
                        $mobile ?: null,
                        $gender,
                        $dob,
                        $address ?: null,
                        $city ?: 'Daund'
                    ]);
                    $hospitalPatientId = (int)$hospConn->lastInsertId();
                }
            } catch (Exception $e) {
                error_log("PatientService Hospital Sync Note: " . $e->getMessage());
            }
        }

        // Generate unique atomic sequence number
        $patientNo = $this->sequenceService->getNextNumber('PATIENT');
        // Ensure uniqueness if historical seed data predated the sequence generator
        $checkStmt = $this->pdo->prepare("SELECT COUNT(*) FROM pharmacy_patients WHERE pharmacy_patient_no = ?");
        $checkStmt->execute([$patientNo]);
        while ((int)$checkStmt->fetchColumn() > 0) {
            $patientNo = $this->sequenceService->getNextNumber('PATIENT');
            $checkStmt->execute([$patientNo]);
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO pharmacy_patients 
                (pharmacy_patient_no, hospital_patient_id, hospital_uhid, name, mobile, gender, date_of_birth, address, city, state, pincode, doctor_id, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', NOW(), NOW())
        ");

        $stmt->execute([
            $patientNo,
            $hospitalPatientId,
            $hospitalUhid,
            $name,
            $mobile ?: null,
            $gender,
            $dob,
            $address ?: null,
            $city ?: null,
            $state ?: null,
            $pincode ?: null,
            $doctorId
        ]);

        $id = (int)$this->pdo->lastInsertId();

        $patient = $this->getPatientById($id);

        // Record non-destructive audit log
        $this->auditService->log(
            'PATIENT_CREATE',
            'pharmacy_patients',
            (string)$id,
            null,
            $patient
        );

        return $patient;
    }

    /**
     * Update an existing pharmacy patient record.
     * Preserves patient primary key ID and pharmacy_patient_no.
     */
    public function updateLocalPatient(int $id, array $data, ?int $userId = null): array
    {
        $existing = $this->getPatientById($id);
        if (!$existing) {
            throw new Exception("Patient ID #{$id} not found.");
        }

        $name = trim($data['name'] ?? '');
        if ($name === '') {
            throw new Exception("Patient name is required.");
        }

        $mobile = trim($data['mobile'] ?? '');
        $gender = in_array($data['gender'] ?? '', ['Male', 'Female', 'Other']) ? $data['gender'] : 'Other';
        $dob = !empty($data['date_of_birth']) ? $data['date_of_birth'] : null;
        $address = trim($data['address'] ?? '');
        $city = trim($data['city'] ?? '');
        $state = trim($data['state'] ?? '');
        $pincode = trim($data['pincode'] ?? '');
        $hospitalPatientId = !empty($data['hospital_patient_id']) ? (int)$data['hospital_patient_id'] : null;
        $hospitalUhid = !empty($data['hospital_uhid']) ? trim($data['hospital_uhid']) : null;
        $doctorId = !empty($data['doctor_id']) ? (int)$data['doctor_id'] : null;

        $stmt = $this->pdo->prepare("
            UPDATE pharmacy_patients SET
                hospital_patient_id = ?,
                hospital_uhid = ?,
                name = ?,
                mobile = ?,
                gender = ?,
                date_of_birth = ?,
                address = ?,
                city = ?,
                state = ?,
                pincode = ?,
                doctor_id = ?,
                updated_at = NOW()
            WHERE id = ?
        ");

        $stmt->execute([
            $hospitalPatientId,
            $hospitalUhid,
            $name,
            $mobile ?: null,
            $gender,
            $dob,
            $address ?: null,
            $city ?: null,
            $state ?: null,
            $pincode ?: null,
            $doctorId,
            $id
        ]);

        // Bidirectional Interdependence: Sync updated demographic info into Hospital MediPro patients
        $hospConn = $this->hospitalPdo;
        if ($hospitalPatientId && $hospConn) {
            try {
                $nameParts = explode(' ', $name, 2);
                $firstName = trim($nameParts[0]);
                $lastName = trim($nameParts[1] ?? '');
                $updHosp = $hospConn->prepare("
                    UPDATE patients SET
                        first_name = ?,
                        last_name = ?,
                        phone = ?,
                        gender = ?,
                        dob = ?,
                        address = ?,
                        city = ?,
                        updated_at = NOW()
                    WHERE patient_id = ?
                ");
                $updHosp->execute([
                    $firstName,
                    $lastName,
                    $mobile ?: null,
                    $gender,
                    $dob,
                    $address ?: null,
                    $city ?: null,
                    $hospitalPatientId
                ]);
            } catch (Exception $e) {
                error_log("PatientService Hospital Sync on Update Error: " . $e->getMessage());
            }
        }

        $updatedPatient = $this->getPatientById($id);

        $this->auditService->log(
            'PATIENT_UPDATE',
            'pharmacy_patients',
            (string)$id,
            $existing,
            $updatedPatient
        );

        return $updatedPatient;
    }

    /**
     * Get patient by local ID.
     */
    public function getPatientById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM pharmacy_patients WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Get patient by Pharmacy Patient Number (PP-XXXXXX).
     */
    public function getPatientByNo(string $patientNo): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM pharmacy_patients WHERE pharmacy_patient_no = ? LIMIT 1");
        $stmt->execute([$patientNo]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Total registered pharmacy patients count.
     */
    public function count(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM pharmacy_patients WHERE status = 'Active'")->fetchColumn();
    }
}
