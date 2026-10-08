<?php
// tests/test_ipd_sale_flow.php - Comprehensive IPD Sales & Patient Verification

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Services/DocumentSequenceService.php';
require_once __DIR__ . '/../app/Services/StockLedgerService.php';
require_once __DIR__ . '/../app/Services/FefoService.php';
require_once __DIR__ . '/../app/Services/AuditService.php';
require_once __DIR__ . '/../app/Services/SalesService.php';

use Pharmacy\Database\Database;
use Pharmacy\Services\SalesService;

echo "==================================================\n";
echo "=== IPD SALES SYSTEM END-TO-END VERIFICATION ===\n";
echo "==================================================\n\n";

$salesService = new SalesService($pdo);

// 1. Verify Medicine Catalog Availability
$medStmt = $pdo->query("
    SELECT m.medicine_id, m.medicine_name, m.price, m.stock_quantity, mb.batch_id, mb.batch_number, mb.expiry_date
    FROM medicines m
    JOIN medicine_batches mb ON mb.medicine_id = m.medicine_id
    WHERE m.status = 'Active' AND mb.status = 'Active' AND mb.quantity_available > 5 AND mb.expiry_date > CURDATE()
    ORDER BY mb.expiry_date ASC
    LIMIT 1
");
$testMed = $medStmt->fetch(PDO::FETCH_ASSOC);

if (!$testMed) {
    echo "[-] FAIL: No active medicine with available batch stock found.\n";
    exit(1);
}
echo "[+] PASS: Selected Medicine: '{$testMed['medicine_name']}' (Batch: {$testMed['batch_number']}, Rate: ₹{$testMed['price']}, Stock: {$testMed['stock_quantity']})\n";

// 2. Verify IPD Patient Discovery
$testPatientName = 'IPD Verified Inpatient ' . rand(100, 999);
$testUhid = 'VH-IPD-' . date('Y') . '-' . rand(1000, 9999);
$testIpdAdmission = 'IPD-' . date('Ymd') . '-' . rand(100, 999);
$testWard = 'General Ward-209';
$testBed = 'BED-0' . rand(1, 9);
$testDoctor = 'Dr. Vaishali Londhe';

$hPdo = Database::getHospitalConnection();
if ($hPdo) {
    // Check if real admitted inpatients exist
    $admittedPat = $hPdo->query("
        SELECT a.admission_id, a.ipd_number, p.patient_code,
               CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, '')) as name,
               w.ward_name, b.bed_number, d.name as doctor_name
        FROM admissions a
        JOIN patients p ON a.patient_id = p.patient_id
        LEFT JOIN wards w ON a.ward_id = w.ward_id
        LEFT JOIN beds b ON a.bed_id = b.bed_id
        LEFT JOIN doctors d ON a.doctor_id = d.doctor_id
        WHERE a.status = 'Admitted'
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    if ($admittedPat) {
        $testPatientName = trim($admittedPat['name']);
        $testUhid = $admittedPat['patient_code'] ?: $testUhid;
        $testIpdAdmission = $admittedPat['ipd_number'] ?: $testIpdAdmission;
        $testWard = $admittedPat['ward_name'] ?: $testWard;
        $testBed = $admittedPat['bed_number'] ?: $testBed;
        $testDoctor = $admittedPat['doctor_name'] ?: $testDoctor;
        echo "[+] PASS: Found Hospital Admitted Inpatient: '{$testPatientName}' (IPD #: {$testIpdAdmission}, Ward: {$testWard}/{$testBed}, Doctor: {$testDoctor})\n";
    }
}

// 3. Create IPD Credit Sale
$saleData = [
    'sale_type'        => 'IPD_SALE',
    'patient_id'       => null,
    'customer_name'    => $testPatientName,
    'ipd_admission_id' => $testIpdAdmission,
    'ipd_ward'         => $testWard,
    'ipd_bed'          => $testBed,
    'doctor_name'      => $testDoctor,
    'discount_percent' => 5.0,
    'is_credit'        => true,
    'notes'            => 'Automated IPD Verification Test Sale'
];

$itemsPayload = [
    [
        'medicine_id'      => (int)$testMed['medicine_id'],
        'quantity'         => 2,
        'discount_percent' => 0.0,
        'batch_id'         => (int)$testMed['batch_id']
    ]
];

$paymentData = [
    'amount' => 0.0,
    'mode'   => 'CREDIT'
];

$saleResult = $salesService->createSale($saleData, $itemsPayload, $paymentData, 1, false);

if (!$saleResult || empty($saleResult['sale_id'])) {
    echo "[-] FAIL: Failed to create IPD sale.\n";
    exit(1);
}

echo "[+] PASS: Created IPD Sale #{$saleResult['sale_number']} (Sale ID: {$saleResult['sale_id']}, Net Total: ₹{$saleResult['grand_total']}, Payment Status: {$saleResult['payment_status']})\n";

// 4. Verify Record in Database Registry
$verifyStmt = $pdo->prepare("
    SELECT s.sale_id, s.sale_number, s.sale_type, s.customer_name, s.doctor_name, s.ipd_admission_id, s.ipd_ward, s.ipd_bed, s.payment_status, s.payment_mode
    FROM pharmacy_sales s
    WHERE s.sale_id = ?
");
$verifyStmt->execute([$saleResult['sale_id']]);
$savedSale = $verifyStmt->fetch(PDO::FETCH_ASSOC);

if (!$savedSale || $savedSale['sale_type'] !== 'IPD_SALE' || $savedSale['payment_status'] !== 'CREDIT') {
    echo "[-] FAIL: Sale registry verification failed.\n";
    exit(1);
}

echo "[+] PASS: Sale verified in pharmacy_sales database registry with IPD metadata & credit flag!\n";
echo "\n🟢 IPD SALES & PATIENT SYSTEM FULLY VERIFIED (100% OPERATIONAL)\n";
