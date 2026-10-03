<?php
// modules/ipd/dispensing.php - Clinical Dispensing History & Batch Traceability

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

use Pharmacy\Services\DispensingService;

require_permission('pharmacy.dispensing.view');

$dispService = new DispensingService($pdo);

$page_title = 'IPD Dispensing Records & Batch Traceability';

// Filters
$startDate = trim($_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days')));
$endDate = trim($_GET['end_date'] ?? date('Y-m-d'));
$search = trim($_GET['search'] ?? '');
$wardFilter = trim($_GET['ward'] ?? '');

$filters = [
    'start_date' => $startDate,
    'end_date'   => $endDate
];
if ($search !== '') $filters['search'] = $search;
if ($wardFilter !== '') $filters['ward'] = $wardFilter;

$records = $dispService->listDispensingRecords($filters, 100);

// View Modal
$selectedDisp = null;
if (!empty($_GET['view_id'])) {
    $selectedDisp = $dispService->getDispensingRecord((int)$_GET['view_id']);
}

// Get wards list
$wardList = $pdo->query("SELECT DISTINCT ward FROM pharmacy_dispensing_records WHERE ward IS NOT NULL AND ward != '' ORDER BY ward ASC")->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="container-fluid px-4 py-3">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-1"><i class="ti ti-vaccine text-emerald me-2"></i>Clinical Dispensing Records</h4>
            <p class="text-muted small mb-0">Traceable audit of all physical medication dispensing events with batch numbers and expiry dates.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= BASE_URL ?>modules/ipd/queue.php" class="btn btn-emerald btn-sm text-white">
                <i class="ti ti-inbox me-1"></i>Dispensing Queue
            </a>
            <a href="<?= BASE_URL ?>modules/reports/batch_traceability.php" class="btn btn-outline-secondary btn-sm">
                <i class="ti ti-search me-1"></i>Batch Traceability Report
            </a>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card card-custom border-0 shadow-sm mb-4">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-center">
                <div class="col-md-3">
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Dispensing #, patient, IPD or Rx..." value="<?= sanitize($search) ?>">
                </div>
                <div class="col-md-2">
                    <input type="date" name="start_date" class="form-control form-control-sm" value="<?= sanitize($startDate) ?>" title="Start Date">
                </div>
                <div class="col-md-2">
                    <input type="date" name="end_date" class="form-control form-control-sm" value="<?= sanitize($endDate) ?>" title="End Date">
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
                    <a href="dispensing.php" class="btn btn-sm btn-light border"><i class="ti ti-refresh"></i></a>
                </div>
                <div class="col-md-1 text-end">
                    <span class="badge bg-light text-dark border px-2 py-1.5 font-monospace">
                        <?= count($records) ?>
                    </span>
                </div>
            </form>
        </div>
    </div>

    <!-- Dispensing Records Table -->
    <div class="card card-custom border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size: 0.88rem;">
                <thead class="table-light text-muted text-uppercase" style="font-size: 0.75rem;">
                    <tr>
                        <th>Dispensing Slip #</th>
                        <th>Dispensed At</th>
                        <th>Patient / IPD No</th>
                        <th>Ward / Bed</th>
                        <th>Prescription #</th>
                        <th class="text-center">Batches Allocated</th>
                        <th class="text-center">Total Units</th>
                        <th>Dispensed By</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="ti ti-inbox fs-1 d-block mb-2 text-secondary"></i>
                                No dispensing records found for the selected criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $r): ?>
                            <tr>
                                <td>
                                    <a href="dispensing.php?view_id=<?= (int)$r['dispensing_id'] ?>" class="fw-bold font-monospace text-emerald text-decoration-none">
                                        <?= sanitize($r['dispensing_number']) ?>
                                    </a>
                                </td>
                                <td class="text-muted small">
                                    <?= date('d M Y, h:i A', strtotime($r['dispensing_date'])) ?>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= sanitize($r['patient_name']) ?></div>
                                    <div class="badge bg-light text-dark border font-monospace" style="font-size: 0.72rem;">
                                        IPD: <?= sanitize($r['ipd_admission_no'] ?? 'N/A') ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-secondary-subtle text-secondary"><?= sanitize($r['ward'] ?? '-') ?></span>
                                    <span class="text-muted small ms-1"><?= sanitize($r['bed'] ?? '') ?></span>
                                </td>
                                <td>
                                    <span class="font-monospace text-dark fw-semibold"><?= sanitize($r['prescription_number'] ?? 'N/A') ?></span>
                                </td>
                                <td class="text-center font-monospace">
                                    <span class="badge bg-info-subtle text-info"><?= (int)$r['batch_count'] ?> batch(es)</span>
                                </td>
                                <td class="text-center font-monospace fw-bold text-dark">
                                    <?= (int)$r['total_units'] ?>
                                </td>
                                <td>
                                    <span class="small text-muted"><i class="ti ti-user me-1"></i><?= sanitize($r['dispensed_by_name'] ?? 'Pharmacist') ?></span>
                                </td>
                                <td class="text-end">
                                    <a href="dispensing.php?view_id=<?= (int)$r['dispensing_id'] ?>" class="btn btn-sm btn-outline-secondary px-2">
                                        <i class="ti ti-eye me-1"></i>Details
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

