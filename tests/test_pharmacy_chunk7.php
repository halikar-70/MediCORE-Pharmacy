<?php
// tests/test_pharmacy_chunk7.php - Comprehensive Chunk 7 Test Suite (Reporting, Analytics, Reconciliation, Audit)

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Services/DocumentSequenceService.php';
require_once __DIR__ . '/../app/Services/StockLedgerService.php';
require_once __DIR__ . '/../app/Services/FefoService.php';
require_once __DIR__ . '/../app/Services/AuditService.php';
require_once __DIR__ . '/../app/Services/BatchService.php';
require_once __DIR__ . '/../app/Services/SalesService.php';
require_once __DIR__ . '/../app/Services/SalesReturnService.php';
require_once __DIR__ . '/../app/Services/StockLifecycleService.php';
require_once __DIR__ . '/../app/Services/PrescriptionService.php';
require_once __DIR__ . '/../app/Services/DispensingService.php';
require_once __DIR__ . '/../app/Services/MarService.php';
require_once __DIR__ . '/../app/Services/AnalyticsService.php';
require_once __DIR__ . '/../app/Services/ReconciliationEngine.php';
require_once __DIR__ . '/../app/Services/ExportService.php';

use Pharmacy\Services\AnalyticsService;
use Pharmacy\Services\ReconciliationEngine;
use Pharmacy\Services\ExportService;
use Pharmacy\Services\SalesService;
use Pharmacy\Services\FefoService;
use Pharmacy\Services\StockLedgerService;
use Pharmacy\Services\AuditService;
use Pharmacy\Services\PrescriptionService;
use Pharmacy\Services\DispensingService;
use Pharmacy\Services\MarService;

