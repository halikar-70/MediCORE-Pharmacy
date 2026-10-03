<?php
// modules/reports/reconciliation.php - Multi-Dimensional System Reconciliation & Ledger Audit (Chunk 7)

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/ReconciliationEngine.php';
require_once __DIR__ . '/../../app/Services/ExportService.php';

use Pharmacy\Services\ReconciliationEngine;
use Pharmacy\Services\ExportService;

require_permission('pharmacy.reports.view');

$page_title = 'Inventory & Financial Reconciliation';
$reconEngine = new ReconciliationEngine($pdo);

$tab = $_GET['tab'] ?? 'batches';

// Export Handling
if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
    require_permission('pharmacy.reports.export');

    if ($tab === 'batches') {
        $batches = $reconEngine->reconcileBatches();
        $headers = ['Medicine Name', 'Batch Number', 'Status Label', 'Expected Ledger Qty', 'Actual Physical Qty', 'Difference', 'Reconciliation Status'];
        $rows = [];
        foreach ($batches as $b) {
            $rows[] = [
                $b['medicine_name'],
                $b['batch_number'],
                $b['status_label'],
                $b['expected'],
                $b['actual'],
                $b['difference'],
                $b['status']
            ];
        }
        ExportService::streamCsvDownload("batch_reconciliation_" . date('Ymd'), $headers, $rows);
    } elseif ($tab === 'medicines') {
        $meds = $reconEngine->reconcileMedicines();
        $headers = ['Medicine Name', 'Master Stock Qty', 'Active Batch Sum', 'Active Batches Count', 'Variance', 'Reconciliation Status'];
        $rows = [];
        foreach ($meds as $m) {
            $rows[] = [
                $m['medicine_name'],
                $m['master_stock'],
                $m['batch_sum'],
                $m['total_batches'],
                $m['difference'],
                $m['status']
            ];
        }
        ExportService::streamCsvDownload("medicine_master_reconciliation_" . date('Ymd'), $headers, $rows);
    } elseif ($tab === 'ledger_audit') {
        $audit = $reconEngine->auditStockLedger(500);
        $headers = ['Anomaly Type', 'Severity', 'Ledger ID', 'Description', 'Timestamp'];
        $rows = [];
        foreach ($audit as $a) {
            $rows[] = [
                $a['type'],
                $a['severity'],
                $a['ledger_id'],
                $a['description'],
                $a['created_at']
            ];
        }
        ExportService::streamCsvDownload("stock_ledger_audit_" . date('Ymd'), $headers, $rows);
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
                <i class="ti ti-scale text-emerald me-2"></i>Reconciliation &amp; Operational Audit
            </h4>
            <p class="text-muted small mb-0">Read-only mathematical verification comparing physical states, master records, ledger entries, and financial balances.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="?tab=<?= urlencode($tab) ?>&export=csv" class="btn btn-sm btn-outline-success">
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
            <a class="nav-link <?= $tab === 'batches' ? 'active fw-bold text-emerald border-bottom border-emerald border-2' : 'text-muted' ?>" href="?tab=batches">
                <i class="ti ti-box me-1"></i>Batch &amp; Ledger
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'medicines' ? 'active fw-bold text-emerald border-bottom border-emerald border-2' : 'text-muted' ?>" href="?tab=medicines">
                <i class="ti ti-pill me-1"></i>Medicine Master vs Batches
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'financials' ? 'active fw-bold text-emerald border-bottom border-emerald border-2' : 'text-muted' ?>" href="?tab=financials">
                <i class="ti ti-cash me-1"></i>Financial Balancing
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'returns' ? 'active fw-bold text-emerald border-bottom border-emerald border-2' : 'text-muted' ?>" href="?tab=returns">
                <i class="ti ti-arrow-back-up me-1"></i>Return Invariants
            </a>
        </li>

        <li class="nav-item">
            <a class="nav-link <?= $tab === 'ledger_audit' ? 'active fw-bold text-emerald border-bottom border-emerald border-2' : 'text-muted' ?>" href="?tab=ledger_audit">
                <i class="ti ti-shield-search me-1"></i>Stock Ledger Audit
            </a>
        </li>
    </ul>

    <!-- TAB 1: Batch & Ledger Reconciliation -->
    <?php if ($tab === 'batches'): ?>
        <?php 
        $batches = $reconEngine->reconcileBatches();
        $matchCount = 0; $mismatchCount = 0; $noDataCount = 0;
        foreach ($batches as $b) {
            if ($b['status'] === 'MATCH') $matchCount++;
            elseif ($b['status'] === 'MISMATCH') $mismatchCount++;
            else $noDataCount++;
        }
        ?>
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="p-3 bg-success-subtle rounded-4 border border-success-subtle">
                    <div class="text-success small fw-semibold">Batches Perfectly Reconciled</div>
                    <div class="fs-4 fw-bold text-success"><?= $matchCount ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 bg-danger-subtle rounded-4 border border-danger-subtle">
                    <div class="text-danger small fw-semibold">Discrepancies Detected</div>
                    <div class="fs-4 fw-bold text-danger"><?= $mismatchCount ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 bg-secondary-subtle rounded-4 border border-secondary-subtle">
                    <div class="text-secondary small fw-semibold">Legacy / Insufficient Ledger History</div>
                    <div class="fs-4 fw-bold text-secondary"><?= $noDataCount ?></div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-uppercase text-muted">
                            <tr>
                                <th>Medicine</th>
                                <th>Batch No</th>
                                <th>Status</th>
                                <th class="text-center">Ledger Net (Expected)</th>
                                <th class="text-center">Physical Qty (Actual)</th>
                                <th class="text-center">Variance</th>
                                <th class="text-center">Reconciliation Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($batches as $b): ?>
                                <tr>
                                    <td class="fw-bold"><?= htmlspecialchars($b['medicine_name']) ?></td>
                                    <td class="font-monospace text-primary"><?= htmlspecialchars($b['batch_number']) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($b['status_label']) ?></span></td>
                                    <td class="text-center"><?= $b['expected'] ?></td>
                                    <td class="text-center fw-bold"><?= $b['actual'] ?></td>
                                    <td class="text-center font-monospace fw-bold <?= $b['difference'] === 0 ? 'text-success' : 'text-danger' ?>">
                                        <?= $b['difference'] > 0 ? '+' . $b['difference'] : $b['difference'] ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-<?= $b['status'] === 'MATCH' ? 'success' : ($b['status'] === 'MISMATCH' ? 'danger' : 'secondary') ?>-subtle text-<?= $b['status'] === 'MATCH' ? 'success' : ($b['status'] === 'MISMATCH' ? 'danger' : 'secondary') ?> fw-bold font-monospace">
                                            <?= htmlspecialchars($b['status']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <!-- TAB 2: Medicine Master vs Batches -->
    <?php elseif ($tab === 'medicines'): ?>
        <?php 
        $meds = $reconEngine->reconcileMedicines(); 
        $medMatches = 0; $medMismatches = 0;
        foreach ($meds as $m) {
            if ($m['status'] === 'MATCH') $medMatches++;
            else $medMismatches++;
        }
        ?>
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="p-3 bg-success-subtle rounded-4 border border-success-subtle">
                    <div class="text-success small fw-semibold">Master Records in Sync</div>
                    <div class="fs-4 fw-bold text-success"><?= $medMatches ?> Medicines</div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="p-3 bg-<?= $medMismatches > 0 ? 'danger' : 'secondary' ?>-subtle rounded-4 border border-<?= $medMismatches > 0 ? 'danger' : 'secondary' ?>-subtle">
                    <div class="text-<?= $medMismatches > 0 ? 'danger' : 'secondary' ?> small fw-semibold">Master vs Batch Discrepancies</div>
                    <div class="fs-4 fw-bold text-<?= $medMismatches > 0 ? 'danger' : 'secondary' ?>"><?= $medMismatches ?> Medicines</div>
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
                                <th class="text-center">Master Stock</th>
                                <th class="text-center">Active Batches Sum</th>
                                <th class="text-center">Active Batches</th>
                                <th class="text-center">Difference</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($meds as $m): ?>
                                <tr>
                                    <td class="fw-bold"><?= htmlspecialchars($m['medicine_name']) ?></td>
                                    <td class="text-center"><?= $m['master_stock'] ?></td>
                                    <td class="text-center fw-bold"><?= $m['batch_sum'] ?></td>
                                    <td class="text-center"><?= $m['total_batches'] ?></td>
                                    <td class="text-center font-monospace fw-bold <?= $m['difference'] === 0 ? 'text-success' : 'text-danger' ?>">
                                        <?= $m['difference'] > 0 ? '+' . $m['difference'] : $m['difference'] ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-<?= $m['status'] === 'MATCH' ? 'success' : 'danger' ?>-subtle text-<?= $m['status'] === 'MATCH' ? 'success' : 'danger' ?> fw-bold font-monospace">
                                            <?= htmlspecialchars($m['status']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <!-- TAB 3: Financial Balancing -->
    <?php elseif ($tab === 'financials'): ?>
        <?php $fin = $reconEngine->reconcileFinancials(); ?>
        <div class="row g-4 mb-4">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm rounded-4 h-100">
                    <div class="card-header bg-white border-bottom py-3 px-4">
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="ti ti-shopping-cart text-primary me-2"></i>Sales Financial Balancing
                        </h6>
                    </div>
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span>Total Sales Checked:</span>
                            <span class="fw-bold"><?= $fin['sales_math']['total_checked'] ?></span>
                        </div>
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span>Arithmetic Discrepancies:</span>
                            <span class="badge bg-<?= $fin['sales_math']['arithmetic_mismatches'] === 0 ? 'success' : 'danger' ?>-subtle text-<?= $fin['sales_math']['arithmetic_mismatches'] === 0 ? 'success' : 'danger' ?>">
                                <?= $fin['sales_math']['arithmetic_mismatches'] ?>
                            </span>
                        </div>
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span>Paid + Due vs Net Discrepancies:</span>
                            <span class="badge bg-<?= $fin['sales_math']['balance_mismatches'] === 0 ? 'success' : 'danger' ?>-subtle text-<?= $fin['sales_math']['balance_mismatches'] === 0 ? 'success' : 'danger' ?>">
                                <?= $fin['sales_math']['balance_mismatches'] ?>
                            </span>
                        </div>
                        <div class="d-flex justify-content-between py-2 mt-2">
                            <span class="fw-bold">Sales Mathematical Status:</span>
                            <span class="badge bg-<?= $fin['sales_math']['status'] === 'MATCH' ? 'success' : 'danger' ?> fw-bold font-monospace">
                                <?= $fin['sales_math']['status'] ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card border-0 shadow-sm rounded-4 h-100">
                    <div class="card-header bg-white border-bottom py-3 px-4">
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="ti ti-truck-loading text-info me-2"></i>Purchases Financial Balancing
                        </h6>
                    </div>
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span>Total Bills Checked:</span>
                            <span class="fw-bold"><?= $fin['purchases_math']['total_checked'] ?></span>
                        </div>
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span>Arithmetic Discrepancies:</span>
                            <span class="badge bg-<?= $fin['purchases_math']['arithmetic_mismatches'] === 0 ? 'success' : 'danger' ?>-subtle text-<?= $fin['purchases_math']['arithmetic_mismatches'] === 0 ? 'success' : 'danger' ?>">
                                <?= $fin['purchases_math']['arithmetic_mismatches'] ?>
                            </span>
                        </div>
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span>Paid + Due vs Grand Total Discrepancies:</span>
                            <span class="badge bg-<?= $fin['purchases_math']['balance_mismatches'] === 0 ? 'success' : 'danger' ?>-subtle text-<?= $fin['purchases_math']['balance_mismatches'] === 0 ? 'success' : 'danger' ?>">
                                <?= $fin['purchases_math']['balance_mismatches'] ?>
                            </span>
                        </div>
                        <div class="d-flex justify-content-between py-2 mt-2">
                            <span class="fw-bold">Purchases Mathematical Status:</span>
                            <span class="badge bg-<?= $fin['purchases_math']['status'] === 'MATCH' ? 'success' : 'danger' ?> fw-bold font-monospace">
                                <?= $fin['purchases_math']['status'] ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <!-- TAB 4: Return Invariants -->
    <?php elseif ($tab === 'returns'): ?>
        <?php $ret = $reconEngine->reconcileReturns(); ?>
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark">Return Invariants Validation (Returned &le; Original Sold / Received)</h6>
                <span class="badge bg-<?= $ret['status'] === 'MATCH' ? 'success' : 'danger' ?> font-monospace">
                    STATUS: <?= $ret['status'] ?>
                </span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($ret['mismatches'])): ?>
                    <div class="p-5 text-center text-success">
                        <i class="ti ti-circle-check fs-1 mb-2"></i>
                        <h6 class="fw-bold">No Return Invariant Violations Detected</h6>
                        <p class="text-muted small mb-0">All processed sales returns and purchase returns strictly respect original quantity bounds.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light small text-uppercase text-muted">
                                <tr>
                                    <th>Violation Type</th>
                                    <th>Reference</th>
                                    <th>Medicine</th>
                                    <th class="text-center">Returned Qty</th>
                                    <th class="text-center">Original Qty</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ret['mismatches'] as $m): ?>
                                    <tr class="table-danger">
                                        <td class="fw-bold"><code><?= htmlspecialchars($m['type']) ?></code></td>
                                        <td><?= htmlspecialchars($m['reference']) ?></td>
                                        <td><?= htmlspecialchars($m['medicine']) ?></td>
                                        <td class="text-center fw-bold text-danger"><?= $m['returned_qty'] ?></td>
                                        <td class="text-center"><?= $m['original_qty'] ?></td>
                                        <td><span class="badge bg-danger"><?= $m['status'] ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>



    <!-- TAB 6: Stock Ledger Audit -->
    <?php elseif ($tab === 'ledger_audit'): ?>
        <?php $anomalies = $reconEngine->auditStockLedger(100); ?>
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark">Stock Ledger Mathematical &amp; Structural Anomaly Audit</h6>
                <span class="badge bg-<?= empty($anomalies) ? 'success' : 'warning' ?> font-monospace">
                    ANOMALIES FOUND: <?= count($anomalies) ?>
                </span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($anomalies)): ?>
                    <div class="p-5 text-center text-success">
                        <i class="ti ti-circle-check fs-1 mb-2"></i>
                        <h6 class="fw-bold">Stock Ledger Structure Clean</h6>
                        <p class="text-muted small mb-0">No orphaned records, negative balances, or structural violations detected in the append-only ledger.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light small text-uppercase text-muted">
                                <tr>
                                    <th>Severity</th>
                                    <th>Anomaly Type</th>
                                    <th>Ledger ID</th>
                                    <th>Description</th>
                                    <th>Timestamp</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($anomalies as $a): ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-<?= $a['severity'] === 'CRITICAL' ? 'danger' : ($a['severity'] === 'HIGH' ? 'warning' : 'info') ?>-subtle text-<?= $a['severity'] === 'CRITICAL' ? 'danger' : ($a['severity'] === 'HIGH' ? 'warning' : 'info') ?>">
                                                <?= $a['severity'] ?>
                                            </span>
                                        </td>
                                        <td><code><?= htmlspecialchars($a['type']) ?></code></td>
                                        <td class="font-monospace">#<?= (int)$a['ledger_id'] ?></td>
                                        <td><?= htmlspecialchars($a['description']) ?></td>
                                        <td class="small text-muted"><?= htmlspecialchars($a['created_at']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
