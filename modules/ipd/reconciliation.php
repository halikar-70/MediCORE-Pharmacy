<?php
// modules/ipd/reconciliation.php - Clinical Medication Reconciliation (Prescribed vs Dispensed vs Administered)

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/DocumentSequenceService.php';
require_once __DIR__ . '/../../app/Services/StockLedgerService.php';
require_once __DIR__ . '/../../app/Services/FefoService.php';
require_once __DIR__ . '/../../app/Services/AuditService.php';
require_once __DIR__ . '/../../app/Services/PrescriptionService.php';
require_once __DIR__ . '/../../app/Services/MarService.php';

use Pharmacy\Services\MarService;
use Pharmacy\Services\PrescriptionService;

require_permission('pharmacy.dispensing.view');

$marService = new MarService($pdo);
$rxService = new PrescriptionService($pdo);

$page_title = 'IPD Medication Reconciliation Dashboard';

$ipdAdmissionNo = trim($_GET['ipd_admission_no'] ?? '');

// Get active IPD admissions list
$admissionsStmt = $pdo->query("
    SELECT DISTINCT ipd_admission_no, patient_name, ward, bed_number
    FROM pharmacy_prescriptions
    WHERE ipd_admission_no IS NOT NULL AND ipd_admission_no != ''
    ORDER BY prescription_id DESC LIMIT 30
");
$admissionsList = $admissionsStmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($ipdAdmissionNo) && !empty($admissionsList)) {
    $ipdAdmissionNo = $admissionsList[0]['ipd_admission_no'];
}

$patientInfo = null;
$prescriptions = [];
$reconciliations = [];