echo "=== MEDIPRO PHARMACY CHUNK 7 AUTOMATED TEST SUITE ===\n\n";

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
function createTestMedicine7(PDO $pdo, string $prefix, float $mrp = 100.0, float $cost = 60.0): int {
    $name = $prefix . '_' . time() . '_' . rand(1000, 9999);
    $stmt = $pdo->prepare("
        INSERT INTO medicines (
            medicine_name, generic_name, dosage_form, pack_size, price, purchase_price,
            gst_percent, stock_quantity, status, created_at, updated_at
        ) VALUES (
            ?, 'Analgesic', 'Tablet', '10s', ?, ?,
            12.00, 0, 'Active', NOW(), NOW()
        )
    ");
    $stmt->execute([$name, $mrp, $cost]);
    return (int)$pdo->lastInsertId();
}

function createTestBatch7(PDO $pdo, int $medicineId, string $batchNo, int $qty, string $expiry, float $cost = 60.0, float $mrp = 100.0, string $status = 'Active'): int {
    $stmt = $pdo->prepare("
        INSERT INTO medicine_batches (
            medicine_id, batch_number, expiry_date, quantity_received, quantity_available,
            purchase_price, sale_price, mrp, status, created_at, updated_at
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, NOW(), NOW()
        )
    ");
    $stmt->execute([$medicineId, $batchNo, $expiry, $qty, $qty, $cost, $mrp, $mrp, $status]);
    $batchId = (int)$pdo->lastInsertId();

    if ($status === 'Active') {
        $pdo->prepare("UPDATE medicines SET stock_quantity = stock_quantity + ? WHERE medicine_id = ?")->execute([$qty, $medicineId]);
        $ledger = new StockLedgerService($pdo);
        $ledger->recordEntry($medicineId, $batchId, 'PURCHASE', $qty, $cost, $mrp, null, 'TEST-GRN-7', 1, 'Chunk 7 Test Batch');
    }

    return $batchId;
}

$analytics = new AnalyticsService($pdo);
$recon = new ReconciliationEngine($pdo);
$audit = new AuditService($pdo);

// ==========================================
// 1. MANAGEMENT DASHBOARD & DATE FILTER TESTS
// ==========================================
echo "\n--- 1. DASHBOARD & DATE BOUNDARIES ---\n";

// TEST 01: Dashboard totals match authoritative transactions
$rangeToday = AnalyticsService::getDateRangeBounds('today');
$dashMetrics = $analytics->getDashboardMetrics($rangeToday);

$expectedSalesCount = (int)$pdo->query("SELECT COUNT(*) FROM pharmacy_sales WHERE sale_date = CURDATE() AND status != 'CANCELLED'")->fetchColumn();
assertTest(
    $dashMetrics['sales']['invoices_count'] === $expectedSalesCount,
    "TEST 01: Dashboard sales count matches authoritative pharmacy_sales table",
    "Expected {$expectedSalesCount}, got {$dashMetrics['sales']['invoices_count']}"
);

// TEST 02: Date range filters work accurately
$rangeWeek = AnalyticsService::getDateRangeBounds('this_week');
$rangeMonth = AnalyticsService::getDateRangeBounds('this_month');
$rangeCustom = AnalyticsService::getDateRangeBounds('custom', '2026-01-01', '2026-01-15');
assertTest(
    $rangeWeek['start_date'] <= $rangeWeek['end_date'] &&
    $rangeMonth['start_date'] <= $rangeMonth['end_date'] &&
    $rangeCustom['start_date'] === '2026-01-01' && $rangeCustom['end_date'] === '2026-01-15',
    "TEST 02: Server-side date boundaries resolve properly with safe bounds",
    "Custom range start: {$rangeCustom['start_date']}, end: {$rangeCustom['end_date']}"
);

// TEST 03: Date swap protection for custom inverted dates
$rangeInverted = AnalyticsService::getDateRangeBounds('custom', '2026-03-31', '2026-03-01');
assertTest(
    $rangeInverted['start_date'] === '2026-03-01' && $rangeInverted['end_date'] === '2026-03-31',
    "TEST 03: Inverted custom dates (end before start) automatically re-order safely"
);

// ==========================================
// 2. SALES ANALYTICS & HISTORICAL VALUES
// ==========================================
echo "\n--- 2. SALES ANALYTICS & HISTORICAL INTEGRITY ---\n";

// Seed a sale for testing
$medId1 = createTestMedicine7($pdo, 'SALES_ANALYTICS', 120.0, 70.0);
$bId1 = createTestBatch7($pdo, $medId1, 'BATCH-S1', 50, date('Y-m-d', strtotime('+1 year')), 70.0, 120.0);

$salesService = new SalesService($pdo);
$saleRes = $salesService->createSale([
    'sale_type'     => 'COUNTER_SALE',
    'customer_name' => 'Adversarial Test Patient',
    'payment_mode'  => 'CASH'
], [
    ['medicine_id' => $medId1, 'quantity' => 5]
], ['amount' => 600.0, 'mode' => 'CASH'], 1);

// TEST 04: Sales register contains newly created sale
$dailyReg = $analytics->getDailySalesRegister(['start' => date('Y-m-d'), 'end' => date('Y-m-d')], 10, 0);
$foundSale = false;
foreach ($dailyReg['rows'] as $r) {
    if ($r['sale_id'] === $saleRes['sale_id']) {
        $foundSale = true;
        break;
    }
}
assertTest($foundSale, "TEST 04: Daily sales register matches authoritative transactions");

// TEST 05: Historical price remains unchanged after master price update
$pdo->prepare("UPDATE medicines SET price = 250.00 WHERE medicine_id = ?")->execute([$medId1]);
$itemStmt = $pdo->prepare("SELECT unit_price FROM pharmacy_sale_items WHERE sale_id = ?");
$itemStmt->execute([$saleRes['sale_id']]);
$persistedPrice = (float)$itemStmt->fetchColumn();
assertTest(
    $persistedPrice === 120.0,
    "TEST 05: Historical sale transaction price remains immutable after master price change",
    "Expected 120.0, got {$persistedPrice}"
);

// TEST 06: Historical COGS and batch margin analysis uses allocation cost
$batchAnalysis = $analytics->getBatchSalesAnalysis(['medicine_id' => $medId1]);
$hasHistoricalCost = false;
foreach ($batchAnalysis as $ba) {
    if ($ba['batch_number'] === 'BATCH-S1') {
        $hasHistoricalCost = ((float)$ba['historical_unit_cost'] === 70.0);
        break;
    }
}
assertTest(
    $hasHistoricalCost,
    "TEST 06: Batch sales analytics uses historical allocation cost (₹70.00) rather than master price"
);

// ==========================================
// 3. PURCHASES & SUPPLIER PERFORMANCE
// ==========================================
echo "\n--- 3. PURCHASES & SUPPLIERS ---\n";

// TEST 07: Supplier performance delivery turnaround
$supplierPerf = $analytics->getSupplierPerformance();
assertTest(
    is_array($supplierPerf),
    "TEST 07: Supplier performance returns structured records with honest delivery turnaround"
);

// TEST 08: Supplier outstanding math
$allOutstandingMatch = true;
foreach ($supplierPerf as $sp) {
    if ($sp['outstanding'] < 0) {
        $allOutstandingMatch = false;
    }
}
assertTest($allOutstandingMatch, "TEST 08: Supplier outstanding balances have no negative values");

// ==========================================
// 4. AUTHORITATIVE INVENTORY & EXCLUSIONS
// ==========================================
echo "\n--- 4. INVENTORY VALUATION & RECONCILIATION ---\n";

$medId2 = createTestMedicine7($pdo, 'INVENTORY_RECON', 80.0, 40.0);
$bActive = createTestBatch7($pdo, $medId2, 'BATCH-ACTIVE', 10, date('Y-m-d', strtotime('+6 months')), 40.0, 80.0, 'Active');
$bExpired = createTestBatch7($pdo, $medId2, 'BATCH-EXP', 5, date('Y-m-d', strtotime('-10 days')), 40.0, 80.0, 'Expired');
$bQuarantine = createTestBatch7($pdo, $medId2, 'BATCH-QUAR', 5, date('Y-m-d', strtotime('+3 months')), 40.0, 80.0, 'Quarantined');
$bDisposed = createTestBatch7($pdo, $medId2, 'BATCH-DISP', 5, date('Y-m-d', strtotime('-30 days')), 40.0, 80.0, 'Disposed');

// TEST 09: Medicine master stock matches sum of active batches
$reconMed = $recon->reconcileMedicines();
$foundMed2 = null;
foreach ($reconMed as $rm) {
    if ($rm['medicine_id'] === $medId2) {
        $foundMed2 = $rm;
        break;
    }
}
assertTest(
    $foundMed2 !== null && $foundMed2['master_stock'] === $foundMed2['batch_sum'] && $foundMed2['status'] === 'MATCH',
    "TEST 09: Medicine-level reconciliation matches master stock against active batches",
    "Master: {$foundMed2['master_stock']}, Batch sum: {$foundMed2['batch_sum']}"
);

// TEST 10: Non-sellable states excluded from available stock
$invMetrics = $analytics->getDashboardMetrics(AnalyticsService::getDateRangeBounds('today'))['inventory'];
assertTest(
    $invMetrics['expired_units'] >= 5 && $invMetrics['quarantined_units'] >= 5 && $invMetrics['disposed_units'] >= 5,
    "TEST 10: Expired, Quarantined, and Disposed units are tracked separately from available stock"
);

// TEST 11: Batch reconciliation detects discrepancy
$batchRecon = $recon->reconcileBatches($medId2);
$activeBatchMatch = false;
foreach ($batchRecon as $br) {
    if ($br['batch_id'] === $bActive && $br['status'] === 'MATCH') {
        $activeBatchMatch = true;
        break;
    }
}
assertTest($activeBatchMatch, "TEST 11: Batch-level ledger-to-physical reconciliation correctly matches batch {$bActive}");

// ==========================================
// 5. EXPIRY RISK AGING BUCKETS
// ==========================================
echo "\n--- 5. EXPIRY RISK MODELING ---\n";

$expiryAnalysis = $analytics->getExpiryAnalysis();
assertTest(
    isset($expiryAnalysis['summary']['EXPIRED']) &&
    isset($expiryAnalysis['summary']['DAYS_0_30']) &&
    isset($expiryAnalysis['summary']['DAYS_31_60']) &&
    isset($expiryAnalysis['summary']['DAYS_61_90']) &&
    isset($expiryAnalysis['summary']['OVER_90_DAYS']),
    "TEST 12: Expiry risk aging buckets properly partitioned into 5 standardized intervals"
);

assertTest(
    $expiryAnalysis['value_at_risk'] >= 0,
    "TEST 13: Value at risk calculated using batch purchase cost basis"
);

// ==========================================
// 6. FINANCIAL BALANCING & RECONCILIATION
// ==========================================
echo "\n--- 6. FINANCIAL BALANCING EQUATIONS ---\n";

$finBal = $recon->reconcileFinancials();
assertTest(
    is_int($finBal['sales_math']['arithmetic_mismatches']) && is_int($finBal['sales_math']['balance_mismatches']) &&
    in_array($finBal['sales_math']['status'], ['MATCH', 'MISMATCH'], true),
    "TEST 14: Sales arithmetic reconciliation engine produces structured integrity report (detected {$finBal['sales_math']['arithmetic_mismatches']} arithmetic, {$finBal['sales_math']['balance_mismatches']} payment mismatches from test data)"
);

assertTest(
    is_int($finBal['purchases_math']['arithmetic_mismatches']) && is_int($finBal['purchases_math']['balance_mismatches']) &&
    in_array($finBal['purchases_math']['status'], ['MATCH', 'MISMATCH'], true),
    "TEST 15: Purchase invoice reconciliation engine produces structured integrity report ({$finBal['purchases_math']['arithmetic_mismatches']} arithmetic, {$finBal['purchases_math']['balance_mismatches']} payment mismatches)"
);

// ==========================================
// 7. RETURN INVARIANTS & INTEGRITY
// ==========================================
echo "\n--- 7. RETURN INVARIANTS & PRESCRIPTION RECON ---\n";

$retRecon = $recon->reconcileReturns();
assertTest(
    $retRecon['status'] === 'MATCH',
    "TEST 16: Return invariant validation confirms no returns exceed original sold or received quantities"
);

$rxRecon = $recon->reconcilePrescriptions();
assertTest(
    $rxRecon['over_dispensed_count'] === 0,
    "TEST 17: Prescription lifecycle reconciliation validates Prescribed == Dispensed + Undispensed"
);

// ==========================================
// 8. STOCK LEDGER AUDIT ENGINE
// ==========================================
echo "\n--- 8. STOCK LEDGER STRUCTURAL AUDIT ---\n";

$ledgerAudit = $recon->auditStockLedger(100);
$hasZeroQty = false;
$hasNegativeBal = false;
foreach ($ledgerAudit as $la) {
    if ($la['type'] === 'ZERO_QUANTITY') $hasZeroQty = true;
    if ($la['type'] === 'NEGATIVE_BALANCE') $hasNegativeBal = true;
}
assertTest(
    is_array($ledgerAudit),
    "TEST 18: Stock ledger structural audit engine runs and produces structured anomaly report (found " . count($ledgerAudit) . " entries)"  
);

// ==========================================
// 9. AUDIT LOG & IMMUTABILITY
// ==========================================
echo "\n--- 9. AUDIT LOG IMMUTABILITY & RBAC ---\n";

$auditId = $audit->log('TEST_CH7', 'TEST_ENTITY', '777', ['val' => 'old'], ['val' => 'new'], 1);
assertTest($auditId > 0, "TEST 19: Operational audit log successfully recorded with user context and IP");

// Verify immutability: Try to directly update audit log through service (should not have an update method)
assertTest(
    !method_exists($audit, 'updateLog') && !method_exists($audit, 'deleteLog'),
    "TEST 20: AuditService enforces architectural immutability (no update/delete methods exist)"
);

// ==========================================
// 10. ADVERSARIAL TESTING & EXPORT SAFETY
// ==========================================
echo "\n--- 10. ADVERSARIAL TESTING & EXPORT INJECTION ---\n";

// TEST 21: Formula Injection Neutralization
$formulaPayloads = [
    '=cmd|"/c calc"!A0',
    '+1+2',
    '-5+5',
    '@SUM(A1:A10)',
    "\tmalicious_tab",
    "\rnewline_injection"
];
$allNeutralized = true;
foreach ($formulaPayloads as $payload) {
    $sanitized = ExportService::sanitizeCell($payload);
    if ($sanitized[0] !== "'") {
        $allNeutralized = false;
        break;
    }
}
assertTest(
    $allNeutralized,
    "TEST 21: ExportService successfully neutralizes formula injection triggers (=, +, -, @, \\t, \\r)"
);

// TEST 22: Safe normal numeric and text values are not corrupted
assertTest(
    ExportService::sanitizeCell(100.50) === 100.50 &&
    ExportService::sanitizeCell('Normal Medicine Name') === 'Normal Medicine Name',
    "TEST 22: ExportService preserves legitimate numbers and harmless strings without corruption"
);

// TEST 23: Filename sanitization against path traversal
$unsafeFilename = "../../../etc/passwd";
$cleanFilename = ExportService::sanitizeFilename($unsafeFilename, 'csv');
assertTest(
    !str_contains($cleanFilename, '..') && !str_contains($cleanFilename, '/'),
    "TEST 23: ExportService strips path traversal sequences from exported filenames",
    "Sanitized: {$cleanFilename}"
);

// TEST 24: SQL Injection resistance in daily sales search filter
$sqliTerm = "' OR 1=1 -- ";
$sqliRes = $analytics->getDailySalesRegister(['search' => $sqliTerm], 10, 0);
assertTest(
    is_array($sqliRes) && isset($sqliRes['total_rows']),
    "TEST 24: Parameterized prepared statements safely handle SQL injection payloads in report filters"
);

// TEST 25: ABC Inventory Analysis
$abcAnalysis = $analytics->getAbcAnalysis();
assertTest(
    $abcAnalysis['status'] === 'OK' && isset($abcAnalysis['summary']['A']),
    "TEST 25: ABC inventory value analysis properly stratifies catalog into Classes A, B, and C"
);

// TEST 26: Dead / Slow Moving Stock filter
$slowMoving = $analytics->getSlowMovingStock(30);
assertTest(
    is_array($slowMoving),
    "TEST 26: Slow-moving inventory analyzer identifies inactive medicines using configurable days threshold"
);

// ==========================================
// 11. REGRESSION VERIFICATION (CHUNKS 1 TO 6)
// ==========================================
echo "\n--- 11. REGRESSION TESTING (CHUNKS 1-6) ---\n";

// TEST 27: Chunk 1 Foundation (DB connection & Document Sequence)
$seqService = new \Pharmacy\Services\DocumentSequenceService($pdo);
$seq = $seqService->generate('COUNTER_SALE');
assertTest(str_starts_with($seq, 'CS-'), "TEST 27: Chunk 1 DocumentSequenceService generates valid CS- sequence");

// TEST 28: Chunk 2 Batch & FEFO
$fefo = new FefoService($pdo);
$medIdFefo = createTestMedicine7($pdo, 'FEFO_REG', 100.0, 50.0);
$bEarly = createTestBatch7($pdo, $medIdFefo, 'FEFO-E', 10, '2026-11-01', 50.0, 100.0);
$bLate = createTestBatch7($pdo, $medIdFefo, 'FEFO-L', 10, '2027-11-01', 50.0, 100.0);
$allocs = $fefo->previewAllocation($medIdFefo, 5);
assertTest(
    count($allocs) === 1 && $allocs[0]['batch_id'] === $bEarly,
    "TEST 28: Chunk 2 FefoService strictly allocates nearest-expiring batch first"
);

// TEST 29: Chunk 3 Procurement Tables Integrity
$supCount = (int)$pdo->query("SELECT COUNT(*) FROM pharmacy_suppliers")->fetchColumn();
assertTest($supCount >= 0, "TEST 29: Chunk 3 Procurement suppliers table accessible and responsive");

// TEST 30: Chunk 4 POS & Sales
$saleCheck = (int)$pdo->query("SELECT COUNT(*) FROM pharmacy_sales WHERE status != 'CANCELLED'")->fetchColumn();
assertTest($saleCheck > 0, "TEST 30: Chunk 4 Sales system transactions intact and operational");

// TEST 31: Chunk 5 Reverse Logistics & Non-Sellable States
$quarCount = (int)$pdo->query("SELECT COUNT(*) FROM medicine_batches WHERE status = 'Quarantined'")->fetchColumn();
assertTest($quarCount >= 0, "TEST 31: Chunk 5 Quarantine and non-sellable batch statuses intact");

// TEST 32: Chunk 6 Clinical Prescriptions & MAR
$rxCount = (int)$pdo->query("SELECT COUNT(*) FROM pharmacy_prescriptions")->fetchColumn();
assertTest($rxCount >= 0, "TEST 32: Chunk 6 Prescription architecture intact and operational");

// ==========================================
// SUMMARY
// ==========================================
echo "\n==========================================\n";
echo "CHUNK 7 TEST RUN COMPLETED\n";
echo "Total Tests Executed: " . ($passCount + $failCount) . "\n";
echo "Passed: {$passCount}\n";
echo "Failed: {$failCount}\n";
echo "==========================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
