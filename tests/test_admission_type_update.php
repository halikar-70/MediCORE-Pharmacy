<?php
// Test updating admission type
require_once __DIR__ . '/../config/database.php';

$h = Pharmacy\Database\Database::getHospitalConnection();
if ($h) {
    // Find a Cashless admission
    $row = $h->query("SELECT admission_id, patient_id, ipd_number, admission_type, is_mediclaim FROM admissions WHERE admission_type = 'Cashless' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $admId = (int)$row['admission_id'];
        echo "Found Cashless admission ID {$admId}: Type={$row['admission_type']}, Mediclaim={$row['is_mediclaim']}\n";

        // Update to Paid
        $up = $h->prepare("UPDATE admissions SET admission_type = 'Paid', is_mediclaim = 'No' WHERE admission_id = ?");
        $up->execute([$admId]);

        $check = $h->query("SELECT admission_id, admission_type, is_mediclaim FROM admissions WHERE admission_id = {$admId}")->fetch(PDO::FETCH_ASSOC);
        echo "After switch to Paid: Type={$check['admission_type']}, Mediclaim={$check['is_mediclaim']}\n";

        // Revert back to Cashless
        $up = $h->prepare("UPDATE admissions SET admission_type = 'Cashless', is_mediclaim = 'Yes' WHERE admission_id = ?");
        $up->execute([$admId]);

        $check2 = $h->query("SELECT admission_id, admission_type, is_mediclaim FROM admissions WHERE admission_id = {$admId}")->fetch(PDO::FETCH_ASSOC);
        echo "After switch back to Cashless: Type={$check2['admission_type']}, Mediclaim={$check2['is_mediclaim']}\n";
    }
}
