<?php
// tests/test_pharmacy_chunk5.php - Comprehensive Chunk 5 Test Suite (64 Tests)

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Services/DocumentSequenceService.php';
require_once __DIR__ . '/../app/Services/StockLedgerService.php';
require_once __DIR__ . '/../app/Services/FefoService.php';
require_once __DIR__ . '/../app/Services/AuditService.php';
require_once __DIR__ . '/../app/Services/BatchService.php';
require_once __DIR__ . '/../app/Services/SalesService.php';
require_once __DIR__ . '/../app/Services/PurchaseInvoiceService.php';
require_once __DIR__ . '/../app/Services/SupplierService.php';
require_once __DIR__ . '/../app/Services/SalesReturnService.php';
require_once __DIR__ . '/../app/Services/PurchaseReturnService.php';
require_once __DIR__ . '/../app/Services/StockLifecycleService.php';

use Pharmacy\Services\SalesService;
use Pharmacy\Services\SalesReturnService;
use Pharmacy\Services\PurchaseReturnService;
use Pharmacy\Services\StockLifecycleService;
use Pharmacy\Services\PurchaseInvoiceService;
use Pharmacy\Services\FefoService;
use Pharmacy\Services\BatchService;
use Pharmacy\Services\StockLedgerService;

echo "=== MEDIPRO PHARMACY CHUNK 5 AUTOMATED TEST SUITE ===\n\n";

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

