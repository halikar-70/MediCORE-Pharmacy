<?php
// modules/purchases/returns.php - Purchase Returns & Supplier Debit Notes
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/DocumentSequenceService.php';
require_once __DIR__ . '/../../app/Services/StockLedgerService.php';
require_once __DIR__ . '/../../app/Services/AuditService.php';
require_once __DIR__ . '/../../app/Services/PurchaseReturnService.php';

require_permission('pharmacy.purchase_returns.view');

use Pharmacy\Services\PurchaseReturnService;
use Pharmacy\Auth\AuthManager;

$prService = new PurchaseReturnService($pdo);
$page_title = 'Purchase Returns & Debit Notes';

$errors = [];
$successMessage = null;

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_purchase_return') {
    verify_csrf();
    require_permission('pharmacy.purchase_returns.create');

    $invoiceId = (int)($_POST['invoice_id'] ?? 0);
    $items = $_POST['items'] ?? [];
    $reason = trim($_POST['reason'] ?? 'Supplier Return');
    $notes = trim($_POST['notes'] ?? '');
    $idempotencyKey = trim($_POST['idempotency_key'] ?? ('PRT_POST_' . uniqid('', true)));

    if ($invoiceId <= 0) {
        $errors[] = "Please select a valid purchase invoice.";
    }

    $validItems = [];
    foreach ($items as $it) {
        $qty = (int)($it['return_quantity'] ?? 0);
        if ($qty > 0) {
            $validItems[] = [
                'invoice_item_id'  => (int)$it['invoice_item_id'],
                'return_quantity'  => $qty,
                'condition_status' => $it['condition_status'] ?? 'DEFECTIVE',
                'reason'           => trim($it['reason'] ?? $reason)
            ];
        }
    }

    if (empty($validItems)) {
        $errors[] = "Please specify a return quantity greater than zero for at least one item.";
    }

    if (empty($errors)) {
        try {
            $userId = AuthManager::userId() ?? 1;
            $res = $prService->createPurchaseReturn([
                'invoice_id'      => $invoiceId,
                'reason'          => $reason,
                'notes'           => $notes,
                'idempotency_key' => $idempotencyKey
            ], $validItems, $userId);

            $successMessage = "Purchase Return #{$res['return_number']} posted successfully! Supplier debit amount: ₹" . number_format($res['refund_amount'], 2) . ". Remaining invoice outstanding: ₹" . number_format($res['new_outstanding'], 2);
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }
}

// Handle AJAX Search for Invoice
if (isset($_GET['ajax']) && $_GET['ajax'] === 'lookup_invoice') {
    header('Content-Type: application/json');
    $query = trim($_GET['q'] ?? '');
    if (strlen($query) < 2) {
        echo json_encode(['success' => false, 'message' => 'Query too short']);
        exit;
    }

    $iStmt = $pdo->prepare("
        SELECT pi.invoice_id, pi.invoice_number, pi.supplier_invoice_no, pi.invoice_date,
               pi.grand_total, pi.outstanding_amount, pi.payment_status,
               s.supplier_name, s.supplier_id
        FROM pharmacy_purchase_invoices pi
        JOIN pharmacy_suppliers s ON pi.supplier_id = s.supplier_id
        WHERE pi.invoice_number LIKE ? OR pi.supplier_invoice_no LIKE ? OR s.supplier_name LIKE ?
        ORDER BY pi.invoice_id DESC LIMIT 10
    ");
    $param = "%{$query}%";
    $iStmt->execute([$param, $param, $param]);
    $invoices = $iStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'invoices' => $invoices]);
    exit;
}

