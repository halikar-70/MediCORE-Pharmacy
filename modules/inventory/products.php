<?php
// modules/inventory/products.php - Medicine Master Catalog

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

$medService = new MedicineService($pdo);
$batchService = new BatchService($pdo);

$page_title = 'Medicine Master';

// Query parameters
$search = trim($_GET['search'] ?? '');
$category = trim($_GET['category'] ?? '');
$schedule = trim($_GET['schedule'] ?? '');
$status = trim($_GET['status'] ?? 'Active');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$filters = [];
if ($status !== '' && $status !== 'All') {
    $filters['status'] = $status;
}
if ($category !== '' && $category !== 'All') {
    $filters['category'] = $category;
}
if ($schedule !== '' && $schedule !== 'All') {
    $filters['schedule_type'] = $schedule;
}

$medicines = $medService->search($search, $filters, $perPage, $offset);
$totalCount = $medService->count($search, $filters);
$totalPages = max(1, ceil($totalCount / $perPage));

$metrics = $batchService->getInventoryMetrics();

// Categories list for filter dropdown
$categories = ['Tablet', 'Capsule', 'Syrup', 'Injection', 'Cream', 'Ointment', 'Drops', 'Powder', 'IV Fluid', 'Surgical/consumable', 'Other'];
$schedules = ['General', 'OTC', 'Schedule H', 'Schedule H1', 'Schedule X', 'Other'];

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="container-fluid px-4 py-3">
    <!-- Top Action Bar -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h4 class="fw-bold text-dark mb-1">
                <i class="ti ti-pill text-emerald me-2"></i>Medicine Master
            </h4>
            <p class="text-muted small mb-0">Pharmaceutical catalog, compositions, dosage strengths, and regulatory classifications.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="opening_stock.php" class="btn btn-outline-secondary rounded-pill px-3 py-2 small">
                <i class="ti ti-box-seam me-1"></i> Opening Stock
            </a>
            <a href="add.php" class="btn btn-emerald rounded-pill px-3 py-2 text-white small" style="background-color: #059669;">
                <i class="ti ti-plus me-1"></i> Add New Medicine
            </a>
        </div>
    </div>

    <!-- Quick Stats Metric Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm p-3 rounded-4 bg-white">
                <div class="d-flex align-items-center">
                    <div class="rounded-3 p-3 text-emerald bg-emerald-subtle me-3" style="background-color: #ecfdf5; color: #059669;">
                        <i class="ti ti-checkup-list fs-3"></i>
                    </div>
                    <div>
                        <div class="text-muted small text-uppercase fw-semibold">Active Medicines</div>
                        <h4 class="fw-bold mb-0 text-dark"><?= number_format($metrics['total_medicines']) ?></h4>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm p-3 rounded-4 bg-white">
                <div class="d-flex align-items-center">
                    <div class="rounded-3 p-3 text-primary bg-primary-subtle me-3" style="background-color: #eff6ff; color: #2563eb;">
                        <i class="ti ti-packages fs-3"></i>
                    </div>
                    <div>
                        <div class="text-muted small text-uppercase fw-semibold">Active Batches</div>
                        <h4 class="fw-bold mb-0 text-dark"><?= number_format($metrics['total_batches']) ?></h4>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm p-3 rounded-4 bg-white">
                <div class="d-flex align-items-center">
                    <div class="rounded-3 p-3 text-warning bg-warning-subtle me-3" style="background-color: #fffbeb; color: #d97706;">
                        <i class="ti ti-alert-triangle fs-3"></i>
                    </div>
                    <div>
                        <div class="text-muted small text-uppercase fw-semibold">Low Stock Alert</div>
                        <h4 class="fw-bold mb-0 text-dark"><?= number_format($metrics['low_stock']) ?></h4>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm p-3 rounded-4 bg-white">
                <div class="d-flex align-items-center">
                    <div class="rounded-3 p-3 text-danger bg-danger-subtle me-3" style="background-color: #fef2f2; color: #dc2626;">
                        <i class="ti ti-calendar-due fs-3"></i>
                    </div>
                    <div>
                        <div class="text-muted small text-uppercase fw-semibold">Near Expiry (90d)</div>
                        <h4 class="fw-bold mb-0 text-dark"><?= number_format($metrics['near_expiry_90']) ?></h4>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter & Search Bar -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 p-3 bg-white">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-12 col-md-4">
                <div class="input-group">
                    <span class="input-group-text bg-light border-0"><i class="ti ti-search text-muted"></i></span>
                    <input type="text" name="search" class="form-control bg-light border-0" placeholder="Search name, generic, barcode, manufacturer..." value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            <div class="col-6 col-md-2">
                <select name="category" class="form-select bg-light border-0">
                    <option value="All">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat ?>" <?= $category === $cat ? 'selected' : '' ?>><?= $cat ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="schedule" class="form-select bg-light border-0">
                    <option value="All">All Schedules</option>
                    <?php foreach ($schedules as $sch): ?>
                        <option value="<?= $sch ?>" <?= $schedule === $sch ? 'selected' : '' ?>><?= $sch ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="status" class="form-select bg-light border-0">
                    <option value="Active" <?= $status === 'Active' ? 'selected' : '' ?>>Active Only</option>
                    <option value="Inactive" <?= $status === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                    <option value="Discontinued" <?= $status === 'Discontinued' ? 'selected' : '' ?>>Discontinued</option>
                    <option value="All" <?= $status === 'All' ? 'selected' : '' ?>>All Statuses</option>
                </select>
            </div>
            <div class="col-6 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-emerald text-white w-100 rounded-pill" style="background-color: #059669;">Filter</button>
                <a href="products.php" class="btn btn-light rounded-pill px-3"><i class="ti ti-refresh"></i></a>
            </div>
        </form>
    </div>

    <!-- Medicine Table -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light text-muted small text-uppercase">
                    <tr>
                        <th class="ps-4">Medicine Details</th>
                        <th>Generic / Composition</th>
                        <th>Category / Pack</th>
                        <th>Schedule</th>
                        <th>Shelf / Rack</th>
                        <th class="text-end">Stock Balance</th>
                        <th class="text-end">Sale Price</th>
                        <th class="text-center">Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($medicines)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="ti ti-pill-off fs-1 d-block mb-2 text-secondary"></i>
                                No medicines found matching your search criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($medicines as $m): ?>
                            <?php 
                                $isLow = ($m['stock_quantity'] <= $m['reorder_level']);
                                $isOut = ($m['stock_quantity'] <= 0);
                            ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($m['medicine_name']) ?></div>
                                    <div class="text-muted small">
                                        <?php if (!empty($m['barcode'])): ?>
                                            <span class="badge bg-light text-dark font-monospace me-1"><i class="ti ti-barcode me-1"></i><?= htmlspecialchars($m['barcode']) ?></span>
                                        <?php endif; ?>
                                        <?= htmlspecialchars($m['manufacturer'] ?? '-') ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="small fw-bold text-dark"><?= htmlspecialchars($m['generic_name'] ?? '-') ?></div>
                                    <?php if (!empty($m['composition'])): ?>
                                        <div class="small text-emerald fw-medium mt-0.5" style="font-size: 0.76rem; color: #047857;" title="Medicine Composition / What it contains">
                                            <i class="ti ti-flask me-1"></i><?= htmlspecialchars($m['composition']) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($m['strength'])): ?>
                                        <div class="text-muted small" style="font-size: 0.72rem;"><?= htmlspecialchars($m['strength']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-secondary border"><?= htmlspecialchars($m['category'] ?? 'Tablet') ?></span>
                                    <div class="text-muted small mt-1"><?= htmlspecialchars($m['pack_size'] ?? '1') ?> <?= htmlspecialchars($m['unit'] ?? '') ?></div>
                                </td>
                                <td>
                                    <?php if ($m['schedule_type'] === 'Schedule H1'): ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger">Sch H1</span>
                                    <?php elseif ($m['schedule_type'] === 'Schedule H'): ?>
                                        <span class="badge bg-warning-subtle text-warning border border-warning">Sch H</span>
                                    <?php elseif ($m['schedule_type'] === 'Schedule X'): ?>
                                        <span class="badge bg-dark text-white">Sch X</span>
                                    <?php elseif ($m['schedule_type'] === 'OTC'): ?>
                                        <span class="badge bg-success-subtle text-success">OTC</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted">General</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="small font-monospace text-dark">
                                        <i class="ti ti-map-pin text-muted me-1"></i><?= htmlspecialchars($m['shelf'] ?? $m['rack_location'] ?? '-') ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <?php if ($isOut): ?>
                                        <span class="badge bg-danger text-white rounded-pill px-2">Out of Stock (0)</span>
                                    <?php elseif ($isLow): ?>
                                        <span class="badge bg-warning text-dark rounded-pill px-2"><?= number_format($m['stock_quantity']) ?> (Low)</span>
                                    <?php else: ?>
                                        <span class="fw-bold text-dark"><?= number_format($m['stock_quantity']) ?></span>
                                    <?php endif; ?>
                                    <div class="text-muted small">
                                        <a href="batch_stock.php?medicine_id=<?= $m['medicine_id'] ?>" class="text-decoration-none small text-emerald">
                                            <?= (int)$m['active_batches_count'] ?> batch(es)
                                        </a>
                                    </div>
                                </td>
                                <td class="text-end fw-semibold text-dark">
                                    <?= format_currency($m['price']) ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($m['status'] === 'Active'): ?>
                                        <span class="badge bg-success-subtle text-success rounded-pill px-2">Active</span>
                                    <?php elseif ($m['status'] === 'Inactive'): ?>
                                        <span class="badge bg-secondary-subtle text-secondary rounded-pill px-2">Inactive</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger rounded-pill px-2">Discontinued</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-light rounded-circle" type="button" data-bs-toggle="dropdown">
                                            <i class="ti ti-dots-vertical"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-3">
                                            <li><a class="dropdown-item py-2" href="profile.php?id=<?= $m['medicine_id'] ?>"><i class="ti ti-eye me-2 text-primary"></i>View Profile</a></li>
                                            <li><a class="dropdown-item py-2" href="edit.php?id=<?= $m['medicine_id'] ?>"><i class="ti ti-edit me-2 text-emerald"></i>Edit Details</a></li>
                                            <li><a class="dropdown-item py-2" href="adjustments.php?medicine_id=<?= $m['medicine_id'] ?>"><i class="ti ti-adjustments me-2 text-warning"></i>Adjust Stock</a></li>
                                            <li><a class="dropdown-item py-2" href="ledger.php?medicine_id=<?= $m['medicine_id'] ?>"><i class="ti ti-history me-2 text-secondary"></i>Stock Ledger</a></li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="card-footer bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                <div class="text-muted small">
                    Showing <?= $offset + 1 ?> to <?= min($totalCount, $offset + $perPage) ?> of <?= $totalCount ?> medicines
                </div>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&category=<?= urlencode($category) ?>&schedule=<?= urlencode($schedule) ?>&status=<?= urlencode($status) ?>">Previous</a>
                        </li>
                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                <a class="page-link" href="?page=<?= $p ?>&search=<?= urlencode($search) ?>&category=<?= urlencode($category) ?>&schedule=<?= urlencode($schedule) ?>&status=<?= urlencode($status) ?>"><?= $p ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&category=<?= urlencode($category) ?>&schedule=<?= urlencode($schedule) ?>&status=<?= urlencode($status) ?>">Next</a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
include __DIR__ . '/../../includes/footer.php';
?>