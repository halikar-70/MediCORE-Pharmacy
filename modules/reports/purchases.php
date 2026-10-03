<?php
// modules/reports/purchases.php - Authoritative Procurement & Supplier Performance Analytics (Chunk 7)

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/AnalyticsService.php';
require_once __DIR__ . '/../../app/Services/ExportService.php';

use Pharmacy\Services\AnalyticsService;
use Pharmacy\Services\ExportService;

require_permission('pharmacy.reports.view');

$page_title = 'Procurement & Supplier Analytics';
$analyticsService = new AnalyticsService($pdo);

$tab = $_GET['tab'] ?? 'invoices';
$preset = $_GET['range'] ?? 'this_month';
$customStart = $_GET['start_date'] ?? null;
$customEnd = $_GET['end_date'] ?? null;

$rangeBounds = AnalyticsService::getDateRangeBounds($preset, $customStart, $customEnd);
$startDate = $rangeBounds['start_date'];
$endDate = $rangeBounds['end_date'];

$supplierId = !empty($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : null;

// Export Handling
if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
    require_permission('pharmacy.reports.export');

    if ($tab === 'invoices') {
        $where = ["pi.invoice_date BETWEEN ? AND ?", "pi.payment_status != 'CANCELLED'"];
        $params = [$startDate, $endDate];
        if ($supplierId) {
            $where[] = "pi.supplier_id = ?";
            $params[] = $supplierId;
        }
        $sql = "
            SELECT pi.invoice_number, pi.supplier_invoice_no, s.supplier_name, pi.invoice_date,
                   pi.taxable_amount, pi.discount_amount, pi.gst_amount, pi.grand_total,
                   pi.amount_paid, pi.outstanding_amount, pi.payment_status
            FROM pharmacy_purchase_invoices pi
            JOIN pharmacy_suppliers s ON pi.supplier_id = s.supplier_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY pi.invoice_date DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $headers = ['Internal Inv No', 'Supplier Bill No', 'Supplier Name', 'Invoice Date', 'Gross/Taxable (₹)', 'Discount (₹)', 'Tax (₹)', 'Grand Total (₹)', 'Paid (₹)', 'Outstanding (₹)', 'Status'];
        $rows = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = [
                $r['invoice_number'],
                $r['supplier_invoice_no'],
                $r['supplier_name'],
                $r['invoice_date'],
                number_format((float)$r['taxable_amount'], 2, '.', ''),
                number_format((float)$r['discount_amount'], 2, '.', ''),
                number_format((float)$r['gst_amount'], 2, '.', ''),
                number_format((float)$r['grand_total'], 2, '.', ''),
                number_format((float)$r['amount_paid'], 2, '.', ''),
                number_format((float)$r['outstanding_amount'], 2, '.', ''),
                $r['payment_status']
            ];
        }
        ExportService::streamCsvDownload("purchase_invoices_{$startDate}_{$endDate}", $headers, $rows);
    } elseif ($tab === 'performance') {
        $perf = $analyticsService->getSupplierPerformance();
        $headers = ['Supplier Name', 'Phone', 'Total POs', 'Total GRNs', 'Purchase Value (₹)', 'Paid (₹)', 'Outstanding (₹)', 'Returns (₹)', 'Avg Delivery Turnaround'];
        $rows = [];
        foreach ($perf as $p) {
            $rows[] = [
                $p['supplier_name'],
                $p['phone'] ?? '',
                $p['total_pos'],
                $p['total_grns'],
                number_format((float)$p['purchase_value'], 2, '.', ''),
                number_format((float)$p['paid'], 2, '.', ''),
                number_format((float)$p['outstanding'], 2, '.', ''),
                number_format((float)$p['returns'], 2, '.', ''),
                $p['avg_delivery_time']
            ];
        }
        ExportService::streamCsvDownload("supplier_performance_" . date('Ymd'), $headers, $rows);
    }
}

