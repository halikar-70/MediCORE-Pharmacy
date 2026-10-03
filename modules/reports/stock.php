<?php
// modules/reports/stock.php - Comprehensive Authoritative Stock & Inventory Analytics (Chunk 7)

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/AnalyticsService.php';
require_once __DIR__ . '/../../app/Services/ExportService.php';

use Pharmacy\Services\AnalyticsService;
use Pharmacy\Services\ExportService;

require_permission('pharmacy.reports.view');

$page_title = 'Inventory & Valuation Analytics';
$analyticsService = new AnalyticsService($pdo);

$tab = $_GET['tab'] ?? 'valuation';
$slowDays = max(15, (int)($_GET['slow_days'] ?? 90));
$preset = $_GET['range'] ?? 'this_month';
$rangeBounds = AnalyticsService::getDateRangeBounds($preset);

// Export Handling
if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
    require_permission('pharmacy.reports.export');

    if ($tab === 'valuation') {
        $stmt = $pdo->query("
            SELECT m.medicine_name, mb.batch_number, mb.expiry_date, mb.quantity_available,
                   mb.purchase_price, (mb.quantity_available * mb.purchase_price) as batch_value,
                   mb.status
            FROM medicine_batches mb
            JOIN medicines m ON mb.medicine_id = m.medicine_id
            WHERE mb.status = 'Active' AND mb.quantity_available > 0
            ORDER BY m.medicine_name ASC
        ");
        $headers = ['Medicine Name', 'Batch Number', 'Expiry Date', 'Available Qty', 'Purchase Rate (₹)', 'Stock Value (₹)', 'Status'];
        $rows = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = [
                $r['medicine_name'],
                $r['batch_number'],
                $r['expiry_date'],
                $r['quantity_available'],
                number_format((float)$r['purchase_price'], 2, '.', ''),
                number_format((float)$r['batch_value'], 2, '.', ''),
                $r['status']
            ];
        }
        ExportService::streamCsvDownload("inventory_valuation_" . date('Ymd'), $headers, $rows);
    } elseif ($tab === 'expiry') {
        $exp = $analyticsService->getExpiryAnalysis();
        $headers = ['Medicine Name', 'Batch No', 'Expiry Date', 'Remaining Qty', 'Purchase Rate (₹)', 'Value at Risk (₹)', 'Supplier', 'Days Remaining'];
        $rows = [];
        foreach ($exp['at_risk_batches'] as $r) {
            $rows[] = [
                $r['medicine_name'],
                $r['batch_number'],
                $r['expiry_date'],
                $r['quantity_available'],
                number_format((float)$r['purchase_price'], 2, '.', ''),
                number_format((float)$r['batch_value'], 2, '.', ''),
                $r['supplier_name'] ?? 'N/A',
                $r['days_remaining']
            ];
        }
        ExportService::streamCsvDownload("expiry_risk_" . date('Ymd'), $headers, $rows);
    } elseif ($tab === 'slow') {
        $slow = $analyticsService->getSlowMovingStock($slowDays);
        $headers = ['Medicine Name', 'Stock Qty', 'Last Sale Date', 'Days Inactive', 'Inventory Value (₹)'];
        $rows = [];
        foreach ($slow as $r) {
            $rows[] = [
                $r['medicine_name'],
                $r['stock_quantity'],
                $r['last_sale_date'] ?? 'Never',
                $r['days_inactive'],
                number_format((float)$r['inventory_value'], 2, '.', '')
            ];
        }
        ExportService::streamCsvDownload("slow_moving_stock_" . date('Ymd'), $headers, $rows);
    } elseif ($tab === 'abc') {
        $abc = $analyticsService->getAbcAnalysis();
        $headers = ['Medicine Name', 'Stock Qty', 'Stock Value (₹)', '% of Total Value', 'Cumulative %', 'ABC Class'];
        $rows = [];
        foreach ($abc['items'] as $r) {
            $rows[] = [
                $r['medicine_name'],
                $r['stock_quantity'],
                number_format((float)$r['value'], 2, '.', ''),
                $r['percentage_of_total'] . '%',
                $r['cumulative_percent'] . '%',
                $r['abc_class']
            ];
        }
        ExportService::streamCsvDownload("abc_analysis_" . date('Ymd'), $headers, $rows);
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
                <i class="ti ti-packages text-emerald me-2"></i>Inventory &amp; Valuation Analytics
            </h4>
            <p class="text-muted small mb-0">Authoritative batch valuation, expiry risk modeling, movement velocity, and ABC classifications.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="?tab=<?= urlencode($tab) ?>&slow_days=<?= $slowDays ?>&export=csv" class="btn btn-sm btn-outline-success">
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
            <a class="nav-link <?= $tab === 'valuation' ? 'active fw-bold text-emerald border-bottom border-emerald border-2' : 'text-muted' ?>" href="?tab=valuation">
                <i class="ti ti-wallet me-1"></i>Inventory Valuation
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'expiry' ? 'active fw-bold text-emerald border-bottom border-emerald border-2' : 'text-muted' ?>" href="?tab=expiry">
                <i class="ti ti-calendar-due me-1"></i>Expiry Risk Buckets
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'slow' ? 'active fw-bold text-emerald border-bottom border-emerald border-2' : 'text-muted' ?>" href="?tab=slow&slow_days=<?= $slowDays ?>">
                <i class="ti ti-hourglass-empty me-1"></i>Slow / Dead Moving
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'fast' ? 'active fw-bold text-emerald border-bottom border-emerald border-2' : 'text-muted' ?>" href="?tab=fast">
                <i class="ti ti-bolt me-1"></i>Fast Moving Velocity
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'abc' ? 'active fw-bold text-emerald border-bottom border-emerald border-2' : 'text-muted' ?>" href="?tab=abc">
                <i class="ti ti-chart-pie me-1"></i>ABC Analysis
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'turnover' ? 'active fw-bold text-emerald border-bottom border-emerald border-2' : 'text-muted' ?>" href="?tab=turnover">
                <i class="ti ti-repeat me-1"></i>Inventory Turnover
            </a>
        </li>
    </ul>

    <!-- TAB 1: Inventory Valuation -->
    <?php if ($tab === 'valuation'): ?>
        <?php
        $valStmt = $pdo->query("
            SELECT 
                COUNT(*) as total_batches,
                COALESCE(SUM(quantity_available), 0) as total_units,
                COALESCE(SUM(quantity_available * purchase_price), 0.00) as total_valuation
            FROM medicine_batches
            WHERE status = 'Active' AND quantity_available > 0
        ");
        $valSummary = $valStmt->fetch(PDO::FETCH_ASSOC);

        $batches = $pdo->query("
            SELECT m.medicine_name, mb.batch_number, mb.expiry_date, mb.quantity_available,
                   mb.purchase_price, (mb.quantity_available * mb.purchase_price) as batch_value,
                   mb.status
            FROM medicine_batches mb
            JOIN medicines m ON mb.medicine_id = m.medicine_id
            WHERE mb.status = 'Active' AND mb.quantity_available > 0
            ORDER BY batch_value DESC
            LIMIT 150
        ")->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="p-3 bg-white rounded-4 border shadow-xs">
                    <div class="text-muted small">Total Active Batches</div>
                    <div class="fs-4 fw-bold text-dark"><?= number_format($valSummary['total_batches']) ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 bg-white rounded-4 border shadow-xs">
                    <div class="text-muted small">Total Available Units</div>
                    <div class="fs-4 fw-bold text-primary"><?= number_format($valSummary['total_units']) ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 bg-white rounded-4 border shadow-xs">
                    <div class="text-muted small">Total Valuation (At Batch Purchase Cost)</div>
                    <div class="fs-4 fw-bold text-emerald">₹<?= number_format((float)$valSummary['total_valuation'], 2) ?></div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-bottom py-3 px-4">
                <h6 class="fw-bold mb-0 text-dark">Active Batches Valuation (Top 150 by Value)</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-uppercase text-muted">
                            <tr>
                                <th>Medicine Name</th>
                                <th>Batch No</th>
                                <th>Expiry Date</th>
                                <th class="text-center">Available Units</th>
                                <th class="text-end">Batch Unit Cost (₹)</th>
                                <th class="text-end">Batch Stock Value (₹)</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($batches as $b): ?>
                                <tr>
                                    <td class="fw-bold"><?= htmlspecialchars($b['medicine_name']) ?></td>
                                    <td class="font-monospace text-primary"><?= htmlspecialchars($b['batch_number']) ?></td>
                                    <td><?= htmlspecialchars($b['expiry_date']) ?></td>
                                    <td class="text-center fw-semibold"><?= number_format($b['quantity_available']) ?></td>
                                    <td class="text-end">₹<?= number_format((float)$b['purchase_price'], 2) ?></td>
                                    <td class="text-end fw-bold text-emerald">₹<?= number_format((float)$b['batch_value'], 2) ?></td>
                                    <td><span class="badge bg-success-subtle text-success"><?= htmlspecialchars($b['status']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <!-- TAB 2: Expiry Risk Buckets -->
    <?php elseif ($tab === 'expiry'): ?>
        <?php $exp = $analyticsService->getExpiryAnalysis(); ?>
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="p-3 bg-warning-subtle rounded-4 border border-warning-subtle">
                    <div class="text-warning-emphasis small fw-semibold">Total Quantity at Risk (&le; 90 Days + Expired)</div>
                    <div class="fs-4 fw-bold text-warning-emphasis"><?= number_format($exp['quantity_at_risk']) ?> Units</div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="p-3 bg-danger-subtle rounded-4 border border-danger-subtle">
                    <div class="text-danger small fw-semibold">Total Value at Risk (At Batch Purchase Cost)</div>
                    <div class="fs-4 fw-bold text-danger">₹<?= number_format($exp['value_at_risk'], 2) ?></div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-bottom py-3 px-4">
                <h6 class="fw-bold mb-0 text-dark">Expiry Aging Buckets</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-uppercase text-muted">
                            <tr>
                                <th>Aging Bucket</th>
                                <th class="text-center">Active Batches</th>
                                <th class="text-center">Units</th>
                                <th class="text-end">Valuation at Risk (₹)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="table-danger">
                                <td class="fw-bold"><i class="ti ti-alert-circle text-danger me-1"></i> Expired Stock</td>
                                <td class="text-center"><?= $exp['summary']['EXPIRED']['batch_count'] ?></td>
                                <td class="text-center fw-bold"><?= number_format($exp['summary']['EXPIRED']['units']) ?></td>
                                <td class="text-end fw-bold">₹<?= number_format($exp['summary']['EXPIRED']['value'], 2) ?></td>
                            </tr>
                            <tr class="table-warning">
                                <td class="fw-bold"><i class="ti ti-clock-alert text-warning me-1"></i> 0 to 30 Days</td>
                                <td class="text-center"><?= $exp['summary']['DAYS_0_30']['batch_count'] ?></td>
                                <td class="text-center fw-bold"><?= number_format($exp['summary']['DAYS_0_30']['units']) ?></td>
                                <td class="text-end fw-bold">₹<?= number_format($exp['summary']['DAYS_0_30']['value'], 2) ?></td>
                            </tr>
                            <tr>
                                <td class="fw-bold">31 to 60 Days</td>
                                <td class="text-center"><?= $exp['summary']['DAYS_31_60']['batch_count'] ?></td>
                                <td class="text-center"><?= number_format($exp['summary']['DAYS_31_60']['units']) ?></td>
                                <td class="text-end">₹<?= number_format($exp['summary']['DAYS_31_60']['value'], 2) ?></td>
                            </tr>
                            <tr>
                                <td class="fw-bold">61 to 90 Days</td>
                                <td class="text-center"><?= $exp['summary']['DAYS_61_90']['batch_count'] ?></td>
                                <td class="text-center"><?= number_format($exp['summary']['DAYS_61_90']['units']) ?></td>
                                <td class="text-end">₹<?= number_format($exp['summary']['DAYS_61_90']['value'], 2) ?></td>
                            </tr>
                            <tr class="table-success">
                                <td class="fw-bold"><i class="ti ti-shield-check text-success me-1"></i> Beyond 90 Days</td>
                                <td class="text-center"><?= $exp['summary']['OVER_90_DAYS']['batch_count'] ?></td>
                                <td class="text-center"><?= number_format($exp['summary']['OVER_90_DAYS']['units']) ?></td>
                                <td class="text-end">₹<?= number_format($exp['summary']['OVER_90_DAYS']['value'], 2) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <!-- TAB 3: Slow / Dead Moving Stock -->
    <?php elseif ($tab === 'slow'): ?>
        <?php $slow = $analyticsService->getSlowMovingStock($slowDays); ?>
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-3">
                <form method="GET" class="d-flex align-items-center gap-2">
                    <input type="hidden" name="tab" value="slow">
                    <label class="small text-muted mb-0">Inactive Threshold:</label>
                    <select name="slow_days" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                        <option value="30" <?= $slowDays === 30 ? 'selected' : '' ?>>30+ Days Inactive</option>
                        <option value="60" <?= $slowDays === 60 ? 'selected' : '' ?>>60+ Days Inactive</option>
                        <option value="90" <?= $slowDays === 90 ? 'selected' : '' ?>>90+ Days Inactive</option>
                        <option value="180" <?= $slowDays === 180 ? 'selected' : '' ?>>180+ Days Inactive</option>
                    </select>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-uppercase text-muted">
                            <tr>
                                <th>Medicine Name</th>
                                <th class="text-center">Stock Units</th>
                                <th>Last Movement / Sale</th>
                                <th class="text-center">Days Inactive</th>
                                <th class="text-end">Tied-Up Valuation (₹)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($slow)): ?>
                                <tr><td colspan="5" class="text-center py-4 text-muted">No medicines meet this inactivity threshold.</td></tr>
                            <?php else: ?>
                                <?php foreach ($slow as $s): ?>
                                    <tr>
                                        <td class="fw-bold"><?= htmlspecialchars($s['medicine_name']) ?></td>
                                        <td class="text-center fw-semibold"><?= (int)$s['stock_quantity'] ?></td>
                                        <td><?= htmlspecialchars($s['last_sale_date'] ?? 'No Sales Recorded') ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-warning-subtle text-warning fw-bold font-monospace"><?= (int)$s['days_inactive'] ?> days</span>
                                        </td>
                                        <td class="text-end fw-bold text-danger">₹<?= number_format((float)$s['inventory_value'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <!-- TAB 4: Fast Moving Stock -->
    <?php elseif ($tab === 'fast'): ?>
        <?php $fast = $analyticsService->getFastMovingMedicines($rangeBounds, 25); ?>
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-uppercase text-muted">
                            <tr>
                                <th>Medicine Name</th>
                                <th>Generic / Formula</th>
                                <th class="text-center">Units Sold</th>
                                <th class="text-center">Transactions</th>
                                <th class="text-end">Revenue Generated (₹)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($fast)): ?>
                                <tr><td colspan="5" class="text-center py-4 text-muted">No fast moving sales in this period.</td></tr>
                            <?php else: ?>
                                <?php foreach ($fast as $f): ?>
                                    <tr>
                                        <td class="fw-bold text-dark"><?= htmlspecialchars($f['medicine_name']) ?></td>
                                        <td class="text-muted small"><?= htmlspecialchars($f['generic_name'] ?? 'N/A') ?></td>
                                        <td class="text-center fw-bold"><span class="badge bg-success"><?= number_format((int)$f['units_sold']) ?></span></td>
                                        <td class="text-center fw-semibold"><?= (int)$f['transaction_count'] ?></td>
                                        <td class="text-end fw-bold text-emerald">₹<?= number_format((float)$f['total_revenue'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <!-- TAB 5: ABC Analysis -->
    <?php elseif ($tab === 'abc'): ?>
        <?php $abc = $analyticsService->getAbcAnalysis(); ?>
        <?php if ($abc['status'] === 'INSUFFICIENT_DATA'): ?>
            <div class="alert alert-warning border-0 rounded-4 p-4">
                <h6 class="fw-bold mb-1"><i class="ti ti-alert-triangle me-2"></i>INSUFFICIENT DATA</h6>
                <p class="mb-0 text-muted"><?= htmlspecialchars($abc['reason']) ?></p>
            </div>
        <?php else: ?>
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="p-3 bg-danger-subtle rounded-4 border border-danger-subtle">
                        <div class="text-danger fw-bold small">Category A (Top 70% Value)</div>
                        <div class="fs-4 fw-bold text-danger"><?= $abc['summary']['A'] ?> Medicines</div>
                        <div class="small text-muted">High value contribution &bull; Strict daily inventory control</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 bg-warning-subtle rounded-4 border border-warning-subtle">
                        <div class="text-warning-emphasis fw-bold small">Category B (Next 20% Value)</div>
                        <div class="fs-4 fw-bold text-warning-emphasis"><?= $abc['summary']['B'] ?> Medicines</div>
                        <div class="small text-muted">Moderate value contribution &bull; Weekly control</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 bg-secondary-subtle rounded-4 border border-secondary-subtle">
                        <div class="text-secondary fw-bold small">Category C (Remaining 10% Value)</div>
                        <div class="fs-4 fw-bold text-secondary"><?= $abc['summary']['C'] ?> Medicines</div>
                        <div class="small text-muted">Bulk/low cost &bull; Monthly periodic review</div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light small text-uppercase text-muted">
                                <tr>
                                    <th>Medicine Name</th>
                                    <th class="text-center">Stock Units</th>
                                    <th class="text-end">Inventory Value (₹)</th>
                                    <th class="text-end">% of Total</th>
                                    <th class="text-end">Cumulative %</th>
                                    <th class="text-center">ABC Class</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($abc['items'] as $item): ?>
                                    <tr>
                                        <td class="fw-bold"><?= htmlspecialchars($item['medicine_name']) ?></td>
                                        <td class="text-center"><?= number_format($item['stock_quantity']) ?></td>
                                        <td class="text-end fw-semibold">₹<?= number_format($item['value'], 2) ?></td>
                                        <td class="text-end"><?= $item['percentage_of_total'] ?>%</td>
                                        <td class="text-end"><?= $item['cumulative_percent'] ?>%</td>
                                        <td class="text-center">
                                            <span class="badge bg-<?= $item['abc_class'] === 'A' ? 'danger' : ($item['abc_class'] === 'B' ? 'warning' : 'secondary') ?>-subtle text-<?= $item['abc_class'] === 'A' ? 'danger' : ($item['abc_class'] === 'B' ? 'warning' : 'secondary') ?> fw-bold font-monospace px-3 py-1">
                                                Class <?= $item['abc_class'] ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    <!-- TAB 6: Inventory Turnover -->
    <?php elseif ($tab === 'turnover'): ?>
        <?php $to = $analyticsService->getInventoryTurnover($rangeBounds); ?>
        <div class="card border-0 shadow-sm rounded-4 p-4">
            <?php if ($to['status'] === 'INSUFFICIENT_DATA'): ?>
                <div class="alert alert-info border-0 rounded-3 mb-0">
                    <h6 class="fw-bold mb-1"><i class="ti ti-info-circle me-2"></i>INSUFFICIENT HISTORICAL BASELINE</h6>
                    <p class="mb-0 text-muted"><?= htmlspecialchars($to['message']) ?></p>
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded-3 border">
                            <div class="text-muted small">Period COGS</div>
                            <div class="fs-4 fw-bold text-dark">₹<?= number_format($to['cogs'], 2) ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded-3 border">
                            <div class="text-muted small">Current Inventory Value</div>
                            <div class="fs-4 fw-bold text-dark">₹<?= number_format($to['current_stock_val'], 2) ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-emerald-subtle rounded-3 border border-emerald-subtle">
                            <div class="text-emerald small fw-semibold">Turnover Ratio</div>
                            <div class="fs-4 fw-bold text-emerald"><?= $to['turnover_ratio'] ?>x</div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php
include __DIR__ . '/../../includes/footer.php';
?>