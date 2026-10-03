<?php
// modules/inventory/batch_stock.php - Batch Master List & Inventory Tracking

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/AuditService.php';
require_once __DIR__ . '/../../app/Services/StockLedgerService.php';
require_once __DIR__ . '/../../app/Services/BatchService.php';
require_once __DIR__ . '/../../app/Services/MedicineService.php';

require_permission('pharmacy.inventory.view');

use Pharmacy\Services\BatchService;
use Pharmacy\Services\MedicineService;

$batchService = new BatchService($pdo);
$medService = new MedicineService($pdo);

$page_title = 'Batch Master & Current Stock';

$search = trim($_GET['search'] ?? '');
$medicineId = (int)($_GET['medicine_id'] ?? 0);
$expiryFilter = trim($_GET['expiry'] ?? 'all');
$statusFilter = trim($_GET['status'] ?? 'Active');
$shelfFilter = trim($_GET['shelf'] ?? '');

$where = ["1=1"];
$params = [];

if ($medicineId > 0) {
    $where[] = "mb.medicine_id = ?";
    $params[] = $medicineId;
}

if ($statusFilter !== '' && $statusFilter !== 'All') {
    $where[] = "mb.status = ?";
    $params[] = $statusFilter;
}

if ($shelfFilter !== '') {
    $where[] = "(mb.shelf_location LIKE ? OR m.shelf LIKE ? OR m.rack_location LIKE ?)";
    $sLike = "%{$shelfFilter}%";
    $params[] = $sLike;
    $params[] = $sLike;
    $params[] = $sLike;
}

if ($search !== '') {
    $where[] = "(m.medicine_name LIKE ? OR m.generic_name LIKE ? OR mb.batch_number LIKE ? OR m.barcode LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($expiryFilter === 'expired') {
    $where[] = "mb.expiry_date < CURDATE()";
} elseif ($expiryFilter === '30d') {
    $where[] = "mb.expiry_date >= CURDATE() AND mb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
} elseif ($expiryFilter === '60d') {
    $where[] = "mb.expiry_date >= CURDATE() AND mb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)";
} elseif ($expiryFilter === '90d') {
    $where[] = "mb.expiry_date >= CURDATE() AND mb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)";
}

$whereSql = implode(' AND ', $where);

// Order by earliest expiry first (FEFO default)
$sql = "
    SELECT mb.*, m.medicine_name, m.generic_name, m.unit, m.pack_size, m.schedule_type,
           DATEDIFF(mb.expiry_date, CURDATE()) AS days_to_expiry
    FROM medicine_batches mb
    JOIN medicines m ON mb.medicine_id = m.medicine_id
    WHERE {$whereSql}
    ORDER BY mb.expiry_date ASC, mb.batch_id ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

