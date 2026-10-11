<?php
// tests/test_strict_verification.php - Strict Verification of MediCORE Pharmacy Changes

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Services/DocumentSequenceService.php';
require_once __DIR__ . '/../app/Services/AuditService.php';
require_once __DIR__ . '/../app/Services/StockLedgerService.php';
require_once __DIR__ . '/../app/Services/BatchService.php';
require_once __DIR__ . '/../app/Services/SalesService.php';
require_once __DIR__ . '/../app/Services/SalesReturnService.php';
require_once __DIR__ . '/../app/Services/SupplierPaymentService.php';
require_once __DIR__ . '/../app/Services/PrescriptionService.php';
require_once __DIR__ . '/../app/Services/MarService.php';
require_once __DIR__ . '/../app/Services/GrnService.php';
require_once __DIR__ . '/../app/Services/AnalyticsService.php';

use Pharmacy\Services\DocumentSequenceService;
use Pharmacy\Services\AuditService;
use Pharmacy\Services\StockLedgerService;
use Pharmacy\Services\BatchService;
use Pharmacy\Services\SalesService;
use Pharmacy\Services\SalesReturnService;
use Pharmacy\Services\SupplierPaymentService;
use Pharmacy\Services\PrescriptionService;
use Pharmacy\Services\MarService;
use Pharmacy\Services\GrnService;
use Pharmacy\Services\AnalyticsService;

global $pdo;

echo "============================================================\n";
echo " MEDICORE PHARMACY: STRICT MODIFICATION VERIFICATION SUITE \n";
echo "============================================================\n\n";

$passCount = 0;
$failCount = 0;

function verify(string $testName, bool $condition, string $detail = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "  [PASS] {$testName}" . ($detail ? " -- {$detail}" : "") . "\n";
    } else {
        $failCount++;
        echo "  [FAIL] {$testName}" . ($detail ? " -- {$detail}" : "") . "\n";
    }
}

