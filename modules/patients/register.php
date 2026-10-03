<?php
// modules/patients/register.php - Local Pharmacy Patient Registration (STEP 5)

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/PatientService.php';

use Pharmacy\Services\PatientService;

$patientService = new PatientService($pdo);

$error = '';
$successPatient = null;
$editId = !empty($_GET['edit_id']) ? (int)$_GET['edit_id'] : null;
$isEdit = false;

$user = auth_user();
$userId = (int)($user['id'] ?? 1);

// Pre-fill fields if arriving from hospital search or blank defaults
$formData = [
    'hospital_patient_id' => $_GET['hospital_patient_id'] ?? '',
    'hospital_uhid'       => $_GET['hospital_uhid'] ?? '',
    'name'                => $_GET['name'] ?? '',
    'mobile'              => $_GET['mobile'] ?? '',
    'gender'              => $_GET['gender'] ?? 'Other',
    'date_of_birth'       => '',
    'address'             => '',
    'city'                => '',
    'state'               => 'Maharashtra',
    'pincode'             => ''
];

if ($editId) {
    if (!has_permission('pharmacy.patients.edit') && !has_permission('pharmacy.patients.create')) {
        deny_access("Permission denied: Cannot edit patient records.");
    }
    $existingPatient = $patientService->getPatientById($editId);
    if ($existingPatient) {
        $isEdit = true;
        $page_title = 'Edit Pharmacy Patient';
        $formData = [
            'hospital_patient_id' => $existingPatient['hospital_patient_id'] ?? '',
            'hospital_uhid'       => $existingPatient['hospital_uhid'] ?? '',
            'name'                => $existingPatient['name'] ?? '',
            'mobile'              => $existingPatient['mobile'] ?? '',
            'gender'              => $existingPatient['gender'] ?? 'Other',
            'date_of_birth'       => $existingPatient['date_of_birth'] ?? '',
            'address'             => $existingPatient['address'] ?? '',
            'city'                => $existingPatient['city'] ?? '',
            'state'               => $existingPatient['state'] ?? 'Maharashtra',
            'pincode'             => $existingPatient['pincode'] ?? ''
        ];
    } else {
        $error = "Patient record #{$editId} not found.";
    }
} else {
    require_permission('pharmacy.patients.create');
    $page_title = 'Register Pharmacy Patient';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $formData = [
        'hospital_patient_id' => trim($_POST['hospital_patient_id'] ?? '') ?: null,
        'hospital_uhid'       => trim($_POST['hospital_uhid'] ?? '') ?: null,
        'name'                => trim($_POST['name'] ?? ''),
        'mobile'              => trim($_POST['mobile'] ?? ''),
        'gender'              => $_POST['gender'] ?? 'Other',
        'date_of_birth'       => trim($_POST['date_of_birth'] ?? '') ?: null,
        'address'             => trim($_POST['address'] ?? ''),
        'city'                => trim($_POST['city'] ?? ''),
        'state'               => trim($_POST['state'] ?? ''),
        'pincode'             => trim($_POST['pincode'] ?? '')
    ];

    if ($formData['name'] === '') {
        $error = 'Patient name is required.';
    } else {
        try {
            if ($isEdit && $editId) {
                $successPatient = $patientService->updateLocalPatient($editId, $formData, $userId);
            } else {
                $successPatient = $patientService->registerLocalPatient($formData);
            }
        } catch (Exception $e) {
            $error = ($isEdit ? 'Failed to update' : 'Failed to register') . ' pharmacy patient: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="mb-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h4 class="fw-bold mb-1 text-dark">
                <?php if ($isEdit): ?>
                    <i class="ti ti-user-edit text-emerald me-1.5"></i> Edit Pharmacy Patient
                <?php else: ?>
                    <i class="ti ti-user-plus text-emerald me-1.5"></i> Register Pharmacy Patient
                <?php endif; ?>
            </h4>
            <p class="text-muted small mb-0">
                <?= $isEdit ? 'Modify demographic and contact details for existing patient (<strong>' . sanitize($existingPatient['pharmacy_patient_no'] ?? '') . '</strong>).' : 'Creates an independent <strong>Pharmacy Patient ID</strong> (<code>PP-XXXXXX</code>). Hospital links remain optional and nullable.' ?>
            </p>
        </div>
        <a href="<?= BASE_URL ?>modules/patients/list.php" class="btn btn-outline-secondary shadow-xs">
            <i class="ti ti-arrow-left me-1"></i> Patient Directory
        </a>
    </div>
</div>

<?php if ($successPatient): ?>
    <div class="card card-custom p-4 mb-4 border-start border-4 border-success">
        <div class="d-flex align-items-start gap-3">
            <div class="bg-success text-white rounded-circle p-2 d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px;">
                <i class="ti ti-check fs-4"></i>
            </div>
            <div class="flex-grow-1">
                <h5 class="fw-bold text-dark mb-1">
                    <?= $isEdit ? 'Pharmacy Patient Successfully Updated!' : 'Pharmacy Patient Successfully Registered!' ?>
                </h5>
                <p class="text-muted small mb-3">
                    Unique Pharmacy Patient Identifier: 
                    <span class="badge bg-primary fs-6 font-monospace px-2.5 py-1">
                        <?= sanitize($successPatient['pharmacy_patient_no']) ?>
                    </span>
                </p>

                <div class="row g-3 bg-light p-3 rounded-3 border mb-3">
                    <div class="col-md-3">
                        <span class="text-muted small d-block">Patient Name</span>
                        <span class="fw-bold text-dark"><?= sanitize($successPatient['name']) ?></span>
                    </div>
                    <div class="col-md-3">
                        <span class="text-muted small d-block">Mobile</span>
                        <span><?= sanitize($successPatient['mobile'] ?: '-') ?></span>
                    </div>
                    <div class="col-md-3">
                        <span class="text-muted small d-block">Hospital UHID</span>
                        <?php if ($successPatient['hospital_uhid']): ?>
                            <span class="badge bg-info-subtle text-info font-monospace"><?= sanitize($successPatient['hospital_uhid']) ?></span>
                        <?php else: ?>
                            <span class="text-muted small">None (Pharmacy Walk-in)</span>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-3">
                        <span class="text-muted small d-block">Gender</span>
                        <span><?= sanitize($successPatient['gender']) ?></span>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <a href="<?= BASE_URL ?>modules/patients/register.php" class="btn btn-sm btn-emerald">
                        <i class="ti ti-plus me-1"></i> Register Another Patient
                    </a>
                    <a href="<?= BASE_URL ?>modules/patients/list.php" class="btn btn-sm btn-outline-dark">
                        <i class="ti ti-users me-1"></i> View Patient Directory
                    </a>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger rounded-3 p-3 mb-4 d-flex align-items-center gap-2">
        <i class="ti ti-alert-circle fs-5"></i>
        <span><?= sanitize($error) ?></span>
    </div>
<?php endif; ?>

<div class="card card-custom p-4">
    <form method="POST" autocomplete="off">
        <?= csrf_field() ?>

        <h6 class="fw-bold text-dark mb-3 border-bottom pb-2">
            1. Hospital Record Linkage (Optional)
        </h6>
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <label class="form-label small fw-semibold text-dark" for="hospitalUhid">Hospital UHID (Optional)</label>
                <input type="text" id="hospitalUhid" name="hospital_uhid" class="form-control font-monospace" value="<?= sanitize($formData['hospital_uhid']) ?>" placeholder="e.g. VH2060 (Leave blank for walk-ins)">
                <div class="form-text text-muted" style="font-size: 0.72rem;">Links this record to an existing hospital patient without altering hospital tables.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label small fw-semibold text-dark" for="hospitalPatientId">Hospital Patient ID # (Optional)</label>
                <input type="number" id="hospitalPatientId" name="hospital_patient_id" class="form-control" value="<?= sanitize($formData['hospital_patient_id']) ?>" placeholder="e.g. 1042">
            </div>
        </div>

        <h6 class="fw-bold text-dark mb-3 border-bottom pb-2">
            2. Demographic Information
        </h6>
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label small fw-semibold text-dark" for="patientName">Patient Full Name <span class="text-danger">*</span></label>
                <input type="text" id="patientName" name="name" class="form-control" required value="<?= sanitize($formData['name']) ?>" placeholder="e.g. Ramesh Kulkarni">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold text-dark" for="patientMobile">Mobile Number</label>
                <input type="text" id="patientMobile" name="mobile" class="form-control" value="<?= sanitize($formData['mobile']) ?>" placeholder="e.g. 9876543210">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold text-dark" for="patientGender">Gender</label>
                <select id="patientGender" name="gender" class="form-select">
                    <option value="Male" <?= $formData['gender'] === 'Male' ? 'selected' : '' ?>>Male</option>
                    <option value="Female" <?= $formData['gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
                    <option value="Other" <?= $formData['gender'] === 'Other' ? 'selected' : '' ?>>Other</option>
                </select>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <label class="form-label small fw-semibold text-dark" for="patientDob">Date of Birth</label>
                <input type="date" id="patientDob" name="date_of_birth" class="form-control" value="<?= sanitize($formData['date_of_birth']) ?>">
            </div>
            <div class="col-md-8">
                <label class="form-label small fw-semibold text-dark" for="patientAddress">Address Line</label>
                <input type="text" id="patientAddress" name="address" class="form-control" value="<?= sanitize($formData['address']) ?>" placeholder="Street, Area, Landmark">
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <label class="form-label small fw-semibold text-dark" for="patientCity">City / Town</label>
                <input type="text" id="patientCity" name="city" class="form-control" value="<?= sanitize($formData['city']) ?>" placeholder="e.g. Baramati">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-semibold text-dark" for="patientState">State</label>
                <input type="text" id="patientState" name="state" class="form-control" value="<?= sanitize($formData['state']) ?>" placeholder="e.g. Maharashtra">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-semibold text-dark" for="patientPincode">Pincode</label>
                <input type="text" id="patientPincode" name="pincode" class="form-control" value="<?= sanitize($formData['pincode']) ?>" placeholder="e.g. 413102">
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2 pt-3 border-top">
            <a href="<?= BASE_URL ?>modules/patients/list.php" class="btn btn-light border px-4">Cancel</a>
            <button type="submit" class="btn btn-emerald px-5 fw-semibold">
                <i class="ti ti-check me-1"></i> <?= $isEdit ? 'Update Pharmacy Patient' : 'Register Pharmacy Patient' ?>
            </button>
        </div>
    </form>
</div>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
