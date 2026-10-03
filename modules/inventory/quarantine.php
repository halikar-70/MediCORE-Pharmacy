<?php
// modules/inventory/quarantine.php - Batch Quarantine & Inspection Hub
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/DocumentSequenceService.php';
require_once __DIR__ . '/../../app/Services/StockLedgerService.php';
require_once __DIR__ . '/../../app/Services/AuditService.php';
require_once __DIR__ . '/../../app/Services/StockLifecycleService.php';

require_permission('pharmacy.quarantine.view');

use Pharmacy\Services\StockLifecycleService;
use Pharmacy\Auth\AuthManager;

$lifecycleService = new StockLifecycleService($pdo);
$page_title = 'Quarantine Hub';

$errors = [];
$successMessage = null;

// Handle Quarantine Creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quarantine_stock') {
    verify_csrf();
    $bId = (int)($_POST['batch_id'] ?? 0);
    $qty = (int)($_POST['quantity'] ?? 0);
    $reason = trim($_POST['reason'] ?? 'OTHER');
    $notes = trim($_POST['notes'] ?? '');

    if ($bId <= 0 || $qty <= 0) {
        $errors[] = "Please select a valid batch and specify quantity > 0.";
    } else {
        try {
            $userId = AuthManager::userId() ?? 1;
            $res = $lifecycleService->quarantineStock($bId, $qty, $reason, $notes, $userId);
            $successMessage = "Stock quarantined successfully under #{$res['quarantine_no']}.";
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }
}

// Handle Quarantine Decision / Release
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'release_quarantine') {
    verify_csrf();
    require_permission('pharmacy.quarantine.release');

    $qId = (int)($_POST['quarantine_id'] ?? 0);
    $decision = trim($_POST['decision'] ?? 'RELEASE_TO_STOCK');
    $notes = trim($_POST['notes'] ?? '');

    try {
        $userId = AuthManager::userId() ?? 1;
        $res = $lifecycleService->releaseQuarantine($qId, $decision, $notes, $userId);
        $successMessage = "Quarantine record updated successfully: {$res['decision']}.";
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }
}

// Active Tab
$tab = trim($_GET['tab'] ?? 'QUARANTINED');
if (!in_array($tab, ['QUARANTINED', 'RELEASED_TO_STOCK', 'SENT_TO_DISPOSAL', 'ALL'], true)) {
    $tab = 'QUARANTINED';
}

