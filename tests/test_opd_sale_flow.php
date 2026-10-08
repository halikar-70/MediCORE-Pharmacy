<?php
// tests/test_opd_sale_flow.php - Dedicated Acceptance Test for OPD POS Counter Sales

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Services/DocumentSequenceService.php';
require_once __DIR__ . '/../app/Services/StockLedgerService.php';
require_once __DIR__ . '/../app/Services/FefoService.php';
require_once __DIR__ . '/../app/Services/AuditService.php';
require_once __DIR__ . '/../app/Services/SalesService.php';

use Pharmacy\Services\SalesService;

echo "==================================================\n";
echo "=== OPD SALES SYSTEM END-TO-END VERIFICATION ===\n";
echo "==================================================\n\n";

$salesService = new SalesService($pdo);

// 1. Check medicine catalog search
$searchResult = $salesService->searchMedicines('Paracetamol', 5);
if (empty($searchResult)) {
    // Fallback search any active medicine
    $searchResult = $salesService->searchMedicines('', 5);
}

if (empty($searchResult)) {
    echo "[-] FAIL: No active medicines found in pharmacy catalog.\n";
    exit(1);
}

$sampleMed = $searchResult[0];
echo "[+] PASS: Medicine search returned '{$sampleMed['medicine_name']}' (ID: {$sampleMed['medicine_id']}, Price: ₹{$sampleMed['sale_price']}, Stock: {$sampleMed['available_stock']})\n";

// 2. Perform end-to-end Counter Sale transaction
$testCustomer = 'OPD Verified Patient ' . rand(100, 999);
$testMobile = '98765' . rand(10000, 99999);
$testDoctor = 'Dr. Sharma';

$saleData = [
    'sale_type'        => 'COUNTER_SALE',
    'customer_name'    => $testCustomer,
    'customer_mobile'  => $testMobile,
    'doctor_name'      => $testDoctor,
    'discount_percent' => 5.0, // 5% discount
    'notes'            => 'Automated OPD POS test sale'
];

$itemsPayload = [
    [
        'medicine_id'      => (int)$sampleMed['medicine_id'],
        'quantity'         => 1,
        'discount_percent' => 0.0
    ]
];

$paymentData = [
    'amount'    => (float)$sampleMed['sale_price'] * 0.95,
    'mode'      => 'CASH',
    'reference' => 'TEST-CASH'
];

try {
    $sale = $salesService->createSale($saleData, $itemsPayload, $paymentData, 1, true);
    echo "[+] PASS: Created Counter Sale #{$sale['sale_number']} (Sale ID: {$sale['sale_id']}, Grand Total: ₹{$sale['grand_total']}, Status: {$sale['payment_status']})\n";

    // 3. Verify Recent OPD Bills Query
    $recentStmt = $pdo->prepare("
        SELECT s.sale_id, s.sale_number, s.customer_name, s.grand_total, s.payment_status
        FROM pharmacy_sales s
        WHERE s.sale_id = ? AND s.sale_type = 'COUNTER_SALE'
    ");
    $recentStmt->execute([$sale['sale_id']]);
    $verified = $recentStmt->fetch(PDO::FETCH_ASSOC);

    if ($verified && $verified['customer_name'] === $testCustomer) {
        echo "[+] PASS: Sale verified in pharmacy_sales database registry!\n";
    } else {
        echo "[-] FAIL: Sale could not be verified in pharmacy_sales registry.\n";
        exit(1);
    }

    echo "\n🟢 OPD SYSTEM TEST COMPLETED SUCCESSFULLY (100% OPERATIONAL)\n";
    exit(0);

} catch (Exception $e) {
    echo "[-] ERROR during sale creation: " . $e->getMessage() . "\n";
    exit(1);
}
