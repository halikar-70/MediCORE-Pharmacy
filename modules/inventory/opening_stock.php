<?php
// modules/inventory/opening_stock.php - Controlled Opening Stock Batch Provisioning

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/AuditService.php';
require_once __DIR__ . '/../../app/Services/StockLedgerService.php';
require_once __DIR__ . '/../../app/Services/BatchService.php';
require_once __DIR__ . '/../../app/Services/MedicineService.php';

require_permission('pharmacy.opening_stock.manage');

use Pharmacy\Services\BatchService;
use Pharmacy\Services\MedicineService;
use Pharmacy\Auth\AuthManager;

$batchService = new BatchService($pdo);
$medService = new MedicineService($pdo);

$page_title = 'Opening Stock Entry';
$errors = [];
$preselectedMedId = (int)($_GET['medicine_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $medId = (int)($_POST['medicine_id'] ?? 0);
    $batchNo = trim($_POST['batch_number'] ?? '');
    $expiry = trim($_POST['expiry_date'] ?? '');
    $mfg = !empty($_POST['manufacturing_date']) ? trim($_POST['manufacturing_date']) : null;
    $qty = (int)($_POST['quantity'] ?? 0);
    $purchaseRate = (float)($_POST['purchase_price'] ?? 0.00);
    $mrp = (float)($_POST['mrp'] ?? 0.00);
    $salePrice = (float)($_POST['sale_price'] ?? $mrp);
    $shelf = trim($_POST['shelf_location'] ?? '');
    $reason = trim($_POST['reason'] ?? 'Baseline physical inventory opening stock entry');

    if ($medId <= 0) {
        $errors[] = "Please select a valid medicine.";
    }
    if ($batchNo === '') {
        $errors[] = "Batch number cannot be blank.";
    }
    if ($expiry === '') {
        $errors[] = "Expiry date is mandatory.";
    }
    if ($qty <= 0) {
        $errors[] = "Opening stock quantity must be greater than zero.";
    }

    if (empty($errors)) {
        try {
            $userId = AuthManager::userId() ?? 1;
            $batchId = $batchService->createBatch([
                'medicine_id'       => $medId,
                'batch_number'      => $batchNo,
                'manufacturing_date'=> $mfg,
                'expiry_date'       => $expiry,
                'purchase_price'    => $purchaseRate,
                'mrp'               => $mrp,
                'sale_price'        => $salePrice,
                'quantity_received' => $qty,
                'quantity_available'=> $qty,
                'shelf_location'    => $shelf,
                'transaction_type'  => 'OPENING_STOCK',
                'reason'            => $reason
            ], $userId);

            flash('success', "Opening stock batch '{$batchNo}' ({$qty} units) created and recorded to ledger successfully!");
            redirect("profile.php?id={$medId}");
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$medicines = $pdo->query("SELECT medicine_id, medicine_name, generic_name, price, purchase_price, shelf FROM medicines WHERE deleted_at IS NULL ORDER BY medicine_name ASC")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold text-dark mb-1">
                <i class="ti ti-box-seam text-emerald me-2"></i>Opening Stock Entry
            </h4>
            <p class="text-muted small mb-0">Record physical baseline stock batches into the system with immediate, immutable ledger tracking.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="batch_stock.php" class="btn btn-outline-secondary rounded-pill px-3 py-2 small">
                <i class="ti ti-arrow-left me-1"></i> Back to Batches
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

    <form method="POST" class="card border-0 shadow-sm rounded-4 p-4 bg-white mb-5" style="max-width: 850px;">
        <?= csrf_field() ?>

        <div class="mb-3">
            <label class="form-label small fw-semibold text-dark">Select Medicine <span class="text-danger">*</span></label>
            <select name="medicine_id" id="medSelect" class="form-select rounded-3" required onchange="onMedSelect()">
                <option value="">-- Choose Medicine --</option>
                <?php foreach ($medicines as $m): ?>
                    <option value="<?= $m['medicine_id'] ?>" 
                            data-price="<?= $m['price'] ?>" 
                            data-cost="<?= $m['purchase_price'] ?>"
                            data-shelf="<?= htmlspecialchars($m['shelf'] ?? '') ?>"
                            <?= $preselectedMedId === (int)$m['medicine_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($m['medicine_name']) ?> (<?= htmlspecialchars($m['generic_name'] ?? 'General') ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-12 col-md-6">
                <label class="form-label small fw-semibold text-dark">Batch Number <span class="text-danger">*</span></label>
                <input type="text" name="batch_number" class="form-control rounded-3" placeholder="e.g. B-2026-X01" value="<?= htmlspecialchars($_POST['batch_number'] ?? '') ?>" required>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-dark">Manufacturing Date</label>
                <input type="date" name="manufacturing_date" class="form-control rounded-3" value="<?= htmlspecialchars($_POST['manufacturing_date'] ?? '') ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-dark">Expiry Date <span class="text-danger">*</span></label>
                <input type="date" name="expiry_date" class="form-control rounded-3" value="<?= htmlspecialchars($_POST['expiry_date'] ?? '') ?>" required>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-dark">Opening Quantity <span class="text-danger">*</span></label>
                <input type="number" min="1" name="quantity" class="form-control rounded-3" placeholder="Units" value="<?= htmlspecialchars($_POST['quantity'] ?? '') ?>" required>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-dark">Purchase Rate (₹)</label>
                <input type="number" step="0.01" min="0" name="purchase_price" id="costPrice" class="form-control rounded-3" placeholder="0.00" value="<?= htmlspecialchars($_POST['purchase_price'] ?? '') ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-dark">MRP (₹)</label>
                <input type="number" step="0.01" min="0" name="mrp" id="mrpPrice" class="form-control rounded-3" placeholder="0.00" value="<?= htmlspecialchars($_POST['mrp'] ?? '') ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold text-dark">Sale Price (₹)</label>
                <input type="number" step="0.01" min="0" name="sale_price" id="salePrice" class="form-control rounded-3" placeholder="0.00" value="<?= htmlspecialchars($_POST['sale_price'] ?? '') ?>">
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-12 col-md-4">
                <label class="form-label small fw-semibold text-dark">Physical Storage Location (Shelf / Rack)</label>
                <input type="text" name="shelf_location" id="shelfLocation" class="form-control rounded-3" placeholder="e.g. Rack B-02, Shelf 3" value="<?= htmlspecialchars($_POST['shelf_location'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-8">
                <label class="form-label small fw-semibold text-dark">Audit Source / Reason</label>
                <input type="text" name="reason" class="form-control rounded-3" value="<?= htmlspecialchars($_POST['reason'] ?? 'Physical baseline opening inventory count') ?>">
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2">
            <a href="products.php" class="btn btn-light rounded-pill px-4">Cancel</a>
            <button type="submit" class="btn btn-emerald text-white rounded-pill px-5 fw-bold" style="background-color: #059669;">
                <i class="ti ti-check me-1"></i> Commit Opening Stock
            </button>
        </div>
    </form>
</div>

<script>
function onMedSelect() {
    const sel = document.getElementById('medSelect');
    const opt = sel.selectedOptions[0];
    if (opt && opt.value) {
        if (opt.dataset.price && !document.getElementById('mrpPrice').value) {
            document.getElementById('mrpPrice').value = opt.dataset.price;
            document.getElementById('salePrice').value = opt.dataset.price;
        }
        if (opt.dataset.cost && !document.getElementById('costPrice').value) {
            document.getElementById('costPrice').value = opt.dataset.cost;
        }
        if (opt.dataset.shelf && !document.getElementById('shelfLocation').value) {
            document.getElementById('shelfLocation').value = opt.dataset.shelf;
        }
    }
}
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('medSelect').value) {
        onMedSelect();
    }
});
</script>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
