<?php
// modules/patients/search.php - Search Hospital Patient Directory First (STEP 5)

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/PatientService.php';

use Pharmacy\Services\PatientService;

require_permission('pharmacy.patients.view');

$page_title = 'Search Hospital Patient';
$patientService = new PatientService($pdo);

$query = trim($_GET['q'] ?? '');
$searchResults = null;

if ($query !== '') {
    $searchResults = $patientService->searchHospitalPatients($query);
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="mb-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h4 class="fw-bold mb-1 text-dark">
                <i class="ti ti-search text-emerald me-1.5"></i> Hospital Patient Search
            </h4>
            <p class="text-muted small mb-0">
                Rule: <strong>Search Hospital Patient First</strong>. Locate existing hospital records before registering a new pharmacy patient.
            </p>
        </div>
        <a href="<?= BASE_URL ?>modules/patients/register.php" class="btn btn-emerald shadow-xs">
            <i class="ti ti-user-plus me-1"></i> Register Pharmacy Patient
        </a>
    </div>
</div>

<!-- Search Input Card -->
<div class="card card-custom p-4 mb-4">
    <form method="GET" action="" class="row g-3">
        <div class="col-md-9">
            <label class="form-label fw-semibold small text-dark" for="searchQuery">Search by Hospital UHID, Mobile Number, or Patient Name</label>
            <div class="input-group">
                <span class="input-group-text bg-white text-muted"><i class="ti ti-search fs-5"></i></span>
                <input type="text" id="searchQuery" name="q" class="form-control form-control-lg fs-6" value="<?= sanitize($query) ?>" placeholder="e.g. VH2060, 9876543210, or John Doe" required autofocus>
            </div>
        </div>
        <div class="col-md-3 d-flex align-items-end">
            <button type="submit" class="btn btn-primary btn-lg w-100 fs-6 fw-semibold d-flex align-items-center justify-content-center gap-1.5">
                <i class="ti ti-search"></i> Search Directory
            </button>
        </div>
    </form>
</div>

<!-- Search Results Section -->
<?php if ($searchResults !== null): ?>
    <?php if (!$searchResults['success'] && ($searchResults['status'] === 'HOSPITAL_INTEGRATION_UNAVAILABLE')): ?>
        <div class="alert alert-warning rounded-3 border-warning-subtle p-3 mb-4 d-flex align-items-start gap-3">
            <i class="ti ti-alert-triangle fs-4 text-warning mt-0.5"></i>
            <div>
                <div class="fw-bold text-dark">Hospital Integration Unavailable</div>
                <div class="text-muted small mb-2"><?= sanitize($searchResults['message']) ?></div>
                <a href="<?= BASE_URL ?>modules/patients/register.php?search_query=<?= urlencode($query) ?>" class="btn btn-sm btn-outline-dark">
                    Proceed with Local Pharmacy Registration
                </a>
            </div>
        </div>
    <?php elseif (empty($searchResults['data'])): ?>
        <div class="card card-custom text-center py-5">
            <div class="text-muted mb-3">
                <i class="ti ti-user-search fs-1 text-secondary"></i>
            </div>
            <h5 class="fw-bold text-dark mb-1">No Hospital Patient Found</h5>
            <p class="text-muted small mb-3">
                No matching patient found in the hospital directory for "<strong><?= sanitize($query) ?></strong>".
            </p>
            <div>
                <a href="<?= BASE_URL ?>modules/patients/register.php?name=<?= urlencode($query) ?>" class="btn btn-emerald px-4">
                    <i class="ti ti-user-plus me-1"></i> Register as Pharmacy Patient
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="card card-custom">
            <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark">
                    Hospital Patients Found (<?= count($searchResults['data']) ?>)
                </h6>
                <span class="badge bg-success-subtle text-success font-monospace">HOSPITAL DATABASE MATCH</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size: 0.88rem;">
                    <thead class="table-light">
                        <tr>
                            <th>Hospital UHID</th>
                            <th>Patient Name</th>
                            <th>Gender / DOB</th>
                            <th>Mobile</th>
                            <th>City / Address</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($searchResults['data'] as $p): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-primary-subtle text-primary font-monospace fw-bold fs-6">
                                        <?= sanitize($p['uhid']) ?>
                                    </span>
                                    <div class="text-muted" style="font-size: 0.7rem;">ID: #<?= (int)$p['hospital_patient_id'] ?></div>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= sanitize($p['name']) ?></div>
                                </td>
                                <td>
                                    <div><?= sanitize($p['gender']) ?></div>
                                    <div class="text-muted small"><?= format_date($p['dob']) ?></div>
                                </td>
                                <td><?= sanitize($p['mobile'] ?: '-') ?></td>
                                <td>
                                    <div><?= sanitize($p['city'] ?: '-') ?></div>
                                    <div class="text-muted small text-truncate" style="max-width: 200px;"><?= sanitize($p['address'] ?: '') ?></div>
                                </td>
                                <td class="text-end">
                                    <a href="<?= BASE_URL ?>modules/patients/register.php?hospital_patient_id=<?= (int)$p['hospital_patient_id'] ?>&hospital_uhid=<?= urlencode($p['uhid']) ?>&name=<?= urlencode($p['name']) ?>&mobile=<?= urlencode($p['mobile'] ?? '') ?>&gender=<?= urlencode($p['gender'] ?? '') ?>" class="btn btn-sm btn-outline-primary" title="Link or Register in Pharmacy">
                                        <i class="ti ti-link me-1"></i> Reference in Pharmacy
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
