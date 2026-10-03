<?php
// modules/sales/return_invoice.php - Sales Return Invoice & Credit Note (With Full Live Editing & Persistence)
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/DocumentSequenceService.php';
require_once __DIR__ . '/../../app/Services/StockLedgerService.php';
require_once __DIR__ . '/../../app/Services/AuditService.php';
require_once __DIR__ . '/../../app/Services/SalesReturnService.php';

require_permission('pharmacy.sales_returns.view');

use Pharmacy\Services\SalesReturnService;

$returnService = new SalesReturnService($pdo);

// -----------------------------------------------------------------------------
// AJAX POST HANDLER: SAVE EDITED RETURN DETAILS TO DATABASE
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_return_edits') {
    header('Content-Type: application/json');
    $retId = (int)($_POST['return_id'] ?? 0);
    if ($retId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid return ID specified.']);
        exit;
    }

    try {
        $customerName = trim($_POST['customer_name'] ?? '');
        $returnDateRaw = trim($_POST['return_date'] ?? date('Y-m-d'));
        $returnDate = date('Y-m-d', strtotime($returnDateRaw));
        $paymentMode = trim($_POST['payment_mode'] ?? 'CASH');
        $reason = trim($_POST['reason'] ?? '');
        $totalRefundAmount = (float)($_POST['total_refund_amount'] ?? 0.0);
        $itemsJson = $_POST['items_json'] ?? '[]';
        $items = json_decode($itemsJson, true) ?: [];

        $pdo->beginTransaction();

        // 1. Update pharmacy_sales_returns header
        $updReturn = $pdo->prepare("
            UPDATE pharmacy_sales_returns SET
                customer_name = ?,
                return_date = ?,
                payment_mode = ?,
                reason = ?,
                total_refund_amount = ?,
                updated_at = NOW()
            WHERE return_id = ?
        ");
        $updReturn->execute([
            $customerName,
            $returnDate,
            $paymentMode,
            $reason,
            $totalRefundAmount,
            $retId
        ]);

        // 2. Update line items
        $updItem = $pdo->prepare("
            UPDATE pharmacy_sales_return_items SET
                return_quantity = ?,
                unit_price = ?,
                refund_amount = ?,
                tax_percent = ?
            WHERE item_id = ? AND return_id = ?
        ");

        $delItem = $pdo->prepare("DELETE FROM pharmacy_sales_return_items WHERE item_id = ? AND return_id = ?");

        $existingItemIds = [];
        $existingItemsStmt = $pdo->prepare("SELECT item_id FROM pharmacy_sales_return_items WHERE return_id = ?");
        $existingItemsStmt->execute([$retId]);
        $allDbItemIds = $existingItemsStmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($items as $it) {
            $itemId = (int)($it['item_id'] ?? 0);
            $qty = (int)($it['return_quantity'] ?? 1);
            $price = (float)($it['unit_price'] ?? 0.0);
            $refund = (float)($it['refund_amount'] ?? ($qty * $price));
            $taxPct = (float)($it['tax_percent'] ?? 0.0);

            if ($itemId > 0 && in_array($itemId, $allDbItemIds)) {
                $existingItemIds[] = $itemId;
                $updItem->execute([
                    $qty,
                    $price,
                    $refund,
                    $taxPct,
                    $itemId,
                    $retId
                ]);
            }
        }

        // Remove deleted items
        foreach ($allDbItemIds as $dbId) {
            if (!in_array($dbId, $existingItemIds)) {
                $delItem->execute([$dbId, $retId]);
            }
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Return invoice details saved successfully!'
        ]);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode([
            'success' => false,
            'message' => 'Failed to save changes: ' . $e->getMessage()
        ]);
        exit;
    }
}

// -----------------------------------------------------------------------------
// GET REQUEST: FETCH RETURN DETAILS & RENDER RETURN INVOICE
// -----------------------------------------------------------------------------
$returnId = (int)($_GET['id'] ?? $_GET['print_id'] ?? $_GET['return_id'] ?? 0);
$returnNumber = trim($_GET['return_no'] ?? '');

