<?php
// modules/inventory/disposals.php - Authorized Medicine Destruction & Disposal Register
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/DocumentSequenceService.php';
require_once __DIR__ . '/../../app/Services/StockLedgerService.php';
require_once __DIR__ . '/../../app/Services/AuditService.php';
require_once __DIR__ . '/../../app/Services/StockLifecycleService.php';

require_permission('pharmacy.disposal.view');

use Pharmacy\Services\StockLifecycleService;
use Pharmacy\Auth\AuthManager;

$lifecycleService = new StockLifecycleService($pdo);
$page_title = 'Disposal Register';

$errors = [];
$successMessage = null;

// Handle Disposal Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_disposal') {
    verify_csrf();
    require_permission('pharmacy.disposal.create');

    $bId = (int)($_POST['batch_id'] ?? 0);
    $qty = (int)($_POST['quantity'] ?? 0);
    $reason = trim($_POST['reason'] ?? 'EXPIRED');
    $method = trim($_POST['disposal_method'] ?? 'INCINERATION');
    $witness = trim($_POST['witness_name'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $idempotencyKey = trim($_POST['idempotency_key'] ?? ('DISP_POST_' . uniqid('', true)));

    if ($bId <= 0 || $qty <= 0) {
        $errors[] = "Please select a valid batch and specify quantity > 0.";
    } else {
        try {
            $userId = AuthManager::userId() ?? 1;
            $res = $lifecycleService->recordDisposal([
                'batch_id'        => $bId,
                'quantity'        => $qty,
                'reason'          => $reason,
                'disposal_method' => $method,
                'witness_name'    => $witness,
                'notes'           => $notes,
                'idempotency_key' => $idempotencyKey
            ], $userId);

            $successMessage = "Medicine destruction recorded successfully under Certificate #{$res['disposal_no']} for {$res['quantity']} units.";
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }
}

// Summary Metrics
$mStmt = $pdo->query("
    SELECT 
        COUNT(disposal_id) AS total_events,
        COALESCE(SUM(quantity), 0) AS total_units_destroyed,
        SUM(CASE WHEN disposal_method = 'INCINERATION' THEN quantity ELSE 0 END) AS incinerated_units,
        SUM(CASE WHEN reason = 'EXPIRED' THEN quantity ELSE 0 END) AS expired_units
    FROM pharmacy_disposals
    WHERE status != 'CANCELLED'
");
$metrics = $mStmt->fetch(PDO::FETCH_ASSOC);

// Disposals List
$dStmt = $pdo->query("
    SELECT d.*, m.medicine_name, mb.batch_number, mb.expiry_date,
           u.username AS executed_by_username
    FROM pharmacy_disposals d
    JOIN medicines m ON d.medicine_id = m.medicine_id
    JOIN medicine_batches mb ON d.batch_id = mb.batch_id
    LEFT JOIN pharmacy_users u ON d.executed_by = u.id
    ORDER BY d.disposal_id DESC LIMIT 50
");
$disposals = $dStmt->fetchAll(PDO::FETCH_ASSOC);

// Batches with non-zero stock (available, quarantined, or damaged)
$bListStmt = $pdo->query("
    SELECT mb.batch_id, mb.batch_number, mb.expiry_date, mb.quantity_available,
           mb.quarantined_quantity, mb.damaged_quantity, m.medicine_name
    FROM medicine_batches mb
    JOIN medicines m ON mb.medicine_id = m.medicine_id
    WHERE (mb.quantity_available > 0 OR mb.quarantined_quantity > 0 OR mb.damaged_quantity > 0)
    ORDER BY m.medicine_name ASC, mb.expiry_date ASC
");
$candidateBatches = $bListStmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="container-fluid px-4 py-3">
    <!-- Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h4 class="fw-bold text-dark mb-1">
                <i class="ti ti-trash text-dark me-2"></i>Pharmaceutical Disposal & Destruction Register
            </h4>
            <p class="text-muted small mb-0">Authorized condemned medicine destruction, incineration logs, and regulatory compliance certificates.</p>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-dark rounded-pill px-3 py-2 small shadow-sm" data-bs-toggle="modal" data-bs-target="#modalNewDisposal">
                <i class="ti ti-plus me-1"></i> Record Stock Disposal
            </button>
            <a href="quarantine.php" class="btn btn-outline-danger rounded-pill px-3 py-2 small">
                <i class="ti ti-shield-alert me-1"></i> Quarantine Hub
            </a>
            <a href="expiry.php" class="btn btn-outline-secondary rounded-pill px-3 py-2 small">
                <i class="ti ti-calendar-due me-1"></i> Expiry Alerts
            </a>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-3 shadow-sm mb-4" role="alert">
            <i class="ti ti-alert-circle me-2 fs-5 align-middle"></i>
            <ul class="mb-0 mt-1">
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

    <!-- Metrics -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="p-3 bg-dark-subtle text-dark rounded-circle fs-4">
                        <i class="ti ti-trash"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Disposal Protocols</div>
                        <div class="fw-bold fs-4 text-dark"><?= number_format($metrics['total_events'] ?? 0) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="p-3 bg-danger-subtle text-danger rounded-circle fs-4">
                        <i class="ti ti-flame"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Total Units Destroyed</div>
                        <div class="fw-bold fs-4 text-dark"><?= number_format($metrics['total_units_destroyed'] ?? 0) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="p-3 bg-warning-subtle text-warning rounded-circle fs-4">
                        <i class="ti ti-calendar-x"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Expired Units Destroyed</div>
                        <div class="fw-bold fs-4 text-dark"><?= number_format($metrics['expired_units'] ?? 0) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="p-3 bg-info-subtle text-info rounded-circle fs-4">
                        <i class="ti ti-biohazard"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Incineration Count</div>
                        <div class="fw-bold fs-4 text-dark"><?= number_format($metrics['incinerated_units'] ?? 0) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Disposals Register -->
    <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden mb-5">
        <div class="card-header bg-white py-3 border-bottom">
            <h5 class="fw-bold mb-0 text-dark">
                <i class="ti ti-list me-1 text-dark"></i>Permanent Destruction Register
            </h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light text-muted small text-uppercase">
                    <tr>
                        <th class="ps-4">Disposal No</th>
                        <th>Date</th>
                        <th>Medicine & Batch</th>
                        <th>Expiry Date</th>
                        <th class="text-center">Quantity Destroyed</th>
                        <th>Reason</th>
                        <th>Method</th>
                        <th>Witness / Executor</th>
                        <th class="text-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($disposals)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="ti ti-check fs-1 d-block mb-2 text-success"></i>
                                No permanent medicine destructions recorded.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($disposals as $d): ?>
                            <tr>
                                <td class="ps-4">
                                    <span class="font-monospace fw-bold text-dark bg-light px-2 py-1 rounded">
                                        <?= htmlspecialchars($d['disposal_no']) ?>
                                    </span>
                                </td>
                                <td><?= format_date($d['disposal_date']) ?></td>
                                <td>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($d['medicine_name']) ?></div>
                                    <span class="font-monospace small text-muted">Batch: <?= htmlspecialchars($d['batch_number']) ?></span>
                                </td>
                                <td><?= format_date($d['expiry_date']) ?></td>
                                <td class="text-center fw-bold fs-6 text-danger"><?= number_format($d['quantity']) ?></td>
                                <td>
                                    <span class="badge bg-secondary-subtle text-secondary small"><?= htmlspecialchars($d['reason']) ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-dark-subtle text-dark small font-monospace"><?= htmlspecialchars($d['disposal_method']) ?></span>
                                </td>
                                <td>
                                    <div class="small fw-semibold"><?= htmlspecialchars($d['witness_name'] ?: 'None recorded') ?></div>
                                    <div class="text-muted small">By: <?= htmlspecialchars($d['executed_by_username'] ?? 'Admin') ?></div>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-success-subtle text-success rounded-pill px-3">
                                        <?= htmlspecialchars($d['status']) ?>
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

<!-- Modal: New Disposal -->
<div class="modal fade" id="modalNewDisposal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-dark text-white border-0 py-3">
                <h5 class="modal-title fw-bold"><i class="ti ti-trash me-2"></i>Authorized Medicine Disposal Protocol</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="record_disposal">
                <input type="hidden" name="idempotency_key" value="DISP_POST_<?= uniqid('', true) ?>">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Select Batch to Destroy</label>
                        <select name="batch_id" class="form-select" required>
                            <option value="">-- Choose Batch --</option>
                            <?php foreach ($candidateBatches as $b): ?>
                                <option value="<?= $b['batch_id'] ?>">
                                    <?= htmlspecialchars($b['medicine_name']) ?> | Batch: <?= htmlspecialchars($b['batch_number']) ?> (Avail: <?= $b['quantity_available'] ?>, Qrn: <?= $b['quarantined_quantity'] ?>, Dmg: <?= $b['damaged_quantity'] ?>, Exp: <?= $b['expiry_date'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Quantity to Destroy</label>
                        <input type="number" name="quantity" class="form-control" min="1" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Destruction Reason</label>
                            <select name="reason" class="form-select" required>
                                <option value="EXPIRED">Expired Shelf Life</option>
                                <option value="DAMAGED">Physical Damage / Leakage</option>
                                <option value="CONTAMINATED">Contamination / Compromised</option>
                                <option value="RECALLED">Regulatory / Supplier Recall</option>
                                <option value="QUARANTINE_REJECTED">Failed Quarantine Inspection</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Disposal Method</label>
                            <select name="disposal_method" class="form-select" required>
                                <option value="INCINERATION">High-Temp Incineration</option>
                                <option value="CHEMICAL_DESTRUCTION">Chemical Inactivation</option>
                                <option value="SECURE_LANDFILL">Encapsulation & Landfill</option>
                                <option value="SUPPLIER_TAKEBACK">Supplier Takeback / Reverse</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Witness Name (Pharmacist / QA Officer)</label>
                        <input type="text" name="witness_name" class="form-control" placeholder="Witness full name and credentials...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Destruction Memo & Certificate Reference</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Destruction manifest notes..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-dark rounded-pill px-4" onclick="return confirm('WARNING: This permanently removes stock from inventory and ledger. Confirm destruction?');">
                        Confirm Disposal
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
