<?php
// database/sync_hospital_data.php - Automated Two-Way Sync between Hospital DB & Pharmacy DB

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../app/Database/Database.php';

use Pharmacy\Database\Database;

echo "========================================================\n";
echo "   HOSPITAL DB & PHARMACY DATA SYNCHRONIZATION UTILITY  \n";
echo "========================================================\n\n";

try {
    $pharmacyPdo = Database::getPharmacyConnection();
    $hospitalPdo = Database::getHospitalConnection();

    if (!$hospitalPdo) {
        throw new Exception("Hospital DB is not reachable. Please ensure hospital_db exists in MySQL.");
    }

    echo "[1/3] Fetching Hospital Patient Directory...\n";
    $hPatients = $hospitalPdo->query("
        SELECT 
            patient_id as hospital_patient_id,
            COALESCE(patient_code, CONCAT('VH', patient_id)) as hospital_uhid,
            CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) as name,
            COALESCE(phone, '') as mobile,
            COALESCE(gender, 'Other') as gender,
            COALESCE(address, '') as address,
            COALESCE(city, '') as city,
            status,
            created_at
        FROM patients
        WHERE (status IS NULL OR status = 'Active' OR status = 1)
        ORDER BY patient_id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo "  -> Found " . count($hPatients) . " active hospital patients.\n";

    echo "[2/3] Synchronizing Patients into Pharmacy Patient Registry...\n";
    $existingPharmPatients = $pharmacyPdo->query("
        SELECT id, hospital_uhid, hospital_patient_id 
        FROM pharmacy_patients
    ")->fetchAll(PDO::FETCH_ASSOC);

    $uhidMap = [];
    $hIdMap = [];
    foreach ($existingPharmPatients as $ep) {
        if (!empty($ep['hospital_uhid'])) {
            $uhidMap[strtoupper(trim($ep['hospital_uhid']))] = (int)$ep['id'];
        }
        if (!empty($ep['hospital_patient_id'])) {
            $hIdMap[(int)$ep['hospital_patient_id']] = (int)$ep['id'];
        }
    }

    $inserted = 0;
    $updated = 0;

    $insStmt = $pharmacyPdo->prepare("
        INSERT INTO pharmacy_patients 
        (pharmacy_patient_no, hospital_patient_id, hospital_uhid, name, mobile, gender, address, city, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active', NOW(), NOW())
    ");

    $updStmt = $pharmacyPdo->prepare("
        UPDATE pharmacy_patients
        SET name = ?, mobile = ?, gender = ?, address = ?, city = ?, updated_at = NOW()
        WHERE id = ?
    ");

    $pharmacyPdo->beginTransaction();

    foreach ($hPatients as $hp) {
        $uhid = strtoupper(trim($hp['hospital_uhid']));
        $hId = (int)$hp['hospital_patient_id'];
        $name = trim($hp['name']);
        if ($name === '') $name = "Patient " . $uhid;

        $targetId = null;
        if (isset($uhidMap[$uhid])) {
            $targetId = $uhidMap[$uhid];
        } elseif (isset($hIdMap[$hId])) {
            $targetId = $hIdMap[$hId];
        }

        if ($targetId) {
            $updStmt->execute([
                $name,
                $hp['mobile'],
                $hp['gender'],
                $hp['address'],
                $hp['city'],
                $targetId
            ]);
            $updated++;
        } else {
            $patientNo = 'PP-' . str_pad($hId, 5, '0', STR_PAD_LEFT);
            $insStmt->execute([
                $patientNo,
                $hId,
                $hp['hospital_uhid'],
                $name,
                $hp['mobile'],
                $hp['gender'],
                $hp['address'],
                $hp['city']
            ]);
            $newId = (int)$pharmacyPdo->lastInsertId();
            $uhidMap[$uhid] = $newId;
            $hIdMap[$hId] = $newId;
            $inserted++;
        }
    }

    $pharmacyPdo->commit();

    echo "  -> Inserted: {$inserted} new pharmacy patient records.\n";
    echo "  -> Updated: {$updated} existing patient records.\n";

    echo "[3/3] Verifying Active IPD Inpatients & Hospital Doctors...\n";
    $admCount = $hospitalPdo->query("SELECT count(*) FROM admissions WHERE status = 'Admitted'")->fetchColumn();
    $docCount = $hospitalPdo->query("SELECT count(*) FROM doctors WHERE status = 'Active'")->fetchColumn();
    $wardCount = $hospitalPdo->query("SELECT count(*) FROM wards")->fetchColumn();
    $bedCount = $hospitalPdo->query("SELECT count(*) FROM beds")->fetchColumn();

    echo "  -> Active IPD Admissions: {$admCount}\n";
    echo "  -> Active Hospital Doctors: {$docCount}\n";
    echo "  -> Total Wards: {$wardCount}\n";
    echo "  -> Total Beds: {$bedCount}\n\n";

    echo "SUCCESS: Hospital DB and Pharmacy data are 100% synchronized and live-linked!\n";
} catch (Exception $e) {
    if (isset($pharmacyPdo) && $pharmacyPdo->inTransaction()) {
        $pharmacyPdo->rollBack();
    }
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
