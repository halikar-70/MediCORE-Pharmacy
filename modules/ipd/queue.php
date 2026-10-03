<?php
// modules/ipd/queue.php - IPD Clinical Medication Dispensing Queue

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/DocumentSequenceService.php';
require_once __DIR__ . '/../../app/Services/StockLedgerService.php';
require_once __DIR__ . '/../../app/Services/FefoService.php';
require_once __DIR__ . '/../../app/Services/AuditService.php';
require_once __DIR__ . '/../../app/Services/PrescriptionService.php';
require_once __DIR__ . '/../../app/Services/DispensingService.php';
require_once __DIR__ . '/../../app/Services/MarService.php';

use Pharmacy\Services\DispensingService;
use Pharmacy\Services\MarService;

require_permission('pharmacy.dispensing.view');

$dispService = new DispensingService($pdo);
$marService = new MarService($pdo);

$page_title = 'IPD Medication Dispensing Queue';
$error = null;
$success = null;

// Handle Dispensing Action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Security token mismatch. Please try again.";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'dispense_item') {
            require_permission('pharmacy.dispensing.manage');
            try {
                $rxId = (int)($_POST['prescription_id'] ?? 0);
                $itemId = (int)($_POST['item_id'] ?? 0);
                $qty = (int)($_POST['dispense_qty'] ?? 0);
                $notes = trim($_POST['notes'] ?? '');

                if ($qty <= 0) {
                    throw new Exception("Dispense quantity must be greater than zero.");
                }

                $meta = [
                    'notes' => $notes,
                    'idempotency_key' => 'DSP-Q-' . $rxId . '-' . $itemId . '-' . time() . '-' . rand(100, 999)
                ];

                $items = [
                    ['item_id' => $itemId, 'quantity' => $qty]
                ];

                $res = $dispService->dispensePrescription($rxId, $items, $meta, $_SESSION['user_id'] ?? 1);

                // Auto-generate MAR schedules if selected
                if (!empty($_POST['generate_mar'])) {
                    $marService->generateSchedules($rxId, [], $_SESSION['user_id'] ?? 1);
                }

                $success = "Successfully dispensed {$qty} unit(s) under Dispensing Slip #{$res['dispensing_number']}!";
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }
}

// Filter parameters
$statusFilter = trim($_GET['status'] ?? 'ALL');
$wardFilter = trim($_GET['ward'] ?? '');
$search = trim($_GET['search'] ?? '');

$filters = [];
if ($statusFilter !== 'ALL' && $statusFilter !== '') {
    $filters['status'] = $statusFilter;
}
if ($wardFilter !== '') {
    $filters['ward'] = $wardFilter;
}
if ($search !== '') {
    $filters['search'] = $search;
}

$queueItems = $dispService->getDispensingQueue($filters, 100);

