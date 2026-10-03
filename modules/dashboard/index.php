<?php
// modules/dashboard/index.php - Clinical Operations & Executive Management Dashboard

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/ConfigService.php';
require_once __DIR__ . '/../../app/Services/AnalyticsService.php';

use Pharmacy\Services\ConfigService;
use Pharmacy\Services\AnalyticsService;

require_permission('pharmacy.dashboard.view');

$page_title = 'Operations Dashboard';

$configService = new ConfigService($pdo);
$analyticsService = new AnalyticsService($pdo);

$hospitalName = $configService->get('hospital_name', 'Vatsalya Hospital');
$pharmacyName = $configService->get('pharmacy_name', 'Vatsalya Central Pharmacy');

// Handle Date Range Preset & Custom Dates
$preset = $_GET['range'] ?? 'today';
$customStart = $_GET['start_date'] ?? null;
$customEnd = $_GET['end_date'] ?? null;

$rangeBounds = AnalyticsService::getDateRangeBounds($preset, $customStart, $customEnd);
$metrics = $analyticsService->getDashboardMetrics($rangeBounds);

// Fetch lists for interactive modal viewing on metric tile clicks
$catalogList = $pdo->query("
    SELECT m.medicine_id, m.medicine_name, m.generic_name, m.dosage_form, m.category, m.stock_quantity, m.reorder_level, m.price, m.rack_location
    FROM medicines m 
    WHERE m.status = 'Active' 
    ORDER BY m.medicine_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$expiringBatchesList = $pdo->query("
    SELECT b.batch_id, b.batch_number, b.expiry_date, b.quantity_available, b.mrp, b.shelf_location,
           m.medicine_id, m.medicine_name, m.generic_name, m.dosage_form,
           DATEDIFF(b.expiry_date, CURDATE()) as days_left
    FROM medicine_batches b 
    JOIN medicines m ON b.medicine_id = m.medicine_id 
    WHERE b.status = 'Active' 
      AND b.quantity_available > 0 
      AND b.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY) 
    ORDER BY b.expiry_date ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch recent sales transactions in selected period
$recentSalesStmt = $pdo->prepare("
    SELECT s.sale_id, s.sale_number, s.sale_date, s.created_at, s.sale_type, s.patient_type, 
           s.customer_name, s.grand_total, s.paid_amount, s.balance_amount, 
           s.payment_status, s.payment_mode
    FROM pharmacy_sales s
    WHERE s.sale_date BETWEEN ? AND ?
      AND s.status != 'CANCELLED'
    ORDER BY s.sale_id DESC
    LIMIT 7
");
$recentSalesStmt->execute([$rangeBounds['start_date'], $rangeBounds['end_date']]);
$recentSales = $recentSalesStmt->fetchAll(PDO::FETCH_ASSOC);

$totalCatalogCount = count($catalogList);
$lowStockCount = (int)($metrics['inventory']['low_stock_medicines'] ?? 0);
$outOfStockCount = (int)($metrics['inventory']['out_of_stock_medicines'] ?? 0);
$inStockHealthyCount = max(0, $totalCatalogCount - $lowStockCount - $outOfStockCount);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="container-fluid px-3 px-md-4 py-3">
    <!-- Header & Date Filter Toolbar -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 pb-2 border-bottom gap-2">
        <div>
            <h4 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                <i class="ti ti-layout-dashboard text-emerald"></i>
                <?= sanitize($pharmacyName) ?>
            </h4>
            <div class="text-muted small">Operations &amp; Clinical Dispensing Dashboard</div>
        </div>

        <!-- Date Range Filter Pill Dropdown -->
        <div class="d-flex align-items-center gap-2">
            <div class="dropdown">
                <button class="date-pill-btn shadow-2xs" 
                        type="button" 
                        data-bs-toggle="dropdown" 
                        aria-expanded="false">
                    <i class="ti ti-calendar text-emerald"></i>
                    <span><?= htmlspecialchars($rangeBounds['label'] ?? 'Today') ?></span>
                    <i class="ti ti-chevron-down text-muted ms-1" style="font-size: 0.75rem;"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm border p-1" style="min-width: 190px;">
                    <li><a class="dropdown-item py-1.5 px-3 small <?= $preset === 'today' ? 'active fw-bold' : '' ?>" href="index.php?range=today"><i class="ti ti-calendar-event me-1.5"></i> Today</a></li>
                    <li><a class="dropdown-item py-1.5 px-3 small <?= $preset === 'yesterday' ? 'active fw-bold' : '' ?>" href="index.php?range=yesterday"><i class="ti ti-history me-1.5"></i> Yesterday</a></li>
                    <li><a class="dropdown-item py-1.5 px-3 small <?= $preset === 'this_week' ? 'active fw-bold' : '' ?>" href="index.php?range=this_week"><i class="ti ti-calendar-stats me-1.5"></i> This Week</a></li>
                    <li><a class="dropdown-item py-1.5 px-3 small <?= $preset === 'this_month' ? 'active fw-bold' : '' ?>" href="index.php?range=this_month"><i class="ti ti-calendar me-1.5"></i> This Month</a></li>
                    <li><a class="dropdown-item py-1.5 px-3 small <?= $preset === 'prev_month' ? 'active fw-bold' : '' ?>" href="index.php?range=prev_month"><i class="ti ti-calendar-minus me-1.5"></i> Previous Month</a></li>
                    <li><a class="dropdown-item py-1.5 px-3 small <?= $preset === 'this_quarter' ? 'active fw-bold' : '' ?>" href="index.php?range=this_quarter"><i class="ti ti-chart-pie me-1.5"></i> This Quarter</a></li>
                    <li><a class="dropdown-item py-1.5 px-3 small <?= $preset === 'this_year' ? 'active fw-bold' : '' ?>" href="index.php?range=this_year"><i class="ti ti-calendar-time me-1.5"></i> This Year</a></li>
                    <li><hr class="dropdown-divider my-1"></li>
                    <li><a class="dropdown-item py-1.5 px-3 small text-emerald fw-semibold" href="javascript:void(0)" onclick="document.getElementById('customDatesWrap').classList.toggle('d-none')"><i class="ti ti-adjustments me-1.5"></i> Custom Range...</a></li>
                </ul>
            </div>

            <!-- Custom Date Range Form (when active or toggled) -->
            <div id="customDatesWrap" class="<?= $preset === 'custom' ? '' : 'd-none' ?>">
                <form method="GET" class="d-flex align-items-center gap-1.5 bg-white px-2 py-1 rounded-pill border shadow-2xs">
                    <input type="hidden" name="range" value="custom">
                    <input type="date" name="start_date" class="form-control form-control-sm border-0 py-0 px-1 text-dark" style="font-size: 0.8rem; width: 120px;" value="<?= sanitize($rangeBounds['start_date']) ?>">
                    <span class="text-muted small">&ndash;</span>
                    <input type="date" name="end_date" class="form-control form-control-sm border-0 py-0 px-1 text-dark" style="font-size: 0.8rem; width: 120px;" value="<?= sanitize($rangeBounds['end_date']) ?>">
                    <button type="submit" class="btn btn-sm btn-emerald px-2.5 py-0.5 rounded-pill fw-semibold" style="font-size: 0.75rem;">Apply</button>
                </form>
            </div>
        </div>
    </div>

    <!-- 1. TOP KPI ROW (Compact, Readable, Minimal Decoration) -->
    <div class="row g-2 g-md-3 mb-3">
        <!-- KPI 1: Today's / Period Sales -->
        <div class="col-xl-3 col-sm-6">
            <div class="kpi-card">
                <div class="kpi-label">
                    <span>Net Sales</span>
                    <span class="kpi-status-dot dot-emerald" title="Normal"></span>
                </div>
                <div class="kpi-value text-emerald">
                    ₹<?= number_format($metrics['sales']['net_after_returns'], 2) ?>
                </div>
                <div class="kpi-sub">
                    <span>Gross: ₹<?= number_format($metrics['sales']['gross_sales'], 2) ?></span>
                    <span>&bull;</span>
                    <span><?= $metrics['sales']['invoices_count'] ?> Invoices</span>
                </div>
            </div>
        </div>

        <!-- KPI 2: Pending Receivables / Dues -->
        <div class="col-xl-3 col-sm-6">
            <div class="kpi-card">
                <div class="kpi-label">
                    <span>Pending Receivables</span>
                    <span class="kpi-status-dot <?= ($metrics['sales']['credit'] + $metrics['sales']['outstanding']) > 0 ? 'dot-amber' : 'dot-emerald' ?>" title="Payment status"></span>
                </div>
                <div class="kpi-value <?= ($metrics['sales']['credit'] + $metrics['sales']['outstanding']) > 0 ? 'text-amber-emphasis' : 'text-dark' ?>">
                    ₹<?= number_format($metrics['sales']['credit'] + $metrics['sales']['outstanding'], 2) ?>
                </div>
                <div class="kpi-sub">
                    <span>OPD: <?= $metrics['opd']['pending_bills'] ?> pending</span>
                    <span>&bull;</span>
                    <span>IPD: <?= $metrics['ipd']['pending_bills'] ?> pending</span>
                </div>
            </div>
        </div>

        <!-- KPI 3: Low & Out of Stock -->
        <div class="col-xl-3 col-sm-6">
            <div class="kpi-card kpi-clickable" 
                 onclick="openCatalogWithFilter('<?= $outOfStockCount > 0 ? 'out_of_stock' : ($lowStockCount > 0 ? 'low_stock' : 'all') ?>')"
                 title="Click to inspect medicine catalog">
                <div class="kpi-label">
                    <span>Stock Alerts</span>
                    <span class="kpi-status-dot <?= ($outOfStockCount > 0) ? 'dot-red' : (($lowStockCount > 0) ? 'dot-amber' : 'dot-emerald') ?>" title="Inventory health"></span>
                </div>
                <div class="kpi-value <?= ($outOfStockCount > 0) ? 'text-danger' : (($lowStockCount > 0) ? 'text-amber-emphasis' : 'text-dark') ?>">
                    <?= $lowStockCount ?> Low <span class="text-muted fs-6 fw-normal">/</span> <?= $outOfStockCount ?> Out
                </div>
                <div class="kpi-sub">
                    <span><?= $totalCatalogCount ?> total catalog items (Click to view)</span>
                </div>
            </div>
        </div>

        <!-- KPI 4: Expiring Soon -->
        <div class="col-xl-3 col-sm-6">
            <div class="kpi-card kpi-clickable" 
                 data-bs-toggle="modal" 
                 data-bs-target="#expiringBatchesModal"
                 title="Click to view expiring batches">
                <div class="kpi-label">
                    <span>Expiring Soon (&le; 90d)</span>
                    <span class="kpi-status-dot <?= count($expiringBatchesList) > 0 ? 'dot-amber' : 'dot-emerald' ?>" title="Shelf life"></span>
                </div>
                <div class="kpi-value <?= count($expiringBatchesList) > 0 ? 'text-amber-emphasis' : 'text-dark' ?>">
                    <?= $metrics['inventory']['expiring_soon_batches'] ?> Batches
                </div>
                <div class="kpi-sub">
                    <span>Requires FEFO dispensing priority</span>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. OPD & IPD OPERATIONAL CHANNELS -->
    <div class="row g-3 mb-3 align-items-stretch">
        <!-- OPD SALES CARD -->
        <div class="col-lg-6">
            <div class="ops-channel-card channel-opd">
                <div class="ops-channel-body">
                    <div class="ops-channel-info">
                        <div class="ops-channel-title">
                            <span class="badge bg-emerald-subtle text-emerald rounded-pill px-2 py-0.5 small">OPD</span>
                            <span>Outpatient Sales</span>
                        </div>
                        <div class="ops-channel-amount">
                            ₹<?= number_format($metrics['opd']['gross_sales'], 2) ?>
                        </div>
                        <div class="text-muted small mt-1">Total outpatient billing for <?= sanitize($rangeBounds['label']) ?></div>
                    </div>
                    <a href="<?= BASE_URL ?>modules/sales/counter.php" class="ops-channel-cta-btn btn-opd-cta">
                        <div class="cta-icon-wrapper">
                            <i class="ti ti-shopping-cart"></i>
                        </div>
                        <div class="cta-text-wrapper">
                            <span class="cta-title">Open OPD Sales</span>
                            <span class="cta-subtitle">Counter Dispensing</span>
                        </div>
                        <i class="ti ti-arrow-right cta-arrow"></i>
                    </a>
                </div>

                <div class="ops-channel-footer">
                    <div><strong class="text-dark"><?= $metrics['opd']['invoices_count'] ?></strong> Invoices</div>
                    <div><strong class="text-emerald">₹<?= number_format($metrics['opd']['paid'], 2) ?></strong> Collected</div>
                    <div>
                        <strong class="<?= $metrics['opd']['pending_bills'] > 0 ? 'text-amber-emphasis' : 'text-muted' ?>">
                            <?= $metrics['opd']['pending_bills'] ?>
                        </strong> Pending Bills
                    </div>
                </div>
            </div>
        </div>

        <!-- IPD PHARMACY CARD -->
        <div class="col-lg-6">
            <div class="ops-channel-card channel-ipd">
                <div class="ops-channel-body">
                    <div class="ops-channel-info">
                        <div class="ops-channel-title">
                            <span class="badge bg-primary-subtle text-primary rounded-pill px-2 py-0.5 small">IPD</span>
                            <span>Inpatient Pharmacy</span>
                        </div>
                        <div class="ops-channel-amount">
                            ₹<?= number_format($metrics['ipd']['gross_sales'], 2) ?>
                        </div>
                        <div class="text-muted small mt-1">Total inpatient &amp; ward issue for <?= sanitize($rangeBounds['label']) ?></div>
                    </div>
                    <a href="<?= BASE_URL ?>modules/sales/regular.php" class="ops-channel-cta-btn btn-ipd-cta">
                        <div class="cta-icon-wrapper">
                            <i class="ti ti-building-hospital"></i>
                        </div>
                        <div class="cta-text-wrapper">
                            <span class="cta-title">Open IPD Pharmacy</span>
                            <span class="cta-subtitle">Ward Issue &amp; Billing</span>
                        </div>
                        <i class="ti ti-arrow-right cta-arrow"></i>
                    </a>
                </div>

                <div class="ops-channel-footer">
                    <div><strong class="text-dark"><?= $metrics['ipd']['invoices_count'] ?></strong> Invoices</div>
                    <div>
                        <strong class="<?= $metrics['ipd']['pending_indents'] > 0 ? 'text-primary' : 'text-muted' ?>">
                            <?= $metrics['ipd']['pending_indents'] ?>
                        </strong> Pending Indents
                    </div>
                    <div>
                        <strong class="<?= $metrics['ipd']['pending_bills'] > 0 ? 'text-amber-emphasis' : 'text-muted' ?>">
                            <?= $metrics['ipd']['pending_bills'] ?>
                        </strong> Pending Bills
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. OPERATIONAL QUEUES & INVENTORY/PROCUREMENT ROW -->
    <div class="row g-3 mb-3">
        <!-- Operational Queues & Workflow Actions -->
        <div class="col-lg-6">
            <div class="card h-100 border">
                <div class="card-header bg-white border-bottom py-2.5 px-3 d-flex justify-content-between align-items-center">
                    <span class="fw-bold text-dark small text-uppercase" style="letter-spacing: 0.03em;">
                        <i class="ti ti-clock-pause text-secondary me-1"></i>Operational &amp; Workflow Queues
                    </span>
                    <a href="<?= BASE_URL ?>modules/reports/reconciliation.php" class="small text-decoration-none fw-semibold text-primary">
                        Reconciliation <i class="ti ti-chevron-right"></i>
                    </a>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <div class="list-group-item d-flex justify-content-between align-items-center px-3 py-2">
                            <div class="d-flex align-items-center gap-2">
                                <i class="ti ti-notes text-primary"></i>
                                <span class="small fw-medium text-dark">Pending Ward Indents</span>
                            </div>
                            <span class="badge <?= ($metrics['operations']['pending_indents'] ?? 0) > 0 ? 'bg-primary' : 'bg-secondary-subtle text-secondary' ?> rounded-pill px-2 py-0.5">
                                <?= $metrics['operations']['pending_indents'] ?? 0 ?>
                            </span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center px-3 py-2">
                            <div class="d-flex align-items-center gap-2">
                                <i class="ti ti-arrow-back-up text-warning"></i>
                                <span class="small fw-medium text-dark">Pending Sales Returns</span>
                            </div>
                            <span class="badge <?= $metrics['operations']['pending_sale_returns'] > 0 ? 'bg-warning text-dark' : 'bg-secondary-subtle text-secondary' ?> rounded-pill px-2 py-0.5">
                                <?= $metrics['operations']['pending_sale_returns'] ?>
                            </span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center px-3 py-2">
                            <div class="d-flex align-items-center gap-2">
                                <i class="ti ti-truck-return text-danger"></i>
                                <span class="small fw-medium text-dark">Pending Purchase Returns</span>
                            </div>
                            <span class="badge <?= $metrics['operations']['pending_purchase_returns'] > 0 ? 'bg-danger' : 'bg-secondary-subtle text-secondary' ?> rounded-pill px-2 py-0.5">
                                <?= $metrics['operations']['pending_purchase_returns'] ?>
                            </span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center px-3 py-2">
                            <div class="d-flex align-items-center gap-2">
                                <i class="ti ti-shield-alert text-secondary"></i>
                                <span class="small fw-medium text-dark">Active Quarantine Records</span>
                            </div>
                            <span class="badge <?= $metrics['operations']['active_quarantine'] > 0 ? 'bg-secondary text-white' : 'bg-secondary-subtle text-secondary' ?> rounded-pill px-2 py-0.5">
                                <?= $metrics['operations']['active_quarantine'] ?>
                            </span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center px-3 py-2">
                            <div class="d-flex align-items-center gap-2">
                                <i class="ti ti-trash text-danger"></i>
                                <span class="small fw-medium text-dark">Pending Stock Disposals</span>
                            </div>
                            <span class="badge <?= ($metrics['operations']['pending_disposals'] ?? 0) > 0 ? 'bg-danger' : 'bg-secondary-subtle text-secondary' ?> rounded-pill px-2 py-0.5">
                                <?= $metrics['operations']['pending_disposals'] ?? 0 ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Inventory State & Procurement Summary -->
        <div class="col-lg-6">
            <div class="card h-100 border">
                <div class="card-header bg-white border-bottom py-2.5 px-3 d-flex justify-content-between align-items-center">
                    <span class="fw-bold text-dark small text-uppercase" style="letter-spacing: 0.03em;">
                        <i class="ti ti-packages text-secondary me-1"></i>Inventory &amp; Procurement Summary
                    </span>
                    <a href="<?= BASE_URL ?>modules/reports/stock.php" class="small text-decoration-none fw-semibold text-emerald">
                        Stock Valuation <i class="ti ti-chevron-right"></i>
                    </a>
                </div>
                <div class="card-body p-3">
                    <div class="row g-2 h-100">
                        <div class="col-6">
                            <div class="procure-metric-tile">
                                <div>
                                    <div class="procure-metric-label">
                                        <i class="ti ti-packages text-primary"></i>
                                        <span>Available Stock Value</span>
                                    </div>
                                    <div class="procure-metric-value">
                                        ₹<?= number_format($metrics['inventory']['available_stock_value'], 2) ?>
                                    </div>
                                </div>
                                <div class="procure-metric-sub">
                                    <?= number_format($metrics['inventory']['available_units']) ?> units · <?= $metrics['inventory']['active_batches'] ?> batches
                                </div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="procure-metric-tile">
                                <div>
                                    <div class="procure-metric-label">
                                        <i class="ti ti-truck-delivery text-info"></i>
                                        <span>Purchases (<?= sanitize($rangeBounds['label']) ?>)</span>
                                    </div>
                                    <div class="procure-metric-value">
                                        ₹<?= number_format($metrics['purchases']['net_purchases'], 2) ?>
                                    </div>
                                </div>
                                <div class="procure-metric-sub">
                                    <?= $metrics['purchases']['invoices_count'] ?> Purchase <?= $metrics['purchases']['invoices_count'] === 1 ? 'Invoice' : 'Invoices' ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="procure-metric-tile">
                                <div>
                                    <div class="procure-metric-label">
                                        <i class="ti ti-circle-check text-emerald"></i>
                                        <span>Paid to Suppliers</span>
                                    </div>
                                    <div class="procure-metric-value text-emerald">
                                        ₹<?= number_format($metrics['purchases']['paid'], 2) ?>
                                    </div>
                                </div>
                                <div class="procure-metric-sub">
                                    Settled supplier payments
                                </div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="procure-metric-tile">
                                <div>
                                    <div class="procure-metric-label">
                                        <i class="ti ti-clock-pause <?= $metrics['purchases']['outstanding'] > 0 ? 'text-danger' : 'text-secondary' ?>"></i>
                                        <span>Outstanding Payables</span>
                                    </div>
                                    <div class="procure-metric-value <?= $metrics['purchases']['outstanding'] > 0 ? 'text-danger' : 'text-dark' ?>">
                                        ₹<?= number_format($metrics['purchases']['outstanding'], 2) ?>
                                    </div>
                                </div>
                                <div class="procure-metric-sub">
                                    <?= $metrics['purchases']['outstanding'] > 0 ? 'Pending credit balance' : 'No overdue payables' ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 4. RECENT TRANSACTIONS TABLE (Compact & High-Density) -->
    <div class="card border mb-3">
        <div class="card-header bg-white border-bottom py-2.5 px-3 d-flex justify-content-between align-items-center">
            <span class="fw-bold text-dark small text-uppercase" style="letter-spacing: 0.03em;">
                <i class="ti ti-receipt text-secondary me-1"></i>Recent Transactions (<?= sanitize($rangeBounds['label']) ?>)
            </span>
            <a href="<?= BASE_URL ?>modules/reports/sales.php" class="small text-decoration-none fw-semibold text-primary">
                View All Sales <i class="ti ti-chevron-right"></i>
            </a>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr class="text-secondary small fw-semibold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.04em;">
                            <th class="ps-3 py-2">Invoice #</th>
                            <th class="py-2">Date / Time</th>
                            <th class="py-2">Channel</th>
                            <th class="py-2">Patient / Customer</th>
                            <th class="py-2">Payment Mode</th>
                            <th class="py-2 text-center">Status</th>
                            <th class="py-2 text-end">Amount</th>
                            <th class="pe-3 py-2 text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentSales)): ?>
                            <tr>
                                <td colspan="8" class="p-0">
                                    <div class="empty-state-box">
                                        <i class="ti ti-receipt-off empty-state-icon"></i>
                                        <div class="empty-state-title">No records found</div>
                                        <div class="empty-state-desc">No sales transactions recorded for <?= htmlspecialchars($rangeBounds['label']) ?>.</div>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentSales as $sale): 
                                $isIPD = ($sale['sale_type'] === 'IPD_SALE' || strtolower($sale['patient_type'] ?? '') === 'ipd');
                                $pName = $sale['customer_name'] ?: 'Counter Walk-in';
                                $statusClass = 'bg-success-subtle text-success';
                                if ($sale['payment_status'] === 'UNPAID') $statusClass = 'bg-danger-subtle text-danger';
                                elseif ($sale['payment_status'] === 'PARTIALLY_PAID') $statusClass = 'bg-warning-subtle text-warning-emphasis';
                            ?>
                            <tr>
                                <td class="ps-3">
                                    <span class="font-monospace fw-bold text-dark small">
                                        <?= htmlspecialchars($sale['sale_number']) ?>
                                    </span>
                                </td>
                                <td class="text-secondary small text-nowrap">
                                    <?= date('d M, h:i A', strtotime($sale['created_at'] ?? $sale['sale_date'])) ?>
                                </td>
                                <td>
                                    <?php if ($isIPD): ?>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2 py-0.5 small">IPD</span>
                                    <?php else: ?>
                                        <span class="badge bg-emerald-subtle text-emerald border border-emerald-subtle rounded-pill px-2 py-0.5 small">OPD</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="fw-semibold text-dark small"><?= htmlspecialchars($pName) ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-light text-secondary border small">
                                        <?= htmlspecialchars(strtoupper($sale['payment_mode'] ?: 'CASH')) ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="badge <?= $statusClass ?> rounded-pill px-2 py-0.5 small fw-semibold">
                                        <?= htmlspecialchars($sale['payment_status'] ?: 'PAID') ?>
                                    </span>
                                </td>
                                <td class="text-end font-monospace fw-bold text-dark small">
                                    ₹<?= number_format((float)$sale['grand_total'], 2) ?>
                                </td>
                                <td class="pe-3 text-end">
                                    <a href="<?= BASE_URL ?>modules/sales/invoice.php?id=<?= $sale['sale_id'] ?>" 
                                       class="btn btn-sm btn-outline-secondary py-0.5 px-2 rounded small"
                                       title="View invoice">
                                        <i class="ti ti-eye"></i>
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

    <!-- 5. CLINICAL MAR OPERATIONS (IF DATA EXISTS) -->
    <?php if ($metrics['clinical_mar']['has_data']): ?>
    <div class="card border mb-3">
        <div class="card-header bg-white border-bottom py-2.5 px-3 d-flex justify-content-between align-items-center">
            <span class="fw-bold text-dark small text-uppercase" style="letter-spacing: 0.03em;">
                <i class="ti ti-clipboard-check text-primary me-1"></i>Today's Inpatient Clinical Medication Administration (MAR)
            </span>
            <a href="<?= BASE_URL ?>modules/reports/mar_register.php" class="small text-decoration-none fw-semibold text-primary">
                MAR Register <i class="ti ti-chevron-right"></i>
            </a>
        </div>
        <div class="card-body p-3">
            <div class="row g-2 text-center">
                <div class="col-sm-2 col-4">
                    <div class="p-2 border rounded bg-light-subtle">
                        <div class="small text-muted">Scheduled</div>
                        <div class="fs-5 fw-bold text-dark font-monospace"><?= $metrics['clinical_mar']['total_scheduled'] ?></div>
                    </div>
                </div>
                <div class="col-sm-2 col-4">
                    <div class="p-2 border rounded bg-success-subtle">
                        <div class="small text-success fw-semibold">Administered</div>
                        <div class="fs-5 fw-bold text-success font-monospace"><?= $metrics['clinical_mar']['administered'] ?></div>
                    </div>
                </div>
                <div class="col-sm-2 col-4">
                    <div class="p-2 border rounded bg-danger-subtle">
                        <div class="small text-danger fw-semibold">Missed</div>
                        <div class="fs-5 fw-bold text-danger font-monospace"><?= $metrics['clinical_mar']['missed'] ?></div>
                    </div>
                </div>
                <div class="col-sm-2 col-4">
                    <div class="p-2 border rounded bg-warning-subtle">
                        <div class="small text-warning fw-semibold">Held</div>
                        <div class="fs-5 fw-bold text-warning font-monospace"><?= $metrics['clinical_mar']['held'] ?></div>
                    </div>
                </div>
                <div class="col-sm-2 col-4">
                    <div class="p-2 border rounded bg-secondary-subtle">
                        <div class="small text-secondary fw-semibold">Refused</div>
                        <div class="fs-5 fw-bold text-secondary font-monospace"><?= $metrics['clinical_mar']['refused'] ?></div>
                    </div>
                </div>
                <div class="col-sm-2 col-4">
                    <div class="p-2 border rounded bg-primary-subtle">
                        <div class="small text-primary fw-semibold">Adherence</div>
                        <div class="fs-5 fw-bold text-primary font-monospace">
                            <?= round(($metrics['clinical_mar']['administered'] / max(1, $metrics['clinical_mar']['total_scheduled'])) * 100, 1) ?>%
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ========================================== -->
<!-- MODALS FOR INTERACTIVE INVENTORY TILE VIEW -->
<!-- ========================================== -->

<!-- 1. Catalog Medicines Modal -->
<div class="modal fade" id="catalogListModal" tabindex="-1" aria-labelledby="catalogListModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content rounded-3 border-0 shadow">
            <div class="modal-header border-bottom px-3 py-2.5 bg-light-subtle">
                <div class="d-flex align-items-center gap-2">
                    <div class="d-inline-flex align-items-center justify-content-center rounded bg-emerald-subtle text-emerald" style="width: 32px; height: 32px;">
                        <i class="ti ti-pill fs-5"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <h6 class="modal-title fw-bold text-dark mb-0" id="catalogListModalLabel">Active Medicine Catalog</h6>
                            <span class="badge bg-emerald-subtle text-emerald border border-emerald-subtle rounded-pill px-2 py-0.5 small">
                                <?= count($catalogList) ?> Items
                            </span>
                        </div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Filter Toolbar -->
            <div class="px-3 py-2 bg-white border-bottom">
                <div class="row g-2 align-items-center">
                    <div class="col-md-7 col-12">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-light border-end-0 text-muted">
                                <i class="ti ti-search"></i>
                            </span>
                            <input type="text" 
                                   id="catalogFilterInput" 
                                   class="form-control bg-light border-start-0" 
                                   placeholder="Search medicine, generic, category, rack..." 
                                   oninput="filterCatalogModalTable(this.value)">
                            <button class="btn btn-outline-secondary" type="button" onclick="clearCatalogFilter()">
                                <i class="ti ti-x"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-5 col-12">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-light border-end-0 text-dark fw-semibold small">
                                <i class="ti ti-filter text-emerald me-1"></i> Filter
                            </span>
                            <select id="catalogStockFilterSelect" 
                                    class="form-select bg-light border-start-0 fw-semibold text-dark shadow-none" 
                                    onchange="setCatalogStatusFilter(this.value)">
                                <option value="all">All Varieties (<?= $totalCatalogCount ?>)</option>
                                <option value="in_stock">In Stock Healthy (<?= $inStockHealthyCount ?>)</option>
                                <option value="low_stock">Low Stock (<?= $lowStockCount ?>)</option>
                                <option value="out_of_stock">Out of Stock (<?= $outOfStockCount ?>)</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-body p-0">
                <div class="table-responsive" style="height: 55vh; min-height: 400px; overflow-y: auto;">
                    <table class="table table-hover align-middle mb-0" id="catalogModalTable">
                        <thead class="table-light sticky-top" style="z-index: 5;">
                            <tr class="text-secondary small fw-semibold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.04em;">
                                <th class="ps-3 py-2" style="width: 40px;">#</th>
                                <th class="py-2">Medicine &amp; Generic Name</th>
                                <th class="py-2">Dosage / Category</th>
                                <th class="py-2 text-center">Current Stock</th>
                                <th class="py-2 text-end">Price</th>
                                <th class="py-2">Rack</th>
                                <th class="pe-3 py-2 text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody id="catalogModalTbody">
                            <?php if (empty($catalogList)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">No medicines found in catalog.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($catalogList as $idx => $med): 
                                    $isOut = ((int)$med['stock_quantity'] <= 0);
                                    $isLow = (!$isOut && (int)$med['stock_quantity'] <= (int)$med['reorder_level']);
                                    $stockStatus = $isOut ? 'out_of_stock' : ($isLow ? 'low_stock' : 'in_stock');
                                ?>
                                <tr class="catalog-row" data-stock-status="<?= $stockStatus ?>">
                                    <td class="ps-3 text-muted small"><?= $idx + 1 ?></td>
                                    <td>
                                        <div class="fw-bold text-dark small"><?= htmlspecialchars($med['medicine_name']) ?></div>
                                        <?php if (!empty($med['generic_name'])): ?>
                                            <div class="text-muted" style="font-size: 0.75rem;"><?= htmlspecialchars($med['generic_name']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border me-1 small"><?= htmlspecialchars($med['dosage_form'] ?? 'Item') ?></span>
                                        <span class="text-muted small"><?= htmlspecialchars($med['category'] ?? 'General') ?></span>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($isOut): ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-2 py-0.5 small fw-semibold">
                                                0 Out of Stock
                                            </span>
                                        <?php elseif ($isLow): ?>
                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill px-2 py-0.5 small fw-semibold">
                                                <?= number_format($med['stock_quantity']) ?> (Low Stock)
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-0.5 small fw-semibold">
                                                <?= number_format($med['stock_quantity']) ?> units
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end font-monospace fw-semibold text-dark small">
                                        ₹<?= number_format((float)$med['price'], 2) ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-secondary border small">
                                            <?= htmlspecialchars($med['rack_location'] ?: 'Not Set') ?>
                                        </span>
                                    </td>
                                    <td class="pe-3 text-end">
                                        <a href="<?= BASE_URL ?>modules/inventory/profile.php?id=<?= $med['medicine_id'] ?>" 
                                           class="btn btn-sm btn-outline-primary py-0.5 px-2 rounded small"
                                           title="View medicine profile">
                                            Profile
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <tr id="catalogEmptySearchRow" style="display: none;">
                                <td colspan="7" class="text-center py-4 text-muted">
                                    <div class="fw-bold small text-dark" id="catalogEmptyTitle">No medicines match your search criteria.</div>
                                    <div class="text-muted" style="font-size: 0.75rem;" id="catalogEmptySubtitle">Try adjusting keywords or selecting a different filter category.</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="modal-footer d-flex justify-content-between align-items-center px-3 py-2 bg-light-subtle border-top">
                <div class="text-muted small">
                    Showing <span id="catalogCountShown" class="fw-bold text-dark"><?= count($catalogList) ?></span> of <?= count($catalogList) ?> medicines
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= BASE_URL ?>modules/inventory/products.php" class="btn btn-sm btn-outline-emerald rounded px-2.5">
                        <i class="ti ti-external-link me-1"></i> Full Medicine Master
                    </a>
                    <button type="button" class="btn btn-sm btn-secondary rounded px-3" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 2. Expiring Soon Batches Modal -->
<div class="modal fade" id="expiringBatchesModal" tabindex="-1" aria-labelledby="expiringBatchesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content rounded-3 border-0 shadow">
            <div class="modal-header border-bottom px-3 py-2.5 bg-light-subtle">
                <div class="d-flex align-items-center gap-2">
                    <div class="d-inline-flex align-items-center justify-content-center rounded bg-warning-subtle text-warning-emphasis" style="width: 32px; height: 32px;">
                        <i class="ti ti-clock-alert fs-5"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <h6 class="modal-title fw-bold text-dark mb-0" id="expiringBatchesModalLabel">Expiring Soon Batches (&le; 90 Days)</h6>
                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill px-2 py-0.5 small">
                                <?= count($expiringBatchesList) ?> Batches
                            </span>
                        </div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Live Search Bar -->
            <div class="p-2.5 bg-white border-bottom">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light border-end-0 text-muted">
                        <i class="ti ti-search"></i>
                    </span>
                    <input type="text" 
                           id="expiringFilterInput" 
                           class="form-control bg-light border-start-0" 
                           placeholder="Filter expiring batches by medicine name, batch number, shelf..." 
                           oninput="filterExpiringModalTable(this.value)">
                    <button class="btn btn-outline-secondary" type="button" onclick="clearExpiringFilter()">
                        <i class="ti ti-x"></i>
                    </button>
                </div>
            </div>

            <div class="modal-body p-0">
                <div class="table-responsive" style="height: 55vh; min-height: 400px; overflow-y: auto;">
                    <table class="table table-hover align-middle mb-0" id="expiringModalTable">
                        <thead class="table-light sticky-top" style="z-index: 5;">
                            <tr class="text-secondary small fw-semibold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.04em;">
                                <th class="ps-3 py-2" style="width: 40px;">#</th>
                                <th class="py-2">Medicine Name</th>
                                <th class="py-2">Batch Number</th>
                                <th class="py-2">Expiry Date</th>
                                <th class="py-2 text-center">Remaining</th>
                                <th class="py-2 text-center">Available Units</th>
                                <th class="py-2 text-end">MRP</th>
                                <th class="py-2">Shelf</th>
                                <th class="pe-3 py-2 text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody id="expiringModalTbody">
                            <?php if (empty($expiringBatchesList)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-4 text-muted">No batches expiring within the next 90 days.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($expiringBatchesList as $idx => $batch): 
                                    $daysLeft = (int)$batch['days_left'];
                                    $urgencyBadge = '';
                                    if ($daysLeft <= 30) {
                                        $urgencyBadge = '<span class="badge bg-danger text-white rounded-pill px-2 py-0.5 small fw-bold">' . $daysLeft . 'd (Critical)</span>';
                                    } elseif ($daysLeft <= 60) {
                                        $urgencyBadge = '<span class="badge bg-warning text-dark rounded-pill px-2 py-0.5 small fw-bold">' . $daysLeft . 'd (High)</span>';
                                    } else {
                                        $urgencyBadge = '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill px-2 py-0.5 small fw-semibold">' . $daysLeft . 'd</span>';
                                    }
                                ?>
                                <tr class="expiring-row">
                                    <td class="ps-3 text-muted small"><?= $idx + 1 ?></td>
                                    <td>
                                        <div class="fw-bold text-dark small"><?= htmlspecialchars($batch['medicine_name']) ?></div>
                                        <div class="text-muted" style="font-size: 0.75rem;">
                                            <?= htmlspecialchars($batch['dosage_form'] ?? '') ?> 
                                            <?= !empty($batch['generic_name']) ? '&bull; ' . htmlspecialchars($batch['generic_name']) : '' ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="font-monospace fw-semibold text-dark small">
                                            <?= htmlspecialchars($batch['batch_number']) ?>
                                        </span>
                                    </td>
                                    <td class="text-nowrap small text-dark">
                                        <?= date('d M Y', strtotime($batch['expiry_date'])) ?>
                                    </td>
                                    <td class="text-center">
                                        <?= $urgencyBadge ?>
                                    </td>
                                    <td class="text-center font-monospace fw-bold text-dark small">
                                        <?= number_format($batch['quantity_available']) ?>
                                    </td>
                                    <td class="text-end font-monospace fw-semibold text-dark small">
                                        ₹<?= number_format((float)$batch['mrp'], 2) ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-secondary border small">
                                            <?= htmlspecialchars($batch['shelf_location'] ?: 'General') ?>
                                        </span>
                                    </td>
                                    <td class="pe-3 text-end">
                                        <a href="<?= BASE_URL ?>modules/inventory/expiry.php?tab=90d" 
                                           class="btn btn-sm btn-outline-warning text-dark py-0.5 px-2 rounded small"
                                           title="Manage batch in Expiry Desk">
                                            Manage
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <tr id="expiringEmptySearchRow" style="display: none;">
                                <td colspan="9" class="text-center py-4 text-muted">
                                    <span class="small fw-semibold">No expiring batches match your search criteria.</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="modal-footer d-flex justify-content-between align-items-center px-3 py-2 bg-light-subtle border-top">
                <div class="text-muted small">
                    Showing <span id="expiringCountShown" class="fw-bold text-dark"><?= count($expiringBatchesList) ?></span> of <?= count($expiringBatchesList) ?> batches
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= BASE_URL ?>modules/inventory/expiry.php?tab=90d" class="btn btn-sm btn-warning text-dark rounded px-2.5 fw-semibold">
                        <i class="ti ti-external-link me-1"></i> Expiry Management Desk
                    </a>
                    <button type="button" class="btn btn-sm btn-secondary rounded px-3" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let currentCatalogFilter = 'all';

function setCatalogStatusFilter(filter) {
    currentCatalogFilter = filter || 'all';

    const selectEl = document.getElementById('catalogStockFilterSelect');
    if (selectEl && selectEl.value !== currentCatalogFilter) {
        selectEl.value = currentCatalogFilter;
    }

    const searchInput = document.getElementById('catalogFilterInput');
    filterCatalogModalTable(searchInput ? searchInput.value : '');
}

function openCatalogWithFilter(filter) {
    setCatalogStatusFilter(filter || 'all');
    const modalEl = document.getElementById('catalogListModal');
    if (modalEl) {
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }
}

function filterCatalogModalTable(query) {
    const q = (query !== undefined ? query : (document.getElementById('catalogFilterInput')?.value || '')).toLowerCase().trim();
    const rows = document.querySelectorAll('#catalogModalTbody .catalog-row');
    let visibleCount = 0;

    rows.forEach(row => {
        const rowStatus = row.getAttribute('data-stock-status') || '';
        const matchesStatus = (currentCatalogFilter === 'all') || (rowStatus === currentCatalogFilter);
        const text = row.textContent.toLowerCase();
        const matchesText = !q || text.includes(q);

        if (matchesStatus && matchesText) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    const emptyRow = document.getElementById('catalogEmptySearchRow');
    if (emptyRow) {
        if (visibleCount === 0 && rows.length > 0) {
            emptyRow.style.display = '';
            const titleEl = document.getElementById('catalogEmptyTitle');
            const subEl = document.getElementById('catalogEmptySubtitle');

            if (currentCatalogFilter === 'low_stock' && !q) {
                if (titleEl) titleEl.textContent = 'No Low Stock Medicines';
                if (subEl) subEl.textContent = 'All registered items have stock levels comfortably above reorder thresholds.';
            } else if (currentCatalogFilter === 'out_of_stock' && !q) {
                if (titleEl) titleEl.textContent = 'No Out of Stock Medicines';
                if (subEl) subEl.textContent = 'Every registered medicine variety currently has active units available.';
            } else {
                if (titleEl) titleEl.textContent = 'No medicines match your search criteria.';
                if (subEl) subEl.textContent = 'Try adjusting keywords or selecting a different filter category.';
            }
        } else {
            emptyRow.style.display = 'none';
        }
    }

    const countElem = document.getElementById('catalogCountShown');
    if (countElem) {
        countElem.textContent = visibleCount;
    }
}

function clearCatalogFilter() {
    const input = document.getElementById('catalogFilterInput');
    if (input) {
        input.value = '';
        filterCatalogModalTable('');
        input.focus();
    }
}

function filterExpiringModalTable(query) {
    const q = (query || '').toLowerCase().trim();
    const rows = document.querySelectorAll('#expiringModalTbody .expiring-row');
    let visibleCount = 0;

    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        if (text.includes(q)) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    const emptyRow = document.getElementById('expiringEmptySearchRow');
    if (emptyRow) {
        emptyRow.style.display = (visibleCount === 0 && rows.length > 0) ? '' : 'none';
    }

    const countElem = document.getElementById('expiringCountShown');
    if (countElem) {
        countElem.textContent = visibleCount;
    }
}

function clearExpiringFilter() {
    const input = document.getElementById('expiringFilterInput');
    if (input) {
        input.value = '';
        filterExpiringModalTable('');
        input.focus();
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const catalogModal = document.getElementById('catalogListModal');
    if (catalogModal) {
        catalogModal.addEventListener('shown.bs.modal', () => {
            const input = document.getElementById('catalogFilterInput');
            if (input) input.focus();
        });
        catalogModal.addEventListener('hidden.bs.modal', () => {
            clearCatalogFilter();
        });
    }

    const expiringModal = document.getElementById('expiringBatchesModal');
    if (expiringModal) {
        expiringModal.addEventListener('shown.bs.modal', () => {
            const input = document.getElementById('expiringFilterInput');
            if (input) input.focus();
        });
        expiringModal.addEventListener('hidden.bs.modal', () => {
            clearExpiringFilter();
        });
    }
});
</script>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
