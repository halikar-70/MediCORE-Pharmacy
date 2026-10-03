<?php
// modules/admin/settings.php - Pharmacy Configuration Settings (STEP 14)

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/ConfigService.php';
require_once __DIR__ . '/../../app/Services/AuditService.php';

use Pharmacy\Services\ConfigService;
use Pharmacy\Services\AuditService;

require_permission('pharmacy.settings.manage');

$page_title = 'Pharmacy Settings';
$configService = new ConfigService($pdo);
$auditService = new AuditService($pdo);

$successMsg = '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $fields = [
        'hospital_name', 'pharmacy_name', 'pharmacy_address',
        'drug_licence_number', 'gstin', 'state', 'state_code',
        'phone', 'email', 'invoice_prefix', 'currency',
        'near_expiry_warning_days', 'default_fefo_behaviour',
        'allow_expiry_override'
    ];

    $updatedCount = 0;
    $userId = auth_user()['id'] ?? null;

    try {
        $oldSettings = $configService->getAll();
        $newValues = [];

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $val = trim($_POST[$field]);
                $configService->set($field, $val, $userId);
                $newValues[$field] = $val;
                $updatedCount++;
            }
        }

        $auditService->log(
            'SETTINGS_UPDATE',
            'pharmacy_settings',
            'all',
            $oldSettings,
            $newValues,
            $userId
        );

        $successMsg = 'Pharmacy configuration updated successfully.';
    } catch (Exception $e) {
        $errorMsg = 'Error saving settings: ' . $e->getMessage();
    }
}

$settings = $configService->getAll();

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="mb-4">
    <h4 class="fw-bold mb-1 text-dark">
        <i class="ti ti-settings text-emerald me-1.5"></i> Pharmacy Configuration &amp; Settings
    </h4>
    <p class="text-muted small mb-0">Manage pharmacy metadata, drug licensing, tax details, and operational defaults.</p>
</div>

<?php if ($successMsg): ?>
    <div class="alert alert-success rounded-3 p-3 mb-4 d-flex align-items-center gap-2">
        <i class="ti ti-check fs-5"></i>
        <span><?= sanitize($successMsg) ?></span>
    </div>
<?php endif; ?>

<?php if ($errorMsg): ?>
    <div class="alert alert-danger rounded-3 p-3 mb-4 d-flex align-items-center gap-2">
        <i class="ti ti-alert-circle fs-5"></i>
        <span><?= sanitize($errorMsg) ?></span>
    </div>
<?php endif; ?>

<div class="card card-custom p-4">
    <!-- Active Brand Logo Preview -->
    <div class="p-3 bg-light rounded-3 mb-4 d-flex align-items-center gap-3 border">
        <div class="bg-white p-2 rounded-2 border shadow-xs d-flex align-items-center justify-content-center" style="min-width: 140px; height: 70px;">
            <img src="<?= BASE_URL ?>assets/images/vatsalya_logo.png" alt="Vatsalya Logo" style="max-height: 55px; max-width: 130px; object-fit: contain;">
        </div>
        <div>
            <div class="fw-bold text-dark mb-0">Official Vatsalya Hospital Brand Logo</div>
            <div class="text-muted small">Configured across login screen, sidebar navigation, top bar, browser favicon, and all receipts &amp; reports.</div>
        </div>
    </div>

    <form method="POST" autocomplete="off">
        <?= csrf_field() ?>

        <h6 class="fw-bold text-dark mb-3 border-bottom pb-2">1. Identity &amp; Legal Entity</h6>
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <label class="form-label small fw-semibold text-dark">Hospital Facility Name</label>
                <input type="text" name="hospital_name" class="form-control" value="<?= sanitize($settings['hospital_name']['value'] ?? '') ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label small fw-semibold text-dark">Pharmacy Trade Name</label>
                <input type="text" name="pharmacy_name" class="form-control" value="<?= sanitize($settings['pharmacy_name']['value'] ?? '') ?>" required>
            </div>
            <div class="col-12">
                <label class="form-label small fw-semibold text-dark">Pharmacy Counter Address</label>
                <input type="text" name="pharmacy_address" class="form-control" value="<?= sanitize($settings['pharmacy_address']['value'] ?? '') ?>" required>
            </div>
        </div>

        <h6 class="fw-bold text-dark mb-3 border-bottom pb-2">2. Licensing &amp; Tax Compliance</h6>
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <label class="form-label small fw-semibold text-dark">Drug Licence Number(s)</label>
                <input type="text" name="drug_licence_number" class="form-control font-monospace" value="<?= sanitize($settings['drug_licence_number']['value'] ?? '') ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-semibold text-dark">GSTIN</label>
                <input type="text" name="gstin" class="form-control font-monospace" value="<?= sanitize($settings['gstin']['value'] ?? '') ?>" required>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold text-dark">State</label>
                <input type="text" name="state" class="form-control" value="<?= sanitize($settings['state']['value'] ?? '') ?>" required>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold text-dark">State Code</label>
                <input type="text" name="state_code" class="form-control" value="<?= sanitize($settings['state_code']['value'] ?? '') ?>" required>
            </div>
        </div>

        <h6 class="fw-bold text-dark mb-3 border-bottom pb-2">3. Invoicing &amp; Operational Defaults</h6>
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <label class="form-label small fw-semibold text-dark">Invoice Number Prefix</label>
                <input type="text" name="invoice_prefix" class="form-control font-monospace" value="<?= sanitize($settings['invoice_prefix']['value'] ?? 'PH-INV-') ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold text-dark">Currency</label>
                <input type="text" name="currency" class="form-control" value="<?= sanitize($settings['currency']['value'] ?? 'INR') ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold text-dark">Near-Expiry Threshold (Days)</label>
                <input type="number" name="near_expiry_warning_days" class="form-control" value="<?= sanitize($settings['near_expiry_warning_days']['value'] ?? '90') ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold text-dark">Default FEFO Behavior</label>
                <select name="default_fefo_behaviour" class="form-select">
                    <option value="strict" <?= ($settings['default_fefo_behaviour']['value'] ?? '') === 'strict' ? 'selected' : '' ?>>Strict FEFO</option>
                    <option value="warning" <?= ($settings['default_fefo_behaviour']['value'] ?? '') === 'warning' ? 'selected' : '' ?>>Warning on Non-FEFO</option>
                </select>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2 pt-3 border-top">
            <button type="submit" class="btn btn-emerald px-4 fw-semibold">
                <i class="ti ti-device-floppy me-1"></i> Save Configuration
            </button>
        </div>
    </form>
</div>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