// Get wards list for dropdown
$wardsStmt = $pdo->query("SELECT DISTINCT ward FROM pharmacy_prescriptions WHERE ward IS NOT NULL AND ward != '' ORDER BY ward ASC");
$wardList = $wardsStmt->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="container-fluid px-4 py-3">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-1"><i class="ti ti-inbox text-emerald me-2"></i>IPD Medication Dispensing Queue</h4>
            <p class="text-muted small mb-0">Live queue of inpatient prescriptions pending pharmacy batch dispensing.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= BASE_URL ?>modules/ipd/dispensing.php" class="btn btn-outline-secondary btn-sm">
                <i class="ti ti-history me-1"></i>Dispensing History
            </a>
            <a href="<?= BASE_URL ?>modules/ipd/mar.php" class="btn btn-emerald btn-sm text-white">
                <i class="ti ti-clipboard-check me-1"></i>Inpatient MAR Sheet
            </a>
        </div>
    </div>

    <!-- Alerts -->
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center" role="alert">
            <i class="ti ti-alert-circle fs-5 me-2"></i>
            <div><?= sanitize($error) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show d-flex align-items-center" role="alert">
            <i class="ti ti-check fs-5 me-2"></i>
            <div><?= sanitize($success) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Filter Card -->
    <div class="card card-custom border-0 shadow-sm mb-4">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-center">
                <div class="col-md-3">
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Search patient, IPD, Rx or medicine..." value="<?= sanitize($search) ?>">
                </div>
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="ALL" <?= $statusFilter === 'ALL' ? 'selected' : '' ?>>All Statuses</option>
                        <option value="PENDING" <?= $statusFilter === 'PENDING' ? 'selected' : '' ?>>Pending (Un-dispensed)</option>
                        <option value="PARTIAL" <?= $statusFilter === 'PARTIAL' ? 'selected' : '' ?>>Partially Dispensed</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="ward" class="form-select form-select-sm">
                        <option value="">All Wards</option>
                        <?php foreach ($wardList as $w): ?>
                            <option value="<?= sanitize($w) ?>" <?= $wardFilter === $w ? 'selected' : '' ?>><?= sanitize($w) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-dark w-100"><i class="ti ti-filter me-1"></i>Filter</button>
                    <a href="queue.php" class="btn btn-sm btn-light border"><i class="ti ti-refresh"></i></a>
                </div>
                <div class="col-md-3 text-end">
                    <span class="badge bg-emerald-subtle text-emerald border border-emerald px-2 py-1.5 font-monospace">
                        <?= count($queueItems) ?> Active Prescribed Item(s)
                    </span>
                </div>
            </form>
        </div>
    </div>

    <!-- Queue Table Card -->
    <div class="card card-custom border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size: 0.88rem;">
                <thead class="table-light text-muted text-uppercase" style="font-size: 0.75rem;">
                    <tr>
                        <th>Rx Details</th>
                        <th>Patient / IPD Ref</th>
                        <th>Ward / Bed</th>
                        <th>Prescribed Medicine</th>
                        <th>Dose & Schedule</th>
                        <th class="text-center">Prescribed</th>
                        <th class="text-center">Dispensed</th>
                        <th class="text-center">Remaining</th>
                        <th class="text-center">Stock</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($queueItems)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-5 text-muted">
                                <i class="ti ti-circle-check fs-1 text-emerald d-block mb-2"></i>
                                No pending medication items in the dispensing queue.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($queueItems as $row): 
                            $rem = (int)$row['remaining_qty'];
                            $stock = (int)$row['available_stock'];
                            $stockClass = ($stock >= $rem) ? 'text-success' : ($stock > 0 ? 'text-warning' : 'text-danger');
                        ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-dark"><?= sanitize($row['prescription_number']) ?></div>
                                    <div class="text-muted small"><?= sanitize($row['prescription_date']) ?></div>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= sanitize($row['patient_name']) ?></div>
                                    <div class="badge bg-light text-dark border font-monospace" style="font-size: 0.72rem;">
                                        IPD: <?= sanitize($row['ipd_admission_no'] ?? 'N/A') ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-secondary-subtle text-secondary"><?= sanitize($row['ward'] ?? 'General Ward') ?></span>
                                    <div class="text-muted small">Bed: <?= sanitize($row['bed_number'] ?? '-') ?></div>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= sanitize($row['medicine_name']) ?></div>
                                    <div class="text-muted small"><?= sanitize($row['generic_name'] ?? '') ?></div>
                                </td>
                                <td>
                                    <div class="fw-semibold text-primary">
                                        <?= $row['dose'] ? sanitize($row['dose'] . ' ' . $row['dose_unit']) : '-' ?>
                                        <span class="badge bg-info-subtle text-info"><?= sanitize($row['route'] ?? 'ORAL') ?></span>
                                    </div>
                                    <div class="text-muted small">
                                        <?= sanitize($row['frequency'] ?? '') ?> <?= $row['duration_days'] ? '(' . $row['duration_days'] . 'd)' : '' ?>
                                    </div>
                                </td>
                                <td class="text-center font-monospace fw-semibold"><?= (int)$row['prescribed_qty'] ?></td>
                                <td class="text-center font-monospace text-muted"><?= (int)$row['dispensed_qty'] ?></td>
                                <td class="text-center font-monospace fw-bold text-danger"><?= $rem ?></td>
                                <td class="text-center">
                                    <span class="fw-bold <?= $stockClass ?> font-monospace"><?= $stock ?></span>
                                    <?php if ($stock < $rem): ?>
                                        <i class="ti ti-alert-triangle text-warning" title="Low stock in pharmacy"></i>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($stock <= 0): ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger disabled" title="Out of stock in pharmacy">Out of Stock</button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-emerald text-white px-3"
                                                onclick="openDispenseModal(<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>)">
                                            <i class="ti ti-vaccine me-1"></i>Dispense
                                        </button>
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