if (!empty($ipdAdmissionNo)) {
    $pStmt = $pdo->prepare("
        SELECT patient_name, patient_id, ipd_admission_no, ward, bed_number, doctor_name
        FROM pharmacy_prescriptions
        WHERE ipd_admission_no = ?
        ORDER BY prescription_id DESC LIMIT 1
    ");
    $pStmt->execute([$ipdAdmissionNo]);
    $patientInfo = $pStmt->fetch(PDO::FETCH_ASSOC);

    $rxStmt = $pdo->prepare("
        SELECT * FROM pharmacy_prescriptions 
        WHERE ipd_admission_no = ? 
        ORDER BY prescription_id DESC
    ");
    $rxStmt->execute([$ipdAdmissionNo]);
    $prescriptions = $rxStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($prescriptions as $rx) {
        $reconciliations[$rx['prescription_id']] = $marService->getAdministrationVariance((int)$rx['prescription_id']);
    }
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="container-fluid px-4 py-3">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-1"><i class="ti ti-scale text-emerald me-2"></i>Inpatient Medication Reconciliation</h4>
            <p class="text-muted small mb-0">Three-way audit separating Doctor Prescribing, Pharmacy Dispensing, and Nurse Administration.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= BASE_URL ?>modules/ipd/mar.php?ipd_admission_no=<?= urlencode($ipdAdmissionNo) ?>" class="btn btn-emerald btn-sm text-white">
                <i class="ti ti-clipboard-check me-1"></i>Open Patient MAR
            </a>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                <i class="ti ti-printer me-1"></i>Print Report
            </button>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="card card-custom border-0 shadow-sm mb-4">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-center">
                <div class="col-md-5">
                    <select name="ipd_admission_no" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Select Inpatient Admission</option>
                        <?php foreach ($admissionsList as $adm): ?>
                            <option value="<?= sanitize($adm['ipd_admission_no']) ?>" <?= $ipdAdmissionNo === $adm['ipd_admission_no'] ? 'selected' : '' ?>>
                                <?= sanitize($adm['patient_name']) ?> (IPD: <?= sanitize($adm['ipd_admission_no']) ?> | <?= sanitize($adm['ward'] ?? 'Ward') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-7 text-end">
                    <span class="badge bg-light text-dark border font-monospace py-1.5 px-3">
                        Invariant: Prescribed = Dispensed + Undispensed | Dispensed = Administered + Bedside Balance
                    </span>
                </div>
            </form>
        </div>
    </div>

    <?php if ($patientInfo): ?>
        <!-- Patient Banner -->
        <div class="card card-custom border-0 shadow-sm mb-4 bg-light">
            <div class="card-body p-3">
                <div class="row align-items-center">
                    <div class="col-md-4">
                        <small class="text-muted text-uppercase fw-semibold d-block" style="font-size: 0.72rem;">Patient</small>
                        <h5 class="fw-bold text-dark mb-0"><?= sanitize($patientInfo['patient_name']) ?></h5>
                        <div class="small font-monospace text-muted">IPD: <strong><?= sanitize($patientInfo['ipd_admission_no']) ?></strong></div>
                    </div>
                    <div class="col-md-3">
                        <small class="text-muted text-uppercase fw-semibold d-block" style="font-size: 0.72rem;">Location</small>
                        <span class="badge bg-secondary-subtle text-secondary"><?= sanitize($patientInfo['ward'] ?? '-') ?></span>
                        <span class="small fw-semibold ms-1">Bed <?= sanitize($patientInfo['bed_number'] ?? '-') ?></span>
                    </div>
                    <div class="col-md-3">
                        <small class="text-muted text-uppercase fw-semibold d-block" style="font-size: 0.72rem;">Prescribing Physician</small>
                        <div class="fw-semibold text-dark"><?= sanitize($patientInfo['doctor_name']) ?></div>
                    </div>
                    <div class="col-md-2 text-md-end">
                        <span class="badge bg-emerald-subtle text-emerald border border-emerald px-2.5 py-1.5 font-monospace">
                            <?= count($prescriptions) ?> Prescription(s)
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Reconciliation Tables for Each Prescription -->
        <?php foreach ($prescriptions as $rx): 
            $rxId = (int)$rx['prescription_id'];
            $items = $reconciliations[$rxId] ?? [];
        ?>
            <div class="card card-custom border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                    <div>
                        <span class="fw-bold text-dark fs-6">Prescription #<?= sanitize($rx['prescription_number']) ?></span>
                        <span class="badge bg-light text-dark border ms-2"><?= sanitize($rx['prescription_date']) ?></span>
                        <span class="badge bg-info-subtle text-info ms-1"><?= sanitize($rx['status']) ?></span>
                    </div>
                    <div class="small text-muted font-monospace">
                        Doctor: <?= sanitize($rx['doctor_name']) ?>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.88rem;">
                        <thead class="table-light text-muted text-uppercase" style="font-size: 0.75rem;">
                            <tr>
                                <th>Medicine Name</th>
                                <th class="text-center">Prescribed</th>
                                <th class="text-center bg-light">Dispensed (Stock Out)</th>
                                <th class="text-center">Undispensed</th>
                                <th class="text-center bg-emerald-subtle text-emerald">Administered (MAR)</th>
                                <th class="text-center bg-warning-subtle text-warning">Bedside Balance</th>
                                <th class="text-center">Clinical Events</th>
                                <th class="text-end">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($items)): ?>
                                <tr><td colspan="8" class="text-center py-4 text-muted">No items found for this prescription.</td></tr>
                            <?php else: ?>
                                <?php foreach ($items as $it): 
                                    $p = (int)$it['prescribed_qty'];
                                    $d = (int)$it['dispensed_qty'];
                                    $u = (int)$it['undispensed_qty'];
                                    $a = (int)$it['administered_qty'];
                                    $b = (int)$it['bedside_remaining'];
                                ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-dark"><?= sanitize($it['medicine_name']) ?></div>
                                            <div class="text-muted small"><?= sanitize($it['generic_name'] ?? '') ?></div>
                                        </td>
                                        <td class="text-center font-monospace fw-bold"><?= $p ?></td>
                                        <td class="text-center font-monospace fw-bold text-primary bg-light"><?= $d ?></td>
                                        <td class="text-center font-monospace text-muted"><?= $u ?></td>
                                        <td class="text-center font-monospace fw-bold text-success bg-emerald-subtle"><?= $a ?></td>
                                        <td class="text-center font-monospace fw-bold text-dark bg-warning-subtle"><?= $b ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-success-subtle text-success me-1"><?= $it['count_given'] ?> Given</span>
                                            <?php if ($it['count_held'] > 0): ?>
                                                <span class="badge bg-warning-subtle text-warning me-1"><?= $it['count_held'] ?> Held</span>
                                            <?php endif; ?>
                                            <?php if ($it['count_refused'] > 0): ?>
                                                <span class="badge bg-danger-subtle text-danger me-1"><?= $it['count_refused'] ?> Refused</span>
                                            <?php endif; ?>
                                            <?php if ($it['count_missed'] > 0): ?>
                                                <span class="badge bg-secondary-subtle text-secondary"><?= $it['count_missed'] ?> Missed</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($p === $d && $d === $a): ?>
                                                <span class="badge bg-success text-white"><i class="ti ti-check me-1"></i>Fully Administered</span>
                                            <?php elseif ($d > $a): ?>
                                                <span class="badge bg-warning text-dark"><i class="ti ti-clock me-1"></i>In Progress</span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-dark border">Pending Dispensing</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>

    <?php else: ?>
        <div class="card card-custom border-0 shadow-sm text-center py-5 text-muted">
            <i class="ti ti-scale fs-1 text-secondary d-block mb-2"></i>
            Please select an Inpatient Admission to view medication reconciliation.
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>