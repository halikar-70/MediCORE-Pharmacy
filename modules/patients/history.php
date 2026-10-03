<?php
// modules/patients/history.php - Patient Pharmacy History Placeholder Shell

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';

require_permission('pharmacy.patients.view');

$page_title = 'Patient Pharmacy History';

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="card card-custom p-5 text-center my-4">
    <div class="mb-3">
        <span class="badge bg-primary-subtle text-primary p-3 rounded-circle fs-2">
            <i class="ti ti-history"></i>
        </span>
    </div>
    <h4 class="fw-bold text-dark mb-1">Patient Pharmacy History</h4>
    <p class="text-muted small mx-auto mb-4" style="max-width: 500px;">
        Complete historical tracking of counter receipts, IPD dispenses, prescription fulfillments, and returns per patient.
    </p>
    <div class="alert alert-info d-inline-block px-4 py-2 border-0 rounded-pill font-monospace small mx-auto">
        <i class="ti ti-calendar me-1"></i> Feature scheduled for Pharmacy Chunk 4 (Patient History &amp; Record Linking)
    </div>
</div>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
