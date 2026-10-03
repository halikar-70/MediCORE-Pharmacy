<?php
// modules/inventory/adjustments.php - Controlled Stock Adjustments

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/AuditService.php';
require_once __DIR__ . '/../../app/Services/DocumentSequenceService.php';
require_once __DIR__ . '/../../app/Services/StockLedgerService.php';
require_once __DIR__ . '/../../app/Services/BatchService.php';
require_once __DIR__ . '/../../app/Services/StockAdjustmentService.php';

require_permission('pharmacy.adjustments.manage');

use Pharmacy\Services\StockAdjustmentService;
use Pharmacy\Services\BatchService;
use Pharmacy\Auth\AuthManager;

$adjService = new StockAdjustmentService($pdo);
$batchService = new BatchService($pdo);

$page_title = 'Stock Adjustments';
$errors = [];
$successMessage = null;

$activeMode = trim($_GET['mode'] ?? 'direct_in');
if (!in_array($activeMode, ['direct_in', 'direct_out', 'standard'], true)) {
    $activeMode = 'direct_in';
}

$preselectedMedId = (int)($_GET['medicine_id'] ?? 0);
$preselectedBatchId = (int)($_GET['batch_id'] ?? 0);
$preselectedType = trim($_GET['type'] ?? 'Count Reconciliation');

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? 'adjust';

    try {
        $userId = AuthManager::userId() ?? 1;

        if ($action === 'direct_stock_in') {
            require_permission('pharmacy.adjustments.manage');

            $medId = (int)($_POST['medicine_id'] ?? 0);
            $qty = (int)($_POST['quantity'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            $refNo = trim($_POST['reference_no'] ?? '');
            $isNew = !empty($_POST['is_new_batch']);

            $data = [
                'medicine_id'        => $medId,
                'quantity'           => $qty,
                'reason'             => $reason,
                'reference_no'       => $refNo,
                'is_new_batch'       => $isNew,
                'batch_id'           => (int)($_POST['batch_id'] ?? 0),
                'batch_number'       => trim($_POST['batch_number'] ?? ''),
                'expiry_date'        => trim($_POST['expiry_date'] ?? ''),
                'manufacturing_date' => trim($_POST['manufacturing_date'] ?? ''),
                'purchase_price'     => (float)($_POST['purchase_price'] ?? 0.00),
                'mrp'                => (float)($_POST['mrp'] ?? 0.00),
                'sale_price'         => (float)($_POST['sale_price'] ?? 0.00),
                'shelf_location'     => trim($_POST['shelf_location'] ?? ''),
                'supplier_id'        => !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null
            ];

            $res = $adjService->directStockIn($data, $userId);
            $successMessage = "Direct Stock In #{$res['adjustment_no']} recorded successfully! +{$res['quantity']} units added to batch '{$res['batch_number']}'. Total batch stock is now {$res['new_quantity']} units.";
            $activeMode = 'direct_in';

        } elseif ($action === 'direct_stock_out') {
            require_permission('pharmacy.adjustments.manage');

            $medId = (int)($_POST['medicine_id'] ?? 0);
            $bId = (int)($_POST['batch_id'] ?? 0);
            $qty = (int)($_POST['quantity'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            $refNo = trim($_POST['reference_no'] ?? '');

            $data = [
                'medicine_id'  => $medId,
                'batch_id'     => $bId,
                'quantity'     => $qty,
                'reason'       => $reason,
                'reference_no' => $refNo
            ];

            $res = $adjService->directStockOut($data, $userId);
            $successMessage = "Direct Stock Out #{$res['adjustment_no']} processed successfully! -{$res['quantity']} units removed from batch '{$res['batch_number']}'. Remaining batch stock is {$res['new_quantity']} units.";
            $activeMode = 'direct_out';

        } elseif ($action === 'update_notes') {
            require_permission('pharmacy.adjustments.manage');

            $adjId = (int)($_POST['adjustment_id'] ?? 0);
            $newReason = trim($_POST['reason'] ?? '');
            $newRefNo = trim($_POST['reference_no'] ?? '');

            $adjService->updateAdjustmentNotes($adjId, $newReason, $newRefNo, $userId);
            $successMessage = "Adjustment record #{$adjId} audit notes updated successfully.";

        } else {
            // Standard adjustment
            $medId = (int)($_POST['medicine_id'] ?? 0);
            $bId = (int)($_POST['batch_id'] ?? 0);
            $type = trim($_POST['adjustment_type'] ?? '');
            $qty = (int)($_POST['quantity'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');

            if ($medId <= 0) $errors[] = "Please select a valid medicine.";
            if ($bId <= 0) $errors[] = "Please select a valid physical batch.";
            if ($qty <= 0) $errors[] = "Adjustment quantity must be greater than zero.";
            if ($reason === '') $errors[] = "Audit reason is required for stock adjustments.";

            if (empty($errors)) {
                $result = $adjService->adjustStock($medId, $bId, $type, $qty, $reason, $userId);
                $successMessage = "Adjustment #{$result['adjustment_no']} recorded successfully. Batch stock updated from {$result['old_quantity']} to {$result['new_quantity']} units.";
                $activeMode = 'standard';
            }
        }
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }
}

// Fetch all active medicines
$allMedicines = $pdo->query("
    SELECT m.medicine_id, m.medicine_name, m.generic_name, m.stock_quantity, m.price as mrp, m.purchase_price, m.shelf,
           COUNT(mb.batch_id) as batch_count
    FROM medicines m
    LEFT JOIN medicine_batches mb ON m.medicine_id = mb.medicine_id
    WHERE m.deleted_at IS NULL
    GROUP BY m.medicine_id
    ORDER BY m.medicine_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all batches mapped by medicine for dynamic switching
$batchMap = [];
$allBatches = $pdo->query("
    SELECT mb.batch_id, mb.medicine_id, mb.batch_number, mb.expiry_date, mb.quantity_available, mb.shelf_location, mb.status,
           mb.purchase_price, mb.sale_price, mb.mrp
    FROM medicine_batches mb
    ORDER BY mb.expiry_date ASC
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($allBatches as $b) {
    $batchMap[$b['medicine_id']][] = $b;
}

// Fetch active suppliers for direct stock in
$suppliers = $pdo->query("SELECT supplier_id, supplier_name FROM pharmacy_suppliers WHERE status = 'Active' ORDER BY supplier_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Recent adjustments list with pagination / limit
$historyFilter = trim($_GET['filter'] ?? 'all');
$whereClause = "";
if ($historyFilter === 'direct_in') {
    $whereClause = "WHERE a.adjustment_type = 'Direct Stock In'";
} elseif ($historyFilter === 'direct_out') {
    $whereClause = "WHERE a.adjustment_type = 'Direct Stock Out'";
} elseif ($historyFilter === 'standard') {
    $whereClause = "WHERE a.adjustment_type NOT IN ('Direct Stock In', 'Direct Stock Out')";
}

$recentAdj = $pdo->query("
    SELECT a.*, m.medicine_name, mb.batch_number, mb.expiry_date, mb.shelf_location, u.full_name as user_name
    FROM pharmacy_stock_adjustments a
    JOIN medicines m ON a.medicine_id = m.medicine_id
    JOIN medicine_batches mb ON a.batch_id = mb.batch_id
    LEFT JOIN pharmacy_users u ON a.created_by = u.id
    {$whereClause}
    ORDER BY a.adjustment_id DESC
    LIMIT 25
")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold text-dark mb-1">
                <i class="ti ti-adjustments text-warning me-2"></i>Stock Operations &amp; Adjustments
            </h4>
            <p class="text-muted small mb-0">Record Direct Stock In (without bill), Direct Stock Out, physical audit reconciliation, and damaged write-offs.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="batch_stock.php" class="btn btn-outline-secondary rounded-pill px-3 py-2 small">
                <i class="ti ti-packages me-1"></i> View Current Stock
            </a>
            <a href="ledger.php" class="btn btn-outline-primary rounded-pill px-3 py-2 small">
                <i class="ti ti-file-text me-1"></i> Stock Ledger
            </a>
        </div>
    </div>

    <?php if ($successMessage): ?>
        <div class="alert alert-success rounded-4 border-0 shadow-sm mb-4 d-flex align-items-center">
            <i class="ti ti-circle-check fs-3 me-2 text-success"></i>
            <div class="fw-medium"><?= htmlspecialchars($successMessage) ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger rounded-4 border-0 shadow-sm mb-4">
            <h6 class="fw-bold mb-2"><i class="ti ti-alert-circle me-1"></i> Transaction Failed:</h6>
            <ul class="mb-0 small">
                <?php foreach ($errors as $err): ?>
                    <li><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="row g-4 mb-5">
        <!-- Entry Forms Card -->
        <div class="col-12 col-xl-6">
            <div class="card border-0 shadow-sm rounded-4 p-4 bg-white h-100">
                <!-- Mode Navigation Tabs -->
                <ul class="nav nav-pills mb-4 p-1 bg-light rounded-pill small" role="tablist">
                    <li class="nav-item flex-fill" role="presentation">
                        <button class="nav-link rounded-pill w-100 fw-bold <?= $activeMode === 'direct_in' ? 'active bg-emerald text-white' : 'text-dark' ?>" id="tab-direct-in" data-bs-toggle="pill" data-bs-target="#pane-direct-in" type="button" role="tab">
                            <i class="ti ti-plus me-1"></i> Direct Stock In
                        </button>
                    </li>
                    <li class="nav-item flex-fill" role="presentation">
                        <button class="nav-link rounded-pill w-100 fw-bold <?= $activeMode === 'direct_out' ? 'active bg-danger text-white' : 'text-dark' ?>" id="tab-direct-out" data-bs-toggle="pill" data-bs-target="#pane-direct-out" type="button" role="tab">
                            <i class="ti ti-minus me-1"></i> Direct Stock Out
                        </button>
                    </li>
                    <li class="nav-item flex-fill" role="presentation">
                        <button class="nav-link rounded-pill w-100 fw-bold <?= $activeMode === 'standard' ? 'active bg-warning text-dark' : 'text-dark' ?>" id="tab-standard" data-bs-toggle="pill" data-bs-target="#pane-standard" type="button" role="tab">
                            <i class="ti ti-adjustments me-1"></i> Audit Adjustment
                        </button>
                    </li>
                </ul>

                <div class="tab-content">
                    <!-- 1. DIRECT STOCK IN FORM -->
                    <div class="tab-pane fade <?= $activeMode === 'direct_in' ? 'show active' : '' ?>" id="pane-direct-in" role="tabpanel">
                        <div class="d-flex align-items-center justify-content-between border-bottom pb-2 mb-3">
                            <h6 class="fw-bold text-dark mb-0">
                                <i class="ti ti-package-import text-emerald me-1.5"></i> Direct Stock Inward (No Invoice Required)
                            </h6>
                            <span class="badge bg-emerald-subtle text-emerald border border-emerald-subtle small">DIRECT_STOCK_IN</span>
                        </div>
                        <p class="text-muted small mb-3">Enter medicines and batches directly into stock in hand without creating a Purchase Order or Purchase Invoice.</p>

                        <form method="POST" id="directInForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="direct_stock_in">

                            <div class="mb-3">
                                <label class="form-label small fw-semibold text-dark">Select Medicine <span class="text-danger">*</span></label>
                                <select name="medicine_id" id="medSelectIn" class="form-select rounded-3" required onchange="onMedicineChangeIn()">
                                    <option value="">-- Choose Medicine --</option>
                                    <?php foreach ($allMedicines as $m): ?>
                                        <option value="<?= $m['medicine_id'] ?>" data-mrp="<?= $m['mrp'] ?>" data-purchase="<?= $m['purchase_price'] ?>" data-shelf="<?= htmlspecialchars($m['shelf'] ?? '') ?>" <?= $preselectedMedId === (int)$m['medicine_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($m['medicine_name']) ?> <?= !empty($m['generic_name']) ? '(' . htmlspecialchars($m['generic_name']) . ')' : '' ?> &bull; Stock: <?= $m['stock_quantity'] ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Batch Mode Switcher -->
                            <div class="mb-3 p-3 bg-light rounded-3 border">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <label class="form-label small fw-bold text-dark mb-0">Batch Selection</label>
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" name="is_new_batch" id="chkNewBatch" value="1" onchange="toggleBatchMode()">
                                        <label class="form-check-label small fw-semibold text-emerald" for="chkNewBatch">Create New Batch</label>
                                    </div>
                                </div>

                                <!-- Existing Batch Dropdown -->
                                <div id="existingBatchContainer">
                                    <select name="batch_id" id="batchSelectIn" class="form-select rounded-3">
                                        <option value="">-- Choose Existing Batch --</option>
                                    </select>
                                    <div class="form-text small text-muted">Select an active batch to add units to, or toggle above to register a new batch.</div>
                                </div>

                                <!-- New Batch Fields -->
                                <div id="newBatchContainer" class="d-none mt-3 pt-3 border-top">
                                    <div class="row g-2 mb-2">
                                        <div class="col-md-6">
                                            <label class="form-label small fw-semibold text-dark">Batch Number <span class="text-danger">*</span></label>
                                            <input type="text" name="batch_number" id="txtBatchNumber" class="form-control form-control-sm rounded-3 text-uppercase font-monospace" placeholder="e.g. BATCH-2026-A">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-semibold text-dark">Expiry Date <span class="text-danger">*</span></label>
                                            <input type="date" name="expiry_date" id="txtExpiryDate" class="form-control form-control-sm rounded-3">
                                        </div>
                                    </div>
                                    <div class="row g-2 mb-2">
                                        <div class="col-md-4">
                                            <label class="form-label small text-muted">Mfg Date</label>
                                            <input type="date" name="manufacturing_date" class="form-control form-control-sm rounded-3">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label small text-muted">Purchase Rate (₹)</label>
                                            <input type="number" step="0.01" min="0" name="purchase_price" id="txtPurchaseRate" class="form-control form-control-sm rounded-3" placeholder="0.00">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label small text-muted">MRP / Sale Price (₹)</label>
                                            <input type="number" step="0.01" min="0" name="mrp" id="txtMrp" class="form-control form-control-sm rounded-3" placeholder="0.00">
                                        </div>
                                    </div>
                                    <div class="row g-2">
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted">Shelf / Rack Location</label>
                                            <input type="text" name="shelf_location" id="txtShelfLocation" class="form-control form-control-sm rounded-3" placeholder="e.g. RACK-B2">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small text-muted">Supplier / Source</label>
                                            <select name="supplier_id" class="form-select form-select-sm rounded-3">
                                                <option value="">-- Optional Supplier --</option>
                                                <?php foreach ($suppliers as $s): ?>
                                                    <option value="<?= $s['supplier_id'] ?>"><?= htmlspecialchars($s['supplier_name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold text-dark">Quantity to Add <span class="text-danger">*</span></label>
                                    <input type="number" min="1" name="quantity" class="form-control rounded-3" placeholder="Number of units" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold text-dark">Reference / Memo No</label>
                                    <input type="text" name="reference_no" class="form-control rounded-3" placeholder="e.g. SAMPLE-01, DONATION-99">
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label small fw-semibold text-dark">Reason / Source Note <span class="text-danger">*</span></label>
                                <textarea name="reason" class="form-control rounded-3" rows="2" placeholder="e.g. Direct batch stock inward without purchase bill, vendor sample stock, or found unbilled stock" required></textarea>
                            </div>

                            <button type="submit" class="btn btn-emerald rounded-pill px-5 fw-bold w-100 py-2 shadow-xs">
                                <i class="ti ti-check me-1"></i> Commit Direct Stock In
                            </button>
                        </form>
                    </div>

                    <!-- 2. DIRECT STOCK OUT FORM -->
                    <div class="tab-pane fade <?= $activeMode === 'direct_out' ? 'show active' : '' ?>" id="pane-direct-out" role="tabpanel">
                        <div class="d-flex align-items-center justify-content-between border-bottom pb-2 mb-3">
                            <h6 class="fw-bold text-dark mb-0">
                                <i class="ti ti-package-export text-danger me-1.5"></i> Direct Stock Outward (Non-Sale Removal)
                            </h6>
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle small">DIRECT_STOCK_OUT</span>
                        </div>
                        <p class="text-muted small mb-3">Deduct stock directly from a physical batch for internal use, hospital consumption, clinical samples, or damages without faking a sale invoice.</p>

                        <form method="POST" id="directOutForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="direct_stock_out">

                            <div class="mb-3">
                                <label class="form-label small fw-semibold text-dark">Select Medicine <span class="text-danger">*</span></label>
                                <select name="medicine_id" id="medSelectOut" class="form-select rounded-3" required onchange="onMedicineChangeOut()">
                                    <option value="">-- Choose Medicine --</option>
                                    <?php foreach ($allMedicines as $m): ?>
                                        <option value="<?= $m['medicine_id'] ?>" <?= $preselectedMedId === (int)$m['medicine_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($m['medicine_name']) ?> &bull; Available: <?= $m['stock_quantity'] ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-semibold text-dark">Select Batch to Remove From <span class="text-danger">*</span></label>
                                <select name="batch_id" id="batchSelectOut" class="form-select rounded-3" required onchange="onBatchChangeOut()">
                                    <option value="">-- Choose Batch --</option>
                                </select>
                            </div>

                            <!-- Batch Info Box -->
                            <div id="batchInfoBoxOut" class="alert alert-light border rounded-3 p-3 mb-3 d-none">
                                <div class="row g-2 small">
                                    <div class="col-6">
                                        <span class="text-muted">Available in Batch:</span>
                                        <strong id="lblAvailOut" class="text-dark ms-1">0</strong>
                                    </div>
                                    <div class="col-6">
                                        <span class="text-muted">Expiry Date:</span>
                                        <strong id="lblExpiryOut" class="text-dark ms-1">-</strong>
                                    </div>
                                    <div class="col-6">
                                        <span class="text-muted">Shelf / Rack:</span>
                                        <strong id="lblShelfOut" class="text-dark ms-1">-</strong>
                                    </div>
                                    <div class="col-6">
                                        <span class="text-muted">Status:</span>
                                        <span id="lblStatusOut" class="badge bg-secondary ms-1">-</span>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold text-dark">Quantity to Remove <span class="text-danger">*</span></label>
                                    <input type="number" min="1" name="quantity" id="txtQtyOut" class="form-control rounded-3" placeholder="Enter units" required oninput="validateStockOut()">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold text-dark">Removal Reason <span class="text-danger">*</span></label>
                                    <select name="reason" class="form-select rounded-3" required>
                                        <option value="Internal Hospital Ward Consumption">Internal Hospital Ward Consumption</option>
                                        <option value="Damaged / Broken in Handling">Damaged / Broken in Handling</option>
                                        <option value="Clinical / Doctor Sample">Clinical / Doctor Sample</option>
                                        <option value="Expired Stock Disposal">Expired Stock Disposal</option>
                                        <option value="Donation / Relief Stock">Donation / Relief Stock</option>
                                        <option value="Inter-Department Transfer">Inter-Department Transfer</option>
                                        <option value="Physical Stock Count Correction">Physical Stock Count Correction</option>
                                        <option value="Other Authorized Reason">Other Authorized Reason</option>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-semibold text-dark">Reference / Requisition No</label>
                                <input type="text" name="reference_no" class="form-control rounded-3" placeholder="e.g. ICU-REQ-102, BREAKAGE-04">
                            </div>

                            <!-- Live Ending Balance Warning -->
                            <div id="previewOut" class="alert alert-secondary border-0 rounded-3 p-3 mb-3 d-flex justify-content-between align-items-center">
                                <div>
                                    <span class="small text-muted">Remaining Batch Stock:</span>
                                    <strong id="lblRemainingOut" class="fs-5 text-dark ms-2">-</strong>
                                </div>
                                <span id="badgeValidationOut" class="badge bg-light text-dark">Awaiting Input</span>
                            </div>

                            <button type="submit" id="btnSubmitOut" class="btn btn-danger rounded-pill px-5 fw-bold w-100 py-2 shadow-xs">
                                <i class="ti ti-check me-1"></i> Commit Direct Stock Out
                            </button>
                        </form>
                    </div>

                    <!-- 3. STANDARD AUDIT ADJUSTMENT FORM -->
                    <div class="tab-pane fade <?= $activeMode === 'standard' ? 'show active' : '' ?>" id="pane-standard" role="tabpanel">
                        <div class="d-flex align-items-center justify-content-between border-bottom pb-2 mb-3">
                            <h6 class="fw-bold text-dark mb-0">
                                <i class="ti ti-scale text-warning me-1.5"></i> Physical Audit &amp; Count Reconciliation
                            </h6>
                            <span class="badge bg-warning-subtle text-dark border border-warning-subtle small">STOCK_ADJUSTMENT</span>
                        </div>
                        <p class="text-muted small mb-3">Adjust existing batch stock balances after periodic cycle counts, write-offs, or physical stock audits.</p>

                        <form method="POST" id="adjustmentForm">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="adjust">

                            <div class="mb-3">
                                <label class="form-label small fw-semibold text-dark">Select Medicine <span class="text-danger">*</span></label>
                                <select name="medicine_id" id="medSelect" class="form-select rounded-3" required onchange="onMedicineChange()">
                                    <option value="">-- Choose Medicine --</option>
                                    <?php foreach ($allMedicines as $m): ?>
                                        <?php if ($m['batch_count'] > 0): ?>
                                            <option value="<?= $m['medicine_id'] ?>" <?= $preselectedMedId === (int)$m['medicine_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($m['medicine_name']) ?> (Total Stock: <?= $m['stock_quantity'] ?>)
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-semibold text-dark">Select Batch <span class="text-danger">*</span></label>
                                <select name="batch_id" id="batchSelect" class="form-select rounded-3" required onchange="onBatchChange()">
                                    <option value="">-- Choose Batch --</option>
                                </select>
                            </div>

                            <!-- Batch Info Box -->
                            <div id="batchInfoBox" class="alert alert-light border rounded-3 p-3 mb-3 d-none">
                                <div class="row g-2 small">
                                    <div class="col-6">
                                        <span class="text-muted">Current Batch Stock:</span>
                                        <strong id="lblCurrentStock" class="text-dark ms-1">0</strong>
                                    </div>
                                    <div class="col-6">
                                        <span class="text-muted">Expiry Date:</span>
                                        <strong id="lblExpiry" class="text-dark ms-1">-</strong>
                                    </div>
                                    <div class="col-6">
                                        <span class="text-muted">Shelf / Rack:</span>
                                        <strong id="lblShelf" class="text-dark ms-1">-</strong>
                                    </div>
                                    <div class="col-6">
                                        <span class="text-muted">Status:</span>
                                        <span id="lblStatus" class="badge bg-secondary ms-1">-</span>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-12 col-md-6">
                                    <label class="form-label small fw-semibold text-dark">Adjustment Type <span class="text-danger">*</span></label>
                                    <select name="adjustment_type" id="adjType" class="form-select rounded-3" required onchange="updateEndingStock()">
                                        <option value="Count Reconciliation" <?= $preselectedType === 'Count Reconciliation' ? 'selected' : '' ?>>Count Reconciliation</option>
                                        <option value="Increase" <?= $preselectedType === 'Increase' ? 'selected' : '' ?>>Increase (Found Stock)</option>
                                        <option value="Decrease" <?= $preselectedType === 'Decrease' ? 'selected' : '' ?>>Decrease (Physical Shortage)</option>
                                        <option value="Damage" <?= $preselectedType === 'Damage' ? 'selected' : '' ?>>Damage / Breakage</option>
                                        <option value="Expired" <?= $preselectedType === 'Expired' ? 'selected' : '' ?>>Expired Stock Write-Off</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label small fw-semibold text-dark">Quantity to Adjust <span class="text-danger">*</span></label>
                                    <input type="number" min="1" name="quantity" id="adjQty" class="form-control rounded-3" placeholder="Enter units" required oninput="updateEndingStock()">
                                </div>
                            </div>

                            <!-- Ending Balance Preview -->
                            <div id="endingBalancePreview" class="alert alert-secondary border-0 rounded-3 p-3 mb-3 d-flex justify-content-between align-items-center">
                                <div>
                                    <span class="small text-muted">Projected Ending Batch Stock:</span>
                                    <strong id="lblEndingStock" class="fs-5 text-dark ms-2">-</strong>
                                </div>
                                <span id="stockValidationBadge" class="badge bg-light text-dark">Awaiting Input</span>
                            </div>

                            <div class="mb-4">
                                <label class="form-label small fw-semibold text-dark">Reason / Justification <span class="text-danger">*</span></label>
                                <textarea name="reason" class="form-control rounded-3" rows="3" placeholder="Provide audit explanation (e.g. Monthly physical audit variance, bottle broken during handling)" required></textarea>
                            </div>

                            <button type="submit" id="btnSubmit" class="btn btn-warning rounded-pill px-5 fw-bold w-100 py-2">
                                <i class="ti ti-check me-1"></i> Commit Controlled Adjustment
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Movements & Saved Records Ledger -->
        <div class="col-12 col-xl-6">
            <div class="card border-0 shadow-sm rounded-4 p-4 bg-white h-100">
                <div class="d-flex align-items-center justify-content-between border-bottom pb-2 mb-3 flex-wrap gap-2">
                    <h5 class="fw-bold text-dark mb-0">Recent Stock Movements Log</h5>
                    <div class="btn-group btn-group-sm" role="group">
                        <a href="adjustments.php?filter=all" class="btn <?= $historyFilter === 'all' ? 'btn-dark' : 'btn-outline-secondary' ?>">All</a>
                        <a href="adjustments.php?filter=direct_in" class="btn <?= $historyFilter === 'direct_in' ? 'btn-dark' : 'btn-outline-secondary' ?>">Direct In</a>
                        <a href="adjustments.php?filter=direct_out" class="btn <?= $historyFilter === 'direct_out' ? 'btn-dark' : 'btn-outline-secondary' ?>">Direct Out</a>
                        <a href="adjustments.php?filter=standard" class="btn <?= $historyFilter === 'standard' ? 'btn-dark' : 'btn-outline-secondary' ?>">Adjustments</a>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="bg-light text-muted">
                            <tr>
                                <th>Movement / Adj No</th>
                                <th>Medicine &amp; Batch</th>
                                <th>Type</th>
                                <th class="text-end">Units</th>
                                <th class="text-end">Old &rarr; New</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentAdj)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">No stock movements recorded under this filter.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentAdj as $ra): ?>
                                    <tr>
                                        <td>
                                            <div class="font-monospace fw-bold text-dark"><?= htmlspecialchars($ra['adjustment_no']) ?></div>
                                            <div class="text-muted" style="font-size:0.7rem;"><?= date('d M Y, H:i', strtotime($ra['created_at'])) ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold text-dark text-truncate" style="max-width: 150px;" title="<?= htmlspecialchars($ra['medicine_name']) ?>">
                                                <?= htmlspecialchars($ra['medicine_name']) ?>
                                            </div>
                                            <div class="text-muted small font-monospace">Batch: <?= htmlspecialchars($ra['batch_number']) ?></div>
                                        </td>
                                        <td>
                                            <?php if ($ra['adjustment_type'] === 'Direct Stock In'): ?>
                                                <span class="badge bg-emerald text-white">Direct Stock In</span>
                                            <?php elseif ($ra['adjustment_type'] === 'Direct Stock Out'): ?>
                                                <span class="badge bg-danger text-white">Direct Stock Out</span>
                                            <?php elseif (in_array($ra['adjustment_type'], ['Increase', 'Found Stock'])): ?>
                                                <span class="badge bg-success-subtle text-success"><?= htmlspecialchars($ra['adjustment_type']) ?></span>
                                            <?php elseif (in_array($ra['adjustment_type'], ['Damage', 'Expired'])): ?>
                                                <span class="badge bg-danger-subtle text-danger"><?= htmlspecialchars($ra['adjustment_type']) ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary-subtle text-secondary"><?= htmlspecialchars($ra['adjustment_type']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end fw-bold <?= in_array($ra['adjustment_type'], ['Direct Stock In', 'Increase', 'Found Stock']) ? 'text-success' : 'text-danger' ?>">
                                            <?= (in_array($ra['adjustment_type'], ['Direct Stock In', 'Increase', 'Found Stock']) ? '+' : '-') . $ra['quantity'] ?>
                                        </td>
                                        <td class="text-end text-muted font-monospace">
                                            <?= $ra['old_quantity'] ?> &rarr; <?= $ra['new_quantity'] ?>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-outline-secondary p-1 rounded-2" onclick="viewMovementDetails(<?= htmlspecialchars(json_encode($ra)) ?>)" title="View Details &amp; Edit Note">
                                                <i class="ti ti-eye"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal for Viewing Details and Editing Notes -->
<div class="modal fade" id="movementDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-bottom py-3">
                <h6 class="modal-title fw-bold text-dark" id="modalTitle">Stock Movement Details</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_notes">
                <input type="hidden" name="adjustment_id" id="modalAdjId">

                <div class="modal-body p-4">
                    <div class="row g-2 small mb-3">
                        <div class="col-6">
                            <span class="text-muted">Document No:</span>
                            <div class="fw-bold text-dark font-monospace" id="modalDocNo">-</div>
                        </div>
                        <div class="col-6">
                            <span class="text-muted">Type:</span>
                            <div class="fw-semibold text-dark" id="modalType">-</div>
                        </div>
                        <div class="col-6">
                            <span class="text-muted">Medicine:</span>
                            <div class="fw-semibold text-dark" id="modalMedicine">-</div>
                        </div>
                        <div class="col-6">
                            <span class="text-muted">Batch:</span>
                            <div class="fw-semibold text-dark font-monospace" id="modalBatch">-</div>
                        </div>
                        <div class="col-6">
                            <span class="text-muted">Quantity:</span>
                            <div class="fw-bold text-dark" id="modalQty">-</div>
                        </div>
                        <div class="col-6">
                            <span class="text-muted">Logged By / Date:</span>
                            <div class="text-muted" id="modalUserDate">-</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-dark">Reference / Memo No</label>
                        <input type="text" name="reference_no" id="modalRefNo" class="form-control form-control-sm rounded-3">
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold text-dark">Audit Reason / Justification <span class="text-danger">*</span></label>
                        <textarea name="reason" id="modalReason" class="form-control form-control-sm rounded-3" rows="3" required></textarea>
                    </div>
                    <div class="form-text small text-muted">Stock movements and batch allocations are immutable. You may update the explanatory note or external reference for audit compliance.</div>
                </div>
                <div class="modal-footer border-top py-2 px-4">
                    <button type="button" class="btn btn-sm btn-light rounded-pill px-3" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-sm btn-dark rounded-pill px-4 fw-semibold">Save Note</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const batchData = <?= json_encode($batchMap) ?>;
const preselectedBatchId = <?= $preselectedBatchId ?>;

// Direct In Handler
function onMedicineChangeIn() {
    const medSelect = document.getElementById('medSelectIn');
    const medId = medSelect.value;
    const batchSelect = document.getElementById('batchSelectIn');
    batchSelect.innerHTML = '<option value="">-- Choose Existing Batch --</option>';

    const opt = medSelect.selectedOptions[0];
    if (opt && opt.dataset.mrp) {
        document.getElementById('txtMrp').value = opt.dataset.mrp;
        document.getElementById('txtPurchaseRate').value = opt.dataset.purchase || '0.00';
        document.getElementById('txtShelfLocation').value = opt.dataset.shelf || '';
    }

    if (!medId || !batchData[medId]) {
        return;
    }

    batchData[medId].forEach(b => {
        const o = document.createElement('option');
        o.value = b.batch_id;
        o.textContent = `${b.batch_number} (Exp: ${b.expiry_date}, Current Stock: ${b.quantity_available})`;
        batchSelect.appendChild(o);
    });
}

function toggleBatchMode() {
    const isNew = document.getElementById('chkNewBatch').checked;
    const existCont = document.getElementById('existingBatchContainer');
    const newCont = document.getElementById('newBatchContainer');
    const batchSelectIn = document.getElementById('batchSelectIn');
    const txtBatchNumber = document.getElementById('txtBatchNumber');
    const txtExpiryDate = document.getElementById('txtExpiryDate');

    if (isNew) {
        existCont.classList.add('d-none');
        newCont.classList.remove('d-none');
        batchSelectIn.required = false;
        txtBatchNumber.required = true;
        txtExpiryDate.required = true;
    } else {
        existCont.classList.remove('d-none');
        newCont.classList.add('d-none');
        batchSelectIn.required = true;
        txtBatchNumber.required = false;
        txtExpiryDate.required = false;
    }
}

// Direct Out Handlers
function onMedicineChangeOut() {
    const medId = document.getElementById('medSelectOut').value;
    const batchSelect = document.getElementById('batchSelectOut');
    batchSelect.innerHTML = '<option value="">-- Choose Batch --</option>';

    if (!medId || !batchData[medId]) {
        document.getElementById('batchInfoBoxOut').classList.add('d-none');
        validateStockOut();
        return;
    }

    batchData[medId].forEach(b => {
        const o = document.createElement('option');
        o.value = b.batch_id;
        o.textContent = `${b.batch_number} (Stock: ${b.quantity_available}, Exp: ${b.expiry_date})`;
        o.dataset.stock = b.quantity_available;
        o.dataset.expiry = b.expiry_date;
        o.dataset.shelf = b.shelf_location || '-';
        o.dataset.status = b.status;
        batchSelect.appendChild(o);
    });

    onBatchChangeOut();
}

function onBatchChangeOut() {
    const batchSelect = document.getElementById('batchSelectOut');
    const opt = batchSelect.selectedOptions[0];
    const infoBox = document.getElementById('batchInfoBoxOut');

    if (!opt || !opt.value) {
        infoBox.classList.add('d-none');
        validateStockOut();
        return;
    }

    document.getElementById('lblAvailOut').textContent = opt.dataset.stock + ' units';
    document.getElementById('lblExpiryOut').textContent = opt.dataset.expiry;
    document.getElementById('lblShelfOut').textContent = opt.dataset.shelf;
    document.getElementById('lblStatusOut').textContent = opt.dataset.status;
    infoBox.classList.remove('d-none');

    validateStockOut();
}

function validateStockOut() {
    const batchSelect = document.getElementById('batchSelectOut');
    const opt = batchSelect.selectedOptions[0];
    const qtyInput = document.getElementById('txtQtyOut').value;
    const qty = parseInt(qtyInput) || 0;
    const lblRemaining = document.getElementById('lblRemainingOut');
    const badge = document.getElementById('badgeValidationOut');
    const btn = document.getElementById('btnSubmitOut');

    if (!opt || !opt.value || qty <= 0) {
        lblRemaining.textContent = '-';
        badge.className = 'badge bg-light text-dark';
        badge.textContent = 'Awaiting Input';
        btn.disabled = false;
        return;
    }

    const currentStock = parseInt(opt.dataset.stock) || 0;
    const remaining = currentStock - qty;
    lblRemaining.textContent = remaining + ' units';

    if (remaining < 0) {
        badge.className = 'badge bg-danger text-white';
        badge.textContent = 'Exceeds Available Stock!';
        btn.disabled = true;
    } else {
        badge.className = 'badge bg-success text-white';
        badge.textContent = 'Valid Quantity';
        btn.disabled = false;
    }
}

// Standard Adjustment Handlers
function onMedicineChange() {
    const medId = document.getElementById('medSelect').value;
    const batchSelect = document.getElementById('batchSelect');
    batchSelect.innerHTML = '<option value="">-- Choose Batch --</option>';

    if (!medId || !batchData[medId]) {
        document.getElementById('batchInfoBox').classList.add('d-none');
        return;
    }

    batchData[medId].forEach(b => {
        const opt = document.createElement('option');
        opt.value = b.batch_id;
        opt.textContent = `${b.batch_number} (Exp: ${b.expiry_date}, Stock: ${b.quantity_available})`;
        opt.dataset.stock = b.quantity_available;
        opt.dataset.expiry = b.expiry_date;
        opt.dataset.shelf = b.shelf_location || '-';
        opt.dataset.status = b.status;
        if (b.batch_id == preselectedBatchId) {
            opt.selected = true;
        }
        batchSelect.appendChild(opt);
    });

    onBatchChange();
}

function onBatchChange() {
    const batchSelect = document.getElementById('batchSelect');
    const opt = batchSelect.selectedOptions[0];
    const infoBox = document.getElementById('batchInfoBox');

    if (!opt || !opt.value) {
        infoBox.classList.add('d-none');
        updateEndingStock();
        return;
    }

    document.getElementById('lblCurrentStock').textContent = opt.dataset.stock + ' units';
    document.getElementById('lblExpiry').textContent = opt.dataset.expiry;
    document.getElementById('lblShelf').textContent = opt.dataset.shelf;
    document.getElementById('lblStatus').textContent = opt.dataset.status;
    infoBox.classList.remove('d-none');

    updateEndingStock();
}

function updateEndingStock() {
    const batchSelect = document.getElementById('batchSelect');
    const opt = batchSelect.selectedOptions[0];
    const adjType = document.getElementById('adjType').value;
    const qtyInput = document.getElementById('adjQty').value;
    const qty = parseInt(qtyInput) || 0;
    const endingLbl = document.getElementById('lblEndingStock');
    const badge = document.getElementById('stockValidationBadge');
    const btn = document.getElementById('btnSubmit');

    if (!opt || !opt.value || qty <= 0) {
        endingLbl.textContent = '-';
        badge.className = 'badge bg-light text-dark';
        badge.textContent = 'Awaiting Input';
        btn.disabled = false;
        return;
    }

    const currentStock = parseInt(opt.dataset.stock) || 0;
    let ending = currentStock;

    if (adjType === 'Increase' || adjType === 'Found Stock') {
        ending = currentStock + qty;
    } else {
        ending = currentStock - qty;
    }

    endingLbl.textContent = ending + ' units';

    if (ending < 0) {
        badge.className = 'badge bg-danger text-white';
        badge.textContent = 'Negative Stock Prohibited!';
        btn.disabled = true;
    } else {
        badge.className = 'badge bg-success text-white';
        badge.textContent = 'Valid Balance';
        btn.disabled = false;
    }
}

// View and Edit Note Modal
function viewMovementDetails(item) {
    document.getElementById('modalAdjId').value = item.adjustment_id;
    document.getElementById('modalDocNo').textContent = item.adjustment_no;
    document.getElementById('modalType').textContent = item.adjustment_type;
    document.getElementById('modalMedicine').textContent = item.medicine_name;
    document.getElementById('modalBatch').textContent = item.batch_number;
    document.getElementById('modalQty').textContent = (['Direct Stock In', 'Increase', 'Found Stock'].includes(item.adjustment_type) ? '+' : '-') + item.quantity + ' units (Old: ' + item.old_quantity + ' -> New: ' + item.new_quantity + ')';
    document.getElementById('modalUserDate').textContent = (item.user_name || 'System') + ' on ' + item.created_at;
    document.getElementById('modalRefNo').value = item.reference_no || '';
    document.getElementById('modalReason').value = item.reason || '';

    const modal = new bootstrap.Modal(document.getElementById('movementDetailsModal'));
    modal.show();
}

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('medSelectIn') && document.getElementById('medSelectIn').value) {
        onMedicineChangeIn();
    }
    if (document.getElementById('medSelectOut') && document.getElementById('medSelectOut').value) {
        onMedicineChangeOut();
    }
    if (document.getElementById('medSelect') && document.getElementById('medSelect').value) {
        onMedicineChange();
    }
});
</script>

<?php
include __DIR__ . '/../../includes/footer.php';
?>