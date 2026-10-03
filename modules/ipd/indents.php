<?php
// modules/ipd/indents.php - IPD Ward Medication Indents & Dispensing Queue

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/DocumentSequenceService.php';
require_once __DIR__ . '/../../app/Services/StockLedgerService.php';
require_once __DIR__ . '/../../app/Services/FefoService.php';
require_once __DIR__ . '/../../app/Services/AuditService.php';
require_once __DIR__ . '/../../app/Services/IndentService.php';

use Pharmacy\Services\IndentService;

require_permission('pharmacy.indents.view');

$indentService = new IndentService($pdo);

$error = null;
$success = null;

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Security token mismatch.";
    } else {
        $action = $_POST['action'] ?? '';

        // 1. Create Ward Indent
        if ($action === 'create_indent') {
            try {
                $ward = trim($_POST['ward'] ?? '');
                $requestedBy = trim($_POST['requested_by'] ?? '');
                $priority = trim($_POST['priority'] ?? 'NORMAL');
                $medIds = $_POST['medicine_id'] ?? [];
                $quantities = $_POST['requested_qty'] ?? [];

                $items = [];
                foreach ($medIds as $k => $mId) {
                    $mId = (int)$mId;
                    $q = (int)($quantities[$k] ?? 0);
                    if ($mId > 0 && $q > 0) {
                        $items[] = [
                            'medicine_id'   => $mId,
                            'requested_qty' => $q
                        ];
                    }
                }

                $data = [
                    'ward'             => $ward,
                    'requested_by'     => $requestedBy,
                    'priority'         => $priority,
                    'bed_number'       => trim($_POST['bed_number'] ?? ''),
                    'patient_name'     => trim($_POST['patient_name'] ?? ''),
                    'ipd_admission_no' => trim($_POST['ipd_admission_no'] ?? ''),
                    'notes'            => trim($_POST['notes'] ?? '')
                ];

                $newInd = $indentService->createIndent($data, $items, $_SESSION['user_id'] ?? null);
                $success = "Ward Indent #{$newInd['indent_number']} created successfully!";
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }

        // 2. Approve Indent
        if ($action === 'approve_indent') {
            require_permission('pharmacy.indents.approve');
            try {
                $indId = (int)($_POST['indent_id'] ?? 0);
                $indentService->approveIndent($indId, null, $_SESSION['user_id'] ?? null);
                $success = "Indent approved for pharmacy dispensing.";
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }

        // 3. Fulfill Indent (Deduct stock through FEFO)
        if ($action === 'fulfill_indent') {
            require_permission('pharmacy.indents.fulfill');
            try {
                $indId = (int)($_POST['indent_id'] ?? 0);
                $itemIds = $_POST['fulfill_item_id'] ?? [];
                $quantities = $_POST['fulfill_qty'] ?? [];

                $fulfillPayload = [];
                foreach ($itemIds as $k => $itId) {
                    $q = (int)($quantities[$k] ?? 0);
                    if ($q > 0) {
                        $fulfillPayload[] = [
                            'item_id'  => (int)$itId,
                            'quantity' => $q
                        ];
                    }
                }

                if (empty($fulfillPayload)) {
                    throw new Exception("Please specify quantity to fulfill for at least one item.");
                }

                $indentService->fulfillIndent($indId, $fulfillPayload, $_SESSION['user_id'] ?? null);
                $success = "Medications dispatched to ward and stock deducted via FEFO!";
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }

        // 4. Reject Indent
        if ($action === 'reject_indent') {
            require_permission('pharmacy.indents.cancel');
            try {
                $indId = (int)($_POST['indent_id'] ?? 0);
                $reason = trim($_POST['rejection_reason'] ?? 'Not available / Rejected by pharmacy');
                $indentService->rejectIndent($indId, $reason, $_SESSION['user_id'] ?? null);
                $success = "Indent rejected.";
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }
}

// Filters
$statusFilter = trim($_GET['status'] ?? '');
$priorityFilter = trim($_GET['priority'] ?? '');
$search = trim($_GET['search'] ?? '');

$filters = [];
if ($statusFilter !== '' && $statusFilter !== 'ALL') $filters['status'] = $statusFilter;
if ($priorityFilter !== '' && $priorityFilter !== 'ALL') $filters['priority'] = $priorityFilter;
if ($search !== '') $filters['search'] = $search;

$indents = $indentService->listIndents($filters);

// View / Fulfill Modal Data
$viewIndent = null;
if (!empty($_GET['view_id'])) {
    $viewIndent = $indentService->getIndent((int)$_GET['view_id']);
}

// Print mode
if (isset($_GET['print_id'])) {
    $printInd = $indentService->getIndent((int)$_GET['print_id']);
    if ($printInd) {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Ward Indent <?= htmlspecialchars($printInd['indent_number']) ?></title>
            <style>
                body { font-family: Arial, sans-serif; font-size: 13px; padding: 20px; color: #000; }
                table { width: 100%; border-collapse: collapse; margin-top: 10px; }
                th, td { border: 1px solid #ddd; padding: 6px 8px; }
                th { background: #f8f9fa; }
            </style>
        </head>
        <body onload="window.print()">
            <div style="display:flex; align-items:center; gap:14px; margin-bottom:12px; border-bottom: 2px solid #059669; padding-bottom: 8px;">
                <img src="<?= BASE_URL ?>assets/images/vatsalya_logo.png" alt="Vatsalya Hospital Logo" style="height: 50px; object-fit: contain;">
                <div>
                    <h2 style="margin:0; font-size:17px; font-weight:bold; color:#059669;">VATSALYA PHARMACY — WARD DISPATCH SLIP</h2>
                    <div style="font-size:12px; color:#666;">Vatsalya Hospital &bull; Inpatient Medication Delivery</div>
                </div>
            </div>
            <div><strong>Indent No:</strong> <?= htmlspecialchars($printInd['indent_number']) ?> (<?= htmlspecialchars($printInd['priority']) ?>)</div>
            <div><strong>Ward:</strong> <?= htmlspecialchars($printInd['ward']) ?> | <strong>Bed:</strong> <?= htmlspecialchars($printInd['bed_number'] ?? 'N/A') ?></div>
            <div><strong>Requested By:</strong> <?= htmlspecialchars($printInd['requested_by']) ?> | <strong>Date:</strong> <?= htmlspecialchars($printInd['indent_date']) ?></div>
            <table>
                <thead>
                    <tr><th>Medicine</th><th>Requested</th><th>Dispensed</th><th>Pending</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($printInd['items'] as $it): ?>
                        <tr>
                            <td><?= htmlspecialchars($it['medicine_name']) ?></td>
                            <td align="center"><?= (int)$it['requested_qty'] ?></td>
                            <td align="center"><?= (int)$it['dispensed_qty'] ?></td>
                            <td align="center"><?= (int)$it['pending_qty'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin-top:20px;">
                <em>Note: Dispensed by pharmacy for ward use. Administration to patient must be verified on MAR.</em>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

// Active medicines catalog
$medicinesCatalog = $pdo->query("
    SELECT m.medicine_id, m.medicine_name, m.generic_name, m.dosage_form, m.strength,
           COALESCE((
               SELECT SUM(mb.quantity_available)
               FROM medicine_batches mb
               WHERE mb.medicine_id = m.medicine_id
                 AND mb.status = 'Active'
                 AND mb.quantity_available > 0
                 AND mb.expiry_date >= CURDATE()
           ), 0) as available_stock
    FROM medicines m 
    WHERE m.status = 'Active' 
    ORDER BY m.medicine_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Load real Hospital Wards for Indent creation
$hospitalWards = ['General Ward-209', 'General Ward-205', 'Private AC', 'Private Room', 'Deluxe Room', 'Emergency Ward', 'Twin Sharing Room', 'ICU', 'NICU'];
try {
    $hPdo = \Pharmacy\Database\Database::getHospitalConnection();
    if ($hPdo) {
        $wList = $hPdo->query("SELECT ward_name FROM wards WHERE status = 'Active' OR status IS NULL ORDER BY ward_name ASC")->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($wList)) {
            $hospitalWards = array_values(array_unique(array_filter($wList)));
        }
    }
} catch (Exception $e) {}

$page_title = 'IPD Ward Indents';

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-0 text-dark"><i class="ti ti-clipboard-list me-2 text-emerald"></i>IPD Ward Requisitions (Indents)</h4>
            <span class="text-muted small">Fulfill inpatient ward medication demands and deduct pharmacy inventory via FEFO</span>
        </div>
        <div>
            <button type="button" class="btn btn-emerald text-white fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#newIndentModal">
                <i class="ti ti-plus me-1"></i>New Ward Indent
            </button>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="ti ti-alert-circle me-2"></i><?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="ti ti-check me-2"></i><?= htmlspecialchars($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Filter Bar -->
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-center">
                <div class="col-md-4">
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Search Indent No, Ward, Nurse..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-3">
                    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="ALL">All Statuses</option>
                        <option value="SUBMITTED" <?= $statusFilter === 'SUBMITTED' ? 'selected' : '' ?>>Submitted (New)</option>
                        <option value="APPROVED" <?= $statusFilter === 'APPROVED' ? 'selected' : '' ?>>Approved</option>
                        <option value="PARTIALLY_FULFILLED" <?= $statusFilter === 'PARTIALLY_FULFILLED' ? 'selected' : '' ?>>Partially Fulfilled</option>
                        <option value="FULFILLED" <?= $statusFilter === 'FULFILLED' ? 'selected' : '' ?>>Fulfilled</option>
                        <option value="REJECTED" <?= $statusFilter === 'REJECTED' ? 'selected' : '' ?>>Rejected</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="priority" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="ALL">All Priorities</option>
                        <option value="STAT" <?= $priorityFilter === 'STAT' ? 'selected' : '' ?>>STAT (Emergency)</option>
                        <option value="URGENT" <?= $priorityFilter === 'URGENT' ? 'selected' : '' ?>>Urgent</option>
                        <option value="NORMAL" <?= $priorityFilter === 'NORMAL' ? 'selected' : '' ?>>Normal</option>
                    </select>
                </div>
                <div class="col-md-2 text-end">
                    <button type="submit" class="btn btn-sm btn-primary"><i class="ti ti-filter me-1"></i>Filter</button>
                    <a href="indents.php" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Indents Table -->
    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light small text-muted text-uppercase">
                        <tr>
                            <th>Indent No</th>
                            <th>Priority</th>
                            <th>Ward / Bed</th>
                            <th>Requested By</th>
                            <th>Items</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($indents)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">
                                    <i class="ti ti-clipboard-x fs-1 text-muted opacity-25 d-block mb-2"></i>
                                    No ward indents found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($indents as $ind): ?>
                                <?php
                                $prioBadge = match ($ind['priority']) {
                                    'STAT'   => 'bg-danger text-white',
                                    'URGENT' => 'bg-warning text-dark',
                                    default  => 'bg-light text-dark border'
                                };
                                $statusBadge = match ($ind['status']) {
                                    'FULFILLED'           => 'bg-success text-white',
                                    'PARTIALLY_FULFILLED' => 'bg-info text-dark',
                                    'APPROVED'            => 'bg-primary text-white',
                                    'REJECTED'            => 'bg-danger text-white',
                                    default               => 'bg-secondary text-white'
                                };
                                ?>
                                <tr>
                                    <td class="fw-bold font-monospace text-dark"><?= htmlspecialchars($ind['indent_number']) ?></td>
                                    <td><span class="badge <?= $prioBadge ?>"><?= htmlspecialchars($ind['priority']) ?></span></td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($ind['ward']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($ind['bed_number'] ?? 'Ward Stock') ?></small>
                                    </td>
                                    <td><?= htmlspecialchars($ind['requested_by']) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= $ind['items_count'] ?> items</span></td>
                                    <td><?= htmlspecialchars($ind['indent_date']) ?></td>
                                    <td><span class="badge <?= $statusBadge ?>"><?= htmlspecialchars($ind['status']) ?></span></td>
                                    <td class="text-end">
                                        <a href="indents.php?view_id=<?= $ind['indent_id'] ?>" class="btn btn-sm btn-outline-primary p-1 px-2 me-1">
                                            <i class="ti ti-eye"></i> View
                                        </a>
                                        <a href="indents.php?print_id=<?= $ind['indent_id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary p-1 px-2 me-1">
                                            <i class="ti ti-printer"></i>
                                        </a>
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

<!-- Modal: New Indent -->
<div class="modal fade" id="newIndentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="create_indent">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <div class="modal-header bg-light">
                    <h6 class="modal-title fw-bold text-dark"><i class="ti ti-plus me-1 text-emerald"></i>Create Ward Requisition Indent</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-3">
                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">Ward Name *</label>
                            <input type="text" name="ward" class="form-control form-control-sm" required placeholder="e.g. General Ward, ICU" list="hospitalWardList">
                            <datalist id="hospitalWardList">
                                <?php foreach ($hospitalWards as $wName): ?>
                                    <option value="<?= htmlspecialchars($wName) ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">Requested By (Nurse/Staff) *</label>
                            <input type="text" name="requested_by" class="form-control form-control-sm" required placeholder="Staff Name">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">Priority *</label>
                            <select name="priority" class="form-select form-select-sm">
                                <option value="NORMAL">Normal</option>
                                <option value="URGENT">Urgent</option>
                                <option value="STAT">STAT (Emergency)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Bed / Room No</label>
                            <input type="text" name="bed_number" class="form-control form-control-sm" placeholder="Optional">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Patient Name</label>
                            <input type="text" name="patient_name" class="form-control form-control-sm" placeholder="Optional">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">IPD Admission No</label>
                            <input type="text" name="ipd_admission_no" class="form-control form-control-sm" placeholder="Optional">
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="fw-bold mb-0 text-dark small text-uppercase">Requested Medications</h6>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="addIndentRow()">
                            <i class="ti ti-plus me-1"></i>Add Medicine
                        </button>
                    </div>

                    <table class="table table-sm table-bordered align-middle" id="indentItemsTable">
                        <thead class="table-light small">
                            <tr>
                                <th style="width: 50%;">Medicine</th>
                                <th style="width: 25%;" class="text-center">Available Stock</th>
                                <th style="width: 20%;" class="text-center">Requested Quantity</th>
                                <th style="width: 5%;"></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-emerald text-white fw-bold">Submit Indent</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: View & Fulfill Indent -->
<?php if ($viewIndent): ?>
<div class="modal fade show d-block" id="viewIndentModal" tabindex="-1" style="background: rgba(0,0,0,0.5);">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="fulfill_indent">
                <input type="hidden" name="indent_id" value="<?= $viewIndent['indent_id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <div class="modal-header bg-light">
                    <h6 class="modal-title fw-bold text-dark"><i class="ti ti-clipboard me-1 text-emerald"></i>Indent <?= htmlspecialchars($viewIndent['indent_number']) ?> Details</h6>
                    <a href="indents.php" class="btn-close"></a>
                </div>
                <div class="modal-body p-3">
                    <div class="row g-2 mb-3 bg-light p-2 rounded border">
                        <div class="col-md-4">
                            <small class="text-muted d-block">Ward / Bed:</small>
                            <strong class="text-dark"><?= htmlspecialchars($viewIndent['ward']) ?> (<?= htmlspecialchars($viewIndent['bed_number'] ?? 'General') ?>)</strong>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted d-block">Requested By:</small>
                            <strong class="text-dark"><?= htmlspecialchars($viewIndent['requested_by']) ?></strong>
                            <div class="small text-muted"><?= htmlspecialchars($viewIndent['indent_date']) ?></div>
                        </div>
                        <div class="col-md-4 text-end">
                            <small class="text-muted d-block">Priority / Status:</small>
                            <span class="badge bg-danger me-1"><?= htmlspecialchars($viewIndent['priority']) ?></span>
                            <span class="badge bg-primary"><?= htmlspecialchars($viewIndent['status']) ?></span>
                        </div>
                    </div>

                    <h6 class="fw-bold mb-2 small text-uppercase text-dark">Requested Items & Fulfillment</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle">
                            <thead class="table-light small">
                                <tr>
                                    <th>Medicine</th>
                                    <th class="text-center">Requested</th>
                                    <th class="text-center">Dispensed</th>
                                    <th class="text-center">Pending</th>
                                    <th class="text-center">Pharmacy Stock</th>
                                    <th class="text-center" style="width: 140px;">Fulfill Now (Qty)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($viewIndent['items'] as $it): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($it['medicine_name']) ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($it['dosage_form'] ?? '') ?></small>
                                        </td>
                                        <td class="text-center"><?= (int)$it['requested_qty'] ?></td>
                                        <td class="text-center text-success fw-bold"><?= (int)$it['dispensed_qty'] ?></td>
                                        <td class="text-center text-danger fw-bold"><?= (int)$it['pending_qty'] ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-light text-dark border"><?= (int)$it['available_stock'] ?> Avail</span>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($it['pending_qty'] > 0 && $viewIndent['status'] !== 'FULFILLED' && $viewIndent['status'] !== 'REJECTED'): ?>
                                                <input type="hidden" name="fulfill_item_id[]" value="<?= $it['item_id'] ?>">
                                                <input type="number" name="fulfill_qty[]" min="0" max="<?= min((int)$it['pending_qty'], (int)$it['available_stock']) ?>" class="form-control form-control-sm text-center fw-bold" value="<?= min((int)$it['pending_qty'], (int)$it['available_stock']) ?>">
                                            <?php else: ?>
                                                <span class="text-muted small">Done</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer py-2 d-flex justify-content-between">
                    <div>
                        <a href="indents.php" class="btn btn-sm btn-secondary">Close</a>
                    </div>
                    <div>
                        <?php if ($viewIndent['status'] === 'SUBMITTED'): ?>
                            <button type="submit" form="approveForm" class="btn btn-sm btn-primary me-2"><i class="ti ti-check me-1"></i>Approve Indent</button>
                        <?php endif; ?>
                        <?php if ($viewIndent['status'] !== 'FULFILLED' && $viewIndent['status'] !== 'REJECTED'): ?>
                            <button type="submit" class="btn btn-sm btn-success fw-bold"><i class="ti ti-truck me-1"></i>Fulfill via FEFO</button>
                        <?php endif; ?>
                    </div>
                </div>
            </form>

            <form method="POST" id="approveForm">
                <input type="hidden" name="action" value="approve_indent">
                <input type="hidden" name="indent_id" value="<?= $viewIndent['indent_id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const medicinesCatalog = <?= json_encode($medicinesCatalog) ?>;

function getIndentStockBadge(stock) {
    if (stock > 10) {
        return `<span class="badge font-monospace px-2 py-1" style="background:#ecfdf5;color:#059669;border:1px solid #a7f3d0;"><i class="ti ti-check me-1"></i>${stock} In Stock</span>`;
    } else if (stock > 0) {
        return `<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle font-monospace px-2 py-1"><i class="ti ti-alert-triangle me-1"></i>Low: ${stock}</span>`;
    } else {
        return `<span class="badge bg-danger-subtle text-danger border border-danger-subtle font-monospace px-2 py-1"><i class="ti ti-x me-1"></i>0 Out</span>`;
    }
}

function addIndentRow() {
    const tbody = document.querySelector('#indentItemsTable tbody');
    const tr = document.createElement('tr');

    let medOptions = '<option value="">-- Select Medicine --</option>';
    medicinesCatalog.forEach(m => {
        const avail = parseInt(m.available_stock) || 0;
        const icon = avail > 10 ? '🟢' : (avail > 0 ? '🟡' : '🔴');
        medOptions += `<option value="${m.medicine_id}" data-stock="${avail}">${icon} [Stock: ${avail}] ${m.medicine_name} (${m.dosage_form || ''})</option>`;
    });

    tr.innerHTML = `
        <td><select name="medicine_id[]" class="form-select form-select-sm" required onchange="onIndentMedChange(this)">${medOptions}</select></td>
        <td class="text-center indent-stock-cell"><span class="badge bg-light text-muted border">-</span></td>
        <td><input type="number" name="requested_qty[]" min="1" class="form-control form-control-sm text-center fw-bold" value="10" required></td>
        <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger p-1" onclick="this.closest('tr').remove()"><i class="ti ti-trash"></i></button></td>
    `;
    tbody.appendChild(tr);
}

function onIndentMedChange(select) {
    const opt = select.options[select.selectedIndex];
    const row = select.closest('tr');
    const cell = row.querySelector('.indent-stock-cell');
    if (opt.value) {
        const stock = parseInt(opt.dataset.stock) || 0;
        cell.innerHTML = getIndentStockBadge(stock);
    } else {
        cell.innerHTML = '<span class="badge bg-light text-muted border">-</span>';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    addIndentRow();
});
</script>

<?php
include __DIR__ . '/../../includes/footer.php';
?>