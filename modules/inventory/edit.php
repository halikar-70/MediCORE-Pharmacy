<?php
// modules/inventory/edit.php - Edit Medicine Master

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/AuditService.php';
require_once __DIR__ . '/../../app/Services/StockLedgerService.php';
require_once __DIR__ . '/../../app/Services/BatchService.php';
require_once __DIR__ . '/../../app/Services/MedicineService.php';

require_permission('pharmacy.medicines.manage');

use Pharmacy\Services\MedicineService;
use Pharmacy\Services\BatchService;
use Pharmacy\Auth\AuthManager;

$medService = new MedicineService($pdo);
$batchService = new BatchService($pdo);

$medicineId = (int)($_GET['id'] ?? 0);
$medicine = $medService->getMedicineById($medicineId);

if (!$medicine) {
    flash('error', 'Medicine not found.');
    redirect('products.php');
}

$page_title = 'Edit Medicine: ' . $medicine['medicine_name'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $name = trim($_POST['medicine_name'] ?? '');
    if ($name === '') {
        $errors[] = "Medicine name cannot be blank.";
    }

    $barcode = trim($_POST['barcode'] ?? '');
    if ($barcode !== '' && $medService->isBarcodeTaken($barcode, $medicineId)) {
        $errors[] = "Barcode '{$barcode}' is already assigned to another medicine.";
    }

    if (empty($errors)) {
        try {
            $data = [
                'medicine_name'     => $name,
                'generic_name'      => trim($_POST['generic_name'] ?? ''),
                'composition'       => trim($_POST['composition'] ?? ''),
                'strength'          => trim($_POST['strength'] ?? ''),
                'dosage_form'       => trim($_POST['dosage_form'] ?? 'Tablet'),
                'brand_name'        => trim($_POST['brand_name'] ?? ''),
                'category'          => trim($_POST['category'] ?? 'Tablet'),
                'unit'              => trim($_POST['unit'] ?? 'Strip'),
                'pack_size'         => trim($_POST['pack_size'] ?? '10'),
                'rack_location'     => trim($_POST['rack_location'] ?? ''),
                'shelf'             => trim($_POST['shelf'] ?? ''),
                'box_bin'           => trim($_POST['box_bin'] ?? ''),
                'schedule_type'     => trim($_POST['schedule_type'] ?? 'General'),
                'manufacturer'      => trim($_POST['manufacturer'] ?? ''),
                'hsn_code'          => trim($_POST['hsn_code'] ?? ''),
                'gst_percent'       => (float)($_POST['gst_percent'] ?? 0.00),
                'price'             => (float)($_POST['price'] ?? 0.00),
                'purchase_price'    => (float)($_POST['purchase_price'] ?? 0.00),
                'reorder_level'     => (int)($_POST['reorder_level'] ?? 10),
                'min_stock'         => (int)($_POST['min_stock'] ?? 5),
                'max_stock'         => (int)($_POST['max_stock'] ?? 1000),
                'reorder_qty'       => (int)($_POST['reorder_qty'] ?? 50),
                'barcode'           => $barcode,
                'alternate_barcode' => trim($_POST['alternate_barcode'] ?? ''),
                'status'            => trim($_POST['status'] ?? 'Active')
            ];

            $userId = AuthManager::userId() ?? 1;
            $medService->updateMedicine($medicineId, $data, $userId);

            flash('success', "Medicine '{$name}' updated successfully.");
            redirect("profile.php?id={$medicineId}");
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$batches = $batchService->getBatchesForMedicine($medicineId);

$categories = ['Tablet', 'Capsule', 'Syrup', 'Injection', 'Cream', 'Ointment', 'Drops', 'Powder', 'IV Fluid', 'Surgical/consumable', 'Other'];
$dosageForms = ['Tablet', 'Capsule', 'Syrup', 'Injection', 'Suspension', 'Gel', 'Ointment', 'Drops', 'Inhaler', 'Powder', 'Solution', 'Other'];
$schedules = ['General', 'OTC', 'Schedule H', 'Schedule H1', 'Schedule X', 'Other'];
$units = ['Strip', 'Bottle', 'Vial', 'Ampoule', 'Tube', 'Box', 'Piece', 'Sachet'];

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold text-dark mb-1">
                <i class="ti ti-edit text-emerald me-2"></i>Edit Medicine Details
            </h4>
            <p class="text-muted small mb-0">Update formulation parameters, packaging, pricing, or regulatory classification.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="profile.php?id=<?= $medicineId ?>" class="btn btn-outline-primary rounded-pill px-3 py-2 small">
                <i class="ti ti-eye me-1"></i> View Profile
            </a>
            <a href="products.php" class="btn btn-outline-secondary rounded-pill px-3 py-2 small">
                <i class="ti ti-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger rounded-4 border-0 shadow-sm mb-4">
            <h6 class="fw-bold mb-2"><i class="ti ti-alert-circle me-1"></i> Please correct the following errors:</h6>
            <ul class="mb-0 small">
                <?php foreach ($errors as $err): ?>
                    <li><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" class="card border-0 shadow-sm rounded-4 p-4 bg-white mb-4">
        <?= csrf_field() ?>

        <h5 class="fw-bold text-dark border-bottom pb-2 mb-3">
            <span class="badge bg-emerald-subtle text-emerald rounded-circle p-2 me-1" style="background-color: #ecfdf5; color: #059669;">1</span>
            Basic Information
        </h5>
        <div class="row g-3 mb-4">
            <div class="col-12 col-md-6">
                <label class="form-label small fw-semibold text-dark">Medicine Name <span class="text-danger">*</span></label>
                <input type="text" name="medicine_name" class="form-control rounded-3" value="<?= htmlspecialchars($medicine['medicine_name']) ?>" required>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label small fw-semibold text-dark">Generic / Salt Composition</label>
                <input type="text" name="generic_name" class="form-control rounded-3" value="<?= htmlspecialchars($medicine['generic_name'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label small fw-semibold text-dark">Strength / Potency</label>
                <input type="text" name="strength" class="form-control rounded-3" value="<?= htmlspecialchars($medicine['strength'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label small fw-semibold text-dark">Dosage Form</label>
                <select name="dosage_form" class="form-select rounded-3">
                    <?php foreach ($dosageForms as $df): ?>
                        <option value="<?= $df ?>" <?= ($medicine['dosage_form'] ?? 'Tablet') === $df ? 'selected' : '' ?>><?= $df ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label small fw-semibold text-dark">Category</label>
                <select name="category" class="form-select rounded-3">
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat ?>" <?= ($medicine['category'] ?? 'Tablet') === $cat ? 'selected' : '' ?>><?= $cat ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label small fw-semibold text-dark">Brand / Manufacturer</label>
                <input type="text" name="manufacturer" class="form-control rounded-3" value="<?= htmlspecialchars($medicine['manufacturer'] ?? '') ?>">
            </div>
        </div>

        <h5 class="fw-bold text-dark border-bottom pb-2 mb-3">
            <span class="badge bg-emerald-subtle text-emerald rounded-circle p-2 me-1" style="background-color: #ecfdf5; color: #059669;">2</span>
            Packaging, Regulatory & Pricing
        </h5>
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-dark">Unit of Measure</label>
                <select name="unit" class="form-select rounded-3">
                    <?php foreach ($units as $u): ?>
                        <option value="<?= $u ?>" <?= ($medicine['unit'] ?? 'Strip') === $u ? 'selected' : '' ?>><?= $u ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-dark">Pack Size</label>
                <input type="text" name="pack_size" class="form-control rounded-3" value="<?= htmlspecialchars($medicine['pack_size'] ?? '10') ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-dark">Schedule Classification</label>
                <select name="schedule_type" class="form-select rounded-3">
                    <?php foreach ($schedules as $sch): ?>
                        <option value="<?= $sch ?>" <?= ($medicine['schedule_type'] ?? 'General') === $sch ? 'selected' : '' ?>><?= $sch ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-dark">HSN Code</label>
                <input type="text" name="hsn_code" class="form-control rounded-3" value="<?= htmlspecialchars($medicine['hsn_code'] ?? '') ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-dark">GST Percentage (%)</label>
                <select name="gst_percent" class="form-select rounded-3">
                    <option value="0.00" <?= (float)$medicine['gst_percent'] === 0.0 ? 'selected' : '' ?>>0% (Exempt)</option>
                    <option value="5.00" <?= (float)$medicine['gst_percent'] === 5.0 ? 'selected' : '' ?>>5%</option>
                    <option value="12.00" <?= (float)$medicine['gst_percent'] === 12.0 ? 'selected' : '' ?>>12%</option>
                    <option value="18.00" <?= (float)$medicine['gst_percent'] === 18.0 ? 'selected' : '' ?>>18%</option>
                    <option value="28.00" <?= (float)$medicine['gst_percent'] === 28.0 ? 'selected' : '' ?>>28%</option>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-dark">Sale Price / MRP (₹)</label>
                <input type="number" step="0.01" min="0" name="price" class="form-control rounded-3" value="<?= htmlspecialchars($medicine['price']) ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-dark">Purchase Price (₹)</label>
                <input type="number" step="0.01" min="0" name="purchase_price" class="form-control rounded-3" value="<?= htmlspecialchars($medicine['purchase_price']) ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-dark">Barcode</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0"><i class="ti ti-barcode"></i></span>
                    <input type="text" name="barcode" class="form-control rounded-3 border-start-0" value="<?= htmlspecialchars($medicine['barcode'] ?? '') ?>">
                </div>
            </div>
        </div>

        <h5 class="fw-bold text-dark border-bottom pb-2 mb-3">
            <span class="badge bg-emerald-subtle text-emerald rounded-circle p-2 me-1" style="background-color: #ecfdf5; color: #059669;">3</span>
            Location & Controls
        </h5>
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-dark">Shelf / Rack</label>
                <input type="text" name="shelf" class="form-control rounded-3" value="<?= htmlspecialchars($medicine['shelf'] ?? $medicine['rack_location'] ?? '') ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-dark">Box / Bin Location</label>
                <input type="text" name="box_bin" class="form-control rounded-3" value="<?= htmlspecialchars($medicine['box_bin'] ?? '') ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold text-dark">Reorder Level</label>
                <input type="number" name="reorder_level" class="form-control rounded-3" value="<?= htmlspecialchars($medicine['reorder_level']) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold text-dark">Min Stock</label>
                <input type="number" name="min_stock" class="form-control rounded-3" value="<?= htmlspecialchars($medicine['min_stock']) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold text-dark">Status</label>
                <select name="status" class="form-select rounded-3">
                    <option value="Active" <?= $medicine['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                    <option value="Inactive" <?= $medicine['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                    <option value="Discontinued" <?= $medicine['status'] === 'Discontinued' ? 'selected' : '' ?>>Discontinued</option>
                </select>
            </div>
        </div>

        <!-- Controlled Stock Info -->
        <div class="alert alert-info border-0 rounded-4 p-3 d-flex justify-content-between align-items-center mb-4">
            <div>
                <i class="ti ti-lock me-2 fs-4 text-primary"></i>
                <span class="text-dark fw-semibold">Current Aggregate Stock:</span>
                <span class="badge bg-primary fs-6 ms-2"><?= number_format($medicine['stock_quantity']) ?> units</span>
                <span class="text-muted small ms-2">(Stock cannot be directly edited; must be adjusted via controlled adjustments)</span>
            </div>
            <a href="adjustments.php?medicine_id=<?= $medicineId ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3">
                <i class="ti ti-adjustments me-1"></i> Controlled Stock Adjustment
            </a>
        </div>

        <div class="d-flex justify-content-end gap-2">
            <a href="products.php" class="btn btn-light rounded-pill px-4">Cancel</a>
            <button type="submit" class="btn btn-emerald text-white rounded-pill px-5 fw-bold" style="background-color: #059669;">
                <i class="ti ti-check me-1"></i> Update Medicine
            </button>
        </div>
    </form>

    <!-- Active Batches for this Medicine -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white mb-5">
        <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
            <h6 class="fw-bold text-dark mb-0"><i class="ti ti-packages me-2 text-emerald"></i>Inventory Batches (<?= count($batches) ?>)</h6>
            <a href="opening_stock.php?medicine_id=<?= $medicineId ?>" class="btn btn-sm btn-outline-emerald rounded-pill px-3" style="color: #059669; border-color: #059669;">
                <i class="ti ti-plus me-1"></i> Add Batch
            </a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light text-muted small text-uppercase">
                    <tr>
                        <th class="ps-4">Batch Number</th>
                        <th>Expiry Date</th>
                        <th>Shelf Location</th>
                        <th class="text-end">MRP</th>
                        <th class="text-end">Available Units</th>
                        <th class="text-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($batches)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted small">No batches registered yet for this medicine.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($batches as $b): ?>
                            <tr>
                                <td class="ps-4 font-monospace fw-bold text-dark"><?= htmlspecialchars($b['batch_number']) ?></td>
                                <td>
                                    <span class="<?= $b['days_to_expiry'] < 0 ? 'text-danger fw-bold' : ($b['days_to_expiry'] <= 90 ? 'text-warning fw-semibold' : 'text-dark') ?>">
                                        <?= format_date($b['expiry_date']) ?>
                                    </span>
                                    <div class="text-muted small">
                                        <?php if ($b['days_to_expiry'] < 0): ?>
                                            (Expired <?= abs($b['days_to_expiry']) ?> days ago)
                                        <?php else: ?>
                                            (<?= $b['days_to_expiry'] ?> days left)
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($b['shelf_location'] ?? '-') ?></td>
                                <td class="text-end fw-semibold text-dark"><?= format_currency($b['mrp']) ?></td>
                                <td class="text-end fw-bold <?= $b['quantity_available'] > 0 ? 'text-dark' : 'text-muted' ?>">
                                    <?= number_format($b['quantity_available']) ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($b['status'] === 'Active'): ?>
                                        <span class="badge bg-success-subtle text-success rounded-pill px-2">Active</span>
                                    <?php elseif ($b['status'] === 'Expired'): ?>
                                        <span class="badge bg-danger text-white rounded-pill px-2">Expired</span>
                                    <?php elseif ($b['status'] === 'Depleted'): ?>
                                        <span class="badge bg-secondary-subtle text-secondary rounded-pill px-2">Depleted</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning-subtle text-warning rounded-pill px-2"><?= htmlspecialchars($b['status']) ?></span>
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

<?php
include __DIR__ . '/../../includes/footer.php';
?>
