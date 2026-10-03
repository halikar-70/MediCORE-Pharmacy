<?php
// modules/inventory/expiry.php - Expiry Management & Threshold Alerts

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/AuditService.php';
require_once __DIR__ . '/../../app/Services/StockLedgerService.php';
require_once __DIR__ . '/../../app/Services/BatchService.php';
require_once __DIR__ . '/../../app/Services/MedicineService.php';

require_once __DIR__ . '/../../app/Services/DocumentSequenceService.php';
require_once __DIR__ . '/../../app/Services/StockLifecycleService.php';

require_permission('pharmacy.expiry.view');

use Pharmacy\Services\BatchService;
use Pharmacy\Services\StockLifecycleService;
use Pharmacy\Auth\AuthManager;

$batchService = new BatchService($pdo);
$lifecycleService = new StockLifecycleService($pdo);

$page_title = 'Expiry Management';
$actionSuccess = null;
$actionError = null;

// Handle Quarantine Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quarantine_expired') {
    verify_csrf();
    require_permission('pharmacy.expiry.quarantine');

    $bId = (int)($_POST['batch_id'] ?? 0);
    try {
        $userId = AuthManager::userId() ?? 1;
        $res = $lifecycleService->quarantineExpiredBatch($bId, $userId);
        $actionSuccess = "Batch stock ({$res['quarantined_quantity']} units) moved to quarantine isolation successfully.";
    } catch (Exception $e) {
        $actionError = $e->getMessage();
    }
}

// Active threshold tab: '30d' | '60d' | '90d' | 'expired'
$tab = trim($_GET['tab'] ?? '30d');
if (!in_array($tab, ['30d', '60d', '90d', 'expired'], true)) {
    $tab = '30d';
}

$alertBatches = $batchService->getExpiryAlerts($tab);

