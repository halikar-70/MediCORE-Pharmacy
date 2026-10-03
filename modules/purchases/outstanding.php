<?php
// modules/purchases/outstanding.php - Supplier Outstanding & Aging Dashboard

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/SupplierPaymentService.php';

require_permission('pharmacy.supplier_outstanding.view');

use Pharmacy\Services\SupplierPaymentService;

$payService = new SupplierPaymentService($pdo);
$page_title = 'Supplier Outstanding & Aging';

$supplierId = !empty($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : null;
$agingItems = $payService->getAgingReport($supplierId);

// Summary metrics
$totalOutstanding = 0.00;
$totalOverdue = 0.00;
$overdueCount = 0;
$agingBuckets = [
    'Current'   => 0.00,
    '1-30 Days' => 0.00,
    '31-60 Days'=> 0.00,
    '61-90 Days'=> 0.00,
    '90+ Days'  => 0.00
];

foreach ($agingItems as $row) {
    $bal = (float)$row['outstanding_amount'];
    $totalOutstanding += $bal;
    $bucket = $row['aging_bucket'];
    if (isset($agingBuckets[$bucket])) {
        $agingBuckets[$bucket] += $bal;
    }
    if ((int)$row['days_overdue'] > 0) {
        $totalOverdue += $bal;
        $overdueCount++;
    }
}

$suppliersList = $pdo->query("SELECT supplier_id, supplier_name, supplier_code FROM pharmacy_suppliers WHERE status != 'Inactive' ORDER BY supplier_name ASC")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="container-fluid px-4 py-3">
    <!-- Header bar -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h4 class="fw-bold text-dark mb-1">
                <i class="ti ti-building-bank text-emerald me-2"></i>Supplier Outstanding & Aging Analysis
            </h4>
            <p class="text-muted small mb-0">Creditor balances, due date monitoring, and aging distribution (0-30, 31-60, 61-90, 90+ days).</p>
        </div>
        <div class="d-flex gap-2">
            <a href="ledger.php" class="btn btn-outline-dark rounded-pill px-3 py-2 small">
                <i class="ti ti-book-2 me-1"></i> Supplier Ledger
            </a>
            <a href="payments.php" class="btn btn-emerald rounded-pill px-3 py-2 text-white small" style="background-color: #059669;">
                <i class="ti ti-cash me-1"></i> Record Payment
            </a>
        </div>
    </div>

    <!-- Metric Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm p-3 rounded-4 bg-white">
                <div class="d-flex align-items-center">
                    <div class="rounded-3 p-3 text-primary bg-primary-subtle me-3">
                        <i class="ti ti-wallet fs-3"></i>
                    </div>
                    <div>
                        <div class="text-muted small text-uppercase fw-semibold">Total Outstanding</div>
                        <h4 class="fw-bold mb-0 text-dark">₹<?= number_format($totalOutstanding, 2) ?></h4>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm p-3 rounded-4 bg-white">
                <div class="d-flex align-items-center">
                    <div class="rounded-3 p-3 text-danger bg-danger-subtle me-3">
                        <i class="ti ti-alert-triangle fs-3"></i>
                    </div>
                    <div>
                        <div class="text-muted small text-uppercase fw-semibold">Total Overdue</div>
                        <h4 class="fw-bold mb-0 text-danger">₹<?= number_format($totalOverdue, 2) ?></h4>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm p-3 rounded-4 bg-white">
                <div class="d-flex align-items-center">
                    <div class="rounded-3 p-3 text-warning bg-warning-subtle me-3">
                        <i class="ti ti-calendar-due fs-3"></i>
                    </div>
                    <div>
                        <div class="text-muted small text-uppercase fw-semibold">Overdue Invoices</div>
                        <h4 class="fw-bold mb-0 text-dark"><?= $overdueCount ?> Bills</h4>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm p-3 rounded-4 bg-white">
                <div class="d-flex align-items-center">
                    <div class="rounded-3 p-3 text-success bg-success-subtle me-3">
                        <i class="ti ti-check fs-3"></i>
                    </div>
                    <div>
                        <div class="text-muted small text-uppercase fw-semibold">Current (Not Due)</div>
                        <h4 class="fw-bold mb-0 text-success">₹<?= number_format($agingBuckets['Current'], 2) ?></h4>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Aging Breakdown Strip -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white p-3">
        <div class="fw-bold small text-muted text-uppercase mb-2">Accounts Payable Aging Schedule</div>
        <div class="row g-2 text-center small">
            <div class="col">
                <div class="p-2 border rounded-3 bg-light">
                    <div class="text-muted">Current / Not Due</div>
                    <div class="fw-bold text-success">₹<?= number_format($agingBuckets['Current'], 2) ?></div>
                </div>
            </div>
            <div class="col">
                <div class="p-2 border rounded-3 bg-light">
                    <div class="text-muted">1 - 30 Days Overdue</div>
                    <div class="fw-bold text-dark">₹<?= number_format($agingBuckets['1-30 Days'], 2) ?></div>
                </div>
            </div>
            <div class="col">
                <div class="p-2 border rounded-3 bg-light">
                    <div class="text-muted">31 - 60 Days Overdue</div>
                    <div class="fw-bold text-warning">₹<?= number_format($agingBuckets['31-60 Days'], 2) ?></div>
                </div>
            </div>
            <div class="col">
                <div class="p-2 border rounded-3 bg-light">
                    <div class="text-muted">61 - 90 Days Overdue</div>
                    <div class="fw-bold text-danger">₹<?= number_format($agingBuckets['61-90 Days'], 2) ?></div>
                </div>
            </div>
            <div class="col">
                <div class="p-2 border rounded-3 bg-light">
                    <div class="text-muted">> 90 Days Overdue</div>
                    <div class="fw-bold text-danger fs-6">₹<?= number_format($agingBuckets['90+ Days'], 2) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white">
        <div class="card-body p-3">
            <form method="GET" action="outstanding.php" class="row g-2 align-items-center">
                <div class="col-md-8">
                    <select name="supplier_id" class="form-select bg-light border-0">
                        <option value="">All Creditor Suppliers</option>
                        <?php foreach ($suppliersList as $sup): ?>
                            <option value="<?= $sup['supplier_id'] ?>" <?= $supplierId === (int)$sup['supplier_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sup['supplier_name']) ?> (<?= htmlspecialchars($sup['supplier_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-dark w-100 rounded-3">Filter</button>
                </div>
                <div class="col-md-2">
                    <a href="outstanding.php" class="btn btn-outline-secondary w-100 rounded-3">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Aging Table -->
    <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr class="text-uppercase small text-muted">
                        <th class="ps-4">Supplier</th>
                        <th>Internal Invoice #</th>
                        <th>Vendor Bill #</th>
                        <th>Bill Date</th>
                        <th>Due Date</th>
                        <th class="text-end">Bill Total</th>
                        <th class="text-end">Paid Amount</th>
                        <th class="text-end">Outstanding</th>
                        <th>Aging / Overdue</th>
                        <th class="text-end pe-4">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($agingItems)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-5 text-muted">
                                <i class="ti ti-mood-check fs-1 d-block mb-2 text-success"></i>
                                All clear! No outstanding supplier bills found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($agingItems as $it): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($it['supplier_name']) ?></div>
                                    <span class="badge bg-light text-secondary font-monospace" style="font-size: 0.7rem;"><?= htmlspecialchars($it['supplier_code']) ?></span>
                                </td>
                                <td class="font-monospace fw-semibold text-dark"><?= htmlspecialchars($it['invoice_number']) ?></td>
                                <td class="font-monospace text-dark"><?= htmlspecialchars($it['supplier_invoice_no']) ?></td>
                                <td class="small"><?= date('d-M-Y', strtotime($it['invoice_date'])) ?></td>
                                <td class="small fw-semibold <?= (int)$it['days_overdue'] > 0 ? 'text-danger' : 'text-dark' ?>">
                                    <?= date('d-M-Y', strtotime($it['due_date'])) ?>
                                </td>
                                <td class="text-end">₹<?= number_format((float)$it['grand_total'], 2) ?></td>
                                <td class="text-end text-success">₹<?= number_format((float)$it['amount_paid'], 2) ?></td>
                                <td class="text-end fw-bold text-danger">₹<?= number_format((float)$it['outstanding_amount'], 2) ?></td>
                                <td>
                                    <?php if ((int)$it['days_overdue'] > 0): ?>
                                        <span class="badge bg-danger-subtle text-danger rounded-pill px-2 py-1">
                                            <?= (int)$it['days_overdue'] ?> Days Overdue (<?= $it['aging_bucket'] ?>)
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-success-subtle text-success rounded-pill px-2 py-1">
                                            Current (Due in <?= abs((int)$it['days_overdue']) ?> days)
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <a href="payments.php?supplier_id=<?= $it['supplier_id'] ?>&invoice_id=<?= $it['invoice_id'] ?>" class="btn btn-sm btn-emerald text-white rounded-pill px-3" style="background-color: #059669;">
                                        <i class="ti ti-cash me-1"></i> Pay
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

<?php
include __DIR__ . '/../../includes/footer.php';
?>