// Metrics
$mStmt = $pdo->query("
    SELECT 
        SUM(CASE WHEN status = 'QUARANTINED' THEN 1 ELSE 0 END) AS active_lots,
        SUM(CASE WHEN status = 'QUARANTINED' THEN quantity ELSE 0 END) AS active_units,
        SUM(CASE WHEN status = 'RELEASED_TO_STOCK' THEN 1 ELSE 0 END) AS released_lots,
        SUM(CASE WHEN status = 'SENT_TO_DISPOSAL' THEN 1 ELSE 0 END) AS disposed_lots
    FROM pharmacy_quarantine_records
");
$metrics = $mStmt->fetch(PDO::FETCH_ASSOC);

// Fetch Quarantine Records
$where = [];
$params = [];
if ($tab !== 'ALL') {
    $where[] = "qr.status = ?";
    $params[] = $tab;
}
$whereClause = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";

$qSql = "
    SELECT qr.*, m.medicine_name, m.generic_name, mb.batch_number, mb.expiry_date,
           u1.username AS created_by_username, u2.username AS released_by_username
    FROM pharmacy_quarantine_records qr
    JOIN medicines m ON qr.medicine_id = m.medicine_id
    JOIN medicine_batches mb ON qr.batch_id = mb.batch_id
    LEFT JOIN pharmacy_users u1 ON qr.created_by = u1.id
    LEFT JOIN pharmacy_users u2 ON qr.released_by = u2.id
    {$whereClause}
    ORDER BY qr.quarantine_id DESC
";
$qStmt = $pdo->prepare($qSql);
$qStmt->execute($params);
$records = $qStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Active Batches for Manual Quarantine Modal
$bListStmt = $pdo->query("
    SELECT mb.batch_id, mb.batch_number, mb.expiry_date, mb.quantity_available, m.medicine_name
    FROM medicine_batches mb
    JOIN medicines m ON mb.medicine_id = m.medicine_id
    WHERE mb.quantity_available > 0
    ORDER BY m.medicine_name ASC, mb.expiry_date ASC
");
$availableBatches = $bListStmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="container-fluid px-4 py-3">
    <!-- Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h4 class="fw-bold text-dark mb-1">
                <i class="ti ti-shield-alert text-danger me-2"></i>Quarantine Isolation Hub
            </h4>
            <p class="text-muted small mb-0">Track suspect pharmaceuticals, quality inspection holding, and authorized disposition workflows.</p>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-danger rounded-pill px-3 py-2 small shadow-sm" data-bs-toggle="modal" data-bs-target="#modalManualQuarantine">
                <i class="ti ti-plus me-1"></i> Quarantine Stock
            </button>
            <a href="disposals.php" class="btn btn-outline-dark rounded-pill px-3 py-2 small">
                <i class="ti ti-trash me-1"></i> Disposal Register
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

    <!-- Summary Widgets -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="p-3 bg-danger-subtle text-danger rounded-circle fs-4">
                        <i class="ti ti-shield-alert"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Active Quarantined Lots</div>
                        <div class="fw-bold fs-4 text-dark"><?= number_format($metrics['active_lots'] ?? 0) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="p-3 bg-warning-subtle text-warning rounded-circle fs-4">
                        <i class="ti ti-packages"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Quarantined Units</div>
                        <div class="fw-bold fs-4 text-dark"><?= number_format($metrics['active_units'] ?? 0) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="p-3 bg-success-subtle text-success rounded-circle fs-4">
                        <i class="ti ti-rotate-clockwise"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Released to Stock</div>
                        <div class="fw-bold fs-4 text-dark"><?= number_format($metrics['released_lots'] ?? 0) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="p-3 bg-dark-subtle text-dark rounded-circle fs-4">
                        <i class="ti ti-trash"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Referred to Disposal</div>
                        <div class="fw-bold fs-4 text-dark"><?= number_format($metrics['disposed_lots'] ?? 0) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Tabs -->
    <ul class="nav nav-pills mb-4 bg-white p-2 rounded-4 shadow-sm">
        <li class="nav-item">
            <a class="nav-link rounded-pill <?= $tab === 'QUARANTINED' ? 'active bg-danger text-white fw-bold' : 'text-dark' ?>" href="?tab=QUARANTINED">
                <i class="ti ti-shield-alert me-1"></i> Under Quarantine (<?= $metrics['active_lots'] ?? 0 ?>)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link rounded-pill <?= $tab === 'RELEASED_TO_STOCK' ? 'active bg-success text-white fw-bold' : 'text-dark' ?>" href="?tab=RELEASED_TO_STOCK">
                <i class="ti ti-check me-1"></i> Released to Stock (<?= $metrics['released_lots'] ?? 0 ?>)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link rounded-pill <?= $tab === 'SENT_TO_DISPOSAL' ? 'active bg-dark text-white fw-bold' : 'text-dark' ?>" href="?tab=SENT_TO_DISPOSAL">
                <i class="ti ti-trash me-1"></i> Sent to Disposal (<?= $metrics['disposed_lots'] ?? 0 ?>)
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link rounded-pill <?= $tab === 'ALL' ? 'active bg-primary text-white fw-bold' : 'text-dark' ?>" href="?tab=ALL">
                <i class="ti ti-list me-1"></i> All Records
            </a>
        </li>
    </ul>

    <!-- Records Table -->
    <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden mb-5">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light text-muted small text-uppercase">
                    <tr>
                        <th class="ps-4">Quarantine No</th>
                        <th>Date</th>
                        <th>Medicine & Batch</th>
                        <th>Expiry Date</th>
                        <th class="text-center">Quarantined Qty</th>
                        <th>Reason / Source</th>
                        <th class="text-center">Status</th>
                        <th class="text-end pe-4">Decision / Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="ti ti-shield-check fs-1 d-block mb-2 text-success"></i>
                                No quarantine records found in this category.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $r): ?>
                            <tr>
                                <td class="ps-4">
                                    <span class="font-monospace fw-bold text-dark bg-light px-2 py-1 rounded">
                                        <?= htmlspecialchars($r['quarantine_no']) ?>
                                    </span>
                                </td>
                                <td><?= format_date($r['created_at']) ?></td>
                                <td>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($r['medicine_name']) ?></div>
                                    <span class="font-monospace small text-muted">Batch: <?= htmlspecialchars($r['batch_number']) ?></span>
                                </td>
                                <td>
                                    <span class="<?= strtotime($r['expiry_date']) < time() ? 'text-danger fw-bold' : 'text-dark' ?>">
                                        <?= format_date($r['expiry_date']) ?>
                                    </span>
                                </td>
                                <td class="text-center fw-bold fs-6 text-danger"><?= number_format($r['quantity']) ?></td>
                                <td>
                                    <span class="badge bg-secondary-subtle text-secondary small"><?= htmlspecialchars($r['reason']) ?></span>
                                    <div class="text-muted small">Source: <?= htmlspecialchars($r['source_type']) ?></div>
                                </td>
                                <td class="text-center">
                                    <?php if ($r['status'] === 'QUARANTINED'): ?>
                                        <span class="badge bg-danger-subtle text-danger rounded-pill px-3">Quarantined</span>
                                    <?php elseif ($r['status'] === 'RELEASED_TO_STOCK'): ?>
                                        <span class="badge bg-success-subtle text-success rounded-pill px-3">Released</span>
                                    <?php elseif ($r['status'] === 'SENT_TO_DISPOSAL'): ?>
                                        <span class="badge bg-dark-subtle text-dark rounded-pill px-3">To Disposal</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary rounded-pill px-3"><?= htmlspecialchars($r['status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <?php if ($r['status'] === 'QUARANTINED'): ?>
                                        <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#modalDecision<?= $r['quarantine_id'] ?>">
                                            <i class="ti ti-gavel me-1"></i> Resolve
                                        </button>

                                        <!-- Modal Decision -->
                                        <div class="modal fade text-start" id="modalDecision<?= $r['quarantine_id'] ?>" tabindex="-1">
                                            <div class="modal-dialog modal-dialog-centered">
                                                <div class="modal-content border-0 shadow-lg rounded-4">
                                                    <div class="modal-header bg-primary text-white border-0 py-3">
                                                        <h5 class="modal-title fw-bold">Resolve Quarantine #<?= htmlspecialchars($r['quarantine_no']) ?></h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form method="POST">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="release_quarantine">
                                                        <input type="hidden" name="quarantine_id" value="<?= $r['quarantine_id'] ?>">
                                                        <div class="modal-body p-4">
                                                            <div class="mb-3">
                                                                <div class="fw-bold text-dark"><?= htmlspecialchars($r['medicine_name']) ?> (Batch: <?= htmlspecialchars($r['batch_number']) ?>)</div>
                                                                <div class="text-muted small">Quantity: <?= $r['quantity'] ?> units | Expiry: <?= format_date($r['expiry_date']) ?></div>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label small fw-bold">Authorized Disposition Decision</label>
                                                                <select name="decision" class="form-select" required>
                                                                    <option value="RELEASE_TO_STOCK">Release to Active Sellable Stock</option>
                                                                    <option value="SEND_TO_DISPOSAL">Condemn & Send to Disposal Queue</option>
                                                                    <option value="MARK_NON_SELLABLE">Mark Damaged / Non-Sellable</option>
                                                                </select>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label small fw-bold">Inspection Justification & Notes *</label>
                                                                <textarea name="notes" class="form-control" rows="2" placeholder="Document physical inspection findings..." required></textarea>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer border-0">
                                                            <button type="button" class="btn btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-primary rounded-pill px-4">Save Decision</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted small">Resolved by <?= htmlspecialchars($r['released_by_username'] ?? 'Admin') ?></span>
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

<!-- Modal: Manual Quarantine -->
<div class="modal fade" id="modalManualQuarantine" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-danger text-white border-0 py-3">
                <h5 class="modal-title fw-bold"><i class="ti ti-shield-alert me-2"></i>Quarantine Medicine Batch</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="quarantine_stock">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Select Active Batch</label>
                        <select name="batch_id" class="form-select" required>
                            <option value="">-- Choose Batch --</option>
                            <?php foreach ($availableBatches as $b): ?>
                                <option value="<?= $b['batch_id'] ?>">
                                    <?= htmlspecialchars($b['medicine_name']) ?> | Batch: <?= htmlspecialchars($b['batch_number']) ?> (Avail: <?= $b['quantity_available'] ?>, Exp: <?= $b['expiry_date'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Quantity to Quarantine</label>
                        <input type="number" name="quantity" class="form-control" min="1" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Isolation Reason</label>
                        <select name="reason" class="form-select" required>
                            <option value="CUSTOMER_RETURN_INSPECTION">Customer Return Inspection</option>
                            <option value="PHYSICAL_DAMAGE">Suspected Physical Damage / Leakage</option>
                            <option value="COLD_CHAIN_BREACH">Cold Chain / Temperature Deviation</option>
                            <option value="MANUFACTURER_RECALL">Manufacturer Batch Recall</option>
                            <option value="EXPIRY_SUSPECT">Expiry Date Discrepancy</option>
                            <option value="OTHER">Other Quality Investigation</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Notes / Laboratory Reference</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Reason for quarantine..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger rounded-pill px-4">Isolate Stock</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