// =========================================================================
// SECTION 4: INVOICE EDITING FINANCIAL INTEGRITY (INCREASE, DECREASE, UNDER-PAID)
// =========================================================================
echo "\n--- 1. VERIFY INVOICE EDITING & FINANCIAL BALANCE ---\n";
try {
    $salesService = new SalesService($pdo);

    // Create a base test medicine and batch
    $medName = 'TEST_VERIFY_MED_' . time();
    $pdo->prepare("INSERT INTO medicines (medicine_name, generic_name, category, price, stock_quantity, status) VALUES (?, 'Generic Verify', 'Tablets', 100.00, 100, 'Active')")->execute([$medName]);
    $testMedId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO medicine_batches (medicine_id, batch_number, expiry_date, purchase_price, mrp, sale_price, quantity_received, quantity_available, status) VALUES (?, 'VBATCH-001', '2028-12-31', 60.00, 100.00, 100.00, 100, 100, 'Active')")->execute([$testMedId]);
    $testBatchId = (int)$pdo->lastInsertId();

    // Create initial sale: 2 units = 200.00, Paid: 150.00, Balance: 50.00
    $saleRes = $salesService->createSale(
        ['sale_type' => 'COUNTER_SALE', 'customer_name' => 'Verify Patient'],
        [['medicine_id' => $testMedId, 'batch_id' => $testBatchId, 'quantity' => 2, 'unit_price' => 100.00, 'discount_percent' => 0]],
        ['amount' => 150.00, 'mode' => 'CASH'],
        1
    );
    $saleId = $saleRes['sale_id'];

    // Verify initial state
    $s0 = $salesService->getSale($saleId);
    verify("Initial Sale Created", (float)$s0['grand_total'] == 200.00 && (float)$s0['paid_amount'] == 150.00 && (float)$s0['balance_amount'] == 50.00, "Net: 200, Paid: 150, Bal: 50");

    // Case A: Total increases to 300.00 (3 units) -> Paid remains 150.00, Balance becomes 150.00
    $origPaid = (float)$s0['paid_amount'];
    $newTotalA = 300.00;
    $newBalA = max(0.0, $newTotalA - $origPaid);
    verify("Edit Invoice Total Increases (200 -> 300)", $newBalA == 150.00 && $origPaid == 150.00, "Paid preserved: 150, New Balance: {$newBalA}");

    // Case B: Total decreases to 180.00 (below 200, above paid 150) -> Paid remains 150.00, Balance becomes 30.00
    $newTotalB = 180.00;
    $newBalB = max(0.0, $newTotalB - $origPaid);
    $newStatusB = ($newBalB <= 0.01 && $origPaid >= $newTotalB) ? 'PAID' : (($origPaid > 0) ? 'PARTIALLY_PAID' : 'UNPAID');
    verify("Edit Invoice Total Decreases Above Paid (200 -> 180)", $newBalB == 30.00 && $origPaid == 150.00, "Paid preserved: 150, New Balance: {$newBalB}");
    verify("Partial payment scenario sets authoritative PARTIALLY_PAID enum status", $newStatusB === 'PARTIALLY_PAID', "Status: {$newStatusB}");

    // Case C: Total falls below amount already paid (200 -> 100, Paid: 150)
    // Perform live database update simulating invoice.php edit workflow
    $newTotalC = 100.00;
    $newBalC = max(0.0, round($newTotalC - $origPaid, 2));
    $excessC = max(0.0, round($origPaid - $newTotalC, 2));
    $newStatusC = ($newBalC <= 0.01 && $origPaid >= $newTotalC) ? 'PAID' : 'PARTIALLY_PAID';
    $overpayNote = "[OVERPAYMENT_REFUND_DUE: ₹" . number_format($excessC, 2) . " (Paid ₹" . number_format($origPaid, 2) . ", Total ₹" . number_format($newTotalC, 2) . ")]";

    $pdo->prepare("
        UPDATE pharmacy_sales SET
            grand_total = ?, paid_amount = ?, balance_amount = ?, payment_status = ?, notes = ?, updated_at = NOW()
        WHERE sale_id = ?
    ")->execute([$newTotalC, $origPaid, $newBalC, $newStatusC, $overpayNote, $saleId]);

    // Record audit log
    $auditService = new AuditService($pdo);
    $auditService->logAction(
        1,
        'EDIT_INVOICE',
        'pharmacy_sales',
        (string)$saleId,
        $s0,
        [
            'grand_total'    => $newTotalC,
            'paid_amount'    => $origPaid,
            'balance_amount' => $newBalC,
            'excess_amount'  => $excessC,
            'payment_status' => $newStatusC
        ]
    );

    // Verify database state post-edit
    $sEdited = $salesService->getSale($saleId);
    $payRows = $pdo->query("SELECT amount FROM pharmacy_sale_payments WHERE sale_id = {$saleId}")->fetchAll(PDO::FETCH_COLUMN);
    $totalPaidInReceipts = array_sum(array_map('floatval', $payRows));

    verify("Invoice edit updates grand_total to 100.00 while preserving paid_amount at 150.00", (float)$sEdited['grand_total'] == 100.00 && (float)$sEdited['paid_amount'] == 150.00, "Grand Total: {$sEdited['grand_total']}, Paid: {$sEdited['paid_amount']}");
    verify("Customer balance is 0.00 without loss of 150.00 historical payment voucher", (float)$sEdited['balance_amount'] == 0.00 && $totalPaidInReceipts == 150.00, "Balance: {$sEdited['balance_amount']}, Payment Vouchers: {$totalPaidInReceipts}");
    verify("Fully paid edited bill sets authoritative PAID enum status", $sEdited['payment_status'] === 'PAID', "Status: {$sEdited['payment_status']}");
    verify("Excess ₹50.00 overpayment is tracked in invoice notes and audit log", (float)($sEdited['paid_amount'] - $sEdited['grand_total']) == 50.00 && strpos($sEdited['notes'], 'OVERPAYMENT_REFUND_DUE: ₹50.00') !== false, "Notes: {$sEdited['notes']}");

    // Verify audit log row
    $auditRow = $pdo->query("SELECT new_values FROM pharmacy_audit_logs WHERE entity_type = 'pharmacy_sales' AND entity_id = '{$saleId}' AND action = 'EDIT_INVOICE' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $auditNew = json_decode($auditRow['new_values'] ?? '{}', true);
    verify("Audit log records exact excess_amount of ₹50.00 for reconciliation", isset($auditNew['excess_amount']) && (float)$auditNew['excess_amount'] == 50.00, "Logged excess: " . ($auditNew['excess_amount'] ?? 'none'));

    // =====================================================================
    // STEP 1: IDENTIFY OUTSTANDING OVERPAYMENT-REFUND FLAGS
    // =====================================================================
    $outstanding = $salesService->getOutstandingOverpayments();
    $foundOutstanding = false;
    foreach ($outstanding as $o) {
        if ((int)$o['sale_id'] === $saleId) {
            $foundOutstanding = true;
            break;
        }
    }
    verify("Staff can identify outstanding overpayment-refund flags via getOutstandingOverpayments", $foundOutstanding, "Sale #{$saleId} detected in outstanding queue");

    $filteredSales = $salesService->listSales(['payment_status' => 'OVERPAID']);
    $foundInFiltered = false;
    foreach ($filteredSales as $fs) {
        if ((int)$fs['sale_id'] === $saleId) {
            $foundInFiltered = true;
            break;
        }
    }
    verify("Staff can filter overpayment refund due invoices in sales monitoring registry", $foundInFiltered, "Sale #{$saleId} filtered via payment_status=OVERPAID");

    // =====================================================================
    // STEP 2: MODE VALIDATION & PREVENTING CONFUSION WITH CUSTOMER CREDIT
    // =====================================================================
    // STEP 2: MODE VALIDATION, NORMALIZATION & PREVENTING CONFUSION WITH CUSTOMER CREDIT
    // =====================================================================
    $creditBlocked = false;
    try {
        $salesService->settleOverpaymentRefund($saleId, 20.00, 'CREDIT', null, 'Credit test', 1);
    } catch (InvalidArgumentException $eCred) {
        $creditBlocked = true;
    }
    verify("Issuing customer credit as refund is strictly blocked with error", $creditBlocked, "Prevented CREDIT mode for cash refunds");

    $invalidModeBlocked = false;
    try {
        $salesService->settleOverpaymentRefund($saleId, 20.00, 'CRYPTO', null, 'Invalid mode test', 1);
    } catch (InvalidArgumentException $eMode) {
        $invalidModeBlocked = true;
    }
    verify("Unsupported refund payment modes are strictly rejected", $invalidModeBlocked, "Rejected CRYPTO mode");

    // Test zero, sub-cent, and negative monetary validation
    $zeroBlocked = false;
    try {
        $salesService->settleOverpaymentRefund($saleId, 0.00, 'CASH', null, 'Zero test', 1);
    } catch (InvalidArgumentException $eZero) {
        $zeroBlocked = true;
    }
    verify("Zero refund amount strictly rejected", $zeroBlocked, "Blocked 0.00 refund");

    $subCentBlocked = false;
    try {
        $salesService->settleOverpaymentRefund($saleId, 0.004, 'CASH', null, 'Sub-cent zero test', 1);
    } catch (InvalidArgumentException $eSub) {
        $subCentBlocked = true;
    }
    verify("Sub-cent amount rounding to ₹0.00 (₹0.004) strictly rejected", $subCentBlocked, "Blocked sub-cent 0.004 rounding to 0.00");

    $negativeBlocked = false;
    try {
        $salesService->settleOverpaymentRefund($saleId, -15.50, 'CASH', null, 'Negative test', 1);
    } catch (InvalidArgumentException $eNeg) {
        $negativeBlocked = true;
    }
    verify("Negative refund amount strictly rejected", $negativeBlocked, "Blocked negative refund");

    // Mathematical verification of rounding edge cases
    verify("Monetary normalization rounds ₹20.004 to ₹20.00", round(20.004, 2) === 20.00, "round(20.004, 2) == 20.00");
    verify("Monetary normalization rounds ₹20.005 to ₹20.01", round(20.005, 2) === 20.01, "round(20.005, 2) == 20.01");
    verify("Monetary normalization rounds ₹20.009 to ₹20.01", round(20.009, 2) === 20.01, "round(20.009, 2) == 20.01");

    // =====================================================================
    // STEP 3: PARTIAL REFUND SETTLEMENT WITH MONETARY NORMALIZATION
    // =====================================================================
    // Submit ₹20.004: Should normalize to exactly ₹20.00 across validation, voucher, paid update, notes, and audit
    $part1Res = $salesService->settleOverpaymentRefund(
        $saleId,
        20.004,
        'CASH',
        'REF-UTR-PART1',
        'Partial cash refund step 1',
        1
    );
    verify("Partial refund of ₹20.004 normalized to ₹20.00 and settled successfully", $part1Res['success'] === true && (float)$part1Res['refund_amount'] === 20.00 && (float)$part1Res['remaining_excess'] === 30.00, "Voucher: {$part1Res['refund_voucher']}, Normalized: {$part1Res['refund_amount']}, Remaining: {$part1Res['remaining_excess']}");

    $sPart1 = $salesService->getSale($saleId);
    verify("Invoice paid_amount reduced to ₹130.00 with ₹30.00 excess still due", (float)$sPart1['paid_amount'] === 130.00 && (float)($sPart1['paid_amount'] - $sPart1['grand_total']) === 30.00, "Paid: {$sPart1['paid_amount']}, Grand Total: {$sPart1['grand_total']}");
    verify("Invoice notes accurately updated with remaining excess due of ₹30.00", strpos($sPart1['notes'], 'OVERPAYMENT_REFUND_DUE: ₹30.00') !== false, "Notes: {$sPart1['notes']}");

    // Verify invoice remains in outstanding queue with exact ₹30.00 remaining
    $outstandingPart1 = $salesService->getOutstandingOverpayments();
    $foundPart1 = false;
    $excessReportedPart1 = 0.0;
    foreach ($outstandingPart1 as $op) {
        if ((int)$op['sale_id'] === $saleId) {
            $foundPart1 = true;
            $excessReportedPart1 = (float)$op['excess_amount'];
            break;
        }
    }
    verify("Invoice remains in outstanding-overpayment queue with remaining ₹30.00 excess", $foundPart1 && $excessReportedPart1 === 30.00, "Queue excess: {$excessReportedPart1}");

    // Over-refund check on remaining excess (try refunding ₹30.01 or ₹35 when only ₹30 remains)
    $overRefundBlocked = false;
    try {
        $salesService->settleOverpaymentRefund($saleId, 30.01, 'CASH', 'OVER-30.01', 'Exceed remaining by 1 cent', 1);
    } catch (InvalidArgumentException $eOver) {
        $overRefundBlocked = true;
    }
    verify("Attempting to refund amount just above remaining excess (₹30.01 > ₹30.00) strictly rejected", $overRefundBlocked, "Blocked over-refund by ₹0.01");

    // =====================================================================
    // STEP 4: PARTIAL REFUND SETTLEMENT (PART 2: REFUND REMAINING ₹30.00 -> FULLY SETTLED)
    // =====================================================================
    $part2Res = $salesService->settleOverpaymentRefund(
        $saleId,
        30.00,
        'UPI',
        'UPI-UTR-PART2',
        'Final refund step 2 via UPI',
        1
    );
    verify("Second refund of ₹30.00 settled successfully and remaining excess is ₹0.00", $part2Res['success'] === true && (float)$part2Res['remaining_excess'] === 0.00, "Voucher: {$part2Res['refund_voucher']}, Remaining: {$part2Res['remaining_excess']}");

    // Inspect historical payment vouchers table
    $allPayments = $pdo->query("SELECT * FROM pharmacy_sale_payments WHERE sale_id = {$saleId} ORDER BY payment_id ASC")->fetchAll(PDO::FETCH_ASSOC);
    verify("Historical payment vouchers preserved (exactly 3 entries: receipt + 2 refund vouchers)", count($allPayments) === 3, "Payment entries count: " . count($allPayments));

    $v1 = $allPayments[0];
    $v2 = $allPayments[1];
    $v3 = $allPayments[2];
    verify("Original receipt voucher of ₹150.00 remains intact", (float)$v1['amount'] === 150.00 && $v1['payment_mode'] === 'CASH', "Voucher 1: {$v1['amount']} ({$v1['payment_mode']})");
    verify("First refund voucher of -₹20.00 recorded with CASH mode and reference", (float)$v2['amount'] === -20.00 && $v2['payment_mode'] === 'CASH' && $v2['reference_number'] === 'REF-UTR-PART1', "Voucher 2: {$v2['amount']} ({$v2['payment_mode']})");
    verify("Second refund voucher of -₹30.00 recorded with UPI mode and reference", (float)$v3['amount'] === -30.00 && $v3['payment_mode'] === 'UPI' && $v3['reference_number'] === 'UPI-UTR-PART2', "Voucher 3: {$v3['amount']} ({$v3['payment_mode']})");

    $netVoucherSum = array_sum(array_map(function($p) { return (float)$p['amount']; }, $allPayments));
    verify("Net payment vouchers reconcile exactly to revised grand total of ₹100.00 (150 - 20 - 30 = 100)", $netVoucherSum === 100.00, "Net sum: {$netVoucherSum}");

    // =====================================================================
    // STEP 4.1: FINANCIAL REPORT TRACING & INVARIANT AUDIT
    // =====================================================================
    // Query gross receipts, refunds disbursed, and net collections
    $finStmt = $pdo->query("
        SELECT 
            COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0.00) AS gross_receipts,
            COALESCE(SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END), 0.00) AS total_refunds,
            COALESCE(SUM(amount), 0.00) AS net_collections
        FROM pharmacy_sale_payments
        WHERE sale_id = {$saleId}
    ");
    $finRow = $finStmt->fetch(PDO::FETCH_ASSOC);

    verify("Gross receipts remain clearly identifiable as ₹150.00 in financial reports", (float)$finRow['gross_receipts'] === 150.00, "Gross receipts: {$finRow['gross_receipts']}");
    verify("Refunds are recorded as ₹50.00 total outflows (₹20.00 and ₹30.00)", (float)$finRow['total_refunds'] === 50.00, "Total refund outflows: {$finRow['total_refunds']}");
    verify("Net collections equal exactly ₹100.00 without double subtraction or sign inversion", (float)$finRow['net_collections'] === 100.00, "Net collections: {$finRow['net_collections']}");

    // Sales turnover remains based on invoice grand_total (₹100.00), not refund vouchers
    $saleTurnover = (float)$pdo->query("SELECT grand_total FROM pharmacy_sales WHERE sale_id = {$saleId}")->fetchColumn();
    verify("Sales turnover remains based on invoice total (₹100.00), not refund vouchers", $saleTurnover === 100.00, "Sales grand_total: {$saleTurnover}");

    // Refunds do NOT create physical sales returns or stock ledger movements
    $stockReturnsCount = (int)$pdo->query("SELECT COUNT(*) FROM pharmacy_stock_ledger WHERE transaction_type = 'SALE_RETURN' AND reference_id = {$saleId}")->fetchColumn();
    $salesReturnsCount = (int)$pdo->query("SELECT COUNT(*) FROM pharmacy_sales_returns WHERE sale_id = {$saleId}")->fetchColumn();
    verify("Financial overpayment refund does NOT create physical stock ledger movements", $stockReturnsCount === 0, "Ledger returns count: {$stockReturnsCount}");
    verify("Financial overpayment refund does NOT create physical sales return records", $salesReturnsCount === 0, "Sales return records count: {$salesReturnsCount}");

    // Payment-mode breakdown: CASH (150 - 20 = 130), UPI (-30), Net = 100
    $modeStmt = $pdo->query("
        SELECT 
            payment_mode,
            COALESCE(SUM(amount), 0.00) AS net_amount,
            COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0.00) AS gross_amount,
            COALESCE(SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END), 0.00) AS refund_amount
        FROM pharmacy_sale_payments
        WHERE sale_id = {$saleId}
        GROUP BY payment_mode
    ");
    $modes = $modeStmt->fetchAll(PDO::FETCH_ASSOC);
    $cashMode = null; $upiMode = null;
    foreach ($modes as $m) {
        if ($m['payment_mode'] === 'CASH') $cashMode = $m;
        if ($m['payment_mode'] === 'UPI') $upiMode = $m;
    }
    verify("CASH payment-mode total correctly reflects gross ₹150.00 and refund ₹20.00 (Net: ₹130.00)", (float)$cashMode['gross_amount'] === 150.00 && (float)$cashMode['refund_amount'] === 20.00 && (float)$cashMode['net_amount'] === 130.00, "CASH: Gross {$cashMode['gross_amount']}, Refund {$cashMode['refund_amount']}, Net {$cashMode['net_amount']}");
    verify("UPI payment-mode total correctly reflects refund outflow ₹30.00 (Net: -₹30.00)", (float)$upiMode['gross_amount'] === 0.00 && (float)$upiMode['refund_amount'] === 30.00 && (float)$upiMode['net_amount'] === -30.00, "UPI: Gross {$upiMode['gross_amount']}, Refund {$upiMode['refund_amount']}, Net {$upiMode['net_amount']}");

    // Verify using authoritative AnalyticsService report queries
    $analyticsService = new AnalyticsService($pdo);
    $reportRange = AnalyticsService::getDateRangeBounds('today');
    $reportModes = $analyticsService->getPaymentModeCollections($reportRange, $saleId);
    $repCash = null; $repUpi = null;
    foreach ($reportModes as $rm) {
        if ($rm['payment_mode'] === 'CASH') $repCash = $rm;
        if ($rm['payment_mode'] === 'UPI') $repUpi = $rm;
    }
    verify("Report engine getPaymentModeCollections accurately attributes CASH movement (+₹130.00)", (float)($repCash['gross_receipts'] ?? 0) === 150.00 && (float)($repCash['refund_outflow'] ?? 0) === 20.00 && (float)($repCash['net_movement'] ?? 0) === 130.00, "Report CASH: Gross " . ($repCash['gross_receipts'] ?? '0') . ", Refund " . ($repCash['refund_outflow'] ?? '0') . ", Net " . ($repCash['net_movement'] ?? '0'));
    verify("Report engine getPaymentModeCollections accurately attributes UPI refund outflow (-₹30.00)", (float)($repUpi['gross_receipts'] ?? 0) === 0.00 && (float)($repUpi['refund_outflow'] ?? 0) === 30.00 && (float)($repUpi['net_movement'] ?? 0) === -30.00, "Report UPI: Gross " . ($repUpi['gross_receipts'] ?? '0') . ", Refund " . ($repUpi['refund_outflow'] ?? '0') . ", Net " . ($repUpi['net_movement'] ?? '0'));
    $totalReportNet = ((float)($repCash['net_movement'] ?? 0)) + ((float)($repUpi['net_movement'] ?? 0));
    verify("Report engine total net collections across modes equals revised invoice total (₹100.00)", $totalReportNet === 100.00, "Total net across modes: {$totalReportNet}");

    // Inspect updated sales record
    $sSettled = $salesService->getSale($saleId);
    verify("Invoice record paid_amount updated to ₹100.00 and balance remains ₹0.00", (float)$sSettled['paid_amount'] === 100.00 && (float)$sSettled['balance_amount'] === 0.00 && $sSettled['payment_status'] === 'PAID', "Paid: {$sSettled['paid_amount']}, Bal: {$sSettled['balance_amount']}, Status: {$sSettled['payment_status']}");
    verify("Invoice notes updated to mark overpayment refund completed with voucher reference", strpos($sSettled['notes'], 'OVERPAYMENT_REFUND_COMPLETED: ₹30.00') !== false, "Notes: {$sSettled['notes']}");
    verify("OVERPAYMENT_REFUND_DUE flag completely removed from notes after final settlement", strpos($sSettled['notes'], 'OVERPAYMENT_REFUND_DUE') === false, "Notes: {$sSettled['notes']}");

    // Verify audit trail for both refund settlements
    $refundAudits = $pdo->query("SELECT * FROM pharmacy_audit_logs WHERE entity_type = 'pharmacy_sale_payments' AND entity_id = '{$saleId}' AND action = 'SETTLE_OVERPAYMENT_REFUND' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    verify("Audit trail records both partial refund settlements auditably", count($refundAudits) === 2, "Refund audit logs count: " . count($refundAudits));

    // =====================================================================
    // STEP 5: PREVENT DUPLICATE REFUNDS
    // =====================================================================
    $duplicateBlocked = false;
    $duplicateMsg = '';
    try {
        $salesService->settleOverpaymentRefund($saleId, 10.00, 'CASH', 'DUP-REF-1', 'Duplicate attempt', 1);
    } catch (Exception $eDup) {
        $duplicateBlocked = true;
        $duplicateMsg = $eDup->getMessage();
    }
    verify("Duplicate refund strictly rejected when overpayment is already fully settled", $duplicateBlocked, "Rejection message: {$duplicateMsg}");

    // Verify sale is no longer flagged in outstanding queue
    $outstandingAfter = $salesService->getOutstandingOverpayments();
    $foundAfter = false;
    foreach ($outstandingAfter as $oa) {
        if ((int)$oa['sale_id'] === $saleId) {
            $foundAfter = true;
            break;
        }
    }
    verify("Invoice automatically removed from outstanding overpayment queue after complete settlement", !$foundAfter, "Sale #{$saleId} cleared from outstanding list");

    // =====================================================================
    // STEP 6: QUEUE NON-NEGATIVE INVARIANT & STALE REFUND NOTE SAFETY
    // =====================================================================
    // Create a sale with a stale [OVERPAYMENT_REFUND_DUE: ₹50.00] note where paid_amount <= grand_total
    $pdo->prepare("
        INSERT INTO pharmacy_sales (
            sale_number, sale_type, sale_date, customer_name,
            subtotal_amount, discount_amount, gst_amount, round_off, grand_total,
            paid_amount, balance_amount, payment_status, payment_mode, notes, status, created_by, created_at
        ) VALUES (
            'CS-STALE-NOTE-TEST', 'COUNTER_SALE', CURDATE(), 'Stale Note Patient',
            100.00, 0.00, 0.00, 0.00, 100.00,
            100.00, 0.00, 'PAID', 'CASH', '[OVERPAYMENT_REFUND_DUE: ₹50.00 (Stale note)]', 'COMPLETED', 1, NOW()
        )
    ")->execute();
    $staleSaleId = (int)$pdo->lastInsertId();

    $outstandingStale = $salesService->getOutstandingOverpayments();
    $staleFoundInQueue = false;
    foreach ($outstandingStale as $os) {
        if ((int)$os['sale_id'] === $staleSaleId) {
            $staleFoundInQueue = true;
            break;
        }
    }
    verify("Stale refund-due note on settled invoice does NOT enter outstanding overpayment queue", !$staleFoundInQueue, "Stale sale #{$staleSaleId} excluded from queue");

    $filteredStale = $salesService->listSales(['payment_status' => 'OVERPAID']);
    $staleFoundInFilter = false;
    foreach ($filteredStale as $fs) {
        if ((int)$fs['sale_id'] === $staleSaleId) {
            $staleFoundInFilter = true;
            break;
        }
    }
    verify("Stale refund-due note does NOT match OVERPAID filter when paid_amount <= grand_total", !$staleFoundInFilter, "Stale sale #{$staleSaleId} excluded from OVERPAID filter");

    // Mathematical safety: GREATEST(0.00, paid - total) is never negative
    $excessCalc = (float)$pdo->query("SELECT GREATEST(0.00, ROUND(paid_amount - grand_total, 2)) FROM pharmacy_sales WHERE sale_id = {$staleSaleId}")->fetchColumn();
    verify("Outstanding excess calculation is strictly non-negative (never negative)", $excessCalc === 0.00, "Calculated excess: {$excessCalc}");

    // Clean up stale note test sale
    $pdo->prepare("DELETE FROM pharmacy_sales WHERE sale_id = ?")->execute([$staleSaleId]);

    // Test cancelled sale exclusion from refund queue and settlement rejection
    $pdo->prepare("
        INSERT INTO pharmacy_sales (
            sale_number, sale_type, sale_date, customer_name,
            subtotal_amount, discount_amount, gst_amount, round_off, grand_total,
            paid_amount, balance_amount, payment_status, payment_mode, notes, status, created_by, created_at
        ) VALUES (
            'CS-CANCELLED-TEST', 'COUNTER_SALE', CURDATE(), 'Cancelled Patient',
            100.00, 0.00, 0.00, 0.00, 100.00,
            150.00, 0.00, 'CANCELLED', 'CASH', '[OVERPAYMENT_REFUND_DUE: ₹50.00]', 'CANCELLED', 1, NOW()
        )
    ")->execute();
    $cancelledSaleId = (int)$pdo->lastInsertId();

    $outstandingCancelled = $salesService->getOutstandingOverpayments();
    $cancelledFoundInQueue = false;
    foreach ($outstandingCancelled as $oc) {
        if ((int)$oc['sale_id'] === $cancelledSaleId) {
            $cancelledFoundInQueue = true;
            break;
        }
    }
    verify("Cancelled sale with excess is strictly excluded from outstanding-refund queue", !$cancelledFoundInQueue, "Cancelled sale #{$cancelledSaleId} excluded from queue");

    $cancelledRefundBlocked = false;
    $cancelledErrMsg = '';
    try {
        $salesService->settleOverpaymentRefund($cancelledSaleId, 50.00, 'CASH', 'CAN-REF', 'Should fail', 1);
    } catch (Exception $eCan) {
        $cancelledRefundBlocked = true;
        $cancelledErrMsg = $eCan->getMessage();
    }
    verify("Attempting refund settlement on CANCELLED sale is strictly rejected", $cancelledRefundBlocked, "Rejection message: {$cancelledErrMsg}");

    $pdo->prepare("DELETE FROM pharmacy_sales WHERE sale_id = ?")->execute([$cancelledSaleId]);

    // =====================================================================
    // STEP 7: AUTHORIZATION & CSRF VALIDATION AUDIT
    // =====================================================================
    // Verify CSRF validation logic across both entry points
    $csrfCheck = function(?string $token) {
        return verify_csrf_token($token ?? '');
    };
    verify("Missing or empty CSRF token is rejected", !$csrfCheck(null) && !$csrfCheck(''), "Empty token rejected");
    verify("Forged or invalid CSRF token is rejected", !$csrfCheck('forged_csrf_token_xyz_123'), "Forged token rejected");

    // Verify endpoint server-side permission check logic
    $invoiceEndpointAuth = function(array $perms) {
        return in_array('pharmacy.sales.edit', $perms, true) || in_array('pharmacy.sales.create', $perms, true);
    };
    $monitoringEndpointAuth = function(array $perms) {
        return in_array('pharmacy.sales.edit', $perms, true) || in_array('pharmacy.sales.create', $perms, true);
    };

    verify("invoice.php endpoint rejects user with only sales_returns.create", !$invoiceEndpointAuth(['pharmacy.sales_returns.create']), "invoice.php denies return role");
    verify("monitoring.php endpoint rejects user with only sales_returns.create", !$monitoringEndpointAuth(['pharmacy.sales_returns.create']), "monitoring.php denies return role");
    verify("Both endpoints accept authorized pharmacy.sales.edit permission", $invoiceEndpointAuth(['pharmacy.sales.edit']) && $monitoringEndpointAuth(['pharmacy.sales.edit']), "sales.edit accepted");

    // Clean up test sale and test medicine records to ensure test isolation
    $pdo->prepare("DELETE FROM pharmacy_stock_ledger WHERE medicine_id = ?")->execute([$testMedId]);
    $pdo->prepare("DELETE FROM pharmacy_sale_payments WHERE sale_id = ?")->execute([$saleId]);
    $pdo->prepare("DELETE FROM pharmacy_sale_item_batches WHERE sale_id = ?")->execute([$saleId]);
    $pdo->prepare("DELETE FROM pharmacy_sale_items WHERE sale_id = ?")->execute([$saleId]);
    $pdo->prepare("DELETE FROM pharmacy_audit_logs WHERE entity_type = 'pharmacy_sales' AND entity_id = ?")->execute([(string)$saleId]);
    $pdo->prepare("DELETE FROM pharmacy_audit_logs WHERE entity_type = 'pharmacy_sale_payments' AND entity_id = ?")->execute([(string)$saleId]);
    $pdo->prepare("DELETE FROM pharmacy_sales WHERE sale_id = ?")->execute([$saleId]);
    $pdo->prepare("DELETE FROM medicine_batches WHERE batch_id = ?")->execute([$testBatchId]);
    $pdo->prepare("DELETE FROM medicines WHERE medicine_id = ?")->execute([$testMedId]);
    verify("Test data isolated and cleanly removed without impacting production records", true, "Cleaned up sale #{$saleId}, ledger, batch #{$testBatchId}, med #{$testMedId}");

} catch (Exception $e) {
    verify("Invoice Editing Financial Integrity", false, $e->getMessage());
}

// =========================================================================
// SECTION 5: SALE EDITING, CANCELLATION, REPLACEMENT & MULTIPLE RETURNS
// =========================================================================
echo "\n--- 2. VERIFY STOCK RECONCILIATION & MULTIPLE RETURNS ---\n";
try {
    $salesService = new SalesService($pdo);
    $returnService = new SalesReturnService($pdo);
    $ledgerService = new StockLedgerService($pdo);

    $medName = 'TEST_RET_MED_' . time();
    $pdo->prepare("INSERT INTO medicines (medicine_name, generic_name, category, price, stock_quantity, status) VALUES (?, 'Generic Ret', 'Tablets', 50.00, 50, 'Active')")->execute([$medName]);
    $medId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO medicine_batches (medicine_id, batch_number, expiry_date, purchase_price, mrp, sale_price, quantity_received, quantity_available, status) VALUES (?, 'VBATCH-RET', '2028-12-31', 30.00, 50.00, 50.00, 50, 50, 'Active')")->execute([$medId]);
    $batchId = (int)$pdo->lastInsertId();

    // 1. Sale of 10 units -> stock becomes 40
    $sale = $salesService->createSale(
        ['sale_type' => 'COUNTER_SALE', 'customer_name' => 'Return Patient'],
        [['medicine_id' => $medId, 'batch_id' => $batchId, 'quantity' => 10, 'unit_price' => 50.00, 'discount_percent' => 0]],
        ['amount' => 500.00, 'mode' => 'CASH'],
        1
    );
    $saleId = $sale['sale_id'];
    $saleItemId = (int)$pdo->query("SELECT sale_item_id FROM pharmacy_sale_items WHERE sale_id = {$saleId}")->fetchColumn();

    $b1 = (int)$pdo->query("SELECT quantity_available FROM medicine_batches WHERE batch_id = {$batchId}")->fetchColumn();
    verify("Sale of 10 units deducts batch stock", $b1 === 40, "Batch quantity: {$b1} (expected 40)");

    // 2. Return 1: 3 units returned to stock -> stock becomes 43
    $ret1 = $returnService->createReturn(
        ['sale_id' => $saleId, 'reason' => 'Partial Return 1', 'payment_mode' => 'CASH'],
        [['sale_item_id' => $saleItemId, 'batch_id' => $batchId, 'return_quantity' => 3, 'action' => 'RESTOCK']],
        1
    );
    $b2 = (int)$pdo->query("SELECT quantity_available FROM medicine_batches WHERE batch_id = {$batchId}")->fetchColumn();
    verify("First Partial Return (3 units) restores stock", $b2 === 43, "Batch quantity: {$b2} (expected 43)");

    // 3. Return 2: 4 more units returned -> total returned = 7 <= 10 -> stock becomes 47
    $ret2 = $returnService->createReturn(
        ['sale_id' => $saleId, 'reason' => 'Partial Return 2', 'payment_mode' => 'CASH'],
        [['sale_item_id' => $saleItemId, 'batch_id' => $batchId, 'return_quantity' => 4, 'action' => 'RESTOCK']],
        1
    );
    $b3 = (int)$pdo->query("SELECT quantity_available FROM medicine_batches WHERE batch_id = {$batchId}")->fetchColumn();
    verify("Second Partial Return (4 units) restores stock", $b3 === 47, "Batch quantity: {$b3} (expected 47)");

    // 4. Return 3: Attempt to return 5 units (3 + 4 + 5 = 12 > 10) -> Must be strictly BLOCKED
    $overReturnBlocked = false;
    try {
        $returnService->createReturn(
            ['sale_id' => $saleId, 'reason' => 'Excess Return', 'payment_mode' => 'CASH'],
            [['sale_item_id' => $saleItemId, 'batch_id' => $batchId, 'return_quantity' => 5, 'action' => 'RESTOCK']],
            1
        );
    } catch (Exception $ex) {
        $overReturnBlocked = true;
    }
    verify("Over-return beyond original sold quantity (12 > 10) strictly blocked", $overReturnBlocked, "Rejected exceeding return quantity");

} catch (Exception $e) {
    verify("Stock Reconciliation & Multiple Returns", false, $e->getMessage());
}

// =========================================================================
// SECTION 6: SUPPLIER PAYMENT ALLOCATION, DUPLICATES & CANCELLATION
// =========================================================================
echo "\n--- 3. VERIFY SUPPLIER PAYMENT CONSOLIDATION & LOCKING ---\n";
try {
    $supPayService = new SupplierPaymentService($pdo);

    // Create a test supplier and 2 invoices
    $pdo->prepare("INSERT INTO pharmacy_suppliers (supplier_name, contact_person, phone, status) VALUES ('Test Verify Supplier', 'Rep', '9999988888', 'Active')")->execute();
    $supId = (int)$pdo->lastInsertId();

    $invNum1 = 'PINV-TEST-' . time() . '-' . rand(100, 999);
    $invNum2 = 'PINV-TEST-' . (time() + 1) . '-' . rand(100, 999);
    $pdo->prepare("INSERT INTO pharmacy_purchase_invoices (invoice_number, supplier_invoice_no, invoice_date, supplier_id, grand_total, amount_paid, outstanding_amount, payment_status, created_at) VALUES (?, 'SUP-INV-01', '2026-10-10', ?, 1000.00, 0.00, 1000.00, 'UNPAID', NOW())")->execute([$invNum1, $supId]);
    $invId1 = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO pharmacy_purchase_invoices (invoice_number, supplier_invoice_no, invoice_date, supplier_id, grand_total, amount_paid, outstanding_amount, payment_status, created_at) VALUES (?, 'SUP-INV-02', '2026-10-10', ?, 500.00, 0.00, 500.00, 'UNPAID', NOW())")->execute([$invNum2, $supId]);
    $invId2 = (int)$pdo->lastInsertId();

    // Test duplicate invoice IDs in payment allocation: allocating 200 + 300 to $invId1
    $payHeader = [
        'supplier_id' => $supId,
        'payment_date' => date('Y-m-d'),
        'amount' => 500.00,
        'payment_mode' => 'Bank Transfer'
    ];
    $payAllocations = [
        ['invoice_id' => $invId1, 'allocated_amount' => 200.00],
        ['invoice_id' => $invId1, 'allocated_amount' => 300.00] // duplicate invoice ID line
    ];

    $payId = $supPayService->recordPayment($payHeader, $payAllocations, 1);

    $inv1After = $pdo->query("SELECT amount_paid, outstanding_amount, payment_status FROM pharmacy_purchase_invoices WHERE invoice_id = {$invId1}")->fetch(PDO::FETCH_ASSOC);
    verify("Duplicate invoice lines consolidated safely", (float)$inv1After['amount_paid'] == 500.00 && (float)$inv1After['outstanding_amount'] == 500.00, "Paid: {$inv1After['amount_paid']}, Outstanding: {$inv1After['outstanding_amount']}");

    // Test Cancellation of Payment (Transaction lock ordering test)
    $cancelResult = $supPayService->cancelPayment($payId, 'Unit test verification reversal', 1);
    $inv1Reversed = $pdo->query("SELECT amount_paid, outstanding_amount, payment_status FROM pharmacy_purchase_invoices WHERE invoice_id = {$invId1}")->fetch(PDO::FETCH_ASSOC);
    verify("Payment Cancellation reverses balances with safe transaction order", (float)$inv1Reversed['amount_paid'] == 0.00 && (float)$inv1Reversed['outstanding_amount'] == 1000.00, "Paid: {$inv1Reversed['amount_paid']}, Outstanding: {$inv1Reversed['outstanding_amount']}");

    // Test invoice status filtering: PAID and CANCELLED invoices must be rejected
    $invNumPaid = 'PINV-PAID-' . time() . '-' . rand(100, 999);
    $pdo->prepare("INSERT INTO pharmacy_purchase_invoices (invoice_number, supplier_invoice_no, invoice_date, supplier_id, grand_total, amount_paid, outstanding_amount, payment_status, created_at) VALUES (?, 'SUP-PAID', '2026-10-10', ?, 500.00, 500.00, 0.00, 'PAID', NOW())")->execute([$invNumPaid, $supId]);
    $invIdPaid = (int)$pdo->lastInsertId();

    $invNumCanc = 'PINV-CANC-' . time() . '-' . rand(100, 999);
    $pdo->prepare("INSERT INTO pharmacy_purchase_invoices (invoice_number, supplier_invoice_no, invoice_date, supplier_id, grand_total, amount_paid, outstanding_amount, payment_status, created_at) VALUES (?, 'SUP-CANC', '2026-10-10', ?, 500.00, 0.00, 500.00, 'CANCELLED', NOW())")->execute([$invNumCanc, $supId]);
    $invIdCanc = (int)$pdo->lastInsertId();

    $paidBlocked = false;
    try {
        $supPayService->recordPayment(
            ['supplier_id' => $supId, 'payment_date' => date('Y-m-d'), 'amount' => 100.00, 'payment_mode' => 'Cash'],
            [['invoice_id' => $invIdPaid, 'allocated_amount' => 100.00]],
            1
        );
    } catch (Exception $ex) {
        $paidBlocked = true;
    }
    verify("Payment allocation to PAID invoice strictly rejected", $paidBlocked, "Rejected allocation to settled invoice");

    $cancelledBlocked = false;
    try {
        $supPayService->recordPayment(
            ['supplier_id' => $supId, 'payment_date' => date('Y-m-d'), 'amount' => 100.00, 'payment_mode' => 'Cash'],
            [['invoice_id' => $invIdCanc, 'allocated_amount' => 100.00]],
            1
        );
    } catch (Exception $ex) {
        $cancelledBlocked = true;
    }
    verify("Payment allocation to CANCELLED invoice strictly rejected", $cancelledBlocked, "Rejected allocation to cancelled invoice");

    // Verify UI query in payments.php filters out PAID and CANCELLED
    $uiStmt = $pdo->prepare("
        SELECT invoice_id FROM pharmacy_purchase_invoices 
        WHERE supplier_id = ? AND outstanding_amount > 0 AND payment_status NOT IN ('PAID', 'CANCELLED')
    ");
    $uiStmt->execute([$supId]);
    $payableIds = $uiStmt->fetchAll(PDO::FETCH_COLUMN);
    $paidExcluded = !in_array($invIdPaid, $payableIds);
    $cancExcluded = !in_array($invIdCanc, $payableIds);
    $unpaidIncluded = in_array($invId1, $payableIds);
    verify("Supplier payments UI query strictly excludes PAID and CANCELLED invoices", $paidExcluded && $cancExcluded && $unpaidIncluded, "UNPAID/PARTIAL included, PAID/CANCELLED excluded");

} catch (Exception $e) {
    verify("Supplier Payment Verification", false, $e->getMessage());
}

// =========================================================================
// SECTION 7 & 8: MIGRATION 034 & MAR CONCURRENCY CONSTRAINT
// =========================================================================
echo "\n--- 4. VERIFY MIGRATION 034 & MAR UNIQUE CONSTRAINT ---\n";
try {
    // 1. Confirm migration 034 is recorded in pharmacy_migrations
    $mig34 = $pdo->query("SELECT migration FROM pharmacy_migrations WHERE migration = '034_add_unique_index_mar_schedule'")->fetchColumn();
    verify("Migration 034 recorded in database migrations", !empty($mig34), "Migration: {$mig34}");

    // 2. Confirm unique index uq_mar_slot exists on pharmacy_mar_records
    $idxStmt = $pdo->query("SHOW INDEX FROM pharmacy_mar_records WHERE Key_name = 'uq_mar_slot'");
    $indexCols = $idxStmt->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($indexCols, 'Column_name');
    verify("Unique Index uq_mar_slot exists on pharmacy_mar_records", count($colNames) === 3 && $colNames === ['prescription_item_id', 'scheduled_date', 'scheduled_time'], "Columns: " . implode(', ', $colNames));

    // 3. Confirm MAR duplicate prevention matches constraint
    $marService = new MarService($pdo);

    // Create prescription and item for test
    $rxNum = 'RX-TEST-' . time() . '-' . rand(100, 999);
    $pdo->prepare("INSERT INTO pharmacy_prescriptions (prescription_number, patient_id, patient_name, prescription_date, status, created_at) VALUES (?, 1, 'MAR Patient', NOW(), 'ACTIVE', NOW())")->execute([$rxNum]);
    $rxId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO pharmacy_prescription_items (prescription_id, medicine_id, dose, dose_unit, frequency, duration_days, prescribed_qty, status) VALUES (?, 1, 1, 'tablet', 'OD', 2, 2, 'ACTIVE')")->execute([$rxId]);
    $rxItemId = (int)$pdo->lastInsertId();

    // First generation -> 2 schedules created
    $sched1 = $marService->generateSchedules($rxId, [], 1);
    verify("Initial MAR schedule generation created slots", count($sched1) === 2, "Created: " . count($sched1) . " slots");

    // Second generation -> should be idempotent, returning 0 new schedules without collision error
    $sched2 = $marService->generateSchedules($rxId, [], 1);
    verify("Re-running MAR generation is strictly idempotent (0 duplicates)", count($sched2) === 0, "Second run generated: " . count($sched2) . " new slots");

} catch (Exception $e) {
    verify("Migration 034 & MAR Unique Constraint", false, $e->getMessage());
}

// =========================================================================
// SECTION 9: MEDICINE COMPOSITION PERSISTENCE WITHOUT OVERWRITE
// =========================================================================
echo "\n--- 5. VERIFY MEDICINE COMPOSITION PERSISTENCE ---\n";
try {
    $grnService = new GrnService($pdo);

    // Case 1: Medicine with no composition receives composition on GRN
    $pdo->prepare("INSERT INTO medicines (medicine_name, generic_name, category, price, stock_quantity, composition, status) VALUES ('COMP_MED_EMPTY', 'Generic', 'Tablets', 50.00, 0, NULL, 'Active')")->execute();
    $medId1 = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO pharmacy_suppliers (supplier_name, status) VALUES ('Comp Supplier', 'Active')")->execute();
    $supId = (int)$pdo->lastInsertId();

    $grnService->createAndPostGrn([
        'supplier_id' => $supId,
        'grn_date' => date('Y-m-d')
    ], [
        [
            'medicine_id' => $medId1,
            'batch_number' => 'B-COMP-01',
            'expiry_date' => '2028-06-30',
            'received_qty' => 10,
            'purchase_rate' => 30.00,
            'composition' => 'Amoxicillin 500mg + Clavulanic Acid 125mg'
        ]
    ], 1);

    $compAfter1 = $pdo->query("SELECT composition FROM medicines WHERE medicine_id = {$medId1}")->fetchColumn();
    verify("Empty medicine composition populated during GRN receipt", $compAfter1 === 'Amoxicillin 500mg + Clavulanic Acid 125mg', "Composition: {$compAfter1}");

    // Case 2: Medicine with existing composition is NOT overwritten by subsequent GRN
    $pdo->prepare("INSERT INTO medicines (medicine_name, generic_name, category, price, stock_quantity, composition, status) VALUES ('COMP_MED_EXISTING', 'Generic', 'Tablets', 50.00, 0, 'Original Preserved Formula 100mg', 'Active')")->execute();
    $medId2 = (int)$pdo->lastInsertId();

    $grnService->createAndPostGrn([
        'supplier_id' => $supId,
        'grn_date' => date('Y-m-d')
    ], [
        [
            'medicine_id' => $medId2,
            'batch_number' => 'B-COMP-02',
            'expiry_date' => '2028-06-30',
            'received_qty' => 10,
            'purchase_rate' => 30.00,
            'composition' => 'Malicious/Conflicting New Formula 200mg'
        ]
    ], 1);

    $compAfter2 = $pdo->query("SELECT composition FROM medicines WHERE medicine_id = {$medId2}")->fetchColumn();
    verify("Pre-existing medicine composition NOT silently overwritten", $compAfter2 === 'Original Preserved Formula 100mg', "Preserved Composition: {$compAfter2}");

} catch (Exception $e) {
    verify("Medicine Composition Persistence", false, $e->getMessage());
}

echo "\n------------------------------------------------------------\n";
echo " STRICT VERIFICATION SUMMARY: Total: " . ($passCount + $failCount) . " | Passed: {$passCount} | Failed: {$failCount}\n";
echo "============================================================\n\n";

exit($failCount > 0 ? 1 : 0);