<!-- Dispensing Record Details Modal -->
<?php if ($selectedDisp): ?>
<div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,0.5);">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-emerald text-white">
                <h5 class="modal-title fs-6 fw-bold">
                    <i class="ti ti-receipt me-2"></i>Dispensing Slip #<?= sanitize($selectedDisp['dispensing_number']) ?>
                </h5>
                <a href="dispensing.php" class="btn-close btn-close-white"></a>
            </div>
            <div class="modal-body p-4" id="printableSlip">
                <!-- Patient Banner -->
                <div class="row g-3 p-3 bg-light rounded-3 mb-4 border">
                    <div class="col-md-4">
                        <small class="text-muted d-block">Patient Name</small>
                        <span class="fw-bold fs-6 text-dark"><?= sanitize($selectedDisp['patient_name']) ?></span>
                        <div class="small font-monospace text-muted">IPD: <?= sanitize($selectedDisp['ipd_admission_no'] ?? 'N/A') ?></div>
                    </div>
                    <div class="col-md-4">
                        <small class="text-muted d-block">Ward / Bed</small>
                        <span class="fw-semibold text-dark"><?= sanitize($selectedDisp['ward'] ?? '-') ?> / Bed <?= sanitize($selectedDisp['bed'] ?? '-') ?></span>
                        <div class="small text-muted">Prescription: <?= sanitize($selectedDisp['prescription_number'] ?? 'N/A') ?></div>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <small class="text-muted d-block">Dispensed At & By</small>
                        <span class="small fw-semibold"><?= date('d M Y, h:i A', strtotime($selectedDisp['dispensing_date'])) ?></span>
                        <div class="small text-emerald fw-bold"><?= sanitize($selectedDisp['dispensed_by_name'] ?? 'Staff') ?></div>
                    </div>
                </div>

                <h6 class="fw-bold text-dark mb-2"><i class="ti ti-packages text-emerald me-1"></i>Dispensed Batch Allocations (FEFO)</h6>
                <div class="table-responsive border rounded-3 mb-3">
                    <table class="table table-sm align-middle mb-0" style="font-size: 0.85rem;">
                        <thead class="table-light text-muted">
                            <tr>
                                <th>Medicine Name</th>
                                <th>Batch Number</th>
                                <th>Expiry Date</th>
                                <th class="text-center">Quantity</th>
                                <th class="text-end">Unit Price</th>
                                <th class="text-end">Line Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $grandQty = 0;
                            $grandVal = 0.0;
                            foreach ($selectedDisp['batches'] as $b): 
                                $bQty = (int)$b['dispensed_qty'];
                                $uPrice = (float)$b['unit_price'];
                                $lTot = $bQty * $uPrice;
                                $grandQty += $bQty;
                                $grandVal += $lTot;
                            ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold"><?= sanitize($b['medicine_name']) ?></div>
                                        <div class="text-muted small" style="font-size: 0.75rem;"><?= sanitize($b['dosage_form'] ?? '') ?> <?= sanitize($b['strength'] ?? '') ?></div>
                                    </td>
                                    <td><span class="badge bg-light text-dark border font-monospace"><?= sanitize($b['batch_number']) ?></span></td>
                                    <td class="font-monospace text-muted"><?= sanitize($b['expiry_date']) ?></td>
                                    <td class="text-center font-monospace fw-bold"><?= $bQty ?></td>
                                    <td class="text-end font-monospace">₹<?= number_format($uPrice, 2) ?></td>
                                    <td class="text-end font-monospace fw-bold">₹<?= number_format($lTot, 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light fw-bold font-monospace">
                            <tr>
                                <td colspan="3" class="text-end">Total Dispensed Units:</td>
                                <td class="text-center"><?= $grandQty ?></td>
                                <td class="text-end">Total Value:</td>
                                <td class="text-end">₹<?= number_format($grandVal, 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <?php if (!empty($selectedDisp['notes'])): ?>
                    <div class="p-2 bg-light rounded border text-muted small">
                        <strong>Notes:</strong> <?= sanitize($selectedDisp['notes']) ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer bg-light p-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                    <i class="ti ti-printer me-1"></i>Print Slip
                </button>
                <a href="dispensing.php" class="btn btn-sm btn-dark px-4">Close</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>