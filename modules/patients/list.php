<?php
// modules/patients/list.php - Registered Pharmacy Patients Directory

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';

require_permission('pharmacy.patients.view');

$page_title = 'Pharmacy Patients Directory';

$term = trim($_GET['q'] ?? '');
if ($term !== '') {
    $stmt = $pdo->prepare("
        SELECT * FROM pharmacy_patients 
        WHERE (pharmacy_patient_no LIKE ? OR name LIKE ? OR mobile LIKE ? OR hospital_uhid LIKE ?)
        ORDER BY id DESC LIMIT 100
    ");
    $like = '%' . $term . '%';
    $stmt->execute([$like, $like, $like, $like]);
} else {
    $stmt = $pdo->query("SELECT * FROM pharmacy_patients ORDER BY id DESC LIMIT 100");
}
$patients = $stmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-1 text-dark">
            <i class="ti ti-users text-emerald me-1.5"></i> Pharmacy Patients Directory
        </h4>
        <p class="text-muted small mb-0">Directory of registered local pharmacy patients with unique identifiers.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>modules/patients/search.php" class="btn btn-outline-primary shadow-xs">
            <i class="ti ti-search me-1"></i> Search Hospital First
        </a>
        <a href="<?= BASE_URL ?>modules/patients/register.php" class="btn btn-emerald shadow-xs">
            <i class="ti ti-user-plus me-1"></i> Register New Patient
        </a>
    </div>
</div>

<div class="card card-custom mb-4 p-3">
    <form method="GET" action="" class="row g-2">
        <div class="col-md-10">
            <input type="text" name="q" class="form-control" value="<?= sanitize($term) ?>" placeholder="Filter by Patient ID (PP-XXXXXX), Name, Mobile, or UHID...">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-dark w-100"><i class="ti ti-filter me-1"></i> Filter</button>
        </div>
    </form>
</div>

<div class="card card-custom">
    <div class="card-body p-0">
        <?php if (empty($patients)): ?>
            <div class="text-center py-5 text-muted">
                <i class="ti ti-user-off fs-1 text-secondary mb-2 d-block"></i>
                <p class="mb-2">No pharmacy patients found.</p>
                <a href="<?= BASE_URL ?>modules/patients/register.php" class="btn btn-sm btn-emerald">
                    <i class="ti ti-plus me-1"></i> Register Pharmacy Patient
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size: 0.88rem;">
                    <thead class="table-light">
                        <tr>
                            <th>Pharmacy ID</th>
                            <th>Patient Name</th>
                            <th>Gender / DOB</th>
                            <th>Mobile</th>
                            <th>Hospital UHID</th>
                            <th>City / State</th>
                            <th>Created</th>
                            <th class="text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($patients as $p): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-primary-subtle text-primary font-monospace fw-bold fs-6">
                                        <?= sanitize($p['pharmacy_patient_no']) ?>
                                    </span>
                                </td>
                                <td class="fw-semibold text-dark"><?= sanitize($p['name']) ?></td>
                                <td>
                                    <div><?= sanitize($p['gender']) ?></div>
                                    <div class="text-muted small"><?= format_date($p['date_of_birth']) ?></div>
                                </td>
                                <td><?= sanitize($p['mobile'] ?: '-') ?></td>
                                <td>
                                    <?php if ($p['hospital_uhid']): ?>
                                        <span class="badge bg-secondary-subtle text-secondary font-monospace"><?= sanitize($p['hospital_uhid']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted small">Walk-in</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= sanitize($p['city'] ?: '-') ?>, <?= sanitize($p['state'] ?: '-') ?></td>
                                <td class="text-muted small"><?= format_date($p['created_at']) ?></td>
                                <td class="text-end pe-4">
                                    <a href="register.php?edit_id=<?= $p['id'] ?>" class="btn btn-sm btn-light border rounded-pill px-2.5 text-nowrap" title="Edit Patient Details">
                                        <i class="ti ti-edit text-emerald me-1"></i>Edit
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
