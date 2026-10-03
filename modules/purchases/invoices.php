<?php
// modules/purchases/invoices.php - Purchase Invoices / Bills & 3-Way Matching

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/AuditService.php';
require_once __DIR__ . '/../../app/Services/DocumentSequenceService.php';
require_once __DIR__ . '/../../app/Services/SupplierService.php';
require_once __DIR__ . '/../../app/Services/PurchaseInvoiceService.php';

require_permission('pharmacy.purchase_invoices.view');

use Pharmacy\Services\SupplierService;
use Pharmacy\Services\PurchaseInvoiceService;

$invService = new PurchaseInvoiceService($pdo);
$supplierService = new SupplierService($pdo);

$page_title = 'Purchase Invoices';
$user = auth_user();
$userId = (int)($user['id'] ?? 1);

$canCreate = has_permission('pharmacy.purchase_invoices.create');
$canManage = has_permission('pharmacy.purchase_invoices.manage');
$canEdit = has_permission('pharmacy.purchase_invoices.edit') || has_permission('pharmacy.purchase_invoices.create');

$feedback = null;
$error = null;

// Handle Form Submissions (Create / Edit Invoice)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Security token validation failed. Please try again.";
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'create_invoice') {
                if (!$canCreate) throw new Exception("Permission denied: Cannot create purchase invoice.");

                $header = [
                    'supplier_id'         => (int)($_POST['supplier_id'] ?? 0),
                    'supplier_invoice_no' => trim($_POST['supplier_invoice_no'] ?? ''),
                    'invoice_date'        => $_POST['invoice_date'] ?? date('Y-m-d'),
                    'due_date'            => !empty($_POST['due_date']) ? $_POST['due_date'] : null,
                    'po_id'               => !empty($_POST['po_id']) ? (int)$_POST['po_id'] : null,
                    'grn_id'              => !empty($_POST['grn_id']) ? (int)$_POST['grn_id'] : null,
                    'other_charges'       => (float)($_POST['other_charges'] ?? 0.00),
                    'payment_terms'       => trim($_POST['payment_terms'] ?? '30 Days Net'),
                    'notes'               => trim($_POST['notes'] ?? '')
                ];

                $items = [];
                $medIds = $_POST['item_medicine_id'] ?? [];
                $grnItemIds = $_POST['item_grn_item_id'] ?? [];
                $batchIds = $_POST['item_batch_id'] ?? [];
                $batchNums = $_POST['item_batch_number'] ?? [];
                $quantities = $_POST['item_qty'] ?? [];
                $freeQtys = $_POST['item_free_qty'] ?? [];
                $rates = $_POST['item_rate'] ?? [];
                $discounts = $_POST['item_discount'] ?? [];
                $gsts = $_POST['item_gst'] ?? [];

                foreach ($medIds as $k => $mId) {
                    $mId = (int)$mId;
                    $qty = (int)($quantities[$k] ?? 0);
                    if ($mId > 0 && $qty > 0) {
                        $items[] = [
                            'grn_item_id'      => !empty($grnItemIds[$k]) ? (int)$grnItemIds[$k] : null,
                            'medicine_id'      => $mId,
                            'batch_id'         => !empty($batchIds[$k]) ? (int)$batchIds[$k] : null,
                            'batch_number'     => trim($batchNums[$k] ?? ''),
                            'quantity'         => $qty,
                            'free_qty'         => (int)($freeQtys[$k] ?? 0),
                            'purchase_rate'    => (float)($rates[$k] ?? 0.00),
                            'discount_percent' => (float)($discounts[$k] ?? 0.00),
                            'gst_percent'      => (float)($gsts[$k] ?? 0.00)
                        ];
                    }
                }

                if (empty($items)) {
                    throw new Exception("Please include at least one invoiced item.");
                }

                $grnIds = !empty($header['grn_id']) ? [$header['grn_id']] : [];
                $invId = $invService->createPurchaseInvoice($header, $items, $grnIds, $userId);
                $savedInv = $invService->getPurchaseInvoice($invId);
                $invNumStr = $savedInv ? htmlspecialchars($savedInv['invoice_number']) : "#{$invId}";
                $feedback = "Purchase invoice {$invNumStr} recorded successfully with 3-way reconciliation. <a href='invoices.php?view_id={$invId}' class='alert-link fw-bold ms-2 text-decoration-underline'><i class='ti ti-eye'></i> View Bill Details</a>";
            } elseif ($action === 'edit_invoice') {
                if (!$canEdit) throw new Exception("Permission denied: Cannot edit purchase invoice.");
                $invoiceId = (int)($_POST['invoice_id'] ?? 0);
                $header = [
                    'supplier_id'         => (int)($_POST['supplier_id'] ?? 0),
                    'supplier_invoice_no' => trim($_POST['supplier_invoice_no'] ?? ''),
                    'invoice_date'        => $_POST['invoice_date'] ?? date('Y-m-d'),
                    'due_date'            => !empty($_POST['due_date']) ? $_POST['due_date'] : null,
                    'other_charges'       => (float)($_POST['other_charges'] ?? 0.00),
                    'payment_terms'       => trim($_POST['payment_terms'] ?? '30 Days Net'),
                    'notes'               => trim($_POST['notes'] ?? '')
                ];

                $items = [];
                $medIds = $_POST['item_medicine_id'] ?? [];
                $grnItemIds = $_POST['item_grn_item_id'] ?? [];
                $batchIds = $_POST['item_batch_id'] ?? [];
                $batchNums = $_POST['item_batch_number'] ?? [];
                $quantities = $_POST['item_qty'] ?? [];
                $freeQtys = $_POST['item_free_qty'] ?? [];
                $rates = $_POST['item_rate'] ?? [];
                $discounts = $_POST['item_discount'] ?? [];
                $gsts = $_POST['item_gst'] ?? [];

                foreach ($medIds as $k => $mId) {
                    $mId = (int)$mId;
                    $qty = (int)($quantities[$k] ?? 0);
                    if ($mId > 0 && $qty > 0) {
                        $items[] = [
                            'grn_item_id'      => !empty($grnItemIds[$k]) ? (int)$grnItemIds[$k] : null,
                            'medicine_id'      => $mId,
                            'batch_id'         => !empty($batchIds[$k]) ? (int)$batchIds[$k] : null,
                            'batch_number'     => trim($batchNums[$k] ?? ''),
                            'quantity'         => $qty,
                            'free_qty'         => (int)($freeQtys[$k] ?? 0),
                            'purchase_rate'    => (float)($rates[$k] ?? 0.00),
                            'discount_percent' => (float)($discounts[$k] ?? 0.00),
                            'gst_percent'      => (float)($gsts[$k] ?? 0.00)
                        ];
                    }
                }

                if (empty($items)) {
                    throw new Exception("Please include at least one invoiced item.");
                }

                $invService->updatePurchaseInvoice($invoiceId, $header, $items, $userId);
                $savedInv = $invService->getPurchaseInvoice($invoiceId);
                $invNumStr = $savedInv ? htmlspecialchars($savedInv['invoice_number']) : "#{$invoiceId}";
                $feedback = "Purchase invoice {$invNumStr} updated successfully. <a href='invoices.php?view_id={$invoiceId}' class='alert-link fw-bold ms-2 text-decoration-underline'><i class='ti ti-eye'></i> View Bill Details</a>";
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Filters
$supplierId = !empty($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : null;
$paymentStatus = trim($_GET['payment_status'] ?? '');
$matchStatus = trim($_GET['match_status'] ?? '');
$overdueOnly = !empty($_GET['overdue_only']);
$search = trim($_GET['search'] ?? '');

$filters = [];
if ($supplierId) $filters['supplier_id'] = $supplierId;
if ($paymentStatus !== '' && $paymentStatus !== 'All') $filters['payment_status'] = $paymentStatus;
if ($matchStatus !== '' && $matchStatus !== 'All') $filters['match_status'] = $matchStatus;
if ($overdueOnly) $filters['overdue_only'] = true;
if ($search !== '') $filters['search'] = $search;

$invoices = $invService->listPurchaseInvoices($filters);

$activeSuppliers = $pdo->query("SELECT supplier_id, supplier_name, supplier_code, payment_terms FROM pharmacy_suppliers WHERE status = 'Active' ORDER BY supplier_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$availableGrns = $pdo->query("SELECT grn_id, grn_number, supplier_id, po_id, supplier_invoice_no, grn_date, total_amount FROM pharmacy_grn WHERE status = 'POSTED' ORDER BY grn_id DESC")->fetchAll(PDO::FETCH_ASSOC);

// View Invoice Details Modal
$viewInv = null;
if (!empty($_GET['view_id'])) {
    $viewInv = $invService->getPurchaseInvoice((int)$_GET['view_id']);
}

// Edit Invoice Mode
$editInv = null;
if (!empty($_GET['edit_id']) && $canEdit) {
    $candInv = $invService->getPurchaseInvoice((int)$_GET['edit_id']);
    if ($candInv && $candInv['payment_status'] !== 'PAID') {
        $editInv = $candInv;
    } else {
        $error = "This invoice cannot be edited because it is fully settled (PAID).";
    }
}

// Print mode
$isPrint = isset($_GET['print']) && $viewInv;
if ($isPrint) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Purchase Bill - <?= htmlspecialchars($viewInv['invoice_number']) ?></title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
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
            .btn-group { display: inline-flex; gap: 6px; align-items: center; flex-wrap: nowrap; }
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
            }
            .btn:hover { background: #f1f5f9; color: #0f172a; }
            .btn-primary { background: #0284c7; color: #ffffff; border-color: #0284c7; }
            .btn-primary:hover { background: #0369a1; border-color: #0369a1; color: #ffffff; }
            .btn-secondary { background: #f8fafc; color: #475569; border-color: #cbd5e1; }
            .btn-secondary:hover { background: #e2e8f0; color: #1e293b; }

            .bill-sheet {
                width: 100%;
                max-width: 920px;
                background-color: #ffffff;
                padding: 18px 22px;
                border: 1.5px solid #000000;
                box-shadow: 0 10px 30px rgba(0,0,0,0.3);
                color: #000000;
            }
            .bill-title {
                text-align: center;
                font-size: 13.5px;
                font-weight: 800;
                letter-spacing: 0.5px;
                padding-bottom: 6px;
                margin-bottom: 6px;
                border-bottom: 1.5px solid #000000;
                text-transform: uppercase;
            }
            .header-grid {
                display: grid;
                grid-template-columns: 38% 28% 34%;
                border: 1px solid #000000;
                margin-bottom: 6px;
                font-size: 11.5px;
                line-height: 1.4;
            }
            .header-box { padding: 6px 8px; }
            .header-box:not(:last-child) { border-right: 1px solid #000000; }
            .shop-name { font-size: 14px; font-weight: 800; margin-bottom: 2px; }
            .header-line { display: flex; margin-bottom: 2px; align-items: baseline; }
            .header-label { font-weight: 700; white-space: nowrap; min-width: 85px; }
            .header-val { font-weight: 500; word-break: break-word; flex-grow: 1; }

            .table-container { width: 100%; min-height: 280px; margin-bottom: 6px; }
            .bill-table { width: 100%; border-collapse: collapse; font-size: 11px; table-layout: fixed; }
            .bill-table th {
                border: 1px solid #000000;
                padding: 5px 3px;
                font-weight: 700;
                text-align: center;
                background: #f8fafc;
                text-transform: uppercase;
                font-size: 10.5px;
            }
            .bill-table td {
                border-left: 1px solid #000000;
                border-right: 1px solid #000000;
                border-bottom: 1px solid #e2e8f0;
                padding: 4px 4px;
                font-size: 11px;
                line-height: 1.3;
                vertical-align: middle;
            }
            .text-center { text-align: center; }
            .text-right { text-align: right; }
            .text-left { text-align: left; }
            .fw-bold { font-weight: 700; }
            .font-mono { font-family: monospace; }

            .footer-grid {
                display: grid;
                grid-template-columns: 50% 50%;
                border: 1px solid #000000;
                font-size: 11.5px;
                margin-top: 6px;
            }
            .footer-box { padding: 6px 10px; display: flex; flex-direction: column; justify-content: space-between; }
            .footer-box:not(:last-child) { border-right: 1px solid #000000; }
            .summary-line { display: flex; justify-content: space-between; margin-bottom: 3px; }
            .summary-label { font-weight: 600; }
            .summary-val { font-weight: 700; }
            .grand-total-row { font-size: 13.5px; font-weight: 800; border-top: 1.5px solid #000000; padding-top: 4px; margin-top: 4px; }

            .sign-grid {
                display: grid;
                grid-template-columns: 33% 33% 34%;
                border: 1px solid #000000;
                border-top: none;
                padding: 18px 10px 6px 10px;
                text-align: center;
                font-size: 10.5px;
            }
            .sign-col { display: flex; flex-direction: column; justify-content: space-between; height: 50px; }
            .sign-line { border-top: 1px dotted #444; margin: 0 15px; padding-top: 3px; font-weight: 700; }

            @media print {
                @page { size: A4 portrait; margin: 5mm; }
                html, body { background: #ffffff !important; margin: 0 !important; padding: 0 !important; width: 100% !important; font-size: 10px !important; }
                .no-print-bar { display: none !important; }
                .bill-sheet { max-width: 100% !important; width: 100% !important; margin: 0 auto !important; padding: 4mm !important; box-shadow: none !important; border: 1px solid #000000 !important; page-break-after: avoid; }
            }
        </style>
    </head>
    <body>
        <div class="no-print-bar">
            <div class="title">
                <i class="bi bi-file-earmark-ruled text-primary"></i>
                <span>Purchase Bill Record</span>
                <span class="badge-bill"><?= htmlspecialchars($viewInv['invoice_number']) ?></span>
            </div>
            <div class="btn-group">
                <button type="button" class="btn btn-primary" onclick="window.print()" title="Print Bill">
                    <i class="bi bi-printer"></i> Print Purchase Bill
                </button>
                <a href="invoices.php" class="btn btn-secondary" title="Return to Invoices list">
                    <i class="bi bi-arrow-left"></i> Back to Invoices
                </a>
                <button type="button" class="btn btn-secondary" onclick="window.close()" title="Close this window">
                    <i class="bi bi-x-lg"></i> Close
                </button>
            </div>
        </div>

        <div class="bill-sheet">
            <div class="bill-title">PURCHASE INVOICE &amp; PAYABLE RECORD</div>

            <div class="header-grid">
                <div class="header-box">
                    <div class="shop-name">VATSALYA CENTRAL PHARMACY</div>
                    <div>Vatsalya Hospital | Accounts Payable &amp; Procurement</div>
                    <div>22, 2A, Mundhwa-Kharadi Rd, Kharadi, Pune-411014</div>
                    <div style="margin-top: 2px;"><strong>DL No.</strong> : 20-276335,21-276336-MH-PZ1</div>
                    <div><strong>GSTIN</strong> : 27AAQFV6256M1Z8</div>
                </div>

                <div class="header-box">
                    <div class="header-line">
                        <span class="header-label">Bill Record:</span>
                        <span class="header-val"><strong><?= htmlspecialchars($viewInv['invoice_number']) ?></strong></span>
                    </div>
                    <div class="header-line">
                        <span class="header-label">Vendor Bill:</span>
                        <span class="header-val"><strong><?= htmlspecialchars($viewInv['supplier_invoice_no']) ?></strong></span>
                    </div>
                    <div class="header-line">
                        <span class="header-label">Bill Date:</span>
                        <span class="header-val"><?= date('d-M-Y', strtotime($viewInv['invoice_date'])) ?></span>
                    </div>
                    <div class="header-line">
                        <span class="header-label">PO Ref:</span>
                        <span class="header-val"><?= htmlspecialchars($viewInv['po_number'] ?? 'Direct Delivery') ?></span>
                    </div>
                    <div class="header-line">
                        <span class="header-label">Payment:</span>
                        <span class="header-val"><strong><?= htmlspecialchars($viewInv['payment_status']) ?></strong></span>
                    </div>
                </div>

                <div class="header-box">
                    <div class="header-line">
                        <span class="header-label">Supplier:</span>
                        <span class="header-val"><strong><?= htmlspecialchars($viewInv['supplier_name']) ?></strong></span>
                    </div>
                    <div class="header-line">
                        <span class="header-label">Address:</span>
                        <span class="header-val"><?= htmlspecialchars($viewInv['supplier_address'] ?? 'N/A') ?></span>
                    </div>
                    <div class="header-line">
                        <span class="header-label">GSTIN:</span>
                        <span class="header-val font-mono"><?= htmlspecialchars($viewInv['supplier_gstin'] ?? 'N/A') ?></span>
                    </div>
                    <div class="header-line">
                        <span class="header-label">GRN Ref:</span>
                        <span class="header-val"><?= htmlspecialchars($viewInv['grn_number'] ?? 'Consolidated / Direct') ?></span>
                    </div>
                    <div class="header-line">
                        <span class="header-label">3-Way Match:</span>
                        <span class="header-val"><strong><?= htmlspecialchars($viewInv['match_status']) ?></strong></span>
                    </div>
                </div>
            </div>

            <div class="table-container">
                <table class="bill-table">
                    <colgroup>
                        <col style="width: 4%;">   <!-- # -->
                        <col style="width: 33%;">  <!-- Medicine Description -->
                        <col style="width: 10%;">  <!-- Batch -->
                        <col style="width: 8%;">   <!-- Billed Qty -->
                        <col style="width: 10%;">  <!-- Rate (₹) -->
                        <col style="width: 7%;">   <!-- Disc % -->
                        <col style="width: 7%;">   <!-- GST % -->
                        <col style="width: 10%;">  <!-- Taxable -->
                        <col style="width: 11%;">  <!-- Total -->
                    </colgroup>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th class="text-left" style="padding-left: 6px;">Medicine Description</th>
                            <th>Batch</th>
                            <th class="text-center">Billed Qty</th>
                            <th class="text-right" style="padding-right: 6px;">Rate (₹)</th>
                            <th class="text-center">Disc %</th>
                            <th class="text-center">GST %</th>
                            <th class="text-right" style="padding-right: 6px;">Taxable (₹)</th>
                            <th class="text-right" style="padding-right: 6px;">Total (₹)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($viewInv['items'] as $i => $item): ?>
                            <tr>
                                <td class="text-center"><?= $i + 1 ?></td>
                                <td class="text-left" style="padding-left: 6px;">
                                    <strong><?= htmlspecialchars($item['medicine_name']) ?></strong>
                                    <?php if (!empty($item['generic_name'])): ?>
                                        <div style="font-size: 9.5px; color: #555;"><?= htmlspecialchars($item['generic_name']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center font-mono"><?= htmlspecialchars($item['batch_number'] ?? '—') ?></td>
                                <td class="text-center fw-bold"><?= $item['quantity'] ?></td>
                                <td class="text-right" style="padding-right: 6px;">₹<?= number_format((float)$item['purchase_rate'], 2) ?></td>
                                <td class="text-center"><?= (float)$item['discount_percent'] ?>%</td>
                                <td class="text-center"><?= (float)$item['gst_percent'] ?>%</td>
                                <td class="text-right" style="padding-right: 6px;">₹<?= number_format((float)$item['taxable_amount'], 2) ?></td>
                                <td class="text-right fw-bold" style="padding-right: 6px;">₹<?= number_format((float)$item['line_total'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="footer-grid">
                <div class="footer-box">
                    <div>
                        <div class="summary-line">
                            <span class="summary-label">Reconciliation Status:</span>
                            <span class="summary-val text-success">VERIFIED &amp; MATCHED (<?= htmlspecialchars($viewInv['match_status']) ?>)</span>
                        </div>
                        <div class="summary-line">
                            <span class="summary-label">Payment Mode:</span>
                            <span class="summary-val"><?= htmlspecialchars($viewInv['payment_status']) ?></span>
                        </div>
                    </div>
                    <div style="font-size: 10px; color: #555; margin-top: 10px;">
                        Audited and entered into hospital inventory ledger against verified delivery challan.
                    </div>
                </div>

                <div class="footer-box">
                    <div class="summary-line">
                        <span class="summary-label">Taxable Amount:</span>
                        <span class="summary-val font-mono">₹<?= number_format((float)$viewInv['taxable_amount'], 2) ?></span>
                    </div>
                    <div class="summary-line">
                        <span class="summary-label">Total GST:</span>
                        <span class="summary-val font-mono">₹<?= number_format((float)$viewInv['gst_amount'], 2) ?></span>
                    </div>
                    <?php if ((float)$viewInv['other_charges'] > 0): ?>
                    <div class="summary-line">
                        <span class="summary-label">Other Charges:</span>
                        <span class="summary-val font-mono">₹<?= number_format((float)$viewInv['other_charges'], 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="summary-line grand-total-row">
                        <span class="summary-label">Grand Total:</span>
                        <span class="summary-val font-mono">₹<?= number_format((float)$viewInv['grand_total'], 2) ?></span>
                    </div>
                    <div class="summary-line" style="margin-top: 3px;">
                        <span class="summary-label">Amount Paid:</span>
                        <span class="summary-val font-mono">₹<?= number_format((float)$viewInv['amount_paid'], 2) ?></span>
                    </div>
                    <div class="summary-line">
                        <span class="summary-label">Balance Outstanding:</span>
                        <span class="summary-val font-mono" style="color: #b91c1c;">₹<?= number_format((float)$viewInv['outstanding_amount'], 2) ?></span>
                    </div>
                </div>
            </div>

            <div class="sign-grid">
                <div class="sign-col">
                    <div>Accounts Officer</div>
                    <div class="sign-line">Prepared By</div>
                </div>
                <div class="sign-col">
                    <div>Chief Pharmacist</div>
                    <div class="sign-line">Stock Verified By</div>
                </div>
                <div class="sign-col">
                    <div>Finance Manager</div>
                    <div class="sign-line">Authorized Signatory</div>
                </div>
            </div>
        </div>
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
                <i class="ti ti-file-invoice text-emerald me-2"></i>Purchase Invoices
            </h4>
            <p class="text-muted small mb-0">Supplier billing, 3-way matching (PO vs GRN vs Bill), and payable tracking.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="payments.php" class="btn btn-outline-secondary rounded-pill px-3 py-2 small">
                <i class="ti ti-cash me-1"></i> Supplier Payments
            </a>
            <a href="outstanding.php" class="btn btn-outline-danger rounded-pill px-3 py-2 small">
                <i class="ti ti-clock-exclamation me-1"></i> Overdue Aging
            </a>
            <?php if ($canCreate): ?>
                <button type="button" id="recordSupplierInvoiceBtn" class="btn btn-emerald rounded-pill px-3 py-2 text-white small" style="background-color: #059669;" data-bs-toggle="modal" data-bs-target="#createInvModal" onclick="var m = bootstrap.Modal.getOrCreateInstance(document.getElementById('createInvModal')); m.show();">
                    <i class="ti ti-plus me-1"></i> Record Supplier Invoice
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
            <form method="GET" action="invoices.php" class="row g-2 align-items-center">
                <div class="col-md-3">
                    <input type="text" name="search" class="form-control bg-light border-0" placeholder="Search bill #, supplier..." value="<?= htmlspecialchars($search) ?>">
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
                <div class="col-md-2">
                    <select name="payment_status" class="form-select bg-light border-0">
                        <option value="">All Payment Statuses</option>
                        <option value="UNPAID" <?= $paymentStatus === 'UNPAID' ? 'selected' : '' ?>>Unpaid</option>
                        <option value="PARTIALLY_PAID" <?= $paymentStatus === 'PARTIALLY_PAID' ? 'selected' : '' ?>>Partially Paid</option>
                        <option value="PAID" <?= $paymentStatus === 'PAID' ? 'selected' : '' ?>>Paid</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="match_status" class="form-select bg-light border-0">
                        <option value="">All Match Statuses</option>
                        <option value="MATCHED" <?= $matchStatus === 'MATCHED' ? 'selected' : '' ?>>Matched</option>
                        <option value="QUANTITY_MISMATCH" <?= $matchStatus === 'QUANTITY_MISMATCH' ? 'selected' : '' ?>>Quantity Mismatch</option>
                        <option value="PRICE_MISMATCH" <?= $matchStatus === 'PRICE_MISMATCH' ? 'selected' : '' ?>>Price Mismatch</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-dark w-100 rounded-3">Filter</button>
                    <a href="invoices.php" class="btn btn-outline-secondary rounded-3">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Invoices Table -->
    <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr class="text-uppercase small text-muted">
                        <th class="ps-4">Invoice # & Date</th>
                        <th>Supplier</th>
                        <th>Vendor Bill No</th>
                        <th>GRN / PO Ref</th>
                        <th class="text-end">Grand Total</th>
                        <th class="text-end">Outstanding</th>
                        <th>Match Status</th>
                        <th>Payment</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($invoices)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="ti ti-receipt-off fs-1 d-block mb-2 text-secondary"></i>
                                No purchase invoices found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($invoices as $inv): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($inv['invoice_number']) ?></div>
                                    <div class="text-muted small"><?= date('d-M-Y', strtotime($inv['invoice_date'])) ?></div>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= htmlspecialchars($inv['supplier_name']) ?></div>
                                    <span class="badge bg-light text-secondary font-monospace" style="font-size: 0.7rem;"><?= htmlspecialchars($inv['supplier_code']) ?></span>
                                </td>
                                <td>
                                    <span class="fw-bold font-monospace text-dark"><?= htmlspecialchars($inv['supplier_invoice_no']) ?></span>
                                </td>
                                <td>
                                    <?php if (!empty($inv['grn_number'])): ?>
                                        <div class="badge bg-light text-secondary font-monospace"><?= htmlspecialchars($inv['grn_number']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($inv['po_number'])): ?>
                                        <div class="text-muted" style="font-size: 0.7rem;"><?= htmlspecialchars($inv['po_number']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end fw-bold text-dark">
                                    ₹<?= number_format((float)$inv['grand_total'], 2) ?>
                                </td>
                                <td class="text-end fw-bold <?= (float)$inv['outstanding_amount'] > 0 ? 'text-danger' : 'text-success' ?>">
                                    ₹<?= number_format((float)$inv['outstanding_amount'], 2) ?>
                                </td>
                                <td>
                                    <?php if ($inv['match_status'] === 'MATCHED'): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1"><i class="ti ti-check me-1"></i>Matched</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning-subtle text-dark border border-warning-subtle rounded-pill px-2 py-1"><i class="ti ti-alert-triangle me-1"></i><?= htmlspecialchars($inv['match_status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                        $payBadge = match($inv['payment_status']) {
                                            'PAID' => 'success',
                                            'PARTIALLY_PAID' => 'warning',
                                            default => 'danger'
                                        };
                                    ?>
                                    <span class="badge bg-<?= $payBadge ?>-subtle text-<?= $payBadge ?> rounded-pill px-2 py-1">
                                        <?= htmlspecialchars($inv['payment_status']) ?>
                                    </span>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="d-flex justify-content-end gap-1">
                                        <a href="invoices.php?view_id=<?= $inv['invoice_id'] ?>" class="btn btn-sm btn-light border rounded-pill px-2" title="View Details">
                                            <i class="ti ti-eye"></i>
                                        </a>
                                        <?php if ($canEdit && $inv['payment_status'] !== 'PAID'): ?>
                                            <a href="invoices.php?edit_id=<?= $inv['invoice_id'] ?>" class="btn btn-sm btn-light border rounded-pill px-2" title="Edit Bill">
                                                <i class="ti ti-edit"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="invoices.php?view_id=<?= $inv['invoice_id'] ?>&print=1" target="_blank" class="btn btn-sm btn-light border rounded-pill px-2" title="Print Bill">
                                            <i class="ti ti-printer"></i>
                                        </a>
                                        <?php if ((float)$inv['outstanding_amount'] > 0): ?>
                                            <a href="payments.php?invoice_id=<?= $inv['invoice_id'] ?>&supplier_id=<?= $inv['supplier_id'] ?>" class="btn btn-sm btn-outline-success rounded-pill px-2" title="Record Payment">
                                                <i class="ti ti-cash"></i>
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

<!-- Create / Edit Purchase Invoice Modal -->
<?php if ($canCreate || $editInv): ?>
<div class="modal fade" id="createInvModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content rounded-4 border-0">
            <form method="POST" action="invoices.php" id="invForm">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="<?= $editInv ? 'edit_invoice' : 'create_invoice' ?>">
                <?php if ($editInv): ?>
                    <input type="hidden" name="invoice_id" value="<?= $editInv['invoice_id'] ?>">
                <?php endif; ?>

                <div class="modal-header border-0 px-4 pt-4">
                    <h5 class="fw-bold">
                        <?php if ($editInv): ?>
                            <i class="ti ti-edit text-emerald me-2"></i>Edit Purchase Bill (<?= htmlspecialchars($editInv['invoice_number']) ?>)
                        <?php else: ?>
                            <i class="ti ti-file-invoice text-emerald me-2"></i>Record Supplier Purchase Bill
                        <?php endif; ?>
                    </h5>
                    <?php if ($editInv): ?>
                        <a href="invoices.php" class="btn-close"></a>
                    <?php else: ?>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    <?php endif; ?>
                </div>
                <div class="modal-body px-4">
                    <div class="row g-3 mb-4 p-3 bg-light rounded-3">
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Select Supplier *</label>
                            <select name="supplier_id" id="invSupplierSelect" class="form-select" required>
                                <option value="">-- Choose Vendor --</option>
                                <?php foreach ($activeSuppliers as $sup): ?>
                                    <option value="<?= $sup['supplier_id'] ?>" data-terms="<?= htmlspecialchars($sup['payment_terms']) ?>" <?= ($editInv && (int)$editInv['supplier_id'] === (int)$sup['supplier_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($sup['supplier_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Vendor Bill / Invoice # *</label>
                            <input type="text" name="supplier_invoice_no" class="form-control font-monospace" placeholder="e.g. INV-2026-981" value="<?= htmlspecialchars($editInv['supplier_invoice_no'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold">Bill Date *</label>
                            <input type="date" name="invoice_date" class="form-control" value="<?= htmlspecialchars($editInv['invoice_date'] ?? date('Y-m-d')) ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold">Payment Due Date</label>
                            <input type="date" name="due_date" class="form-control" value="<?= htmlspecialchars($editInv['due_date'] ?? date('Y-m-d', strtotime('+30 days'))) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold">Link GRN (Optional)</label>
                            <select name="grn_id" id="invGrnSelect" class="form-select" onchange="loadGrnItems(this.value)">
                                <option value="">-- Manual Entry --</option>
                                <?php foreach ($availableGrns as $ag): ?>
                                    <option value="<?= $ag['grn_id'] ?>" data-sup="<?= $ag['supplier_id'] ?>" data-po="<?= $ag['po_id'] ?>" <?= ($editInv && (int)$editInv['grn_id'] === (int)$ag['grn_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($ag['grn_number']) ?> (₹<?= number_format((float)$ag['total_amount'], 2) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Invoice Lines -->
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="fw-bold mb-0 text-dark">Billed Line Items</h6>
                        <button type="button" class="btn btn-sm btn-outline-dark rounded-pill" onclick="addInvRow()">
                            <i class="ti ti-plus me-1"></i> Add Line
                        </button>
                    </div>

                    <div class="table-responsive mb-3">
                        <table class="table table-bordered table-sm align-middle" id="invItemsTable">
                            <thead class="table-light small">
                                <tr>
                                    <th style="width: 30%;">Medicine *</th>
                                    <th style="width: 15%;">Batch No</th>
                                    <th style="width: 10%;" class="text-center">Billed Qty *</th>
                                    <th style="width: 12%;">Rate (₹) *</th>
                                    <th style="width: 10%;">Disc %</th>
                                    <th style="width: 10%;">GST %</th>
                                    <th style="width: 15%;" class="text-end">Total (₹)</th>
                                    <th style="width: 3%;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Rendered dynamically -->
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="6" class="text-end">Grand Total:</th>
                                    <th class="text-end text-success fs-6" id="invGrandTotal">₹0.00</th>
                                    <th></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label small fw-semibold">Bill Notes / Verification Comments</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Audit note, verification stamp, or discrepancy remarks..."><?= htmlspecialchars($editInv['notes'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Freight / Other Charges (₹)</label>
                            <input type="number" step="0.01" name="other_charges" id="otherChargesInput" class="form-control" value="<?= htmlspecialchars($editInv['other_charges'] ?? '0.00') ?>" oninput="recalcInvGrand()">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <?php if ($editInv): ?>
                        <a href="invoices.php" class="btn btn-light rounded-pill px-4">Cancel</a>
                    <?php else: ?>
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-emerald rounded-pill px-4 text-white" style="background-color: #059669;">
                        <?= $editInv ? 'Update Purchase Bill' : 'Save Purchase Bill' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const medicinesCatalog = <?= json_encode($pdo->query("SELECT medicine_id, medicine_name, dosage_form, purchase_price, gst_percent FROM medicines WHERE status = 'Active'")->fetchAll(PDO::FETCH_ASSOC)) ?>;

function addInvRow(preset = null) {
    const tableBody = document.querySelector('#invItemsTable tbody');
    const row = document.createElement('tr');

    let medOptions = '<option value="">-- Select Medicine --</option>';
    medicinesCatalog.forEach(m => {
        const sel = preset && parseInt(preset.medicine_id) === parseInt(m.medicine_id) ? 'selected' : '';
        medOptions += `<option value="${m.medicine_id}" data-rate="${m.purchase_price}" data-gst="${m.gst_percent}" ${sel}>${m.medicine_name}</option>`;
    });

    const batchNo = preset ? (preset.batch_number || '') : '';
    const qty = preset ? (preset.quantity || preset.accepted_qty || 10) : 10;
    const rate = preset ? parseFloat(preset.purchase_rate || 0).toFixed(2) : '0.00';
    const disc = preset ? parseFloat(preset.discount_percent || 0).toFixed(2) : '0.00';
    const gst = preset ? parseFloat(preset.gst_percent || 0).toFixed(2) : '0.00';
    const grnItemId = preset ? (preset.grn_item_id || '') : '';

    row.innerHTML = `
        <td>
            <input type="hidden" name="item_grn_item_id[]" value="${grnItemId}">
            <select name="item_medicine_id[]" class="form-select form-select-sm" required onchange="onInvMedChange(this)">
                ${medOptions}
            </select>
        </td>
        <td><input type="text" name="item_batch_number[]" class="form-control form-select-sm font-monospace text-uppercase" value="${batchNo}"></td>
        <td><input type="number" name="item_qty[]" class="form-control form-select-sm text-center qty-col" min="1" value="${qty}" required oninput="calcInvLine(this)"></td>
        <td><input type="number" step="0.01" name="item_rate[]" class="form-control form-select-sm rate-col" min="0" value="${rate}" required oninput="calcInvLine(this)"></td>
        <td><input type="number" step="0.01" name="item_discount[]" class="form-control form-select-sm disc-col" min="0" max="100" value="${disc}" oninput="calcInvLine(this)"></td>
        <td><input type="number" step="0.01" name="item_gst[]" class="form-control form-select-sm gst-col" min="0" value="${gst}" oninput="calcInvLine(this)"></td>
        <td class="text-end fw-bold line-total-col">₹0.00</td>
        <td class="text-center">
            <button type="button" class="btn btn-sm text-danger p-0 border-0" onclick="this.closest('tr').remove(); recalcInvGrand();"><i class="ti ti-trash"></i></button>
        </td>
    `;
    tableBody.appendChild(row);
    calcInvLine(row.querySelector('.qty-col'));
}

function onInvMedChange(sel) {
    const opt = sel.options[sel.selectedIndex];
    const row = sel.closest('tr');
    if (opt.value) {
        row.querySelector('.rate-col').value = opt.dataset.rate || '0.00';
        row.querySelector('.gst-col').value = opt.dataset.gst || '0.00';
    }
    calcInvLine(sel);
}

function calcInvLine(elem) {
    const row = elem.closest('tr');
    const qty = parseFloat(row.querySelector('.qty-col').value) || 0;
    const rate = parseFloat(row.querySelector('.rate-col').value) || 0;
    const disc = parseFloat(row.querySelector('.disc-col').value) || 0;
    const gst = parseFloat(row.querySelector('.gst-col').value) || 0;

    const gross = qty * rate;
    const taxable = gross - (gross * (disc / 100));
    const total = taxable + (taxable * (gst / 100));

    row.querySelector('.line-total-col').textContent = '₹' + total.toFixed(2);
    recalcInvGrand();
}

function recalcInvGrand() {
    let grand = 0;
    document.querySelectorAll('#invItemsTable tbody tr').forEach(row => {
        const qty = parseFloat(row.querySelector('.qty-col').value) || 0;
        const rate = parseFloat(row.querySelector('.rate-col').value) || 0;
        const disc = parseFloat(row.querySelector('.disc-col').value) || 0;
        const gst = parseFloat(row.querySelector('.gst-col').value) || 0;
        const gross = qty * rate;
        const taxable = gross - (gross * (disc / 100));
        grand += taxable + (taxable * (gst / 100));
    });
    const other = parseFloat(document.getElementById('otherChargesInput').value) || 0;
    grand += other;
    document.getElementById('invGrandTotal').textContent = '₹' + Math.round(grand).toFixed(2);
}

document.addEventListener('DOMContentLoaded', () => {
    const editInvItems = <?= !empty($editInv['items']) ? json_encode($editInv['items']) : '[]' ?>;
    if (editInvItems && editInvItems.length > 0) {
        editInvItems.forEach(item => addInvRow(item));
        recalcInvGrand();
        const mEl = document.getElementById('createInvModal');
        if (mEl) {
            const m = bootstrap.Modal.getOrCreateInstance(mEl);
            m.show();
        }
    } else {
        addInvRow();
    }

    const btn = document.getElementById('recordSupplierInvoiceBtn');
    if (btn) {
        btn.addEventListener('click', () => {
            const mEl = document.getElementById('createInvModal');
            if (mEl) {
                const m = bootstrap.Modal.getOrCreateInstance(mEl);
                m.show();
            }
        });
    }
});
</script>
<?php endif; ?>

<!-- View Invoice Modal -->
<?php if ($viewInv): ?>
<div class="modal fade show" id="viewInvModal" tabindex="-1" style="display: block; background: rgba(0,0,0,0.5);">
    <div class="modal-dialog modal-xl">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header border-0 px-4 pt-4">
                <div>
                    <h5 class="fw-bold mb-0 text-dark">
                        <i class="ti ti-file-invoice text-emerald me-2"></i><?= htmlspecialchars($viewInv['invoice_number']) ?>
                    </h5>
                    <span class="badge bg-<?= $viewInv['payment_status'] === 'PAID' ? 'success' : 'danger' ?>-subtle text-<?= $viewInv['payment_status'] === 'PAID' ? 'success' : 'danger' ?>">
                        <?= $viewInv['payment_status'] ?>
                    </span>
                    <span class="badge bg-<?= $viewInv['match_status'] === 'MATCHED' ? 'success' : 'warning' ?>-subtle text-dark ms-1">
                        <?= $viewInv['match_status'] ?>
                    </span>
                </div>
                <a href="invoices.php" class="btn-close"></a>
            </div>
            <div class="modal-body px-4">
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="p-3 border rounded-3 bg-light">
                            <div class="text-muted small">Supplier & Vendor Bill:</div>
                            <h6 class="fw-bold mb-1"><?= htmlspecialchars($viewInv['supplier_name']) ?> (<?= htmlspecialchars($viewInv['supplier_code']) ?>)</h6>
                            <div class="small">Vendor Invoice No: <strong class="font-monospace"><?= htmlspecialchars($viewInv['supplier_invoice_no']) ?></strong></div>
                            <div class="small">GSTIN: <span class="font-monospace"><?= htmlspecialchars($viewInv['supplier_gstin'] ?? 'N/A') ?></span></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 border rounded-3 bg-light">
                            <div class="text-muted small">Financial Particulars:</div>
                            <div class="small">Invoice Date: <strong><?= date('d-M-Y', strtotime($viewInv['invoice_date'])) ?></strong></div>
                            <div class="small">Due Date: <strong><?= !empty($viewInv['due_date']) ? date('d-M-Y', strtotime($viewInv['due_date'])) : 'Standard' ?></strong></div>
                            <div class="small">Amount Paid: <strong class="text-success">₹<?= number_format((float)$viewInv['amount_paid'], 2) ?></strong> | Outstanding: <strong class="text-danger">₹<?= number_format((float)$viewInv['outstanding_amount'], 2) ?></strong></div>
                        </div>
                    </div>
                </div>

                <h6 class="fw-bold text-dark">Billed Items</h6>
                <div class="table-responsive mb-4">
                    <table class="table table-bordered table-sm align-middle">
                        <thead class="table-light small">
                            <tr>
                                <th>#</th>
                                <th>Medicine</th>
                                <th>Batch</th>
                                <th class="text-center">Billed Qty</th>
                                <th class="text-end">Rate (₹)</th>
                                <th class="text-center">GST %</th>
                                <th class="text-end">Taxable (₹)</th>
                                <th class="text-end">Total (₹)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($viewInv['items'] as $i => $it): ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($it['medicine_name']) ?></strong>
                                    </td>
                                    <td class="font-monospace"><?= htmlspecialchars($it['batch_number'] ?? '—') ?></td>
                                    <td class="text-center fw-bold"><?= (int)$it['quantity'] ?></td>
                                    <td class="text-end">₹<?= number_format((float)$it['purchase_rate'], 2) ?></td>
                                    <td class="text-center"><?= (float)$it['gst_percent'] ?>%</td>
                                    <td class="text-end">₹<?= number_format((float)$it['taxable_amount'], 2) ?></td>
                                    <td class="text-end fw-bold">₹<?= number_format((float)$it['line_total'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="7" class="text-end">Grand Total:</th>
                                <th class="text-end text-success fs-6">₹<?= number_format((float)$viewInv['grand_total'], 2) ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Payment History -->
                <?php if (!empty($viewInv['payments'])): ?>
                    <h6 class="fw-bold text-dark mb-2"><i class="ti ti-cash text-success me-1"></i>Settlement / Payment Vouchers</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered">
                            <thead class="table-light small">
                                <tr>
                                    <th>Voucher #</th>
                                    <th>Date</th>
                                    <th>Mode</th>
                                    <th>Reference / UTR</th>
                                    <th class="text-end">Allocated Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($viewInv['payments'] as $p): ?>
                                    <tr>
                                        <td class="font-monospace fw-bold"><?= htmlspecialchars($p['payment_number']) ?></td>
                                        <td><?= date('d-M-Y', strtotime($p['payment_date'])) ?></td>
                                        <td><?= htmlspecialchars($p['payment_mode']) ?></td>
                                        <td><?= htmlspecialchars($p['reference_no'] ?? '—') ?></td>
                                        <td class="text-end fw-bold text-success">₹<?= number_format((float)$p['allocated_amount'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer border-0 px-4 pb-4">
                <a href="invoices.php?view_id=<?= $viewInv['invoice_id'] ?>&print=1" target="_blank" class="btn btn-outline-dark rounded-pill px-3">
                    <i class="ti ti-printer me-1"></i> Print Bill
                </a>
                <?php if ((float)$viewInv['outstanding_amount'] > 0): ?>
                    <a href="payments.php?invoice_id=<?= $viewInv['invoice_id'] ?>&supplier_id=<?= $viewInv['supplier_id'] ?>" class="btn btn-emerald text-white rounded-pill px-3" style="background-color: #059669;">
                        <i class="ti ti-cash me-1"></i> Pay Now
                    </a>
                <?php endif; ?>
                <a href="invoices.php" class="btn btn-light rounded-pill px-4">Close</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
include __DIR__ . '/../../includes/footer.php';
?>