// Handle AJAX Get Invoice Returnable Details
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_invoice_details') {
    header('Content-Type: application/json');
    $invId = (int)($_GET['invoice_id'] ?? 0);
    try {
        $details = $prService->getReturnableInvoiceDetails($invId);
        echo json_encode(['success' => true, 'data' => $details]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Summary Metrics
$mStmt = $pdo->query("
    SELECT 
        COUNT(return_id) AS total_returns,
        COALESCE(SUM(refund_amount), 0) AS total_debit_amount
    FROM pharmacy_purchase_returns
    WHERE status != 'CANCELLED'
");
$metrics = $mStmt->fetch(PDO::FETCH_ASSOC);

$itemMStmt = $pdo->query("
    SELECT COALESCE(SUM(quantity), 0) AS total_returned_units
    FROM pharmacy_purchase_return_items pri
    JOIN pharmacy_purchase_returns pr ON pri.return_id = pr.return_id
    WHERE pr.status != 'CANCELLED'
");
$itemMetrics = $itemMStmt->fetch(PDO::FETCH_ASSOC);

// Search Filters for Register
$fReturnNo = trim($_GET['return_no'] ?? '');
$fSupplier = trim($_GET['supplier'] ?? '');
$fDate = trim($_GET['return_date'] ?? '');

$where = ["1=1"];
$params = [];
if ($fReturnNo !== '') {
    $where[] = "pr.return_number LIKE ?";
    $params[] = "%{$fReturnNo}%";
}
if ($fSupplier !== '') {
    $where[] = "s.supplier_name LIKE ?";
    $params[] = "%{$fSupplier}%";
}
if ($fDate !== '') {
    $where[] = "pr.return_date = ?";
    $params[] = $fDate;
}

$regSql = "
    SELECT pr.*, 
           pi.invoice_number, pi.supplier_invoice_no,
           s.supplier_name,
           COUNT(pri.item_id) AS line_items_count,
           SUM(pri.quantity) AS total_units_returned,
           u.username AS created_by_username
    FROM pharmacy_purchase_returns pr
    LEFT JOIN pharmacy_purchase_invoices pi ON pr.invoice_id = pi.invoice_id
    LEFT JOIN pharmacy_suppliers s ON pr.supplier_id = s.supplier_id
    LEFT JOIN pharmacy_purchase_return_items pri ON pr.return_id = pri.return_id
    LEFT JOIN pharmacy_users u ON pr.created_by = u.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY pr.return_id
    ORDER BY pr.return_id DESC LIMIT 50
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
                <i class="ti ti-truck-return text-primary me-2"></i>Purchase Returns & Debit Notes
            </h4>
            <p class="text-muted small mb-0">Supplier stock returns, debit note issuance, and accounts payable reconciliation.</p>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-primary rounded-pill px-3 py-2 small shadow-sm" data-bs-toggle="modal" data-bs-target="#modalNewPurchaseReturn">
                <i class="ti ti-plus me-1"></i> New Purchase Return
            </button>
            <a href="invoices.php" class="btn btn-outline-secondary rounded-pill px-3 py-2 small">
                <i class="ti ti-file-invoice me-1"></i> Purchase Invoices
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
        <div class="alert alert-success alert-dismissible fade show rounded-3 shadow-sm mb-4" role="alert">
            <i class="ti ti-circle-check me-2 fs-5 align-middle"></i>
            <?= htmlspecialchars($successMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Summary Metrics -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-4">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="p-3 bg-primary-subtle text-primary rounded-circle fs-4">
                        <i class="ti ti-truck-return"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Total Returns</div>
                        <div class="fw-bold fs-4 text-dark"><?= number_format($metrics['total_returns'] ?? 0) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-4">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="p-3 bg-danger-subtle text-danger rounded-circle fs-4">
                        <i class="ti ti-credit-card-off"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Total Debit Amount</div>
                        <div class="fw-bold fs-4 text-dark"><?= format_currency($metrics['total_debit_amount'] ?? 0) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-4">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="p-3 bg-warning-subtle text-warning rounded-circle fs-4">
                        <i class="ti ti-packages"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Units Returned</div>
                        <div class="fw-bold fs-4 text-dark"><?= number_format($itemMetrics['total_returned_units'] ?? 0) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Purchase Returns Register -->
    <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden mb-5">
        <div class="card-header bg-white py-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h5 class="fw-bold mb-0 text-dark">
                <i class="ti ti-list me-1 text-primary"></i>Purchase Returns Register
            </h5>
            <form method="GET" class="d-flex flex-wrap gap-2 align-items-center">
                <input type="text" name="return_no" class="form-control form-control-sm rounded-pill" placeholder="Return No..." value="<?= htmlspecialchars($fReturnNo) ?>" style="width: 140px;">
                <input type="text" name="supplier" class="form-control form-control-sm rounded-pill" placeholder="Supplier..." value="<?= htmlspecialchars($fSupplier) ?>" style="width: 150px;">
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
                        <th>Supplier</th>
                        <th>Purchase Invoice</th>
                        <th>Units Returned</th>
                        <th class="text-end">Debit Amount</th>
                        <th class="text-center">Status</th>
                        <th class="text-end pe-4">Created By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($returns)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="ti ti-truck-return fs-1 d-block mb-2 text-muted"></i>
                                No purchase returns recorded yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($returns as $r): ?>
                            <tr>
                                <td class="ps-4">
                                    <span class="font-monospace fw-bold text-dark bg-light px-2 py-1 rounded">
                                        <?= htmlspecialchars($r['return_number'] ?? ('PRT-' . str_pad($r['return_id'], 6, '0', STR_PAD_LEFT))) ?>
                                    </span>
                                </td>
                                <td><?= format_date($r['return_date']) ?></td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= htmlspecialchars($r['supplier_name'] ?? 'Supplier') ?></div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border font-monospace">
                                        <?= htmlspecialchars($r['invoice_number'] ?? 'N/A') ?>
                                    </span>
                                    <?php if (!empty($r['supplier_invoice_no'])): ?>
                                        <div class="text-muted small">Bill: <?= htmlspecialchars($r['supplier_invoice_no']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="fw-bold text-dark"><?= $r['total_units_returned'] ?></span> unit(s)
                                </td>
                                <td class="text-end fw-bold text-danger"><?= format_currency($r['refund_amount']) ?></td>
                                <td class="text-center">
                                    <span class="badge bg-success-subtle text-success rounded-pill px-3">
                                        <?= htmlspecialchars($r['status'] ?? 'POSTED') ?>
                                    </span>
                                </td>
                                <td class="text-end pe-4 text-muted small">
                                    <?= htmlspecialchars($r['created_by_username'] ?? 'System') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: New Purchase Return -->
<div class="modal fade" id="modalNewPurchaseReturn" tabindex="-1" aria-labelledby="modalNewPurchaseReturnLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-primary text-white border-0 py-3">
                <h5 class="modal-title fw-bold" id="modalNewPurchaseReturnLabel">
                    <i class="ti ti-truck-return me-2"></i>Draft Supplier Purchase Return & Debit Note
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <!-- Search Purchase Invoice -->
                <div class="card border-0 shadow-sm rounded-4 p-3 bg-white mb-4">
                    <h6 class="fw-bold text-dark mb-2">Step 1: Search Purchase Invoice</h6>
                    <div class="row g-2 align-items-center">
                        <div class="col-md-9">
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i class="ti ti-search"></i></span>
                                <input type="text" id="invSearchInput" class="form-control border-start-0" placeholder="Enter Internal Invoice (PINV-), Supplier Bill Number, or Supplier Name...">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <button type="button" id="btnSearchInv" class="btn btn-primary w-100 rounded-pill">
                                <i class="ti ti-search me-1"></i> Find Invoice
                            </button>
                        </div>
                    </div>
                    <div id="invSearchResults" class="list-group mt-3 d-none"></div>
                </div>

                <!-- Return Form -->
                <form method="POST" id="formProcessPurchaseReturn" class="d-none">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="process_purchase_return">
                    <input type="hidden" name="invoice_id" id="selectedInvoiceId" value="">
                    <input type="hidden" name="idempotency_key" value="PRT_POST_<?= uniqid('', true) ?>">

                    <div class="card border-0 shadow-sm rounded-4 p-3 bg-white mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <span class="badge bg-primary-subtle text-primary px-3 py-2 fs-6 rounded-pill" id="displayInvNo"></span>
                                <span class="fw-bold text-dark ms-2" id="displaySupplierName"></span>
                            </div>
                            <div class="text-end">
                                <span class="small text-muted">Outstanding Balance:</span>
                                <span class="fw-bold text-danger fs-6 ms-1" id="displayOutstanding"></span>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered align-middle mb-0" id="purchaseReturnItemsTable">
                                <thead class="bg-light text-muted small text-uppercase">
                                    <tr>
                                        <th>Medicine / Batch</th>
                                        <th class="text-center">Received Qty</th>
                                        <th class="text-center">Prev Ret</th>
                                        <th class="text-center">Physical Avail</th>
                                        <th class="text-center">Max Returnable</th>
                                        <th style="width: 120px;">Return Qty</th>
                                        <th style="width: 180px;">Defect / Reason</th>
                                        <th class="text-end">Rate</th>
                                        <th class="text-end">Line Total</th>
                                    </tr>
                                </thead>
                                <tbody id="purchaseReturnItemsTbody">
                                    <!-- Dynamic Items -->
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm rounded-4 p-3 bg-white mb-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Return Date</label>
                                <input type="date" name="return_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Total Debit / Credit Note Amount</label>
                                <div class="form-control-plaintext fs-4 fw-bold text-danger text-end" id="grandDebitDisplay">
                                    ₹0.00
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-bold">Return Justification / Memo *</label>
                                <textarea name="reason" class="form-control" rows="2" placeholder="State reason for supplier return (e.g. Broken ampoules, near expiry delivery, cold-chain violation)..." required></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success rounded-pill px-5">
                            <i class="ti ti-check me-1"></i> Post Purchase Return
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const invSearchInput = document.getElementById('invSearchInput');
    const btnSearchInv = document.getElementById('btnSearchInv');
    const invSearchResults = document.getElementById('invSearchResults');
    const formProcessPurchaseReturn = document.getElementById('formProcessPurchaseReturn');
    const purchaseReturnItemsTbody = document.getElementById('purchaseReturnItemsTbody');
    const grandDebitDisplay = document.getElementById('grandDebitDisplay');

    btnSearchInv.addEventListener('click', function() {
        const q = invSearchInput.value.trim();
        if (q.length < 2) {
            alert('Please enter at least 2 characters.');
            return;
        }

        const originalBtnHtml = btnSearchInv.innerHTML;
        btnSearchInv.disabled = true;
        btnSearchInv.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Searching...';

        fetch(`returns.php?ajax=lookup_invoice&q=${encodeURIComponent(q)}`)
            .then(res => {
                if (!res.ok) throw new Error('HTTP error ' + res.status);
                return res.json();
            })
            .then(data => {
                invSearchResults.innerHTML = '';
                if (data.success && data.invoices.length > 0) {
                    invSearchResults.classList.remove('d-none');
                    data.invoices.forEach(inv => {
                        const item = document.createElement('a');
                        item.href = 'javascript:void(0)';
                        item.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center py-2';
                        item.innerHTML = `
                            <div>
                                <span class="fw-bold text-dark">${escapeHtml(inv.invoice_number)}</span> 
                                <span class="text-muted ms-2">(Bill: ${escapeHtml(inv.supplier_invoice_no || 'N/A')}) - ${escapeHtml(inv.supplier_name)}</span>
                                <div class="text-muted small">Date: ${escapeHtml(inv.invoice_date)}</div>
                            </div>
                            <div class="text-end">
                                <div class="fw-bold text-dark">Total: ₹${parseFloat(inv.grand_total).toFixed(2)}</div>
                                <span class="badge bg-danger-subtle text-danger small">Outstanding: ₹${parseFloat(inv.outstanding_amount).toFixed(2)}</span>
                            </div>
                        `;
                        item.addEventListener('click', function() {
                            loadInvoiceForReturn(inv.invoice_id);
                        });
                        invSearchResults.appendChild(item);
                    });
                } else {
                    invSearchResults.classList.remove('d-none');
                    invSearchResults.innerHTML = '<div class="p-3 text-muted text-center">No matching invoices found.</div>';
                }
            })
            .catch(err => {
                console.error('Invoice lookup failed:', err);
                alert('Network or server error while searching invoices: ' + err.message);
            })
            .finally(() => {
                btnSearchInv.disabled = false;
                btnSearchInv.innerHTML = originalBtnHtml;
            });
    });

    function escapeHtml(text) {
        if (!text) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }

    function loadInvoiceForReturn(invId) {
        fetch(`returns.php?ajax=get_invoice_details&invoice_id=${invId}`)
            .then(res => {
                if (!res.ok) throw new Error('HTTP error ' + res.status);
                return res.json();
            })
            .then(data => {
                if (!data.success) {
                    alert(data.message || 'Error loading invoice details');
                    return;
                }

                const inv = data.data;
                document.getElementById('selectedInvoiceId').value = inv.invoice_id;
                document.getElementById('displayInvNo').textContent = inv.invoice_number;
                document.getElementById('displaySupplierName').textContent = `${inv.supplier_name} (GSTIN: ${inv.gstin || 'N/A'})`;
                document.getElementById('displayOutstanding').textContent = `₹${parseFloat(inv.outstanding_amount).toFixed(2)}`;

                purchaseReturnItemsTbody.innerHTML = '';
                invSearchResults.classList.add('d-none');
                formProcessPurchaseReturn.classList.remove('d-none');

                inv.items.forEach((item, idx) => {
                    const tr = document.createElement('tr');
                    const rate = parseFloat(item.purchase_rate || 0);

                    tr.innerHTML = `
                        <td>
                            <input type="hidden" name="items[${idx}][invoice_item_id]" value="${escapeHtml(item.invoice_item_id)}">
                            <input type="hidden" class="pr-item-rate" value="${rate}">
                            <div class="fw-bold text-dark">${escapeHtml(item.medicine_name)}</div>
                            <span class="font-monospace small text-muted bg-light px-2 py-0.5 rounded">Batch: ${escapeHtml(item.batch_number || 'N/A')} (Exp: ${escapeHtml(item.expiry_date || 'N/A')})</span>
                        </td>
                        <td class="text-center fw-bold">${item.quantity}</td>
                        <td class="text-center text-muted">${item.previously_returned_qty}</td>
                        <td class="text-center fw-bold">${item.quantity_available || 0}</td>
                        <td class="text-center text-primary fw-bold">${item.returnable_quantity}</td>
                        <td>
                            <input type="number" name="items[${idx}][return_quantity]" class="form-control form-control-sm pr-return-qty" min="0" max="${item.returnable_quantity}" value="0" ${item.returnable_quantity === 0 ? 'disabled' : ''}>
                        </td>
                        <td>
                            <select name="items[${idx}][condition_status]" class="form-select form-select-sm">
                                <option value="DEFECTIVE">Damaged / Broken</option>
                                <option value="NEAR_EXPIRY">Near Expiry / Short Shelf</option>
                                <option value="COLD_CHAIN_VIOLATION">Cold Chain Breach</option>
                                <option value="SUPPLIER_RECALL">Manufacturer Recall</option>
                                <option value="EXCESS_DELIVERY">Wrong / Excess Supply</option>
                            </select>
                        </td>
                        <td class="text-end font-monospace">₹${rate.toFixed(2)}</td>
                        <td class="text-end fw-bold text-dark pr-line-display">₹0.00</td>
                    `;
                    purchaseReturnItemsTbody.appendChild(tr);
                });

                bindCalculations();
            })
            .catch(err => {
                console.error('Invoice details retrieval failed:', err);
                alert('Network or server error while retrieving invoice details: ' + err.message);
            });
    }

    function bindCalculations() {
        const qtyInputs = document.querySelectorAll('.pr-return-qty');
        qtyInputs.forEach(input => {
            input.addEventListener('input', calculateTotalDebit);
        });
    }

    function calculateTotalDebit() {
        let total = 0;
        const rows = purchaseReturnItemsTbody.querySelectorAll('tr');
        rows.forEach(tr => {
            const qtyInput = tr.querySelector('.pr-return-qty');
            const rateInput = tr.querySelector('.pr-item-rate');
            const lineDisplay = tr.querySelector('.pr-line-display');
            if (qtyInput && rateInput) {
                const qty = parseInt(qtyInput.value) || 0;
                const rate = parseFloat(rateInput.value) || 0;
                const lineTotal = qty * rate;
                lineDisplay.textContent = `₹${lineTotal.toFixed(2)}`;
                total += lineTotal;
            }
        });
        grandDebitDisplay.textContent = `₹${total.toFixed(2)}`;
    }
});
</script>

<?php
include __DIR__ . '/../../includes/footer.php';
?>