$return = null;
if ($returnId > 0) {
    $return = $returnService->getReturnDetails($returnId);
} elseif ($returnNumber !== '') {
    $stmt = $pdo->prepare("SELECT return_id FROM pharmacy_sales_returns WHERE return_number = ? LIMIT 1");
    $stmt->execute([$returnNumber]);
    $foundId = (int)$stmt->fetchColumn();
    if ($foundId > 0) {
        $return = $returnService->getReturnDetails($foundId);
    }
}

if (!$return) {
    die("<h1>Return Invoice Not Found</h1><p>The requested return invoice could not be located. <a href='returns.php'>Return to Register</a></p>");
}

// Calculate tax breakdown
$totalTax = 0;
$subtotal = 0;
foreach ($return['items'] as $item) {
    $taxAmt = ($item['refund_amount'] * $item['tax_percent']) / (100 + $item['tax_percent']);
    $totalTax += $taxAmt;
    $subtotal += ($item['refund_amount'] - $taxAmt);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Return Invoice - <?= htmlspecialchars($return['return_number']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #111;
            background-color: #525659;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 20px 12px;
        }

        /* Utility classes */
        .d-none {
            display: none !important;
        }
        .d-inline-flex {
            display: inline-flex !important;
        }
        .d-flex {
            display: flex !important;
        }

        /* Top Action Bar (Hidden on Print) */
        .no-print-bar {
            width: 100%;
            max-width: 800px;
            margin-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #ffffff;
            padding: 8px 14px;
            border-radius: 8px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.18);
            gap: 8px;
        }
        .no-print-bar .title {
            font-size: 12.5px;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }
        .no-print-bar .title .badge-bill {
            background: #f1f5f9;
            color: #0f172a;
            border: 1px solid #cbd5e1;
            padding: 2px 7px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 11px;
            font-weight: 700;
        }
        .btn-group {
            display: inline-flex;
            gap: 6px;
            align-items: center;
            flex-wrap: nowrap;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            height: 32px;
            padding: 0 12px;
            font-size: 11.5px;
            font-weight: 600;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #334155;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
            transition: all 0.15s ease;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .btn:hover {
            background: #f1f5f9;
            color: #0f172a;
        }
        .btn-primary {
            background: #0284c7;
            color: #ffffff;
            border-color: #0284c7;
        }
        .btn-primary:hover {
            background: #0369a1;
            border-color: #0369a1;
            color: #ffffff;
        }
        .btn-success {
            background: #16a34a;
            color: #ffffff;
            border-color: #16a34a;
        }
        .btn-success:hover {
            background: #15803d;
            border-color: #15803d;
            color: #ffffff;
        }
        .btn-warning {
            background: #d97706;
            color: #ffffff;
            border-color: #d97706;
        }
        .btn-warning:hover {
            background: #b45309;
            border-color: #b45309;
            color: #ffffff;
        }
        .btn-secondary {
            background: #f8fafc;
            color: #475569;
            border-color: #cbd5e1;
        }
        .btn-secondary:hover {
            background: #e2e8f0;
            color: #1e293b;
        }

        /* Notification Banner */
        #editNoticeBanner {
            width: 100%;
            max-width: 800px;
            background: #fefce8;
            color: #854d0e;
            border: 1px solid #fef08a;
            border-radius: 8px;
            padding: 8px 12px;
            margin-bottom: 12px;
            font-size: 11px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
        }

        /* Invoice Sheet */
        .invoice-wrapper {
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
        }
        .invoice-card {
            background: #ffffff;
            width: 100%;
            padding: 24px 28px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            border-radius: 6px;
            color: #000;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .fw-bold { font-weight: 700; }
        .fw-semibold { font-weight: 600; }
        .font-mono { font-family: SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }

        .dashed-divider {
            border-bottom: 1px dashed #444;
            margin: 10px 0;
            width: 100%;
        }
        .double-divider {
            border-bottom: 3px double #333;
            margin: 10px 0;
            width: 100%;
        }
        .dotted-divider {
            border-bottom: 1px dotted #888;
            margin: 6px 0;
            width: 100%;
        }

        /* Header */
        .invoice-header {
            text-align: center;
            padding-bottom: 4px;
        }
        .invoice-logo {
            max-height: 48px;
            max-width: 160px;
            object-fit: contain;
            margin-bottom: 4px;
        }
        .hospital-title {
            font-size: 16px;
            font-weight: 800;
            letter-spacing: 0.5px;
            margin: 2px 0 3px 0;
            text-transform: uppercase;
        }
        .hospital-sub {
            font-size: 11px;
            color: #333;
            line-height: 1.35;
        }
        .doc-title-badge {
            display: inline-block;
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #f87171;
            font-weight: 800;
            font-size: 12px;
            padding: 3px 14px;
            border-radius: 4px;
            margin-top: 6px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        /* Meta table */
        .meta-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            margin: 8px 0 10px 0;
        }
        .meta-table td {
            padding: 3px 0;
            vertical-align: top;
        }
        .meta-label {
            color: #555;
            font-weight: 600;
            white-space: nowrap;
            width: 90px;
        }
        .meta-val {
            color: #000;
        }

        /* Items table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }
        .items-table th {
            font-size: 11.5px;
            font-weight: 700;
            padding: 6px 4px;
            border-top: 1.5px dashed #444;
            border-bottom: 1.5px dashed #444;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .items-table td {
            padding: 6px 4px;
            font-size: 12px;
            vertical-align: middle;
        }
        .item-row {
            border-bottom: 1px dotted #e2e8f0;
        }
        .item-name {
            font-weight: 700;
            font-size: 12.5px;
            color: #000;
        }
        .batch-sub {
            font-size: 10.5px;
            color: #555;
            line-height: 1.3;
            margin-top: 2px;
        }
        .badge-status {
            display: inline-block;
            font-size: 9.5px;
            font-weight: 700;
            padding: 1px 6px;
            border-radius: 3px;
            background: #dcfce7;
            color: #166534;
        }

        /* Totals */
        .totals-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        .totals-table td {
            padding: 4px 4px;
            font-size: 12px;
        }
        .totals-table .total-highlight td {
            font-size: 15px;
            font-weight: 800;
            padding: 9px 4px;
            border-top: 1.5px dashed #444;
            border-bottom: 1.5px dashed #444;
            color: #b91c1c;
        }

        /* Signatures & Footer */
        .sign-area {
            display: flex;
            justify-content: space-between;
            margin-top: 28px;
            padding-top: 20px;
            font-size: 11px;
            color: #444;
        }
        .sign-box {
            text-align: center;
            width: 160px;
            border-top: 1px dotted #666;
            padding-top: 5px;
        }
        .invoice-footer {
            margin-top: 16px;
            padding-top: 8px;
            border-top: 1px dashed #444;
            text-align: center;
            font-size: 10.5px;
            color: #555;
            line-height: 1.35;
        }

        /* ----------------------------------------------------------------- */
        /* LIVE EDITABLE MODE STYLING                                        */
        /* ----------------------------------------------------------------- */
        .editable-field {
            display: inline-block;
            transition: all 0.15s ease;
        }
        .edit-mode-active .editable-field {
            background-color: rgba(2, 132, 199, 0.10) !important;
            border: none !important;
            border-radius: 3px !important;
            padding: 1px 4px !important;
            cursor: text !important;
            outline: none !important;
            min-width: 24px !important;
        }
        .edit-mode-active .editable-field:focus {
            background-color: rgba(2, 132, 199, 0.22) !important;
            outline: none !important;
            box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.25) !important;
        }

        .edit-ui-control {
            display: none;
        }
        .edit-mode-active .edit-ui-control {
            display: inline-flex !important;
            align-items: center;
            justify-content: center;
        }

        .btn-del-row {
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fca5a5;
            border-radius: 4px;
            cursor: pointer;
            font-size: 11px;
            padding: 2px 5px;
            line-height: 1;
        }
        .btn-del-row:hover {
            background: #ef4444;
            color: #ffffff;
        }

        @media print {
            @page {
                size: auto;
                margin: 4mm auto;
            }
            html, body {
                background: #ffffff !important;
                margin: 0 auto !important;
                padding: 0 !important;
                width: 100% !important;
                min-height: auto !important;
                display: block !important;
            }
            .no-print-bar, #editNoticeBanner, .edit-ui-control {
                display: none !important;
            }
            .editable-field {
                background: transparent !important;
                border: none !important;
                padding: 0 !important;
                box-shadow: none !important;
            }
            .invoice-wrapper {
                width: 100% !important;
                max-width: 100% !important;
                margin: 0 auto !important;
                padding: 0 !important;
            }
            .invoice-card {
                width: 100% !important;
                max-width: 100% !important;
                margin: 0 auto !important;
                padding: 6mm 4mm !important;
                box-shadow: none !important;
                border: none !important;
                border-radius: 0 !important;
            }
        }
    </style>
