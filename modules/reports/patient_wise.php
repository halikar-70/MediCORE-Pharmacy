<?php
// modules/reports/patient_wise.php - Patient-wise Sales Reports Shell

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';

require_permission('pharmacy.reports.view');

$page_title = 'Patient-wise Sales Reports';

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="card card-custom p-5 text-center my-4">
    <div class="mb-3">
        <span class="badge bg-emerald-subtle text-emerald p-3 rounded-circle fs-2" style="background-color: #ecfdf5; color: #059669;">
            <i class="ti ti-user-scan"></i>
        </span>
    </div>
    <h4 class="fw-bold text-dark mb-1">Patient-wise Sales Reports</h4>
    <p class="text-muted small mx-auto mb-4" style="max-width: 550px;">
        Patient-specific purchase history and ledger.
    </p>
    <div class="alert alert-info d-inline-block px-4 py-2 border-0 rounded-pill font-monospace small mx-auto">
        <i class="ti ti-calendar me-1"></i> Feature scheduled for Pharmacy Chunk 6 (Reports & Compliance)
    </div>
</div>

<?php
include __DIR__ . '/../../includes/footer.php';
?>