<!-- Dispense Action Modal -->
<div class="modal fade" id="dispenseModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="dispense_item">
                <input type="hidden" name="prescription_id" id="modal_rx_id">
                <input type="hidden" name="item_id" id="modal_item_id">

                <div class="modal-header bg-emerald text-white">
                    <h5 class="modal-title fs-6 fw-bold"><i class="ti ti-vaccine me-2"></i>Dispense Inpatient Medication</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="p-3 bg-light rounded-3 mb-3 border">
                        <div class="row">
                            <div class="col-6">
                                <small class="text-muted d-block">Patient Name</small>
                                <span class="fw-bold" id="modal_patient_name">-</span>
                            </div>
                            <div class="col-6 text-end">
                                <small class="text-muted d-block">Prescription</small>
                                <span class="fw-bold font-monospace" id="modal_rx_num">-</span>
                            </div>
                        </div>
                        <hr class="my-2">
                        <div>
                            <small class="text-muted d-block">Medicine</small>
                            <span class="fw-bold text-emerald fs-6" id="modal_med_name">-</span>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold text-muted">Remaining Needed</label>
                            <input type="text" class="form-control form-control-sm font-monospace fw-bold bg-light" id="modal_remaining" readonly>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold text-muted">Available Stock (FEFO)</label>
                            <input type="text" class="form-control form-control-sm font-monospace fw-bold bg-light" id="modal_available" readonly>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Quantity to Dispense Now <span class="text-danger">*</span></label>
                        <input type="number" name="dispense_qty" id="modal_dispense_qty" class="form-control font-monospace fw-bold text-center fs-5" min="1" required>
                        <div class="form-text small text-muted">Stock will be automatically allocated from earliest expiry non-quarantined batches.</div>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="generate_mar" value="1" id="chkGenMar" checked>
                        <label class="form-check-label small fw-semibold" for="chkGenMar">
                            Auto-generate MAR Timed Schedules (if not already scheduled)
                        </label>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-muted">Dispensing Notes (Optional)</label>
                        <input type="text" name="notes" class="form-control form-control-sm" placeholder="e.g. Dispensed directly to ward nurse">
                    </div>
                </div>
                <div class="modal-footer bg-light p-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-emerald text-white px-4 fw-bold">
                        <i class="ti ti-check me-1"></i>Confirm & Deduct Stock
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openDispenseModal(data) {
    document.getElementById('modal_rx_id').value = data.prescription_id;
    document.getElementById('modal_item_id').value = data.item_id;
    document.getElementById('modal_patient_name').innerText = data.patient_name;
    document.getElementById('modal_rx_num').innerText = data.prescription_number;
    document.getElementById('modal_med_name').innerText = data.medicine_name;
    document.getElementById('modal_remaining').value = data.remaining_qty + ' units';
    document.getElementById('modal_available').value = data.available_stock + ' units';

    const maxDisp = Math.min(parseInt(data.remaining_qty), parseInt(data.available_stock));
    const qtyInput = document.getElementById('modal_dispense_qty');
    qtyInput.max = maxDisp;
    qtyInput.value = maxDisp;

    const modal = new bootstrap.Modal(document.getElementById('dispenseModal'));
    modal.show();
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>