<?php
// modules/purchases/payments.php - Supplier Payments & Invoice Allocation

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

$page_title = 'Supplier Payments';
$user = auth_user();
$userId = (int)($user['id'] ?? 1);

$canCreate = has_permission('pharmacy.supplier_payments.create');

$feedback = null;
$error = null;

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
                $feedback = "Supplier payment recorded and allocated successfully (Payment ID #{$payId}).";
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Filters
$supplierId = !empty($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : null;
$search = trim($_GET['search'] ?? '');

$sql = "
    SELECT p.*, s.supplier_name, s.supplier_code, u.full_name as creator_name,
           (SELECT COUNT(*) FROM pharmacy_supplier_payment_allocations WHERE payment_id = p.payment_id) as allocated_count
    FROM pharmacy_supplier_payments p
    JOIN pharmacy_suppliers s ON p.supplier_id = s.supplier_id
    LEFT JOIN pharmacy_users u ON p.created_by = u.id
    WHERE 1=1
";
$params = [];
if ($supplierId) {
    $sql .= " AND p.supplier_id = ?";
    $params[] = $supplierId;
}
if ($search !== '') {
    $term = '%' . $search . '%';
    $sql .= " AND (p.payment_number LIKE ? OR s.supplier_name LIKE ? OR p.reference_no LIKE ?)";
    $params = array_merge($params, [$term, $term, $term]);
}
$sql .= " ORDER BY p.payment_id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$activeSuppliers = $pdo->query("SELECT supplier_id, supplier_name, supplier_code FROM pharmacy_suppliers WHERE status = 'Active' ORDER BY supplier_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// If supplier_id / invoice_id preset in GET
$presetSupplierId = (int)($_GET['supplier_id'] ?? 0);
$presetInvoiceId = (int)($_GET['invoice_id'] ?? 0);
$unpaidInvoices = [];
if ($presetSupplierId > 0) {
    $uStmt = $pdo->prepare("SELECT invoice_id, invoice_number, supplier_invoice_no, invoice_date, due_date, grand_total, amount_paid, outstanding_amount FROM pharmacy_purchase_invoices WHERE supplier_id = ? AND outstanding_amount > 0 AND payment_status != 'PAID' ORDER BY due_date ASC");
    $uStmt->execute([$presetSupplierId]);
    $unpaidInvoices = $uStmt->fetchAll(PDO::FETCH_ASSOC);
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
                <i class="ti ti-cash text-emerald me-2"></i>Supplier Payments
            </h4>
            <p class="text-muted small mb-0">Disbursement vouchers, payment allocations, and accounts payable settlement.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="outstanding.php" class="btn btn-outline-danger rounded-pill px-3 py-2 small">
                <i class="ti ti-building-bank me-1"></i> Supplier Outstanding
            </a>
            <a href="ledger.php" class="btn btn-outline-secondary rounded-pill px-3 py-2 small">
                <i class="ti ti-book-2 me-1"></i> Supplier Ledger
            </a>
            <?php if ($canCreate): ?>
                <button type="button" class="btn btn-emerald rounded-pill px-3 py-2 text-white small" style="background-color: #059669;" data-bs-toggle="modal" data-bs-target="#createPaymentModal">
                    <i class="ti ti-plus me-1"></i> Record Payment
                </button>
            <?php endif; ?>
        </div>
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

    <!-- Filter Card -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white">
        <div class="card-body p-3">
            <form method="GET" action="payments.php" class="row g-2 align-items-center">
                <div class="col-md-5">
                    <input type="text" name="search" class="form-control bg-light border-0" placeholder="Search payment #, UTR / ref, or vendor..." value="<?= htmlspecialchars($search) ?>">
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
                    <a href="payments.php" class="btn btn-outline-secondary rounded-3">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Payments List Table -->
    <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden">
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
                    <?php if (empty($payments)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="ti ti-cash-off fs-1 d-block mb-2 text-secondary"></i>
                                No supplier payment vouchers recorded yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($payments as $p): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold font-monospace text-dark"><?= htmlspecialchars($p['payment_number']) ?></div>
                                    <div class="text-muted small"><?= date('d-M-Y', strtotime($p['payment_date'])) ?></div>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= htmlspecialchars($p['supplier_name']) ?></div>
                                    <span class="badge bg-light text-secondary font-monospace" style="font-size: 0.7rem;"><?= htmlspecialchars($p['supplier_code']) ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-light border text-dark"><?= htmlspecialchars($p['payment_mode']) ?></span>
                                </td>
                                <td>
                                    <span class="font-monospace text-dark small"><?= htmlspecialchars($p['reference_no'] ?? '—') ?></span>
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
</div>

<!-- Record Payment Modal -->
<?php if ($canCreate): ?>
<div class="modal fade <?= ($presetSupplierId > 0) ? 'show' : '' ?>" id="createPaymentModal" tabindex="-1" style="<?= ($presetSupplierId > 0) ? 'display:block; background:rgba(0,0,0,0.5);' : '' ?>">
    <div class="modal-dialog modal-lg">
        <div class="modal-content rounded-4 border-0">
            <form method="POST" action="payments.php" id="paymentForm">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="create_payment">

                <div class="modal-header border-0 px-4 pt-4">
                    <h5 class="fw-bold"><i class="ti ti-cash text-emerald me-2"></i>Record Supplier Disbursement</h5>
                    <?php if ($presetSupplierId > 0): ?>
                        <a href="payments.php" class="btn-close"></a>
                    <?php else: ?>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    <?php endif; ?>
                </div>
                <div class="modal-body px-4">
                    <div class="row g-3 mb-4 p-3 bg-light rounded-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Select Supplier *</label>
                            <select name="supplier_id" id="paySupplierSelect" class="form-select" required onchange="window.location.href='payments.php?supplier_id='+this.value">
                                <option value="">-- Choose Vendor --</option>
                                <?php foreach ($activeSuppliers as $sup): ?>
                                    <option value="<?= $sup['supplier_id'] ?>" <?= $presetSupplierId === (int)$sup['supplier_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($sup['supplier_name']) ?> (<?= htmlspecialchars($sup['supplier_code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Payment Date *</label>
                            <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Total Amount (₹) *</label>
                            <input type="number" step="0.01" name="amount" id="totalPaymentAmount" class="form-control fw-bold text-success" required placeholder="0.00" oninput="validateAllocationSum()">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Payment Mode</label>
                            <select name="payment_mode" class="form-select">
                                <option value="Bank Transfer">Bank Transfer (NEFT/RTGS/IMPS)</option>
                                <option value="UPI">UPI / QR</option>
                                <option value="Cheque">Cheque</option>
                                <option value="Cash">Cash</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">UTR / Cheque / Ref Number</label>
                            <input type="text" name="reference_no" class="form-control font-monospace" placeholder="e.g. UTR-982138219">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Drawee Bank Name</label>
                            <input type="text" name="bank_name" class="form-control" placeholder="e.g. State Bank of India">
                        </div>
                    </div>

                    <!-- Invoices to allocate against -->
                    <h6 class="fw-bold text-dark mb-2">Allocate Payment to Outstanding Invoices</h6>
                    <?php if (empty($unpaidInvoices)): ?>
                        <div class="alert alert-info small border-0 rounded-3 mb-3">
                            <i class="ti ti-info-circle me-1"></i> Please select a supplier with outstanding invoices to allocate payment.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive mb-3">
                            <table class="table table-bordered table-sm align-middle">
                                <thead class="table-light small">
                                    <tr>
                                        <th>Invoice #</th>
                                        <th>Vendor Bill #</th>
                                        <th>Due Date</th>
                                        <th class="text-end">Total (₹)</th>
                                        <th class="text-end">Balance Due (₹)</th>
                                        <th style="width: 25%;" class="text-end">Allocate Amount (₹)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($unpaidInvoices as $uInv): ?>
                                        <tr>
                                            <td class="font-monospace fw-semibold"><?= htmlspecialchars($uInv['invoice_number']) ?></td>
                                            <td class="font-monospace"><?= htmlspecialchars($uInv['supplier_invoice_no']) ?></td>
                                            <td class="small"><?= date('d-M-Y', strtotime($uInv['due_date'])) ?></td>
                                            <td class="text-end">₹<?= number_format((float)$uInv['grand_total'], 2) ?></td>
                                            <td class="text-end fw-bold text-danger max-due" data-due="<?= (float)$uInv['outstanding_amount'] ?>">
                                                ₹<?= number_format((float)$uInv['outstanding_amount'], 2) ?>
                                            </td>
                                            <td>
                                                <input type="hidden" name="alloc_invoice_id[]" value="<?= $uInv['invoice_id'] ?>">
                                                <input type="number" step="0.01" name="alloc_amount[]" class="form-control form-control-sm text-end alloc-input" max="<?= (float)$uInv['outstanding_amount'] ?>" min="0" value="<?= ($presetInvoiceId === (int)$uInv['invoice_id']) ? (float)$uInv['outstanding_amount'] : '0.00' ?>" oninput="validateAllocationSum()">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <th colspan="5" class="text-end">Total Allocated:</th>
                                        <th class="text-end fs-6" id="totalAllocatedDisplay">₹0.00</th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Disbursement Remarks</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Payment approval note or details..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <?php if ($presetSupplierId > 0): ?>
                        <a href="payments.php" class="btn btn-light rounded-pill px-4">Cancel</a>
                    <?php else: ?>
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <?php endif; ?>
                    <button type="submit" id="savePaymentBtn" class="btn btn-emerald rounded-pill px-4 text-white" style="background-color: #059669;">Confirm Payment Voucher</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function validateAllocationSum() {
    const totalPay = parseFloat(document.getElementById('totalPaymentAmount').value) || 0;
    let totalAlloc = 0;
    document.querySelectorAll('.alloc-input').forEach(input => {
        const val = parseFloat(input.value) || 0;
        const max = parseFloat(input.getAttribute('max')) || 0;
        if (val > max) {
            input.value = max.toFixed(2);
        }
        totalAlloc += parseFloat(input.value) || 0;
    });

    const disp = document.getElementById('totalAllocatedDisplay');
    if (disp) {
        disp.textContent = '₹' + totalAlloc.toFixed(2);
        if (totalAlloc > totalPay && totalPay > 0) {
            disp.className = 'text-end fs-6 text-danger fw-bold';
        } else {
            disp.className = 'text-end fs-6 text-success fw-bold';
        }
    }
}
document.addEventListener('DOMContentLoaded', () => {
    validateAllocationSum();
});
</script>
<?php endif; ?>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
