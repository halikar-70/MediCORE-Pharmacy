<?php
// modules/purchases/orders.php - Purchase Order Management & Workflow

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/AuditService.php';
require_once __DIR__ . '/../../app/Services/DocumentSequenceService.php';
require_once __DIR__ . '/../../app/Services/SupplierService.php';
require_once __DIR__ . '/../../app/Services/PurchaseOrderService.php';

require_permission('pharmacy.purchase_orders.view');

use Pharmacy\Services\SupplierService;
use Pharmacy\Services\PurchaseOrderService;

$poService = new PurchaseOrderService($pdo);
$supplierService = new SupplierService($pdo);

$page_title = 'Purchase Orders';
$user = auth_user();
$userId = (int)($user['id'] ?? 1);

$canCreate = has_permission('pharmacy.purchase_orders.create');
$canApprove = has_permission('pharmacy.purchase_orders.approve');
$canCancel = has_permission('pharmacy.purchase_orders.cancel');
$canEdit = has_permission('pharmacy.purchase_orders.edit') || has_permission('pharmacy.purchase_orders.create');

$feedback = null;
$error = null;

// Handle Form Submissions (Create / Edit / Approve / Cancel)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid or expired security token. Please try again.";
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'create_po') {
                if (!$canCreate) throw new Exception("Permission denied: Cannot create purchase order.");

                $header = [
                    'supplier_id'            => (int)($_POST['supplier_id'] ?? 0),
                    'po_date'                => $_POST['po_date'] ?? date('Y-m-d'),
                    'expected_delivery_date' => !empty($_POST['expected_delivery_date']) ? $_POST['expected_delivery_date'] : null,
                    'payment_terms'          => $_POST['payment_terms'] ?? '30 Days Net',
                    'notes'                  => $_POST['notes'] ?? null,
                    'status'                 => (!empty($_POST['auto_approve']) && $canApprove) ? 'APPROVED' : 'DRAFT'
                ];

                $items = [];
                $medIds = $_POST['item_medicine_id'] ?? [];
                $quantities = $_POST['item_qty'] ?? [];
                $rates = $_POST['item_rate'] ?? [];
                $discounts = $_POST['item_discount'] ?? [];
                $gsts = $_POST['item_gst'] ?? [];
                $mrps = $_POST['item_mrp'] ?? [];
                $packs = $_POST['item_pack'] ?? [];

                foreach ($medIds as $k => $mId) {
                    $mId = (int)$mId;
                    $qty = (int)($quantities[$k] ?? 0);
                    if ($mId > 0 && $qty > 0) {
                        $items[] = [
                            'medicine_id'         => $mId,
                            'pack_size'           => $packs[$k] ?? '1',
                            'requested_qty'       => $qty,
                            'free_qty'            => 0,
                            'purchase_rate'       => (float)($rates[$k] ?? 0.00),
                            'discount_percent'    => (float)($discounts[$k] ?? 0.00),
                            'gst_percent'         => (float)($gsts[$k] ?? 0.00),
                            'expected_mrp'        => (float)($mrps[$k] ?? 0.00),
                            'expected_sale_price' => (float)($mrps[$k] ?? 0.00),
                        ];
                    }
                }

                if (empty($items)) {
                    throw new Exception("Please add at least one medicine with valid quantity.");
                }

                $newPoId = $poService->createPurchaseOrder($header, $items, $userId);
                $poRec = $poService->getPurchaseOrder($newPoId);
                $poNumStr = $poRec ? htmlspecialchars($poRec['po_number']) : "#{$newPoId}";
                $feedback = "Purchase Order {$poNumStr} created successfully. <a href='orders.php?view_id={$newPoId}' class='alert-link fw-bold ms-2 text-decoration-underline'><i class='ti ti-eye'></i> View Order Details</a>";
            } elseif ($action === 'edit_po') {
                if (!$canEdit) throw new Exception("Permission denied: Cannot edit purchase order.");
                $poId = (int)($_POST['po_id'] ?? 0);
                $header = [
                    'supplier_id'            => (int)($_POST['supplier_id'] ?? 0),
                    'po_date'                => $_POST['po_date'] ?? date('Y-m-d'),
                    'expected_delivery_date' => !empty($_POST['expected_delivery_date']) ? $_POST['expected_delivery_date'] : null,
                    'payment_terms'          => $_POST['payment_terms'] ?? '30 Days Net',
                    'notes'                  => $_POST['notes'] ?? null,
                ];

                $items = [];
                $medIds = $_POST['item_medicine_id'] ?? [];
                $quantities = $_POST['item_qty'] ?? [];
                $rates = $_POST['item_rate'] ?? [];
                $discounts = $_POST['item_discount'] ?? [];
                $gsts = $_POST['item_gst'] ?? [];
                $mrps = $_POST['item_mrp'] ?? [];
                $packs = $_POST['item_pack'] ?? [];

                foreach ($medIds as $k => $mId) {
                    $mId = (int)$mId;
                    $qty = (int)($quantities[$k] ?? 0);
                    if ($mId > 0 && $qty > 0) {
                        $items[] = [
                            'medicine_id'         => $mId,
                            'pack_size'           => $packs[$k] ?? '1',
                            'requested_qty'       => $qty,
                            'free_qty'            => 0,
                            'purchase_rate'       => (float)($rates[$k] ?? 0.00),
                            'discount_percent'    => (float)($discounts[$k] ?? 0.00),
                            'gst_percent'         => (float)($gsts[$k] ?? 0.00),
                            'expected_mrp'        => (float)($mrps[$k] ?? 0.00),
                            'expected_sale_price' => (float)($mrps[$k] ?? 0.00),
                        ];
                    }
                }

                if (empty($items)) {
                    throw new Exception("Please add at least one medicine with valid quantity.");
                }

                $poService->updatePurchaseOrder($poId, $header, $items, $userId);
                $poRec = $poService->getPurchaseOrder($poId);
                $poNumStr = $poRec ? htmlspecialchars($poRec['po_number']) : "#{$poId}";
                $feedback = "Purchase Order {$poNumStr} updated successfully. <a href='orders.php?view_id={$poId}' class='alert-link fw-bold ms-2 text-decoration-underline'><i class='ti ti-eye'></i> View Order Details</a>";
            } elseif ($action === 'approve_po') {
                if (!$canApprove) throw new Exception("Permission denied: Cannot approve purchase order.");
                $poId = (int)($_POST['po_id'] ?? 0);
                $poService->approvePurchaseOrder($poId, $userId);
                $feedback = "Purchase Order approved successfully. <a href='orders.php?view_id={$poId}' class='alert-link fw-bold ms-2 text-decoration-underline'><i class='ti ti-eye'></i> View Order Details</a>";
            } elseif ($action === 'cancel_po') {
                if (!$canCancel) throw new Exception("Permission denied: Cannot cancel purchase order.");
                $poId = (int)($_POST['po_id'] ?? 0);
                $reason = trim($_POST['cancellation_reason'] ?? 'Cancelled by user');
                $poService->cancelPurchaseOrder($poId, $reason, $userId);
                $feedback = "Purchase Order has been cancelled.";
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Filters
$supplierId = !empty($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : null;
$status = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');
$filters = [];
if ($supplierId) $filters['supplier_id'] = $supplierId;
if ($status !== '' && $status !== 'All') $filters['status'] = $status;
if ($search !== '') $filters['search'] = $search;

$orders = $poService->listPurchaseOrders($filters);
$activeSuppliers = $pdo->query("SELECT supplier_id, supplier_name, supplier_code, payment_terms FROM pharmacy_suppliers WHERE status = 'Active' ORDER BY supplier_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$medicinesCatalog = $pdo->query("
    SELECT m.medicine_id, m.medicine_name, m.generic_name, m.dosage_form, m.strength, m.pack_size, m.gst_percent, m.purchase_price, m.price as mrp,
           COALESCE((
               SELECT SUM(mb.quantity_available)
               FROM medicine_batches mb
               WHERE mb.medicine_id = m.medicine_id
                 AND mb.status = 'Active'
                 AND mb.quantity_available > 0
                 AND mb.expiry_date >= CURDATE()
           ), 0) as available_stock
    FROM medicines m 
    WHERE m.status = 'Active' 
    ORDER BY m.medicine_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// View PO Details Modal
$viewPo = null;
if (!empty($_GET['view_id'])) {
    $viewPo = $poService->getPurchaseOrder((int)$_GET['view_id']);
}

// Edit PO Mode
$editPo = null;
if (!empty($_GET['edit_id']) && $canEdit) {
    $candPo = $poService->getPurchaseOrder((int)$_GET['edit_id']);
    if ($candPo && in_array($candPo['status'], ['DRAFT', 'SUBMITTED', 'APPROVED'], true) && (int)($candPo['total_received_units'] ?? 0) === 0) {
        $editPo = $candPo;
    } else {
        $error = "This purchase order cannot be edited (it may be cancelled, closed, or already received).";
    }
}

// AJAX Handler: Save PO Print Edits
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_po_print_edits') {
    header('Content-Type: application/json');
    $pId = (int)($_POST['po_id'] ?? 0);
    if ($pId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid PO ID specified.']);
        exit;
    }

    try {
        $expDelivery = !empty($_POST['expected_delivery_date']) ? date('Y-m-d', strtotime($_POST['expected_delivery_date'])) : null;
        $paymentTerms = trim($_POST['payment_terms'] ?? '30 Days Net');
        $notes = trim($_POST['notes'] ?? '');
        $itemsJson = $_POST['items_json'] ?? '[]';
        $items = json_decode($itemsJson, true) ?: [];

        $pdo->beginTransaction();

        $calcSubtotal = 0.0;
        $calcTax = 0.0;
        $calcTotal = 0.0;

        $updItem = $pdo->prepare("
            UPDATE pharmacy_purchase_order_items SET
                requested_qty = ?,
                purchase_rate = ?,
                discount_percent = ?,
                gst_percent = ?,
                line_total = ?
            WHERE po_item_id = ? AND po_id = ?
        ");

        foreach ($items as $it) {
            $itemId = (int)($it['po_item_id'] ?? 0);
            $qty = (int)($it['requested_qty'] ?? 1);
            $rate = (float)($it['purchase_rate'] ?? 0.0);
            $discPct = (float)($it['discount_percent'] ?? 0.0);
            $gstPct = (float)($it['gst_percent'] ?? 0.0);

            $discAmt = ($qty * $rate) * ($discPct / 100);
            $taxable = ($qty * $rate) - $discAmt;
            $taxAmt = $taxable * ($gstPct / 100);
            $lineTot = round($taxable + $taxAmt, 2);

            $calcSubtotal += $taxable;
            $calcTax += $taxAmt;
            $calcTotal += $lineTot;

            if ($itemId > 0) {
                $updItem->execute([$qty, $rate, $discPct, $gstPct, $lineTot, $itemId, $pId]);
            }
        }

        $calcSubtotal = round($calcSubtotal, 2);
        $calcTax = round($calcTax, 2);
        $calcTotal = round($calcTotal, 2);

        $updPo = $pdo->prepare("
            UPDATE pharmacy_purchase_orders SET
                expected_delivery_date = ?,
                payment_terms = ?,
                notes = ?,
                subtotal_amount = ?,
                tax_amount = ?,
                total_amount = ?,
                updated_at = NOW()
            WHERE po_id = ?
        ");
        $updPo->execute([$expDelivery, $paymentTerms, $notes, $calcSubtotal, $calcTax, $calcTotal, $pId]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Purchase order details updated successfully!'
        ]);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode([
            'success' => false,
            'message' => 'Failed to save PO edits: ' . $e->getMessage()
        ]);
        exit;
    }
}

// Print mode
$isPrint = isset($_GET['print']) && $viewPo;

if ($isPrint) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Purchase Order - <?= htmlspecialchars($viewPo['po_number']) ?></title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
        <style>
            * {
                box-sizing: border-box;
                margin: 0;
                padding: 0;
            }
            body {
                font-family: Arial, "Helvetica Neue", Helvetica, sans-serif;
                font-size: 11.5px;
                color: #000000;
                background-color: #525659;
                min-height: 100vh;
                display: flex;
                flex-direction: column;
                align-items: center;
                padding: 20px 10px;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            /* Utility classes */
            .d-none { display: none !important; }
            .d-inline-flex { display: inline-flex !important; }
            .d-flex { display: flex !important; }

            /* Top Action Bar (Hidden on Print) */
            .no-print-bar {
                width: 100%;
                max-width: 920px;
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
                gap: 5px;
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
                max-width: 920px;
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

            /* PO Sheet Container */
            .po-sheet {
                width: 100%;
                max-width: 920px;
                background-color: #ffffff;
                padding: 18px 22px;
                border: 1.5px solid #000000;
                box-shadow: 0 10px 30px rgba(0,0,0,0.3);
                color: #000000;
                position: relative;
            }

            .po-doc-title {
                text-align: center;
                font-size: 13.5px;
                font-weight: 800;
                letter-spacing: 0.5px;
                padding-bottom: 6px;
                margin-bottom: 6px;
                border-bottom: 1.5px solid #000000;
                text-transform: uppercase;
            }

            /* 3-Box Header Information Grid */
            .header-grid {
                display: grid;
                grid-template-columns: 38% 28% 34%;
                border: 1px solid #000000;
                margin-bottom: 6px;
                font-size: 11.5px;
                line-height: 1.4;
            }
            .header-box {
                padding: 6px 8px;
            }
            .header-box:not(:last-child) {
                border-right: 1px solid #000000;
            }
            .shop-name {
                font-size: 14px;
                font-weight: 800;
                letter-spacing: 0.3px;
                margin-bottom: 2px;
            }
            .header-line {
                display: flex;
                margin-bottom: 2px;
                align-items: baseline;
            }
            .header-label {
                font-weight: 700;
                white-space: nowrap;
                min-width: 85px;
            }
            .header-val {
                font-weight: 500;
                word-break: break-word;
                flex-grow: 1;
            }

            /* Main Table */
            .table-container {
                width: 100%;
                min-height: 280px;
                margin-bottom: 6px;
            }
            .po-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 11.5px;
                table-layout: fixed;
            }
            .po-table th {
                border: 1px solid #000000;
                padding: 5px 3px;
                font-weight: 700;
                text-align: center;
                background: #f8fafc;
                text-transform: uppercase;
                font-size: 11px;
            }
            .po-table td {
                border-left: 1px solid #000000;
                border-right: 1px solid #000000;
                border-bottom: 1px solid #e2e8f0;
                border-top: none;
                padding: 4px 4px;
                font-size: 11px;
                line-height: 1.3;
                vertical-align: middle;
                box-sizing: border-box;
            }
            .po-table tr.item-row td {
                height: 24px;
            }
            .text-center { text-align: center; }
            .text-right { text-align: right; }
            .text-left { text-align: left; }
            .fw-bold { font-weight: 700; }
            .font-mono { font-family: monospace; }

            /* Footer Summary Grid */
            .footer-grid {
                display: grid;
                grid-template-columns: 55% 45%;
                border: 1px solid #000000;
                font-size: 11.5px;
                margin-top: 6px;
            }
            .footer-box {
                padding: 6px 10px;
                display: flex;
                flex-direction: column;
                justify-content: space-between;
            }
            .footer-box:not(:last-child) {
                border-right: 1px solid #000000;
            }
            .summary-line {
                display: flex;
                justify-content: space-between;
                margin-bottom: 3px;
                line-height: 1.35;
            }
            .summary-label {
                font-weight: 600;
            }
            .summary-val {
                font-weight: 700;
            }
            .grand-total-row {
                font-size: 13.5px;
                font-weight: 800;
                border-top: 1.5px solid #000000;
                padding-top: 4px;
                margin-top: 4px;
            }

            /* Signatures Area */
            .sign-grid {
                display: grid;
                grid-template-columns: 33% 33% 34%;
                border: 1px solid #000000;
                border-top: none;
                padding: 18px 10px 6px 10px;
                text-align: center;
                font-size: 10.5px;
            }
            .sign-col {
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                height: 50px;
            }
            .sign-line {
                border-top: 1px dotted #444;
                margin: 0 15px;
                padding-top: 3px;
                font-weight: 700;
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
                    size: A4 portrait;
                    margin: 5mm;
                }
                html, body {
                    background: #ffffff !important;
                    margin: 0 !important;
                    padding: 0 !important;
                    width: 100% !important;
                    min-height: auto !important;
                    display: block !important;
                    font-size: 10px !important;
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
                .po-sheet {
                    max-width: 100% !important;
                    width: 100% !important;
                    margin: 0 auto !important;
                    padding: 4mm !important;
                    box-shadow: none !important;
                    border: 1px solid #000000 !important;
                    page-break-after: avoid;
                }
            }
        </style>
    </head>
    <body>

        <!-- Screen Action Controls (Hidden on Print) -->
        <div class="no-print-bar">
            <div class="title">
                <i class="bi bi-file-earmark-spreadsheet text-primary"></i>
                <span>Purchase Order</span>
                <span class="badge-bill"><?= htmlspecialchars($viewPo['po_number']) ?></span>
            </div>
            <div class="btn-group">
                <button type="button" class="btn btn-primary" onclick="window.print()" title="Print this Purchase Order">
                    <i class="bi bi-printer"></i> Print Purchase Order
                </button>

                <!-- Edit Details Toggle Button -->
                <button type="button" id="btnEditToggle" class="btn btn-warning" onclick="toggleEditMode()" title="Enable editing details on this purchase order">
                    <i class="bi bi-pencil-square"></i> Edit Details
                </button>

                <!-- Save Changes Button (Visible when editing) -->
                <button type="button" id="btnSaveEdits" class="btn btn-success d-none" onclick="savePoEditsToServer()" title="Save modified details to database">
                    <i class="bi bi-check-circle-fill"></i> Save Changes
                </button>

                <!-- Cancel Button (Visible when editing) -->
                <button type="button" id="btnCancelEdit" class="btn btn-secondary d-none" onclick="cancelEditMode()" title="Cancel editing">
                    <i class="bi bi-arrow-counterclockwise"></i> Cancel
                </button>

                <a href="orders.php" class="btn btn-secondary" title="Return to Orders list">
                    <i class="bi bi-arrow-left"></i> Back to Orders
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
                <strong>Edit Mode Active:</strong> Click delivery date, payment terms, requested quantities, rates, discounts, or GST % to edit. Totals recalculate live.
            </div>
            <div style="display: flex; gap: 6px;">
                <button type="button" class="btn btn-success btn-sm" onclick="savePoEditsToServer()" style="height: 26px; padding: 0 10px; font-size: 11px;">
                    <i class="bi bi-check-circle-fill"></i> Save to Database
                </button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="cancelEditMode()" style="height: 26px; padding: 0 10px; font-size: 11px;">
                    <i class="bi bi-arrow-counterclockwise"></i> Cancel
                </button>
            </div>
        </div>

        <div class="po-sheet" id="poSheetContainer">
            <!-- Center Top Document Heading -->
            <div class="po-doc-title">PURCHASE ORDER &amp; PROCUREMENT BILL</div>

            <!-- 3-Box Header Information Grid -->
            <div class="header-grid">
                <!-- Box 1: Hospital & Pharmacy Details -->
                <div class="header-box">
                    <div class="shop-name">VATSALYA CENTRAL PHARMACY</div>
                    <div>Vatsalya Hospital | Central Procurement Dept</div>
                    <div>22, 2A, Mundhwa-Kharadi Rd, Kharadi, Pune-411014</div>
                    <div style="margin-top: 2px;"><strong>DL No.</strong> : 20-276335,21-276336-MH-PZ1</div>
                    <div><strong>GSTIN</strong> : 27AAQFV6256M1Z8</div>
                </div>

                <!-- Box 2: PO Details -->
                <div class="header-box">
                    <div class="header-line">
                        <span class="header-label">PO No.</span>
                        <span class="header-val">: <strong><?= htmlspecialchars($viewPo['po_number']) ?></strong></span>
                    </div>
                    <div class="header-line">
                        <span class="header-label">PO Date</span>
                        <span class="header-val">: <?= date('d-M-Y', strtotime($viewPo['po_date'])) ?></span>
                    </div>
                    <div class="header-line">
                        <span class="header-label">Status</span>
                        <span class="header-val">: <strong><?= htmlspecialchars($viewPo['status']) ?></strong></span>
                    </div>
                    <div class="header-line">
                        <span class="header-label">Delivery</span>
                        <span class="header-val">: <span class="editable-field" id="fieldExpDelivery" data-field="expected_delivery_date"><?= !empty($viewPo['expected_delivery_date']) ? date('d-M-Y', strtotime($viewPo['expected_delivery_date'])) : 'Immediate' ?></span></span>
                    </div>
                    <div class="header-line">
                        <span class="header-label">Terms</span>
                        <span class="header-val">: <span class="editable-field" id="fieldPaymentTerms" data-field="payment_terms"><?= htmlspecialchars($viewPo['payment_terms'] ?? '30 Days Net') ?></span></span>
                    </div>
                </div>

                <!-- Box 3: Vendor / Supplier Details -->
                <div class="header-box">
                    <div class="header-line">
                        <span class="header-label">Supplier</span>
                        <span class="header-val">: <strong><?= htmlspecialchars($viewPo['supplier_name']) ?></strong></span>
                    </div>
                    <div class="header-line">
                        <span class="header-label">Address</span>
                        <span class="header-val">: <?= htmlspecialchars($viewPo['supplier_address'] ?? 'N/A') ?>, <?= htmlspecialchars($viewPo['supplier_city'] ?? '') ?></span>
                    </div>
                    <div class="header-line">
                        <span class="header-label">GSTIN</span>
                        <span class="header-val font-mono">: <?= htmlspecialchars($viewPo['supplier_gstin'] ?? 'N/A') ?></span>
                    </div>
                    <div class="header-line">
                        <span class="header-label">Drug Lic.</span>
                        <span class="header-val font-mono">: <?= htmlspecialchars($viewPo['supplier_drug_licence'] ?? 'N/A') ?></span>
                    </div>
                    <div class="header-line">
                        <span class="header-label">Created By</span>
                        <span class="header-val">: <?= htmlspecialchars($viewPo['creator_name'] ?? 'Staff') ?></span>
                    </div>
                </div>
            </div>

            <!-- Table Container -->
            <div class="table-container">
                <table class="po-table" id="poItemsTable">
                    <colgroup>
                        <col style="width: 4%;">   <!-- # -->
                        <col style="width: 36%;">  <!-- Medicine Description -->
                        <col style="width: 10%;">  <!-- Pack -->
                        <col style="width: 8%;">   <!-- Req Qty -->
                        <col style="width: 11%;">  <!-- Rate (₹) -->
                        <col style="width: 7%;">   <!-- Disc % -->
                        <col style="width: 8%;">   <!-- GST % -->
                        <col style="width: 12%;">  <!-- Amount (₹) -->
                        <col class="edit-ui-control" style="width: 4%;"> <!-- Actions -->
                    </colgroup>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th class="text-left" style="padding-left: 6px;">Medicine Description</th>
                            <th>Pack</th>
                            <th class="text-center">Req Qty</th>
                            <th class="text-right" style="padding-right: 6px;">Rate (₹)</th>
                            <th class="text-center">Disc %</th>
                            <th class="text-center">GST %</th>
                            <th class="text-right" style="padding-right: 6px;">Amount (₹)</th>
                            <th class="edit-ui-control text-center"></th>
                        </tr>
                    </thead>
                    <tbody id="poItemsBody">
                        <?php foreach ($viewPo['items'] as $i => $item): ?>
                            <tr class="item-row po-item-row" data-po-item-id="<?= (int)$item['po_item_id'] ?>" data-row-idx="<?= $i ?>">
                                <td class="text-center"><?= $i + 1 ?></td>
                                <td class="text-left" style="padding-left: 6px;">
                                    <strong><?= htmlspecialchars($item['medicine_name']) ?></strong>
                                    <?php if (!empty($item['generic_name']) || !empty($item['manufacturer'])): ?>
                                        <div style="font-size: 10px; color: #555;">
                                            <?= htmlspecialchars($item['generic_name'] ?? '') ?>
                                            <?= !empty($item['manufacturer']) ? ' (' . htmlspecialchars($item['manufacturer']) . ')' : '' ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?= htmlspecialchars($item['pack_size']) ?></td>
                                <td class="text-center fw-bold">
                                    <span class="editable-field item-qty" data-field="requested_qty" oninput="onPoItemChange(<?= $i ?>)"><?= (int)$item['requested_qty'] ?></span>
                                </td>
                                <td class="text-right" style="padding-right: 6px;">
                                    <span class="editable-field item-rate" data-field="purchase_rate" oninput="onPoItemChange(<?= $i ?>)"><?= number_format((float)$item['purchase_rate'], 2, '.', '') ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="editable-field item-disc" data-field="discount_percent" oninput="onPoItemChange(<?= $i ?>)"><?= (float)$item['discount_percent'] ?></span>%
                                </td>
                                <td class="text-center">
                                    <span class="editable-field item-gst" data-field="gst_percent" oninput="onPoItemChange(<?= $i ?>)"><?= (float)$item['gst_percent'] ?></span>%
                                </td>
                                <td class="text-right fw-bold item-line-amount" style="padding-right: 6px;">
                                    ₹<?= number_format((float)$item['line_total'], 2) ?>
                                </td>
                                <td class="edit-ui-control text-center">
                                    <button type="button" class="btn-del-row" onclick="deletePoRow(<?= $i ?>)" title="Remove item"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Footer Summary Grid -->
            <div class="footer-grid">
                <!-- Left Box: Terms & Notes -->
                <div class="footer-box">
                    <div>
                        <strong>Notes / Instructions:</strong>
                        <div class="editable-field mt-1" id="fieldNotes" data-field="notes" style="min-height: 28px; width: 100%;">
                            <?= nl2br(htmlspecialchars($viewPo['notes'] ?: 'Deliver all items in verified condition with valid batch and expiry.')) ?>
                        </div>
                    </div>
                    <div style="font-size: 10px; color: #555; margin-top: 10px;">
                        All supplies subject to standard QA inspection upon delivery. Please quote PO Number on all invoices and challans.
                    </div>
                </div>

                <!-- Right Box: Totals Breakdown -->
                <div class="footer-box">
                    <div class="summary-line">
                        <span class="summary-label">Subtotal (Taxable Value):</span>
                        <span class="summary-val font-mono" id="lblPoSubtotal">₹<?= number_format((float)$viewPo['subtotal_amount'], 2) ?></span>
                    </div>
                    <div class="summary-line">
                        <span class="summary-label">Tax / GST Total:</span>
                        <span class="summary-val font-mono" id="lblPoTax">₹<?= number_format((float)$viewPo['tax_amount'], 2) ?></span>
                    </div>
                    <div class="summary-line grand-total-row">
                        <span class="summary-label">Grand Total:</span>
                        <span class="summary-val font-mono" id="lblPoGrandTotal">₹<?= number_format((float)$viewPo['total_amount'], 2) ?></span>
                    </div>
                </div>
            </div>

            <!-- Signatures Grid -->
            <div class="sign-grid">
                <div class="sign-col">
                    <div><?= htmlspecialchars($viewPo['creator_name'] ?? 'Storekeeper') ?></div>
                    <div class="sign-line">Prepared By</div>
                </div>
                <div class="sign-col">
                    <div>Pharmacy Storekeeper</div>
                    <div class="sign-line">Verified By (Accounts)</div>
                </div>
                <div class="sign-col">
                    <div><?= !empty($viewPo['approver_name']) ? htmlspecialchars($viewPo['approver_name']) : 'Authorized Signatory' ?></div>
                    <div class="sign-line">Authorized Signatory</div>
                </div>
            </div>
        </div>

        <!-- ----------------------------------------------------------------- -->
        <!-- JAVASCRIPT: LIVE EDITING, AUTO-CALCULATION & SERVER PERSISTENCE   -->
        <!-- ----------------------------------------------------------------- -->
        <script>
            let isEditMode = false;
            const currentPoId = <?= (int)$viewPo['po_id'] ?>;

            function toggleEditMode() {
                isEditMode = !isEditMode;
                const container = document.getElementById('poSheetContainer');
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
                if (confirm('Cancel editing and restore original purchase order details?')) {
                    window.location.reload();
                }
            }

            function onPoItemChange(rowIdx) {
                const row = document.querySelector(`.po-item-row[data-row-idx="${rowIdx}"]`);
                if (!row) return;

                const qty = parseFloat(row.querySelector('.item-qty')?.innerText || 1) || 0;
                const rate = parseFloat(row.querySelector('.item-rate')?.innerText || 0) || 0;
                const discPct = parseFloat(row.querySelector('.item-disc')?.innerText || 0) || 0;
                const gstPct = parseFloat(row.querySelector('.item-gst')?.innerText || 0) || 0;

                const discAmt = (qty * rate) * (discPct / 100);
                const taxable = (qty * rate) - discAmt;
                const taxAmt = taxable * (gstPct / 100);
                const lineTotal = taxable + taxAmt;

                if (row.querySelector('.item-line-amount')) {
                    row.querySelector('.item-line-amount').innerText = `₹${lineTotal.toFixed(2)}`;
                }

                recalculatePoTotals();
            }

            function recalculatePoTotals() {
                let subtotal = 0.0;
                let taxTotal = 0.0;
                let grandTotal = 0.0;

                document.querySelectorAll('.po-item-row').forEach(row => {
                    const qty = parseFloat(row.querySelector('.item-qty')?.innerText || 0) || 0;
                    const rate = parseFloat(row.querySelector('.item-rate')?.innerText || 0) || 0;
                    const discPct = parseFloat(row.querySelector('.item-disc')?.innerText || 0) || 0;
                    const gstPct = parseFloat(row.querySelector('.item-gst')?.innerText || 0) || 0;

                    const discAmt = (qty * rate) * (discPct / 100);
                    const taxable = (qty * rate) - discAmt;
                    const taxAmt = taxable * (gstPct / 100);
                    const lineTotal = taxable + taxAmt;

                    subtotal += taxable;
                    taxTotal += taxAmt;
                    grandTotal += lineTotal;
                });

                if (document.getElementById('lblPoSubtotal')) {
                    document.getElementById('lblPoSubtotal').innerText = `₹${subtotal.toFixed(2)}`;
                }
                if (document.getElementById('lblPoTax')) {
                    document.getElementById('lblPoTax').innerText = `₹${taxTotal.toFixed(2)}`;
                }
                if (document.getElementById('lblPoGrandTotal')) {
                    document.getElementById('lblPoGrandTotal').innerText = `₹${grandTotal.toFixed(2)}`;
                }
            }

            function deletePoRow(rowIdx) {
                const row = document.querySelector(`.po-item-row[data-row-idx="${rowIdx}"]`);
                if (row) {
                    row.remove();
                    recalculatePoTotals();
                }
            }

            function savePoEditsToServer() {
                const btnSave = document.getElementById('btnSaveEdits');
                btnSave.disabled = true;
                btnSave.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';

                const expDeliveryStr = (document.getElementById('fieldExpDelivery')?.innerText || '').trim();
                const paymentTerms = (document.getElementById('fieldPaymentTerms')?.innerText || '30 Days Net').trim();
                const notes = (document.getElementById('fieldNotes')?.innerText || '').trim();

                const items = [];
                document.querySelectorAll('.po-item-row').forEach(row => {
                    const itemId = parseInt(row.getAttribute('data-po-item-id') || '0', 10);
                    const qty = parseFloat(row.querySelector('.item-qty')?.innerText || 1) || 1;
                    const rate = parseFloat(row.querySelector('.item-rate')?.innerText || 0) || 0;
                    const discPct = parseFloat(row.querySelector('.item-disc')?.innerText || 0) || 0;
                    const gstPct = parseFloat(row.querySelector('.item-gst')?.innerText || 0) || 0;

                    items.push({
                        po_item_id: itemId,
                        requested_qty: qty,
                        purchase_rate: rate,
                        discount_percent: discPct,
                        gst_percent: gstPct
                    });
                });

                const formData = new FormData();
                formData.append('action', 'save_po_print_edits');
                formData.append('po_id', currentPoId);
                formData.append('expected_delivery_date', expDeliveryStr);
                formData.append('payment_terms', paymentTerms);
                formData.append('notes', notes);
                formData.append('items_json', JSON.stringify(items));

                fetch('orders.php', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    btnSave.disabled = false;
                    btnSave.innerHTML = '<i class="bi bi-check-circle-fill"></i> Save Changes';
                    if (data.success) {
                        alert('✓ Purchase order details updated successfully!');
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
    <?php
    exit;
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="container-fluid px-4 py-3">
    <!-- Header bar -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h4 class="fw-bold text-dark mb-1">
                <i class="ti ti-file-text text-emerald me-2"></i>Purchase Orders
            </h4>
            <p class="text-muted small mb-0">Procurement orders, vendor approval workflows, and delivery fulfillment tracking.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="grn.php" class="btn btn-outline-secondary rounded-pill px-3 py-2 small">
                <i class="ti ti-truck-loading me-1"></i> Receive Goods (GRN)
            </a>
            <?php if ($canCreate): ?>
                <button type="button" class="btn btn-emerald rounded-pill px-3 py-2 text-white small" style="background-color: #059669;" data-bs-toggle="modal" data-bs-target="#createPoModal">
                    <i class="ti ti-plus me-1"></i> Create Purchase Order
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($feedback): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3 border-0 shadow-sm mb-4">
            <i class="ti ti-circle-check me-2 fs-5"></i><?= $feedback ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-3 border-0 shadow-sm mb-4">
            <i class="ti ti-alert-circle me-2 fs-5"></i><?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Filter Card -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white">
        <div class="card-body p-3">
            <form method="GET" action="orders.php" class="row g-2 align-items-center">
                <div class="col-md-4">
                    <input type="text" name="search" class="form-control bg-light border-0" placeholder="Search PO number or supplier..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-3">
                    <select name="supplier_id" class="form-select bg-light border-0">
                        <option value="">All Suppliers</option>
                        <?php foreach ($activeSuppliers as $sup): ?>
                            <option value="<?= $sup['supplier_id'] ?>" <?= $supplierId === (int)$sup['supplier_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sup['supplier_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="status" class="form-select bg-light border-0">
                        <option value="">All Statuses</option>
                        <option value="DRAFT" <?= $status === 'DRAFT' ? 'selected' : '' ?>>Draft</option>
                        <option value="APPROVED" <?= $status === 'APPROVED' ? 'selected' : '' ?>>Approved</option>
                        <option value="PARTIALLY_RECEIVED" <?= $status === 'PARTIALLY_RECEIVED' ? 'selected' : '' ?>>Partially Received</option>
                        <option value="FULLY_RECEIVED" <?= $status === 'FULLY_RECEIVED' ? 'selected' : '' ?>>Fully Received</option>
                        <option value="CANCELLED" <?= $status === 'CANCELLED' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-dark w-100 rounded-3">Filter</button>
                    <a href="orders.php" class="btn btn-outline-secondary rounded-3">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- PO List Table -->
    <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr class="text-uppercase small text-muted">
                        <th class="ps-4">PO Number & Date</th>
                        <th>Supplier</th>
                        <th>Items / Units</th>
                        <th class="text-end">Total Amount</th>
                        <th>Fulfillment</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="ti ti-file-search fs-1 d-block mb-2 text-secondary"></i>
                                No purchase orders found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($orders as $o): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($o['po_number']) ?></div>
                                    <div class="text-muted small"><?= date('d-M-Y', strtotime($o['po_date'])) ?></div>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= htmlspecialchars($o['supplier_name']) ?></div>
                                    <span class="badge bg-light text-secondary font-monospace" style="font-size: 0.7rem;"><?= htmlspecialchars($o['supplier_code']) ?></span>
                                </td>
                                <td>
                                    <div class="text-dark small fw-semibold"><?= (int)$o['item_count'] ?> Products</div>
                                    <div class="text-muted small">Req: <?= (int)$o['total_requested_units'] ?> | Rec: <?= (int)$o['total_received_units'] ?></div>
                                </td>
                                <td class="text-end fw-bold text-dark">
                                    ₹<?= number_format((float)$o['total_amount'], 2) ?>
                                </td>
                                <td>
                                    <?php 
                                        $req = max(1, (int)$o['total_requested_units']);
                                        $rec = (int)$o['total_received_units'];
                                        $pct = min(100, round(($rec / $req) * 100));
                                    ?>
                                    <div class="progress" style="height: 6px; width: 80px;">
                                        <div class="progress-bar <?= $pct >= 100 ? 'bg-success' : 'bg-primary' ?>" role="progressbar" style="width: <?= $pct ?>%;"></div>
                                    </div>
                                    <div class="text-muted" style="font-size: 0.68rem;"><?= $pct ?>% fulfilled</div>
                                </td>
                                <td>
                                    <?php
                                        $badgeColor = match($o['status']) {
                                            'APPROVED' => 'primary',
                                            'PARTIALLY_RECEIVED' => 'warning',
                                            'FULLY_RECEIVED' => 'success',
                                            'CANCELLED' => 'danger',
                                            default => 'secondary'
                                        };
                                    ?>
                                    <span class="badge bg-<?= $badgeColor ?>-subtle text-<?= $badgeColor ?> rounded-pill px-2 py-1">
                                        <?= htmlspecialchars($o['status']) ?>
                                    </span>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="d-flex justify-content-end gap-1">
                                        <a href="orders.php?view_id=<?= $o['po_id'] ?>" class="btn btn-sm btn-light border rounded-pill px-2" title="View Order">
                                            <i class="ti ti-eye"></i>
                                        </a>
                                        <?php if ($canEdit && in_array($o['status'], ['DRAFT', 'SUBMITTED', 'APPROVED'], true) && (int)$o['total_received_units'] === 0): ?>
                                            <a href="orders.php?edit_id=<?= $o['po_id'] ?>" class="btn btn-sm btn-light border rounded-pill px-2" title="Edit Order">
                                                <i class="ti ti-edit"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="orders.php?view_id=<?= $o['po_id'] ?>&print=1" target="_blank" class="btn btn-sm btn-light border rounded-pill px-2" title="Print PO">
                                            <i class="ti ti-printer"></i>
                                        </a>
                                        <?php if ($o['status'] === 'DRAFT' && $canApprove): ?>
                                            <form method="POST" action="orders.php" class="d-inline" onsubmit="return confirm('Approve this purchase order?');">
                                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                <input type="hidden" name="action" value="approve_po">
                                                <input type="hidden" name="po_id" value="<?= $o['po_id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-success rounded-pill px-2" title="Approve">
                                                    <i class="ti ti-check"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if (in_array($o['status'], ['APPROVED', 'PARTIALLY_RECEIVED'], true)): ?>
                                            <a href="grn.php?po_id=<?= $o['po_id'] ?>" class="btn btn-sm btn-emerald text-white rounded-pill px-2" style="background-color: #059669;" title="Receive GRN">
                                                <i class="ti ti-truck-loading"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Create / Edit Purchase Order Modal -->
<?php if ($canCreate || $editPo): ?>
<div class="modal fade" id="createPoModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content rounded-4 border-0">
            <form method="POST" action="orders.php" id="poForm">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="<?= $editPo ? 'edit_po' : 'create_po' ?>">
                <?php if ($editPo): ?>
                    <input type="hidden" name="po_id" value="<?= $editPo['po_id'] ?>">
                <?php endif; ?>
                <div class="modal-header border-0 px-4 pt-4">
                    <h5 class="fw-bold">
                        <?php if ($editPo): ?>
                            <i class="ti ti-edit text-emerald me-2"></i>Edit Purchase Order (<?= htmlspecialchars($editPo['po_number']) ?>)
                        <?php else: ?>
                            <i class="ti ti-file-plus text-emerald me-2"></i>Create Purchase Order
                        <?php endif; ?>
                    </h5>
                    <?php if ($editPo): ?>
                        <a href="orders.php" class="btn-close"></a>
                    <?php else: ?>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    <?php endif; ?>
                </div>
                <div class="modal-body px-4">
                    <!-- PO Header inputs -->
                    <div class="row g-3 mb-4 p-3 bg-light rounded-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Select Supplier *</label>
                            <select name="supplier_id" class="form-select" required>
                                <option value="">-- Choose Vendor --</option>
                                <?php foreach ($activeSuppliers as $sup): ?>
                                    <option value="<?= $sup['supplier_id'] ?>" data-terms="<?= htmlspecialchars($sup['payment_terms']) ?>" <?= ($editPo && (int)$editPo['supplier_id'] === (int)$sup['supplier_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($sup['supplier_name']) ?> (<?= htmlspecialchars($sup['supplier_code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Order Date *</label>
                            <input type="date" name="po_date" class="form-control" value="<?= htmlspecialchars($editPo['po_date'] ?? date('Y-m-d')) ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Expected Delivery Date</label>
                            <input type="date" name="expected_delivery_date" class="form-control" value="<?= htmlspecialchars($editPo['expected_delivery_date'] ?? '') ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold">Payment Terms</label>
                            <input type="text" name="payment_terms" class="form-control" value="<?= htmlspecialchars($editPo['payment_terms'] ?? '30 Days Net') ?>">
                        </div>
                    </div>

                    <!-- Line Items Section -->
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="fw-bold mb-0 text-dark">Order Items</h6>
                        <button type="button" class="btn btn-sm btn-outline-dark rounded-pill" onclick="addPoRow()">
                            <i class="ti ti-plus me-1"></i> Add Item
                        </button>
                    </div>

                    <div class="table-responsive mb-3">
                        <table class="table table-bordered table-sm align-middle" id="poItemsTable">
                            <thead class="table-light small">
                                <tr>
                                    <th style="width: 30%;">Medicine *</th>
                                    <th style="width: 16%;" class="text-center">Current Stock</th>
                                    <th style="width: 10%;">Pack</th>
                                    <th style="width: 10%;">Req Qty *</th>
                                    <th style="width: 12%;">Rate (₹) *</th>
                                    <th style="width: 8%;">Disc %</th>
                                    <th style="width: 8%;">GST %</th>
                                    <th style="width: 13%;" class="text-end">Line Total (₹)</th>
                                    <th style="width: 3%;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Initial rows will be injected by JS -->
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="7" class="text-end">Grand Total:</th>
                                    <th class="text-end text-success fs-6" id="poGrandTotal">₹0.00</th>
                                    <th></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label small fw-semibold">Order Notes / Terms to Supplier</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Delivery instructions, batch preference, etc."><?= htmlspecialchars($editPo['notes'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <?php if (!$editPo && $canApprove): ?>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="auto_approve" value="1" id="autoApproveCheck" checked>
                                    <label class="form-check-label small fw-semibold" for="autoApproveCheck">
                                        Immediately mark as APPROVED
                                    </label>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <?php if ($editPo): ?>
                        <a href="orders.php" class="btn btn-light rounded-pill px-4">Cancel</a>
                    <?php else: ?>
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-emerald rounded-pill px-4 text-white" style="background-color: #059669;">
                        <?= $editPo ? 'Update Purchase Order' : 'Save Purchase Order' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const medicinesCatalog = <?= json_encode($medicinesCatalog) ?>;

function getPoStockBadge(stock) {
    if (stock > 10) {
        return `<span class="badge font-monospace px-2 py-1" style="background:#ecfdf5;color:#059669;border:1px solid #a7f3d0;"><i class="ti ti-check me-1"></i>${stock} In Stock</span>`;
    } else if (stock > 0) {
        return `<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle font-monospace px-2 py-1"><i class="ti ti-alert-triangle me-1"></i>Low: ${stock} Left</span>`;
    } else {
        return `<span class="badge bg-danger-subtle text-danger border border-danger-subtle font-monospace px-2 py-1"><i class="ti ti-x me-1"></i>0 Out of Stock</span>`;
    }
}

function addPoRow(prefill = null) {
    const tableBody = document.querySelector('#poItemsTable tbody');
    const row = document.createElement('tr');

    let medOptions = '<option value="">-- Select Medicine --</option>';
    medicinesCatalog.forEach(m => {
        const avail = parseInt(m.available_stock) || 0;
        const icon = avail > 10 ? '🟢' : (avail > 0 ? '🟡' : '🔴');
        const sel = (prefill && parseInt(prefill.medicine_id) === parseInt(m.medicine_id)) ? 'selected' : '';
        medOptions += `<option value="${m.medicine_id}" data-rate="${m.purchase_price}" data-gst="${m.gst_percent}" data-pack="${m.pack_size}" data-mrp="${m.mrp}" data-stock="${avail}" ${sel}>${icon} [Stock: ${avail}] ${m.medicine_name} (${m.dosage_form || ''} - ${m.strength || ''})</option>`;
    });

    const initQty = prefill ? prefill.requested_qty : 10;
    const initRate = prefill ? parseFloat(prefill.purchase_rate).toFixed(2) : '0.00';
    const initDisc = prefill ? parseFloat(prefill.discount_percent).toFixed(2) : '0.00';
    const initGst = prefill ? parseFloat(prefill.gst_percent).toFixed(2) : '0.00';
    const initPack = prefill ? prefill.pack_size : '1';

    row.innerHTML = `
        <td>
            <select name="item_medicine_id[]" class="form-select form-select-sm med-select" required onchange="onMedChange(this)">
                ${medOptions}
            </select>
        </td>
        <td class="text-center po-stock-cell"><span class="badge bg-light text-muted border">-</span></td>
        <td><input type="text" name="item_pack[]" class="form-control form-select-sm pack-input" value="${initPack}"></td>
        <td><input type="number" name="item_qty[]" class="form-control form-select-sm qty-input" min="1" value="${initQty}" required oninput="calcLine(this)"></td>
        <td><input type="number" step="0.01" name="item_rate[]" class="form-control form-select-sm rate-input" min="0" value="${initRate}" required oninput="calcLine(this)"></td>
        <td><input type="number" step="0.01" name="item_discount[]" class="form-control form-select-sm disc-input" min="0" max="100" value="${initDisc}" oninput="calcLine(this)"></td>
        <td><input type="number" step="0.01" name="item_gst[]" class="form-control form-select-sm gst-input" min="0" value="${initGst}" oninput="calcLine(this)"></td>
        <td class="text-end fw-bold line-total-col">₹0.00</td>
        <td class="text-center">
            <button type="button" class="btn btn-sm text-danger p-0 border-0" onclick="this.closest('tr').remove(); recalcPoGrand();"><i class="ti ti-trash"></i></button>
        </td>
    `;
    tableBody.appendChild(row);

    const selElem = row.querySelector('.med-select');
    if (prefill) {
        calcLine(selElem);
        const opt = selElem.options[selElem.selectedIndex];
        if (opt && opt.value) {
            const stock = parseInt(opt.dataset.stock) || 0;
            row.querySelector('.po-stock-cell').innerHTML = getPoStockBadge(stock);
        }
    }
}

function onMedChange(select) {
    const opt = select.options[select.selectedIndex];
    const row = select.closest('tr');
    if (opt.value) {
        row.querySelector('.rate-input').value = opt.dataset.rate || '0.00';
        row.querySelector('.gst-input').value = opt.dataset.gst || '0.00';
        row.querySelector('.pack-input').value = opt.dataset.pack || '1';
        const stock = parseInt(opt.dataset.stock) || 0;
        row.querySelector('.po-stock-cell').innerHTML = getPoStockBadge(stock);
    } else {
        row.querySelector('.po-stock-cell').innerHTML = '<span class="badge bg-light text-muted border">-</span>';
    }
    calcLine(select);
}

function calcLine(elem) {
    const row = elem.closest('tr');
    const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
    const rate = parseFloat(row.querySelector('.rate-input').value) || 0;
    const disc = parseFloat(row.querySelector('.disc-input').value) || 0;
    const gst = parseFloat(row.querySelector('.gst-input').value) || 0;

    const gross = qty * rate;
    const discAmt = gross * (disc / 100);
    const taxable = gross - discAmt;
    const gstAmt = taxable * (gst / 100);
    const total = taxable + gstAmt;

    row.querySelector('.line-total-col').textContent = '₹' + total.toFixed(2);
    recalcPoGrand();
}

function recalcPoGrand() {
    let grand = 0;
    document.querySelectorAll('#poItemsTable tbody tr').forEach(row => {
        const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
        const rate = parseFloat(row.querySelector('.rate-input').value) || 0;
        const disc = parseFloat(row.querySelector('.disc-input').value) || 0;
        const gst = parseFloat(row.querySelector('.gst-input').value) || 0;
        const gross = qty * rate;
        const taxable = gross - (gross * (disc / 100));
        grand += taxable + (taxable * (gst / 100));
    });
    document.getElementById('poGrandTotal').textContent = '₹' + grand.toFixed(2);
}

document.addEventListener('DOMContentLoaded', () => {
    const editItems = <?= !empty($editPo['items']) ? json_encode($editPo['items']) : '[]' ?>;
    if (editItems && editItems.length > 0) {
        editItems.forEach(item => addPoRow(item));
        recalcPoGrand();
        const modalEl = document.getElementById('createPoModal');
        if (modalEl) {
            const m = new bootstrap.Modal(modalEl);
            m.show();
        }
    } else {
        addPoRow();
    }
});
</script>
<?php endif; ?>

<!-- View PO Details Modal -->
<?php if ($viewPo): ?>
<div class="modal fade show" id="viewPoModal" tabindex="-1" style="display: block; background: rgba(0,0,0,0.5);">
    <div class="modal-dialog modal-xl">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header border-0 px-4 pt-4">
                <div>
                    <h5 class="fw-bold mb-0 text-dark">
                        <i class="ti ti-file-text text-emerald me-2"></i><?= htmlspecialchars($viewPo['po_number']) ?>
                    </h5>
                    <span class="badge bg-<?= $viewPo['status'] === 'APPROVED' ? 'primary' : ($viewPo['status'] === 'FULLY_RECEIVED' ? 'success' : 'secondary') ?>-subtle text-dark">
                        <?= $viewPo['status'] ?>
                    </span>
                    <span class="text-muted small ms-2">Date: <?= date('d-M-Y', strtotime($viewPo['po_date'])) ?></span>
                </div>
                <a href="orders.php" class="btn-close"></a>
            </div>
            <div class="modal-body px-4">
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="p-3 border rounded-3 bg-light">
                            <div class="text-muted small">Supplier Details:</div>
                            <h6 class="fw-bold mb-1"><?= htmlspecialchars($viewPo['supplier_name']) ?> (<?= htmlspecialchars($viewPo['supplier_code']) ?>)</h6>
                            <div class="small">GSTIN: <span class="font-monospace"><?= htmlspecialchars($viewPo['supplier_gstin'] ?? 'N/A') ?></span> | Phone: <?= htmlspecialchars($viewPo['supplier_phone'] ?? '—') ?></div>
                            <div class="small"><?= htmlspecialchars($viewPo['supplier_address'] ?? '') ?>, <?= htmlspecialchars($viewPo['supplier_city'] ?? '') ?></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 border rounded-3 bg-light">
                            <div class="text-muted small">Procurement Details:</div>
                            <div class="small">Payment Terms: <strong><?= htmlspecialchars($viewPo['payment_terms'] ?? '30 Days Net') ?></strong></div>
                            <div class="small">Expected Delivery: <strong><?= !empty($viewPo['expected_delivery_date']) ? date('d-M-Y', strtotime($viewPo['expected_delivery_date'])) : 'Standard' ?></strong></div>
                            <div class="small">Created By: <?= htmlspecialchars($viewPo['creator_name'] ?? 'Staff') ?></div>
                        </div>
                    </div>
                </div>

                <h6 class="fw-bold text-dark">Line Items</h6>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm align-middle">
                        <thead class="table-light small">
                            <tr>
                                <th>#</th>
                                <th>Medicine</th>
                                <th>Pack</th>
                                <th class="text-center">Requested</th>
                                <th class="text-center">Received</th>
                                <th class="text-end">Rate (₹)</th>
                                <th class="text-center">GST %</th>
                                <th class="text-end">Line Total (₹)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($viewPo['items'] as $i => $it): ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($it['medicine_name']) ?></strong>
                                        <div class="text-muted small" style="font-size: 0.72rem;"><?= htmlspecialchars($it['generic_name'] ?? '') ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($it['pack_size']) ?></td>
                                    <td class="text-center fw-bold"><?= (int)$it['requested_qty'] ?></td>
                                    <td class="text-center <?= (int)$it['received_qty'] >= (int)$it['requested_qty'] ? 'text-success fw-bold' : 'text-muted' ?>">
                                        <?= (int)$it['received_qty'] ?>
                                    </td>
                                    <td class="text-end">₹<?= number_format((float)$it['purchase_rate'], 2) ?></td>
                                    <td class="text-center"><?= (float)$it['gst_percent'] ?>%</td>
                                    <td class="text-end fw-bold">₹<?= number_format((float)$it['line_total'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="7" class="text-end">Grand Total:</th>
                                <th class="text-end text-success fs-6">₹<?= number_format((float)$viewPo['total_amount'], 2) ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="modal-footer border-0 px-4 pb-4">
                <a href="orders.php?view_id=<?= $viewPo['po_id'] ?>&print=1" target="_blank" class="btn btn-outline-dark rounded-pill px-3">
                    <i class="ti ti-printer me-1"></i> Print Order
                </a>
                <?php if (in_array($viewPo['status'], ['APPROVED', 'PARTIALLY_RECEIVED'], true)): ?>
                    <a href="grn.php?po_id=<?= $viewPo['po_id'] ?>" class="btn btn-emerald text-white rounded-pill px-3" style="background-color: #059669;">
                        <i class="ti ti-truck-loading me-1"></i> Receive Goods (GRN)
                    </a>
                <?php endif; ?>
                <?php if ($viewPo['status'] === 'DRAFT' && $canApprove): ?>
                    <form method="POST" action="orders.php" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <input type="hidden" name="action" value="approve_po">
                        <input type="hidden" name="po_id" value="<?= $viewPo['po_id'] ?>">
                        <button type="submit" class="btn btn-success rounded-pill px-3">Approve Order</button>
                    </form>
                <?php endif; ?>
                <a href="orders.php" class="btn btn-light rounded-pill px-4">Close</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
include __DIR__ . '/../../includes/footer.php';
?>