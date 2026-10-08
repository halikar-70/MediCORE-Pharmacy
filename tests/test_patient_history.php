<?php
// test_patient_history.php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

$_GET['uhid'] = 'VH3319';
$_GET['name'] = 'Swaraj Sagar Shinde';
$_GET['id'] = '3319';

// Set up mock session so require_permission passes
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['pharmacy_logged_in'] = true;
$_SESSION['pharmacy_user_id'] = 1;
$_SESSION['pharmacy_role_id'] = 1;
$_SESSION['pharmacy_role_name'] = 'Admin';
$_SESSION['pharmacy_user'] = [
    'user_id' => 1,
    'username' => 'admin',
    'role' => 'admin',
    'permissions' => ['*']
];

ob_start();
require __DIR__ . '/../modules/patients/history.php';
$output = ob_get_clean();

if (str_contains($output, 'Fatal error') || str_contains($output, 'PDOException')) {
    echo "[-] FAILED with error:\n" . $output . "\n";
    exit(1);
}

echo "[+] SUCCESS: Patient history rendered perfectly (" . strlen($output) . " bytes)\n";
if (str_contains($output, 'Sameer Saifi')) {
    echo "[+] SUCCESS: Patient 'Sameer Saifi' found in rendered HTML!\n";
}
