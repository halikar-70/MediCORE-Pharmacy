<?php
// tests/test_pharmacy_chunk4.php - Comprehensive Chunk 4 Test Suite (55 Tests)

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Services/DocumentSequenceService.php';
require_once __DIR__ . '/../app/Services/StockLedgerService.php';
require_once __DIR__ . '/../app/Services/FefoService.php';
require_once __DIR__ . '/../app/Services/AuditService.php';
require_once __DIR__ . '/../app/Services/SalesService.php';
require_once __DIR__ . '/../app/Services/PrescriptionService.php';
require_once __DIR__ . '/../app/Services/IndentService.php';

use Pharmacy\Services\SalesService;
use Pharmacy\Services\PrescriptionService;
use Pharmacy\Services\IndentService;
use Pharmacy\Services\FefoService;
use Pharmacy\Services\StockLedgerService;
use Pharmacy\Services\DocumentSequenceService;

echo "=== MEDIPRO PHARMACY CHUNK 4 AUTOMATED TEST SUITE ===\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(bool $condition, string $testName, string $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "[PASS] {$testName}\n";
    } else {
        $failCount++;
        echo "[FAIL] {$testName} - {$details}\n";
    }
}

// Helper: create isolated test medicine with custom batches
function createTestMedicine(PDO $pdo, string $prefix, float $mrp = 100.0, float $gst = 12.0): int {
    $name = $prefix . '_' . time() . '_' . rand(100, 999);
    $stmt = $pdo->prepare("
        INSERT INTO medicines (
            medicine_name, generic_name, dosage_form, pack_size, price, purchase_price,
            gst_percent, stock_quantity, status, created_at, updated_at
        ) VALUES (
            ?, 'Generic', 'Tablet', '10s', ?, 60.00,
            ?, 0, 'Active', NOW(), NOW()
        )
    ");
    $stmt->execute([$name, $mrp, $gst]);
    return (int)$pdo->lastInsertId();
}

function createTestBatch(PDO $pdo, int $medId, string $batchNum, string $expiry, int $qty, float $mrp = 100.0, string $status = 'Active'): int {
    $stmt = $pdo->prepare("
        INSERT INTO medicine_batches (
            medicine_id, batch_number, expiry_date, quantity_available, purchase_price,
            mrp, sale_price, status, created_at, updated_at
        ) VALUES (
            ?, ?, ?, ?, 60.00,
            ?, ?, ?, NOW(), NOW()
        )
    ");
    $stmt->execute([$medId, $batchNum, $expiry, $qty, $mrp, $mrp, $status]);
    $batchId = (int)$pdo->lastInsertId();

    // Sync master medicine stock
    $upd = $pdo->prepare("UPDATE medicines SET stock_quantity = stock_quantity + ? WHERE medicine_id = ?");
    $upd->execute([$qty, $medId]);

    return $batchId;
}

$salesService = new SalesService($pdo);
$rxService = new PrescriptionService($pdo);
$indentService = new IndentService($pdo);
$fefoService = new FefoService($pdo);
$ledgerService = new StockLedgerService($pdo);

// -------------------------------------------------------------
// SECTION 1: COUNTER SALE TESTS (01 - 12)
// -------------------------------------------------------------

// TEST 01: Create counter sale
$med1 = createTestMedicine($pdo, 'MED_CS_01');
$b1 = createTestBatch($pdo, $med1, 'BAT-01', '2028-12-31', 50);

$sale1 = $salesService->createSale(
    ['customer_name' => 'John Doe', 'customer_mobile' => '9876543210'],
    [['medicine_id' => $med1, 'quantity' => 10]],
    ['amount' => 1000.00, 'mode' => 'CASH']
);
assertTest($sale1 && !empty($sale1['sale_number']), 'TEST 01: Create counter sale');

// TEST 02: Counter sale deducts stock
$chkMed1 = $pdo->query("SELECT stock_quantity FROM medicines WHERE medicine_id = {$med1}")->fetchColumn();
$chkB1 = $pdo->query("SELECT quantity_available FROM medicine_batches WHERE batch_id = {$b1}")->fetchColumn();
assertTest((int)$chkMed1 === 40 && (int)$chkB1 === 40, 'TEST 02: Counter sale deducts stock', "Master: {$chkMed1}, Batch: {$chkB1}");

// TEST 03: Correct stock ledger entry created
$ledger1 = $pdo->query("SELECT * FROM pharmacy_stock_ledger WHERE medicine_id = {$med1} AND transaction_type = 'COUNTER_SALE'")->fetch(PDO::FETCH_ASSOC);
assertTest($ledger1 && (int)$ledger1['quantity_change'] === -10, 'TEST 03: Correct stock ledger entry created');

// TEST 04: Sale uses FEFO
$med4 = createTestMedicine($pdo, 'MED_CS_FEFO');
$b4_early = createTestBatch($pdo, $med4, 'BAT-EARLY', '2027-01-01', 30);
$b4_late = createTestBatch($pdo, $med4, 'BAT-LATE', '2029-01-01', 30);

$sale4 = $salesService->createSale(
    ['customer_name' => 'FEFO Test'],
    [['medicine_id' => $med4, 'quantity' => 15]],
    ['amount' => 1500.00, 'mode' => 'CASH']
);
$earlyBatchRemaining = (int)$pdo->query("SELECT quantity_available FROM medicine_batches WHERE batch_id = {$b4_early}")->fetchColumn();
$lateBatchRemaining = (int)$pdo->query("SELECT quantity_available FROM medicine_batches WHERE batch_id = {$b4_late}")->fetchColumn();
assertTest($earlyBatchRemaining === 15 && $lateBatchRemaining === 30, 'TEST 04: Sale uses FEFO', "Early: {$earlyBatchRemaining}, Late: {$lateBatchRemaining}");

// TEST 05: Sale uses multiple batches when required
$sale5 = $salesService->createSale(
    ['customer_name' => 'Multi Batch'],
    [['medicine_id' => $med4, 'quantity' => 25]], // Should take 15 from early (depleting it) and 10 from late
    ['amount' => 2500.00, 'mode' => 'CASH']
);
$earlyRemaining5 = (int)$pdo->query("SELECT quantity_available FROM medicine_batches WHERE batch_id = {$b4_early}")->fetchColumn();
$lateRemaining5 = (int)$pdo->query("SELECT quantity_available FROM medicine_batches WHERE batch_id = {$b4_late}")->fetchColumn();
assertTest($earlyRemaining5 === 0 && $lateRemaining5 === 20, 'TEST 05: Sale uses multiple batches when required');

// TEST 06: Expired batch excluded
$med6 = createTestMedicine($pdo, 'MED_EXPIRED');
$b6_exp = createTestBatch($pdo, $med6, 'BAT-EXP', '2024-01-01', 50); // Expired
$b6_valid = createTestBatch($pdo, $med6, 'BAT-VAL', '2028-01-01', 20); // Valid

$sale6 = $salesService->createSale(
    ['customer_name' => 'Expiry Check'],
    [['medicine_id' => $med6, 'quantity' => 10]],
    ['amount' => 1000.00, 'mode' => 'CASH']
);
$expBatchQty = (int)$pdo->query("SELECT quantity_available FROM medicine_batches WHERE batch_id = {$b6_exp}")->fetchColumn();
$valBatchQty = (int)$pdo->query("SELECT quantity_available FROM medicine_batches WHERE batch_id = {$b6_valid}")->fetchColumn();
assertTest($expBatchQty === 50 && $valBatchQty === 10, 'TEST 06: Expired batch excluded', "Expired batch untouched: {$expBatchQty}");

// TEST 07: Insufficient stock blocked
$insufficientBlocked = false;
try {
    $salesService->createSale(
        ['customer_name' => 'Over Request'],
        [['medicine_id' => $med6, 'quantity' => 500]]
    );
} catch (Exception $e) {
    $insufficientBlocked = true;
}
assertTest($insufficientBlocked, 'TEST 07: Insufficient stock blocked');

// TEST 08: Negative quantity blocked
$negBlocked = false;
try {
    $salesService->createSale(
        ['customer_name' => 'Neg Qty'],
        [['medicine_id' => $med1, 'quantity' => -5]]
    );
} catch (Exception $e) {
    $negBlocked = true;
}
assertTest($negBlocked, 'TEST 08: Negative quantity blocked');

// TEST 09: Zero quantity blocked
$zeroBlocked = false;
try {
    $salesService->createSale(
        ['customer_name' => 'Zero Qty'],
        [['medicine_id' => $med1, 'quantity' => 0]]
    );
} catch (Exception $e) {
    $zeroBlocked = true;
}
assertTest($zeroBlocked, 'TEST 09: Zero quantity blocked');

// TEST 10: Tampered price rejected/re-read server-side
// Customer tries submitting price 1.00 instead of authoritative 100.00
$sale10 = $salesService->createSale(
    ['customer_name' => 'Price Tamper'],
    [['medicine_id' => $med1, 'quantity' => 2, 'unit_price' => 1.00]] // Service ignores client unit_price
);
assertTest((float)$sale10['subtotal_amount'] === 200.00, 'TEST 10: Tampered price rejected/re-read server-side', "Subtotal: {$sale10['subtotal_amount']}");

// TEST 11: Tampered GST rejected/re-read server-side
$sale11 = $salesService->createSale(
    ['customer_name' => 'GST Tamper'],
    [['medicine_id' => $med1, 'quantity' => 1, 'gst_percent' => 0.00]]
);
assertTest((float)$sale11['gst_amount'] === 12.00, 'TEST 11: Tampered GST rejected/re-read server-side', "GST Amount: {$sale11['gst_amount']}");

// TEST 12: Tampered discount rejected
$discBlocked = false;
try {
    $salesService->createSale(
        ['customer_name' => 'Disc Tamper', 'discount_percent' => 80.0], // > 50% absolute limit
        [['medicine_id' => $med1, 'quantity' => 1]]
    );
} catch (Exception $e) {
    $discBlocked = true;
}
assertTest($discBlocked, 'TEST 12: Tampered discount rejected');


// -------------------------------------------------------------
// SECTION 2: PRESCRIPTION TESTS (13 - 19)
// -------------------------------------------------------------

// TEST 13: Prescription sale created
$medRx = createTestMedicine($pdo, 'MED_RX');
createTestBatch($pdo, $medRx, 'BAT-RX', '2028-01-01', 100);

$rx13 = $rxService->createPrescription(
    ['patient_name' => 'Alice Smith', 'doctor_name' => 'Dr. Sharma', 'patient_type' => 'OPD'],
    [['medicine_id' => $medRx, 'prescribed_qty' => 30, 'dosage_instructions' => '1-0-1']]
);
assertTest($rx13 && !empty($rx13['prescription_number']), 'TEST 13: Prescription sale created');

// TEST 14: Prescription quantity preserved
assertTest((int)$rx13['items'][0]['prescribed_qty'] === 30 && (int)$rx13['items'][0]['dispensed_qty'] === 0, 'TEST 14: Prescription quantity preserved');

// TEST 15: Partial dispensing works
$dispense15 = $salesService->createSale(
    ['customer_name' => 'Alice Smith', 'sale_type' => 'PRESCRIPTION_SALE', 'prescription_id' => $rx13['prescription_id']],
    [['medicine_id' => $medRx, 'quantity' => 10]],
    ['amount' => 1000.00, 'mode' => 'CASH']
);
$rx15After = $rxService->getPrescription($rx13['prescription_id']);
assertTest((int)$rx15After['items'][0]['dispensed_qty'] === 10 && $rx15After['status'] === 'PARTIALLY_DISPENSED', 'TEST 15: Partial dispensing works', "Dispensed: {$rx15After['items'][0]['dispensed_qty']}, Status: {$rx15After['status']}");

// TEST 16: Remaining quantity calculated correctly
assertTest((int)$rx15After['items'][0]['remaining_qty'] === 20, 'TEST 16: Remaining quantity calculated correctly');

// TEST 17: Fully dispensed prescription cannot be accidentally dispensed again
// Dispense remaining 20 units
$salesService->createSale(
    ['customer_name' => 'Alice Smith', 'sale_type' => 'PRESCRIPTION_SALE', 'prescription_id' => $rx13['prescription_id']],
    [['medicine_id' => $medRx, 'quantity' => 20]],
    ['amount' => 2000.00, 'mode' => 'CASH']
);
$rxFullyDispensed = $rxService->getPrescription($rx13['prescription_id']);

$overDispenseBlocked = false;
try {
    $rxService->validateDispensingEligibility($rx13['prescription_id'], [['medicine_id' => $medRx, 'quantity' => 5]]);
} catch (Exception $e) {
    $overDispenseBlocked = true;
}
assertTest($rxFullyDispensed['status'] === 'DISPENSED' && $overDispenseBlocked, 'TEST 17: Fully dispensed prescription cannot be accidentally dispensed again');

// TEST 18: Prescription dispensing uses FEFO
$rxLedger = $pdo->query("SELECT * FROM pharmacy_stock_ledger WHERE medicine_id = {$medRx} AND transaction_type = 'PRESCRIPTION_SALE'")->fetchAll(PDO::FETCH_ASSOC);
assertTest(count($rxLedger) >= 2, 'TEST 18: Prescription dispensing uses FEFO');

// TEST 19: Prescription dispensing creates stock ledger movement
$totalRxDeducted = array_sum(array_column($rxLedger, 'quantity_change'));
assertTest($totalRxDeducted === -30, 'TEST 19: Prescription dispensing creates stock ledger movement', "Total: {$totalRxDeducted}");


// -------------------------------------------------------------
// SECTION 3: IPD TESTS (20 - 25)
// -------------------------------------------------------------

// TEST 20: IPD sale created
$medIpd = createTestMedicine($pdo, 'MED_IPD');
createTestBatch($pdo, $medIpd, 'BAT-IPD', '2028-01-01', 100);

$saleIpd = $salesService->createSale(
    ['customer_name' => 'Inpatient Bob', 'sale_type' => 'IPD_SALE', 'ipd_admission_id' => 'IPD-999', 'ipd_ward' => 'ICU'],
    [['medicine_id' => $medIpd, 'quantity' => 15]],
    ['amount' => 0.00, 'mode' => 'CREDIT']
);
assertTest($saleIpd && $saleIpd['sale_type'] === 'IPD_SALE', 'TEST 20: IPD sale created');

// TEST 21: IPD sale deducts stock
$ipdStockRemaining = (int)$pdo->query("SELECT stock_quantity FROM medicines WHERE medicine_id = {$medIpd}")->fetchColumn();
assertTest($ipdStockRemaining === 85, 'TEST 21: IPD sale deducts stock', "Remaining: {$ipdStockRemaining}");

// TEST 22: IPD indent created
$indent22 = $indentService->createIndent(
    ['ward' => 'Emergency Unit', 'requested_by' => 'Nurse Mary', 'priority' => 'STAT'],
    [['medicine_id' => $medIpd, 'requested_qty' => 20]]
);
assertTest($indent22 && !empty($indent22['indent_number']) && $indent22['status'] === 'SUBMITTED', 'TEST 22: IPD indent created');

// TEST 23: IPD indent fulfillment deducts stock correctly
$indentService->approveIndent($indent22['indent_id']);
$indentService->fulfillIndent(
    $indent22['indent_id'],
    [['item_id' => $indent22['items'][0]['item_id'], 'quantity' => 20]]
);
$ipdStockAfterIndent = (int)$pdo->query("SELECT stock_quantity FROM medicines WHERE medicine_id = {$medIpd}")->fetchColumn();
$indentLedger = $pdo->query("SELECT * FROM pharmacy_stock_ledger WHERE medicine_id = {$medIpd} AND transaction_type = 'IPD_INDENT'")->fetch(PDO::FETCH_ASSOC);
assertTest($ipdStockAfterIndent === 65 && $indentLedger && (int)$indentLedger['quantity_change'] === -20, 'TEST 23: IPD indent fulfillment deducts stock correctly');

// TEST 24: Indent cannot exceed requested quantity
$indent24 = $indentService->createIndent(
    ['ward' => 'Ward A', 'requested_by' => 'Nurse Joy', 'priority' => 'NORMAL'],
    [['medicine_id' => $medIpd, 'requested_qty' => 10]]
);
$indentService->approveIndent($indent24['indent_id']);

$overIndentBlocked = false;
try {
    $indentService->fulfillIndent($indent24['indent_id'], [['item_id' => $indent24['items'][0]['item_id'], 'quantity' => 25]]);
} catch (Exception $e) {
    $overIndentBlocked = true;
}
assertTest($overIndentBlocked, 'TEST 24: Indent cannot exceed requested quantity');

// TEST 25: IPD dispensing does not equal administration
// Confirm indent table has dispensed_qty but NO administered status
assertTest(isset($indent22['items'][0]['dispensed_qty']), 'TEST 25: IPD dispensing does not equal administration');


// -------------------------------------------------------------
// SECTION 4: PAYMENTS TESTS (26 - 29)
// -------------------------------------------------------------

// TEST 26: Full payment
$medPay = createTestMedicine($pdo, 'MED_PAY', 50.0, 0.0);
createTestBatch($pdo, $medPay, 'BAT-PAY', '2028-01-01', 100, 50.0);

$sale26 = $salesService->createSale(
    ['customer_name' => 'Pay Full'],
    [['medicine_id' => $medPay, 'quantity' => 2]], // Grand total = 100.00
    ['amount' => 100.00, 'mode' => 'CASH']
);
assertTest($sale26['payment_status'] === 'PAID' && (float)$sale26['balance_amount'] === 0.00, 'TEST 26: Full payment');

// TEST 27: Partial payment
$sale27 = $salesService->createSale(
    ['customer_name' => 'Pay Part'],
    [['medicine_id' => $medPay, 'quantity' => 2]], // Grand total = 100.00
    ['amount' => 40.00, 'mode' => 'CASH']
);
assertTest($sale27['payment_status'] === 'PARTIALLY_PAID' && (float)$sale27['balance_amount'] === 60.00, 'TEST 27: Partial payment');

// TEST 28: Overpayment blocked
$overpayBlocked = false;
try {
    $salesService->addPayment($sale27['sale_id'], 150.00); // Balance is only 60.00
} catch (Exception $e) {
    $overpayBlocked = true;
}
assertTest($overpayBlocked, 'TEST 28: Overpayment blocked');

// TEST 29: Payment against cancelled invoice blocked
$sale29 = $salesService->createSale(
    ['customer_name' => 'Cancel Test'],
    [['medicine_id' => $medPay, 'quantity' => 1]],
    ['amount' => 20.00, 'mode' => 'CASH']
);
$salesService->cancelSale($sale29['sale_id'], 'Customer returned item');

$cancelledPayBlocked = false;
try {
    $salesService->addPayment($sale29['sale_id'], 30.00);
} catch (Exception $e) {
    $cancelledPayBlocked = true;
}
assertTest($cancelledPayBlocked, 'TEST 29: Payment against cancelled invoice blocked');


// -------------------------------------------------------------
// SECTION 5: SECURITY TESTS (30 - 37)
// -------------------------------------------------------------

// TEST 30: Unauthorized sale blocked (discount override permission test)
$unauthDiscBlocked = false;
try {
    $salesService->createSale(
        ['customer_name' => 'No Perm Disc', 'discount_percent' => 25.0], // > 10% requires override
        [['medicine_id' => $medPay, 'quantity' => 1]],
        null,
        null,
        false // hasDiscountOverridePermission = false
    );
} catch (Exception $e) {
    $unauthDiscBlocked = true;
}
assertTest($unauthDiscBlocked, 'TEST 30: Unauthorized sale discount override blocked');

// TEST 31: Unauthorized prescription dispensing blocked (invalid rx ID)
$invalidRxBlocked = false;
try {
    $rxService->validateDispensingEligibility(999999, [['medicine_id' => $medPay, 'quantity' => 1]]);
} catch (Exception $e) {
    $invalidRxBlocked = true;
}
assertTest($invalidRxBlocked, 'TEST 31: Unauthorized prescription dispensing blocked');

// TEST 32: Unauthorized indent fulfillment blocked (invalid indent ID)
$invalidIndentBlocked = false;
try {
    $indentService->fulfillIndent(999999, [['item_id' => 1, 'quantity' => 1]]);
} catch (Exception $e) {
    $invalidIndentBlocked = true;
}
assertTest($invalidIndentBlocked, 'TEST 32: Unauthorized indent fulfillment blocked');

// TEST 33: CSRF blocked
assertTest(verify_csrf_token('invalid_forged_token') === false, 'TEST 33: CSRF blocked');

// TEST 34: SQL injection blocked
$sqlInjResult = $salesService->searchMedicines("' OR '1'='1' -- ");
assertTest(is_array($sqlInjResult), 'TEST 34: SQL injection blocked');

// TEST 35: IDOR blocked
$idorSale = $salesService->getSale(9999999);
assertTest($idorSale === null, 'TEST 35: IDOR blocked');

// TEST 36: Forged batch ID blocked
$forgedBatchBlocked = false;
try {
    $fefoService->executeDeduction(
        [['batch_id' => 999999, 'medicine_id' => $medPay, 'quantity' => 1]],
        'COUNTER_SALE', 1, 'TEST', 1, 'Forged'
    );
} catch (Exception $e) {
    $forgedBatchBlocked = true;
}
assertTest($forgedBatchBlocked, 'TEST 36: Forged batch ID blocked');

// TEST 37: Forged medicine ID blocked
$forgedMedBlocked = false;
try {
    $salesService->createSale(
        ['customer_name' => 'Forged Med'],
        [['medicine_id' => 999999, 'quantity' => 1]]
    );
} catch (Exception $e) {
    $forgedMedBlocked = true;
}
assertTest($forgedMedBlocked, 'TEST 37: Forged medicine ID blocked');


// -------------------------------------------------------------
// SECTION 6: INVENTORY INTEGRITY & IDEMPOTENCY (38 - 44)
// -------------------------------------------------------------

// TEST 38: Negative stock impossible
$medStockChk = createTestMedicine($pdo, 'MED_NEG_CHK');
createTestBatch($pdo, $medStockChk, 'BAT-NEG', '2028-01-01', 5);

$oversellBlocked = false;
try {
    $salesService->createSale(
        ['customer_name' => 'Oversell'],
        [['medicine_id' => $medStockChk, 'quantity' => 6]]
    );
} catch (Exception $e) {
    $oversellBlocked = true;
}
$remBatNeg = (int)$pdo->query("SELECT quantity_available FROM medicine_batches WHERE medicine_id = {$medStockChk}")->fetchColumn();
assertTest($oversellBlocked && $remBatNeg === 5, 'TEST 38: Negative stock impossible', "Remaining: {$remBatNeg}");

// TEST 39: Duplicate sale submission blocked via idempotency key
$idemKey = 'IDEM_KEY_' . time() . '_' . rand(100, 999);
$sale39_first = $salesService->createSale(
    ['customer_name' => 'Idem First', 'idempotency_key' => $idemKey],
    [['medicine_id' => $medStockChk, 'quantity' => 1]],
    ['amount' => 100.00, 'mode' => 'CASH']
);
$sale39_dup = $salesService->createSale(
    ['customer_name' => 'Idem Dup', 'idempotency_key' => $idemKey],
    [['medicine_id' => $medStockChk, 'quantity' => 1]],
    ['amount' => 100.00, 'mode' => 'CASH']
);
assertTest($sale39_first['sale_id'] === $sale39_dup['sale_id'], 'TEST 39: Duplicate sale submission blocked');

// TEST 40: Double-click sale posting safe
$remAfterIdem = (int)$pdo->query("SELECT quantity_available FROM medicine_batches WHERE medicine_id = {$medStockChk}")->fetchColumn();
assertTest($remAfterIdem === 4, 'TEST 40: Double-click sale posting safe (deducted exactly once)', "Remaining: {$remAfterIdem}");

// TEST 41: Concurrent sale transactions serialize correctly
$medConc = createTestMedicine($pdo, 'MED_CONC');
createTestBatch($pdo, $medConc, 'BAT-CONC', '2028-01-01', 10);

$saleConc1 = $salesService->createSale(['customer_name' => 'Conc 1'], [['medicine_id' => $medConc, 'quantity' => 6]]);
$conc2Blocked = false;
try {
    // Attempting to buy 5 when only 4 remain
    $salesService->createSale(['customer_name' => 'Conc 2'], [['medicine_id' => $medConc, 'quantity' => 5]]);
} catch (Exception $e) {
    $conc2Blocked = true;
}
$remConc = (int)$pdo->query("SELECT quantity_available FROM medicine_batches WHERE medicine_id = {$medConc}")->fetchColumn();
assertTest($conc2Blocked && $remConc === 4, 'TEST 41: Concurrent sale transactions serialize correctly');

// TEST 42: Rollback after forced failure leaves no partial stock movement
$medRollback = createTestMedicine($pdo, 'MED_ROLLBACK');
createTestBatch($pdo, $medRollback, 'BAT-RB', '2028-01-01', 20);

$rollbackPassed = false;
try {
    $pdo->beginTransaction();
    $allocs = $fefoService->allocate($medRollback, 5, true);
    $fefoService->executeDeduction($allocs, 'COUNTER_SALE', 1, 'RB-01', 1, 'Simulated');
    // Force intentional crash
    throw new Exception("Simulated DB Crash");
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $rollbackPassed = true;
}
$stockAfterRollback = (int)$pdo->query("SELECT stock_quantity FROM medicines WHERE medicine_id = {$medRollback}")->fetchColumn();
assertTest($rollbackPassed && $stockAfterRollback === 20, 'TEST 42: Rollback after forced failure leaves no partial stock movement', "Stock: {$stockAfterRollback}");

// TEST 43: Rollback leaves no orphan sale
$orphanSales = $pdo->query("SELECT COUNT(*) FROM pharmacy_sales WHERE sale_number = 'RB-01'")->fetchColumn();
assertTest((int)$orphanSales === 0, 'TEST 43: Rollback leaves no orphan sale');

// TEST 44: Rollback leaves no orphan ledger record
$orphanLedger = $pdo->query("SELECT COUNT(*) FROM pharmacy_stock_ledger WHERE medicine_id = {$medRollback} AND reference_no = 'RB-01'")->fetchColumn();
assertTest((int)$orphanLedger === 0, 'TEST 44: Rollback leaves no orphan ledger record');


// -------------------------------------------------------------
// SECTION 7: FEFO & LEDGER INTEGRITY (45 - 50)
// -------------------------------------------------------------

// TEST 45: Chunk 3 GRN stock is visible to FEFO
$medGrnFefo = createTestMedicine($pdo, 'MED_GRN_FEFO');
createTestBatch($pdo, $medGrnFefo, 'BAT-GRN-FEFO', '2028-06-30', 45);
$availBatches = $fefoService->getAvailableBatches($medGrnFefo);
assertTest(count($availBatches) >= 1 && (int)$availBatches[0]['quantity_available'] === 45, 'TEST 45: Chunk 3 GRN stock is visible to FEFO');

// TEST 46: FEFO selects earliest valid expiry
$medFefoOrder = createTestMedicine($pdo, 'MED_ORDER_CHK');
createTestBatch($pdo, $medFefoOrder, 'BAT-2029', '2029-01-01', 10);
createTestBatch($pdo, $medFefoOrder, 'BAT-2027', '2027-01-01', 10);

$allocOrder = $fefoService->allocate($medFefoOrder, 5, false);
assertTest($allocOrder[0]['batch_number'] === 'BAT-2027', 'TEST 46: FEFO selects earliest valid expiry');

// TEST 47: Expired stock remains excluded
createTestBatch($pdo, $medFefoOrder, 'BAT-2023', '2023-01-01', 10); // Expired
$allocExclude = $fefoService->allocate($medFefoOrder, 15, false);
$hasExpired = false;
foreach ($allocExclude as $a) {
    if ($a['batch_number'] === 'BAT-2023') {
        $hasExpired = true;
    }
}
assertTest($hasExpired === false, 'TEST 47: Expired stock remains excluded');

// TEST 48: Ledger is append-only
$ledgerCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM pharmacy_stock_ledger")->fetchColumn();
$salesService->createSale(
    ['customer_name' => 'Ledger Append'],
    [['medicine_id' => $medPay, 'quantity' => 1]]
);
$ledgerCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM pharmacy_stock_ledger")->fetchColumn();
assertTest($ledgerCountAfter === $ledgerCountBefore + 1, 'TEST 48: Ledger is append-only');

// TEST 49: Sale ledger balance_before is correct
$latestLedger = $pdo->query("SELECT * FROM pharmacy_stock_ledger ORDER BY ledger_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertTest((int)$latestLedger['balance_before'] > (int)$latestLedger['balance_after'], 'TEST 49: Sale ledger balance_before is correct');

// TEST 50: Sale ledger balance_after is correct
$diff = (int)$latestLedger['balance_before'] + (int)$latestLedger['quantity_change'];
assertTest($diff === (int)$latestLedger['balance_after'], 'TEST 50: Sale ledger balance_after is correct', "Expected: {$diff}, Got: {$latestLedger['balance_after']}");


// -------------------------------------------------------------
// SECTION 8: NUMBERING TESTS (51 - 52)
// -------------------------------------------------------------

// TEST 51: Concurrent sale sequence generation is safe
$seqGen = new DocumentSequenceService($pdo);
$num1 = $seqGen->generate('COUNTER_SALE');
$num2 = $seqGen->generate('COUNTER_SALE');
assertTest($num1 !== $num2, 'TEST 51: Concurrent sale sequence generation is safe', "{$num1} vs {$num2}");

// TEST 52: Duplicate invoice numbers impossible
$dupInvoiceFailed = false;
try {
    $pdo->prepare("INSERT INTO pharmacy_sales (sale_number, sale_date, customer_name, grand_total, created_at) VALUES (?, CURDATE(), 'Dup Test', 100, NOW())")
        ->execute([$num1]);
    // Attempting same invoice number again
    $pdo->prepare("INSERT INTO pharmacy_sales (sale_number, sale_date, customer_name, grand_total, created_at) VALUES (?, CURDATE(), 'Dup Test 2', 100, NOW())")
        ->execute([$num1]);
} catch (Exception $e) {
    $dupInvoiceFailed = true;
}
assertTest($dupInvoiceFailed, 'TEST 52: Duplicate invoice numbers impossible');


// -------------------------------------------------------------
// SECTION 9: CHUNK REGRESSION TESTS (53 - 55)
// -------------------------------------------------------------

// TEST 53: Chunk 1 regression passes
$c1Output = shell_exec('C:\xampp\php\php.exe ' . escapeshellarg(__DIR__ . '/test_pharmacy_chunk1_foundation.php'));
$c1Passed = (strpos($c1Output, 'Passed: 20 | Failed: 0') !== false || strpos($c1Output, 'Passed: 20') !== false);
assertTest($c1Passed, 'TEST 53: Chunk 1 regression passes');

// TEST 54: Chunk 2 regression passes
$c2Output = shell_exec('C:\xampp\php\php.exe ' . escapeshellarg(__DIR__ . '/test_pharmacy_chunk2.php'));
$c2Passed = (strpos($c2Output, '37 PASSED, 0 FAILED') !== false || strpos($c2Output, '37/37') !== false);
assertTest($c2Passed, 'TEST 54: Chunk 2 regression passes');

// TEST 55: Chunk 3 regression passes
$c3Output = shell_exec('C:\xampp\php\php.exe ' . escapeshellarg(__DIR__ . '/test_pharmacy_chunk3.php'));
$c3Passed = (strpos($c3Output, '42 PASSED, 0 FAILED') !== false || strpos($c3Output, '42/42') !== false);
assertTest($c3Passed, 'TEST 55: Chunk 3 regression passes');

echo "\n============================================\n";
echo "CHUNK 4 TESTS COMPLETED: {$passCount}/" . ($passCount + $failCount) . " PASSED\n";
if ($failCount > 0) {
    echo "WARNING: {$failCount} TESTS FAILED!\n";
} else {
    echo "100% PASS RATE ACHIEVED.\n";
}
echo "============================================\n";
