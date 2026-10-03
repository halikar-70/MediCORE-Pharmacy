<?php
// modules/sales/returns.php - Sales Returns & Restock Management
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
use Pharmacy\Auth\AuthManager;

$returnService = new SalesReturnService($pdo);
$page_title = 'Sales Returns & Restock';

// Direct print / invoice view mode
if (isset($_GET['print_id']) || isset($_GET['invoice_id']) || isset($_GET['print_return_id'])) {
    require_once __DIR__ . '/return_invoice.php';
    exit;
}

$errors = [];
$successMessage = null;
$createdReturnId = null;

// Handle Return Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_return') {
    verify_csrf();
    require_permission('pharmacy.sales_returns.create');

    $saleId = (int)($_POST['sale_id'] ?? 0);
    $items = $_POST['items'] ?? [];
    $reason = trim($_POST['reason'] ?? 'Customer Return');
    $paymentMode = trim($_POST['payment_mode'] ?? 'CASH');
    $idempotencyKey = trim($_POST['idempotency_key'] ?? ('SRT_POST_' . uniqid('', true)));

    if ($saleId <= 0) {
        $errors[] = "Please select a valid original sale.";
    }

    $validItems = [];
    foreach ($items as $it) {
        $qty = (int)($it['return_quantity'] ?? 0);
        if ($qty > 0) {
            $validItems[] = [
                'sale_item_id'     => (int)$it['sale_item_id'],
                'batch_id'         => (int)$it['batch_id'],
                'return_quantity'  => $qty,
                'condition_status' => $it['condition_status'] ?? 'SEALED_INTACT',
                'restock_decision' => $it['restock_decision'] ?? 'SELLABLE_RESTOCK',
                'return_reason'    => trim($it['return_reason'] ?? $reason)
            ];
        }
    }

    if (empty($validItems)) {
        $errors[] = "Please specify a return quantity greater than zero for at least one item.";
    }

    if (empty($errors)) {
        try {
            $userId = AuthManager::userId() ?? 1;
            $res = $returnService->createReturn([
                'sale_id'         => $saleId,
                'reason'          => $reason,
                'payment_mode'    => $paymentMode,
                'idempotency_key' => $idempotencyKey
            ], $validItems, $userId);

            $createdReturnId = (int)$res['return_id'];
            $successMessage = "Sales Return #{$res['return_number']} posted successfully! Refund amount: ₹" . number_format($res['total_refund_amount'], 2);
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }
}

// Handle AJAX Search for Sale
if (isset($_GET['ajax']) && $_GET['ajax'] === 'lookup_sale') {
    header('Content-Type: application/json');
    $query = trim($_GET['q'] ?? '');
    if (strlen($query) < 2) {
        echo json_encode(['success' => false, 'message' => 'Search term too short']);
        exit;
    }

    $sStmt = $pdo->prepare("
        SELECT s.sale_id, s.sale_number, s.sale_type, s.sale_date, s.customer_name,
               s.customer_mobile, s.grand_total, s.paid_amount, s.status,
               COALESCE(p.name, s.customer_name, 'Walk-in') AS patient_display
        FROM pharmacy_sales s
        LEFT JOIN pharmacy_patients p ON s.patient_id = p.id
        WHERE s.status != 'CANCELLED'
          AND (s.sale_number LIKE ? OR s.customer_name LIKE ? OR s.customer_mobile LIKE ?)
        ORDER BY s.sale_id DESC LIMIT 10
    ");
    $param = "%{$query}%";
    $sStmt->execute([$param, $param, $param]);
    $sales = $sStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'sales' => $sales]);
    exit;
}

