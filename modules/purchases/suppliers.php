<?php
// modules/purchases/suppliers.php - Supplier Master Directory & Profile

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/AuditService.php';
require_once __DIR__ . '/../../app/Services/DocumentSequenceService.php';
require_once __DIR__ . '/../../app/Services/SupplierService.php';

require_permission('pharmacy.suppliers.view');

use Pharmacy\Services\SupplierService;

$supplierService = new SupplierService($pdo);
$page_title = 'Supplier Master';

$user = auth_user();
$userId = (int)($user['id'] ?? 1);
$canManage = has_permission('pharmacy.suppliers.manage');

$feedback = null;
$error = null;

// Handle Form Submissions (Create / Update / Block / Deactivate)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid or expired security token. Please refresh and try again.";
    } elseif (!$canManage) {
        $error = "Access denied: You do not have permission to manage suppliers.";
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'create') {
                $allowDup = !empty($_POST['allow_duplicate']);
                $newId = $supplierService->createSupplier($_POST, $userId, $allowDup);
                $feedback = "Supplier successfully created with ID #{$newId}.";
            } elseif ($action === 'update') {
                $supId = (int)($_POST['supplier_id'] ?? 0);
                $supplierService->updateSupplier($supId, $_POST, $userId);
                $feedback = "Supplier details updated successfully.";
            } elseif ($action === 'deactivate') {
                $supId = (int)($_POST['supplier_id'] ?? 0);
                $supplierService->deactivateSupplier($supId, $userId);
                $feedback = "Supplier deactivated successfully.";
            } elseif ($action === 'block') {
                $supId = (int)($_POST['supplier_id'] ?? 0);
                $reason = trim($_POST['reason'] ?? 'Blocked by administrator');
                $supplierService->blockSupplier($supId, $userId, $reason);
                $feedback = "Supplier has been placed on BLOCKED status.";
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Filters
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$filters = [];
if ($search !== '') $filters['search'] = $search;
if ($status !== '' && $status !== 'All') $filters['status'] = $status;

$suppliers = $supplierService->listSuppliers($filters);

// View Supplier Profile Modal if view_id is set
$viewSupplier = null;
$viewStats = null;
if (!empty($_GET['view_id'])) {
    $viewId = (int)$_GET['view_id'];
    $viewSupplier = $supplierService->getSupplier($viewId);
    if ($viewSupplier) {
        $viewStats = $supplierService->getSupplierStats($viewId);
    }
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="container-fluid px-4 py-3">
    <!-- Header bar -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h4 class="fw-bold text-dark mb-1">
                <i class="ti ti-building-store text-emerald me-2"></i>Supplier Master
            </h4>
            <p class="text-muted small mb-0">Authorized pharmaceutical distributors, manufacturers, and vendor credentials.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="orders.php" class="btn btn-outline-secondary rounded-pill px-3 py-2 small">
                <i class="ti ti-file-text me-1"></i> Purchase Orders
            </a>
            <?php if ($canManage): ?>
                <button type="button" class="btn btn-emerald rounded-pill px-3 py-2 text-white small" style="background-color: #059669;" data-bs-toggle="modal" data-bs-target="#addSupplierModal">
                    <i class="ti ti-plus me-1"></i> Add Supplier
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($feedback): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3 border-0 shadow-sm mb-4" role="alert">
            <i class="ti ti-circle-check me-2 fs-5"></i><?= htmlspecialchars($feedback) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-3 border-0 shadow-sm mb-4" role="alert">
            <i class="ti ti-alert-circle me-2 fs-5"></i><?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Search & Filter Card -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white">
        <div class="card-body p-3">
            <form method="GET" action="suppliers.php" class="row g-2 align-items-center">
                <div class="col-md-5">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-0"><i class="ti ti-search text-muted"></i></span>
                        <input type="text" name="search" class="form-control bg-light border-0" placeholder="Search by name, code, phone, or GSTIN..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <select name="status" class="form-select bg-light border-0">
                        <option value="">All Statuses</option>
                        <option value="Active" <?= $status === 'Active' ? 'selected' : '' ?>>Active</option>
                        <option value="Inactive" <?= $status === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                        <option value="Blocked" <?= $status === 'Blocked' ? 'selected' : '' ?>>Blocked</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-dark w-100 rounded-3">Filter</button>
                </div>
                <div class="col-md-2">
                    <a href="suppliers.php" class="btn btn-outline-secondary w-100 rounded-3">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Suppliers Table -->
    <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr class="text-uppercase small text-muted">
                        <th class="ps-4">Supplier Code & Name</th>
                        <th>Contact / Phone</th>
                        <th>GSTIN & Licence</th>
                        <th>City / Terms</th>
                        <th class="text-end">Total Billed</th>
                        <th class="text-end">Outstanding</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($suppliers)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="ti ti-mood-empty fs-1 d-block mb-2 text-secondary"></i>
                                No suppliers found matching criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($suppliers as $s): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($s['supplier_name']) ?></div>
                                    <span class="badge bg-secondary-subtle text-secondary font-monospace" style="font-size: 0.72rem;">
                                        <?= htmlspecialchars($s['supplier_code'] ?? 'SUP-' . str_pad($s['supplier_id'], 6, '0', STR_PAD_LEFT)) ?>
                                    </span>
                                    <?php if (!empty($s['legal_name'])): ?>
                                        <div class="text-muted small" style="font-size: 0.75rem;"><?= htmlspecialchars($s['legal_name']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="text-dark small fw-semibold"><?= htmlspecialchars($s['contact_person'] ?? '—') ?></div>
                                    <div class="text-muted small"><?= htmlspecialchars($s['phone'] ?? '—') ?></div>
                                </td>
                                <td>
                                    <div class="font-monospace small text-dark"><?= htmlspecialchars($s['gstin'] ?? 'Not provided') ?></div>
                                    <div class="text-muted small" style="font-size: 0.75rem;">DL: <?= htmlspecialchars($s['drug_licence_no'] ?? '—') ?></div>
                                </td>
                                <td>
                                    <div class="text-dark small"><?= htmlspecialchars($s['city'] ?? '—') ?></div>
                                    <div class="badge bg-light text-secondary border font-monospace" style="font-size: 0.7rem;"><?= htmlspecialchars($s['payment_terms'] ?? '30 Days') ?></div>
                                </td>
                                <td class="text-end fw-semibold text-dark">
                                    ₹<?= number_format((float)($s['total_billed'] ?? 0), 2) ?>
                                </td>
                                <td class="text-end fw-bold <?= (float)($s['current_outstanding'] ?? 0) > 0 ? 'text-danger' : 'text-success' ?>">
                                    ₹<?= number_format((float)($s['current_outstanding'] ?? 0), 2) ?>
                                </td>
                                <td>
                                    <?php if ($s['status'] === 'Active'): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1">Active</span>
                                    <?php elseif ($s['status'] === 'Blocked'): ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-2 py-1">Blocked</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary border rounded-pill px-2 py-1">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-light border rounded-pill px-2 py-1" type="button" data-bs-toggle="dropdown">
                                            <i class="ti ti-dots-vertical"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                                            <li><a class="dropdown-item small" href="suppliers.php?view_id=<?= $s['supplier_id'] ?>"><i class="ti ti-id me-2"></i>View Profile</a></li>
                                            <li><a class="dropdown-item small" href="ledger.php?supplier_id=<?= $s['supplier_id'] ?>"><i class="ti ti-book-2 me-2"></i>View Ledger</a></li>
                                            <li><a class="dropdown-item small" href="orders.php?supplier_id=<?= $s['supplier_id'] ?>"><i class="ti ti-file-text me-2"></i>Purchase Orders</a></li>
                                            <li><a class="dropdown-item small" href="grn.php?supplier_id=<?= $s['supplier_id'] ?>"><i class="ti ti-truck-loading me-2"></i>Goods Received</a></li>
                                            <?php if ($canManage): ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <button type="button" class="dropdown-item small text-primary" data-bs-toggle="modal" data-bs-target="#editSupplierModal<?= $s['supplier_id'] ?>">
                                                        <i class="ti ti-edit me-2"></i>Edit Details
                                                    </button>
                                                </li>
                                                <?php if ($s['status'] === 'Active'): ?>
                                                    <li>
                                                        <form method="POST" action="suppliers.php" onsubmit="return confirm('Deactivate this supplier? Historical records will be preserved.');">
                                                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                            <input type="hidden" name="action" value="deactivate">
                                                            <input type="hidden" name="supplier_id" value="<?= $s['supplier_id'] ?>">
                                                            <button type="submit" class="dropdown-item small text-warning"><i class="ti ti-player-pause me-2"></i>Deactivate</button>
                                                        </form>
                                                    </li>
                                                    <li>
                                                        <form method="POST" action="suppliers.php" onsubmit="return confirm('Block this supplier? PO and GRN receiving will be strictly prevented.');">
                                                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                            <input type="hidden" name="action" value="block">
                                                            <input type="hidden" name="supplier_id" value="<?= $s['supplier_id'] ?>">
                                                            <button type="submit" class="dropdown-item small text-danger"><i class="ti ti-ban me-2"></i>Block Supplier</button>
                                                        </form>
                                                    </li>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </td>
                            </tr>

                            <!-- Edit Modal for each supplier -->
                            <?php if ($canManage): ?>
                            <div class="modal fade" id="editSupplierModal<?= $s['supplier_id'] ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content rounded-4 border-0">
                                        <form method="POST" action="suppliers.php">
                                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="supplier_id" value="<?= $s['supplier_id'] ?>">
                                            <div class="modal-header border-0 px-4 pt-4">
                                                <h5 class="fw-bold"><i class="ti ti-edit text-emerald me-2"></i>Edit Supplier: <?= htmlspecialchars($s['supplier_name']) ?></h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body px-4">
                                                <div class="row g-3">
                                                    <div class="col-md-6">
                                                        <label class="form-label small fw-semibold">Supplier Trade Name *</label>
                                                        <input type="text" name="supplier_name" class="form-control" required value="<?= htmlspecialchars($s['supplier_name']) ?>">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label small fw-semibold">Legal Registered Name</label>
                                                        <input type="text" name="legal_name" class="form-control" value="<?= htmlspecialchars($s['legal_name'] ?? '') ?>">
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label small fw-semibold">Contact Person</label>
                                                        <input type="text" name="contact_person" class="form-control" value="<?= htmlspecialchars($s['contact_person'] ?? '') ?>">
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label small fw-semibold">Phone</label>
                                                        <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($s['phone'] ?? '') ?>">
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label small fw-semibold">Email</label>
                                                        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($s['email'] ?? '') ?>">
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label small fw-semibold">GSTIN (15 Alphanumeric)</label>
                                                        <input type="text" name="gstin" class="form-control font-monospace" maxlength="15" value="<?= htmlspecialchars($s['gstin'] ?? '') ?>">
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label small fw-semibold">Drug Licence Number</label>
                                                        <input type="text" name="drug_licence_no" class="form-control font-monospace" value="<?= htmlspecialchars($s['drug_licence_no'] ?? '') ?>">
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label small fw-semibold">Licence Expiry Date</label>
                                                        <input type="date" name="licence_expiry_date" class="form-control" value="<?= htmlspecialchars($s['licence_expiry_date'] ?? '') ?>">
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label small fw-semibold">Credit Days</label>
                                                        <input type="number" name="credit_days" class="form-control" value="<?= (int)($s['credit_days'] ?? 30) ?>">
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label small fw-semibold">Credit Limit (₹)</label>
                                                        <input type="number" step="0.01" name="credit_limit" class="form-control" value="<?= (float)($s['credit_limit'] ?? 0) ?>">
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label small fw-semibold">Status</label>
                                                        <select name="status" class="form-select">
                                                            <option value="Active" <?= $s['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                                                            <option value="Inactive" <?= $s['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                                                            <option value="Blocked" <?= $s['status'] === 'Blocked' ? 'selected' : '' ?>>Blocked</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-12">
                                                        <label class="form-label small fw-semibold">Address, City, State, Pincode</label>
                                                        <input type="text" name="address" class="form-control mb-2" placeholder="Street Address" value="<?= htmlspecialchars($s['address'] ?? '') ?>">
                                                        <div class="row g-2">
                                                            <div class="col-md-4"><input type="text" name="city" class="form-control" placeholder="City" value="<?= htmlspecialchars($s['city'] ?? '') ?>"></div>
                                                            <div class="col-md-4"><input type="text" name="state" class="form-control" placeholder="State" value="<?= htmlspecialchars($s['state'] ?? '') ?>"></div>
                                                            <div class="col-md-4"><input type="text" name="pincode" class="form-control" placeholder="Pincode" value="<?= htmlspecialchars($s['pincode'] ?? '') ?>"></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="modal-footer border-0 px-4 pb-4">
                                                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-emerald rounded-pill px-4 text-white" style="background-color: #059669;">Save Changes</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Supplier Modal -->
<?php if ($canManage): ?>
<div class="modal fade" id="addSupplierModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content rounded-4 border-0">
            <form method="POST" action="suppliers.php">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="create">
                <div class="modal-header border-0 px-4 pt-4">
                    <h5 class="fw-bold"><i class="ti ti-building-store text-emerald me-2"></i>Register New Supplier</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Supplier Trade Name *</label>
                            <input type="text" name="supplier_name" class="form-control" required placeholder="e.g. Medico Life Sciences Ltd">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Legal / Registered Name</label>
                            <input type="text" name="legal_name" class="form-control" placeholder="Official registered entity name">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Contact Person</label>
                            <input type="text" name="contact_person" class="form-control" placeholder="Name of representative">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Phone / Mobile</label>
                            <input type="text" name="phone" class="form-control" placeholder="10-digit mobile or phone">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Email Address</label>
                            <input type="email" name="email" class="form-control" placeholder="supplier@example.com">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">GSTIN (15 Alphanumeric)</label>
                            <input type="text" name="gstin" class="form-control font-monospace" maxlength="15" placeholder="e.g. 27ABCDE1234F1Z5">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">PAN Number</label>
                            <input type="text" name="pan" class="form-control font-monospace" maxlength="10" placeholder="e.g. ABCDE1234F">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Supplier Type</label>
                            <select name="supplier_type" class="form-select">
                                <option value="Distributor">Distributor</option>
                                <option value="Manufacturer">Manufacturer</option>
                                <option value="Wholesaler">Wholesaler</option>
                                <option value="Stockist">Stockist</option>
                                <option value="Importer">Importer</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Drug Licence Number</label>
                            <input type="text" name="drug_licence_no" class="form-control font-monospace" placeholder="e.g. DL-20B-123456">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Drug Licence Type</label>
                            <input type="text" name="drug_licence_type" class="form-control" placeholder="Form 20B / 21B">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Licence Expiry Date</label>
                            <input type="date" name="licence_expiry_date" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Payment Terms</label>
                            <input type="text" name="payment_terms" class="form-control" value="30 Days Net">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Credit Days</label>
                            <input type="number" name="credit_days" class="form-control" value="30">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Credit Limit (₹)</label>
                            <input type="number" step="0.01" name="credit_limit" class="form-control" value="0.00">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Bank Name</label>
                            <input type="text" name="bank_name" class="form-control" placeholder="e.g. HDFC Bank">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Bank Account No</label>
                            <input type="text" name="bank_account_no" class="form-control font-monospace" placeholder="Account Number">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">IFSC Code</label>
                            <input type="text" name="bank_ifsc" class="form-control font-monospace" placeholder="e.g. HDFC0001234">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label small fw-semibold">Address Details</label>
                            <input type="text" name="address" class="form-control mb-2" placeholder="Warehouse / Office Address">
                            <div class="row g-2">
                                <div class="col-md-4"><input type="text" name="city" class="form-control" placeholder="City"></div>
                                <div class="col-md-4"><input type="text" name="state" class="form-control" placeholder="State"></div>
                                <div class="col-md-4"><input type="text" name="pincode" class="form-control" placeholder="Pincode"></div>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="allow_duplicate" value="1" id="allowDuplicateCheck">
                                <label class="form-check-label small text-muted" for="allowDuplicateCheck">
                                    Allow saving if similar supplier name/contact warning is detected (Authorized override)
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-emerald rounded-pill px-4 text-white" style="background-color: #059669;">Register Supplier</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Supplier Profile Drawer / Modal (if view_id requested) -->
<?php if ($viewSupplier): ?>
<div class="modal fade show" id="profileModal" tabindex="-1" style="display: block; background: rgba(0,0,0,0.5);">
    <div class="modal-dialog modal-lg">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header border-0 px-4 pt-4">
                <div>
                    <h5 class="fw-bold mb-0 text-dark"><i class="ti ti-id text-emerald me-2"></i><?= htmlspecialchars($viewSupplier['supplier_name']) ?></h5>
                    <span class="badge bg-secondary-subtle text-secondary font-monospace"><?= htmlspecialchars($viewSupplier['supplier_code']) ?></span>
                    <span class="badge bg-<?= $viewSupplier['status'] === 'Active' ? 'success' : ($viewSupplier['status'] === 'Blocked' ? 'danger' : 'secondary') ?>-subtle text-<?= $viewSupplier['status'] === 'Active' ? 'success' : ($viewSupplier['status'] === 'Blocked' ? 'danger' : 'secondary') ?> ms-1">
                        <?= $viewSupplier['status'] ?>
                    </span>
                </div>
                <a href="suppliers.php" class="btn-close"></a>
            </div>
            <div class="modal-body px-4">
                <!-- Financial Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="p-3 rounded-3 bg-light text-center">
                            <div class="text-muted small">Total Invoiced</div>
                            <h5 class="fw-bold text-dark mb-0">₹<?= number_format((float)$viewStats['total_invoiced'], 2) ?></h5>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 rounded-3 bg-light text-center">
                            <div class="text-muted small">Total Paid</div>
                            <h5 class="fw-bold text-success mb-0">₹<?= number_format((float)$viewStats['total_paid'], 2) ?></h5>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 rounded-3 bg-light text-center">
                            <div class="text-muted small">Current Outstanding</div>
                            <h5 class="fw-bold text-danger mb-0">₹<?= number_format((float)$viewStats['total_outstanding'], 2) ?></h5>
                        </div>
                    </div>
                </div>

                <!-- Supplier Details Table -->
                <div class="row g-3 small">
                    <div class="col-md-6">
                        <div class="p-2 border rounded-3 bg-white">
                            <div class="text-muted">GSTIN:</div>
                            <div class="fw-semibold font-monospace"><?= htmlspecialchars($viewSupplier['gstin'] ?? 'None') ?></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-2 border rounded-3 bg-white">
                            <div class="text-muted">Drug Licence:</div>
                            <div class="fw-semibold font-monospace"><?= htmlspecialchars($viewSupplier['drug_licence_no'] ?? 'None') ?> (<?= htmlspecialchars($viewSupplier['drug_licence_type'] ?? 'Standard') ?>)</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-2 border rounded-3 bg-white">
                            <div class="text-muted">Phone & Email:</div>
                            <div class="fw-semibold"><?= htmlspecialchars($viewSupplier['phone'] ?? '—') ?> | <?= htmlspecialchars($viewSupplier['email'] ?? '—') ?></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-2 border rounded-3 bg-white">
                            <div class="text-muted">Payment Terms & Credit:</div>
                            <div class="fw-semibold"><?= htmlspecialchars($viewSupplier['payment_terms'] ?? '30 Days') ?> (<?= (int)$viewSupplier['credit_days'] ?> Days, Limit: ₹<?= number_format((float)$viewSupplier['credit_limit'], 2) ?>)</div>
                        </div>
                    </div>
                    <div class="col-md-12">
                        <div class="p-2 border rounded-3 bg-white">
                            <div class="text-muted">Bank Details:</div>
                            <div class="fw-semibold"><?= htmlspecialchars($viewSupplier['bank_name'] ?? '—') ?> | A/C: <?= htmlspecialchars($viewSupplier['bank_account_no'] ?? '—') ?> | IFSC: <?= htmlspecialchars($viewSupplier['bank_ifsc'] ?? '—') ?></div>
                        </div>
                    </div>
                    <div class="col-md-12">
                        <div class="p-2 border rounded-3 bg-white">
                            <div class="text-muted">Full Address:</div>
                            <div class="fw-semibold"><?= htmlspecialchars($viewSupplier['address'] ?? '') ?>, <?= htmlspecialchars($viewSupplier['city'] ?? '') ?>, <?= htmlspecialchars($viewSupplier['state'] ?? '') ?> - <?= htmlspecialchars($viewSupplier['pincode'] ?? '') ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 px-4 pb-4">
                <a href="ledger.php?supplier_id=<?= $viewSupplier['supplier_id'] ?>" class="btn btn-outline-dark rounded-pill px-3"><i class="ti ti-book-2 me-1"></i>Open Account Ledger</a>
                <a href="orders.php?supplier_id=<?= $viewSupplier['supplier_id'] ?>" class="btn btn-outline-secondary rounded-pill px-3"><i class="ti ti-file-text me-1"></i>Purchase Orders</a>
                <a href="suppliers.php" class="btn btn-light rounded-pill px-4">Close</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
include __DIR__ . '/../../includes/footer.php';
?>