<?php
// modules/purchases/payments.php - Supplier Payments, Bills & Accounts Payable Settlement

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/AuditService.php';
require_once __DIR__ . '/../../app/Services/DocumentSequenceService.php';
require_once __DIR__ . '/../../app/Services/SupplierService.php';
require_once __DIR__ . '/../../app/Services/SupplierPaymentService.php';

require_permission('pharmacy.supplier_payments.view');

use Pharmacy\Services\SupplierService;
use Pharmacy\Services\SupplierPaymentService;

$paymentService = new SupplierPaymentService($pdo);
$supplierService = new SupplierService($pdo);

$page_title = 'Supplier Payments & Bills';
$user = auth_user();
$userId = (int)($user['id'] ?? 1);

$canCreate = has_permission('pharmacy.supplier_payments.create');

$feedback = null;
$error = null;

// AJAX Endpoint: Fetch unpaid invoices for a supplier
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_unpaid_invoices') {
    header('Content-Type: application/json');
    $sId = (int)($_GET['supplier_id'] ?? 0);
    if ($sId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid supplier']);
        exit;
    }

    $uStmt = $pdo->prepare("
        SELECT invoice_id, invoice_number, supplier_invoice_no, invoice_date, due_date, grand_total, amount_paid, outstanding_amount 
        FROM pharmacy_purchase_invoices 
        WHERE supplier_id = ? AND outstanding_amount > 0 AND payment_status NOT IN ('PAID', 'CANCELLED')
        ORDER BY due_date ASC
    ");
    $uStmt->execute([$sId]);
    $invoices = $uStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'invoices' => $invoices]);
    exit;
}