$suppliers = $pdo->query("SELECT supplier_id, supplier_name FROM pharmacy_suppliers ORDER BY supplier_name ASC")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="container-fluid px-4 py-3">
    <!-- Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div>
            <h4 class="fw-bold mb-1 text-dark">
                <i class="ti ti-truck-delivery text-emerald me-2"></i>Procurement &amp; Supplier Analytics
            </h4>
            <p class="text-muted small mb-0">Authoritative registers for purchase bills, supplier accounts, and vendor delivery performance.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="?tab=<?= urlencode($tab) ?>&range=<?= urlencode($preset) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&supplier_id=<?= (int)$supplierId ?>&export=csv" class="btn btn-sm btn-outline-success">
                <i class="ti ti-file-spreadsheet me-1"></i> Export CSV
            </a>
            <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-secondary">
                <i class="ti ti-printer me-1"></i> Print
            </button>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'invoices' ? 'active fw-bold text-emerald border-bottom border-emerald border-2' : 'text-muted' ?>" href="?tab=invoices&range=<?= urlencode($preset) ?>">
                <i class="ti ti-file-invoice me-1"></i>Purchase Bills Register
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'performance' ? 'active fw-bold text-emerald border-bottom border-emerald border-2' : 'text-muted' ?>" href="?tab=performance">
                <i class="ti ti-chart-arrows me-1"></i>Supplier Performance &amp; Turnaround
            </a>
        </li>
    </ul>

    <!-- TAB 1: Invoices Register -->
    <?php if ($tab === 'invoices'): ?>
        <!-- Filter Bar -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-3">
                <form method="GET" class="row g-2 align-items-center">
                    <input type="hidden" name="tab" value="invoices">
                    <div class="col-md-3">
                        <label class="small text-muted mb-1">Period Preset</label>
                        <select name="range" class="form-select form-select-sm" onchange="this.value === 'custom' ? document.getElementById('customDates').classList.remove('d-none') : this.form.submit()">
                            <option value="today" <?= $preset === 'today' ? 'selected' : '' ?>>Today</option>
                            <option value="yesterday" <?= $preset === 'yesterday' ? 'selected' : '' ?>>Yesterday</option>
                            <option value="this_week" <?= $preset === 'this_week' ? 'selected' : '' ?>>This Week</option>
                            <option value="this_month" <?= $preset === 'this_month' ? 'selected' : '' ?>>This Month</option>
                            <option value="prev_month" <?= $preset === 'prev_month' ? 'selected' : '' ?>>Previous Month</option>
                            <option value="this_quarter" <?= $preset === 'this_quarter' ? 'selected' : '' ?>>This Quarter</option>
                            <option value="this_year" <?= $preset === 'this_year' ? 'selected' : '' ?>>This Year</option>
                            <option value="custom" <?= $preset === 'custom' ? 'selected' : '' ?>>Custom Range...</option>
                        </select>
                    </div>

                    <div id="customDates" class="col-md-4 <?= $preset === 'custom' ? '' : 'd-none' ?>">
                        <label class="small text-muted mb-1">From - To</label>
                        <div class="d-flex gap-1 align-items-center">
                            <input type="date" name="start_date" class="form-control form-control-sm" value="<?= htmlspecialchars($startDate) ?>">
                            <span>-</span>
                            <input type="date" name="end_date" class="form-control form-control-sm" value="<?= htmlspecialchars($endDate) ?>">
                        </div>
                    </div>

                    <div class="col-md-3">
                        <label class="small text-muted mb-1">Supplier</label>
                        <select name="supplier_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Suppliers</option>
                            <?php foreach ($suppliers as $s): ?>
                                <option value="<?= $s['supplier_id'] ?>" <?= $supplierId === (int)$s['supplier_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['supplier_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-sm btn-emerald w-100"><i class="ti ti-filter me-1"></i>Filter</button>
                    </div>
                </form>
            </div>
        </div>

        <?php
        $where = ["pi.invoice_date BETWEEN ? AND ?", "pi.payment_status != 'CANCELLED'"];
        $params = [$startDate, $endDate];
        if ($supplierId) {
            $where[] = "pi.supplier_id = ?";
            $params[] = $supplierId;
        }
        $sql = "
            SELECT pi.invoice_id, pi.invoice_number, pi.supplier_invoice_no, s.supplier_name, pi.invoice_date,
                   pi.taxable_amount, pi.discount_amount, pi.gst_amount, pi.grand_total,
                   pi.amount_paid, pi.outstanding_amount, pi.payment_status, g.grn_number
            FROM pharmacy_purchase_invoices pi
            JOIN pharmacy_suppliers s ON pi.supplier_id = s.supplier_id
            LEFT JOIN pharmacy_grn g ON pi.grn_id = g.grn_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY pi.invoice_date DESC
            LIMIT 100
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>

        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-uppercase text-muted">
                            <tr>
                                <th>Invoice No</th>
                                <th>Supplier Bill No</th>
                                <th>Supplier</th>
                                <th>Date</th>
                                <th class="text-end">Taxable (₹)</th>
                                <th class="text-end">GST (₹)</th>
                                <th class="text-end">Total (₹)</th>
                                <th class="text-end">Paid (₹)</th>
                                <th class="text-end">Due (₹)</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($invoices)): ?>
                                <tr><td colspan="10" class="text-center py-4 text-muted">No purchase invoices found for this criteria.</td></tr>
                            <?php else: ?>
                                <?php foreach ($invoices as $inv): ?>
                                    <tr>
                                        <td class="fw-bold font-monospace"><?= htmlspecialchars($inv['invoice_number']) ?></td>
                                        <td class="font-monospace text-muted"><?= htmlspecialchars($inv['supplier_invoice_no']) ?></td>
                                        <td class="fw-semibold text-dark"><?= htmlspecialchars($inv['supplier_name']) ?></td>
                                        <td><?= htmlspecialchars($inv['invoice_date']) ?></td>
                                        <td class="text-end">₹<?= number_format((float)$inv['taxable_amount'], 2) ?></td>
                                        <td class="text-end">₹<?= number_format((float)$inv['gst_amount'], 2) ?></td>
                                        <td class="text-end fw-bold text-dark">₹<?= number_format((float)$inv['grand_total'], 2) ?></td>
                                        <td class="text-end text-success fw-semibold">₹<?= number_format((float)$inv['amount_paid'], 2) ?></td>
                                        <td class="text-end text-danger fw-semibold">₹<?= number_format((float)$inv['outstanding_amount'], 2) ?></td>
                                        <td>
                                            <span class="badge bg-<?= $inv['payment_status'] === 'PAID' ? 'success' : ($inv['payment_status'] === 'PARTIALLY_PAID' ? 'warning' : 'danger') ?>-subtle text-<?= $inv['payment_status'] === 'PAID' ? 'success' : ($inv['payment_status'] === 'PARTIALLY_PAID' ? 'warning' : 'danger') ?>">
                                                <?= htmlspecialchars($inv['payment_status']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <!-- TAB 2: Supplier Performance -->
    <?php elseif ($tab === 'performance'): ?>
        <?php $perf = $analyticsService->getSupplierPerformance(); ?>
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-bottom py-3 px-4">
                <span class="badge bg-info-subtle text-info me-2 font-monospace">METRIC INTEGRITY</span>
                <span class="small text-muted">Delivery turnaround time is strictly measured between PO order date and GRN receipt date. No delivery metrics are fabricated.</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-uppercase text-muted">
                            <tr>
                                <th>Supplier Name</th>
                                <th>Phone</th>
                                <th class="text-center">Orders</th>
                                <th class="text-center">GRNs</th>
                                <th class="text-end">Purchase Value (₹)</th>
                                <th class="text-end">Paid (₹)</th>
                                <th class="text-end">Outstanding (₹)</th>
                                <th class="text-end">Returns (₹)</th>
                                <th class="text-center">Avg Delivery Turnaround</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($perf)): ?>
                                <tr><td colspan="9" class="text-center py-4 text-muted">No supplier records available.</td></tr>
                            <?php else: ?>
                                <?php foreach ($perf as $p): ?>
                                    <tr>
                                        <td class="fw-bold text-dark"><?= htmlspecialchars($p['supplier_name']) ?></td>
                                        <td class="text-muted small"><?= htmlspecialchars($p['phone'] ?? 'N/A') ?></td>
                                        <td class="text-center fw-semibold"><?= (int)$p['total_pos'] ?></td>
                                        <td class="text-center fw-semibold"><?= (int)$p['total_grns'] ?></td>
                                        <td class="text-end fw-bold">₹<?= number_format((float)$p['purchase_value'], 2) ?></td>
                                        <td class="text-end text-success">₹<?= number_format((float)$p['paid'], 2) ?></td>
                                        <td class="text-end text-danger fw-semibold">₹<?= number_format((float)$p['outstanding'], 2) ?></td>
                                        <td class="text-end text-warning">₹<?= number_format((float)$p['returns'], 2) ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-light text-dark border font-monospace">
                                                <?= htmlspecialchars($p['avg_delivery_time']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
include __DIR__ . '/../../includes/footer.php';
?>