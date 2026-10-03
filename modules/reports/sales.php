<?php
// modules/reports/sales.php - Authoritative Multi-Channel Sales Analytics & Register (Chunk 7)

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/AnalyticsService.php';
require_once __DIR__ . '/../../app/Services/ExportService.php';

use Pharmacy\Services\AnalyticsService;
use Pharmacy\Services\ExportService;

require_permission('pharmacy.reports.view');

$page_title = 'Sales & Revenue Analytics';
$analyticsService = new AnalyticsService($pdo);

// Filters
$tab = $_GET['tab'] ?? 'summary';
$preset = $_GET['range'] ?? 'this_month';
$customStart = $_GET['start_date'] ?? null;
$customEnd = $_GET['end_date'] ?? null;

$rangeBounds = AnalyticsService::getDateRangeBounds($preset, $customStart, $customEnd);
$startDate = $rangeBounds['start_date'];
$endDate = $rangeBounds['end_date'];

$saleType = !empty($_GET['sale_type']) ? trim($_GET['sale_type']) : null;
$searchTerm = !empty($_GET['search']) ? trim($_GET['search']) : null;
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

// Export Handling
if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
    require_permission('pharmacy.reports.export');

    if ($tab === 'register') {
        $regData = $analyticsService->getDailySalesRegister([
            'start'     => $startDate,
            'end'       => $endDate,
            'sale_type' => $saleType,
            'search'    => $searchTerm
        ], 5000, 0);

        $headers = ['Invoice No', 'Date', 'Type', 'Customer/Patient', 'Gross (₹)', 'Discount (₹)', 'Tax (₹)', 'Net (₹)', 'Paid (₹)', 'Due (₹)', 'Mode', 'Status'];
        $rows = [];
        foreach ($regData['rows'] as $r) {
            $rows[] = [
                $r['sale_number'],
                $r['sale_date'],
                $r['sale_type'],
                $r['customer_patient'],
                number_format((float)$r['gross'], 2, '.', ''),
                number_format((float)$r['discount'], 2, '.', ''),
                number_format((float)$r['tax'], 2, '.', ''),
                number_format((float)$r['net'], 2, '.', ''),
                number_format((float)$r['paid'], 2, '.', ''),
                number_format((float)$r['outstanding'], 2, '.', ''),
                $r['payment_mode'],
                $r['status']
            ];
        }
        ExportService::streamCsvDownload("sales_register_{$startDate}_{$endDate}", $headers, $rows);
    } elseif ($tab === 'medicine') {
        $medData = $analyticsService->getMedicineSalesAnalysis([
            'start'     => $startDate,
            'end'       => $endDate,
            'sale_type' => $saleType
        ], 5000, 0);

        $headers = ['Medicine Name', 'Generic', 'Category', 'Manufacturer', 'Transactions', 'Units Sold', 'Gross (₹)', 'Discount (₹)', 'Tax (₹)', 'Net Sales (₹)'];
        $rows = [];
        foreach ($medData as $m) {
            $rows[] = [
                $m['medicine_name'],
                $m['generic_name'] ?? '',
                $m['category_name'] ?? '',
                $m['manufacturer'] ?? '',
                $m['transaction_count'],
                $m['total_quantity_sold'],
                number_format((float)$m['gross_sales'], 2, '.', ''),
                number_format((float)$m['total_discount'], 2, '.', ''),
                number_format((float)$m['total_tax'], 2, '.', ''),
                number_format((float)$m['net_sales'], 2, '.', '')
            ];
        }
        ExportService::streamCsvDownload("medicine_sales_{$startDate}_{$endDate}", $headers, $rows);
    } elseif ($tab === 'batch') {
        $batchData = $analyticsService->getBatchSalesAnalysis([
            'start' => $startDate,
            'end'   => $endDate
        ], 5000, 0);

        $headers = ['Medicine Name', 'Batch No', 'Expiry Date', 'Units Sold', 'Unit Cost (₹)', 'Total Sale Value (₹)', 'COGS (₹)', 'Gross Margin (₹)', 'Margin (%)'];
        $rows = [];
        foreach ($batchData as $b) {
            $rows[] = [
                $b['medicine_name'],
                $b['batch_number'],
                $b['expiry_date'],
                $b['quantity_sold'],
                $b['historical_unit_cost'] !== null ? number_format((float)$b['historical_unit_cost'], 2, '.', '') : 'N/A',
                number_format((float)$b['total_sale_value'], 2, '.', ''),
                number_format((float)$b['total_cogs'], 2, '.', ''),
                $b['gross_margin'],
                $b['margin_percentage'] !== null ? $b['margin_percentage'] . '%' : 'N/A'
            ];
        }
        ExportService::streamCsvDownload("batch_sales_margin_{$startDate}_{$endDate}", $headers, $rows);
    }
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="container-fluid px-4 py-3">
    <!-- Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div>
            <h4 class="fw-bold mb-1 text-dark">
                <i class="ti ti-chart-bar text-emerald me-2"></i>Sales &amp; Revenue Analytics
            </h4>
            <p class="text-muted small mb-0">Authoritative registers for counter retail, e-prescriptions, and inpatient ward sales.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="?tab=<?= urlencode($tab) ?>&range=<?= urlencode($preset) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&sale_type=<?= urlencode((string)$saleType) ?>&export=csv" class="btn btn-sm btn-outline-success">
                <i class="ti ti-file-spreadsheet me-1"></i> Export CSV
            </a>
            <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-secondary">
                <i class="ti ti-printer me-1"></i> Print
            </button>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-center">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
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
                    <label class="small text-muted mb-1">Sale Channel</label>
                    <select name="sale_type" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Channels</option>
                        <option value="COUNTER_SALE" <?= $saleType === 'COUNTER_SALE' ? 'selected' : '' ?>>OPD Sales</option>
                        <option value="IPD_SALE" <?= $saleType === 'IPD_SALE' ? 'selected' : '' ?>>IPD Ward Sale</option>
                    </select>
                </div>

                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-sm btn-emerald w-100"><i class="ti ti-filter me-1"></i>Filter</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'summary' ? 'active fw-bold text-emerald border-bottom border-emerald border-2' : 'text-muted' ?>" href="?tab=summary&range=<?= urlencode($preset) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>">
                <i class="ti ti-layout-grid me-1"></i>Channel Summary
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'register' ? 'active fw-bold text-emerald border-bottom border-emerald border-2' : 'text-muted' ?>" href="?tab=register&range=<?= urlencode($preset) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>">
                <i class="ti ti-receipt me-1"></i>Daily Sales Register
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'medicine' ? 'active fw-bold text-emerald border-bottom border-emerald border-2' : 'text-muted' ?>" href="?tab=medicine&range=<?= urlencode($preset) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>">
                <i class="ti ti-pill me-1"></i>Medicine Analysis
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'batch' ? 'active fw-bold text-emerald border-bottom border-emerald border-2' : 'text-muted' ?>" href="?tab=batch&range=<?= urlencode($preset) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>">
                <i class="ti ti-percentage me-1"></i>Batch Margins &amp; COGS
            </a>
        </li>
    </ul>

    <!-- TAB 1: Channel Summary -->
    <?php if ($tab === 'summary'): ?>
        <?php $channels = $analyticsService->getSalesSummaryByChannel($rangeBounds); ?>
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-uppercase text-muted">
                            <tr>
                                <th>Sale Channel</th>
                                <th class="text-center">Transactions</th>
                                <th class="text-end">Gross (₹)</th>
                                <th class="text-end">Discounts (₹)</th>
                                <th class="text-end">Tax (₹)</th>
                                <th class="text-end">Net Sales (₹)</th>
                                <th class="text-end">Paid (₹)</th>
                                <th class="text-end">Due (₹)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($channels)): ?>
                                <tr><td colspan="8" class="text-center py-4 text-muted">No sales recorded for this period.</td></tr>
                            <?php else: ?>
                                <?php 
                                $totTx = 0; $totGross = 0; $totDisc = 0; $totTax = 0; $totNet = 0; $totPaid = 0; $totDue = 0;
                                foreach ($channels as $c): 
                                    $totTx += (int)$c['transaction_count'];
                                    $totGross += (float)$c['gross_sales'];
                                    $totDisc += (float)$c['discount'];
                                    $totTax += (float)$c['tax'];
                                    $totNet += (float)$c['net_sales'];
                                    $totPaid += (float)$c['paid'];
                                    $totDue += (float)$c['outstanding'];
                                ?>
                                    <tr>
                                        <td class="fw-bold">
                                            <?php
                                            $cLabel = match ($c['sale_type']) {
                                                'COUNTER_SALE'      => '<span class="badge bg-emerald-subtle text-emerald border border-emerald-subtle me-2 fw-semibold">OPD Sales</span>',
                                                'PRESCRIPTION_SALE' => '<span class="badge bg-purple-subtle text-purple border border-purple-subtle me-2 fw-semibold" style="background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe;">OPD Rx Sales</span>',
                                                'IPD_SALE'          => '<span class="badge bg-primary-subtle text-primary border border-primary-subtle me-2 fw-semibold" style="background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;">IPD Ward Sales</span>',
                                                default             => '<span class="badge bg-light text-dark border me-2 font-monospace">' . htmlspecialchars($c['sale_type']) . '</span>'
                                            };
                                            echo $cLabel;
                                            ?>
                                        </td>
                                        <td class="text-center fw-semibold"><?= (int)$c['transaction_count'] ?></td>
                                        <td class="text-end">₹<?= number_format((float)$c['gross_sales'], 2) ?></td>
                                        <td class="text-end text-danger">-₹<?= number_format((float)$c['discount'], 2) ?></td>
                                        <td class="text-end">₹<?= number_format((float)$c['tax'], 2) ?></td>
                                        <td class="text-end fw-bold text-emerald">₹<?= number_format((float)$c['net_sales'], 2) ?></td>
                                        <td class="text-end text-success">₹<?= number_format((float)$c['paid'], 2) ?></td>
                                        <td class="text-end text-danger">₹<?= number_format((float)$c['outstanding'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <?php if (!empty($channels)): ?>
                        <tfoot class="table-light fw-bold">
                            <tr>
                                <td>TOTALS:</td>
                                <td class="text-center"><?= $totTx ?></td>
                                <td class="text-end">₹<?= number_format($totGross, 2) ?></td>
                                <td class="text-end text-danger">-₹<?= number_format($totDisc, 2) ?></td>
                                <td class="text-end">₹<?= number_format($totTax, 2) ?></td>
                                <td class="text-end text-emerald">₹<?= number_format($totNet, 2) ?></td>
                                <td class="text-end text-success">₹<?= number_format($totPaid, 2) ?></td>
                                <td class="text-end text-danger">₹<?= number_format($totDue, 2) ?></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

    <!-- TAB 2: Daily Sales Register -->
    <?php elseif ($tab === 'register'): ?>
        <?php 
        $reg = $analyticsService->getDailySalesRegister([
            'start'     => $startDate,
            'end'       => $endDate,
            'sale_type' => $saleType,
            'search'    => $searchTerm
        ], $limit, $offset);
        $totalPages = ceil($reg['total_rows'] / $limit);
        ?>
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-uppercase text-muted">
                            <tr>
                                <th>Invoice No</th>
                                <th>Date</th>
                                <th>Channel</th>
                                <th>Customer / Patient</th>
                                <th class="text-end">Gross (₹)</th>
                                <th class="text-end">Disc (₹)</th>
                                <th class="text-end">Tax (₹)</th>
                                <th class="text-end">Net (₹)</th>
                                <th class="text-end">Paid (₹)</th>
                                <th class="text-end">Due (₹)</th>
                                <th>Mode</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($reg['rows'])): ?>
                                <tr><td colspan="12" class="text-center py-4 text-muted">No sales match current criteria.</td></tr>
                            <?php else: ?>
                                <?php foreach ($reg['rows'] as $r): ?>
                                    <?php
                                    $rBadge = match ($r['sale_type']) {
                                        'COUNTER_SALE'      => '<span class="badge bg-emerald-subtle text-emerald border border-emerald-subtle fw-semibold">OPD Sale</span>',
                                        'PRESCRIPTION_SALE' => '<span class="badge bg-purple-subtle text-purple border border-purple-subtle fw-semibold" style="background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe;">OPD Rx</span>',
                                        'IPD_SALE'          => '<span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-semibold" style="background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;">IPD Sale</span>',
                                        default             => '<span class="badge bg-light text-dark border">' . htmlspecialchars($r['sale_type']) . '</span>'
                                    };
                                    ?>
                                    <tr>
                                        <td class="fw-bold font-monospace"><?= htmlspecialchars($r['sale_number']) ?></td>
                                        <td><?= htmlspecialchars($r['sale_date']) ?></td>
                                        <td><?= $rBadge ?></td>
                                        <td><?= htmlspecialchars($r['customer_patient']) ?></td>
                                        <td class="text-end">₹<?= number_format((float)$r['gross'], 2) ?></td>
                                        <td class="text-end text-danger">-₹<?= number_format((float)$r['discount'], 2) ?></td>
                                        <td class="text-end">₹<?= number_format((float)$r['tax'], 2) ?></td>
                                        <td class="text-end fw-bold text-emerald">₹<?= number_format((float)$r['net'], 2) ?></td>
                                        <td class="text-end text-success fw-bold">₹<?= number_format((float)$r['paid'], 2) ?></td>
                                        <td class="text-end text-danger fw-bold">₹<?= number_format((float)$r['outstanding'], 2) ?></td>
                                        <td><span class="badge bg-secondary-subtle text-secondary"><?= htmlspecialchars($r['payment_mode']) ?></span></td>
                                        <td>
                                            <span class="badge bg-<?= $r['payment_status'] === 'PAID' ? 'success' : 'warning' ?>-subtle text-<?= $r['payment_status'] === 'PAID' ? 'success' : 'warning' ?>">
                                                <?= htmlspecialchars($r['payment_status']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="p-3 border-top d-flex justify-content-between align-items-center">
                    <span class="small text-muted">Showing <?= $offset + 1 ?> to <?= min($reg['total_rows'], $offset + $limit) ?> of <?= $reg['total_rows'] ?> records</span>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?tab=register&range=<?= urlencode($preset) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&page=<?= $p ?>"><?= $p ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
            </div>
        </div>

    <!-- TAB 3: Medicine Analysis -->
    <?php elseif ($tab === 'medicine'): ?>
        <?php 
        $meds = $analyticsService->getMedicineSalesAnalysis([
            'start'     => $startDate,
            'end'       => $endDate,
            'sale_type' => $saleType
        ], $limit, $offset);
        ?>
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-uppercase text-muted">
                            <tr>
                                <th>Medicine Name</th>
                                <th>Generic Name</th>
                                <th>Category</th>
                                <th class="text-center">Transactions</th>
                                <th class="text-center">Units Sold</th>
                                <th class="text-end">Gross Sales (₹)</th>
                                <th class="text-end">Discount (₹)</th>
                                <th class="text-end">Tax (₹)</th>
                                <th class="text-end">Net Revenue (₹)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($meds)): ?>
                                <tr><td colspan="9" class="text-center py-4 text-muted">No medicine sales recorded.</td></tr>
                            <?php else: ?>
                                <?php foreach ($meds as $m): ?>
                                    <tr>
                                        <td class="fw-bold text-dark"><?= htmlspecialchars($m['medicine_name']) ?></td>
                                        <td class="text-muted small"><?= htmlspecialchars($m['generic_name'] ?? 'N/A') ?></td>
                                        <td><span class="badge bg-light text-secondary border"><?= htmlspecialchars($m['category_name'] ?? 'General') ?></span></td>
                                        <td class="text-center"><?= (int)$m['transaction_count'] ?></td>
                                        <td class="text-center"><span class="badge bg-primary"><?= (int)$m['total_quantity_sold'] ?></span></td>
                                        <td class="text-end">₹<?= number_format((float)$m['gross_sales'], 2) ?></td>
                                        <td class="text-end text-danger">-₹<?= number_format((float)$m['total_discount'], 2) ?></td>
                                        <td class="text-end">₹<?= number_format((float)$m['total_tax'], 2) ?></td>
                                        <td class="text-end fw-bold text-emerald">₹<?= number_format((float)$m['net_sales'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <!-- TAB 4: Batch Margins & COGS -->
    <?php elseif ($tab === 'batch'): ?>
        <?php $batches = $analyticsService->getBatchSalesAnalysis(['start' => $startDate, 'end' => $endDate]); ?>
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-bottom py-3 px-4">
                <span class="badge bg-info-subtle text-info me-2 font-monospace">HISTORICAL COST BASIS</span>
                <span class="small text-muted">COGS and Gross Margins are computed solely from historical allocation unit costs at transaction time. Current master rates are strictly ignored.</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-uppercase text-muted">
                            <tr>
                                <th>Medicine</th>
                                <th>Batch No</th>
                                <th>Expiry</th>
                                <th class="text-center">Units Sold</th>
                                <th class="text-end">Historical Cost (₹)</th>
                                <th class="text-end">Sale Revenue (₹)</th>
                                <th class="text-end">COGS (₹)</th>
                                <th class="text-end">Gross Margin (₹)</th>
                                <th class="text-end">Margin %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($batches)): ?>
                                <tr><td colspan="9" class="text-center py-4 text-muted">No batch sales recorded during this period.</td></tr>
                            <?php else: ?>
                                <?php foreach ($batches as $b): ?>
                                    <tr>
                                        <td class="fw-bold"><?= htmlspecialchars($b['medicine_name']) ?></td>
                                        <td class="font-monospace text-primary"><?= htmlspecialchars($b['batch_number']) ?></td>
                                        <td class="small"><?= htmlspecialchars($b['expiry_date']) ?></td>
                                        <td class="text-center fw-semibold"><?= (int)$b['quantity_sold'] ?></td>
                                        <td class="text-end">
                                            <?= $b['historical_unit_cost'] !== null ? '₹' . number_format((float)$b['historical_unit_cost'], 2) : '<span class="text-muted">N/A</span>' ?>
                                        </td>
                                        <td class="text-end fw-semibold">₹<?= number_format((float)$b['total_sale_value'], 2) ?></td>
                                        <td class="text-end text-muted">₹<?= number_format((float)$b['total_cogs'], 2) ?></td>
                                        <td class="text-end fw-bold <?= is_numeric($b['gross_margin']) && (float)$b['gross_margin'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                            <?= is_numeric($b['gross_margin']) ? '₹' . number_format((float)$b['gross_margin'], 2) : '<span class="badge bg-secondary-subtle text-secondary font-monospace">' . htmlspecialchars($b['gross_margin']) . '</span>' ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($b['margin_percentage'] !== null): ?>
                                                <span class="badge bg-<?= (float)$b['margin_percentage'] >= 20 ? 'success' : 'warning' ?>-subtle text-<?= (float)$b['margin_percentage'] >= 20 ? 'success' : 'warning' ?> fw-bold">
                                                    <?= htmlspecialchars((string)$b['margin_percentage']) ?>%
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted small">-</span>
                                            <?php endif; ?>
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