// Handle Form Submissions (Create Payment)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Security token mismatch. Please try again.";
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'create_payment') {
                if (!$canCreate) throw new Exception("Permission denied: Cannot record supplier payments.");

                $header = [
                    'supplier_id'   => (int)($_POST['supplier_id'] ?? 0),
                    'payment_date'  => $_POST['payment_date'] ?? date('Y-m-d'),
                    'amount'        => (float)($_POST['amount'] ?? 0.00),
                    'payment_mode'  => $_POST['payment_mode'] ?? 'Bank Transfer',
                    'reference_no'  => trim($_POST['reference_no'] ?? ''),
                    'bank_name'     => trim($_POST['bank_name'] ?? ''),
                    'notes'         => trim($_POST['notes'] ?? '')
                ];

                $allocations = [];
                $invIds = $_POST['alloc_invoice_id'] ?? [];
                $allocAmts = $_POST['alloc_amount'] ?? [];

                foreach ($invIds as $k => $iId) {
                    $iId = (int)$iId;
                    $amt = (float)($allocAmts[$k] ?? 0.00);
                    if ($iId > 0 && $amt > 0) {
                        $allocations[] = [
                            'invoice_id'       => $iId,
                            'allocated_amount' => $amt
                        ];
                    }
                }

                $payId = $paymentService->recordPayment($header, $allocations, $userId);
                $supId = (int)$header['supplier_id'];
                header("Location: payments.php?supplier_id={$supId}&success_pay=1&pay_id={$payId}");
                exit;
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

if (!empty($_GET['success_pay'])) {
    $pId = (int)($_GET['pay_id'] ?? 0);
    $feedback = "Supplier payment voucher recorded and bills allocated successfully! (Voucher ID #{$pId})";
}

// Filters & State
$supplierId = !empty($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : null;
$search = trim($_GET['search'] ?? '');

$activeSuppliers = $pdo->query("
    SELECT supplier_id, supplier_name, supplier_code 
    FROM pharmacy_suppliers 
    WHERE status != 'Inactive' 
    ORDER BY supplier_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$selectedSupplier = null;
$supplierBills = [];
$supplierPayments = [];
$supplierDirectory = [];
$allBillsBySupplier = [];
$allPaymentsBySupplier = [];
$allPayments = [];

if ($supplierId) {
    // 1. Fetch selected supplier details and aggregate stats
    $sStmt = $pdo->prepare("
        SELECT s.*,
               COALESCE((SELECT SUM(grand_total) FROM pharmacy_purchase_invoices WHERE supplier_id = s.supplier_id), 0) as total_invoiced,
               COALESCE((SELECT SUM(amount_paid) FROM pharmacy_purchase_invoices WHERE supplier_id = s.supplier_id), 0) as total_invoices_paid,
               COALESCE((SELECT SUM(outstanding_amount) FROM pharmacy_purchase_invoices WHERE supplier_id = s.supplier_id AND payment_status != 'PAID'), 0) as total_outstanding,
               COALESCE((SELECT SUM(amount) FROM pharmacy_supplier_payments WHERE supplier_id = s.supplier_id), 0) as total_disbursed,
               (SELECT COUNT(*) FROM pharmacy_purchase_invoices WHERE supplier_id = s.supplier_id) as total_bills_count,
               (SELECT COUNT(*) FROM pharmacy_purchase_invoices WHERE supplier_id = s.supplier_id AND outstanding_amount > 0 AND payment_status != 'PAID') as unpaid_bills_count,
               (SELECT COUNT(*) FROM pharmacy_supplier_payments WHERE supplier_id = s.supplier_id) as payments_count
        FROM pharmacy_suppliers s
        WHERE s.supplier_id = ?
    ");
    $sStmt->execute([$supplierId]);
    $selectedSupplier = $sStmt->fetch(PDO::FETCH_ASSOC);

    if ($selectedSupplier) {
        // 2. Fetch all bills of this supplier
        $bSql = "
            SELECT i.*,
                   (SELECT COUNT(*) FROM pharmacy_purchase_invoice_items WHERE invoice_id = i.invoice_id) as line_items_count
            FROM pharmacy_purchase_invoices i
            WHERE i.supplier_id = ?
        ";
        $bParams = [$supplierId];
        if ($search !== '') {
            $bSql .= " AND (i.invoice_number LIKE ? OR i.supplier_invoice_no LIKE ?)";
            $bParams[] = "%{$search}%";
            $bParams[] = "%{$search}%";
        }
        $bSql .= " ORDER BY i.invoice_date DESC, i.invoice_id DESC";
        $bStmt = $pdo->prepare($bSql);
        $bStmt->execute($bParams);
        $supplierBills = $bStmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. Fetch all payments/disbursements of this supplier
        $pSql = "
            SELECT p.*, s.supplier_name, s.supplier_code, u.full_name as creator_name,
                   (SELECT COUNT(*) FROM pharmacy_supplier_payment_allocations WHERE payment_id = p.payment_id) as allocated_count
            FROM pharmacy_supplier_payments p
            JOIN pharmacy_suppliers s ON p.supplier_id = s.supplier_id
            LEFT JOIN pharmacy_users u ON p.created_by = u.id
            WHERE p.supplier_id = ?
        ";
        $pParams = [$supplierId];
        if ($search !== '') {
            $pSql .= " AND (p.payment_number LIKE ? OR p.reference_no LIKE ?)";
            $pParams[] = "%{$search}%";
            $pParams[] = "%{$search}%";
        }
        $pSql .= " ORDER BY p.payment_date DESC, p.payment_id DESC";
        $pStmt = $pdo->prepare($pSql);
        $pStmt->execute($pParams);
        $supplierPayments = $pStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} else {
    // No specific supplier selected -> Show Supplier Directory with interactive accordion drop-downs
    $dirSql = "
        SELECT s.supplier_id, s.supplier_name, s.supplier_code, s.contact_person, s.phone, s.email, s.status,
               COALESCE((SELECT SUM(grand_total) FROM pharmacy_purchase_invoices WHERE supplier_id = s.supplier_id), 0) as total_invoiced,
               COALESCE((SELECT SUM(amount_paid) FROM pharmacy_purchase_invoices WHERE supplier_id = s.supplier_id), 0) as total_invoices_paid,
               COALESCE((SELECT SUM(outstanding_amount) FROM pharmacy_purchase_invoices WHERE supplier_id = s.supplier_id AND payment_status != 'PAID'), 0) as total_outstanding,
               COALESCE((SELECT SUM(amount) FROM pharmacy_supplier_payments WHERE supplier_id = s.supplier_id), 0) as total_disbursed,
               (SELECT COUNT(*) FROM pharmacy_purchase_invoices WHERE supplier_id = s.supplier_id) as total_bills_count,
               (SELECT COUNT(*) FROM pharmacy_purchase_invoices WHERE supplier_id = s.supplier_id AND outstanding_amount > 0 AND payment_status != 'PAID') as unpaid_bills_count,
               (SELECT COUNT(*) FROM pharmacy_supplier_payments WHERE supplier_id = s.supplier_id) as payments_count
        FROM pharmacy_suppliers s
        WHERE s.status != 'Inactive'
    ";
    $dirParams = [];
    if ($search !== '') {
        $dirSql .= " AND (s.supplier_name LIKE ? OR s.supplier_code LIKE ? OR s.contact_person LIKE ? OR s.phone LIKE ?)";
        $sTerm = "%{$search}%";
        $dirParams = [$sTerm, $sTerm, $sTerm, $sTerm];
    }
    $dirSql .= " ORDER BY total_outstanding DESC, s.supplier_name ASC";
    $dirStmt = $pdo->prepare($dirSql);
    $dirStmt->execute($dirParams);
    $supplierDirectory = $dirStmt->fetchAll(PDO::FETCH_ASSOC);

    // Pre-fetch all purchase bills mapped by supplier_id for instant accordion drop-down
    $allBillsStmt = $pdo->query("
        SELECT i.*, 
               (SELECT COUNT(*) FROM pharmacy_purchase_invoice_items WHERE invoice_id = i.invoice_id) as line_items_count
        FROM pharmacy_purchase_invoices i
        ORDER BY i.invoice_date DESC, i.invoice_id DESC
    ");
    while ($bRow = $allBillsStmt->fetch(PDO::FETCH_ASSOC)) {
        $allBillsBySupplier[$bRow['supplier_id']][] = $bRow;
    }

    // Pre-fetch all payments mapped by supplier_id
    $allPayStmt = $pdo->query("
        SELECT p.*,
               (SELECT COUNT(*) FROM pharmacy_supplier_payment_allocations WHERE payment_id = p.payment_id) as allocated_count
        FROM pharmacy_supplier_payments p
        ORDER BY p.payment_date DESC, p.payment_id DESC
    ");
    while ($pRow = $allPayStmt->fetch(PDO::FETCH_ASSOC)) {
        $allPaymentsBySupplier[$pRow['supplier_id']][] = $pRow;
    }

    // Global recent payments
    $allPaySql = "
        SELECT p.*, s.supplier_name, s.supplier_code, u.full_name as creator_name,
               (SELECT COUNT(*) FROM pharmacy_supplier_payment_allocations WHERE payment_id = p.payment_id) as allocated_count
        FROM pharmacy_supplier_payments p
        JOIN pharmacy_suppliers s ON p.supplier_id = s.supplier_id
        LEFT JOIN pharmacy_users u ON p.created_by = u.id
        WHERE 1=1
    ";
    $allParams = [];
    if ($search !== '') {
        $term = '%' . $search . '%';
        $allPaySql .= " AND (p.payment_number LIKE ? OR s.supplier_name LIKE ? OR p.reference_no LIKE ?)";
        $allParams = [$term, $term, $term];
    }
    $allPaySql .= " ORDER BY p.payment_id DESC LIMIT 50";
    $allStmt = $pdo->prepare($allPaySql);
    $allStmt->execute($allParams);
    $allPayments = $allStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Preset for opening payment modal
$presetSupplierId = (int)($_GET['modal_supplier_id'] ?? ($supplierId ?? 0));
$presetInvoiceId = (int)($_GET['invoice_id'] ?? 0);
$openModalAuto = isset($_GET['open_payment_modal']) || ($presetInvoiceId > 0);

$unpaidInvoices = [];
if ($presetSupplierId > 0) {
    $uStmt = $pdo->prepare("
        SELECT invoice_id, invoice_number, supplier_invoice_no, invoice_date, due_date, grand_total, amount_paid, outstanding_amount 
        FROM pharmacy_purchase_invoices 
        WHERE supplier_id = ? AND outstanding_amount > 0 AND payment_status NOT IN ('PAID', 'CANCELLED')
        ORDER BY due_date ASC
    ");
    $uStmt->execute([$presetSupplierId]);
    $unpaidInvoices = $uStmt->fetchAll(PDO::FETCH_ASSOC);
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<style>
.supplier-accordion-row {
    cursor: pointer;
    transition: background-color 0.15s ease-in-out;
}
.supplier-accordion-row:hover {
    background-color: #f8fafc !important;
}
.chevron-rotate {
    transition: transform 0.2s ease-in-out;
}
.supplier-accordion-row[aria-expanded="true"] .chevron-rotate {
    transform: rotate(180deg);
}
.bills-dropdown-container {
    background: #f8fafc;
    border-top: 1px dashed #cbd5e1;
    border-bottom: 2px solid #e2e8f0;
}
.bill-row-selected {
    background-color: #ecfdf5 !important;
}
.bill-select-box, .select-all-bills-cb, .modal-inv-check {
    cursor: pointer;
    width: 1.15rem;
    height: 1.15rem;
}
.selection-upper-bar {
    background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 100%);
    border: 1px solid #a7f3d0 !important;
    animation: fadeIn 0.2s ease-in-out;
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-4px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-end align-items-center mb-3 gap-2 flex-wrap">
        <a href="outstanding.php" class="btn btn-outline-danger rounded-pill px-3 py-2 small">
            <i class="ti ti-building-bank me-1"></i> Supplier Outstanding
        </a>
        <a href="ledger.php<?= $supplierId ? '?supplier_id=' . $supplierId : '' ?>" class="btn btn-outline-secondary rounded-pill px-3 py-2 small">
            <i class="ti ti-book-2 me-1"></i> Supplier Ledger
        </a>
        <?php if ($canCreate): ?>
            <button type="button" class="btn btn-emerald rounded-pill px-3 py-2 text-white small shadow-sm" style="background-color: #059669;" onclick="openPaymentModal(<?= $supplierId ?: 'null' ?>)">
                <i class="ti ti-plus me-1"></i> Record Payment
            </button>
        <?php endif; ?>
    </div>

    <?php if ($feedback): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3 border-0 shadow-sm mb-4">
            <i class="ti ti-circle-check me-2 fs-5"></i><?= htmlspecialchars($feedback) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-3 border-0 shadow-sm mb-4">
            <i class="ti ti-alert-circle me-2 fs-5"></i><?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Supplier Search & Filter Card -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white">
        <div class="card-body p-3">
            <form method="GET" action="payments.php" class="row g-2 align-items-center">
                <div class="col-md-5">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-0"><i class="ti ti-search text-muted"></i></span>
                        <input type="text" name="search" class="form-control bg-light border-0" placeholder="Search bill #, payment #, ref UTR, or supplier..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <select name="supplier_id" class="form-select bg-light border-0" onchange="this.form.submit()">
                        <option value="">-- All Suppliers (Click to Dropdown Bills) --</option>
                        <?php foreach ($activeSuppliers as $sup): ?>
                            <option value="<?= $sup['supplier_id'] ?>" <?= $supplierId === (int)$sup['supplier_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sup['supplier_name'] ?? '') ?><?= !empty($sup['supplier_code']) ? ' (' . htmlspecialchars($sup['supplier_code']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-dark w-100 rounded-3">
                        <i class="ti ti-filter me-1"></i> Filter
                    </button>
                    <?php if ($supplierId || $search !== ''): ?>
                        <a href="payments.php" class="btn btn-outline-secondary rounded-3" title="Clear Filters">Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <?php if ($selectedSupplier): ?>
        <!-- ============================================================= -->
        <!-- VIEW: SPECIFIC SUPPLIER BILLS & PAYMENTS                      -->
        <!-- ============================================================= -->
        
        <!-- Supplier Banner & Stats -->
        <div class="card border-0 shadow-sm rounded-4 bg-white mb-4 overflow-hidden">
            <div class="card-body p-4">
                <div class="row align-items-center g-3">
                    <div class="col-lg-6">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle bg-emerald-subtle text-success p-3 d-flex align-items-center justify-content-center" style="width: 56px; height: 56px; background-color: #d1fae5;">
                                <i class="ti ti-truck-delivery fs-2 text-success"></i>
                            </div>
                            <div>
                                <div class="d-flex align-items-center gap-2">
                                    <h4 class="fw-bold text-dark mb-0"><?= htmlspecialchars($selectedSupplier['supplier_name'] ?? '') ?></h4>
                                    <?php if (!empty($selectedSupplier['supplier_code'])): ?>
                                        <span class="badge bg-secondary font-monospace"><?= htmlspecialchars($selectedSupplier['supplier_code']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-muted small mt-1">
                                    <i class="ti ti-user me-1"></i><?= htmlspecialchars($selectedSupplier['contact_person'] ?: 'N/A') ?>
                                    <span class="mx-2">•</span>
                                    <i class="ti ti-phone me-1"></i><?= htmlspecialchars($selectedSupplier['phone'] ?: 'N/A') ?>
                                    <?php if (!empty($selectedSupplier['gstin'])): ?>
                                        <span class="mx-2">•</span>
                                        <i class="ti ti-receipt-tax me-1"></i>GSTIN: <?= htmlspecialchars($selectedSupplier['gstin']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="row g-2 text-center text-md-end">
                            <div class="col-4">
                                <div class="p-2 rounded-3 bg-light">
                                    <div class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem;">Total Invoiced</div>
                                    <div class="fw-bold text-dark fs-6">₹<?= number_format((float)$selectedSupplier['total_invoiced'], 2) ?></div>
                                    <div class="text-muted" style="font-size: 0.75rem;"><?= (int)$selectedSupplier['total_bills_count'] ?> Bills</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="p-2 rounded-3 bg-light">
                                    <div class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem;">Total Paid</div>
                                    <div class="fw-bold text-success fs-6">₹<?= number_format((float)$selectedSupplier['total_invoices_paid'], 2) ?></div>
                                    <div class="text-muted" style="font-size: 0.75rem;"><?= (int)$selectedSupplier['payments_count'] ?> Payments</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="p-2 rounded-3 <?= (float)$selectedSupplier['total_outstanding'] > 0 ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success' ?>">
                                    <div class="small fw-semibold text-uppercase" style="font-size: 0.72rem;">Outstanding Due</div>
                                    <div class="fw-bold fs-6">₹<?= number_format((float)$selectedSupplier['total_outstanding'], 2) ?></div>
                                    <div style="font-size: 0.75rem;"><?= (int)$selectedSupplier['unpaid_bills_count'] ?> Unpaid</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Tab Navigation for this Supplier -->
            <div class="card-footer bg-white border-top px-4 py-2">
                <ul class="nav nav-pills gap-2" id="supplierTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active rounded-pill px-4 py-2 fw-semibold" id="bills-tab" data-bs-toggle="pill" data-bs-target="#billsTabPane" type="button" role="tab">
                            <i class="ti ti-file-invoice me-1"></i> Purchase Bills & Invoices (<?= count($supplierBills) ?>)
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link rounded-pill px-4 py-2 fw-semibold" id="payments-tab" data-bs-toggle="pill" data-bs-target="#paymentsTabPane" type="button" role="tab">
                            <i class="ti ti-cash me-1"></i> Payment Vouchers (<?= count($supplierPayments) ?>)
                        </button>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Tab Content -->
        <div class="tab-content" id="supplierTabsContent">
            <!-- TAB 1: SUPPLIER BILLS / INVOICES -->
            <div class="tab-pane fade show active" id="billsTabPane" role="tabpanel">
                <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden">
                    <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <h6 class="fw-bold text-dark mb-0">
                                <i class="ti ti-receipt text-primary me-2"></i>Bills & Purchase Invoices from <?= htmlspecialchars($selectedSupplier['supplier_name']) ?>
                            </h6>
                            <span class="text-muted small">Select bills to pay specific tenders/amounts or click Pay Outstanding for full settlement.</span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <?php if ($canCreate): ?>
                                <button type="button" class="btn btn-sm btn-emerald text-white rounded-pill px-3 shadow-sm pay-selected-btn d-none" id="paySelectedBtn_<?= $selectedSupplier['supplier_id'] ?>" style="background-color: #059669;" onclick="paySelectedBills(<?= $selectedSupplier['supplier_id'] ?>)">
                                    <i class="ti ti-cash me-1"></i> Pay Selected (<span class="sel-count">0</span> bills · ₹<span class="sel-total">0.00</span>)
                                </button>
                            <?php endif; ?>
                            <?php if ((float)$selectedSupplier['total_outstanding'] > 0 && $canCreate): ?>
                                <button type="button" class="btn btn-sm btn-success rounded-pill px-3" onclick="openPaymentModal(<?= $selectedSupplier['supplier_id'] ?>)">
                                    <i class="ti ti-cash me-1"></i> Pay Outstanding (₹<?= number_format((float)$selectedSupplier['total_outstanding'], 2) ?>)
                                </button>
                            <?php endif; ?>
                    </div>
                    
                    <!-- Dedicated Upper Selection Bar (Visible when bills are checked) -->
                    <div class="selection-upper-bar py-2 px-3 mx-3 my-2 rounded-3 d-none align-items-center justify-content-between flex-wrap gap-2" id="upperSelectionBar_<?= $selectedSupplier['supplier_id'] ?>">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-success rounded-pill px-3 py-1 fs-6">
                                <i class="ti ti-check me-1"></i><span class="sel-count">0</span> Bills Selected
                            </span>
                            <span class="text-dark fw-semibold">
                                Total Payment: <strong class="text-success fs-6">₹<span class="sel-total">0.00</span></strong>
                            </span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-sm btn-success rounded-pill px-3 shadow-sm fw-bold" onclick="paySelectedBills(<?= $selectedSupplier['supplier_id'] ?>)">
                                <i class="ti ti-cash me-1"></i> Proceed to Pay Selected (₹<span class="sel-total-btn">0.00</span>)
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-2" onclick="clearSupplierSelection(<?= $selectedSupplier['supplier_id'] ?>)" title="Clear Selection">
                                <i class="ti ti-x me-1"></i> Clear
                            </button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr class="text-uppercase small text-muted">
                                    <th style="width: 40px;" class="ps-3 text-center">
                                        <input type="checkbox" class="form-check-input select-all-bills-cb" data-supplier-id="<?= $selectedSupplier['supplier_id'] ?>" title="Select / Deselect all unpaid bills" onchange="toggleSelectAllBills(<?= $selectedSupplier['supplier_id'] ?>, this.checked)">
                                    </th>
                                    <th>Internal Inv #</th>
                                    <th>Vendor Bill #</th>
                                    <th>Bill Date</th>
                                    <th>Due Date</th>
                                    <th class="text-end">Grand Total</th>
                                    <th class="text-end">Paid Amount</th>
                                    <th class="text-end">Balance Due</th>
                                    <th class="text-center">Payment Status</th>
                                    <th class="text-end pe-4">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($supplierBills)): ?>
                                    <tr>
                                        <td colspan="10" class="text-center py-5 text-muted">
                                            <i class="ti ti-file-off fs-1 d-block mb-2 text-secondary"></i>
                                            No purchase bills found for this supplier.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($supplierBills as $b): 
                                        $isOverdue = ((float)$b['outstanding_amount'] > 0) && (strtotime($b['due_date']) < strtotime(date('Y-m-d')));
                                        $statusClass = 'bg-secondary';
                                        if ($b['payment_status'] === 'PAID') $statusClass = 'bg-success-subtle text-success';
                                        elseif ($b['payment_status'] === 'PARTIAL') $statusClass = 'bg-warning-subtle text-warning';
                                        elseif ($isOverdue) $statusClass = 'bg-danger-subtle text-danger';
                                        else $statusClass = 'bg-danger-subtle text-danger';
                                        $isUnpaid = ((float)$b['outstanding_amount'] > 0);
                                    ?>
                                        <tr class="bill-row bill-row-<?= $selectedSupplier['supplier_id'] ?> <?= $isUnpaid ? 'bill-row-unpaid' : 'bill-row-paid' ?>" id="bill_row_<?= $b['invoice_id'] ?>">
                                            <td class="ps-3 text-center">
                                                <?php if ($isUnpaid): ?>
                                                    <input type="checkbox" class="form-check-input bill-select-box bill-cb-<?= $selectedSupplier['supplier_id'] ?>" 
                                                           data-supplier-id="<?= $selectedSupplier['supplier_id'] ?>" 
                                                           data-invoice-id="<?= $b['invoice_id'] ?>" 
                                                           data-invoice-num="<?= htmlspecialchars($b['invoice_number']) ?>" 
                                                           data-due="<?= (float)$b['outstanding_amount'] ?>" 
                                                           onchange="onBillCheckboxChange(<?= $selectedSupplier['supplier_id'] ?>)">
                                                <?php else: ?>
                                                    <i class="ti ti-check text-success fs-6" title="Fully Paid"></i>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="invoices.php?view_id=<?= $b['invoice_id'] ?>" class="fw-bold font-monospace text-primary text-decoration-none">
                                                    <?= htmlspecialchars($b['invoice_number']) ?>
                                                </a>
                                                <div class="text-muted small"><?= (int)$b['line_items_count'] ?> items</div>
                                            </td>
                                            <td>
                                                <span class="font-monospace fw-semibold text-dark"><?= htmlspecialchars($b['supplier_invoice_no'] ?: '—') ?></span>
                                            </td>
                                            <td class="small text-muted">
                                                <?= date('d-M-Y', strtotime($b['invoice_date'])) ?>
                                            </td>
                                            <td class="small">
                                                <span class="<?= $isOverdue ? 'text-danger fw-bold' : 'text-dark' ?>">
                                                    <?= date('d-M-Y', strtotime($b['due_date'])) ?>
                                                </span>
                                                <?php if ($isOverdue): ?>
                                                    <span class="badge bg-danger rounded-pill ms-1" style="font-size: 0.65rem;">OVERDUE</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end fw-semibold text-dark">
                                                ₹<?= number_format((float)$b['grand_total'], 2) ?>
                                            </td>
                                            <td class="text-end text-success fw-semibold">
                                                ₹<?= number_format((float)$b['amount_paid'], 2) ?>
                                            </td>
                                            <td class="text-end fw-bold <?= (float)$b['outstanding_amount'] > 0 ? 'text-danger fs-6' : 'text-muted' ?>">
                                                ₹<?= number_format((float)$b['outstanding_amount'], 2) ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge <?= $statusClass ?> rounded-pill px-2 py-1">
                                                    <?= htmlspecialchars($b['payment_status']) ?>
                                                </span>
                                            </td>
                                            <td class="text-end pe-4">
                                                <div class="d-inline-flex gap-1">
                                                    <?php if ((float)$b['outstanding_amount'] > 0 && $canCreate): ?>
                                                        <button type="button" class="btn btn-sm btn-success rounded-pill px-3 shadow-sm d-inline-flex align-items-center gap-1" onclick="paySpecificInvoice(<?= $selectedSupplier['supplier_id'] ?>, <?= $b['invoice_id'] ?>, <?= (float)$b['outstanding_amount'] ?>)">
                                                            <i class="ti ti-cash"></i> Pay Bill
                                                        </button>
                                                    <?php endif; ?>
                                                    <a href="invoices.php?view_id=<?= $b['invoice_id'] ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-2" title="View Bill Details">
                                                        <i class="ti ti-eye"></i>
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

            <!-- TAB 2: SUPPLIER PAYMENT VOUCHERS -->
            <div class="tab-pane fade" id="paymentsTabPane" role="tabpanel">
                <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden">
                    <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="fw-bold text-dark mb-0">
                                <i class="ti ti-cash text-success me-2"></i>Disbursement Payments to <?= htmlspecialchars($selectedSupplier['supplier_name']) ?>
                            </h6>
                            <span class="text-muted small">Record of all payments and UTR settlement vouchers.</span>
                        </div>
                        <?php if ($canCreate): ?>
                            <button type="button" class="btn btn-sm btn-emerald text-white rounded-pill px-3" style="background-color: #059669;" onclick="openPaymentModal(<?= $selectedSupplier['supplier_id'] ?>)">
                                <i class="ti ti-plus me-1"></i> Record New Payment
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr class="text-uppercase small text-muted">
                                    <th class="ps-4">Voucher # & Date</th>
                                    <th>Payment Mode</th>
                                    <th>Reference / UTR</th>
                                    <th>Bank / Drawee</th>
                                    <th class="text-end">Amount Paid</th>
                                    <th>Invoices Settled</th>
                                    <th>Recorded By</th>
                                    <th class="text-center pe-4">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($supplierPayments)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-5 text-muted">
                                            <i class="ti ti-cash-off fs-1 d-block mb-2 text-secondary"></i>
                                            No payment vouchers recorded yet for this supplier.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($supplierPayments as $p): ?>
                                        <tr>
                                            <td class="ps-4">
                                                <div class="fw-bold font-monospace text-dark"><?= htmlspecialchars($p['payment_number']) ?></div>
                                                <div class="text-muted small"><?= date('d-M-Y', strtotime($p['payment_date'])) ?></div>
                                            </td>
                                            <td>
                                                <span class="badge bg-light border text-dark"><?= htmlspecialchars($p['payment_mode']) ?></span>
                                            </td>
                                            <td>
                                                <span class="font-monospace text-dark small"><?= htmlspecialchars($p['reference_no'] ?: '—') ?></span>
                                            </td>
                                            <td class="small text-muted">
                                                <?= htmlspecialchars($p['bank_name'] ?: '—') ?>
                                            </td>
                                            <td class="text-end fw-bold text-success fs-6">
                                                ₹<?= number_format((float)$p['amount'], 2) ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-primary-subtle text-primary rounded-pill px-2"><?= (int)$p['allocated_count'] ?> Invoices</span>
                                            </td>
                                            <td class="small text-muted">
                                                <?= htmlspecialchars($p['creator_name'] ?: 'Admin') ?>
                                            </td>
                                            <td class="text-center pe-4">
                                                <span class="badge bg-success-subtle text-success rounded-pill px-2 py-1"><?= htmlspecialchars($p['status']) ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- ============================================================= -->
        <!-- VIEW: ALL SUPPLIERS DIRECTORY WITH INTERACTIVE DROPDOWN BILLS  -->
        <!-- ============================================================= -->
        
        <div class="card border-0 shadow-sm rounded-4 bg-white mb-4 overflow-hidden">
            <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="fw-bold text-dark mb-0">
                        <i class="ti ti-building-store text-emerald me-2"></i>Suppliers Accounts & Invoices Directory
                    </h5>
                    <span class="text-muted small">Click any supplier row to drop down and inspect their individual purchase bills and payments instantly.</span>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr class="text-uppercase small text-muted">
                            <th style="width: 40px;" class="ps-3 text-center"></th>
                            <th>Supplier Name & Code</th>
                            <th>Contact Person</th>
                            <th class="text-center">Total Bills</th>
                            <th class="text-end">Total Invoiced</th>
                            <th class="text-end">Total Paid</th>
                            <th class="text-end">Outstanding Due</th>
                            <th class="text-center">Status</th>
                            <th class="text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($supplierDirectory)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-5 text-muted">
                                    <i class="ti ti-users-off fs-1 d-block mb-2 text-secondary"></i>
                                    No matching active suppliers found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($supplierDirectory as $s): 
                                $supId = (int)$s['supplier_id'];
                                $supBills = $allBillsBySupplier[$supId] ?? [];
                                $supPayments = $allPaymentsBySupplier[$supId] ?? [];
                            ?>
                                <!-- MASTER SUPPLIER ROW (CLICK TO EXPAND / COLLAPSE BILLS) -->
                                <tr class="supplier-accordion-row border-top" data-bs-toggle="collapse" data-bs-target="#collapseBills_<?= $supId ?>" aria-expanded="false" onclick="currentActiveSupplierId = <?= $supId ?>;">
                                    <td class="ps-3 text-center">
                                        <i class="ti ti-chevron-down text-muted fs-5 chevron-rotate"></i>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark fs-6">
                                            <?= htmlspecialchars($s['supplier_name'] ?? '') ?>
                                        </div>
                                        <?php if (!empty($s['supplier_code'])): ?>
                                            <span class="badge bg-light text-secondary font-monospace" style="font-size: 0.7rem;"><?= htmlspecialchars($s['supplier_code']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="text-dark small fw-semibold"><?= htmlspecialchars($s['contact_person'] ?: 'N/A') ?></div>
                                        <div class="text-muted small"><?= htmlspecialchars($s['phone'] ?: 'N/A') ?></div>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-light border text-dark fw-bold"><?= (int)$s['total_bills_count'] ?> Bills</span>
                                        <?php if ((int)$s['unpaid_bills_count'] > 0): ?>
                                            <div class="text-danger small fw-semibold mt-1"><?= (int)$s['unpaid_bills_count'] ?> Unpaid</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end fw-semibold text-dark">
                                        ₹<?= number_format((float)$s['total_invoiced'], 2) ?>
                                    </td>
                                    <td class="text-end text-success fw-semibold">
                                        ₹<?= number_format((float)$s['total_invoices_paid'], 2) ?>
                                    </td>
                                    <td class="text-end fw-bold <?= (float)$s['total_outstanding'] > 0 ? 'text-danger fs-6' : 'text-success' ?>">
                                        ₹<?= number_format((float)$s['total_outstanding'], 2) ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-success-subtle text-success rounded-pill px-2 py-1"><?= htmlspecialchars($s['status']) ?></span>
                                    </td>
                                    <td class="text-end pe-4" onclick="event.stopPropagation()">
                                        <div class="d-inline-flex gap-2">
                                            <button type="button" class="btn btn-sm btn-emerald text-white rounded-pill px-3 shadow-sm d-inline-flex align-items-center gap-1" style="background-color: #059669;" data-bs-toggle="collapse" data-bs-target="#collapseBills_<?= $supId ?>" onclick="currentActiveSupplierId = <?= $supId ?>;">
                                                <i class="ti ti-list-details"></i> View Bills (<?= (int)$s['total_bills_count'] ?>)
                                            </button>
                                            <?php if ((float)$s['total_outstanding'] > 0 && $canCreate): ?>
                                                <button type="button" class="btn btn-sm btn-success rounded-pill px-3" onclick="openPaymentModal(<?= $supId ?>)">
                                                    <i class="ti ti-cash me-1"></i> Pay
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>

                                <!-- COLLAPSIBLE DROPDOWN CONTAINER FOR THIS SUPPLIER'S BILLS -->
                                <tr class="p-0 border-0">
                                    <td colspan="9" class="p-0 border-0">
                                        <div class="collapse bills-dropdown-container px-4 py-3" id="collapseBills_<?= $supId ?>">
                                            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 pb-2 border-bottom gap-2">
                                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                                    <span class="badge bg-primary px-3 py-2 fs-6 rounded-pill">
                                                        <i class="ti ti-file-invoice me-1"></i> <?= htmlspecialchars($s['supplier_name']) ?>'s Invoices & Bills
                                                    </span>
                                                    <span class="text-muted small">
                                                        Total: ₹<?= number_format((float)$s['total_invoiced'], 2) ?> | Paid: ₹<?= number_format((float)$s['total_invoices_paid'], 2) ?> | Due: <strong class="text-danger">₹<?= number_format((float)$s['total_outstanding'], 2) ?></strong>
                                                    </span>
                                                </div>
                                                <div class="d-flex flex-wrap align-items-center gap-2">
                                                    <?php if ($canCreate): ?>
                                                        <button type="button" class="btn btn-sm btn-emerald text-white rounded-pill px-3 shadow-sm pay-selected-btn d-none" id="paySelectedBtn_<?= $supId ?>" style="background-color: #059669;" onclick="paySelectedBills(<?= $supId ?>)">
                                                            <i class="ti ti-cash me-1"></i> Pay Selected (<span class="sel-count">0</span> bills · ₹<span class="sel-total">0.00</span>)
                                                        </button>
                                                    <?php endif; ?>
                                                    <a href="ledger.php?supplier_id=<?= $supId ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3">
                                                        <i class="ti ti-book-2 me-1"></i> View Full Ledger
                                                    </a>
                                                    <?php if ((float)$s['total_outstanding'] > 0 && $canCreate): ?>
                                                        <button type="button" class="btn btn-sm btn-success rounded-pill px-3" onclick="openPaymentModal(<?= $supId ?>)">
                                                            <i class="ti ti-cash me-1"></i> Pay Full Outstanding
                                                        </button>
                                                    <?php endif; ?>
                                                    <a href="payments.php?supplier_id=<?= $supId ?>" class="btn btn-sm btn-primary rounded-pill px-3">
                                                        <i class="ti ti-external-link me-1"></i> Open Dedicated Page
                                                    </a>
                                                </div>
                                            </div>

                                            <!-- Dropdown Table: Bills -->
                                            <div class="card border rounded-3 bg-white shadow-sm overflow-hidden mb-2">
                                                <!-- Upper Selection Action Strip for this Supplier -->
                                                <div class="selection-upper-bar py-2 px-3 d-none align-items-center justify-content-between flex-wrap gap-2 border-bottom" id="upperSelectionBar_<?= $supId ?>">
                                                    <div class="d-flex align-items-center gap-2">
                                                        <span class="badge bg-success rounded-pill px-3 py-1 fs-6">
                                                            <i class="ti ti-check me-1"></i><span class="sel-count">0</span> Bills Selected
                                                        </span>
                                                        <span class="text-dark fw-semibold small">
                                                            Total Payment: <strong class="text-success fs-6">₹<span class="sel-total">0.00</span></strong>
                                                        </span>
                                                    </div>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <button type="button" class="btn btn-sm btn-success rounded-pill px-3 shadow-sm fw-bold" onclick="paySelectedBills(<?= $supId ?>)">
                                                            <i class="ti ti-cash me-1"></i> Proceed to Pay Selected (₹<span class="sel-total-btn">0.00</span>)
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-2" onclick="clearSupplierSelection(<?= $supId ?>)" title="Clear Selection">
                                                            <i class="ti ti-x me-1"></i> Clear
                                                        </button>
                                                    </div>
                                                </div>

                                                <div class="table-responsive">
                                                    <table class="table table-sm table-hover align-middle mb-0">
                                                        <thead class="table-light small text-uppercase">
                                                            <tr>
                                                                <th style="width: 36px;" class="ps-3 text-center">
                                                                    <input type="checkbox" class="form-check-input select-all-bills-cb" data-supplier-id="<?= $supId ?>" title="Select / Deselect all unpaid bills" onchange="toggleSelectAllBills(<?= $supId ?>, this.checked)">
                                                                </th>
                                                                <th>Internal Inv #</th>
                                                                <th>Vendor Bill #</th>
                                                                <th>Bill Date</th>
                                                                <th>Due Date</th>
                                                                <th class="text-end">Grand Total</th>
                                                                <th class="text-end">Paid Amount</th>
                                                                <th class="text-end">Balance Due</th>
                                                                <th class="text-center">Status</th>
                                                                <th class="text-end pe-3">Action</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php if (empty($supBills)): ?>
                                                                <tr>
                                                                    <td colspan="10" class="text-center py-3 text-muted">
                                                                        <i class="ti ti-file-off me-1"></i> No purchase bills recorded for this supplier yet.
                                                                    </td>
                                                                </tr>
                                                            <?php else: ?>
                                                                <?php foreach ($supBills as $b): 
                                                                    $isOverdue = ((float)$b['outstanding_amount'] > 0) && (strtotime($b['due_date']) < strtotime(date('Y-m-d')));
                                                                    $statusClass = 'bg-secondary';
                                                                    if ($b['payment_status'] === 'PAID') $statusClass = 'bg-success-subtle text-success';
                                                                    elseif ($b['payment_status'] === 'PARTIAL') $statusClass = 'bg-warning-subtle text-warning';
                                                                    elseif ($isOverdue) $statusClass = 'bg-danger-subtle text-danger';
                                                                    else $statusClass = 'bg-danger-subtle text-danger';
                                                                    $isUnpaid = ((float)$b['outstanding_amount'] > 0);
                                                                ?>
                                                                    <tr class="bill-row bill-row-<?= $supId ?> <?= $isUnpaid ? 'bill-row-unpaid' : 'bill-row-paid' ?>" id="bill_row_<?= $b['invoice_id'] ?>">
                                                                        <td class="ps-3 text-center">
                                                                            <?php if ($isUnpaid): ?>
                                                                                <input type="checkbox" class="form-check-input bill-select-box bill-cb-<?= $supId ?>" 
                                                                                       data-supplier-id="<?= $supId ?>" 
                                                                                       data-invoice-id="<?= $b['invoice_id'] ?>" 
                                                                                       data-invoice-num="<?= htmlspecialchars($b['invoice_number']) ?>" 
                                                                                       data-due="<?= (float)$b['outstanding_amount'] ?>" 
                                                                                       onchange="onBillCheckboxChange(<?= $supId ?>)">
                                                                            <?php else: ?>
                                                                                <i class="ti ti-check text-success fs-6" title="Fully Paid"></i>
                                                                            <?php endif; ?>
                                                                        </td>
                                                                        <td>
                                                                            <a href="invoices.php?view_id=<?= $b['invoice_id'] ?>" class="fw-bold font-monospace text-primary text-decoration-none">
                                                                                <?= htmlspecialchars($b['invoice_number']) ?>
                                                                            </a>
                                                                            <span class="text-muted small ms-1">(<?= (int)$b['line_items_count'] ?> items)</span>
                                                                        </td>
                                                                        <td>
                                                                            <span class="font-monospace fw-semibold text-dark"><?= htmlspecialchars($b['supplier_invoice_no'] ?: '—') ?></span>
                                                                        </td>
                                                                        <td class="small text-muted">
                                                                            <?= date('d-M-Y', strtotime($b['invoice_date'])) ?>
                                                                        </td>
                                                                        <td class="small">
                                                                            <span class="<?= $isOverdue ? 'text-danger fw-bold' : 'text-dark' ?>">
                                                                                <?= date('d-M-Y', strtotime($b['due_date'])) ?>
                                                                            </span>
                                                                            <?php if ($isOverdue): ?>
                                                                                <span class="badge bg-danger rounded-pill ms-1" style="font-size: 0.62rem;">OVERDUE</span>
                                                                            <?php endif; ?>
                                                                        </td>
                                                                        <td class="text-end fw-semibold text-dark">
                                                                            ₹<?= number_format((float)$b['grand_total'], 2) ?>
                                                                        </td>
                                                                        <td class="text-end text-success fw-semibold">
                                                                            ₹<?= number_format((float)$b['amount_paid'], 2) ?>
                                                                        </td>
                                                                        <td class="text-end fw-bold <?= (float)$b['outstanding_amount'] > 0 ? 'text-danger' : 'text-muted' ?>">
                                                                            ₹<?= number_format((float)$b['outstanding_amount'], 2) ?>
                                                                        </td>
                                                                        <td class="text-center">
                                                                            <span class="badge <?= $statusClass ?> rounded-pill px-2 py-0.5" style="font-size: 0.72rem;">
                                                                                <?= htmlspecialchars($b['payment_status']) ?>
                                                                            </span>
                                                                        </td>
                                                                        <td class="text-end pe-3">
                                                                            <div class="d-inline-flex gap-1">
                                                                                <?php if ((float)$b['outstanding_amount'] > 0 && $canCreate): ?>
                                                                                    <button type="button" class="btn btn-sm btn-success rounded-pill px-2 py-0.5 shadow-sm d-inline-flex align-items-center gap-1" style="font-size: 0.75rem;" onclick="paySpecificInvoice(<?= $supId ?>, <?= $b['invoice_id'] ?>, <?= (float)$b['outstanding_amount'] ?>)">
                                                                                        <i class="ti ti-cash"></i> Pay Bill
                                                                                    </button>
                                                                                <?php endif; ?>
                                                                                <a href="invoices.php?view_id=<?= $b['invoice_id'] ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-2 py-0.5" style="font-size: 0.75rem;" title="View Bill Details">
                                                                                    <i class="ti ti-eye"></i>
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
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recent Payments Across All Vendors -->
        <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden mb-4">
            <div class="card-header bg-white border-bottom py-3 px-4">
                <h6 class="fw-bold text-dark mb-0">
                    <i class="ti ti-history text-secondary me-2"></i>Recent Payment Disbursement Vouchers (All Vendors)
                </h6>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr class="text-uppercase small text-muted">
                            <th class="ps-4">Voucher # & Date</th>
                            <th>Supplier</th>
                            <th>Payment Mode</th>
                            <th>Reference / UTR</th>
                            <th class="text-end">Amount Paid</th>
                            <th>Invoices Settled</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($allPayments)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">No disbursement vouchers recorded yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($allPayments as $p): ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="fw-bold font-monospace text-dark"><?= htmlspecialchars($p['payment_number']) ?></div>
                                        <div class="text-muted small"><?= date('d-M-Y', strtotime($p['payment_date'])) ?></div>
                                    </td>
                                    <td>
                                        <a href="payments.php?supplier_id=<?= $p['supplier_id'] ?>" class="fw-semibold text-dark text-decoration-none">
                                            <?= htmlspecialchars($p['supplier_name'] ?? '') ?>
                                        </a>
                                        <?php if (!empty($p['supplier_code'])): ?>
                                            <div><span class="badge bg-light text-secondary font-monospace" style="font-size: 0.7rem;"><?= htmlspecialchars($p['supplier_code']) ?></span></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-light border text-dark"><?= htmlspecialchars($p['payment_mode']) ?></span>
                                    </td>
                                    <td>
                                        <span class="font-monospace text-dark small"><?= htmlspecialchars($p['reference_no'] ?: '—') ?></span>
                                    </td>
                                    <td class="text-end fw-bold text-success fs-6">
                                        ₹<?= number_format((float)$p['amount'], 2) ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary-subtle text-primary rounded-pill px-2"><?= (int)$p['allocated_count'] ?> Invoices</span>
                                    </td>
                                    <td>
                                        <span class="badge bg-success-subtle text-success rounded-pill px-2 py-1"><?= htmlspecialchars($p['status']) ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>
</div>

<!-- ===================================================================== -->
<!-- RECORD PAYMENT MODAL                                                  -->
<!-- ===================================================================== -->
<?php if ($canCreate): ?>
<div class="modal fade" id="createPaymentModal" tabindex="-1" aria-labelledby="createPaymentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" style="max-width: 1080px;">
        <div class="modal-content rounded-4 border-0 shadow-lg overflow-hidden">
            <form method="POST" action="payments.php" id="paymentForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_payment">

                <div class="modal-header bg-success text-white border-0 px-4 py-2.5">
                    <h6 class="modal-title fw-bold mb-0 d-flex align-items-center" id="createPaymentModalLabel">
                        <i class="ti ti-cash me-2 fs-5"></i>Record Supplier Disbursement &amp; Settle Bills
                    </h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body px-4 py-2.5">
                    <!-- Compact Top Row of Form Fields -->
                    <div class="p-2.5 px-3 bg-light rounded-3 mb-2 border">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-4">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label small fw-bold text-muted text-uppercase mb-0" style="font-size: 0.72rem;" id="modalSupplierLabel">Supplier *</label>
                                    <button type="button" id="btnChangeSupplier" class="btn btn-link p-0 text-decoration-none small text-primary d-none" style="font-size: 0.72rem;" onclick="enableSupplierSelectChange()">Change</button>
                                </div>
                                
                                <!-- Direct Supplier Display Card -->
                                <div id="supplierDirectCard" class="d-none align-items-center justify-content-between p-1.5 px-2.5 bg-white border border-success-subtle rounded-3 shadow-xs" style="min-height: 32px;">
                                    <div class="d-flex align-items-center text-truncate me-1">
                                        <i class="ti ti-building-store text-success me-1.5 fs-6"></i>
                                        <span class="fw-bold text-dark text-truncate small" id="supplierDirectNameText">--</span>
                                    </div>
                                    <span class="badge bg-light text-secondary border font-monospace" style="font-size: 0.68rem;" id="supplierDirectCodeText">SUP</span>
                                </div>

                                <!-- Supplier Select Dropdown -->
                                <select name="supplier_id" id="modalSupplierSelect" class="form-select form-select-sm fw-semibold" required onchange="onSupplierDropdownChanged(this.value)">
                                    <option value="">-- Choose Vendor / Supplier --</option>
                                    <?php foreach ($activeSuppliers as $sup): ?>
                                        <option value="<?= $sup['supplier_id'] ?>" data-name="<?= htmlspecialchars($sup['supplier_name'] ?? '') ?>" data-code="<?= htmlspecialchars($sup['supplier_code'] ?? '') ?>" <?= ((int)$presetSupplierId === (int)$sup['supplier_id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($sup['supplier_name'] ?? '') ?><?= !empty($sup['supplier_code']) ? ' (' . htmlspecialchars($sup['supplier_code']) . ')' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 col-sm-6">
                                <label class="form-label small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.72rem;">Payment Date *</label>
                                <input type="date" name="payment_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-2 col-sm-6">
                                <label class="form-label small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.72rem;">Payment Mode *</label>
                                <select name="payment_mode" class="form-select form-select-sm" required>
                                    <option value="Bank Transfer">Bank Transfer (NEFT/RTGS)</option>
                                    <option value="UPI">UPI / Digital QR</option>
                                    <option value="Cheque">Cheque</option>
                                    <option value="Cash">Cash</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-2 col-sm-6">
                                <label class="form-label small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.72rem;">UTR / Cheque Ref</label>
                                <input type="text" name="reference_no" id="modalRefNo" class="form-control form-control-sm font-monospace" placeholder="e.g. UTR-982138219">
                            </div>
                            <div class="col-md-2 col-sm-6">
                                <label class="form-label small fw-bold text-success text-uppercase mb-1" style="font-size: 0.72rem;">Total Paid (₹) *</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-success-subtle text-success fw-bold border-success-subtle">₹</span>
                                    <input type="number" step="0.01" name="amount" id="totalPaymentAmount" class="form-control form-control-sm fw-bold text-success border-success-subtle" required placeholder="0.00" oninput="validateAllocationSum()">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Invoices to allocate against -->
                    <div class="d-flex justify-content-between align-items-center mb-1.5">
                        <div class="d-flex align-items-center gap-2">
                            <h6 class="fw-bold text-dark mb-0 small text-uppercase" style="font-size: 0.78rem;">
                                <i class="ti ti-list-check text-success me-1"></i>Allocate Payment to Supplier Bills
                            </h6>
                            <span class="text-muted" style="font-size: 0.72rem;">(Tick bills or adjust allocated amounts)</span>
                        </div>
                        <div class="d-flex gap-1.5">
                            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-2.5 py-0.5" style="font-size: 0.74rem;" onclick="modalSelectAllInvoices(true)">
                                <i class="ti ti-check-all me-1"></i> Select &amp; Allocate All
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-2.5 py-0.5" style="font-size: 0.74rem;" onclick="modalSelectAllInvoices(false)">
                                <i class="ti ti-x me-1"></i> Clear All
                            </button>
                        </div>
                    </div>

                    <!-- Compact Scrollable Bills Container (Sticky Header & Footer) -->
                    <div id="unpaidInvoicesContainer">
                        <div class="table-responsive" style="max-height: 230px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px;">
                            <table class="table table-hover table-sm align-middle mb-0" style="font-size: 0.83rem;">
                                <thead class="table-light small text-uppercase sticky-top" style="z-index: 3; top: 0; background-color: #f1f5f9; box-shadow: 0 1px 2px rgba(0,0,0,0.06);">
                                    <tr>
                                        <th style="width: 38px;" class="text-center py-1.5">
                                            <input type="checkbox" id="modalSelectAllCb" class="form-check-input modal-inv-check" onchange="modalSelectAllInvoices(this.checked)" title="Select/Deselect All">
                                        </th>
                                        <th class="py-1.5">Internal Inv #</th>
                                        <th class="py-1.5">Vendor Bill #</th>
                                        <th class="py-1.5">Due Date</th>
                                        <th class="text-end py-1.5">Total (₹)</th>
                                        <th class="text-end py-1.5">Balance Due (₹)</th>
                                        <th class="text-end py-1.5" style="width: 170px;">Allocate Amount (₹)</th>
                                    </tr>
                                </thead>
                                <tbody id="unpaidInvoicesTbody">
                                    <?php if (empty($unpaidInvoices)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4 text-muted">
                                                <i class="ti ti-info-circle me-1"></i> Please select a supplier to load pending unpaid bills.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($unpaidInvoices as $uInv): 
                                            $prefillAlloc = ($presetInvoiceId === (int)$uInv['invoice_id'] || $presetInvoiceId === 0) ? (float)$uInv['outstanding_amount'] : 0.00;
                                            $isChecked = ($presetInvoiceId === 0 || $presetInvoiceId === (int)$uInv['invoice_id']);
                                        ?>
                                            <tr id="modal_inv_row_<?= $uInv['invoice_id'] ?>">
                                                <td class="text-center py-1">
                                                    <input type="checkbox" class="form-check-input modal-inv-check" data-invoice-id="<?= $uInv['invoice_id'] ?>" data-due="<?= (float)$uInv['outstanding_amount'] ?>" <?= $isChecked ? 'checked' : '' ?> onchange="onModalInvCheckboxChange(this, <?= $uInv['invoice_id'] ?>)">
                                                </td>
                                                <td class="font-monospace fw-semibold text-primary py-1"><?= htmlspecialchars($uInv['invoice_number'] ?? '') ?></td>
                                                <td class="font-monospace text-dark py-1"><?= htmlspecialchars($uInv['supplier_invoice_no'] ?: '—') ?></td>
                                                <td class="small py-1"><?= !empty($uInv['due_date']) ? date('d-M-Y', strtotime($uInv['due_date'])) : '—' ?></td>
                                                <td class="text-end py-1">₹<?= number_format((float)$uInv['grand_total'], 2) ?></td>
                                                <td class="text-end fw-bold text-danger max-due py-1" data-due="<?= (float)$uInv['outstanding_amount'] ?>">
                                                    ₹<?= number_format((float)$uInv['outstanding_amount'], 2) ?>
                                                </td>
                                                <td class="text-end py-1">
                                                    <input type="hidden" name="alloc_invoice_id[]" value="<?= $uInv['invoice_id'] ?>">
                                                    <input type="number" step="0.01" name="alloc_amount[]" id="modal_alloc_<?= $uInv['invoice_id'] ?>" class="form-control form-control-sm text-end alloc-input font-monospace fw-bold text-success" max="<?= (float)$uInv['outstanding_amount'] ?>" min="0" value="<?= number_format($prefillAlloc, 2, '.', '') ?>" oninput="onAllocInputChange(this, <?= $uInv['invoice_id'] ?>)">
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Inline Notes, Bank Name & Live Allocation Summary -->
                    <div class="p-2 px-3 bg-light rounded-3 mt-2 border">
                        <div class="row g-2 align-items-center">
                            <div class="col-md-4">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-white text-muted small"><i class="ti ti-building-bank"></i></span>
                                    <input type="text" name="bank_name" class="form-control form-control-sm" placeholder="Drawee Bank Name (e.g. SBI)...">
                                </div>
                            </div>
                            <div class="col-md-5">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-white text-muted small"><i class="ti ti-notes"></i></span>
                                    <input type="text" name="notes" class="form-control form-control-sm" placeholder="Disbursement remarks / notes (optional)...">
                                </div>
                            </div>
                            <div class="col-md-3 text-end">
                                <div class="d-inline-flex align-items-center justify-content-end gap-1.5 w-100">
                                    <span class="small text-muted fw-semibold" style="font-size: 0.76rem;">Allocated:</span>
                                    <span class="fs-6 fw-bold text-success font-monospace px-2 py-0.5 bg-white border border-success-subtle rounded-2" id="totalAllocatedDisplay">₹0.00</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 py-2.5 d-flex justify-content-between align-items-center bg-white">
                    <div class="small text-muted d-none d-sm-block">
                        <i class="ti ti-shield-check text-success me-1"></i>Auto-reconciles vendor balances against selected bills
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-light border rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" id="savePaymentBtn" class="btn btn-sm btn-success rounded-pill px-5 shadow-sm fw-semibold">
                            <i class="ti ti-check me-1"></i> Confirm &amp; Post Payment
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let paymentModalInstance = null;
let currentActiveSupplierId = <?= $presetSupplierId > 0 ? (int)$presetSupplierId : 'null' ?>;
const suppliersMap = <?= json_encode(array_column($activeSuppliers, null, 'supplier_id')) ?>;

// Update the modal to directly show the supplier's name and code
function updateModalSupplierDisplay(supplierId) {
    const card = document.getElementById('supplierDirectCard');
    const select = document.getElementById('modalSupplierSelect');
    const nameText = document.getElementById('supplierDirectNameText');
    const codeText = document.getElementById('supplierDirectCodeText');
    const btnChange = document.getElementById('btnChangeSupplier');

    if (!card || !select) return;

    const sId = parseInt(supplierId);
    if (sId && suppliersMap && suppliersMap[sId]) {
        const sup = suppliersMap[sId];
        if (nameText) nameText.textContent = sup.supplier_name;
        if (codeText) codeText.textContent = sup.supplier_code || 'SUP';
        
        card.classList.remove('d-none');
        card.classList.add('d-flex');
        card.style.display = 'flex';
        select.classList.add('d-none');
        select.style.display = 'none';
        select.value = String(sId);
        if (btnChange) {
            btnChange.classList.remove('d-none');
            btnChange.style.display = 'inline-block';
        }
    } else {
        card.classList.add('d-none');
        card.classList.remove('d-flex');
        card.style.display = 'none';
        select.classList.remove('d-none');
        select.style.display = 'block';
        if (btnChange) {
            btnChange.classList.add('d-none');
            btnChange.style.display = 'none';
        }
    }
}

function enableSupplierSelectChange() {
    const card = document.getElementById('supplierDirectCard');
    const select = document.getElementById('modalSupplierSelect');
    const btnChange = document.getElementById('btnChangeSupplier');
    if (card) {
        card.classList.add('d-none');
        card.classList.remove('d-flex');
        card.style.display = 'none';
    }
    if (select) {
        select.classList.remove('d-none');
        select.style.display = 'block';
        select.focus();
    }
    if (btnChange) {
        btnChange.classList.add('d-none');
        btnChange.style.display = 'none';
    }
}

function onSupplierDropdownChanged(supplierId) {
    if (supplierId) {
        currentActiveSupplierId = supplierId;
        updateModalSupplierDisplay(supplierId);
        loadSupplierUnpaidInvoices(supplierId);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const modalEl = document.getElementById('createPaymentModal');
    if (modalEl) {
        paymentModalInstance = new bootstrap.Modal(modalEl);
    }

    <?php if ($presetSupplierId > 0): ?>
        updateModalSupplierDisplay(<?= (int)$presetSupplierId ?>);
    <?php endif; ?>

    <?php if ($openModalAuto): ?>
        if (paymentModalInstance) {
            paymentModalInstance.show();
            <?php if ($presetInvoiceId > 0 && !empty($unpaidInvoices)): ?>
                const targetInv = <?= json_encode($unpaidInvoices[0] ?? null) ?>;
                if (targetInv) {
                    document.getElementById('totalPaymentAmount').value = parseFloat(targetInv.outstanding_amount).toFixed(2);
                }
            <?php endif; ?>
            validateAllocationSum();
        }
    <?php endif; ?>
});

// Select all / deselect all checkboxes in a supplier's bills table
function toggleSelectAllBills(supplierId, isChecked) {
    const boxes = document.querySelectorAll(`.bill-cb-${supplierId}`);
    boxes.forEach(cb => {
        cb.checked = isChecked;
    });
    onBillCheckboxChange(supplierId);
}

// Handle checkbox click on individual bills
function onBillCheckboxChange(supplierId) {
    currentActiveSupplierId = supplierId;
    const boxes = document.querySelectorAll(`.bill-cb-${supplierId}`);
    let selectedCount = 0;
    let totalSelectedDue = 0;

    boxes.forEach(cb => {
        const row = document.getElementById(`bill_row_${cb.dataset.invoiceId}`);
        if (cb.checked) {
            selectedCount++;
            totalSelectedDue += parseFloat(cb.dataset.due || 0);
            if (row) row.classList.add('bill-row-selected');
        } else {
            if (row) row.classList.remove('bill-row-selected');
        }
    });

    // Update table header select-all checkbox state
    const headerCb = document.querySelector(`.select-all-bills-cb[data-supplier-id="${supplierId}"]`);
    if (headerCb) {
        headerCb.checked = (boxes.length > 0 && selectedCount === boxes.length);
        headerCb.indeterminate = (selectedCount > 0 && selectedCount < boxes.length);
    }

    const formattedAmount = totalSelectedDue.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    // Update Upper Selection Action Bar (located directly above the table)
    const upperBar = document.getElementById(`upperSelectionBar_${supplierId}`);
    if (upperBar) {
        if (selectedCount > 0) {
            upperBar.classList.remove('d-none');
            upperBar.classList.add('d-flex');
            const countEl = upperBar.querySelector('.sel-count');
            const totalEl = upperBar.querySelector('.sel-total');
            const totalBtnEl = upperBar.querySelector('.sel-total-btn');
            if (countEl) countEl.textContent = selectedCount;
            if (totalEl) totalEl.textContent = formattedAmount;
            if (totalBtnEl) totalBtnEl.textContent = formattedAmount;
        } else {
            upperBar.classList.add('d-none');
            upperBar.classList.remove('d-flex');
        }
    }

    // Update header Pay Selected button if present
    const paySelBtn = document.getElementById(`paySelectedBtn_${supplierId}`);
    if (paySelBtn) {
        if (selectedCount > 0) {
            paySelBtn.classList.remove('d-none');
            const countEl = paySelBtn.querySelector('.sel-count');
            const totalEl = paySelBtn.querySelector('.sel-total');
            if (countEl) countEl.textContent = selectedCount;
            if (totalEl) totalEl.textContent = formattedAmount;
        } else {
            paySelBtn.classList.add('d-none');
        }
    }
}

// Clear checkboxes for a specific supplier
function clearSupplierSelection(supplierId) {
    document.querySelectorAll(`.bill-cb-${supplierId}`).forEach(cb => {
        cb.checked = false;
    });
    const headerCb = document.querySelector(`.select-all-bills-cb[data-supplier-id="${supplierId}"]`);
    if (headerCb) {
        headerCb.checked = false;
        headerCb.indeterminate = false;
    }
    document.querySelectorAll(`.bill-row-${supplierId}`).forEach(row => {
        row.classList.remove('bill-row-selected');
    });
    const upperBar = document.getElementById(`upperSelectionBar_${supplierId}`);
    if (upperBar) {
        upperBar.classList.add('d-none');
        upperBar.classList.remove('d-flex');
    }
    const paySelBtn = document.getElementById(`paySelectedBtn_${supplierId}`);
    if (paySelBtn) paySelBtn.classList.add('d-none');
}

// Clear all checkboxes across all tables
function clearAllSelections() {
    document.querySelectorAll('.bill-select-box').forEach(cb => {
        cb.checked = false;
    });
    document.querySelectorAll('.select-all-bills-cb').forEach(cb => {
        cb.checked = false;
        cb.indeterminate = false;
    });
    document.querySelectorAll('.bill-row-selected').forEach(row => {
        row.classList.remove('bill-row-selected');
    });
    document.querySelectorAll('.selection-upper-bar').forEach(bar => {
        bar.classList.add('d-none');
        bar.classList.remove('d-flex');
    });
    document.querySelectorAll('.pay-selected-btn').forEach(btn => {
        btn.classList.add('d-none');
    });
    currentActiveSupplierId = null;
}

// Pay selected bills for a given supplier
function paySelectedBills(supplierId) {
    if (!supplierId && currentActiveSupplierId) {
        supplierId = currentActiveSupplierId;
    }
    if (!supplierId) {
        alert("Please select a supplier.");
        return;
    }
    currentActiveSupplierId = supplierId;

    const checkedBoxes = document.querySelectorAll(`.bill-cb-${supplierId}:checked`);
    if (checkedBoxes.length === 0) {
        alert("Please select at least one bill to pay.");
        return;
    }

    const selectedMap = {};
    let totalSum = 0;

    checkedBoxes.forEach(cb => {
        const invId = parseInt(cb.dataset.invoiceId);
        const due = parseFloat(cb.dataset.due || 0);
        selectedMap[invId] = due;
        totalSum += due;
    });

    updateModalSupplierDisplay(supplierId);

    const totalInput = document.getElementById('totalPaymentAmount');
    if (totalInput) {
        totalInput.value = totalSum.toFixed(2);
    }

    loadSupplierUnpaidInvoices(supplierId, selectedMap, totalSum);
    if (paymentModalInstance) {
        paymentModalInstance.show();
    }
}

function openPaymentModal(supplierId = null) {
    if (!supplierId && currentActiveSupplierId) {
        supplierId = currentActiveSupplierId;
    }
    const select = document.getElementById('modalSupplierSelect');
    if (supplierId) {
        currentActiveSupplierId = supplierId;
        updateModalSupplierDisplay(supplierId);
        loadSupplierUnpaidInvoices(supplierId, null);
    } else if (select && select.value) {
        updateModalSupplierDisplay(select.value);
        loadSupplierUnpaidInvoices(select.value, null);
    } else {
        updateModalSupplierDisplay(null);
    }
    if (paymentModalInstance) {
        paymentModalInstance.show();
    }
}

function paySpecificInvoice(supplierId, invoiceId, dueAmount) {
    currentActiveSupplierId = supplierId;
    updateModalSupplierDisplay(supplierId);

    const due = parseFloat(dueAmount);
    const totalInput = document.getElementById('totalPaymentAmount');
    if (totalInput) {
        totalInput.value = due.toFixed(2);
    }

    const selectedMap = {};
    selectedMap[parseInt(invoiceId)] = due;

    loadSupplierUnpaidInvoices(supplierId, selectedMap, due);
    if (paymentModalInstance) {
        paymentModalInstance.show();
    }
}

// Load unpaid invoices via AJAX and populate table with checkboxes
function loadSupplierUnpaidInvoices(supplierId, preselectedMap = null, preselectTotal = null) {
    const tbody = document.getElementById('unpaidInvoicesTbody');
    if (supplierId) {
        updateModalSupplierDisplay(supplierId);
    }

    if (!supplierId) {
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-muted"><i class="ti ti-info-circle me-1"></i> Please select a supplier to load pending unpaid bills.</td></tr>';
        }
        validateAllocationSum();
        return;
    }

    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Loading supplier bills...</td></tr>';
    }

    fetch(`payments.php?ajax=get_unpaid_invoices&supplier_id=${supplierId}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success || !data.invoices || data.invoices.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-success fw-semibold"><i class="ti ti-circle-check me-1"></i> No outstanding unpaid bills for this supplier!</td></tr>';
                validateAllocationSum();
                return;
            }

            tbody.innerHTML = '';
            let totalDueSum = 0;
            let allocatedSum = 0;

            data.invoices.forEach(inv => {
                const due = parseFloat(inv.outstanding_amount);
                totalDueSum += due;
                const invId = parseInt(inv.invoice_id);

                let isChecked = false;
                let allocVal = '0.00';

                if (preselectedMap !== null) {
                    if (preselectedMap[invId] !== undefined) {
                        isChecked = true;
                        allocVal = parseFloat(preselectedMap[invId]).toFixed(2);
                        allocatedSum += parseFloat(preselectedMap[invId]);
                    }
                } else {
                    isChecked = true;
                    allocVal = due.toFixed(2);
                    allocatedSum += due;
                }

                const tr = document.createElement('tr');
                tr.id = `modal_inv_row_${invId}`;
                tr.innerHTML = `
                    <td class="text-center py-1">
                        <input type="checkbox" class="form-check-input modal-inv-check" data-invoice-id="${invId}" data-due="${due}" ${isChecked ? 'checked' : ''} onchange="onModalInvCheckboxChange(this, ${invId})">
                    </td>
                    <td class="font-monospace fw-semibold text-primary py-1">${inv.invoice_number}</td>
                    <td class="font-monospace text-dark py-1">${inv.supplier_invoice_no || '—'}</td>
                    <td class="small py-1">${inv.due_date}</td>
                    <td class="text-end py-1">₹${parseFloat(inv.grand_total).toFixed(2)}</td>
                    <td class="text-end fw-bold text-danger max-due py-1" data-due="${due}">₹${due.toFixed(2)}</td>
                    <td class="text-end py-1">
                        <input type="hidden" name="alloc_invoice_id[]" value="${invId}">
                        <input type="number" step="0.01" name="alloc_amount[]" id="modal_alloc_${invId}" class="form-control form-control-sm text-end alloc-input font-monospace fw-bold text-success" max="${due}" min="0" value="${allocVal}" oninput="onAllocInputChange(this, ${invId})">
                    </td>
                `;
                tbody.appendChild(tr);
            });

            if (preselectTotal !== null) {
                document.getElementById('totalPaymentAmount').value = parseFloat(preselectTotal).toFixed(2);
            } else if (preselectedMap === null) {
                document.getElementById('totalPaymentAmount').value = totalDueSum.toFixed(2);
            } else {
                document.getElementById('totalPaymentAmount').value = allocatedSum.toFixed(2);
            }

            updateModalHeaderSelectAllState();
            validateAllocationSum();
        })
        .catch(err => {
            tbody.innerHTML = `<tr><td colspan="7" class="text-center py-4 text-danger"><i class="ti ti-alert-circle me-1"></i> Failed to load bills: ${err.message}</td></tr>`;
        });
}

// When checkbox is toggled inside modal
function onModalInvCheckboxChange(checkbox, invoiceId) {
    const allocInput = document.getElementById(`modal_alloc_${invoiceId}`);
    if (allocInput) {
        if (checkbox.checked) {
            const maxDue = parseFloat(checkbox.dataset.due || 0);
            allocInput.value = maxDue.toFixed(2);
        } else {
            allocInput.value = '0.00';
        }
    }

    // Recalculate and sync total payment amount with checked items
    syncModalTotalFromAllocations();
    updateModalHeaderSelectAllState();
    validateAllocationSum();
}

// When allocation amount is typed manually in modal
function onAllocInputChange(input, invoiceId) {
    const val = parseFloat(input.value || 0);
    const cb = document.querySelector(`.modal-inv-check[data-invoice-id="${invoiceId}"]`);
    if (cb) {
        cb.checked = (val > 0);
    }
    updateModalHeaderSelectAllState();
    validateAllocationSum();
}

// Select All / Deselect All inside Modal
function modalSelectAllInvoices(isChecked) {
    const checkboxes = document.querySelectorAll('.modal-inv-check');
    checkboxes.forEach(cb => {
        cb.checked = isChecked;
        const invId = cb.dataset.invoiceId;
        const allocInput = document.getElementById(`modal_alloc_${invId}`);
        if (allocInput) {
            if (isChecked) {
                const maxDue = parseFloat(cb.dataset.due || 0);
                allocInput.value = maxDue.toFixed(2);
            } else {
                allocInput.value = '0.00';
            }
        }
    });

    const headerCb = document.getElementById('modalSelectAllCb');
    if (headerCb) {
        headerCb.checked = isChecked;
        headerCb.indeterminate = false;
    }

    syncModalTotalFromAllocations();
    validateAllocationSum();
}

function updateModalHeaderSelectAllState() {
    const checkboxes = document.querySelectorAll('.modal-inv-check');
    const headerCb = document.getElementById('modalSelectAllCb');
    if (!headerCb || checkboxes.length === 0) return;

    let checkedCount = 0;
    checkboxes.forEach(cb => {
        if (cb.checked) checkedCount++;
    });

    headerCb.checked = (checkedCount === checkboxes.length);
    headerCb.indeterminate = (checkedCount > 0 && checkedCount < checkboxes.length);
}

function syncModalTotalFromAllocations() {
    let sum = 0;
    document.querySelectorAll('.alloc-input').forEach(input => {
        sum += parseFloat(input.value) || 0;
    });
    document.getElementById('totalPaymentAmount').value = sum.toFixed(2);
}

function autoAllocateAll() {
    modalSelectAllInvoices(true);
}

function validateAllocationSum() {
    const totalPay = parseFloat(document.getElementById('totalPaymentAmount').value) || 0;
    let totalAlloc = 0;
    document.querySelectorAll('.alloc-input').forEach(input => {
        let val = parseFloat(input.value) || 0;
        const max = parseFloat(input.getAttribute('max')) || 0;
        if (val > max) {
            val = max;
            input.value = max.toFixed(2);
        }
        totalAlloc += val;
    });

    const disp = document.getElementById('totalAllocatedDisplay');
    if (disp) {
        disp.textContent = '₹' + totalAlloc.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        if (totalAlloc > (totalPay + 0.01) && totalPay > 0) {
            disp.className = 'text-end fs-6 text-danger fw-bold';
        } else {
            disp.className = 'text-end fs-6 text-success fw-bold';
        }
    }
}
</script>
<?php endif; ?>

<?php
include __DIR__ . '/../../includes/footer.php';
?>