$metrics = $batchService->getInventoryMetrics();

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="container-fluid px-4 py-3">
    <!-- Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h4 class="fw-bold text-dark mb-1">
                <i class="ti ti-packages text-emerald me-2"></i>Batch Master & Current Stock
            </h4>
            <p class="text-muted small mb-0">First Expiry First Out (FEFO) physical inventory tracking, shelf life, and batch audit.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="opening_stock.php" class="btn btn-emerald text-white rounded-pill px-3 py-2 small" style="background-color: #059669;">
                <i class="ti ti-plus me-1"></i> Add Batch
            </a>
            <a href="adjustments.php" class="btn btn-outline-warning rounded-pill px-3 py-2 small">
                <i class="ti ti-adjustments me-1"></i> Stock Adjustment
            </a>
            <a href="expiry.php" class="btn btn-outline-danger rounded-pill px-3 py-2 small">
                <i class="ti ti-calendar-due me-1"></i> Expiry Dashboard
            </a>
        </div>
    </div>

    <!-- Quick Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm p-3 rounded-4 bg-white">
                <div class="text-muted small text-uppercase fw-semibold">Active Batches</div>
                <h3 class="fw-bold mb-0 text-dark"><?= number_format($metrics['total_batches']) ?></h3>
                <div class="text-muted small mt-1">Total physical batches in stock</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm p-3 rounded-4 bg-white">
                <div class="text-muted small text-uppercase fw-semibold">Total In-Stock Units</div>
                <h3 class="fw-bold mb-0 text-dark"><?= number_format($metrics['total_units']) ?></h3>
                <div class="text-muted small mt-1">Dispensing units on shelves</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm p-3 rounded-4 bg-white">
                <div class="text-muted small text-uppercase fw-semibold">Near Expiry (&le; 90 Days)</div>
                <h3 class="fw-bold mb-0 text-warning"><?= number_format($metrics['near_expiry_90']) ?></h3>
                <div class="text-muted small mt-1">Eligible for expedited FEFO</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm p-3 rounded-4 bg-white">
                <div class="text-muted small text-uppercase fw-semibold">Expired Batches</div>
                <h3 class="fw-bold mb-0 text-danger"><?= number_format($metrics['expired_batches']) ?></h3>
                <div class="text-muted small mt-1">Blocked from dispensing</div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 p-3 bg-white">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-12 col-md-4">
                <div class="input-group">
                    <span class="input-group-text bg-light border-0"><i class="ti ti-search text-muted"></i></span>
                    <input type="text" name="search" class="form-control bg-light border-0" placeholder="Search medicine, generic, batch number..." value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            <div class="col-6 col-md-2">
                <select name="status" class="form-select bg-light border-0">
                    <option value="Active" <?= $statusFilter === 'Active' ? 'selected' : '' ?>>Active Only</option>
                    <option value="Expired" <?= $statusFilter === 'Expired' ? 'selected' : '' ?>>Expired</option>
                    <option value="Depleted" <?= $statusFilter === 'Depleted' ? 'selected' : '' ?>>Depleted (0)</option>
                    <option value="All" <?= $statusFilter === 'All' ? 'selected' : '' ?>>All Statuses</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="expiry" class="form-select bg-light border-0">
                    <option value="all" <?= $expiryFilter === 'all' ? 'selected' : '' ?>>All Expiry Dates</option>
                    <option value="30d" <?= $expiryFilter === '30d' ? 'selected' : '' ?>>Expiring &le; 30 Days</option>
                    <option value="60d" <?= $expiryFilter === '60d' ? 'selected' : '' ?>>Expiring &le; 60 Days</option>
                    <option value="90d" <?= $expiryFilter === '90d' ? 'selected' : '' ?>>Expiring &le; 90 Days</option>
                    <option value="expired" <?= $expiryFilter === 'expired' ? 'selected' : '' ?>>Already Expired</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <input type="text" name="shelf" class="form-control bg-light border-0" placeholder="Shelf/Rack..." value="<?= htmlspecialchars($shelfFilter) ?>">
            </div>
            <div class="col-6 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-emerald text-white w-100 rounded-pill" style="background-color: #059669;">Filter</button>
                <a href="batch_stock.php" class="btn btn-light rounded-pill px-3"><i class="ti ti-refresh"></i></a>
            </div>
        </form>
    </div>

    <!-- Batch Table (Ordered by FEFO) -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white mb-5">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light text-muted small text-uppercase">
                    <tr>
                        <th class="ps-4">Medicine & Formulation</th>
                        <th>Batch Number</th>
                        <th>Shelf Location</th>
                        <th>Expiry Date (FEFO)</th>
                        <th class="text-end">MRP</th>
                        <th class="text-end">Sale Price</th>
                        <th class="text-end">Available Units</th>
                        <th class="text-center">Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($batches)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="ti ti-packages fs-1 d-block mb-2 text-secondary"></i>
                                No batches found matching your search criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($batches as $b): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold text-dark">
                                        <a href="profile.php?id=<?= $b['medicine_id'] ?>" class="text-dark text-decoration-none hover-emerald">
                                            <?= htmlspecialchars($b['medicine_name']) ?>
                                        </a>
                                    </div>
                                    <div class="text-muted small"><?= htmlspecialchars($b['generic_name'] ?? '-') ?></div>
                                </td>
                                <td>
                                    <span class="font-monospace fw-bold text-dark bg-light px-2 py-1 rounded">
                                        <?= htmlspecialchars($b['batch_number']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="small font-monospace text-dark">
                                        <i class="ti ti-map-pin text-muted me-1"></i><?= htmlspecialchars($b['shelf_location'] ?? '-') ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="<?= $b['days_to_expiry'] < 0 ? 'text-danger fw-bold' : ($b['days_to_expiry'] <= 90 ? 'text-warning fw-semibold' : 'text-dark') ?>">
                                        <?= format_date($b['expiry_date']) ?>
                                    </div>
                                    <div class="small">
                                        <?php if ($b['days_to_expiry'] < 0): ?>
                                            <span class="badge bg-danger-subtle text-danger">Expired (<?= abs($b['days_to_expiry']) ?>d ago)</span>
                                        <?php elseif ($b['days_to_expiry'] <= 30): ?>
                                            <span class="badge bg-danger-subtle text-danger"><?= $b['days_to_expiry'] ?> days left</span>
                                        <?php elseif ($b['days_to_expiry'] <= 90): ?>
                                            <span class="badge bg-warning-subtle text-warning"><?= $b['days_to_expiry'] ?> days left</span>
                                        <?php else: ?>
                                            <span class="text-muted small"><?= $b['days_to_expiry'] ?> days left</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="text-end fw-semibold text-dark"><?= format_currency($b['mrp']) ?></td>
                                <td class="text-end fw-semibold text-dark"><?= format_currency($b['sale_price']) ?></td>
                                <td class="text-end">
                                    <?php if ($b['quantity_available'] <= 0): ?>
                                        <span class="badge bg-secondary rounded-pill px-2">Depleted (0)</span>
                                    <?php else: ?>
                                        <span class="fw-bold fs-6 text-dark"><?= number_format($b['quantity_available']) ?></span>
                                    <?php endif; ?>
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
                                <td class="text-end pe-4">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-light rounded-circle" type="button" data-bs-toggle="dropdown">
                                            <i class="ti ti-dots-vertical"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-3">
                                            <li><a class="dropdown-item py-2" href="adjustments.php?medicine_id=<?= $b['medicine_id'] ?>&batch_id=<?= $b['batch_id'] ?>"><i class="ti ti-adjustments me-2 text-warning"></i>Adjust Stock</a></li>
                                            <li><a class="dropdown-item py-2" href="ledger.php?medicine_id=<?= $b['medicine_id'] ?>&batch_id=<?= $b['batch_id'] ?>"><i class="ti ti-history me-2 text-secondary"></i>Batch Ledger</a></li>
                                        </ul>
                                    </div>
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