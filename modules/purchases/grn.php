<?php
// modules/purchases/grn.php - Goods Received Note & Physical Stock Inward

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/AuditService.php';
require_once __DIR__ . '/../../app/Services/DocumentSequenceService.php';
require_once __DIR__ . '/../../app/Services/StockLedgerService.php';
require_once __DIR__ . '/../../app/Services/BatchService.php';
require_once __DIR__ . '/../../app/Services/SupplierService.php';
require_once __DIR__ . '/../../app/Services/PurchaseOrderService.php';
require_once __DIR__ . '/../../app/Services/GrnService.php';

require_permission('pharmacy.grn.view');

use Pharmacy\Services\SupplierService;
use Pharmacy\Services\PurchaseOrderService;
use Pharmacy\Services\GrnService;

$grnService = new GrnService($pdo);
$poService = new PurchaseOrderService($pdo);
$supplierService = new SupplierService($pdo);

$page_title = 'Goods Received Notes';
$user = auth_user();
$userId = (int)($user['id'] ?? 1);

$canCreate = has_permission('pharmacy.grn.create') || has_permission('pharmacy.grn.post');
$canCancel = has_permission('pharmacy.grn.cancel');

$feedback = null;
$error = null;

// AJAX Endpoint: Get single GRN details for editing modal
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_grn') {
    header('Content-Type: application/json');
    $grnId = (int)($_GET['grn_id'] ?? 0);
    $grn = $grnService->getGrn($grnId);
    if (!$grn) {
        echo json_encode(['success' => false, 'message' => 'GRN not found']);
    } else {
        echo json_encode(['success' => true, 'grn' => $grn]);
    }
    exit;
}