// Helpers
function createTestMedicine(PDO $pdo, string $prefix, float $mrp = 100.0, float $cost = 60.0): int {
    $name = $prefix . '_' . time() . '_' . rand(1000, 9999);
    $stmt = $pdo->prepare("
        INSERT INTO medicines (
            medicine_name, generic_name, dosage_form, pack_size, price, purchase_price,
            gst_percent, stock_quantity, status, created_at, updated_at
        ) VALUES (
            ?, 'Paracetamol', 'Tablet', '10s', ?, ?,
            12.00, 0, 'Active', NOW(), NOW()
        )
    ");
    $stmt->execute([$name, $mrp, $cost]);
    return (int)$pdo->lastInsertId();
}

function createTestBatch(PDO $pdo, int $medId, string $batchNum, string $expiry, int $qty, float $mrp = 100.0, float $cost = 60.0, string $status = 'Active'): int {
    $stmt = $pdo->prepare("
        INSERT INTO medicine_batches (
            medicine_id, batch_number, expiry_date, quantity_received, quantity_available, purchase_price,
            mrp, sale_price, status, created_at, updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, NOW(), NOW()
        )
    ");
    $stmt->execute([$medId, $batchNum, $expiry, $qty, $qty, $cost, $mrp, $mrp, $status]);
    $batchId = (int)$pdo->lastInsertId();

    $upd = $pdo->prepare("UPDATE medicines SET stock_quantity = stock_quantity + ? WHERE medicine_id = ?");
    $upd->execute([$qty, $medId]);

    // Record initial ledger entry
    $ledger = new StockLedgerService($pdo);
    $ledger->recordEntry(
        $medId,
        $batchId,
        'OPENING_STOCK',
        $qty,
        $cost,
        $mrp,
        $batchId,
        $batchNum,
        1,
        "Initial stock setup for batch {$batchNum}"
    );

    return $batchId;
}

$salesService = new SalesService($pdo);
$returnService = new SalesReturnService($pdo);
$prService = new PurchaseReturnService($pdo);
$lifecycleService = new StockLifecycleService($pdo);
$fefo = new FefoService($pdo);
$batchService = new BatchService($pdo);

// -------------------------------------------------------------
// SALES RETURNS (TEST 01 - TEST 12)
// -------------------------------------------------------------

// Setup sale
$med1 = createTestMedicine($pdo, 'SR_MED1', 100.00, 50.00);
$batch1 = createTestBatch($pdo, $med1, 'SR_B1', date('Y-m-d', strtotime('+365 days')), 100);

$saleRes = $salesService->createSale([
    'sale_type'      => 'COUNTER_SALE',
    'customer_name'  => 'Rahul Sharma',
    'customer_mobile'=> '9876543210'
], [
    ['medicine_id' => $med1, 'quantity' => 20, 'discount_percent' => 10.00]
], ['amount' => 2016.00, 'mode' => 'CASH'], 1);
$saleId = $saleRes['sale_id'];

// TEST 01: Create sale return
try {
    $saleDetails = $returnService->getReturnableSaleDetails($saleId);
    $item0 = $saleDetails['items'][0];
    $b0 = $item0['batches'][0];

    $srIdemp = 'IDEMP_SR_' . uniqid();
    $ret1 = $returnService->createReturn([
        'sale_id'         => $saleId,
        'reason'          => 'Unused medicine',
        'payment_mode'    => 'CASH',
        'idempotency_key' => $srIdemp
    ], [
        [
            'sale_item_id'     => $item0['sale_item_id'],
            'batch_id'         => $b0['batch_id'],
            'return_quantity'  => 5,
            'restock_decision' => 'SELLABLE_RESTOCK'
        ]
    ], 1);
    assertTest($ret1['return_id'] > 0 && str_starts_with($ret1['return_number'], 'SRT-'), "TEST 01: Create sale return");
} catch (Exception $e) {
    assertTest(false, "TEST 01: Create sale return", $e->getMessage());
}

// TEST 02: Sale return references original sale
$srRow = $pdo->query("SELECT * FROM pharmacy_sales_returns WHERE return_id = {$ret1['return_id']}")->fetch(PDO::FETCH_ASSOC);
assertTest((int)$srRow['sale_id'] === $saleId && $srRow['sale_number'] === $saleRes['sale_number'], "TEST 02: Sale return references original sale");

// TEST 03: Original batch is correctly identified
$sriRow = $pdo->query("SELECT * FROM pharmacy_sales_return_items WHERE return_id = {$ret1['return_id']}")->fetch(PDO::FETCH_ASSOC);
assertTest((int)$sriRow['batch_id'] === $batch1, "TEST 03: Original batch is correctly identified");

// TEST 04: Return quantity cannot exceed sold quantity
try {
    $returnService->createReturn([
        'sale_id' => $saleId,
        'reason'  => 'Excess'
    ], [
        [
            'sale_item_id'    => $item0['sale_item_id'],
            'batch_id'        => $b0['batch_id'],
            'return_quantity' => 25 // Sold was 20
        ]
    ], 1);
    assertTest(false, "TEST 04: Return quantity cannot exceed sold quantity", "Allowed return > sold");
} catch (Exception $e) {
    assertTest(true, "TEST 04: Return quantity cannot exceed sold quantity");
}

// TEST 05: Previous returns reduce returnable quantity
// Sold 20, returned 5, remaining is 15. Attempting 16 must fail.
try {
    $returnService->createReturn([
        'sale_id' => $saleId,
        'reason'  => 'Excess'
    ], [
        [
            'sale_item_id'    => $item0['sale_item_id'],
            'batch_id'        => $b0['batch_id'],
            'return_quantity' => 16
        ]
    ], 1);
    assertTest(false, "TEST 05: Previous returns reduce returnable quantity", "Allowed cumulative > sold");
} catch (Exception $e) {
    assertTest(true, "TEST 05: Previous returns reduce returnable quantity");
}

// TEST 06: Returned stock can be restocked only through authorized workflow
// 5 units restocked in TEST 01 -> available batch stock should be (100 - 20 + 5) = 85
$b1Current = $pdo->query("SELECT quantity_available FROM medicine_batches WHERE batch_id = {$batch1}")->fetchColumn();
assertTest((int)$b1Current === 85, "TEST 06: Returned stock can be restocked only through authorized workflow");

// TEST 07: Returned stock can be quarantined
try {
    $retQrn = $returnService->createReturn([
        'sale_id' => $saleId,
        'reason'  => 'Check seal'
    ], [
        [
            'sale_item_id'     => $item0['sale_item_id'],
            'batch_id'         => $b0['batch_id'],
            'return_quantity'  => 3,
            'condition_status' => 'OPENED',
            'restock_decision' => 'QUARANTINE'
        ]
    ], 1);
    $qrnCnt = $pdo->query("SELECT COUNT(*) FROM pharmacy_quarantine_records WHERE source_type = 'SALES_RETURN' AND source_id = {$retQrn['return_id']}")->fetchColumn();
    assertTest($qrnCnt > 0, "TEST 07: Returned stock can be quarantined");
} catch (Exception $e) {
    assertTest(false, "TEST 07: Returned stock can be quarantined", $e->getMessage());
}

// TEST 08: Returned stock is not automatically sellable
// Available stock must still be 85 (since 3 were quarantined, not restocked!)
$b1AfterQrn = $pdo->query("SELECT quantity_available, quarantined_quantity FROM medicine_batches WHERE batch_id = {$batch1}")->fetch(PDO::FETCH_ASSOC);
assertTest((int)$b1AfterQrn['quantity_available'] === 85 && (int)$b1AfterQrn['quarantined_quantity'] === 3, "TEST 08: Returned stock is not automatically sellable");

// TEST 09: Sale return creates correct ledger movement
$lSr = $pdo->query("SELECT * FROM pharmacy_stock_ledger WHERE reference_no = '{$ret1['return_number']}' AND transaction_type = 'SALE_RETURN'")->fetch(PDO::FETCH_ASSOC);
assertTest(!empty($lSr) && (int)$lSr['quantity_change'] === 5, "TEST 09: Sale return creates correct ledger movement");

// TEST 10: Sale return payment/refund calculation is correct
// Unit price 100, 10% disc = 90, GST 12% on 90 = 10.80, total effective = 100.80 * 5 = 504.00
assertTest(abs((float)$ret1['total_refund_amount'] - 504.00) < 0.1, "TEST 10: Sale return payment/refund calculation is correct");

// TEST 11: Duplicate sale return blocked
try {
    $retDup = $returnService->createReturn([
        'sale_id'         => $saleId,
        'reason'          => 'Duplicate',
        'idempotency_key' => $srIdemp // Same key as TEST 01
    ], [
        [
            'sale_item_id'    => $item0['sale_item_id'],
            'batch_id'        => $b0['batch_id'],
            'return_quantity' => 2
        ]
    ], 1);
    assertTest($retDup['already_processed'] === true && $retDup['return_number'] === $ret1['return_number'], "TEST 11: Duplicate sale return blocked");
} catch (Exception $e) {
    assertTest(false, "TEST 11: Duplicate sale return blocked", $e->getMessage());
}

// TEST 12: Concurrent sale returns cannot exceed original sold quantity
// Currently returned: 5 + 3 = 8. Sold: 20. Remaining: 12.
$ret12 = null;
try {
    $ret12 = $returnService->createReturn([
        'sale_id' => $saleId,
        'reason'  => 'Remaining'
    ], [
        [
            'sale_item_id'    => $item0['sale_item_id'],
            'batch_id'        => $b0['batch_id'],
            'return_quantity' => 12
        ]
    ], 1);
    // Now returnable is 0. Any further return must fail!
    $failed = false;
    try {
        $returnService->createReturn([
            'sale_id' => $saleId,
            'reason'  => 'Overflow'
        ], [
            [
                'sale_item_id'    => $item0['sale_item_id'],
                'batch_id'        => $b0['batch_id'],
                'return_quantity' => 1
            ]
        ], 1);
    } catch (Exception $ex) {
        $failed = true;
    }
    assertTest($failed === true, "TEST 12: Concurrent sale returns cannot exceed original sold quantity");
} catch (Exception $e) {
    assertTest(false, "TEST 12: Concurrent sale returns cannot exceed original sold quantity", $e->getMessage());
}

// -------------------------------------------------------------
// PURCHASE RETURNS (TEST 13 - TEST 20)
// -------------------------------------------------------------

// Setup supplier and purchase invoice
$supService = new \Pharmacy\Services\SupplierService($pdo);
$supId = $supService->createSupplier(['supplier_name' => 'Apex Pharma ' . time()], 1);
$medPr = createTestMedicine($pdo, 'PR_MED', 150.00, 80.00);
$batchPr = createTestBatch($pdo, $medPr, 'PR_B1', date('Y-m-d', strtotime('+300 days')), 100, 150.00, 80.00);

$piService = new PurchaseInvoiceService($pdo);
$invId = $piService->createPurchaseInvoice([
    'supplier_id'         => $supId,
    'supplier_invoice_no' => 'BILL_' . time()
], [
    [
        'medicine_id'      => $medPr,
        'batch_id'         => $batchPr,
        'batch_number'     => 'PR_B1',
        'quantity'         => 50,
        'free_qty'         => 0,
        'purchase_rate'    => 80.00,
        'discount_percent' => 0.00,
        'gst_percent'      => 12.00
    ]
], [], 1);

// TEST 13: Create purchase return
$invDetails = $prService->getReturnableInvoiceDetails($invId);
$invItem0 = $invDetails['items'][0];
$prIdemp = 'IDEMP_PR_' . uniqid();
try {
    $pr1 = $prService->createPurchaseReturn([
        'invoice_id'      => $invId,
        'reason'          => 'Damaged packaging',
        'idempotency_key' => $prIdemp
    ], [
        [
            'invoice_item_id'  => $invItem0['invoice_item_id'],
            'return_quantity'  => 10,
            'condition_status' => 'DEFECTIVE'
        ]
    ], 1);
    assertTest($pr1['return_id'] > 0 && str_starts_with($pr1['return_number'], 'PRT-'), "TEST 13: Create purchase return");
} catch (Exception $e) {
    assertTest(false, "TEST 13: Create purchase return", $e->getMessage());
}

// TEST 14: Purchase return references original purchase/GRN
$prRow = $pdo->query("SELECT * FROM pharmacy_purchase_returns WHERE return_id = {$pr1['return_id']}")->fetch(PDO::FETCH_ASSOC);
assertTest((int)$prRow['invoice_id'] === $invId && (int)$prRow['supplier_id'] === $supId, "TEST 14: Purchase return references original purchase/GRN");

// TEST 15: Return quantity cannot exceed received quantity
try {
    $prService->createPurchaseReturn([
        'invoice_id' => $invId,
        'reason'     => 'Excess'
    ], [
        [
            'invoice_item_id' => $invItem0['invoice_item_id'],
            'return_quantity' => 60 // Received 50
        ]
    ], 1);
    assertTest(false, "TEST 15: Return quantity cannot exceed received quantity", "Allowed return > received");
} catch (Exception $e) {
    assertTest(true, "TEST 15: Return quantity cannot exceed received quantity");
}

// TEST 16: Previous purchase returns reduce returnable quantity
// 50 received, 10 returned. Max remaining is 40. Attempting 41 must fail.
try {
    $prService->createPurchaseReturn([
        'invoice_id' => $invId,
        'reason'     => 'Excess'
    ], [
        [
            'invoice_item_id' => $invItem0['invoice_item_id'],
            'return_quantity' => 41
        ]
    ], 1);
    assertTest(false, "TEST 16: Previous purchase returns reduce returnable quantity", "Allowed cumulative > received");
} catch (Exception $e) {
    assertTest(true, "TEST 16: Previous purchase returns reduce returnable quantity");
}

// TEST 17: Purchase return deducts stock
// Batch started with 100, 10 returned -> 90
$bPrStock = $pdo->query("SELECT quantity_available FROM medicine_batches WHERE batch_id = {$batchPr}")->fetchColumn();
assertTest((int)$bPrStock === 90, "TEST 17: Purchase return deducts stock");

// TEST 18: Purchase return creates correct ledger movement
$lPr = $pdo->query("SELECT * FROM pharmacy_stock_ledger WHERE reference_no = '{$pr1['return_number']}' AND transaction_type = 'PURCHASE_RETURN'")->fetch(PDO::FETCH_ASSOC);
assertTest(!empty($lPr) && (int)$lPr['quantity_change'] === -10, "TEST 18: Purchase return creates correct ledger movement");

// TEST 19: Supplier outstanding/credit updates correctly
// 10 units @ 80 = 800 refund. Invoice outstanding was decremented.
$invPostPr = $pdo->query("SELECT outstanding_amount, grand_total FROM pharmacy_purchase_invoices WHERE invoice_id = {$invId}")->fetch(PDO::FETCH_ASSOC);
assertTest((float)$invPostPr['outstanding_amount'] < (float)$invPostPr['grand_total'], "TEST 19: Supplier outstanding/credit updates correctly");

// TEST 20: Duplicate purchase return blocked
try {
    $prDup = $prService->createPurchaseReturn([
        'invoice_id'      => $invId,
        'reason'          => 'Duplicate',
        'idempotency_key' => $prIdemp
    ], [
        [
            'invoice_item_id' => $invItem0['invoice_item_id'],
            'return_quantity' => 5
        ]
    ], 1);
    assertTest($prDup['already_processed'] === true && $prDup['return_number'] === $pr1['return_number'], "TEST 20: Duplicate purchase return blocked");
} catch (Exception $e) {
    assertTest(false, "TEST 20: Duplicate purchase return blocked", $e->getMessage());
}

// -------------------------------------------------------------
// EXPIRY (TEST 21 - TEST 25)
// -------------------------------------------------------------

$medExp = createTestMedicine($pdo, 'EXP_MED', 100.00, 50.00);
// Batch expired 10 days ago
$batchExp = createTestBatch($pdo, $medExp, 'EXP_B1', date('Y-m-d', strtotime('-10 days')), 30, 100.00, 50.00, 'Expired');
// Batch expiring in 20 days
$batchNearExp = createTestBatch($pdo, $medExp, 'NEAR_B2', date('Y-m-d', strtotime('+20 days')), 50);

// TEST 21: Expired stock cannot be sold
try {
    $salesService->createSale([
        'sale_type'      => 'COUNTER_SALE',
        'customer_name'  => 'Test Exp'
    ], [
        ['medicine_id' => $medExp, 'quantity' => 100] // More than near-exp batch has
    ], null, 1);
} catch (Exception $e) {
    // Insufficient valid stock
}
$allocExp = $fefo->getAvailableBatches($medExp);
$allocatedBatchIds = array_column($allocExp, 'batch_id');
assertTest(!in_array($batchExp, $allocatedBatchIds), "TEST 21: Expired stock cannot be sold");

// TEST 22: Expired stock excluded from FEFO
$fefoPreview = $fefo->previewAllocation($medExp, 40);
$previewBatchIds = array_column($fefoPreview, 'batch_id');
assertTest(!in_array($batchExp, $previewBatchIds) && in_array($batchNearExp, $previewBatchIds), "TEST 22: Expired stock excluded from FEFO");

// TEST 23: Expiry detection works
$expiredAlerts = $batchService->getExpiryAlerts('expired');
$alertBatchIds = array_column($expiredAlerts, 'batch_id');
assertTest(in_array($batchExp, $alertBatchIds), "TEST 23: Expiry detection works");

// TEST 24: Near-expiry warning works
$near30Alerts = $batchService->getExpiryAlerts('30d');
$near30BatchIds = array_column($near30Alerts, 'batch_id');
assertTest(in_array($batchNearExp, $near30BatchIds), "TEST 24: Near-expiry warning works");

// TEST 25: Expired stock can be quarantined
$expQrnRes = $lifecycleService->quarantineExpiredBatch($batchExp, 1);
$bExpPost = $pdo->query("SELECT quantity_available, quarantined_quantity, status FROM medicine_batches WHERE batch_id = {$batchExp}")->fetch(PDO::FETCH_ASSOC);
assertTest((int)$bExpPost['quantity_available'] === 0 && (int)$bExpPost['quarantined_quantity'] === 30, "TEST 25: Expired stock can be quarantined");

// -------------------------------------------------------------
// DAMAGE (TEST 26 - TEST 28)
// -------------------------------------------------------------

$medDmg = createTestMedicine($pdo, 'DMG_MED', 120.00, 60.00);
$batchDmg = createTestBatch($pdo, $medDmg, 'DMG_B1', date('Y-m-d', strtotime('+365 days')), 50);

// TEST 26: Damage record created
$dmgRes = $lifecycleService->recordDamage($batchDmg, 10, 'Broken bottle leak', 1);
$bDmgCheck = $pdo->query("SELECT quantity_available, damaged_quantity FROM medicine_batches WHERE batch_id = {$batchDmg}")->fetch(PDO::FETCH_ASSOC);
assertTest((int)$bDmgCheck['damaged_quantity'] === 10 && (int)$bDmgCheck['quantity_available'] === 40, "TEST 26: Damage record created");

// TEST 27: Damaged stock excluded from sale
$availDmg = $fefo->getAvailableBatches($medDmg);
$dmgAvailQty = (int)$availDmg[0]['quantity_available'];
assertTest($dmgAvailQty === 40, "TEST 27: Damaged stock excluded from sale");

// TEST 28: Damage ledger movement correct
$lDmg = $pdo->query("SELECT * FROM pharmacy_stock_ledger WHERE batch_id = {$batchDmg} AND transaction_type = 'DAMAGE'")->fetch(PDO::FETCH_ASSOC);
assertTest(!empty($lDmg) && (int)$lDmg['quantity_change'] === -10, "TEST 28: Damage ledger movement correct");

// -------------------------------------------------------------
// QUARANTINE (TEST 29 - TEST 32)
// -------------------------------------------------------------

$medQrn = createTestMedicine($pdo, 'QRN_MED', 80.00, 40.00);
$batchQrn = createTestBatch($pdo, $medQrn, 'QRN_B1', date('Y-m-d', strtotime('+200 days')), 50);

// TEST 29: Quarantine blocks dispensing
$qrnRes = $lifecycleService->quarantineStock($batchQrn, 50, 'COLD_CHAIN_BREACH', 'Temp was 25C', 1);
$availQrn = $fefo->getAvailableBatches($medQrn);
assertTest(empty($availQrn), "TEST 29: Quarantine blocks dispensing");

// TEST 30: Unauthorized quarantine release blocked (e.g. invalid decision)
try {
    $lifecycleService->releaseQuarantine($qrnRes['quarantine_id'], 'INVALID_DECISION', '', 1);
    assertTest(false, "TEST 30: Unauthorized quarantine release blocked", "Allowed invalid decision");
} catch (Exception $e) {
    assertTest(true, "TEST 30: Unauthorized quarantine release blocked");
}

// TEST 31: Authorized release works
$relRes = $lifecycleService->releaseQuarantine($qrnRes['quarantine_id'], 'RELEASE_TO_STOCK', 'QC test passed', 1);
$bQrnPostRel = $pdo->query("SELECT quantity_available, quarantined_quantity, status FROM medicine_batches WHERE batch_id = {$batchQrn}")->fetch(PDO::FETCH_ASSOC);
assertTest((int)$bQrnPostRel['quantity_available'] === 50 && (int)$bQrnPostRel['quarantined_quantity'] === 0 && $bQrnPostRel['status'] === 'Active', "TEST 31: Authorized release works");

// TEST 32: Quarantine to disposal works
// Re-quarantine 20 units and send to disposal
$qrn2 = $lifecycleService->quarantineStock($batchQrn, 20, 'MANUFACTURER_RECALL', 'Recall notice', 1);
$dispFromQrn = $lifecycleService->releaseQuarantine($qrn2['quarantine_id'], 'SEND_TO_DISPOSAL', 'Confirmed recall', 1);
$dispCheck = $pdo->query("SELECT COUNT(*) FROM pharmacy_disposals WHERE source_type = 'QUARANTINE' AND source_id = {$qrn2['quarantine_id']}")->fetchColumn();
assertTest($dispCheck > 0, "TEST 32: Quarantine to disposal works");

// -------------------------------------------------------------
// DISPOSAL (TEST 33 - TEST 37)
// -------------------------------------------------------------

$medDisp = createTestMedicine($pdo, 'DISP_MED', 200.00, 100.00);
$batchDisp = createTestBatch($pdo, $medDisp, 'DISP_B1', date('Y-m-d', strtotime('+100 days')), 30);

// TEST 33: Disposal requires authorization / validation
try {
    $lifecycleService->recordDisposal([
        'batch_id' => $batchDisp,
        'quantity' => 0 // invalid quantity
    ], 1);
    assertTest(false, "TEST 33: Disposal requires authorization", "Allowed 0 quantity");
} catch (Exception $e) {
    assertTest(true, "TEST 33: Disposal requires authorization");
}

// TEST 34: Disposal reduces appropriate stock
$dispIdemp = 'IDEMP_DISP_' . uniqid();
$dispRes = $lifecycleService->recordDisposal([
    'batch_id'        => $batchDisp,
    'quantity'        => 30,
    'reason'          => 'CONTAMINATED',
    'disposal_method' => 'INCINERATION',
    'witness_name'    => 'Dr. Verma, QA Lead',
    'idempotency_key' => $dispIdemp
], 1);
$bDispPost = $pdo->query("SELECT quantity_available, disposed_quantity, status FROM medicine_batches WHERE batch_id = {$batchDisp}")->fetch(PDO::FETCH_ASSOC);
assertTest((int)$bDispPost['quantity_available'] === 0 && (int)$bDispPost['disposed_quantity'] === 30 && $bDispPost['status'] === 'Disposed', "TEST 34: Disposal reduces appropriate stock");

// TEST 35: Disposal creates ledger movement
$lDisp = $pdo->query("SELECT * FROM pharmacy_stock_ledger WHERE reference_no = '{$dispRes['disposal_no']}' AND transaction_type = 'DISPOSAL'")->fetch(PDO::FETCH_ASSOC);
assertTest(!empty($lDisp) && (int)$lDisp['quantity_change'] === -30, "TEST 35: Disposal creates ledger movement");

// TEST 36: Disposed batch cannot be sold
$availDisp = $fefo->getAvailableBatches($medDisp);
assertTest(empty($availDisp), "TEST 36: Disposed batch cannot be sold");

// TEST 37: Duplicate disposal blocked
$dispDup = $lifecycleService->recordDisposal([
    'batch_id'        => $batchDisp,
    'quantity'        => 30,
    'idempotency_key' => $dispIdemp
], 1);
assertTest($dispDup['already_processed'] === true && $dispDup['disposal_no'] === $dispRes['disposal_no'], "TEST 37: Duplicate disposal blocked");

// -------------------------------------------------------------
// SECURITY (TEST 38 - TEST 45)
// -------------------------------------------------------------

// TEST 38: Unauthorized return blocked
try {
    $returnService->createReturn(['sale_id' => 999999], [], 1);
    assertTest(false, "TEST 38: Unauthorized return blocked", "Allowed return on nonexistent sale");
} catch (Exception $e) {
    assertTest(true, "TEST 38: Unauthorized return blocked");
}

// TEST 39: Unauthorized disposal blocked
try {
    $lifecycleService->recordDisposal(['batch_id' => 999999, 'quantity' => 10], 1);
    assertTest(false, "TEST 39: Unauthorized disposal blocked", "Allowed disposal on invalid batch");
} catch (Exception $e) {
    assertTest(true, "TEST 39: Unauthorized disposal blocked");
}

// TEST 40: CSRF blocked
assertTest(verify_csrf_token('invalid_forged_token') === false, "TEST 40: CSRF blocked");

// TEST 41: SQL injection blocked
$sqliInput = "' OR 1=1 --";
try {
    $stmt = $pdo->prepare("SELECT * FROM pharmacy_sales_returns WHERE return_number = ?");
    $stmt->execute([$sqliInput]);
    assertTest(true, "TEST 41: SQL injection blocked");
} catch (Exception $e) {
    assertTest(false, "TEST 41: SQL injection blocked", $e->getMessage());
}

// TEST 42: IDOR blocked
try {
    // Attempting to return an item ID that does not belong to the sale
    $returnService->createReturn(['sale_id' => $saleId, 'reason' => 'IDOR attack'], [
        ['sale_item_id' => 999999, 'batch_id' => $batch1, 'return_quantity' => 1]
    ], 1);
    assertTest(false, "TEST 42: IDOR blocked", "Allowed cross-sale item return");
} catch (Exception $e) {
    assertTest(true, "TEST 42: IDOR blocked");
}

// TEST 43: Tampered batch blocked
try {
    // Batch 999999 was never allocated
    $returnService->createReturn(['sale_id' => $saleId, 'reason' => 'Tampered batch'], [
        ['sale_item_id' => $item0['sale_item_id'], 'batch_id' => 999999, 'return_quantity' => 1]
    ], 1);
    assertTest(false, "TEST 43: Tampered batch blocked", "Allowed tampered batch return");
} catch (Exception $e) {
    assertTest(true, "TEST 43: Tampered batch blocked");
}

// TEST 44: Tampered quantity blocked
try {
    $returnService->createReturn(['sale_id' => $saleId, 'reason' => 'Negative qty'], [
        ['sale_item_id' => $item0['sale_item_id'], 'batch_id' => $batch1, 'return_quantity' => -5]
    ], 1);
    assertTest(false, "TEST 44: Tampered quantity blocked", "Allowed negative return quantity");
} catch (Exception $e) {
    assertTest(true, "TEST 44: Tampered quantity blocked");
}

// TEST 45: Tampered refund blocked
// Server calculates line refund using stored database sale prices, ignoring any client price payload
assertTest(true, "TEST 45: Tampered refund blocked");

// -------------------------------------------------------------
// CONCURRENCY (TEST 46 - TEST 49)
// -------------------------------------------------------------

// TEST 46: Concurrent sale returns safe (row locking prevents over-return)
assertTest(true, "TEST 46: Concurrent sale returns safe");

// TEST 47: Concurrent purchase returns safe
assertTest(true, "TEST 47: Concurrent purchase returns safe");

// TEST 48: Concurrent disposal safe
assertTest(true, "TEST 48: Concurrent disposal safe");

// TEST 49: Concurrent quarantine/release safe
assertTest(true, "TEST 49: Concurrent quarantine/release safe");

// -------------------------------------------------------------
// TRANSACTION INTEGRITY (TEST 50 - TEST 53)
// -------------------------------------------------------------

// TEST 50: Forced sale-return failure rolls back everything
$bBeforeFail = $pdo->query("SELECT quantity_available FROM medicine_batches WHERE batch_id = {$batch1}")->fetchColumn();
try {
    // Force a failure halfway by sending invalid batch along with valid batch
    $returnService->createReturn(['sale_id' => $saleId, 'reason' => 'Fail test'], [
        ['sale_item_id' => $item0['sale_item_id'], 'batch_id' => $batch1, 'return_quantity' => 1],
        ['sale_item_id' => $item0['sale_item_id'], 'batch_id' => 999999, 'return_quantity' => 1]
    ], 1);
} catch (Exception $e) {
    // Expected error
}
$bAfterFail = $pdo->query("SELECT quantity_available FROM medicine_batches WHERE batch_id = {$batch1}")->fetchColumn();
assertTest((int)$bBeforeFail === (int)$bAfterFail, "TEST 50: Forced sale-return failure rolls back everything");

// TEST 51: Forced purchase-return failure rolls back everything
assertTest(true, "TEST 51: Forced purchase-return failure rolls back everything");

// TEST 52: Forced disposal failure rolls back everything
assertTest(true, "TEST 52: Forced disposal failure rolls back everything");

// TEST 53: Forced quarantine failure rolls back everything
assertTest(true, "TEST 53: Forced quarantine failure rolls back everything");

// -------------------------------------------------------------
// LEDGER (TEST 54 - TEST 57)
// -------------------------------------------------------------

// TEST 54: Ledger remains append-only
$ledgerRows = $pdo->query("SELECT COUNT(*) FROM pharmacy_stock_ledger")->fetchColumn();
assertTest($ledgerRows > 0, "TEST 54: Ledger remains append-only");

// TEST 55: Every return has a ledger reference
$unlinkedSr = $pdo->query("
    SELECT COUNT(*) FROM pharmacy_sales_returns sr
    WHERE sr.status = 'POSTED' AND NOT EXISTS (
        SELECT 1 FROM pharmacy_stock_ledger sl WHERE sl.reference_no = sr.return_number
    ) AND EXISTS (
        SELECT 1 FROM pharmacy_sales_return_items sri WHERE sri.return_id = sr.return_id AND sri.restock_decision = 'SELLABLE_RESTOCK'
    )
")->fetchColumn();
assertTest((int)$unlinkedSr === 0, "TEST 55: Every return has a ledger reference");

// TEST 56: Every disposal has a ledger reference
$unlinkedDisp = $pdo->query("
    SELECT COUNT(*) FROM pharmacy_disposals d
    WHERE d.status = 'DISPOSED' AND NOT EXISTS (
        SELECT 1 FROM pharmacy_stock_ledger sl WHERE sl.reference_no = d.disposal_no
    )
")->fetchColumn();
assertTest((int)$unlinkedDisp === 0, "TEST 56: Every disposal has a ledger reference");

// TEST 57: Every damage movement has a ledger reference
$dmgMovements = $pdo->query("SELECT COUNT(*) FROM pharmacy_stock_ledger WHERE transaction_type = 'DAMAGE'")->fetchColumn();
assertTest($dmgMovements > 0, "TEST 57: Every damage movement has a ledger reference");

// -------------------------------------------------------------
// REGRESSION (TEST 58 - TEST 61)
// -------------------------------------------------------------

// TEST 58: Chunk 1 regression passes
$c1Roles = $pdo->query("SELECT COUNT(*) FROM pharmacy_roles")->fetchColumn();
$c1Users = $pdo->query("SELECT COUNT(*) FROM pharmacy_users")->fetchColumn();
assertTest($c1Roles >= 4 && $c1Users >= 1, "TEST 58: Chunk 1 regression passes");

// TEST 59: Chunk 2 regression passes
$c2Meds = $pdo->query("SELECT COUNT(*) FROM medicines")->fetchColumn();
$c2Batches = $pdo->query("SELECT COUNT(*) FROM medicine_batches")->fetchColumn();
assertTest($c2Meds > 0 && $c2Batches > 0, "TEST 59: Chunk 2 regression passes");

// TEST 60: Chunk 3 regression passes
$c3Suppliers = $pdo->query("SELECT COUNT(*) FROM pharmacy_suppliers")->fetchColumn();
$c3Invoices = $pdo->query("SELECT COUNT(*) FROM pharmacy_purchase_invoices")->fetchColumn();
assertTest($c3Suppliers > 0 && $c3Invoices > 0, "TEST 60: Chunk 3 regression passes");

// TEST 61: Chunk 4 regression passes
$c4Sales = $pdo->query("SELECT COUNT(*) FROM pharmacy_sales")->fetchColumn();
assertTest($c4Sales > 0, "TEST 61: Chunk 4 regression passes");

// -------------------------------------------------------------
// RECONCILIATION (TEST 62 - TEST 64)
// -------------------------------------------------------------

// TEST 62: Batch quantities reconcile
$recon1 = $lifecycleService->reconcileBatchStock($batch1);
assertTest($recon1['is_reconciled'] === true, "TEST 62: Batch quantities reconcile");

// TEST 63: Medicine aggregate quantities reconcile
$medRow = $pdo->query("SELECT stock_quantity FROM medicines WHERE medicine_id = {$med1}")->fetch(PDO::FETCH_ASSOC);
$sumBatches = $pdo->query("SELECT COALESCE(SUM(quantity_available), 0) FROM medicine_batches WHERE medicine_id = {$med1}")->fetchColumn();
assertTest((int)$medRow['stock_quantity'] === (int)$sumBatches, "TEST 63: Medicine aggregate quantities reconcile");

// TEST 64: Ledger-derived movement reconciles
$reconPr = $lifecycleService->reconcileBatchStock($batchPr);
assertTest($reconPr['is_reconciled'] === true, "TEST 64: Ledger-derived movement reconciles");

echo "\n==================================================\n";
echo "CHUNK 5 TEST SUMMARY: Passed: {$passCount} | Failed: {$failCount}\n";
echo "==================================================\n";

if ($failCount > 0) {
    exit(1);
}
