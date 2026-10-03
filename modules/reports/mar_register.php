<?php
// modules/reports/mar_register.php - Clinical Medication Administration Register

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/DocumentSequenceService.php';
require_once __DIR__ . '/../../app/Services/AuditService.php';
require_once __DIR__ . '/../../app/Services/MarService.php';

use Pharmacy\Services\MarService;

require_permission('pharmacy.reports.view');

$marService = new MarService($pdo);

$page_title = 'Medication Administration Register (MAR)';

// Filters
$date = trim($_GET['date'] ?? date('Y-m-d'));
$status = trim($_GET['status'] ?? 'ALL');
$ward = trim($_GET['ward'] ?? '');
$search = trim($_GET['search'] ?? '');

$filters = [
    'scheduled_date' => $date
];
if ($status !== 'ALL' && $status !== '') $filters['status'] = $status;
if ($ward !== '') $filters['ward'] = $ward;
if ($search !== '') $filters['search'] = $search;

$records = $marService->listMarRecords($filters, 100);

// Get wards list
$wardList = $pdo->query("SELECT DISTINCT ward FROM pharmacy_mar_records WHERE ward IS NOT NULL AND ward != '' ORDER BY ward ASC")->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="container-fluid px-4 py-3">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-1"><i class="ti ti-clipboard-list text-emerald me-2"></i>Medication Administration Register</h4>
            <p class="text-muted small mb-0">Daily log of clinical doses given, held, refused, or missed across all inpatient wards.</p>
        </div>
        <div>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                <i class="ti ti-printer me-1"></i>Print Register
            </button>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="card card-custom border-0 shadow-sm mb-4">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-center">
                <div class="col-md-3">
                    <input type="date" name="date" class="form-control form-control-sm" value="<?= sanitize($date) ?>">
                </div>
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="ALL" <?= $status === 'ALL' ? 'selected' : '' ?>>All Outcomes</option>
                        <option value="GIVEN" <?= $status === 'GIVEN' ? 'selected' : '' ?>>GIVEN</option>
                        <option value="HELD" <?= $status === 'HELD' ? 'selected' : '' ?>>HELD</option>
                        <option value="REFUSED" <?= $status === 'REFUSED' ? 'selected' : '' ?>>REFUSED</option>
                        <option value="MISSED" <?= $status === 'MISSED' ? 'selected' : '' ?>>MISSED</option>
                        <option value="SCHEDULED" <?= $status === 'SCHEDULED' ? 'selected' : '' ?>>SCHEDULED</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="ward" class="form-select form-select-sm">
                        <option value="">All Wards</option>
                        <?php foreach ($wardList as $w): ?>
                            <option value="<?= sanitize($w) ?>" <?= $ward === $w ? 'selected' : '' ?>><?= sanitize($w) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Patient, IPD, Rx or Medicine..." value="<?= sanitize($search) ?>">
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-dark w-100"><i class="ti ti-filter me-1"></i>Filter</button>
                    <a href="mar_register.php" class="btn btn-sm btn-light border"><i class="ti ti-refresh"></i></a>
                </div>
            </form>
        </div>
    </div>

    <!-- Table -->
    <div class="card card-custom border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem;">
                <thead class="table-light text-muted text-uppercase" style="font-size: 0.72rem;">
                    <tr>
                        <th>MAR #</th>
                        <th>Patient / IPD</th>
                        <th>Ward / Bed</th>
                        <th>Medicine</th>
                        <th>Scheduled</th>
                        <th>Actual Time</th>
                        <th class="text-center">Outcome</th>
                        <th>Administered Dose</th>
                        <th>Clinical Notes / Reason</th>
                        <th>Administered By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr><td colspan="10" class="text-center py-5 text-muted">No administration records found for this date.</td></tr>
                    <?php else: ?>
                        <?php foreach ($records as $r): 
                            $st = $r['status'];
                            $badge = 'bg-light text-dark border';
                            if ($st === 'GIVEN') $badge = 'bg-success text-white';
                            elseif ($st === 'HELD') $badge = 'bg-warning text-dark';
                            elseif ($st === 'REFUSED') $badge = 'bg-danger text-white';
                            elseif ($st === 'MISSED') $badge = 'bg-secondary text-white';
                        ?>
                            <tr>
                                <td class="font-monospace text-emerald fw-bold"><?= sanitize($r['mar_number']) ?></td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= sanitize($r['patient_name']) ?></div>
                                    <span class="small font-monospace text-muted">IPD: <?= sanitize($r['ipd_admission_no'] ?? '-') ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-secondary-subtle text-secondary"><?= sanitize($r['ward'] ?? '-') ?></span>
                                    <span class="small text-muted ms-1">Bed <?= sanitize($r['bed_number'] ?? '-') ?></span>
                                </td>
                                <td>
                                    <div class="fw-bold"><?= sanitize($r['medicine_name']) ?></div>
                                    <span class="text-muted small"><?= sanitize($r['route']) ?></span>
                                </td>
                                <td class="font-monospace fw-semibold"><?= date('h:i A', strtotime($r['scheduled_time'])) ?></td>
                                <td class="font-monospace text-muted">
                                    <?= $r['actual_admin_time'] ? date('h:i A', strtotime($r['actual_admin_time'])) : '—' ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge <?= $badge ?> px-2 py-1 font-monospace"><?= sanitize($st) ?></span>
                                </td>
                                <td class="font-monospace">
                                    <?php if ($st === 'GIVEN'): ?>
                                        <?= (float)$r['administered_dose'] ?> <?= sanitize($r['dose_unit']) ?> (<?= (int)$r['administered_qty'] ?> unit)
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($r['not_given_reason']): ?>
                                        <span class="badge bg-danger-subtle text-danger"><?= sanitize($r['not_given_reason']) ?></span>
                                    <?php endif; ?>
                                    <div class="small text-muted"><?= sanitize($r['notes'] ?? '') ?></div>
                                </td>
                                <td>
                                    <small><i class="ti ti-user me-1 text-muted"></i><?= sanitize($r['administered_by_name'] ?? '—') ?></small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