// Handle AJAX Fetch Returnable Details
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_sale_details') {
    header('Content-Type: application/json');
    $sId = (int)($_GET['sale_id'] ?? 0);
    try {
        $details = $returnService->getReturnableSaleDetails($sId);
        echo json_encode(['success' => true, 'data' => $details]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle AJAX Fetch Return Details (Invoice Data)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_return_details') {
    header('Content-Type: application/json');
    $retId = (int)($_GET['return_id'] ?? 0);
    try {
        $details = $returnService->getReturnDetails($retId);
        if ($details) {
            echo json_encode(['success' => true, 'data' => $details]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Return record not found']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Summary Metrics
$mStmt = $pdo->query("
    SELECT 
        COUNT(return_id) AS total_returns,
        COALESCE(SUM(total_refund_amount), 0) AS total_refunds
    FROM pharmacy_sales_returns
    WHERE status != 'CANCELLED'
");
$metrics = $mStmt->fetch(PDO::FETCH_ASSOC);

$itemMStmt = $pdo->query("
    SELECT 
        SUM(CASE WHEN restock_decision = 'SELLABLE_RESTOCK' THEN return_quantity ELSE 0 END) AS restocked_units,
        SUM(CASE WHEN restock_decision = 'QUARANTINE' THEN return_quantity ELSE 0 END) AS quarantined_units
    FROM pharmacy_sales_return_items sri
    JOIN pharmacy_sales_returns sr ON sri.return_id = sr.return_id
    WHERE sr.status != 'CANCELLED'
");
$itemMetrics = $itemMStmt->fetch(PDO::FETCH_ASSOC);

// Search Filters for Register
$fReturnNo = trim($_GET['return_no'] ?? '');
$fSaleNo = trim($_GET['sale_no'] ?? '');
$fDate = trim($_GET['return_date'] ?? '');

$where = ["1=1"];
$params = [];
if ($fReturnNo !== '') {
    $where[] = "sr.return_number LIKE ?";
    $params[] = "%{$fReturnNo}%";
}
if ($fSaleNo !== '') {
    $where[] = "sr.sale_number LIKE ?";
    $params[] = "%{$fSaleNo}%";
}
if ($fDate !== '') {
    $where[] = "sr.return_date = ?";
    $params[] = $fDate;
}

$regSql = "
    SELECT sr.*, 
           COUNT(sri.item_id) AS line_items_count,
           SUM(sri.return_quantity) AS total_units_returned,
           u.username AS created_by_username
    FROM pharmacy_sales_returns sr
    LEFT JOIN pharmacy_sales_return_items sri ON sr.return_id = sri.return_id
    LEFT JOIN pharmacy_users u ON sr.created_by = u.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY sr.return_id
    ORDER BY sr.return_id DESC LIMIT 50
";
$rStmt = $pdo->prepare($regSql);
$rStmt->execute($params);
$returns = $rStmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="container-fluid px-4 py-3">
    <!-- Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h4 class="fw-bold text-dark mb-1">
                <i class="ti ti-arrow-back-up text-primary me-2"></i>Sales Returns & Restock Management
            </h4>
            <p class="text-muted small mb-0">Patient & customer medicine returns, batch-specific triage, and restocking controls.</p>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-primary rounded-pill px-3 py-2 small shadow-sm" data-bs-toggle="modal" data-bs-target="#modalNewReturn">
                <i class="ti ti-plus me-1"></i> New Sales Return
            </button>
            <a href="monitoring.php" class="btn btn-outline-secondary rounded-pill px-3 py-2 small">
                <i class="ti ti-receipt me-1"></i> Sales Register
            </a>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-3 shadow-sm mb-4" role="alert">
            <i class="ti ti-alert-circle me-2 fs-5 align-middle"></i>
            <strong>Submission Error:</strong>
            <ul class="mb-0 mt-2">
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($successMessage): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3 shadow-sm mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2" role="alert">
            <div class="d-flex align-items-center">
                <i class="ti ti-circle-check me-2 fs-5"></i>
                <span class="fw-semibold"><?= htmlspecialchars($successMessage) ?></span>
            </div>
            <?php if (!empty($createdReturnId)): ?>
                <a href="return_invoice.php?id=<?= $createdReturnId ?>" target="_blank" class="btn btn-sm btn-success text-white rounded-pill px-3 shadow-sm d-inline-flex align-items-center gap-1">
                    <i class="ti ti-printer"></i> Print Return Invoice
                </a>
            <?php endif; ?>
            <button type="button" class="btn-close ms-2" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Summary Metrics Widgets -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="p-3 bg-primary-subtle text-primary rounded-circle fs-4">
                        <i class="ti ti-arrow-back-up"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Total Returns</div>
                        <div class="fw-bold fs-4 text-dark"><?= number_format($metrics['total_returns'] ?? 0) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="p-3 bg-danger-subtle text-danger rounded-circle fs-4">
                        <i class="ti ti-cash-banknote"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Total Refunded</div>
                        <div class="fw-bold fs-4 text-dark"><?= format_currency($metrics['total_refunds'] ?? 0) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="p-3 bg-success-subtle text-success rounded-circle fs-4">
                        <i class="ti ti-rotate-clockwise"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Restocked Units</div>
                        <div class="fw-bold fs-4 text-dark"><?= number_format($itemMetrics['restocked_units'] ?? 0) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="p-3 bg-warning-subtle text-warning rounded-circle fs-4">
                        <i class="ti ti-shield-alert"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Quarantined Units</div>
                        <div class="fw-bold fs-4 text-dark"><?= number_format($itemMetrics['quarantined_units'] ?? 0) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Returns Register -->
    <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden mb-5">
        <div class="card-header bg-white py-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h5 class="fw-bold mb-0 text-dark">
                <i class="ti ti-list me-1 text-primary"></i>Sales Returns Register
            </h5>
            <form method="GET" class="d-flex flex-wrap gap-2 align-items-center">
                <input type="text" name="return_no" class="form-control form-control-sm rounded-pill" placeholder="Return No..." value="<?= htmlspecialchars($fReturnNo) ?>" style="width: 140px;">
                <input type="text" name="sale_no" class="form-control form-control-sm rounded-pill" placeholder="Sale No..." value="<?= htmlspecialchars($fSaleNo) ?>" style="width: 140px;">
                <input type="date" name="return_date" class="form-control form-control-sm rounded-pill" value="<?= htmlspecialchars($fDate) ?>" style="width: 140px;">
                <button type="submit" class="btn btn-sm btn-primary rounded-pill px-3">Filter</button>
                <a href="returns.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3">Reset</a>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light text-muted small text-uppercase">
                    <tr>
                        <th class="ps-4">Return No</th>
                        <th>Return Date</th>
                        <th>Original Sale</th>
                        <th>Customer / Patient</th>
                        <th>Items / Units</th>
                        <th class="text-end">Refund Amount</th>
                        <th>Payment Mode</th>
                        <th class="text-center">Status</th>
                        <th>Created By</th>
                        <th class="text-center pe-4" style="min-width: 105px;">Invoice</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($returns)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-5 text-muted">
                                <i class="ti ti-arrow-back-up fs-1 d-block mb-2 text-muted"></i>
                                No customer sales returns recorded yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($returns as $r): ?>
                            <tr>
                                <td class="ps-4">
                                    <a href="return_invoice.php?id=<?= (int)$r['return_id'] ?>" target="_blank" class="font-monospace fw-bold text-primary text-decoration-none bg-primary-subtle px-2 py-1 rounded d-inline-flex align-items-center gap-1" title="View & Print Return Invoice">
                                        <i class="ti ti-receipt fs-6"></i> <?= htmlspecialchars($r['return_number']) ?>
                                    </a>
                                </td>
                                <td><?= format_date($r['return_date']) ?></td>
                                <td>
                                    <a href="counter.php?print_id=<?= (int)$r['sale_id'] ?>" target="_blank" class="badge bg-light text-dark border font-monospace text-decoration-none d-inline-flex align-items-center gap-1" title="View Original Sale Invoice">
                                        <?= htmlspecialchars($r['sale_number']) ?> <i class="ti ti-external-link small text-muted"></i>
                                    </a>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= htmlspecialchars($r['customer_name'] ?: 'Walk-in Customer') ?></div>
                                    <span class="badge bg-secondary-subtle text-secondary small"><?= htmlspecialchars($r['sale_type']) ?></span>
                                </td>
                                <td>
                                    <span class="fw-bold text-dark"><?= $r['line_items_count'] ?></span> line(s) / 
                                    <span class="text-muted"><?= $r['total_units_returned'] ?> unit(s)</span>
                                </td>
                                <td class="text-end fw-bold text-danger"><?= format_currency($r['total_refund_amount']) ?></td>
                                <td>
                                    <span class="badge bg-info-subtle text-info font-monospace"><?= htmlspecialchars($r['payment_mode']) ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-success-subtle text-success rounded-pill px-3">
                                        <?= htmlspecialchars($r['status']) ?>
                                    </span>
                                </td>
                                <td class="text-muted small">
                                    <?= htmlspecialchars($r['created_by_username'] ?? 'System') ?>
                                </td>
                                <td class="text-center pe-4">
                                    <a href="return_invoice.php?id=<?= (int)$r['return_id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill px-2.5 py-1 d-inline-flex align-items-center gap-1 shadow-sm" title="View & Print Return Invoice">
                                        <i class="ti ti-printer"></i> <span>Invoice</span>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: New Sales Return -->
<div class="modal fade" id="modalNewReturn" tabindex="-1" aria-labelledby="modalNewReturnLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-primary text-white border-0 py-3">
                <h5 class="modal-title fw-bold" id="modalNewReturnLabel">
                    <i class="ti ti-arrow-back-up me-2"></i>Process Customer Sales Return
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <!-- Step 1: Lookup Sale -->
                <div class="card border-0 shadow-sm rounded-4 p-3 bg-white mb-4">
                    <h6 class="fw-bold text-dark mb-2">Step 1: Search Original Sale Invoice</h6>
                    <div class="row g-2 align-items-center">
                        <div class="col-md-9">
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i class="ti ti-search"></i></span>
                                <input type="text" id="saleSearchInput" class="form-control border-start-0" placeholder="Enter Sale Number (e.g. CS-000001, RS-), Customer Name, or Mobile Number...">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <button type="button" id="btnSearchSale" class="btn btn-primary w-100 rounded-pill">
                                <i class="ti ti-search me-1"></i> Search Invoice
                            </button>
                        </div>
                    </div>
                    <div id="saleSearchResults" class="list-group mt-3 d-none"></div>
                </div>

                <!-- Step 2: Selected Sale & Item Return Form -->
                <form method="POST" id="formProcessReturn" class="d-none">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="process_return">
                    <input type="hidden" name="sale_id" id="selectedSaleId" value="">
                    <input type="hidden" name="idempotency_key" value="SRT_POST_<?= uniqid('', true) ?>">

                    <div class="card border-0 shadow-sm rounded-4 p-3 bg-white mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <span class="badge bg-primary-subtle text-primary px-3 py-2 fs-6 rounded-pill" id="displaySaleNo"></span>
                                <span class="text-muted ms-2" id="displayCustomer"></span>
                            </div>
                            <div class="text-end">
                                <span class="small text-muted">Original Total:</span>
                                <span class="fw-bold text-dark fs-6 ms-1" id="displayOriginalTotal"></span>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered align-middle mb-0" id="returnItemsTable">
                                <thead class="bg-light text-muted small text-uppercase">
                                    <tr>
                                        <th>Medicine / Batch</th>
                                        <th class="text-center">Sold Qty</th>
                                        <th class="text-center">Prev Ret</th>
                                        <th class="text-center">Returnable</th>
                                        <th style="width: 110px;">Return Qty</th>
                                        <th style="width: 150px;">Condition</th>
                                        <th style="width: 170px;">Restock Action</th>
                                        <th class="text-end">Line Refund</th>
                                    </tr>
                                </thead>
                                <tbody id="returnItemsTbody">
                                    <!-- Dynamic Rows -->
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Step 3: Refund Payment & Justification -->
                    <div class="card border-0 shadow-sm rounded-4 p-3 bg-white mb-4">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">Return Date</label>
                                <input type="date" name="return_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">Refund Payment Mode</label>
                                <select name="payment_mode" class="form-select" required>
                                    <option value="CASH">Cash Refund</option>
                                    <option value="UPI">UPI / Digital Refund</option>
                                    <option value="CREDIT">Adjust Patient Credit Balance</option>
                                    <option value="CARD">Credit / Debit Card</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">Total Refund Payable</label>
                                <div class="form-control-plaintext fs-4 fw-bold text-danger text-end" id="grandRefundAmountDisplay">
                                    ₹0.00
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-bold">Return Justification / Reason *</label>
                                <textarea name="reason" class="form-control" rows="2" placeholder="Mandatory reason for return and audit trail..." required></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" id="btnSubmitReturn" class="btn btn-success rounded-pill px-5">
                            <i class="ti ti-check me-1"></i> Confirm & Post Return
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const saleSearchInput = document.getElementById('saleSearchInput');
    const btnSearchSale = document.getElementById('btnSearchSale');
    const saleSearchResults = document.getElementById('saleSearchResults');
    const formProcessReturn = document.getElementById('formProcessReturn');
    const returnItemsTbody = document.getElementById('returnItemsTbody');
    const grandRefundAmountDisplay = document.getElementById('grandRefundAmountDisplay');

    btnSearchSale.addEventListener('click', function() {
        const q = saleSearchInput.value.trim();
        if (q.length < 2) {
            alert('Please enter at least 2 characters to search.');
            return;
        }

        btnSearchSale.disabled = true;
        btnSearchSale.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Searching...';

        fetch(`returns.php?ajax=lookup_sale&q=${encodeURIComponent(q)}`)
            .then(res => {
                if (!res.ok) throw new Error('Server returned HTTP ' + res.status);
                return res.json();
            })
            .then(data => {
                saleSearchResults.innerHTML = '';
                if (data.success && data.sales.length > 0) {
                    saleSearchResults.classList.remove('d-none');
                    data.sales.forEach(s => {
                        const item = document.createElement('a');
                        item.href = 'javascript:void(0)';
                        item.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center py-2';
                        item.innerHTML = `
                            <div>
                                <span class="fw-bold text-dark">${s.sale_number}</span> 
                                <span class="text-muted ms-2">(${s.sale_type}) - ${s.patient_display}</span>
                                <div class="text-muted small">${s.sale_date} | Mobile: ${s.customer_mobile || 'N/A'}</div>
                            </div>
                            <div class="text-end">
                                <div class="fw-bold text-dark">₹${parseFloat(s.grand_total).toFixed(2)}</div>
                                <span class="badge bg-success-subtle text-success small">${s.status}</span>
                            </div>
                        `;
                        item.addEventListener('click', function() {
                            loadSaleForReturn(s.sale_id);
                        });
                        saleSearchResults.appendChild(item);
                    });
                } else {
                    saleSearchResults.classList.remove('d-none');
                    saleSearchResults.innerHTML = '<div class="p-3 text-muted text-center">No matching sales found.</div>';
                }
            })
            .catch(err => {
                saleSearchResults.classList.remove('d-none');
                saleSearchResults.innerHTML = `<div class="p-3 text-danger text-center"><i class="ti ti-alert-circle me-1"></i> Failed to search sales: ${err.message}</div>`;
            })
            .finally(() => {
                btnSearchSale.disabled = false;
                btnSearchSale.innerHTML = '<i class="ti ti-search me-1"></i> Search';
            });
    });

    function loadSaleForReturn(saleId) {
        returnItemsTbody.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Loading sale items...</td></tr>';
        formProcessReturn.classList.remove('d-none');

        fetch(`returns.php?ajax=get_sale_details&sale_id=${saleId}`)
            .then(res => {
                if (!res.ok) throw new Error('Server returned HTTP ' + res.status);
                return res.json();
            })
            .then(data => {
                if (!data.success) {
                    alert(data.message || 'Error loading sale details');
                    formProcessReturn.classList.add('d-none');
                    return;
                }

                const s = data.data;
                document.getElementById('selectedSaleId').value = s.sale_id;
                document.getElementById('displaySaleNo').textContent = s.sale_number;
                document.getElementById('displayCustomer').textContent = `${s.patient_display_name} (${s.sale_type})`;
                document.getElementById('displayOriginalTotal').textContent = `₹${parseFloat(s.grand_total).toFixed(2)}`;

                returnItemsTbody.innerHTML = '';
                saleSearchResults.classList.add('d-none');
                formProcessReturn.classList.remove('d-none');

                let rowIdx = 0;
                s.items.forEach(item => {
                    item.batches.forEach(b => {
                        const tr = document.createElement('tr');
                        const unitRate = parseFloat(item.unit_price) * (1 - (parseFloat(item.discount_percent || 0) / 100)) * (1 + (parseFloat(item.tax_percent || 0) / 100));
                        
                        tr.innerHTML = `
                            <td>
                                <input type="hidden" name="items[${rowIdx}][sale_item_id]" value="${item.sale_item_id}">
                                <input type="hidden" name="items[${rowIdx}][batch_id]" value="${b.batch_id}">
                                <input type="hidden" class="item-rate" value="${unitRate}">
                                <div class="fw-bold text-dark">${item.medicine_name}</div>
                                <span class="font-monospace small text-muted bg-light px-2 py-0.5 rounded">Batch: ${b.batch_number} (Exp: ${b.expiry_date})</span>
                            </td>
                            <td class="text-center fw-bold">${b.allocated_quantity}</td>
                            <td class="text-center text-muted">${b.batch_previously_returned}</td>
                            <td class="text-center text-primary fw-bold">${b.batch_returnable}</td>
                            <td>
                                <input type="number" name="items[${rowIdx}][return_quantity]" class="form-control form-control-sm return-qty-input" min="0" max="${b.batch_returnable}" value="0" ${b.batch_returnable === 0 ? 'disabled' : ''}>
                            </td>
                            <td>
                                <select name="items[${rowIdx}][condition_status]" class="form-select form-select-sm">
                                    <option value="SEALED_INTACT">Sealed / Intact</option>
                                    <option value="OPENED">Opened Pack</option>
                                    <option value="DAMAGED">Damaged / Leaked</option>
                                    <option value="EXPIRED_POST_SALE">Expired Post-Sale</option>
                                </select>
                            </td>
                            <td>
                                <select name="items[${rowIdx}][restock_decision]" class="form-select form-select-sm restock-decision-select">
                                    <option value="SELLABLE_RESTOCK">Restock (Sellable)</option>
                                    <option value="QUARANTINE">Move to Quarantine</option>
                                    <option value="NON_SELLABLE">Mark Damaged</option>
                                    <option value="DISPOSAL">Send to Disposal</option>
                                </select>
                            </td>
                            <td class="text-end fw-bold text-dark line-refund-display">₹0.00</td>
                        `;
                        returnItemsTbody.appendChild(tr);
                        rowIdx++;
                    });
                });

                bindCalculations();
            });
    }

    function bindCalculations() {
        const qtyInputs = document.querySelectorAll('.return-qty-input');
        qtyInputs.forEach(input => {
            input.addEventListener('input', calculateTotalRefund);
        });
    }

    function calculateTotalRefund() {
        let total = 0;
        const rows = returnItemsTbody.querySelectorAll('tr');
        rows.forEach(tr => {
            const qtyInput = tr.querySelector('.return-qty-input');
            const rateInput = tr.querySelector('.item-rate');
            const lineDisplay = tr.querySelector('.line-refund-display');
            if (qtyInput && rateInput) {
                const qty = parseInt(qtyInput.value) || 0;
                const rate = parseFloat(rateInput.value) || 0;
                const lineRefund = qty * rate;
                lineDisplay.textContent = `₹${lineRefund.toFixed(2)}`;
                total += lineRefund;
            }
        });
        grandRefundAmountDisplay.textContent = `₹${total.toFixed(2)}`;
    }
});
</script>

<?php
include __DIR__ . '/../../includes/footer.php';
?>