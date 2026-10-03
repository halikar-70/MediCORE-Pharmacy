<?php
// modules/inventory/profile.php - Medicine Comprehensive Profile

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/AuditService.php';
require_once __DIR__ . '/../../app/Services/StockLedgerService.php';
require_once __DIR__ . '/../../app/Services/BatchService.php';
require_once __DIR__ . '/../../app/Services/MedicineService.php';

require_permission('pharmacy.inventory.view');

use Pharmacy\Services\MedicineService;
use Pharmacy\Services\BatchService;
use Pharmacy\Services\StockLedgerService;

$medService = new MedicineService($pdo);
$batchService = new BatchService($pdo);
$ledgerService = new StockLedgerService($pdo);

$medicineId = (int)($_GET['id'] ?? 0);
$medicine = $medService->getMedicineById($medicineId);

if (!$medicine) {
    flash('error', 'Medicine not found.');
    redirect('products.php');
}

$page_title = 'Medicine Profile: ' . $medicine['medicine_name'];

// Batches
$batches = $batchService->getBatchesForMedicine($medicineId);

// Reconciliation verification
$recon = $ledgerService->reconcileMedicine($medicineId);

// Stock Ledger Movements for this medicine
$ledgerMovements = $ledgerService->getLedgerHistory(['medicine_id' => $medicineId], 15, 0);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="container-fluid px-4 py-3">
    <!-- Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <h4 class="fw-bold text-dark mb-0"><?= htmlspecialchars($medicine['medicine_name']) ?></h4>
                <?php if ($medicine['status'] === 'Active'): ?>
                    <span class="badge bg-success-subtle text-success rounded-pill px-3">Active</span>
                <?php elseif ($medicine['status'] === 'Inactive'): ?>
                    <span class="badge bg-secondary-subtle text-secondary rounded-pill px-3">Inactive</span>
                <?php else: ?>
                    <span class="badge bg-danger-subtle text-danger rounded-pill px-3">Discontinued</span>
                <?php endif; ?>

                <?php if ($medicine['schedule_type'] === 'Schedule H1'): ?>
                    <span class="badge bg-danger-subtle text-danger border border-danger">Schedule H1</span>
                <?php elseif ($medicine['schedule_type'] === 'Schedule H'): ?>
                    <span class="badge bg-warning-subtle text-warning border border-warning">Schedule H</span>
                <?php elseif ($medicine['schedule_type'] === 'Schedule X'): ?>
                    <span class="badge bg-dark text-white">Schedule X</span>
                <?php elseif ($medicine['schedule_type'] === 'OTC'): ?>
                    <span class="badge bg-success-subtle text-success">OTC</span>
                <?php endif; ?>
            </div>
            <p class="text-muted small mb-0">
                <?= htmlspecialchars($medicine['generic_name'] ?? 'Generic not specified') ?> &bull; 
                Strength: <?= htmlspecialchars($medicine['strength'] ?? 'N/A') ?> &bull; 
                <?= htmlspecialchars($medicine['manufacturer'] ?? 'Manufacturer not specified') ?>
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="adjustments.php?medicine_id=<?= $medicineId ?>" class="btn btn-outline-warning rounded-pill px-3 py-2 small">
                <i class="ti ti-adjustments me-1"></i> Adjust Stock
            </a>
            <a href="opening_stock.php?medicine_id=<?= $medicineId ?>" class="btn btn-outline-primary rounded-pill px-3 py-2 small">
                <i class="ti ti-plus me-1"></i> Add Batch
            </a>
            <a href="edit.php?id=<?= $medicineId ?>" class="btn btn-emerald text-white rounded-pill px-3 py-2 small" style="background-color: #059669;">
                <i class="ti ti-edit me-1"></i> Edit Details
            </a>
            <a href="products.php" class="btn btn-outline-secondary rounded-pill px-3 py-2 small">
                <i class="ti ti-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>

    <!-- Stock KPI & Reconciliation Card -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm p-3 rounded-4 bg-white">
                <div class="text-muted small text-uppercase fw-semibold">Total Physical Stock</div>
                <div class="d-flex align-items-baseline mt-1">
                    <h3 class="fw-bold mb-0 text-dark"><?= number_format($medicine['stock_quantity']) ?></h3>
                    <span class="text-muted small ms-2"><?= htmlspecialchars($medicine['unit'] ?? 'Units') ?></span>
                </div>
                <div class="small mt-1 <?= $medicine['stock_quantity'] <= $medicine['reorder_level'] ? 'text-danger fw-bold' : 'text-success' ?>">
                    Reorder Threshold: <?= $medicine['reorder_level'] ?> units
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm p-3 rounded-4 bg-white">
                <div class="text-muted small text-uppercase fw-semibold">Active Batches</div>
                <h3 class="fw-bold mb-0 text-dark"><?= count($batches) ?></h3>
                <div class="text-muted small mt-1">
                    Batch Sum: <?= number_format($recon['batch_sum']) ?> units
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm p-3 rounded-4 bg-white">
                <div class="text-muted small text-uppercase fw-semibold">Standard MRP / Sale Price</div>
                <h3 class="fw-bold mb-0 text-dark"><?= format_currency($medicine['price']) ?></h3>
                <div class="text-muted small mt-1">
                    Purchase Rate: <?= format_currency($medicine['purchase_price']) ?>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm p-3 rounded-4 bg-white">
                <div class="text-muted small text-uppercase fw-semibold">Reconciliation Health</div>
                <div class="mt-1">
                    <?php if ($recon['is_reconciled']): ?>
                        <span class="badge bg-success-subtle text-success fs-6 rounded-pill px-3">
                            <i class="ti ti-check me-1"></i> 100% Balanced
                        </span>
                        <div class="text-muted small mt-1">Master equals batch sum</div>
                    <?php else: ?>
                        <span class="badge bg-danger text-white fs-6 rounded-pill px-3">
                            <i class="ti ti-alert-triangle me-1"></i> Mismatch (<?= $recon['discrepancy'] ?>)
                        </span>
                        <div class="text-danger small mt-1">Audit reconciliation needed!</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Metadata Details -->
    <div class="card border-0 shadow-sm rounded-4 p-4 bg-white mb-4">
        <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="ti ti-info-circle me-2 text-emerald"></i>Master Specifications</h6>
        <div class="row g-3">
            <div class="col-6 col-md-3">
                <div class="text-muted small">Dosage Form</div>
                <div class="fw-semibold text-dark"><?= htmlspecialchars($medicine['dosage_form'] ?? 'Tablet') ?></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted small">Pack Size</div>
                <div class="fw-semibold text-dark"><?= htmlspecialchars($medicine['pack_size'] ?? '1') ?> <?= htmlspecialchars($medicine['unit'] ?? '') ?></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted small">Shelf / Location</div>
                <div class="fw-semibold text-dark font-monospace"><i class="ti ti-map-pin me-1 text-muted"></i><?= htmlspecialchars($medicine['shelf'] ?? $medicine['rack_location'] ?? 'Not assigned') ?></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted small">Box / Bin</div>
                <div class="fw-semibold text-dark"><?= htmlspecialchars($medicine['box_bin'] ?? '-') ?></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted small">Barcode / EAN</div>
                <div class="fw-semibold text-dark font-monospace"><?= htmlspecialchars($medicine['barcode'] ?? 'No Barcode') ?></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted small">HSN Code</div>
                <div class="fw-semibold text-dark"><?= htmlspecialchars($medicine['hsn_code'] ?? '-') ?></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted small">GST Rate</div>
                <div class="fw-semibold text-dark"><?= number_format((float)$medicine['gst_percent'], 1) ?>%</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted small">Stock Bounds (Min / Max)</div>
                <div class="fw-semibold text-dark"><?= $medicine['min_stock'] ?> / <?= $medicine['max_stock'] ?> units</div>
            </div>
        </div>
    </div>

    <!-- Active Batches Table -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white mb-4">
        <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
            <h6 class="fw-bold text-dark mb-0"><i class="ti ti-packages me-2 text-emerald"></i>Physical Batches & Expiry (<?= count($batches) ?>)</h6>
            <a href="opening_stock.php?medicine_id=<?= $medicineId ?>" class="btn btn-sm btn-outline-emerald rounded-pill px-3" style="color: #059669; border-color: #059669;">
                <i class="ti ti-plus me-1"></i> Add Opening Batch
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
                        <th class="text-end">Sale Price</th>
                        <th class="text-end">Available Stock</th>
                        <th class="text-center">Status</th>
                        <th class="text-end pe-4">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($batches)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted small">No batches registered yet for this medicine.</td>
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
                                            <span class="text-danger">Expired <?= abs($b['days_to_expiry']) ?> days ago</span>
                                        <?php elseif ($b['days_to_expiry'] <= 90): ?>
                                            <span class="text-warning"><?= $b['days_to_expiry'] ?> days left</span>
                                        <?php else: ?>
                                            <?= $b['days_to_expiry'] ?> days left
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($b['shelf_location'] ?? '-') ?></td>
                                <td class="text-end fw-semibold text-dark"><?= format_currency($b['mrp']) ?></td>
                                <td class="text-end fw-semibold text-dark"><?= format_currency($b['sale_price']) ?></td>
                                <td class="text-end fw-bold text-dark"><?= number_format($b['quantity_available']) ?></td>
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
                                <td class="text-end pe-4">
                                    <a href="adjustments.php?medicine_id=<?= $medicineId ?>&batch_id=<?= $b['batch_id'] ?>" class="btn btn-sm btn-light rounded-pill px-2">
                                        <i class="ti ti-adjustments me-1"></i> Adjust
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Stock Ledger Movements -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white mb-5">
        <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
            <h6 class="fw-bold text-dark mb-0"><i class="ti ti-history me-2 text-emerald"></i>Recent Stock Movements</h6>
            <a href="ledger.php?medicine_id=<?= $medicineId ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3">
                View Complete Ledger
            </a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light text-muted small text-uppercase">
                    <tr>
                        <th class="ps-4">Date / Time</th>
                        <th>Type</th>
                        <th>Batch</th>
                        <th>Reference</th>
                        <th class="text-end">Delta</th>
                        <th class="text-end">Balance After</th>
                        <th>Reason / User</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ledgerMovements)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted small">No stock ledger transactions recorded yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($ledgerMovements as $l): ?>
                            <tr>
                                <td class="ps-4 small text-muted"><?= format_date($l['created_at'], 'd M Y, H:i') ?></td>
                                <td>
                                    <span class="badge bg-light text-dark font-monospace"><?= htmlspecialchars($l['transaction_type']) ?></span>
                                </td>
                                <td class="font-monospace small"><?= htmlspecialchars($l['batch_number'] ?? '-') ?></td>
                                <td class="small font-monospace"><?= htmlspecialchars($l['reference_no'] ?? '-') ?></td>
                                <td class="text-end fw-bold <?= $l['quantity_change'] > 0 ? 'text-success' : 'text-danger' ?>">
                                    <?= ($l['quantity_change'] > 0 ? '+' : '') . $l['quantity_change'] ?>
                                </td>
                                <td class="text-end fw-bold text-dark"><?= number_format($l['balance_after']) ?></td>
                                <td class="small text-muted">
                                    <div><?= htmlspecialchars($l['reason'] ?? '-') ?></div>
                                    <div class="text-secondary" style="font-size: 0.75rem;"><i class="ti ti-user me-1"></i><?= htmlspecialchars($l['user_name'] ?? 'System') ?></div>
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
