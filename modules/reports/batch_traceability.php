<?php
// modules/reports/batch_traceability.php - 360-degree Pharmaceutical Batch Traceability Report
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/DocumentSequenceService.php';
require_once __DIR__ . '/../../app/Services/StockLedgerService.php';
require_once __DIR__ . '/../../app/Services/AuditService.php';
require_once __DIR__ . '/../../app/Services/StockLifecycleService.php';

require_permission('pharmacy.reports.view');

use Pharmacy\Services\StockLifecycleService;

$lifecycleService = new StockLifecycleService($pdo);
$page_title = 'Batch Traceability Report';

$selectedBatchId = (int)($_GET['batch_id'] ?? 0);
$lifecycleData = null;
$error = null;

if ($selectedBatchId > 0) {
    try {
        $lifecycleData = $lifecycleService->getBatchLifecycle($selectedBatchId);
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Fetch all batches for selector dropdown
$bListStmt = $pdo->query("
    SELECT mb.batch_id, mb.batch_number, mb.expiry_date, m.medicine_name
    FROM medicine_batches mb
    JOIN medicines m ON mb.medicine_id = m.medicine_id
    ORDER BY m.medicine_name ASC, mb.expiry_date DESC
    LIMIT 100
");
$batchesList = $bListStmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="container-fluid px-4 py-3">
    <!-- Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h4 class="fw-bold text-dark mb-1">
                <i class="ti ti-route text-primary me-2"></i>End-to-End Batch Traceability Audit
            </h4>
            <p class="text-muted small mb-0">Track complete batch lifecycle from procurement & inwarding to sales, returns, quarantines, and destruction.</p>
        </div>
        <?php if ($lifecycleData): ?>
            <button type="button" class="btn btn-outline-secondary rounded-pill px-3 py-2 small" onclick="window.print();">
                <i class="ti ti-printer me-1"></i> Print Report
            </button>
        <?php endif; ?>
    </div>

    <!-- Batch Selector Form -->
    <div class="card border-0 shadow-sm rounded-4 p-3 bg-white mb-4">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-9">
                <label class="form-label small fw-bold text-muted mb-1">Select Pharmaceutical Batch to Audit</label>
                <select name="batch_id" class="form-select" onchange="this.form.submit()">
                    <option value="">-- Choose a Medicine Batch --</option>
                    <?php foreach ($batchesList as $b): ?>
                        <option value="<?= $b['batch_id'] ?>" <?= $b['batch_id'] == $selectedBatchId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($b['medicine_name']) ?> | Batch: <?= htmlspecialchars($b['batch_number']) ?> (Exp: <?= $b['expiry_date'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary rounded-pill w-100 py-2">
                    <i class="ti ti-search me-1"></i> Trace Batch
                </button>
            </div>
        </form>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger rounded-3 shadow-sm mb-4">
            <i class="ti ti-alert-circle me-2"></i><?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($lifecycleData): ?>
        <?php $b = $lifecycleData['batch']; ?>
        <!-- Batch Master Dossier Card -->
        <div class="card border-0 shadow-sm rounded-4 p-4 bg-white mb-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center border-bottom pb-3 mb-3">
                <div>
                    <h5 class="fw-bold text-dark mb-1"><?= htmlspecialchars($b['medicine_name']) ?></h5>
                    <div class="text-muted small">Generic: <?= htmlspecialchars($b['generic_name'] ?? 'N/A') ?> | Dosage: <?= htmlspecialchars($b['dosage_form'] ?? 'N/A') ?> <?= htmlspecialchars($b['strength'] ?? '') ?></div>
                </div>
                <div class="text-end">
                    <span class="badge bg-primary-subtle text-primary fs-6 px-3 py-1 rounded-pill font-monospace">Batch: <?= htmlspecialchars($b['batch_number']) ?></span>
                    <div class="small text-muted mt-1">Status: <span class="fw-bold text-dark"><?= htmlspecialchars($b['status']) ?></span></div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-6 col-md-3">
                    <div class="text-muted small">Expiry Date</div>
                    <div class="fw-bold fs-6 <?= strtotime($b['expiry_date']) < time() ? 'text-danger' : 'text-dark' ?>">
                        <?= format_date($b['expiry_date']) ?>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-muted small">Supplier / Source</div>
                    <div class="fw-bold text-dark"><?= htmlspecialchars($b['supplier_name'] ?? 'Direct / Opening Stock') ?></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-muted small">Purchase Price / MRP</div>
                    <div class="fw-bold text-dark"><?= format_currency($b['purchase_price']) ?> / <?= format_currency($b['mrp']) ?></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-muted small">Shelf / Rack</div>
                    <div class="fw-bold text-dark"><?= htmlspecialchars($b['shelf_location'] ?: 'Unassigned') ?></div>
                </div>
            </div>

            <hr class="my-3 text-muted">

            <!-- Stock Breakdown -->
            <div class="row g-3 text-center">
                <div class="col-4 col-md-2">
                    <div class="text-muted small">Received</div>
                    <div class="fw-bold fs-5 text-dark"><?= number_format($b['quantity_received']) ?></div>
                </div>
                <div class="col-4 col-md-2">
                    <div class="text-muted small">Available</div>
                    <div class="fw-bold fs-5 text-success"><?= number_format($b['quantity_available']) ?></div>
                </div>
                <div class="col-4 col-md-2">
                    <div class="text-muted small">Reserved</div>
                    <div class="fw-bold fs-5 text-warning"><?= number_format($b['reserved_quantity']) ?></div>
                </div>
                <div class="col-4 col-md-2">
                    <div class="text-muted small">Quarantined</div>
                    <div class="fw-bold fs-5 text-danger"><?= number_format($b['quarantined_quantity']) ?></div>
                </div>
                <div class="col-4 col-md-2">
                    <div class="text-muted small">Damaged</div>
                    <div class="fw-bold fs-5 text-secondary"><?= number_format($b['damaged_quantity']) ?></div>
                </div>
                <div class="col-4 col-md-2">
                    <div class="text-muted small">Disposed</div>
                    <div class="fw-bold fs-5 text-dark"><?= number_format($b['disposed_quantity']) ?></div>
                </div>
            </div>
        </div>

        <!-- Section 1: Immutable Stock Ledger Movements -->
        <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden mb-4">
            <div class="card-header bg-white py-3 border-bottom">
                <h6 class="fw-bold text-dark mb-0"><i class="ti ti-book-2 me-2 text-primary"></i>1. Stock Ledger Movements (Append-Only Audit Trail)</h6>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="bg-light text-muted text-uppercase">
                        <tr>
                            <th class="ps-4">Timestamp</th>
                            <th>Transaction Type</th>
                            <th>Reference Doc</th>
                            <th class="text-center">Quantity Delta</th>
                            <th class="text-center">Balance Before</th>
                            <th class="text-center">Balance After</th>
                            <th>Cost / Price</th>
                            <th>Reason / Narrative</th>
                            <th class="pe-4">User</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($lifecycleData['ledger_entries'])): ?>
                            <tr><td colspan="9" class="text-center py-3 text-muted">No ledger records found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($lifecycleData['ledger_entries'] as $l): ?>
                                <tr>
                                    <td class="ps-4 font-monospace"><?= htmlspecialchars($l['created_at']) ?></td>
                                    <td><span class="badge bg-secondary-subtle text-secondary font-monospace"><?= htmlspecialchars($l['transaction_type']) ?></span></td>
                                    <td><?= htmlspecialchars($l['reference_no'] ?? '-') ?></td>
                                    <td class="text-center fw-bold <?= $l['quantity_change'] > 0 ? 'text-success' : 'text-danger' ?>">
                                        <?= ($l['quantity_change'] > 0 ? '+' : '') . $l['quantity_change'] ?>
                                    </td>
                                    <td class="text-center"><?= $l['balance_before'] ?></td>
                                    <td class="text-center fw-bold"><?= $l['balance_after'] ?></td>
                                    <td><?= format_currency($l['unit_cost']) ?> / <?= format_currency($l['unit_price']) ?></td>
                                    <td><?= htmlspecialchars($l['reason'] ?? '-') ?></td>
                                    <td class="pe-4 text-muted"><?= htmlspecialchars($l['performed_by_user'] ?? 'System') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Section 2: Dispensed Sales -->
        <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden mb-4">
            <div class="card-header bg-white py-3 border-bottom">
                <h6 class="fw-bold text-dark mb-0"><i class="ti ti-shopping-cart me-2 text-success"></i>2. Dispensed Sales</h6>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="bg-light text-muted text-uppercase">
                        <tr>
                            <th class="ps-4">Sale No</th>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Customer / Patient</th>
                            <th class="text-center">Dispensed Qty</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-center pe-4">Sale Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($lifecycleData['sales'])): ?>
                            <tr><td colspan="7" class="text-center py-3 text-muted">No sales dispensed from this batch.</td></tr>
                        <?php else: ?>
                            <?php foreach ($lifecycleData['sales'] as $s): ?>
                                <tr>
                                    <td class="ps-4 font-monospace fw-bold text-dark"><?= htmlspecialchars($s['sale_number']) ?></td>
                                    <td><?= format_date($s['sale_date']) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($s['sale_type']) ?></span></td>
                                    <td><?= htmlspecialchars($s['customer_name'] ?: 'Walk-in') ?></td>
                                    <td class="text-center fw-bold text-danger"><?= $s['allocated_quantity'] ?></td>
                                    <td class="text-end"><?= format_currency($s['unit_price']) ?></td>
                                    <td class="text-center pe-4">
                                        <span class="badge bg-success-subtle text-success rounded-pill px-2"><?= htmlspecialchars($s['sale_status']) ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Section 3: Reverse Logistics (Returns, Quarantine & Disposals) -->
        <div class="row g-3 mb-5">
            <!-- Sales Returns -->
            <div class="col-md-6">
                <div class="card border-0 shadow-sm rounded-4 bg-white h-100 overflow-hidden">
                    <div class="card-header bg-white py-3 border-bottom">
                        <h6 class="fw-bold text-dark mb-0"><i class="ti ti-arrow-back-up text-info me-2"></i>Customer Sales Returns</h6>
                    </div>
                    <div class="p-3">
                        <?php if (empty($lifecycleData['sales_returns'])): ?>
                            <div class="text-muted text-center py-3 small">No customer returns for this batch.</div>
                        <?php else: ?>
                            <ul class="list-group list-group-flush small">
                                <?php foreach ($lifecycleData['sales_returns'] as $sr): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-2">
                                        <div>
                                            <span class="fw-bold font-monospace"><?= htmlspecialchars($sr['return_number']) ?></span>
                                            <span class="text-muted ms-2">(Sale: <?= htmlspecialchars($sr['sale_number']) ?>)</span>
                                            <div class="text-muted">Action: <?= htmlspecialchars($sr['restock_decision']) ?></div>
                                        </div>
                                        <div class="text-end">
                                            <div class="fw-bold text-success">+<?= $sr['return_quantity'] ?> units</div>
                                            <div class="text-muted"><?= format_currency($sr['refund_amount']) ?></div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Disposals & Quarantine -->
            <div class="col-md-6">
                <div class="card border-0 shadow-sm rounded-4 bg-white h-100 overflow-hidden">
                    <div class="card-header bg-white py-3 border-bottom">
                        <h6 class="fw-bold text-dark mb-0"><i class="ti ti-trash text-danger me-2"></i>Quarantine & Destruction Events</h6>
                    </div>
                    <div class="p-3">
                        <?php if (empty($lifecycleData['quarantines']) && empty($lifecycleData['disposals'])): ?>
                            <div class="text-muted text-center py-3 small">No quarantine or destruction records.</div>
                        <?php else: ?>
                            <ul class="list-group list-group-flush small">
                                <?php foreach ($lifecycleData['quarantines'] as $qr): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-2">
                                        <div>
                                            <span class="badge bg-warning-subtle text-warning font-monospace"><?= htmlspecialchars($qr['quarantine_no']) ?></span>
                                            <span class="text-muted ms-1"><?= htmlspecialchars($qr['reason']) ?></span>
                                        </div>
                                        <div class="text-end fw-bold text-warning"><?= $qr['quantity'] ?> units (<?= htmlspecialchars($qr['status']) ?>)</div>
                                    </li>
                                <?php endforeach; ?>
                                <?php foreach ($lifecycleData['disposals'] as $dp): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-2">
                                        <div>
                                            <span class="badge bg-dark-subtle text-dark font-monospace"><?= htmlspecialchars($dp['disposal_no']) ?></span>
                                            <span class="text-muted ms-1"><?= htmlspecialchars($dp['disposal_method']) ?></span>
                                        </div>
                                        <div class="text-end fw-bold text-danger"><?= $dp['quantity'] ?> units destroyed</div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    <?php else: ?>
        <div class="card border-0 shadow-sm rounded-4 p-5 text-center bg-white">
            <i class="ti ti-route fs-1 d-block mb-3 text-muted"></i>
            <h5 class="fw-bold text-dark">Select a Batch Above to Generate Full Traceability Audit</h5>
            <p class="text-muted small mx-auto" style="max-width: 450px;">
                Inspect complete provenance, inventory transitions, and dispensing records for any batch in the MediPro Pharmacy database.
            </p>
        </div>
    <?php endif; ?>
</div>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