// Handle Form Submissions (Post GRN / Edit GRN / Cancel GRN)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Security token mismatch or expired. Please refresh and try again.";
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'post_grn') {
                if (!$canCreate) throw new Exception("Permission denied: Cannot post goods receipt.");

                $header = [
                    'supplier_id'          => (int)($_POST['supplier_id'] ?? 0),
                    'po_id'                => !empty($_POST['po_id']) ? (int)$_POST['po_id'] : null,
                    'grn_date'             => $_POST['grn_date'] ?? date('Y-m-d'),
                    'supplier_invoice_no'  => trim($_POST['supplier_invoice_no'] ?? ''),
                    'supplier_invoice_date'=> !empty($_POST['supplier_invoice_date']) ? $_POST['supplier_invoice_date'] : null,
                    'receiving_location'   => trim($_POST['receiving_location'] ?? 'Main Pharmacy Store'),
                    'notes'                => trim($_POST['notes'] ?? ''),
                    'idempotency_key'      => trim($_POST['idempotency_key'] ?? '')
                ];

                $items = [];
                $medIds = $_POST['item_medicine_id'] ?? [];
                $poItemIds = $_POST['item_po_item_id'] ?? [];
                $batches = $_POST['item_batch_number'] ?? [];
                $mfgDates = $_POST['item_mfg_date'] ?? [];
                $expiryDates = $_POST['item_expiry_date'] ?? [];
                $receivedQtys = $_POST['item_received_qty'] ?? [];
                $freeQtys = $_POST['item_free_qty'] ?? [];
                $rejectedQtys = $_POST['item_rejected_qty'] ?? [];
                $damagedQtys = $_POST['item_damaged_qty'] ?? [];
                $rates = $_POST['item_purchase_rate'] ?? [];
                $mrps = $_POST['item_mrp'] ?? [];
                $sales = $_POST['item_sale_price'] ?? [];
                $gsts = $_POST['item_gst_percent'] ?? [];
                $shelves = $_POST['item_shelf_location'] ?? [];
                $remarks = $_POST['item_remarks'] ?? [];

                foreach ($medIds as $k => $mId) {
                    $mId = (int)$mId;
                    $batchNum = trim($batches[$k] ?? '');
                    $exp = trim($expiryDates[$k] ?? '');
                    $recQty = (int)($receivedQtys[$k] ?? 0);

                    if ($mId > 0 && $batchNum !== '' && $recQty > 0) {
                        $items[] = [
                            'po_item_id'       => !empty($poItemIds[$k]) ? (int)$poItemIds[$k] : null,
                            'medicine_id'      => $mId,
                            'batch_number'     => $batchNum,
                            'mfg_date'         => !empty($mfgDates[$k]) ? $mfgDates[$k] : null,
                            'expiry_date'      => $exp,
                            'received_qty'     => $recQty,
                            'free_qty'         => (int)($freeQtys[$k] ?? 0),
                            'rejected_qty'     => (int)($rejectedQtys[$k] ?? 0),
                            'damaged_qty'      => (int)($damagedQtys[$k] ?? 0),
                            'purchase_rate'    => (float)($rates[$k] ?? 0.00),
                            'mrp'              => (float)($mrps[$k] ?? 0.00),
                            'sale_price'       => (float)($sales[$k] ?? ($mrps[$k] ?? 0.00)),
                            'gst_percent'      => (float)($gsts[$k] ?? 0.00),
                            'shelf_location'   => !empty($shelves[$k]) ? trim($shelves[$k]) : null,
                            'remarks'          => !empty($remarks[$k]) ? trim($remarks[$k]) : null
                        ];
                    }
                }

                if (empty($items)) {
                    throw new Exception("Please specify at least one valid item with batch, expiry, and quantity.");
                }

                $allowOver = !empty($_POST['allow_over_receipt']);
                $overReason = trim($_POST['over_receipt_reason'] ?? '');

                $grnId = $grnService->createAndPostGrn($header, $items, $userId, $allowOver, $overReason);
                $savedGrn = $grnService->getGrn($grnId);
                $grnNumStr = $savedGrn ? htmlspecialchars($savedGrn['grn_number']) : "#{$grnId}";
                $feedback = "GRN {$grnNumStr} posted successfully! Stock inward and batch inventory have been updated. <a href='grn.php?view_id={$grnId}' class='alert-link fw-bold ms-2 text-decoration-underline'><i class='ti ti-eye'></i> View GRN Details</a>";
            } elseif ($action === 'edit_full_grn' || $action === 'edit_metadata') {
                $grnId = (int)($_POST['grn_id'] ?? 0);
                $header = [
                    'grn_date'              => $_POST['grn_date'] ?? date('Y-m-d'),
                    'supplier_invoice_no'   => trim($_POST['supplier_invoice_no'] ?? ''),
                    'supplier_invoice_date' => !empty($_POST['supplier_invoice_date']) ? $_POST['supplier_invoice_date'] : null,
                    'receiving_location'    => trim($_POST['receiving_location'] ?? 'Main Pharmacy Store'),
                    'notes'                 => trim($_POST['notes'] ?? '')
                ];

                $items = [];
                $grnItemIds = $_POST['edit_grn_item_id'] ?? [];
                $medIds = $_POST['edit_medicine_id'] ?? [];
                $batches = $_POST['edit_batch_number'] ?? [];
                $mfgDates = $_POST['edit_mfg_date'] ?? [];
                $expiryDates = $_POST['edit_expiry_date'] ?? [];
                $receivedQtys = $_POST['edit_received_qty'] ?? [];
                $freeQtys = $_POST['edit_free_qty'] ?? [];
                $rejectedQtys = $_POST['edit_rejected_qty'] ?? [];
                $damagedQtys = $_POST['edit_damaged_qty'] ?? [];
                $rates = $_POST['edit_purchase_rate'] ?? [];
                $mrps = $_POST['edit_mrp'] ?? [];
                $sales = $_POST['edit_sale_price'] ?? [];
                $gsts = $_POST['edit_gst_percent'] ?? [];
                $shelves = $_POST['edit_shelf_location'] ?? [];
                $remarks = $_POST['edit_remarks'] ?? [];

                if (!empty($batches)) {
                    foreach ($batches as $k => $batchNum) {
                        $batchNum = trim($batchNum);
                        $mId = (int)($medIds[$k] ?? 0);
                        $recQty = (int)($receivedQtys[$k] ?? 0);
                        $exp = trim($expiryDates[$k] ?? '');

                        if ($batchNum !== '' && $recQty > 0 && $exp !== '') {
                            $items[] = [
                                'grn_item_id'      => !empty($grnItemIds[$k]) ? (int)$grnItemIds[$k] : null,
                                'medicine_id'      => $mId,
                                'batch_number'     => $batchNum,
                                'mfg_date'         => !empty($mfgDates[$k]) ? $mfgDates[$k] : null,
                                'expiry_date'      => $exp,
                                'received_qty'     => $recQty,
                                'free_qty'         => (int)($freeQtys[$k] ?? 0),
                                'rejected_qty'     => (int)($rejectedQtys[$k] ?? 0),
                                'damaged_qty'      => (int)($damagedQtys[$k] ?? 0),
                                'purchase_rate'    => (float)($rates[$k] ?? 0.00),
                                'mrp'              => (float)($mrps[$k] ?? 0.00),
                                'sale_price'       => (float)($sales[$k] ?? ($mrps[$k] ?? 0.00)),
                                'gst_percent'      => (float)($gsts[$k] ?? 0.00),
                                'shelf_location'   => !empty($shelves[$k]) ? trim($shelves[$k]) : null,
                                'remarks'          => !empty($remarks[$k]) ? trim($remarks[$k]) : null
                            ];
                        }
                    }

                    if (empty($items)) {
                        throw new Exception("Please specify at least one valid item line with batch, expiry, and quantity.");
                    }

                    $grnService->updateFullGrn($grnId, $header, $items, $userId);
                } else {
                    $grnService->updateGrnMetadata($grnId, $header, $userId);
                }

                $savedGrn = $grnService->getGrn($grnId);
                $grnNumStr = $savedGrn ? htmlspecialchars($savedGrn['grn_number']) : "#{$grnId}";
                $feedback = "GRN {$grnNumStr} & Batch records updated successfully! Physical inventory and stock ledger updated. <a href='grn.php?view_id={$grnId}' class='alert-link fw-bold ms-2 text-decoration-underline'><i class='ti ti-eye'></i> View Details</a>";
            } elseif ($action === 'cancel_grn') {
                if (!$canCancel) throw new Exception("Permission denied: Cannot cancel GRN.");
                $grnId = (int)($_POST['grn_id'] ?? 0);
                $reason = trim($_POST['reversal_reason'] ?? 'Cancelled by authorized user');
                $grnService->cancelGrn($grnId, $reason, $userId);
                $feedback = "GRN #{$grnId} cancelled and compensating inventory movements posted to stock ledger.";
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Filters
$supplierId = !empty($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : null;
$poIdFilter = !empty($_GET['po_id']) ? (int)$_GET['po_id'] : null;
$search = trim($_GET['search'] ?? '');
$filters = [];
if ($supplierId) $filters['supplier_id'] = $supplierId;
if ($poIdFilter) $filters['po_id'] = $poIdFilter;
if ($search !== '') $filters['search'] = $search;

$grns = $grnService->listGrns($filters);

$activeSuppliers = $pdo->query("SELECT supplier_id, supplier_name, supplier_code FROM pharmacy_suppliers WHERE status = 'Active' ORDER BY supplier_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$approvedPos = $pdo->query("SELECT po_id, po_number, supplier_id, po_date FROM pharmacy_purchase_orders WHERE status IN ('APPROVED', 'PARTIALLY_RECEIVED') ORDER BY po_id DESC")->fetchAll(PDO::FETCH_ASSOC);
$medicinesCatalog = $pdo->query("
    SELECT m.medicine_id, m.medicine_name, m.generic_name, m.dosage_form, m.strength, m.shelf, m.purchase_price, m.price as mrp, m.gst_percent,
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

// If po_id is provided in GET to start receiving against an approved PO
$preloadedPo = null;
if (!empty($_GET['po_id'])) {
    $preloadedPo = $poService->getPurchaseOrder((int)$_GET['po_id']);
}

// View GRN details modal
$viewGrn = null;
if (!empty($_GET['view_id'])) {
    $viewGrn = $grnService->getGrn((int)$_GET['view_id']);
}

// Print mode
$isPrint = isset($_GET['print']) && $viewGrn;
if ($isPrint) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>GRN Print - <?= htmlspecialchars($viewGrn['grn_number']) ?></title>
        <link href="<?= BASE_URL ?>assets/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 13px; color: #111; padding: 20px; }
            .grn-header { border-bottom: 2px solid #059669; padding-bottom: 12px; margin-bottom: 20px; }
            .table-sm th, .table-sm td { padding: 6px 8px; }
            @media print { .no-print { display: none; } }
        </style>
    </head>
    <body>
        <div class="no-print mb-3 text-end">
            <button type="button" onclick="window.print()" class="btn btn-primary btn-sm">Print Receipt Note</button>
            <button type="button" onclick="window.close()" class="btn btn-secondary btn-sm">Close</button>
        </div>
        <div class="grn-header d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-3">
                <img src="<?= BASE_URL ?>assets/images/vatsalya_logo.png" alt="Vatsalya Hospital Logo" style="max-height: 52px; object-fit: contain;">
                <div>
                    <h3 class="fw-bold mb-0 text-success"><?= APP_NAME ?></h3>
                    <div class="text-muted small">Vatsalya Hospital | Central Receiving &amp; Quality Control</div>
                </div>
            </div>
            <div class="text-end">
                <h4 class="fw-bold mb-0">GOODS RECEIVED NOTE</h4>
                <div class="fw-bold font-monospace"><?= htmlspecialchars($viewGrn['grn_number']) ?></div>
                <div class="small">Date: <?= date('d-M-Y', strtotime($viewGrn['grn_date'])) ?></div>
                <div class="small">Status: <?= htmlspecialchars($viewGrn['status']) ?></div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-6">
                <div class="p-3 border rounded">
                    <div class="fw-bold text-uppercase small text-muted mb-1">Received From Supplier:</div>
                    <div class="fw-bold fs-6"><?= htmlspecialchars($viewGrn['supplier_name']) ?> (<?= htmlspecialchars($viewGrn['supplier_code']) ?>)</div>
                    <div class="small">GSTIN: <span class="font-monospace"><?= htmlspecialchars($viewGrn['supplier_gstin'] ?? 'N/A') ?></span></div>
                    <div class="small">Vendor Invoice No: <strong class="font-monospace"><?= htmlspecialchars($viewGrn['supplier_invoice_no'] ?? 'N/A') ?></strong></div>
                </div>
            </div>
            <div class="col-6">
                <div class="p-3 border rounded">
                    <div class="fw-bold text-uppercase small text-muted mb-1">Receiving Particulars:</div>
                    <div class="small">Purchase Order Ref: <strong><?= htmlspecialchars($viewGrn['po_number'] ?? 'Direct Supplier Inward') ?></strong></div>
                    <div class="small">Receiving Bay/Store: <strong><?= htmlspecialchars($viewGrn['receiving_location']) ?></strong></div>
                    <div class="small">Received & Posted By: <strong><?= htmlspecialchars($viewGrn['poster_name'] ?? 'Authorized Pharmacist') ?></strong></div>
                </div>
            </div>
        </div>

        <table class="table table-bordered table-sm">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Medicine Description</th>
                    <th>Batch No</th>
                    <th>Expiry</th>
                    <th class="text-center">Recv</th>
                    <th class="text-center">Rej</th>
                    <th class="text-center">Dam</th>
                    <th class="text-center">Accepted</th>
                    <th class="text-end">Rate (₹)</th>
                    <th class="text-end">MRP (₹)</th>
                    <th class="text-end">Total (₹)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($viewGrn['items'] as $i => $item): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td>
                            <strong><?= htmlspecialchars($item['medicine_name']) ?></strong>
                            <div class="text-muted" style="font-size: 10px;"><?= htmlspecialchars($item['generic_name'] ?? '') ?></div>
                        </td>
                        <td class="font-monospace fw-bold"><?= htmlspecialchars($item['batch_number']) ?></td>
                        <td class="font-monospace"><?= date('m/y', strtotime($item['expiry_date'])) ?></td>
                        <td class="text-center"><?= $item['received_qty'] ?></td>
                        <td class="text-center text-danger"><?= $item['rejected_qty'] ?></td>
                        <td class="text-center text-warning"><?= $item['damaged_qty'] ?></td>
                        <td class="text-center fw-bold text-success"><?= $item['accepted_qty'] ?></td>
                        <td class="text-end"><?= number_format((float)$item['purchase_rate'], 2) ?></td>
                        <td class="text-end"><?= number_format((float)$item['mrp'], 2) ?></td>
                        <td class="text-end fw-bold"><?= number_format((float)$item['line_total'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="10" class="text-end">Total Billed Inward Amount:</th>
                    <th class="text-end fs-6">₹<?= number_format((float)$viewGrn['total_amount'], 2) ?></th>
                </tr>
            </tfoot>
        </table>

        <div class="row mt-5 pt-4 text-center">
            <div class="col-4">
                <div class="border-top pt-2">Delivery Driver / Courier</div>
            </div>
            <div class="col-4">
                <div class="border-top pt-2">Receiving Pharmacist</div>
            </div>
            <div class="col-4">
                <div class="border-top pt-2">Store Manager Approval</div>
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
                <i class="ti ti-truck-loading text-emerald me-2"></i>Goods Received Notes (GRN)
            </h4>
            <p class="text-muted small mb-0">Physical stock receipt verification, batch allocation, expiry inspection, and inventory inward.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="invoices.php" class="btn btn-outline-secondary rounded-pill px-3 py-2 small">
                <i class="ti ti-file-invoice me-1"></i> Purchase Invoices
            </a>
            <?php if ($canCreate): ?>
                <button type="button" class="btn btn-emerald rounded-pill px-3 py-2 text-white small" style="background-color: #059669;" data-bs-toggle="modal" data-bs-target="#createGrnModal">
                    <i class="ti ti-plus me-1"></i> Receive Stock (New GRN)
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
            <form method="GET" action="grn.php" class="row g-2 align-items-center">
                <div class="col-md-5">
                    <input type="text" name="search" class="form-control bg-light border-0" placeholder="Search GRN #, Supplier invoice #, or supplier..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-4">
                    <select name="supplier_id" class="form-select bg-light border-0">
                        <option value="">All Suppliers</option>
                        <?php foreach ($activeSuppliers as $sup): ?>
                            <option value="<?= $sup['supplier_id'] ?>" <?= $supplierId === (int)$sup['supplier_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sup['supplier_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-dark w-100 rounded-3">Filter</button>
                    <a href="grn.php" class="btn btn-outline-secondary rounded-3">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- GRN List Table -->
    <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr class="text-uppercase small text-muted">
                        <th class="ps-4">GRN Number & Date</th>
                        <th>Supplier & Bill No</th>
                        <th>PO Reference</th>
                        <th>Quantities (Recv / Accepted)</th>
                        <th class="text-end">Total Value</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($grns)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="ti ti-package fs-1 d-block mb-2 text-secondary"></i>
                                No goods received notes recorded yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($grns as $g): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($g['grn_number']) ?></div>
                                    <div class="text-muted small"><?= date('d-M-Y', strtotime($g['grn_date'])) ?></div>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= htmlspecialchars($g['supplier_name']) ?></div>
                                    <div class="text-muted small">Inv: <span class="font-monospace fw-bold"><?= htmlspecialchars($g['supplier_invoice_no'] ?? '—') ?></span></div>
                                </td>
                                <td>
                                    <?php if (!empty($g['po_number'])): ?>
                                        <span class="badge bg-primary-subtle text-primary font-monospace"><?= htmlspecialchars($g['po_number']) ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-secondary">Direct Receiving</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="small fw-semibold text-dark">Accepted: <span class="text-success"><?= (int)$g['total_accepted'] ?></span> / Recv: <?= (int)$g['total_received'] ?></div>
                                    <?php if ((int)$g['total_rejected'] > 0): ?>
                                        <div class="text-danger small" style="font-size: 0.72rem;">Rejected/Damaged: <?= (int)$g['total_rejected'] ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end fw-bold text-dark">
                                    ₹<?= number_format((float)$g['total_amount'], 2) ?>
                                </td>
                                <td>
                                    <?php if ($g['status'] === 'POSTED'): ?>
                                        <span class="badge bg-success-subtle text-success rounded-pill px-2 py-1">POSTED (IN STOCK)</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger rounded-pill px-2 py-1">CANCELLED</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="d-flex justify-content-end gap-1">
                                        <a href="grn.php?view_id=<?= $g['grn_id'] ?>" class="btn btn-sm btn-light border rounded-pill px-2" title="View GRN Details">
                                            <i class="ti ti-eye"></i>
                                        </a>
                                        <?php if ($g['status'] === 'POSTED'): ?>
                                            <button type="button" class="btn btn-sm btn-light border rounded-pill px-2" title="Edit GRN Details & Batches" onclick="openEditGrnModal(<?= (int)$g['grn_id'] ?>)">
                                                <i class="ti ti-edit"></i>
                                            </button>
                                        <?php endif; ?>
                                        <a href="grn.php?view_id=<?= $g['grn_id'] ?>&print=1" target="_blank" class="btn btn-sm btn-light border rounded-pill px-2" title="Print Note">
                                            <i class="ti ti-printer"></i>
                                        </a>
                                        <a href="../inventory/ledger.php?search=<?= urlencode($g['grn_number']) ?>" class="btn btn-sm btn-light border rounded-pill px-2" title="View Inward Ledger">
                                            <i class="ti ti-book-2"></i>
                                        </a>
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

<!-- Create GRN Modal -->
<?php if ($canCreate): ?>
<div class="modal fade <?= $preloadedPo ? 'show' : '' ?>" id="createGrnModal" tabindex="-1" style="<?= $preloadedPo ? 'display:block; background:rgba(0,0,0,0.5);' : '' ?>">
    <div class="modal-dialog modal-xl">
        <div class="modal-content rounded-4 border-0">
            <form method="POST" action="grn.php" id="grnForm">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="post_grn">
                <!-- Double-click / Idempotency prevention key -->
                <input type="hidden" name="idempotency_key" id="grnIdempotencyKey" value="<?= bin2hex(random_bytes(16)) ?>">

                <div class="modal-header border-0 px-4 pt-4">
                    <h5 class="fw-bold"><i class="ti ti-truck-loading text-emerald me-2"></i>Receive Stock & Post GRN</h5>
                    <?php if ($preloadedPo): ?>
                        <a href="grn.php" class="btn-close"></a>
                    <?php else: ?>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    <?php endif; ?>
                </div>
                <div class="modal-body px-4">
                    <!-- Header Info -->
                    <div class="row g-3 mb-4 p-3 bg-light rounded-3">
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Supplier *</label>
                            <select name="supplier_id" id="grnSupplierSelect" class="form-select" required>
                                <option value="">-- Choose Vendor --</option>
                                <?php foreach ($activeSuppliers as $sup): ?>
                                    <option value="<?= $sup['supplier_id'] ?>" <?= ($preloadedPo && (int)$preloadedPo['supplier_id'] === (int)$sup['supplier_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($sup['supplier_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Linked PO (Optional)</label>
                            <select name="po_id" id="grnPoSelect" class="form-select">
                                <option value="">-- Direct Supplier Delivery --</option>
                                <?php foreach ($approvedPos as $po): ?>
                                    <option value="<?= $po['po_id'] ?>" <?= ($preloadedPo && (int)$preloadedPo['po_id'] === (int)$po['po_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($po['po_number']) ?> (<?= date('d-M', strtotime($po['po_date'])) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold">Receiving Date *</label>
                            <input type="date" name="grn_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold">Supplier Invoice / Challan #</label>
                            <input type="text" name="supplier_invoice_no" class="form-control" placeholder="e.g. INV-98231">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold">Invoice Date</label>
                            <input type="date" name="supplier_invoice_date" class="form-control">
                        </div>
                    </div>

                    <!-- Line Items Table -->
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="fw-bold mb-0 text-dark">Received Product Batches</h6>
                        <button type="button" class="btn btn-sm btn-outline-dark rounded-pill" onclick="addGrnRow()">
                            <i class="ti ti-plus me-1"></i> Add Batch Line
                        </button>
                    </div>

                    <div class="table-responsive mb-3">
                        <table class="table table-bordered table-sm align-middle" id="grnItemsTable">
                            <thead class="table-light small">
                                <tr>
                                    <th style="width: 20%;">Medicine *</th>
                                    <th style="width: 12%;">Batch No *</th>
                                    <th style="width: 10%;">Expiry *</th>
                                    <th style="width: 8%;" class="text-center">Recv Qty *</th>
                                    <th style="width: 7%;" class="text-center">Rej Qty</th>
                                    <th style="width: 7%;" class="text-center">Dam Qty</th>
                                    <th style="width: 8%;" class="text-center">Accepted</th>
                                    <th style="width: 10%;">Cost Rate (₹)</th>
                                    <th style="width: 9%;">MRP (₹)</th>
                                    <th style="width: 9%;">Shelf/Rack</th>
                                    <th style="width: 3%;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Rows will be rendered by JS -->
                            </tbody>
                        </table>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label small fw-semibold">Receiving Notes / Remarks</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Cold-chain verification, damaged container details, etc."></textarea>
                        </div>
                        <div class="col-md-5">
                            <div class="p-3 border rounded-3 bg-light">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="allow_over_receipt" value="1" id="allowOverCheck" onchange="toggleOverReason(this)">
                                    <label class="form-check-label small fw-semibold text-danger" for="allowOverCheck">
                                        Authorize Over-Receipt (if received units exceed PO order)
                                    </label>
                                </div>
                                <input type="text" name="over_receipt_reason" id="overReceiptReasonInput" class="form-control form-control-sm d-none" placeholder="Required reason for over-receipt...">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <?php if ($preloadedPo): ?>
                        <a href="grn.php" class="btn btn-light rounded-pill px-4">Cancel</a>
                    <?php else: ?>
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <?php endif; ?>
                    <button type="submit" id="submitGrnBtn" class="btn btn-emerald rounded-pill px-4 text-white" style="background-color: #059669;">Post GRN & Inward Stock</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const medicinesCatalog = <?= json_encode($medicinesCatalog) ?>;
const preloadedPo = <?= json_encode($preloadedPo) ?>;

function toggleOverReason(chk) {
    const input = document.getElementById('overReceiptReasonInput');
    if (chk.checked) {
        input.classList.remove('d-none');
        input.required = true;
    } else {
        input.classList.add('d-none');
        input.required = false;
    }
}

function addGrnRow(preset = null) {
    const tableBody = document.querySelector('#grnItemsTable tbody');
    const row = document.createElement('tr');

    let medOptions = '<option value="">-- Select Medicine --</option>';
    medicinesCatalog.forEach(m => {
        const sel = preset && parseInt(preset.medicine_id) === parseInt(m.medicine_id) ? 'selected' : '';
        const avail = parseInt(m.available_stock) || 0;
        const icon = avail > 10 ? '🟢' : (avail > 0 ? '🟡' : '🔴');
        medOptions += `<option value="${m.medicine_id}" data-rate="${m.purchase_price}" data-mrp="${m.mrp}" data-shelf="${m.shelf_location || ''}" data-gst="${m.gst_percent}" data-stock="${avail}" ${sel}>${icon} [Stock: ${avail}] ${m.medicine_name} (${m.dosage_form || ''})</option>`;
    });

    const poItemId = preset ? (preset.po_item_id || '') : '';
    const defQty = preset ? Math.max(1, (preset.requested_qty - (preset.received_qty || 0))) : 10;
    const defRate = preset ? preset.purchase_rate : '0.00';
    const defMrp = preset ? preset.expected_mrp : '0.00';
    const defGst = preset ? preset.gst_percent : '0.00';

    row.innerHTML = `
        <td>
            <input type="hidden" name="item_po_item_id[]" value="${poItemId}">
            <input type="hidden" name="item_gst_percent[]" class="gst-col" value="${defGst}">
            <select name="item_medicine_id[]" class="form-select form-select-sm med-select" required onchange="onGrnMedChange(this)">
                ${medOptions}
            </select>
        </td>
        <td><input type="text" name="item_batch_number[]" class="form-control form-select-sm font-monospace text-uppercase" placeholder="BATCH123" required></td>
        <td><input type="date" name="item_expiry_date[]" class="form-control form-select-sm" required min="<?= date('Y-m-d') ?>"></td>
        <td><input type="number" name="item_received_qty[]" class="form-control form-select-sm text-center recv-input" min="1" value="${defQty}" required oninput="calcAccepted(this)"></td>
        <td><input type="number" name="item_rejected_qty[]" class="form-control form-select-sm text-center rej-input" min="0" value="0" oninput="calcAccepted(this)"></td>
        <td><input type="number" name="item_damaged_qty[]" class="form-control form-select-sm text-center dam-input" min="0" value="0" oninput="calcAccepted(this)"></td>
        <td class="text-center fw-bold text-success accepted-col">${defQty}</td>
        <td><input type="number" step="0.01" name="item_purchase_rate[]" class="form-control form-select-sm rate-input" value="${defRate}" required></td>
        <td><input type="number" step="0.01" name="item_mrp[]" class="form-control form-select-sm mrp-input" value="${defMrp}" required></td>
        <td><input type="text" name="item_shelf_location[]" class="form-control form-select-sm shelf-input" placeholder="Rack-A"></td>
        <td class="text-center">
            <button type="button" class="btn btn-sm text-danger p-0 border-0" onclick="this.closest('tr').remove();"><i class="ti ti-trash"></i></button>
        </td>
    `;
    tableBody.appendChild(row);
}

function onGrnMedChange(select) {
    const opt = select.options[select.selectedIndex];
    const row = select.closest('tr');
    if (opt.value) {
        row.querySelector('.rate-input').value = opt.dataset.rate || '0.00';
        row.querySelector('.mrp-input').value = opt.dataset.mrp || '0.00';
        row.querySelector('.shelf-input').value = opt.dataset.shelf || '';
        row.querySelector('.gst-col').value = opt.dataset.gst || '0.00';
    }
}

function calcAccepted(elem) {
    const row = elem.closest('tr');
    const recv = parseInt(row.querySelector('.recv-input').value) || 0;
    const rej = parseInt(row.querySelector('.rej-input').value) || 0;
    const dam = parseInt(row.querySelector('.dam-input').value) || 0;
    const accepted = Math.max(0, recv - rej - dam);
    row.querySelector('.accepted-col').textContent = accepted;
}

// Double-click defense on form submit
document.getElementById('grnForm').addEventListener('submit', function(e) {
    const btn = document.getElementById('submitGrnBtn');
    if (btn.disabled) {
        e.preventDefault();
        return false;
    }
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Posting Inward...';
});

document.addEventListener('DOMContentLoaded', () => {
    if (preloadedPo && preloadedPo.items && preloadedPo.items.length > 0) {
        preloadedPo.items.forEach(it => {
            addGrnRow(it);
        });
    } else {
        addGrnRow();
    }
});
</script>
<?php endif; ?>

<!-- View GRN Modal -->
<?php if ($viewGrn): ?>
<div class="modal fade show" id="viewGrnModal" tabindex="-1" style="display: block; background: rgba(0,0,0,0.5);">
    <div class="modal-dialog modal-xl">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header border-0 px-4 pt-4">
                <div>
                    <h5 class="fw-bold mb-0 text-dark">
                        <i class="ti ti-truck-loading text-emerald me-2"></i><?= htmlspecialchars($viewGrn['grn_number']) ?>
                    </h5>
                    <span class="badge bg-<?= $viewGrn['status'] === 'POSTED' ? 'success' : 'danger' ?>-subtle text-<?= $viewGrn['status'] === 'POSTED' ? 'success' : 'danger' ?>">
                        <?= $viewGrn['status'] ?>
                    </span>
                    <span class="text-muted small ms-2">Date: <?= date('d-M-Y', strtotime($viewGrn['grn_date'])) ?></span>
                </div>
                <a href="grn.php" class="btn-close"></a>
            </div>
            <div class="modal-body px-4">
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="p-3 border rounded-3 bg-light">
                            <div class="text-muted small">Supplier Details:</div>
                            <h6 class="fw-bold mb-1"><?= htmlspecialchars($viewGrn['supplier_name']) ?> (<?= htmlspecialchars($viewGrn['supplier_code']) ?>)</h6>
                            <div class="small">Vendor Invoice: <strong class="font-monospace"><?= htmlspecialchars($viewGrn['supplier_invoice_no'] ?? 'N/A') ?></strong></div>
                            <div class="small">GSTIN: <span class="font-monospace"><?= htmlspecialchars($viewGrn['supplier_gstin'] ?? 'N/A') ?></span></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 border rounded-3 bg-light">
                            <div class="text-muted small">Inward Particulars:</div>
                            <div class="small">Linked PO: <strong><?= htmlspecialchars($viewGrn['po_number'] ?? 'Direct Supplier Delivery') ?></strong></div>
                            <div class="small">Receiving Location: <strong><?= htmlspecialchars($viewGrn['receiving_location']) ?></strong></div>
                            <div class="small">Posted By: <?= htmlspecialchars($viewGrn['poster_name'] ?? 'Staff') ?></div>
                        </div>
                    </div>
                </div>

                <h6 class="fw-bold text-dark">Accepted & Received Batches</h6>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm align-middle">
                        <thead class="table-light small">
                            <tr>
                                <th>#</th>
                                <th>Medicine</th>
                                <th>Batch No</th>
                                <th>Expiry</th>
                                <th class="text-center">Recv</th>
                                <th class="text-center">Rej</th>
                                <th class="text-center">Dam</th>
                                <th class="text-center">Accepted</th>
                                <th class="text-end">Cost Rate</th>
                                <th class="text-end">MRP</th>
                                <th class="text-end">Line Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($viewGrn['items'] as $i => $it): ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($it['medicine_name']) ?></strong>
                                        <div class="text-muted small" style="font-size: 0.72rem;"><?= htmlspecialchars($it['generic_name'] ?? '') ?></div>
                                    </td>
                                    <td class="font-monospace fw-bold"><?= htmlspecialchars($it['batch_number']) ?></td>
                                    <td class="font-monospace"><?= date('m/y', strtotime($it['expiry_date'])) ?></td>
                                    <td class="text-center"><?= (int)$it['received_qty'] ?></td>
                                    <td class="text-center text-danger"><?= (int)$it['rejected_qty'] ?></td>
                                    <td class="text-center text-warning"><?= (int)$it['damaged_qty'] ?></td>
                                    <td class="text-center fw-bold text-success"><?= (int)$it['accepted_qty'] ?></td>
                                    <td class="text-end">₹<?= number_format((float)$it['purchase_rate'], 2) ?></td>
                                    <td class="text-end">₹<?= number_format((float)$it['mrp'], 2) ?></td>
                                    <td class="text-end fw-bold">₹<?= number_format((float)$it['line_total'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="10" class="text-end">Grand Total Inward:</th>
                                <th class="text-end text-success fs-6">₹<?= number_format((float)$viewGrn['total_amount'], 2) ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <div class="modal-footer border-0 px-4 pb-4">
                <a href="grn.php?view_id=<?= $viewGrn['grn_id'] ?>&print=1" target="_blank" class="btn btn-outline-dark rounded-pill px-3">
                    <i class="ti ti-printer me-1"></i> Print Receipt Note
                </a>
                <a href="../inventory/ledger.php?search=<?= urlencode($viewGrn['grn_number']) ?>" class="btn btn-outline-secondary rounded-pill px-3">
                    <i class="ti ti-book-2 me-1"></i> View Stock Ledger Entries
                </a>
                <?php if ($viewGrn['status'] === 'POSTED' && $canCancel): ?>
                    <button type="button" class="btn btn-outline-danger rounded-pill px-3" data-bs-toggle="collapse" data-bs-target="#cancelGrnCollapse">
                        <i class="ti ti-arrow-back-up me-1"></i> Cancel / Reverse GRN
                    </button>
                <?php endif; ?>
                <a href="grn.php" class="btn btn-light rounded-pill px-4">Close</a>
            </div>

            <!-- Cancel Collapse -->
            <div class="collapse px-4 pb-4" id="cancelGrnCollapse">
                <div class="p-3 bg-danger-subtle border border-danger-subtle rounded-3">
                    <h6 class="fw-bold text-danger"><i class="ti ti-alert-triangle me-1"></i>Warning: GRN Reversal</h6>
                    <p class="small text-danger mb-2">Reversing this GRN will deduct the accepted quantities from physical batches and record a compensating reversal entry in the immutable stock ledger.</p>
                    <form method="POST" action="grn.php">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <input type="hidden" name="action" value="cancel_grn">
                        <input type="hidden" name="grn_id" value="<?= $viewGrn['grn_id'] ?>">
                        <div class="input-group">
                            <input type="text" name="reversal_reason" class="form-control" placeholder="Mandatory reason for reversal..." required>
                            <button type="submit" class="btn btn-danger">Confirm Compensating Reversal</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Edit Full GRN & Batches Modal -->
<div class="modal fade" id="editGrnModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable" style="max-width: 1240px;">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <form method="POST" action="grn.php" id="editGrnForm">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="edit_full_grn">
                <input type="hidden" name="grn_id" id="editGrnId">

                <div class="modal-header border-0 px-4 pt-4 pb-2">
                    <div class="d-flex align-items-center gap-2">
                        <div class="p-2 rounded-3 text-white" style="background-color: #059669;">
                            <i class="ti ti-edit fs-5"></i>
                        </div>
                        <div>
                            <h5 class="fw-bold mb-0 text-dark">
                                Edit Goods Received Note (<span id="editGrnNumberDisplay" class="font-monospace text-success"></span>)
                            </h5>
                            <div class="text-muted small">Update delivery header, received medicine batches, expiry, rates, quantities, and sync batch ledger.</div>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body px-4 py-3">
                    <!-- Loading Spinner -->
                    <div id="editGrnLoading" class="text-center py-5">
                        <div class="spinner-border" role="status" style="color: #059669; width: 3rem; height: 3rem;">
                            <span class="visually-hidden">Loading GRN...</span>
                        </div>
                        <div class="mt-3 text-muted fw-semibold">Fetching receipt data and batch lines...</div>
                    </div>

                    <!-- Main Editable Content Container -->
                    <div id="editGrnContent" class="d-none">
                        <!-- Header Details Box (10% Opacity Blue) -->
                        <div class="p-3 mb-4 rounded-3" style="background: rgba(2, 132, 199, 0.08);">
                            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 pb-2 border-bottom border-light">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="text-muted small text-uppercase fw-semibold">Supplier:</span>
                                    <span class="fw-bold text-dark fs-6" id="editGrnSupplierName">—</span>
                                    <span class="badge bg-white text-secondary shadow-sm font-monospace" id="editGrnSupplierCode"></span>
                                </div>
                                <div class="small">
                                    <span class="text-muted">PO Reference:</span>
                                    <span class="font-monospace fw-bold text-dark ms-1" id="editGrnPoRef">Direct Inward</span>
                                </div>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-3 col-sm-6">
                                    <label class="form-label small fw-semibold text-dark mb-1">Supplier Bill / Invoice #</label>
                                    <input type="text" name="supplier_invoice_no" id="editGrnSupplierInv" class="form-control form-control-sm font-monospace bg-white border-0 shadow-sm" placeholder="e.g. INV-9821">
                                </div>
                                <div class="col-md-3 col-sm-6">
                                    <label class="form-label small fw-semibold text-dark mb-1">Supplier Invoice Date</label>
                                    <input type="date" name="supplier_invoice_date" id="editGrnSupplierInvDate" class="form-control form-control-sm bg-white border-0 shadow-sm">
                                </div>
                                <div class="col-md-3 col-sm-6">
                                    <label class="form-label small fw-semibold text-dark mb-1">GRN Receiving Date *</label>
                                    <input type="date" name="grn_date" id="editGrnDate" class="form-control form-control-sm bg-white border-0 shadow-sm" required>
                                </div>
                                <div class="col-md-3 col-sm-6">
                                    <label class="form-label small fw-semibold text-dark mb-1">Receiving Location / Store</label>
                                    <input type="text" name="receiving_location" id="editGrnLocation" class="form-control form-control-sm bg-white border-0 shadow-sm" value="Main Pharmacy Store">
                                </div>
                                <div class="col-12">
                                    <label class="form-label small fw-semibold text-dark mb-1">Inspection / Receipt Notes</label>
                                    <input type="text" name="notes" id="editGrnNotes" class="form-control form-control-sm bg-white border-0 shadow-sm" placeholder="Condition of boxes, storage temperature, cold-chain checks...">
                                </div>
                            </div>
                        </div>

                        <!-- Items Table Header -->
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="fw-bold mb-0 text-dark">
                                <i class="ti ti-packages text-success me-1"></i>Received Medicine Batches &amp; Financials
                            </h6>
                            <button type="button" class="btn btn-sm btn-outline-success rounded-pill px-3" onclick="addEditGrnRow()">
                                <i class="ti ti-plus me-1"></i> Add Batch Line
                            </button>
                        </div>

                        <!-- Table -->
                        <div class="table-responsive mb-3 border rounded-3 bg-white shadow-sm">
                            <table class="table table-sm align-middle mb-0" id="editGrnItemsTable">
                                <thead class="table-light small">
                                    <tr class="text-uppercase text-secondary" style="font-size: 0.72rem;">
                                        <th style="min-width: 180px;">Medicine *</th>
                                        <th style="min-width: 110px;">Batch No *</th>
                                        <th style="min-width: 120px;">Expiry *</th>
                                        <th style="width: 75px;" class="text-center">Recv *</th>
                                        <th style="width: 65px;" class="text-center">Free</th>
                                        <th style="width: 65px;" class="text-center">Rej</th>
                                        <th style="width: 65px;" class="text-center">Dam</th>
                                        <th style="width: 75px;" class="text-center">Accepted</th>
                                        <th style="min-width: 95px;">Cost Rate (₹) *</th>
                                        <th style="min-width: 85px;">MRP (₹)</th>
                                        <th style="min-width: 85px;">Sale (₹)</th>
                                        <th style="width: 70px;">GST %</th>
                                        <th style="min-width: 85px;">Shelf</th>
                                        <th style="min-width: 95px;" class="text-end pe-2">Line Total (₹)</th>
                                        <th style="width: 35px;" class="text-center"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Dynamic rows loaded via JS -->
                                </tbody>
                            </table>
                        </div>

                        <!-- Summary Cards Box (10% Opacity Blue) -->
                        <div class="p-3 rounded-3" style="background: rgba(2, 132, 199, 0.08);">
                            <div class="row g-3 text-center align-items-center">
                                <div class="col-md-2 col-6">
                                    <div class="text-muted small">Total Recv Qty</div>
                                    <div class="fw-bold fs-6 text-dark" id="editSummaryRecvQty">0</div>
                                </div>
                                <div class="col-md-2 col-6">
                                    <div class="text-muted small">Total Accepted Qty</div>
                                    <div class="fw-bold fs-6 text-success" id="editSummaryAcceptedQty">0</div>
                                </div>
                                <div class="col-md-2 col-6">
                                    <div class="text-muted small">Total Rej / Dam</div>
                                    <div class="fw-bold fs-6 text-danger" id="editSummaryRejDamQty">0</div>
                                </div>
                                <div class="col-md-2 col-6">
                                    <div class="text-muted small">Subtotal</div>
                                    <div class="fw-bold fs-6 text-dark" id="editSummarySubtotal">₹0.00</div>
                                </div>
                                <div class="col-md-2 col-6">
                                    <div class="text-muted small">GST Tax Total</div>
                                    <div class="fw-bold fs-6 text-dark" id="editSummaryTax">₹0.00</div>
                                </div>
                                <div class="col-md-2 col-12">
                                    <div class="text-muted small">Grand Total</div>
                                    <div class="fw-bold fs-5 text-emerald" style="color: #059669;" id="editSummaryGrandTotal">₹0.00</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer border-0 px-4 pb-4">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" id="saveEditGrnBtn" class="btn btn-emerald rounded-pill px-4 text-white" style="background-color: #059669;">
                        <i class="ti ti-check me-1"></i> Save Changes &amp; Sync Stock
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function buildEditMedOptions(selectedMedId) {
    let options = '<option value="">-- Choose Medicine --</option>';
    medicinesCatalog.forEach(m => {
        const isSel = (parseInt(m.medicine_id) === parseInt(selectedMedId)) ? 'selected' : '';
        options += `<option value="${m.medicine_id}" data-rate="${m.purchase_price}" data-mrp="${m.mrp}" data-shelf="${m.shelf || ''}" data-gst="${m.gst_percent}" ${isSel}>${m.medicine_name} (${m.dosage_form || ''})</option>`;
    });
    return options;
}

function openEditGrnModal(grnId) {
    const modalEl = document.getElementById('editGrnModal');
    const m = bootstrap.Modal.getOrCreateInstance(modalEl);
    m.show();

    const loadingEl = document.getElementById('editGrnLoading');
    const contentEl = document.getElementById('editGrnContent');
    loadingEl.classList.remove('d-none');
    contentEl.classList.add('d-none');

    fetch(`grn.php?ajax=get_grn&grn_id=${grnId}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success || !data.grn) {
                alert(data.message || 'Failed to fetch GRN details.');
                m.hide();
                return;
            }

            const grn = data.grn;
            document.getElementById('editGrnId').value = grn.grn_id;
            document.getElementById('editGrnNumberDisplay').textContent = grn.grn_number;
            document.getElementById('editGrnSupplierName').textContent = grn.supplier_name || '—';
            document.getElementById('editGrnSupplierCode').textContent = grn.supplier_code ? `[${grn.supplier_code}]` : '';
            document.getElementById('editGrnPoRef').textContent = grn.po_number || 'Direct Inward';
            document.getElementById('editGrnSupplierInv').value = grn.supplier_invoice_no || '';
            document.getElementById('editGrnSupplierInvDate').value = grn.supplier_invoice_date || '';
            document.getElementById('editGrnDate').value = grn.grn_date || '';
            document.getElementById('editGrnLocation').value = grn.receiving_location || 'Main Pharmacy Store';
            document.getElementById('editGrnNotes').value = grn.notes || '';

            const tbody = document.querySelector('#editGrnItemsTable tbody');
            tbody.innerHTML = '';

            if (grn.items && grn.items.length > 0) {
                grn.items.forEach(it => {
                    renderEditGrnRow(it);
                });
            } else {
                addEditGrnRow();
            }

            recalcEditGrnTotals();
            loadingEl.classList.add('d-none');
            contentEl.classList.remove('d-none');
        })
        .catch(err => {
            console.error(err);
            alert('Error fetching GRN information.');
            m.hide();
        });
}

function renderEditGrnRow(item = null) {
    const tbody = document.querySelector('#editGrnItemsTable tbody');
    const tr = document.createElement('tr');

    const grnItemId = item ? (item.grn_item_id || '') : '';
    const medId = item ? (item.medicine_id || '') : '';
    const batchNo = item ? (item.batch_number || '') : '';
    const expDate = item ? (item.expiry_date ? item.expiry_date.substring(0, 10) : '') : '';
    const mfgDate = item ? (item.mfg_date ? item.mfg_date.substring(0, 10) : '') : '';
    const recvQty = item ? parseInt(item.received_qty) || 1 : 10;
    const freeQty = item ? parseInt(item.free_qty) || 0 : 0;
    const rejQty = item ? parseInt(item.rejected_qty) || 0 : 0;
    const damQty = item ? parseInt(item.damaged_qty) || 0 : 0;
    const acceptedQty = Math.max(0, recvQty - rejQty - damQty);
    const costRate = item ? parseFloat(item.purchase_rate) || 0 : 0.00;
    const mrp = item ? parseFloat(item.mrp) || 0 : 0.00;
    const salePrice = item ? parseFloat(item.sale_price) || mrp : 0.00;
    const gstPct = item ? parseFloat(item.gst_percent) || 0 : 0.00;
    const shelf = item ? (item.shelf_location || '') : '';
    const remarks = item ? (item.remarks || '') : '';

    const medOptions = buildEditMedOptions(medId);

    tr.innerHTML = `
        <td>
            <input type="hidden" name="edit_grn_item_id[]" value="${grnItemId}">
            <input type="hidden" name="edit_mfg_date[]" value="${mfgDate}">
            <input type="hidden" name="edit_remarks[]" value="${remarks}">
            <select name="edit_medicine_id[]" class="form-select form-select-sm edit-med-select" required onchange="onEditMedChange(this)">
                ${medOptions}
            </select>
        </td>
        <td>
            <input type="text" name="edit_batch_number[]" class="form-control form-select-sm font-monospace text-uppercase edit-batch-input" value="${batchNo}" placeholder="BATCH123" required>
        </td>
        <td>
            <input type="date" name="edit_expiry_date[]" class="form-control form-select-sm edit-exp-input" value="${expDate}" required>
        </td>
        <td>
            <input type="number" name="edit_received_qty[]" class="form-control form-select-sm text-center edit-recv-input" min="1" value="${recvQty}" required oninput="calcEditAccepted(this)">
        </td>
        <td>
            <input type="number" name="edit_free_qty[]" class="form-control form-select-sm text-center edit-free-input" min="0" value="${freeQty}" oninput="calcEditAccepted(this)">
        </td>
        <td>
            <input type="number" name="edit_rejected_qty[]" class="form-control form-select-sm text-center edit-rej-input" min="0" value="${rejQty}" oninput="calcEditAccepted(this)">
        </td>
        <td>
            <input type="number" name="edit_damaged_qty[]" class="form-control form-select-sm text-center edit-dam-input" min="0" value="${damQty}" oninput="calcEditAccepted(this)">
        </td>
        <td class="text-center fw-bold text-success edit-accepted-display">${acceptedQty}</td>
        <td>
            <input type="number" step="0.01" min="0" name="edit_purchase_rate[]" class="form-control form-select-sm edit-rate-input" value="${costRate.toFixed(2)}" required oninput="calcEditAccepted(this)">
        </td>
        <td>
            <input type="number" step="0.01" min="0" name="edit_mrp[]" class="form-control form-select-sm edit-mrp-input" value="${mrp.toFixed(2)}" required>
        </td>
        <td>
            <input type="number" step="0.01" min="0" name="edit_sale_price[]" class="form-control form-select-sm edit-sale-input" value="${salePrice.toFixed(2)}" required>
        </td>
        <td>
            <input type="number" step="0.01" min="0" name="edit_gst_percent[]" class="form-control form-select-sm edit-gst-input" value="${gstPct.toFixed(2)}" oninput="calcEditAccepted(this)">
        </td>
        <td>
            <input type="text" name="edit_shelf_location[]" class="form-control form-select-sm edit-shelf-input" value="${shelf}" placeholder="Rack">
        </td>
        <td class="text-end pe-2 fw-bold text-dark font-monospace edit-line-total-display">₹0.00</td>
        <td class="text-center">
            <button type="button" class="btn btn-sm text-danger p-0 border-0" title="Delete Row" onclick="removeEditGrnRow(this)"><i class="ti ti-trash"></i></button>
        </td>
    `;
    tbody.appendChild(tr);
    calcEditAccepted(tr.querySelector('.edit-recv-input'));
}

function addEditGrnRow() {
    renderEditGrnRow();
    recalcEditGrnTotals();
}

function removeEditGrnRow(btn) {
    const tbody = document.querySelector('#editGrnItemsTable tbody');
    if (tbody.querySelectorAll('tr').length <= 1) {
        alert('A GRN must have at least one batch item.');
        return;
    }
    btn.closest('tr').remove();
    recalcEditGrnTotals();
}

function onEditMedChange(select) {
    const opt = select.options[select.selectedIndex];
    const tr = select.closest('tr');
    if (opt.value) {
        tr.querySelector('.edit-rate-input').value = parseFloat(opt.dataset.rate || 0).toFixed(2);
        tr.querySelector('.edit-mrp-input').value = parseFloat(opt.dataset.mrp || 0).toFixed(2);
        tr.querySelector('.edit-sale-input').value = parseFloat(opt.dataset.mrp || 0).toFixed(2);
        tr.querySelector('.edit-gst-input').value = parseFloat(opt.dataset.gst || 0).toFixed(2);
        tr.querySelector('.edit-shelf-input').value = opt.dataset.shelf || '';
    }
    calcEditAccepted(select);
}

function calcEditAccepted(elem) {
    const tr = elem.closest('tr');
    const recv = parseInt(tr.querySelector('.edit-recv-input').value) || 0;
    const rej = parseInt(tr.querySelector('.edit-rej-input').value) || 0;
    const dam = parseInt(tr.querySelector('.edit-dam-input').value) || 0;
    const accepted = Math.max(0, recv - rej - dam);
    tr.querySelector('.edit-accepted-display').textContent = accepted;

    const rate = parseFloat(tr.querySelector('.edit-rate-input').value) || 0;
    const gstPct = parseFloat(tr.querySelector('.edit-gst-input').value) || 0;
    const lineSub = recv * rate;
    const lineTax = lineSub * (gstPct / 100);
    const lineTot = lineSub + lineTax;

    tr.querySelector('.edit-line-total-display').textContent = '₹' + lineTot.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});

    recalcEditGrnTotals();
}

function recalcEditGrnTotals() {
    let totRecv = 0;
    let totAcc = 0;
    let totRejDam = 0;
    let subtotal = 0;
    let taxTotal = 0;
    let grandTotal = 0;

    const rows = document.querySelectorAll('#editGrnItemsTable tbody tr');
    rows.forEach(tr => {
        const recv = parseInt(tr.querySelector('.edit-recv-input').value) || 0;
        const rej = parseInt(tr.querySelector('.edit-rej-input').value) || 0;
        const dam = parseInt(tr.querySelector('.edit-dam-input').value) || 0;
        const acc = Math.max(0, recv - rej - dam);
        const rate = parseFloat(tr.querySelector('.edit-rate-input').value) || 0;
        const gstPct = parseFloat(tr.querySelector('.edit-gst-input').value) || 0;

        const lineSub = recv * rate;
        const lineTax = lineSub * (gstPct / 100);
        const lineTot = lineSub + lineTax;

        totRecv += recv;
        totAcc += acc;
        totRejDam += (rej + dam);
        subtotal += lineSub;
        taxTotal += lineTax;
        grandTotal += lineTot;
    });

    document.getElementById('editSummaryRecvQty').textContent = totRecv;
    document.getElementById('editSummaryAcceptedQty').textContent = totAcc;
    document.getElementById('editSummaryRejDamQty').textContent = totRejDam;
    document.getElementById('editSummarySubtotal').textContent = '₹' + subtotal.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('editSummaryTax').textContent = '₹' + taxTotal.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('editSummaryGrandTotal').textContent = '₹' + grandTotal.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}
</script>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