// Metrics
$count30 = count($batchService->getExpiryAlerts('30d'));
$count60 = count($batchService->getExpiryAlerts('60d'));
$count90 = count($batchService->getExpiryAlerts('90d'));
$countExpired = count($batchService->getExpiryAlerts('expired'));

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h4 class="fw-bold text-dark mb-1">
                <i class="ti ti-calendar-due text-danger me-2"></i>Batch Expiry Management
            </h4>
            <p class="text-muted small mb-0">Monitor pharmaceutical shelf life, FEFO dispatch prioritization, and expired batch quarantines.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="quarantine.php" class="btn btn-outline-danger rounded-pill px-3 py-2 small">
                <i class="ti ti-shield-alert me-1"></i> Quarantine Hub
            </a>
            <a href="disposals.php" class="btn btn-outline-dark rounded-pill px-3 py-2 small">
                <i class="ti ti-trash me-1"></i> Disposal Register
            </a>
            <a href="batch_stock.php" class="btn btn-outline-secondary rounded-pill px-3 py-2 small">
                <i class="ti ti-packages me-1"></i> All Batches
            </a>
        </div>
    </div>

    <?php if ($actionSuccess): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3 shadow-sm mb-4" role="alert">
            <i class="ti ti-circle-check me-2 fs-5 align-middle"></i>
            <?= htmlspecialchars($actionSuccess) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($actionError): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-3 shadow-sm mb-4" role="alert">
            <i class="ti ti-alert-circle me-2 fs-5 align-middle"></i>
            <?= htmlspecialchars($actionError) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Threshold Tabs -->
    <ul class="nav nav-pills mb-4 bg-white p-2 rounded-4 shadow-sm">
        <li class="nav-item">
            <a class="nav-link rounded-pill <?= $tab === '30d' ? 'active bg-danger text-white fw-bold' : 'text-dark' ?>" href="?tab=30d">
                <i class="ti ti-alert-triangle me-1"></i> Near Expiry (&le; 30 Days)
                <span class="badge rounded-pill ms-2 <?= $tab === '30d' ? 'bg-white text-danger' : 'bg-danger text-white' ?>"><?= $count30 ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link rounded-pill <?= $tab === '60d' ? 'active bg-warning text-dark fw-bold' : 'text-dark' ?>" href="?tab=60d">
                <i class="ti ti-clock me-1"></i> Within 60 Days
                <span class="badge rounded-pill ms-2 <?= $tab === '60d' ? 'bg-dark text-white' : 'bg-warning text-dark' ?>"><?= $count60 ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link rounded-pill <?= $tab === '90d' ? 'active bg-info text-dark fw-bold' : 'text-dark' ?>" href="?tab=90d">
                <i class="ti ti-calendar me-1"></i> Within 90 Days
                <span class="badge rounded-pill ms-2 <?= $tab === '90d' ? 'bg-dark text-white' : 'bg-info text-dark' ?>"><?= $count90 ?></span>
            </a>
        </li>
        <li class="nav-item ms-auto">
            <a class="nav-link rounded-pill <?= $tab === 'expired' ? 'active bg-dark text-white fw-bold' : 'text-danger' ?>" href="?tab=expired">
                <i class="ti ti-ban me-1"></i> Already Expired
                <span class="badge rounded-pill ms-2 <?= $tab === 'expired' ? 'bg-danger text-white' : 'bg-dark text-white' ?>"><?= $countExpired ?></span>
            </a>
        </li>
    </ul>

    <!-- Batch Table -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white mb-5">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light text-muted small text-uppercase">
                    <tr>
                        <th class="ps-4">Medicine & Formulation</th>
                        <th>Batch Number</th>
                        <th>Shelf Location</th>
                        <th>Expiry Date</th>
                        <th>Days Remaining</th>
                        <th class="text-end">In-Stock Units</th>
                        <th class="text-end">MRP</th>
                        <th class="text-center">Status</th>
                        <th class="text-end pe-4">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($alertBatches)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="ti ti-circle-check fs-1 d-block mb-2 text-success"></i>
                                No batches currently found in this expiry category. All stock is well within shelf-life bounds.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($alertBatches as $b): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold text-dark">
                                        <a href="profile.php?id=<?= $b['medicine_id'] ?>" class="text-dark text-decoration-none hover-emerald">
                                            <?= htmlspecialchars($b['medicine_name']) ?>
                                        </a>
                                    </div>
                                    <div class="text-muted small"><?= htmlspecialchars($b['generic_name'] ?? '-') ?></div>
                                </td>
                                <td>
                                    <span class="font-monospace fw-bold text-dark bg-light px-2 py-1 rounded">
                                        <?= htmlspecialchars($b['batch_number']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="font-monospace small text-muted">
                                        <i class="ti ti-map-pin me-1"></i><?= htmlspecialchars($b['shelf_location'] ?? $b['shelf'] ?? $b['rack_location'] ?? '-') ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-bold <?= $b['days_to_expiry'] < 0 ? 'text-danger' : 'text-dark' ?>">
                                        <?= format_date($b['expiry_date']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($b['days_to_expiry'] < 0): ?>
                                        <span class="badge bg-danger text-white rounded-pill px-3">
                                            EXPIRED (<?= abs($b['days_to_expiry']) ?> days ago)
                                        </span>
                                    <?php elseif ($b['days_to_expiry'] <= 30): ?>
                                        <span class="badge bg-danger-subtle text-danger rounded-pill px-3 fw-bold">
                                            <?= $b['days_to_expiry'] ?> days left
                                        </span>
                                    <?php elseif ($b['days_to_expiry'] <= 60): ?>
                                        <span class="badge bg-warning-subtle text-warning rounded-pill px-3 fw-semibold">
                                            <?= $b['days_to_expiry'] ?> days left
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-info-subtle text-info rounded-pill px-3">
                                            <?= $b['days_to_expiry'] ?> days left
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end fw-bold fs-6 text-dark"><?= number_format($b['quantity_available']) ?></td>
                                <td class="text-end fw-semibold text-dark"><?= format_currency($b['mrp']) ?></td>
                                <td class="text-center">
                                    <?php if ($b['days_to_expiry'] < 0): ?>
                                        <span class="badge bg-danger text-white rounded-pill px-2">Blocked / Expired</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning-subtle text-warning rounded-pill px-2">Near Expiry</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="d-flex justify-content-end gap-1">
                                        <?php if ($b['days_to_expiry'] < 0 && $b['quantity_available'] > 0): ?>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Confirm transferring this expired batch to Quarantine Isolation?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="quarantine_expired">
                                                <input type="hidden" name="batch_id" value="<?= $b['batch_id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger rounded-pill px-3">
                                                    <i class="ti ti-shield-alert me-1"></i> Quarantine
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <a href="adjustments.php?medicine_id=<?= $b['medicine_id'] ?>&batch_id=<?= $b['batch_id'] ?>&type=<?= $b['days_to_expiry'] < 0 ? 'Expired' : 'Damage' ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3">
                                            <i class="ti ti-adjustments me-1"></i> Adjust
                                        </a>
                                    </div>
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