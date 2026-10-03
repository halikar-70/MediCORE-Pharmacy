<?php
// modules/inventory/add.php - Add New Medicine Master

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

$page_title = 'Add Medicine';
$errors = [];
$warnings = [];

$duplicateMatches = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $name = trim($_POST['medicine_name'] ?? '');
    $confirmDuplicate = !empty($_POST['confirm_duplicate']);

    if ($name === '') {
        $errors[] = "Medicine name is required.";
    }

    // Duplicate detection check
    if ($name !== '' && !$confirmDuplicate) {
        $duplicateMatches = $medService->findPotentialDuplicates($name);
        if (!empty($duplicateMatches)) {
            $warnings[] = "A similar medicine already exists in the catalog. Please review before proceeding.";
        }
    }

    // Barcode check
    $barcode = trim($_POST['barcode'] ?? '');
    if ($barcode !== '' && $medService->isBarcodeTaken($barcode)) {
        $errors[] = "Barcode '{$barcode}' is already assigned to an existing medicine.";
    }

    if (empty($errors) && (empty($duplicateMatches) || $confirmDuplicate)) {
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
            $newId = $medService->createMedicine($data, $userId);

            // If initial batch info is provided
            $initialQty = (int)($_POST['initial_quantity'] ?? 0);
            $batchNo = trim($_POST['initial_batch_no'] ?? '');
            $expiry = trim($_POST['initial_expiry'] ?? '');

            if ($initialQty > 0 && $batchNo !== '' && $expiry !== '') {
                $batchService->createBatch([
                    'medicine_id'       => $newId,
                    'batch_number'      => $batchNo,
                    'expiry_date'       => $expiry,
                    'manufacturing_date'=> !empty($_POST['initial_mfg']) ? $_POST['initial_mfg'] : null,
                    'quantity_received' => $initialQty,
                    'quantity_available'=> $initialQty,
                    'purchase_price'    => (float)($_POST['purchase_price'] ?? 0.00),
                    'mrp'               => (float)($_POST['price'] ?? 0.00),
                    'sale_price'        => (float)($_POST['price'] ?? 0.00),
                    'shelf_location'    => trim($_POST['shelf'] ?? ''),
                    'transaction_type'  => 'OPENING_STOCK',
                    'reason'            => 'Initial opening stock entry during medicine creation'
                ], $userId);
            }

            flash('success', "Medicine '{$name}' created successfully!");
            redirect("profile.php?id={$newId}");
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }
}

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
                <i class="ti ti-plus text-emerald me-2"></i>Add New Medicine
            </h4>
            <p class="text-muted small mb-0">Register a new pharmaceutical formulation in the master catalog.</p>
        </div>
        <a href="products.php" class="btn btn-outline-secondary rounded-pill px-3 py-2 small">
            <i class="ti ti-arrow-left me-1"></i> Back to Catalog
        </a>
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

    <?php if (!empty($duplicateMatches)): ?>
        <div class="alert alert-warning rounded-4 border-0 shadow-sm mb-4 p-4">
            <div class="d-flex align-items-center mb-2">
                <i class="ti ti-alert-triangle fs-3 text-warning me-2"></i>
                <h5 class="fw-bold text-dark mb-0">Potential Duplicate Detected!</h5>
            </div>
            <p class="text-muted small mb-3">
                A medicine with a similar or identical name is already registered in the system:
            </p>
            <div class="list-group mb-3">
                <?php foreach ($duplicateMatches as $dm): ?>
                    <div class="list-group-item list-group-item-light d-flex justify-content-between align-items-center rounded-3 mb-1">
                        <div>
                            <strong><?= htmlspecialchars($dm['medicine_name']) ?></strong> 
                            <span class="text-muted small">(<?= htmlspecialchars($dm['generic_name'] ?? 'No generic') ?>, Strength: <?= htmlspecialchars($dm['strength'] ?? 'N/A') ?>)</span>
                        </div>
                        <a href="profile.php?id=<?= $dm['medicine_id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill">View Existing</a>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="small text-muted mb-3">
                If this is a different strength or formulation (e.g., 650mg vs 500mg), check the box below to authorize creation.
            </p>
        </div>
    <?php endif; ?>

    <form method="POST" class="card border-0 shadow-sm rounded-4 p-4 bg-white mb-5">
        <?= csrf_field() ?>

        <?php if (!empty($duplicateMatches)): ?>
            <input type="hidden" name="confirm_duplicate" value="1">
            <div class="form-check form-switch mb-4 p-3 bg-light rounded-3 border">
                <input class="form-check-input ms-0 me-2" type="checkbox" id="confirm_dup_check" required>
                <label class="form-check-label fw-bold text-dark" for="confirm_dup_check">
                    I confirm that this is a separate, distinct medicine and wish to create it anyway.
                </label>
            </div>
        <?php endif; ?>

        <!-- SECTION 1: Basic Pharmaceutical Information -->
        <h5 class="fw-bold text-dark border-bottom pb-2 mb-3">
            <span class="badge bg-emerald-subtle text-emerald rounded-circle p-2 me-1" style="background-color: #ecfdf5; color: #059669;">1</span>
            Basic Pharmaceutical Information
        </h5>
        <div class="row g-3 mb-4">
            <div class="col-12 col-md-6">
                <label class="form-label small fw-semibold text-dark">Medicine Name <span class="text-danger">*</span></label>
                <input type="text" name="medicine_name" class="form-control rounded-3" placeholder="e.g. Dolo 650, Augmentin 625 Duo" value="<?= htmlspecialchars($_POST['medicine_name'] ?? '') ?>" required>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label small fw-semibold text-dark">Generic / Salt Composition</label>
                <input type="text" name="generic_name" class="form-control rounded-3" placeholder="e.g. Paracetamol, Amoxicillin + Clavulanic Acid" value="<?= htmlspecialchars($_POST['generic_name'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label small fw-semibold text-dark">Strength / Potency</label>
                <input type="text" name="strength" class="form-control rounded-3" placeholder="e.g. 650 mg, 500 mg, 250 mg/5ml" value="<?= htmlspecialchars($_POST['strength'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label small fw-semibold text-dark">Dosage Form</label>
                <select name="dosage_form" class="form-select rounded-3">
                    <?php foreach ($dosageForms as $df): ?>
                        <option value="<?= $df ?>" <?= ($_POST['dosage_form'] ?? 'Tablet') === $df ? 'selected' : '' ?>><?= $df ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label small fw-semibold text-dark">Category</label>
                <select name="category" class="form-select rounded-3">
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat ?>" <?= ($_POST['category'] ?? 'Tablet') === $cat ? 'selected' : '' ?>><?= $cat ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label small fw-semibold text-dark">Brand / Manufacturer</label>
                <input type="text" name="manufacturer" class="form-control rounded-3" placeholder="e.g. Micro Labs, Cipla, Sun Pharma" value="<?= htmlspecialchars($_POST['manufacturer'] ?? '') ?>">
            </div>
        </div>

        <!-- SECTION 2: Packaging, Regulatory & Taxation -->
        <h5 class="fw-bold text-dark border-bottom pb-2 mb-3">
            <span class="badge bg-emerald-subtle text-emerald rounded-circle p-2 me-1" style="background-color: #ecfdf5; color: #059669;">2</span>
            Packaging, Regulatory & Taxation
        </h5>
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-dark">Unit of Measure (UOM)</label>
                <select name="unit" class="form-select rounded-3">
                    <?php foreach ($units as $u): ?>
                        <option value="<?= $u ?>" <?= ($_POST['unit'] ?? 'Strip') === $u ? 'selected' : '' ?>><?= $u ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-dark">Pack Size (Units/Pack)</label>
                <input type="text" name="pack_size" class="form-control rounded-3" placeholder="e.g. 10, 15, 100" value="<?= htmlspecialchars($_POST['pack_size'] ?? '10') ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-dark">Schedule Classification</label>
                <select name="schedule_type" class="form-select rounded-3">
                    <?php foreach ($schedules as $sch): ?>
                        <option value="<?= $sch ?>" <?= ($_POST['schedule_type'] ?? 'General') === $sch ? 'selected' : '' ?>><?= $sch ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-dark">HSN Code</label>
                <input type="text" name="hsn_code" class="form-control rounded-3" placeholder="e.g. 300490" value="<?= htmlspecialchars($_POST['hsn_code'] ?? '') ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-dark">GST Percentage (%)</label>
                <select name="gst_percent" class="form-select rounded-3">
                    <option value="0.00" <?= ($_POST['gst_percent'] ?? '') === '0.00' ? 'selected' : '' ?>>0% (Exempt)</option>
                    <option value="5.00" <?= ($_POST['gst_percent'] ?? '') === '5.00' ? 'selected' : '' ?>>5%</option>
                    <option value="12.00" <?= ($_POST['gst_percent'] ?? '12.00') === '12.00' ? 'selected' : '' ?>>12% (Standard Pharma)</option>
                    <option value="18.00" <?= ($_POST['gst_percent'] ?? '') === '18.00' ? 'selected' : '' ?>>18%</option>
                    <option value="28.00" <?= ($_POST['gst_percent'] ?? '') === '28.00' ? 'selected' : '' ?>>28%</option>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-dark">MRP / Sale Price (₹)</label>
                <input type="number" step="0.01" min="0" name="price" class="form-control rounded-3" placeholder="0.00" value="<?= htmlspecialchars($_POST['price'] ?? '0.00') ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-dark">Purchase Price (₹)</label>
                <input type="number" step="0.01" min="0" name="purchase_price" class="form-control rounded-3" placeholder="0.00" value="<?= htmlspecialchars($_POST['purchase_price'] ?? '0.00') ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-dark">Barcode (EAN/UPC)</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0"><i class="ti ti-barcode"></i></span>
                    <input type="text" name="barcode" class="form-control rounded-3 border-start-0" placeholder="Scan or enter barcode" value="<?= htmlspecialchars($_POST['barcode'] ?? '') ?>">
                </div>
            </div>
        </div>

        <!-- SECTION 3: Storage Location & Reorder Controls -->
        <h5 class="fw-bold text-dark border-bottom pb-2 mb-3">
            <span class="badge bg-emerald-subtle text-emerald rounded-circle p-2 me-1" style="background-color: #ecfdf5; color: #059669;">3</span>
            Storage Location & Reorder Controls
        </h5>
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-dark">Shelf / Rack</label>
                <input type="text" name="shelf" class="form-control rounded-3" placeholder="e.g. Shelf A-04, Rack 2" value="<?= htmlspecialchars($_POST['shelf'] ?? '') ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-dark">Box / Bin Location</label>
                <input type="text" name="box_bin" class="form-control rounded-3" placeholder="e.g. Bin-12" value="<?= htmlspecialchars($_POST['box_bin'] ?? '') ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold text-dark">Reorder Level</label>
                <input type="number" name="reorder_level" class="form-control rounded-3" value="<?= htmlspecialchars($_POST['reorder_level'] ?? '10') ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold text-dark">Min Stock</label>
                <input type="number" name="min_stock" class="form-control rounded-3" value="<?= htmlspecialchars($_POST['min_stock'] ?? '5') ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold text-dark">Status</label>
                <select name="status" class="form-select rounded-3">
                    <option value="Active" <?= ($_POST['status'] ?? 'Active') === 'Active' ? 'selected' : '' ?>>Active</option>
                    <option value="Inactive" <?= ($_POST['status'] ?? '') === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                    <option value="Discontinued" <?= ($_POST['status'] ?? '') === 'Discontinued' ? 'selected' : '' ?>>Discontinued</option>
                </select>
            </div>
        </div>

        <!-- SECTION 4: Optional Opening Batch Provisioning -->
        <h5 class="fw-bold text-dark border-bottom pb-2 mb-3">
            <span class="badge bg-secondary-subtle text-secondary rounded-circle p-2 me-1">4</span>
            Initial Opening Stock Batch (Optional)
        </h5>
        <p class="text-muted small mb-3">If you already have physical stock on hand, enter batch details below. It will automatically create an immutable Opening Stock ledger entry.</p>
        <div class="row g-3 mb-4 p-3 bg-light rounded-4">
            <div class="col-12 col-md-3">
                <label class="form-label small fw-semibold text-dark">Batch Number</label>
                <input type="text" name="initial_batch_no" class="form-control rounded-3 bg-white" placeholder="e.g. BAT-2026A">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-dark">Manufacturing Date</label>
                <input type="date" name="initial_mfg" class="form-control rounded-3 bg-white">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-dark">Expiry Date</label>
                <input type="date" name="initial_expiry" class="form-control rounded-3 bg-white">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label small fw-semibold text-dark">Initial Quantity</label>
                <input type="number" min="0" name="initial_quantity" class="form-control rounded-3 bg-white" placeholder="0">
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2">
            <a href="products.php" class="btn btn-light rounded-pill px-4">Cancel</a>
            <button type="submit" class="btn btn-emerald text-white rounded-pill px-5 fw-bold" style="background-color: #059669;">
                <i class="ti ti-check me-1"></i> Save Medicine
            </button>
        </div>
    </form>
</div>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