</head>
<body>

    <!-- Screen Action Controls (Hidden on Print) -->
    <div class="no-print-bar">
        <div class="title">
            <i class="bi bi-arrow-return-left text-primary"></i>
            <span>Return Invoice</span>
            <span class="badge-bill"><?= htmlspecialchars($return['return_number']) ?></span>
        </div>
        <div class="btn-group">
            <button type="button" class="btn btn-primary" onclick="window.print()" title="Print Credit Note">
                <i class="bi bi-printer"></i> Print Return Invoice
            </button>

            <!-- Edit Details Toggle Button -->
            <button type="button" id="btnEditToggle" class="btn btn-warning" onclick="toggleEditMode()" title="Enable editing details on this return invoice">
                <i class="bi bi-pencil-square"></i> Edit Details
            </button>

            <!-- Save Changes Button (Visible when editing) -->
            <button type="button" id="btnSaveEdits" class="btn btn-success d-none" onclick="saveReturnEditsToServer()" title="Save modified details to database">
                <i class="bi bi-check-circle-fill"></i> Save Changes
            </button>

            <!-- Cancel Button (Visible when editing) -->
            <button type="button" id="btnCancelEdit" class="btn btn-secondary d-none" onclick="cancelEditMode()" title="Cancel editing">
                <i class="bi bi-arrow-counterclockwise"></i> Cancel
            </button>

            <a href="returns.php" class="btn btn-secondary" title="Return to Sales Returns Register">
                <i class="bi bi-arrow-left"></i> Back to Returns
            </a>
            <button type="button" class="btn btn-secondary" onclick="window.close()" title="Close this window">
                <i class="bi bi-x-lg"></i> Close
            </button>
        </div>
    </div>

    <!-- Edit Mode Active Notification Banner (Hidden by default) -->
    <div id="editNoticeBanner" class="d-none">
        <div>
            <i class="bi bi-info-circle-fill me-1"></i>
            <strong>Edit Mode Active:</strong> Click date, customer name, refund mode, reason, quantities, or rates to edit. Totals recalculate live.
        </div>
        <div style="display: flex; gap: 6px;">
            <button type="button" class="btn btn-success btn-sm" onclick="saveReturnEditsToServer()" style="height: 26px; padding: 0 10px; font-size: 11px;">
                <i class="bi bi-check-circle-fill"></i> Save to Database
            </button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="cancelEditMode()" style="height: 26px; padding: 0 10px; font-size: 11px;">
                <i class="bi bi-arrow-counterclockwise"></i> Cancel
            </button>
        </div>
    </div>

    <div class="invoice-wrapper" id="returnInvoiceContainer">
        <div class="invoice-card">
            <!-- Header -->
            <div class="invoice-header">
                <img src="<?= BASE_URL ?>assets/images/vatsalya_logo.png" alt="Vatsalya Hospital" class="invoice-logo"><br>
                <div class="hospital-title">Vatsalya Central Pharmacy</div>
                <div class="hospital-sub">Ground Floor, Main Hospital Building, Station Road</div>
                <div class="hospital-sub">DL No: DL-2026-MH-01928 / 20B &amp; 21B</div>
                <div class="hospital-sub">GSTIN: 27AAAAA0000A1Z5 | Ph: +91 9876543210</div>
                <div class="doc-title-badge">SALES RETURN INVOICE / CREDIT NOTE</div>
            </div>

            <div class="dashed-divider"></div>

            <!-- Meta Information -->
            <table class="meta-table">
                <tr>
                    <td class="meta-label">Return No:</td>
                    <td class="meta-val font-mono fw-bold"><strong><?= htmlspecialchars($return['return_number']) ?></strong></td>
                    <td class="meta-label" style="text-align:right; padding-right:6px;">Return Date:</td>
                    <td class="meta-val" style="text-align:right;">
                        <strong><span class="editable-field" id="fieldReturnDate" data-field="return_date"><?= date('d/m/Y', strtotime($return['return_date'])) ?></span></strong>
                    </td>
                </tr>
                <tr>
                    <td class="meta-label">Original Sale:</td>
                    <td class="meta-val font-mono">
                        <a href="counter.php?print_id=<?= (int)$return['sale_id'] ?>" target="_blank" style="color:inherit; text-decoration:underline;">
                            <?= htmlspecialchars($return['sale_number']) ?>
                        </a>
                    </td>
                    <td class="meta-label" style="text-align:right; padding-right:6px;">Original Date:</td>
                    <td class="meta-val" style="text-align:right;">
                        <?= !empty($return['original_sale_date']) ? date('d/m/Y', strtotime($return['original_sale_date'])) : 'N/A' ?>
                    </td>
                </tr>
                <tr>
                    <td class="meta-label">Customer / Pt:</td>
                    <td class="meta-val fw-bold">
                        <span class="editable-field" id="fieldCustomerName" data-field="customer_name"><?= htmlspecialchars($return['patient_display_name']) ?></span>
                    </td>
                    <td class="meta-label" style="text-align:right; padding-right:6px;">Sale Type:</td>
                    <td class="meta-val" style="text-align:right;"><?= htmlspecialchars($return['sale_type']) ?></td>
                </tr>
                <?php if (!empty($return['patient_mobile']) || !empty($return['pharmacy_patient_no'])): ?>
                <tr>
                    <td class="meta-label">Patient Info:</td>
                    <td class="meta-val" colspan="3">
                        <?= !empty($return['pharmacy_patient_no']) ? ('ID: ' . htmlspecialchars($return['pharmacy_patient_no']) . ' | ') : '' ?>
                        <?= !empty($return['patient_mobile']) ? ('Mobile: ' . htmlspecialchars($return['patient_mobile'])) : '' ?>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td class="meta-label">Processed By:</td>
                    <td class="meta-val"><?= htmlspecialchars($return['created_by_name'] ?? $return['created_by_username'] ?? 'Staff') ?></td>
                    <td class="meta-label" style="text-align:right; padding-right:6px;">Refund Mode:</td>
                    <td class="meta-val fw-bold" style="text-align:right;">
                        <span class="editable-field" id="fieldRefundMode" data-field="payment_mode"><?= htmlspecialchars($return['payment_mode']) ?></span>
                    </td>
                </tr>
                <tr>
                    <td class="meta-label">Reason:</td>
                    <td class="meta-val" colspan="3" style="color: #444; font-style: italic;">
                        <span class="editable-field" id="fieldReason" data-field="reason"><?= htmlspecialchars($return['reason'] ?: 'Customer return') ?></span>
                    </td>
                </tr>
            </table>

            <!-- Returned Items Table -->
            <table class="items-table" id="returnItemsTable">
                <thead>
                    <tr>
                        <th class="text-left" style="width: 44%;">Medicine / Batch</th>
                        <th class="text-center" style="width: 14%;">Ret Qty</th>
                        <th class="text-right" style="width: 18%;">Unit Rate</th>
                        <th class="text-right" style="width: 20%;">Refund (₹)</th>
                        <th class="edit-ui-control text-center" style="width: 4%;"></th>
                    </tr>
                </thead>
                <tbody id="returnItemsBody">
                    <?php 
                    $totalQty = 0;
                    foreach ($return['items'] as $idx => $it): 
                        $totalQty += (int)$it['return_quantity'];
                    ?>
                    <tr class="item-row return-item-row" data-item-id="<?= (int)$it['item_id'] ?>" data-row-idx="<?= $idx ?>">
                        <td>
                            <div class="item-name"><?= htmlspecialchars($it['medicine_name']) ?></div>
                            <div class="batch-sub">
                                Batch: <strong><?= htmlspecialchars($it['batch_number'] ?? 'N/A') ?></strong> | 
                                Exp: <?= !empty($it['expiry_date']) ? date('m/y', strtotime($it['expiry_date'])) : 'N/A' ?>
                                | GST: <span class="editable-field item-tax-pct" data-field="tax_percent" oninput="onReturnItemChange(<?= $idx ?>)"><?= (float)$it['tax_percent'] ?></span>%
                            </div>
                            <div class="batch-sub" style="margin-top:2px;">
                                <span class="badge-status"><?= htmlspecialchars(str_replace('_', ' ', $it['restock_decision'])) ?></span>
                            </div>
                        </td>
                        <td class="text-center fw-bold" style="vertical-align: middle;">
                            <span class="editable-field item-ret-qty" data-field="return_quantity" oninput="onReturnItemChange(<?= $idx ?>)"><?= (int)$it['return_quantity'] ?></span>
                        </td>
                        <td class="text-right" style="vertical-align: middle;">
                            ₹<span class="editable-field item-unit-price" data-field="unit_price" oninput="onReturnItemChange(<?= $idx ?>)"><?= number_format((float)$it['unit_price'], 2, '.', '') ?></span>
                        </td>
                        <td class="text-right fw-bold" style="vertical-align: middle;">
                            ₹<span class="item-refund-amt"><?= number_format((float)$it['refund_amount'], 2) ?></span>
                        </td>
                        <td class="edit-ui-control text-center" style="vertical-align: middle;">
                            <button type="button" class="btn-del-row" onclick="deleteReturnRow(<?= $idx ?>)" title="Remove item"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Totals & Financial Breakdown -->
            <div class="dashed-divider"></div>
            <table class="totals-table">
                <tr>
                    <td class="text-left">Total Lines Returned:</td>
                    <td class="text-right font-mono" id="lblTotalLines"><?= count($return['items']) ?> item(s) / <?= $totalQty ?> unit(s)</td>
                </tr>
                <tr>
                    <td class="text-left">Taxable Return Value:</td>
                    <td class="text-right font-mono" id="lblTaxableVal">₹<?= number_format($subtotal, 2) ?></td>
                </tr>
                <tr>
                    <td class="text-left">Applicable GST / Tax Refund:</td>
                    <td class="text-right font-mono" id="lblTaxVal">₹<?= number_format($totalTax, 2) ?></td>
                </tr>
                <tr class="total-highlight">
                    <td class="text-left">TOTAL REFUND AMOUNT:</td>
                    <td class="text-right font-mono" id="lblTotalRefund">₹<?= number_format((float)$return['total_refund_amount'], 2) ?></td>
                </tr>
                <tr>
                    <td class="text-left">Refund Disbursement Status:</td>
                    <td class="text-right fw-bold" style="color: #166534;" id="lblRefundStatus">
                        <?= htmlspecialchars($return['refund_status']) ?> (<span id="lblRefundModeText"><?= htmlspecialchars($return['payment_mode']) ?></span>)
                    </td>
                </tr>
            </table>

            <!-- Signature Section -->
            <div class="sign-area">
                <div class="sign-box">
                    Customer Signature
                </div>
                <div class="sign-box">
                    Authorized Pharmacist
                </div>
            </div>

            <!-- Footer -->
            <div class="invoice-footer">
                <div>This Credit Note is issued against medication returned to Vatsalya Central Pharmacy.</div>
                <div>Returned items have been audited and inspected under standard pharmacy SOP.</div>
                <div style="margin-top: 4px; font-size: 9px; color: #888;">
                    Printed on: <?= date('d/m/Y H:i:s') ?> | ID: <?= htmlspecialchars($return['return_number']) ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ----------------------------------------------------------------- -->
    <!-- JAVASCRIPT: LIVE EDITING, AUTO-CALCULATION & SERVER PERSISTENCE   -->
    <!-- ----------------------------------------------------------------- -->
    <script>
        let isEditMode = false;
        const currentReturnId = <?= (int)$return['return_id'] ?>;

        function toggleEditMode() {
            isEditMode = !isEditMode;
            const container = document.getElementById('returnInvoiceContainer');
            const editNotice = document.getElementById('editNoticeBanner');
            const btnToggle = document.getElementById('btnEditToggle');
            const btnSave = document.getElementById('btnSaveEdits');
            const btnCancel = document.getElementById('btnCancelEdit');

            if (isEditMode) {
                container.classList.add('edit-mode-active');
                if (editNotice) editNotice.classList.remove('d-none');
                if (btnToggle) btnToggle.classList.add('d-none');
                if (btnSave) btnSave.classList.remove('d-none');
                if (btnCancel) btnCancel.classList.remove('d-none');

                document.querySelectorAll('.editable-field').forEach(el => {
                    el.setAttribute('contenteditable', 'true');
                });
            } else {
                container.classList.remove('edit-mode-active');
                if (editNotice) editNotice.classList.add('d-none');
                if (btnToggle) btnToggle.classList.remove('d-none');
                if (btnSave) btnSave.classList.add('d-none');
                if (btnCancel) btnCancel.classList.add('d-none');

                document.querySelectorAll('.editable-field').forEach(el => {
                    el.setAttribute('contenteditable', 'false');
                });
            }
        }

        function cancelEditMode() {
            if (confirm('Cancel editing and restore original return invoice details?')) {
                window.location.reload();
            }
        }

        function onReturnItemChange(rowIdx) {
            const row = document.querySelector(`.return-item-row[data-row-idx="${rowIdx}"]`);
            if (!row) return;

            const qty = parseFloat(row.querySelector('.item-ret-qty')?.innerText || 1) || 0;
            const price = parseFloat(row.querySelector('.item-unit-price')?.innerText || 0) || 0;
            const lineRefund = qty * price;

            if (row.querySelector('.item-refund-amt')) {
                row.querySelector('.item-refund-amt').innerText = lineRefund.toFixed(2);
            }

            recalculateReturnTotals();
        }

        function recalculateReturnTotals() {
            let totalRefund = 0.0;
            let totalTax = 0.0;
            let totalUnits = 0;
            let itemCount = 0;

            document.querySelectorAll('.return-item-row').forEach(row => {
                const qty = parseFloat(row.querySelector('.item-ret-qty')?.innerText || 0) || 0;
                const price = parseFloat(row.querySelector('.item-unit-price')?.innerText || 0) || 0;
                const taxPct = parseFloat(row.querySelector('.item-tax-pct')?.innerText || 0) || 0;

                const lineRefund = qty * price;
                const lineTax = (lineRefund * taxPct) / (100 + taxPct);

                totalRefund += lineRefund;
                totalTax += lineTax;
                totalUnits += qty;
                itemCount++;
            });

            const taxableVal = Math.max(0, totalRefund - totalTax);

            if (document.getElementById('lblTotalLines')) {
                document.getElementById('lblTotalLines').innerText = `${itemCount} item(s) / ${totalUnits} unit(s)`;
            }
            if (document.getElementById('lblTaxableVal')) {
                document.getElementById('lblTaxableVal').innerText = `₹${taxableVal.toFixed(2)}`;
            }
            if (document.getElementById('lblTaxVal')) {
                document.getElementById('lblTaxVal').innerText = `₹${totalTax.toFixed(2)}`;
            }
            if (document.getElementById('lblTotalRefund')) {
                document.getElementById('lblTotalRefund').innerText = `₹${totalRefund.toFixed(2)}`;
            }

            const refundMode = (document.getElementById('fieldRefundMode')?.innerText || 'CASH').trim();
            if (document.getElementById('lblRefundModeText')) {
                document.getElementById('lblRefundModeText').innerText = refundMode;
            }
        }

        function deleteReturnRow(rowIdx) {
            const row = document.querySelector(`.return-item-row[data-row-idx="${rowIdx}"]`);
            if (row) {
                row.remove();
                recalculateReturnTotals();
            }
        }

        function saveReturnEditsToServer() {
            const btnSave = document.getElementById('btnSaveEdits');
            btnSave.disabled = true;
            btnSave.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';

            const customerName = (document.getElementById('fieldCustomerName')?.innerText || '').trim();
            const returnDateStr = (document.getElementById('fieldReturnDate')?.innerText || '').trim();
            const refundMode = (document.getElementById('fieldRefundMode')?.innerText || 'CASH').trim();
            const reason = (document.getElementById('fieldReason')?.innerText || '').trim();

            let totalRefundAmount = 0.0;
            const items = [];

            document.querySelectorAll('.return-item-row').forEach(row => {
                const itemId = parseInt(row.getAttribute('data-item-id') || '0', 10);
                const qty = parseFloat(row.querySelector('.item-ret-qty')?.innerText || 1) || 1;
                const price = parseFloat(row.querySelector('.item-unit-price')?.innerText || 0) || 0;
                const taxPct = parseFloat(row.querySelector('.item-tax-pct')?.innerText || 0) || 0;
                const refund = qty * price;

                items.push({
                    item_id: itemId,
                    return_quantity: qty,
                    unit_price: price,
                    tax_percent: taxPct,
                    refund_amount: refund
                });

                totalRefundAmount += refund;
            });

            // Format date string to YYYY-MM-DD
            let formattedDate = '<?= date('Y-m-d', strtotime($return['return_date'])) ?>';
            if (returnDateStr.includes('/')) {
                const parts = returnDateStr.split('/');
                if (parts.length === 3) {
                    formattedDate = `${parts[2]}-${parts[1].padStart(2, '0')}-${parts[0].padStart(2, '0')}`;
                }
            } else if (returnDateStr.includes('-')) {
                formattedDate = returnDateStr;
            }

            const formData = new FormData();
            formData.append('action', 'save_return_edits');
            formData.append('return_id', currentReturnId);
            formData.append('customer_name', customerName);
            formData.append('return_date', formattedDate);
            formData.append('payment_mode', refundMode);
            formData.append('reason', reason);
            formData.append('total_refund_amount', totalRefundAmount);
            formData.append('items_json', JSON.stringify(items));

            fetch('return_invoice.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                btnSave.disabled = false;
                btnSave.innerHTML = '<i class="bi bi-check-circle-fill"></i> Save Changes';
                if (data.success) {
                    alert('✓ Return invoice details saved successfully to database!');
                    window.location.reload();
                } else {
                    alert('Error saving changes: ' + (data.message || 'Unknown error.'));
                }
            })
            .catch(err => {
                btnSave.disabled = false;
                btnSave.innerHTML = '<i class="bi bi-check-circle-fill"></i> Save Changes';
                alert('Connection error while saving changes. Please try again.');
            });
        }
    </script>
</body>
</html>
