<?php
// modules/reports/administration_variance.php - Clinical vs Pharmacy Variance Report

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/DocumentSequenceService.php';
require_once __DIR__ . '/../../app/Services/AuditService.php';

require_permission('pharmacy.reports.view');

$page_title = 'Medication Administration Variance Report';

$ward = trim($_GET['ward'] ?? '');
$search = trim($_GET['search'] ?? '');

$where = ["rx.status NOT IN ('CANCELLED')"];
$params = [];

if ($ward !== '') {
    $where[] = "rx.ward = ?";
    $params[] = $ward;
}

if ($search !== '') {
    $s = '%' . $search . '%';
    $where[] = "(rx.prescription_number LIKE ? OR rx.patient_name LIKE ? OR rx.ipd_admission_no LIKE ? OR m.medicine_name LIKE ?)";
    $params[] = $s;
    $params[] = $s;
    $params[] = $s;
    $params[] = $s;
}

$whereSql = implode(' AND ', $where);

$sql = "
    SELECT rxi.item_id, rxi.prescription_id, rxi.prescribed_qty, rxi.dispensed_qty,
           (rxi.prescribed_qty - rxi.dispensed_qty) as undispensed_qty,
           rx.prescription_number, rx.prescription_date, rx.patient_name, rx.ipd_admission_no,
           rx.ward, rx.bed_number, rx.doctor_name, rx.status as rx_status,
           m.medicine_name, m.generic_name,
           COALESCE((
               SELECT SUM(administered_qty)
               FROM pharmacy_mar_records
               WHERE prescription_item_id = rxi.item_id AND status = 'GIVEN'
           ), 0) as administered_qty,
           COALESCE((
               SELECT COUNT(*)
               FROM pharmacy_mar_records
               WHERE prescription_item_id = rxi.item_id AND status IN ('HELD', 'REFUSED', 'MISSED')
           ), 0) as non_given_count
    FROM pharmacy_prescription_items rxi
    JOIN pharmacy_prescriptions rx ON rxi.prescription_id = rx.prescription_id
    JOIN medicines m ON rxi.medicine_id = m.medicine_id
    WHERE {$whereSql}
    ORDER BY rx.prescription_id DESC, rxi.item_id ASC
    LIMIT 150
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Ward list
$wardList = $pdo->query("SELECT DISTINCT ward FROM pharmacy_prescriptions WHERE ward IS NOT NULL AND ward != '' ORDER BY ward ASC")->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="container-fluid px-4 py-3">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-1"><i class="ti ti-chart-arrows text-emerald me-2"></i>Administration Variance Report</h4>
            <p class="text-muted small mb-0">Reconciles Prescribed, Dispensed (Pharmacy stock out), and Administered (MAR clinical charting).</p>
        </div>
        <div>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                <i class="ti ti-printer me-1"></i>Print Variance Report
            </button>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="card card-custom border-0 shadow-sm mb-4">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-center">
                <div class="col-md-3">
                    <select name="ward" class="form-select form-select-sm">
                        <option value="">All Wards</option>
                        <?php foreach ($wardList as $w): ?>
                            <option value="<?= sanitize($w) ?>" <?= $ward === $w ? 'selected' : '' ?>><?= sanitize($w) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Search patient, IPD, Rx or medicine..." value="<?= sanitize($search) ?>">
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-dark w-100"><i class="ti ti-filter me-1"></i>Filter</button>
                    <a href="administration_variance.php" class="btn btn-sm btn-light border"><i class="ti ti-refresh"></i></a>
                </div>
                <div class="col-md-3 text-end">
                    <span class="badge bg-light text-dark border font-monospace py-1.5 px-2">
                        <?= count($rows) ?> Prescribed Order(s)
                    </span>
                </div>
            </form>
        </div>
    </div>

    <!-- Variance Table -->
    <div class="card card-custom border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem;">
                <thead class="table-light text-muted text-uppercase" style="font-size: 0.72rem;">
                    <tr>
                        <th>Rx #</th>
                        <th>Patient / IPD</th>
                        <th>Ward / Bed</th>
                        <th>Medicine</th>
                        <th class="text-center">Prescribed</th>
                        <th class="text-center bg-light">Dispensed</th>
                        <th class="text-center">Undispensed</th>
                        <th class="text-center bg-emerald-subtle text-emerald">Administered</th>
                        <th class="text-center bg-warning-subtle text-warning">Bedside Balance</th>
                        <th class="text-end">Reconciliation Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="10" class="text-center py-5 text-muted">No records found matching filter criteria.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): 
                            $p = (int)$r['prescribed_qty'];
                            $d = (int)$r['dispensed_qty'];
                            $u = (int)$r['undispensed_qty'];
                            $a = (int)$r['administered_qty'];
                            $b = max(0, $d - $a);
                        ?>
                            <tr>
                                <td class="font-monospace fw-bold text-dark"><?= sanitize($r['prescription_number']) ?></td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= sanitize($r['patient_name']) ?></div>
                                    <span class="small font-monospace text-muted">IPD: <?= sanitize($r['ipd_admission_no'] ?? '-') ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-secondary-subtle text-secondary"><?= sanitize($r['ward'] ?? '-') ?></span>
                                    <span class="small text-muted ms-1">Bed <?= sanitize($r['bed_number'] ?? '-') ?></span>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= sanitize($r['medicine_name']) ?></div>
                                    <small class="text-muted"><?= sanitize($r['generic_name'] ?? '') ?></small>
                                </td>
                                <td class="text-center font-monospace fw-bold"><?= $p ?></td>
                                <td class="text-center font-monospace fw-bold text-primary bg-light"><?= $d ?></td>
                                <td class="text-center font-monospace text-muted"><?= $u ?></td>
                                <td class="text-center font-monospace fw-bold text-success bg-emerald-subtle"><?= $a ?></td>
                                <td class="text-center font-monospace fw-bold text-dark bg-warning-subtle"><?= $b ?></td>
                                <td class="text-end">
                                    <?php if ($p === $d && $d === $a): ?>
                                        <span class="badge bg-success-subtle text-success border border-success">Fully Administered</span>
                                    <?php elseif ($d > $a): ?>
                                        <span class="badge bg-warning-subtle text-warning border border-warning">In Administration (<?= $b ?> at bedside)</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted border">Undispensed (<?= $u ?> pending)</span>
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

<?php include __DIR__ . '/../../includes/footer.php'; ?>
