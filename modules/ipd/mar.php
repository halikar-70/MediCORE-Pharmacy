<?php
// modules/ipd/mar.php - Inpatient Medication Administration Record (eMAR)

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

require_permission('pharmacy.mar.view');

$marService = new MarService($pdo);
$rxService = new PrescriptionService($pdo);

$page_title = 'Inpatient Medication Administration Record (MAR)';
$error = null;
$success = null;

// Handle Administration Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Security token mismatch. Please try again.";
    } else {
        $action = $_POST['action'] ?? '';

        // 1. Record Administration (Given, Held, Refused, Missed)
        if ($action === 'record_admin') {
            require_permission('pharmacy.mar.administer');
            try {
                $marId = (int)($_POST['mar_id'] ?? 0);
                $status = trim($_POST['status'] ?? 'GIVEN');
                $qty = (int)($_POST['administered_qty'] ?? 1);
                $dose = !empty($_POST['administered_dose']) ? (float)$_POST['administered_dose'] : null;
                $reason = trim($_POST['not_given_reason'] ?? '');
                $witness = trim($_POST['witnessed_by'] ?? '');
                $notes = trim($_POST['notes'] ?? '');

                $data = [
                    'administered_qty'  => $qty,
                    'administered_dose' => $dose,
                    'not_given_reason'  => $reason,
                    'witnessed_by'      => $witness,
                    'notes'             => $notes,
                    'actual_admin_time' => date('Y-m-d H:i:s'),
                    'idempotency_key'   => 'MAR-ADM-' . $marId . '-' . time() . '-' . rand(100, 999)
                ];

                $marService->recordAdministration($marId, $status, $data, $_SESSION['user_id'] ?? 1);
                $success = "Dose administration recorded successfully as {$status}!";
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }

        // 2. Correct Administration Record
        if ($action === 'correct_admin') {
            require_permission('pharmacy.mar.correct');
            try {
                $marId = (int)($_POST['mar_id'] ?? 0);
                $newStatus = trim($_POST['new_status'] ?? 'GIVEN');
                $reason = trim($_POST['correction_reason'] ?? '');
                $qty = (int)($_POST['administered_qty'] ?? 1);
                $dose = !empty($_POST['administered_dose']) ? (float)$_POST['administered_dose'] : null;

                if ($reason === '') {
                    throw new Exception("A clinical reason for correcting the MAR entry is strictly required.");
                }

                $data = [
                    'administered_qty'  => $qty,
                    'administered_dose' => $dose
                ];

                $marService->correctAdministration($marId, $newStatus, $data, $reason, $_SESSION['user_id'] ?? 1);
                $success = "MAR record #{$marId} corrected and audited successfully.";
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }

        // 3. Generate Schedules for Prescription
        if ($action === 'generate_schedules') {
            require_permission('pharmacy.mar.schedule');
            try {
                $rxId = (int)($_POST['prescription_id'] ?? 0);
                $created = $marService->generateSchedules($rxId, [], $_SESSION['user_id'] ?? 1);
                $success = "Generated " . count($created) . " timed administration schedules for prescription.";
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }
}

// Select IPD Admission and Date
$ipdAdmissionNo = trim($_GET['ipd_admission_no'] ?? '');
$selectedDate = trim($_GET['date'] ?? date('Y-m-d'));

// Get active IPD admissions list for quick selection
$admissionsStmt = $pdo->query("
    SELECT DISTINCT ipd_admission_no, patient_name, ward, bed_number
    FROM pharmacy_prescriptions
    WHERE ipd_admission_no IS NOT NULL AND ipd_admission_no != ''
      AND status NOT IN ('CANCELLED', 'EXPIRED')
    ORDER BY prescription_id DESC LIMIT 30
");
$admissionsList = $admissionsStmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($ipdAdmissionNo) && !empty($admissionsList)) {
    $ipdAdmissionNo = $admissionsList[0]['ipd_admission_no'];
}

$patientInfo = null;
$marSchedules = [];
$activePrescriptions = [];

if (!empty($ipdAdmissionNo)) {
    // Fetch patient info from prescriptions
    $pStmt = $pdo->prepare("
        SELECT patient_name, patient_id, ipd_admission_no, ward, bed_number, doctor_name
        FROM pharmacy_prescriptions
        WHERE ipd_admission_no = ?
        ORDER BY prescription_id DESC LIMIT 1
    ");
    $pStmt->execute([$ipdAdmissionNo]);
    $patientInfo = $pStmt->fetch(PDO::FETCH_ASSOC);

    // Fetch active prescriptions for this admission
    $rxStmt = $pdo->prepare("
        SELECT rx.*, 
               (SELECT COUNT(*) FROM pharmacy_mar_records WHERE prescription_id = rx.prescription_id) as mar_count
        FROM pharmacy_prescriptions rx
        WHERE rx.ipd_admission_no = ? AND rx.status NOT IN ('CANCELLED', 'EXPIRED')
        ORDER BY rx.prescription_id DESC
    ");
    $rxStmt->execute([$ipdAdmissionNo]);
    $activePrescriptions = $rxStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch MAR schedules for selected date
    $marSchedules = $marService->getPatientMar($ipdAdmissionNo, $selectedDate);
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="container-fluid px-4 py-3">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-1"><i class="ti ti-clipboard-check text-emerald me-2"></i>Medication Administration Record (eMAR)</h4>
            <p class="text-muted small mb-0">Nurse administration documentation. Documents clinical doses given, held, refused, or missed.</p>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                <i class="ti ti-printer me-1"></i>Print MAR Sheet
            </button>
            <a href="<?= BASE_URL ?>modules/ipd/queue.php" class="btn btn-emerald btn-sm text-white">
                <i class="ti ti-inbox me-1"></i>Dispensing Queue
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

    <!-- Filter Bar -->
    <div class="card card-custom border-0 shadow-sm mb-4">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-center">
                <div class="col-md-4">
                    <select name="ipd_admission_no" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Select Inpatient Admission</option>
                        <?php foreach ($admissionsList as $adm): ?>
                            <option value="<?= sanitize($adm['ipd_admission_no']) ?>" <?= $ipdAdmissionNo === $adm['ipd_admission_no'] ? 'selected' : '' ?>>
                                <?= sanitize($adm['patient_name']) ?> (IPD: <?= sanitize($adm['ipd_admission_no']) ?> | <?= sanitize($adm['ward'] ?? 'Ward') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light text-muted">Date</span>
                        <input type="date" name="date" class="form-control" value="<?= sanitize($selectedDate) ?>" onchange="this.form.submit()">
                    </div>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-dark w-100"><i class="ti ti-search me-1"></i>Load MAR</button>
                </div>
                <div class="col-md-3 text-end">
                    <span class="badge bg-secondary-subtle text-secondary py-1.5 px-2 font-monospace">
                        <i class="ti ti-info-circle me-1"></i>MAR strictly does NOT alter pharmacy stock
                    </span>
                </div>
            </form>
        </div>
    </div>

    <?php if ($patientInfo): ?>
        <!-- Patient Banner -->
        <div class="card card-custom border-0 shadow-sm mb-4 bg-emerald-subtle border-start border-emerald border-4">
            <div class="card-body p-3">
                <div class="row align-items-center">
                    <div class="col-md-4">
                        <div class="text-muted small text-uppercase fw-semibold" style="font-size: 0.72rem;">Patient Information</div>
                        <h5 class="fw-bold text-dark mb-0"><?= sanitize($patientInfo['patient_name']) ?></h5>
                        <div class="small font-monospace text-muted">IPD Admission: <strong><?= sanitize($patientInfo['ipd_admission_no']) ?></strong></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small text-uppercase fw-semibold" style="font-size: 0.72rem;">Location</div>
                        <span class="badge bg-emerald text-white px-2 py-1"><?= sanitize($patientInfo['ward'] ?? 'Ward') ?></span>
                        <span class="ms-1 fw-bold text-dark">Bed: <?= sanitize($patientInfo['bed_number'] ?? '-') ?></span>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small text-uppercase fw-semibold" style="font-size: 0.72rem;">Primary Prescriber</div>
                        <div class="fw-semibold text-dark"><i class="ti ti-stethoscope me-1 text-emerald"></i><?= sanitize($patientInfo['doctor_name']) ?></div>
                    </div>
                    <div class="col-md-2 text-md-end">
                        <a href="reconciliation.php?ipd_admission_no=<?= urlencode($ipdAdmissionNo) ?>" class="btn btn-sm btn-outline-dark">
                            <i class="ti ti-scale me-1"></i>Reconciliation
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Prescriptions MAR Schedule Generator check -->
        <?php foreach ($activePrescriptions as $rx): ?>
            <?php if ((int)$rx['mar_count'] === 0): ?>
                <div class="alert alert-warning border-0 shadow-sm d-flex justify-content-between align-items-center p-3 mb-4">
                    <div>
                        <i class="ti ti-alert-triangle fs-4 me-2"></i>
                        Prescription <strong><?= sanitize($rx['prescription_number']) ?></strong> does not have scheduled administration slots generated yet.
                    </div>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <input type="hidden" name="action" value="generate_schedules">
                        <input type="hidden" name="prescription_id" value="<?= (int)$rx['prescription_id'] ?>">
                        <button type="submit" class="btn btn-sm btn-dark px-3 fw-semibold">
                            <i class="ti ti-calendar-plus me-1"></i>Generate MAR Schedule Slots
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>

        <!-- MAR Sheet Table -->
        <div class="card card-custom border-0 shadow-sm">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark">
                    <i class="ti ti-calendar-time text-emerald me-2"></i>Administration Grid — <?= date('d M Y (l)', strtotime($selectedDate)) ?>
                </h6>
                <div class="d-flex gap-2">
                    <span class="badge bg-success-subtle text-success border border-success"><i class="ti ti-check me-1"></i>GIVEN</span>
                    <span class="badge bg-warning-subtle text-warning border border-warning"><i class="ti ti-player-pause me-1"></i>HELD</span>
                    <span class="badge bg-danger-subtle text-danger border border-danger"><i class="ti ti-ban me-1"></i>REFUSED</span>
                    <span class="badge bg-secondary-subtle text-secondary border border-secondary"><i class="ti ti-clock-pause me-1"></i>MISSED</span>
                    <span class="badge bg-light text-dark border"><i class="ti ti-clock me-1"></i>SCHEDULED</span>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0" style="font-size: 0.88rem;">
                    <thead class="table-light text-muted text-uppercase" style="font-size: 0.74rem;">
                        <tr>
                            <th style="width: 250px;">Medication Order</th>
                            <th>Scheduled Time</th>
                            <th>Scheduled Dose</th>
                            <th>Administration Status</th>
                            <th>Administered Time & Staff</th>
                            <th>Clinical Notes / Reason</th>
                            <th class="text-end" style="width: 140px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($marSchedules)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="ti ti-calendar-event fs-1 text-secondary d-block mb-2"></i>
                                    No administration doses scheduled for this patient on <?= sanitize($selectedDate) ?>.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($marSchedules as $slot): 
                                $status = $slot['status'];
                                $badgeClass = 'bg-light text-dark border';
                                if ($status === 'GIVEN') $badgeClass = 'bg-success text-white';
                                elseif ($status === 'HELD') $badgeClass = 'bg-warning text-dark';
                                elseif ($status === 'REFUSED') $badgeClass = 'bg-danger text-white';
                                elseif ($status === 'MISSED') $badgeClass = 'bg-secondary text-white';
                                elseif ($status === 'CANCELLED') $badgeClass = 'bg-light text-muted text-decoration-line-through border';
                            ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark"><?= sanitize($slot['medicine_name']) ?></div>
                                        <div class="text-muted small"><?= sanitize($slot['dosage_form'] ?? '') ?> • Route: <span class="fw-semibold text-primary"><?= sanitize($slot['route']) ?></span></div>
                                        <div class="text-muted small"><?= sanitize($slot['frequency'] ?? '') ?></div>
                                    </td>
                                    <td class="font-monospace fw-bold text-dark fs-6">
                                        <?= date('h:i A', strtotime($slot['scheduled_time'])) ?>
                                    </td>
                                    <td>
                                        <span class="fw-bold"><?= (float)$slot['scheduled_dose'] ?></span> <?= sanitize($slot['dose_unit']) ?>
                                    </td>
                                    <td>
                                        <span class="badge <?= $badgeClass ?> px-2.5 py-1 font-monospace" style="font-size: 0.78rem;">
                                            <?= sanitize($status) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($slot['actual_admin_time']): ?>
                                            <div class="fw-semibold text-dark"><?= date('h:i A', strtotime($slot['actual_admin_time'])) ?></div>
                                            <div class="small text-muted"><i class="ti ti-user me-1"></i><?= sanitize($slot['administered_by_name'] ?? 'Staff') ?></div>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($slot['not_given_reason']): ?>
                                            <span class="badge bg-danger-subtle text-danger mb-1"><?= sanitize($slot['not_given_reason']) ?></span>
                                        <?php endif; ?>
                                        <?php if ($slot['notes']): ?>
                                            <div class="small text-muted"><?= sanitize($slot['notes']) ?></div>
                                        <?php endif; ?>
                                        <?php if (!$slot['not_given_reason'] && !$slot['notes']): ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($status === 'SCHEDULED'): ?>
                                            <button type="button" class="btn btn-sm btn-emerald text-white px-3 fw-semibold"
                                                    onclick="openAdminModal(<?= htmlspecialchars(json_encode($slot), ENT_QUOTES, 'UTF-8') ?>)">
                                                <i class="ti ti-check me-1"></i>Record
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-outline-secondary px-2"
                                                    onclick="openCorrectModal(<?= htmlspecialchars(json_encode($slot), ENT_QUOTES, 'UTF-8') ?>)">
                                                <i class="ti ti-edit me-1"></i>Correct
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

    <?php else: ?>
        <div class="card card-custom border-0 shadow-sm text-center py-5 text-muted">
            <i class="ti ti-users fs-1 text-secondary d-block mb-2"></i>
            Please select an Inpatient Admission to view the Medication Administration Record (MAR).
        </div>
    <?php endif; ?>
</div>

<!-- Record Administration Modal -->
<div class="modal fade" id="adminModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="record_admin">
                <input type="hidden" name="mar_id" id="modal_mar_id">

                <div class="modal-header bg-emerald text-white">
                    <h5 class="modal-title fs-6 fw-bold"><i class="ti ti-check-circle me-2"></i>Record Clinical Administration</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="p-3 bg-light rounded-3 mb-3 border">
                        <div class="d-flex justify-content-between">
                            <span class="fw-bold text-dark" id="modal_med_name">-</span>
                            <span class="badge bg-primary-subtle text-primary font-monospace" id="modal_scheduled_time">-</span>
                        </div>
                        <div class="small text-muted mt-1">
                            Scheduled Dose: <strong id="modal_scheduled_dose">-</strong>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Administration Outcome <span class="text-danger">*</span></label>
                        <select name="status" id="modal_status_select" class="form-select fw-semibold" onchange="toggleReasonField(this.value)" required>
                            <option value="GIVEN" class="text-success fw-bold">✓ GIVEN (Administered to Patient)</option>
                            <option value="HELD" class="text-warning fw-bold">⏸ HELD (Clinically Suspended by Doctor/Nurse)</option>
                            <option value="REFUSED" class="text-danger fw-bold">✗ REFUSED (Patient Declined Dose)</option>
                            <option value="MISSED" class="text-secondary fw-bold">⏱ MISSED (Patient Absent / Omitted)</option>
                        </select>
                    </div>

                    <div id="givenFields">
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label small fw-semibold text-muted">Administered Qty</label>
                                <input type="number" name="administered_qty" id="modal_admin_qty" class="form-control font-monospace" value="1" min="1">
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-semibold text-muted">Administered Dose</label>
                                <input type="number" step="0.01" name="administered_dose" id="modal_admin_dose" class="form-control font-monospace">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-semibold text-muted">Witness Staff (Optional for High-Alert Drugs)</label>
                            <input type="text" name="witnessed_by" class="form-control form-control-sm" placeholder="e.g. Sister Mary">
                        </div>
                    </div>

                    <div class="mb-3 d-none" id="reasonGroup">
                        <label class="form-label small fw-bold text-danger">Mandatory Reason (Why not given) <span class="text-danger">*</span></label>
                        <select name="not_given_reason" id="modal_reason_select" class="form-select form-select-sm mb-2">
                            <option value="">Select Clinical Reason</option>
                            <option value="NPO for Procedure / Surgery">NPO for Procedure / Surgery</option>
                            <option value="Vomiting / Unable to Tolerate Oral Medication">Vomiting / Unable to Tolerate Oral Medication</option>
                            <option value="Patient Sleeping / Rested">Patient Sleeping / Rested</option>
                            <option value="Patient Refused Dose">Patient Refused Dose</option>
                            <option value="Low Blood Pressure / Vital Sign Threshold">Low Blood Pressure / Vital Sign Threshold</option>
                            <option value="High Blood Sugar / Hypoglycemia">High Blood Sugar / Hypoglycemia</option>
                            <option value="Doctor Verbal Hold Order">Doctor Verbal Hold Order</option>
                            <option value="Patient Outside Ward for Diagnostics">Patient Outside Ward for Diagnostics</option>
                            <option value="Other Clinical Reason">Other Clinical Reason</option>
                        </select>
                        <input type="text" name="custom_reason" id="modal_custom_reason" class="form-control form-control-sm" placeholder="Specify clinical details...">
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-muted">Clinical Notes (Optional)</label>
                        <textarea name="notes" class="form-control form-control-sm" rows="2" placeholder="e.g. Vitals normal, taken with water"></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light p-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-emerald text-white px-4 fw-bold">
                        <i class="ti ti-check me-1"></i>Save MAR Record
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Auditable Correction Modal -->
<div class="modal fade" id="correctModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="correct_admin">
                <input type="hidden" name="mar_id" id="correct_mar_id">

                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fs-6 fw-bold"><i class="ti ti-edit me-2"></i>Auditable MAR Correction</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="alert alert-warning small border-0 mb-3">
                        <i class="ti ti-alert-circle me-1"></i>
                        Healthcare compliance rule: MAR records cannot be destructively altered. This action creates a permanent, auditable correction event.
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold">New Administration Status <span class="text-danger">*</span></label>
                        <select name="new_status" class="form-select fw-semibold" required>
                            <option value="GIVEN">GIVEN</option>
                            <option value="HELD">HELD</option>
                            <option value="REFUSED">REFUSED</option>
                            <option value="MISSED">MISSED</option>
                            <option value="CANCELLED">CANCELLED</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Mandatory Correction Reason <span class="text-danger">*</span></label>
                        <input type="text" name="correction_reason" class="form-control" placeholder="e.g. Charted on wrong timeslot / Nurse correction" required>
                    </div>
                </div>
                <div class="modal-footer bg-light p-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-dark px-4 fw-bold">
                        <i class="ti ti-check me-1"></i>Save Correction Event
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openAdminModal(slot) {
    document.getElementById('modal_mar_id').value = slot.mar_id;
    document.getElementById('modal_med_name').innerText = slot.medicine_name;
    document.getElementById('modal_scheduled_time').innerText = slot.scheduled_time;
    document.getElementById('modal_scheduled_dose').innerText = slot.scheduled_dose + ' ' + slot.dose_unit;
    document.getElementById('modal_admin_dose').value = slot.scheduled_dose;
    document.getElementById('modal_status_select').value = 'GIVEN';
    toggleReasonField('GIVEN');

    new bootstrap.Modal(document.getElementById('adminModal')).show();
}

function openCorrectModal(slot) {
    document.getElementById('correct_mar_id').value = slot.mar_id;
    new bootstrap.Modal(document.getElementById('correctModal')).show();
}

function toggleReasonField(status) {
    const reasonGrp = document.getElementById('reasonGroup');
    const givenFields = document.getElementById('givenFields');
    const reasonSelect = document.getElementById('modal_reason_select');

    if (status === 'GIVEN') {
        reasonGrp.classList.add('d-none');
        givenFields.classList.remove('d-none');
        reasonSelect.required = false;
    } else {
        reasonGrp.classList.remove('d-none');
        givenFields.classList.add('d-none');
        reasonSelect.required = true;
    }
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
