<?php
// modules/sales/monitoring.php - Sales Registry & Monitoring Dashboard

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/DocumentSequenceService.php';
require_once __DIR__ . '/../../app/Services/StockLedgerService.php';
require_once __DIR__ . '/../../app/Services/FefoService.php';
require_once __DIR__ . '/../../app/Services/AuditService.php';
require_once __DIR__ . '/../../app/Services/SalesService.php';

use Pharmacy\Services\SalesService;

require_permission('pharmacy.sales.view');

$salesService = new SalesService($pdo);

// Auto-purge expired bill versions (>30 days old)
$salesService->purgeExpiredEditHistories(30);

// AJAX Endpoint: 1-Month Bill Edit History & Version Snapshots
if (isset($_GET['ajax_bill_history'])) {
    header('Content-Type: application/json');
    $saleId = (int)($_GET['sale_id'] ?? 0);
    $currSale = $salesService->getSale($saleId);
    $histories = $salesService->getBillEditHistory($saleId);
    echo json_encode([
        'success' => true,
        'current_sale' => $currSale,
        'history_versions' => $histories
    ]);
    exit;
}

$error = null;
$success = null;

// POST Handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Security token mismatch.";
    } else {
        $action = $_POST['action'] ?? '';

        // Add Payment
        if ($action === 'add_payment') {
            try {
                $saleId = (int)($_POST['sale_id'] ?? 0);
                $amt = (float)($_POST['amount'] ?? 0.0);
                $mode = trim($_POST['payment_mode'] ?? 'CASH');
                $ref = trim($_POST['reference_number'] ?? '');

                $salesService->addPayment($saleId, $amt, $mode, $ref !== '' ? $ref : null, $_SESSION['user_id'] ?? null);
                $success = "Payment of ₹" . number_format($amt, 2) . " recorded successfully!";
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }

        // Settle Overpayment Refund
        if ($action === 'settle_overpayment_refund') {
            if (!has_permission('pharmacy.sales.edit') && !has_permission('pharmacy.sales.create')) {
                $error = "Unauthorized to issue customer overpayment refunds.";
            } else {
                try {
                    $saleId = (int)($_POST['sale_id'] ?? 0);
                    $amt = (float)($_POST['amount'] ?? 0.0);
                    $mode = trim($_POST['payment_mode'] ?? 'CASH');
                    $ref = trim($_POST['reference_number'] ?? '');
                    $notes = trim($_POST['notes'] ?? '');

                    $result = $salesService->settleOverpaymentRefund($saleId, $amt, $mode, $ref !== '' ? $ref : null, $notes, $_SESSION['user_id'] ?? null);
                    $success = "Overpayment refund of ₹" . number_format($result['refund_amount'], 2) . " settled successfully! Voucher #" . htmlspecialchars($result['refund_voucher']) . " recorded.";
                } catch (Exception $e) {
                    $error = $e->getMessage();
                }
            }
        }

        // Cancel Sale
        if ($action === 'cancel_sale') {
            require_permission('pharmacy.sales.cancel');
            try {
                $saleId = (int)($_POST['sale_id'] ?? 0);
                $reason = trim($_POST['cancellation_reason'] ?? '');
                $salesService->cancelSale($saleId, $reason, $_SESSION['user_id'] ?? null);
                $success = "Sale cancelled successfully. Compensating stock movements created.";
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }

        // Update Sale Info (Customer / Doctor / Notes)
        if ($action === 'update_sale_info') {
            try {
                $saleId = (int)($_POST['sale_id'] ?? 0);
                $saleData = [
                    'customer_name'   => trim($_POST['customer_name'] ?? ''),
                    'customer_mobile' => trim($_POST['customer_mobile'] ?? ''),
                    'doctor_name'     => trim($_POST['doctor_name'] ?? ''),
                    'notes'           => trim($_POST['notes'] ?? '')
                ];
                $salesService->updateSaleMetadata($saleId, $saleData, $_SESSION['user_id'] ?? null);
                $success = "Sale details updated successfully.";
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }
}

// Filters
$saleType = trim($_GET['sale_type'] ?? '');
$paymentStatus = trim($_GET['payment_status'] ?? '');
$search = trim($_GET['search'] ?? '');
$startDate = trim($_GET['start_date'] ?? '');
$endDate = trim($_GET['end_date'] ?? '');

$filters = [];
if ($saleType !== '' && $saleType !== 'ALL') $filters['sale_type'] = $saleType;
if ($paymentStatus !== '' && $paymentStatus !== 'ALL') $filters['payment_status'] = $paymentStatus;
if ($search !== '') $filters['search'] = $search;
if ($startDate !== '') $filters['start_date'] = $startDate;
if ($endDate !== '') $filters['end_date'] = $endDate;

$sales = $salesService->listSales($filters, 100);

// View Sale Details Modal Data
$viewSale = null;
if (!empty($_GET['view_id'])) {
    $viewSale = $salesService->getSale((int)$_GET['view_id']);
}

$page_title = 'Sales Registry & Monitoring';

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="w-100">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-0 text-dark"><i class="ti ti-chart-dots me-2 text-emerald"></i>Sales Monitoring Registry</h4>
            <span class="text-muted small">Real-time sales invoices, payments, batch allocation traceability, and returns</span>
        </div>
        <div>
            <a href="counter.php" class="btn btn-emerald text-white fw-bold shadow-sm">
                <i class="ti ti-plus me-1"></i>New OPD Sale
            </a>
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

    <!-- Filter Card -->
    <div class="card shadow-sm border-0 mb-3 rounded-3">
        <div class="card-header bg-white border-bottom py-2.5 px-3 d-flex justify-content-between align-items-center">
            <span class="small fw-bold text-dark d-inline-flex align-items-center gap-1.5">
                <i class="ti ti-adjustments-horizontal text-emerald fs-5"></i> Filter &amp; Search Sales Records
            </span>
            <div id="filterBadgeWrapper">
                <?php if (!empty($filters)): ?>
                    <span class="badge bg-emerald-subtle text-emerald border border-emerald-subtle small font-monospace px-2 py-1">
                        <?= count($filters) ?> Active Filter<?= count($filters) > 1 ? 's' : '' ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body p-3">
            <form id="monitoringFilterForm" method="GET" class="row g-2.5 align-items-end">
                <div class="col-lg-3 col-md-6">
                    <label class="form-label small fw-semibold text-muted mb-1 d-flex align-items-center gap-1">
                        <i class="ti ti-search text-emerald"></i> Search Keyword
                    </label>
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Search Invoice, Customer, Mobile..." value="<?= htmlspecialchars($search) ?>" autocomplete="off">
                </div>
                <div class="col-lg-2 col-md-3">
                    <label class="form-label small fw-semibold text-muted mb-1 d-flex align-items-center gap-1">
                        <i class="ti ti-category text-primary"></i> Sale Type
                    </label>
                    <select name="sale_type" class="form-select form-select-sm no-search" data-no-search="true">
                        <option value="">All Sale Types</option>
                        <option value="COUNTER_SALE" <?= $saleType === 'COUNTER_SALE' ? 'selected' : '' ?>>OPD Sales</option>
                        <option value="PRESCRIPTION_SALE" <?= $saleType === 'PRESCRIPTION_SALE' ? 'selected' : '' ?>>Prescription</option>
                        <option value="IPD_SALE" <?= $saleType === 'IPD_SALE' ? 'selected' : '' ?>>IPD Patient</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-3">
                    <label class="form-label small fw-semibold text-muted mb-1 d-flex align-items-center gap-1">
                        <i class="ti ti-credit-card text-success"></i> Payment Status
                    </label>
                    <select name="payment_status" class="form-select form-select-sm no-search" data-no-search="true">
                        <option value="">All Payment Statuses</option>
                        <option value="PAID" <?= $paymentStatus === 'PAID' ? 'selected' : '' ?>>Paid</option>
                        <option value="PARTIALLY_PAID" <?= $paymentStatus === 'PARTIALLY_PAID' ? 'selected' : '' ?>>Partially Paid</option>
                        <option value="UNPAID" <?= $paymentStatus === 'UNPAID' ? 'selected' : '' ?>>Unpaid</option>
                        <option value="OVERPAID" <?= $paymentStatus === 'OVERPAID' ? 'selected' : '' ?>>Overpayment Refund Due</option>
                        <option value="CREDIT" <?= $paymentStatus === 'CREDIT' ? 'selected' : '' ?>>Credit</option>
                        <option value="CANCELLED" <?= $paymentStatus === 'CANCELLED' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-3">
                    <label class="form-label small fw-semibold text-muted mb-1 d-flex align-items-center gap-1">
                        <i class="ti ti-calendar text-secondary"></i> From Date
                    </label>
                    <input type="date" name="start_date" class="form-control form-control-sm" value="<?= htmlspecialchars($startDate) ?>" title="Start Date">
                </div>
                <div class="col-lg-2 col-md-3">
                    <label class="form-label small fw-semibold text-muted mb-1 d-flex align-items-center gap-1">
                        <i class="ti ti-calendar text-secondary"></i> To Date
                    </label>
                    <input type="date" name="end_date" class="form-control form-control-sm" value="<?= htmlspecialchars($endDate) ?>" title="End Date">
                </div>
                <div class="col-lg-1 col-md-6 d-flex gap-1.5">
                    <button type="submit" id="btnSubmitFilter" class="btn btn-sm btn-emerald fw-semibold flex-grow-1 d-inline-flex align-items-center justify-content-center gap-1 shadow-xs" title="Apply Filters">
                        <i class="ti ti-filter"></i> Filter
                    </button>
                    <div id="resetBtnWrapper">
                        <?php if (!empty($filters)): ?>
                            <a href="monitoring.php" onclick="resetAllFilters(event)" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center justify-content-center px-2 shadow-xs" title="Reset all filters">
                                <i class="ti ti-rotate-clockwise"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Sales Table -->
    <div class="card shadow-sm border-0 rounded-3">
        <div class="card-body p-0">
            <div class="table-responsive" style="max-height: calc(100vh - 240px); overflow-y: auto;">
                <table class="table table-hover align-middle mb-0" style="min-width: 1140px;">
                    <thead class="table-light small text-muted text-uppercase sticky-top" style="top: 0; z-index: 2; background-color: #f8fafc; border-bottom: 2px solid #e2e8f0; box-shadow: 0 1px 2px rgba(0,0,0,0.04);">
                        <tr>
                            <th class="ps-3 text-nowrap" style="width: 140px;">Invoice No</th>
                            <th class="text-nowrap" style="width: 115px;">Date</th>
                            <th class="text-center text-nowrap" style="width: 100px;">Type</th>
                            <th class="text-nowrap" style="width: 220px;">Customer / Patient</th>
                            <th class="text-end text-nowrap font-monospace" style="width: 110px;">Total (₹)</th>
                            <th class="text-end text-nowrap font-monospace" style="width: 110px;">Paid (₹)</th>
                            <th class="text-end text-nowrap font-monospace" style="width: 110px;">Due (₹)</th>
                            <th class="text-center text-nowrap" style="width: 120px;">Payment</th>
                            <th class="text-center text-nowrap" style="width: 110px;">Status</th>
                            <th class="text-end pe-3 text-nowrap" style="width: 130px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="salesTableBody">
                        <?php if (empty($sales)): ?>
                            <tr>
                                <td colspan="10" class="text-center py-5 text-muted">
                                    <i class="ti ti-receipt-off fs-1 text-muted opacity-25 d-block mb-2"></i>
                                    No sales records found matching the specified filters.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sales as $s): ?>
                                <?php
                                $payClass = match ($s['payment_status']) {
                                    'PAID'                      => 'bg-success-subtle text-success border border-success-subtle',
                                    'PARTIALLY_PAID', 'PARTIAL' => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
                                    'CREDIT'                    => 'bg-info-subtle text-info border border-info-subtle',
                                    'CANCELLED'                 => 'bg-danger-subtle text-danger border border-danger-subtle',
                                    default                     => 'bg-secondary-subtle text-secondary border border-secondary-subtle'
                                };
                                $typeBadge = match ($s['sale_type']) {
                                    'COUNTER_SALE'      => '<span class="badge font-monospace px-2 py-1" style="background:#ecfdf5;color:#059669;border:1px solid #a7f3d0;">OPD Sale</span>',
                                    'PRESCRIPTION_SALE' => '<span class="badge font-monospace px-2 py-1" style="background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe;">Rx Sale</span>',
                                    'IPD_SALE'          => '<span class="badge font-monospace px-2 py-1" style="background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;">IPD Sale</span>',
                                    default             => '<span class="badge bg-light text-dark border font-monospace px-2 py-1">' . htmlspecialchars($s['sale_type']) . '</span>'
                                };
                                $isDue = (float)$s['balance_amount'] > 0.009;
                                $isEditedBill = (strpos($s['notes'] ?? '', '[EDITED:') !== false);
                                $excessAmount = max(0.00, round((float)$s['paid_amount'] - (float)$s['grand_total'], 2));
                                $hasOverpaymentDue = ($excessAmount > 0.00);
                                $isRefundCompleted = (strpos($s['notes'] ?? '', 'OVERPAYMENT_REFUND_COMPLETED') !== false);
                                ?>
                                <tr class="<?= $s['status'] === 'CANCELLED' ? 'table-light text-muted' : '' ?>">
                                    <td class="ps-3 fw-bold font-monospace text-dark text-nowrap" style="font-size: 0.86rem;">
                                        <?= htmlspecialchars($s['sale_number']) ?>
                                        <?php if ($isEditedBill): ?>
                                            <span class="badge bg-warning text-dark border border-warning-subtle font-monospace ms-1" style="font-size: 0.66rem;">
                                                <i class="ti ti-history me-0.5"></i>EDITED
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($hasOverpaymentDue): ?>
                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle font-monospace ms-1" style="font-size: 0.66rem;" title="Customer Overpayment: Refund Due">
                                                <i class="ti ti-cash-refund me-0.5"></i>REFUND DUE ₹<?= number_format($excessAmount, 2) ?>
                                            </span>
                                        <?php elseif ($isRefundCompleted): ?>
                                            <span class="badge bg-info-subtle text-info border border-info-subtle font-monospace ms-1" style="font-size: 0.66rem;" title="Overpayment Refund Settled">
                                                <i class="ti ti-check me-0.5"></i>REFUNDED
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-nowrap text-dark small" style="font-size: 0.84rem;"><?= date('d-M-Y', strtotime($s['sale_date'])) ?></td>
                                    <td class="text-center text-nowrap"><?= $typeBadge ?></td>
                                    <td style="max-width: 220px;">
                                        <div class="fw-bold text-dark text-truncate" title="<?= htmlspecialchars($s['customer_name']) ?>" style="font-size: 0.86rem;"><?= htmlspecialchars($s['customer_name']) ?></div>
                                        <?php if (!empty($s['customer_mobile'])): ?>
                                            <div class="text-muted small font-monospace" style="font-size: 0.74rem;"><i class="ti ti-phone me-1 opacity-75"></i><?= htmlspecialchars($s['customer_mobile']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end fw-bold text-dark text-nowrap font-monospace" style="font-size: 0.88rem;">₹<?= number_format($s['grand_total'], 2) ?></td>
                                    <td class="text-end fw-bold text-success text-nowrap font-monospace" style="font-size: 0.88rem;">₹<?= number_format($s['paid_amount'], 2) ?></td>
                                    <td class="text-end fw-bold text-nowrap font-monospace <?= $isDue ? 'text-danger' : ($hasOverpaymentDue ? 'text-warning-emphasis' : 'text-muted') ?>" style="font-size: 0.88rem;">
                                        <?php if ($hasOverpaymentDue): ?>
                                            <span class="text-warning-emphasis" title="Refund Due">Excess ₹<?= number_format($excessAmount, 2) ?></span>
                                        <?php else: ?>
                                            <?= $isDue ? '₹' . number_format($s['balance_amount'], 2) : '₹0.00' ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center text-nowrap">
                                        <span class="badge <?= $payClass ?> font-monospace px-2 py-1" style="font-size: 0.72rem;"><?= htmlspecialchars($s['payment_status']) ?></span>
                                    </td>
                                    <td class="text-center text-nowrap">
                                        <?php if ($s['status'] === 'CANCELLED'): ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle font-monospace px-2 py-1" style="font-size: 0.72rem;">CANCELLED</span>
                                        <?php else: ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle font-monospace px-2 py-1" style="font-size: 0.72rem;">COMPLETED</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-3 text-nowrap">
                                        <div class="d-inline-flex align-items-center justify-content-end gap-1">
                                            <?php 
                                                $editUrl = ($s['sale_type'] === 'IPD_SALE') ? 'regular.php?edit_sale_id=' . $s['sale_id'] : 'counter.php?edit_sale_id=' . $s['sale_id'];
                                                if ($s['status'] === 'CANCELLED') {
                                                    $editUrl = 'invoice.php?id=' . $s['sale_id'];
                                                }
                                            ?>
                                            <a href="<?= $editUrl ?>" class="btn btn-sm btn-outline-primary p-1" style="width: 29px; height: 29px; display: inline-flex; align-items: center; justify-content: center;" title="<?= $s['status'] === 'CANCELLED' ? 'View Bill' : 'View & Edit Bill' ?>">
                                                <i class="ti ti-edit"></i>
                                            </a>
                                            <?php if ($s['status'] !== 'CANCELLED' && has_permission('pharmacy.sales_returns.create')): ?>
                                                <a href="returns.php?sale_id=<?= $s['sale_id'] ?>" class="btn btn-sm btn-outline-warning p-1" style="width: 29px; height: 29px; display: inline-flex; align-items: center; justify-content: center; color: #d97706; border-color: #fde68a;" title="Return Medicines / Create Return Bill">
                                                    <i class="ti ti-arrow-back-up"></i>
                                                </a>
                                            <?php endif; ?>
                                            <a href="counter.php?print_id=<?= $s['sale_id'] ?>" target="_blank" class="btn btn-sm btn-light border p-1" style="width: 29px; height: 29px; display: inline-flex; align-items: center; justify-content: center;" title="Reprint Receipt">
                                                <i class="ti ti-printer"></i>
                                            </a>
                                            <?php if ($s['status'] !== 'CANCELLED' && $isDue): ?>
                                                <button type="button" class="btn btn-sm btn-outline-success p-1" style="width: 29px; height: 29px; display: inline-flex; align-items: center; justify-content: center;" title="Add Payment" onclick="openPaymentModal(<?= $s['sale_id'] ?>, '<?= $s['sale_number'] ?>', <?= $s['balance_amount'] ?>)">
                                                    <i class="ti ti-cash"></i>
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($s['status'] !== 'CANCELLED' && $hasOverpaymentDue && (has_permission('pharmacy.sales.edit') || has_permission('pharmacy.sales.create'))): ?>
                                                <button type="button" class="btn btn-sm btn-outline-warning p-1" style="width: 29px; height: 29px; display: inline-flex; align-items: center; justify-content: center; color: #b45309; border-color: #fde68a;" title="Settle Overpayment Refund (₹<?= number_format($excessAmount, 2) ?>)" onclick="openRefundModal(<?= $s['sale_id'] ?>, '<?= $s['sale_number'] ?>', <?= $excessAmount ?>)">
                                                    <i class="ti ti-cash-refund"></i>
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($s['status'] !== 'CANCELLED' && has_permission('pharmacy.sales.cancel')): ?>
                                                <button type="button" class="btn btn-sm btn-outline-danger p-1" style="width: 29px; height: 29px; display: inline-flex; align-items: center; justify-content: center;" title="Cancel Sale" onclick="openCancelModal(<?= $s['sale_id'] ?>, '<?= $s['sale_number'] ?>')">
                                                    <i class="ti ti-x"></i>
                                                </button>
                                            <?php endif; ?>
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
</div>

<!-- Modal: View Sale Details & Batch Traceability -->
<?php if ($viewSale): ?>
<div class="modal fade show d-block" id="viewSaleModal" tabindex="-1" style="background: rgba(0,0,0,0.5);">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h6 class="modal-title fw-bold text-dark"><i class="ti ti-file-invoice me-1 text-emerald"></i>Invoice <?= htmlspecialchars($viewSale['sale_number']) ?> Details</h6>
                <a href="monitoring.php" class="btn-close"></a>
            </div>
            <div class="modal-body p-3">
                <div class="row g-2 mb-3 bg-light p-3 rounded border">
                    <div class="col-md-4">
                        <small class="text-muted d-block">Customer / Patient:</small>
                        <strong class="text-dark"><?= htmlspecialchars($viewSale['customer_name']) ?></strong>
                        <div class="small text-muted"><?= htmlspecialchars($viewSale['customer_mobile'] ?? 'No Mobile') ?></div>
                    </div>
                    <div class="col-md-4">
                        <small class="text-muted d-block">Sale Type & Date:</small>
                        <span class="badge bg-secondary"><?= htmlspecialchars($viewSale['sale_type']) ?></span>
                        <div class="small text-muted"><?= htmlspecialchars($viewSale['sale_date']) ?></div>
                    </div>
                    <div class="col-md-4 text-end">
                        <small class="text-muted d-block">Grand Total / Paid:</small>
                        <strong class="text-dark fs-5">₹<?= number_format($viewSale['grand_total'], 2) ?></strong>
                        <div class="small text-success">Paid: ₹<?= number_format($viewSale['paid_amount'], 2) ?> (Due: ₹<?= number_format($viewSale['balance_amount'], 2) ?>)</div>
                    </div>
                </div>

                <h6 class="fw-bold mb-2 small text-uppercase text-dark">Dispensed Items & Batch Traceability</h6>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered align-middle">
                        <thead class="table-light small">
                            <tr>
                                <th>Medicine</th>
                                <th>Allocated Batches (FEFO)</th>
                                <th class="text-center">Total Qty</th>
                                <th class="text-end">Unit Price</th>
                                <th class="text-end">Line Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($viewSale['items'] as $it): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($it['medicine_name']) ?></div>
                                        <small class="text-muted">HSN: <?= htmlspecialchars($it['hsn_code'] ?? 'N/A') ?></small>
                                    </td>
                                    <td>
                                        <?php foreach ($it['batches'] as $b): ?>
                                            <span class="badge bg-light text-dark border me-1 mb-1">
                                                <strong><?= htmlspecialchars($b['batch_number']) ?></strong>: <?= $b['allocated_quantity'] ?> units (Exp: <?= $b['expiry_date'] ?>)
                                            </span>
                                        <?php endforeach; ?>
                                    </td>
                                    <td class="text-center fw-bold"><?= (int)$it['quantity'] ?></td>
                                    <td class="text-end">₹<?= number_format($it['unit_price'], 2) ?></td>
                                    <td class="text-end fw-bold text-emerald">₹<?= number_format($it['line_total'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Payment Vouchers History -->
                <h6 class="fw-bold mb-2 small text-uppercase text-dark">Payment Receipts</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-striped">
                        <thead>
                            <tr>
                                <th>Receipt No</th>
                                <th>Date</th>
                                <th>Mode</th>
                                <th>Reference</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($viewSale['payments'])): ?>
                                <tr><td colspan="5" class="text-center text-muted small">No payment records found (Credit/Unpaid sale).</td></tr>
                            <?php else: ?>
                                <?php foreach ($viewSale['payments'] as $p): ?>
                                    <?php $isRefundVoucher = ((float)$p['amount'] < 0); ?>
                                    <tr>
                                        <td class="font-monospace fw-bold <?= $isRefundVoucher ? 'text-danger' : '' ?>">
                                            <?= htmlspecialchars($p['payment_number']) ?>
                                            <?php if ($isRefundVoucher): ?>
                                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle ms-1" style="font-size: 0.65rem;">REFUND</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($p['payment_date']) ?></td>
                                        <td><span class="badge <?= $isRefundVoucher ? 'bg-warning-subtle text-warning-emphasis' : 'bg-light text-dark' ?> border"><?= htmlspecialchars($p['payment_mode']) ?></span></td>
                                        <td><?= htmlspecialchars($p['reference_number'] ?? '-') ?></td>
                                        <td class="text-end fw-bold <?= $isRefundVoucher ? 'text-danger' : 'text-success' ?>">
                                            <?= $isRefundVoucher ? '-₹' . number_format(abs($p['amount']), 2) : '₹' . number_format($p['amount'], 2) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer py-2">
                <a href="monitoring.php" class="btn btn-sm btn-secondary">Close</a>
                <a href="counter.php?print_id=<?= $viewSale['sale_id'] ?>" target="_blank" class="btn btn-sm btn-primary">
                    <i class="ti ti-printer me-1"></i>Print Receipt
                </a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal: Add Payment -->
<div class="modal fade" id="addPaymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_payment">
                <input type="hidden" name="sale_id" id="paySaleId" value="">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <div class="modal-header bg-light">
                    <h6 class="modal-title fw-bold text-dark"><i class="ti ti-cash me-1 text-emerald"></i>Record Payment Voucher</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-3">
                    <div class="alert alert-info py-2 small">
                        Recording payment for Invoice: <strong id="paySaleNumber"></strong><br>
                        Remaining Due Balance: <strong id="payDueBalance"></strong>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Amount to Pay (₹) *</label>
                        <input type="number" step="any" min="0.01" name="amount" id="payAmountInput" class="form-control form-control-sm text-end fw-bold fs-6" placeholder="0.00" onfocus="this.select()" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Payment Mode *</label>
                        <select name="payment_mode" class="form-select form-select-sm">
                            <option value="CASH">Cash</option>
                            <option value="UPI">UPI / Online</option>
                            <option value="CARD">Debit / Credit Card</option>
                            <option value="BANK_TRANSFER">Bank Transfer</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Reference / UTR Number</label>
                        <input type="text" name="reference_number" class="form-control form-control-sm" placeholder="Optional transaction ID">
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-success fw-bold">Post Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Settle Overpayment Refund -->
<div class="modal fade" id="settleRefundModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="settle_overpayment_refund">
                <input type="hidden" name="sale_id" id="refundSaleId" value="">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <div class="modal-header bg-warning-subtle border-bottom py-2.5 px-3">
                    <h6 class="modal-title fw-bold text-dark mb-0"><i class="ti ti-cash-refund me-1 text-warning-emphasis"></i>Settle Customer Overpayment Refund</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-3">
                    <div class="alert alert-warning py-2 small border shadow-xs">
                        Issuing refund for Invoice: <strong id="refundSaleNumber" class="font-monospace"></strong><br>
                        Maximum Outstanding Excess: <strong id="refundExcessMax" class="font-monospace text-warning-emphasis"></strong>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Refund Amount (₹) *</label>
                        <input type="number" step="any" min="0.01" name="amount" id="refundAmountInput" class="form-control form-control-sm text-end fw-bold fs-6 font-monospace" placeholder="0.00" onfocus="this.select()" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Refund Payment Mode *</label>
                        <select name="payment_mode" class="form-select form-select-sm">
                            <option value="CASH">Cash</option>
                            <option value="UPI">UPI / Online</option>
                            <option value="CARD">Debit / Credit Card</option>
                            <option value="BANK_TRANSFER">Bank Transfer</option>
                            <option value="CHEQUE">Bank Cheque</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Reference / Refund Receipt / UTR</label>
                        <input type="text" name="reference_number" class="form-control form-control-sm" placeholder="Optional transaction ID">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Notes / Audit Memo</label>
                        <input type="text" name="notes" class="form-control form-control-sm" placeholder="e.g. Overpayment refund for invoice edit">
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-warning fw-bold text-dark shadow-xs"><i class="ti ti-cash-refund me-1"></i>Confirm &amp; Settle Refund</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Cancel Sale -->
<div class="modal fade" id="cancelSaleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="cancel_sale">
                <input type="hidden" name="sale_id" id="cancelSaleId" value="">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <div class="modal-header bg-danger text-white">
                    <h6 class="modal-title fw-bold"><i class="ti ti-alert-triangle me-1"></i>Cancel Sale Transaction</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-3">
                    <div class="alert alert-warning py-2 small">
                        <strong>Warning:</strong> Cancelling sale <strong id="cancelSaleNumber"></strong> will write compensating <code>SALE_RETURN</code> stock movements back into inventory and mark invoice CANCELLED.
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Reason for Cancellation *</label>
                        <textarea name="cancellation_reason" class="form-control form-control-sm" rows="3" required placeholder="State why this transaction is being reversed..."></textarea>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Keep Sale</button>
                    <button type="submit" class="btn btn-sm btn-danger fw-bold">Confirm & Reverse Stock</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Edit Sale Clerical Details -->
<div class="modal fade" id="editSaleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="monitoring.php">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="update_sale_info">
                <input type="hidden" name="sale_id" id="editSaleId">
                <div class="modal-header bg-light">
                    <h6 class="modal-title fw-bold text-dark"><i class="ti ti-edit me-1 text-emerald"></i>Edit Sale Info (<span id="editSaleNumberDisplay"></span>)</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-3">
                    <div class="alert alert-light border small text-muted mb-3">
                        <i class="ti ti-info-circle me-1 text-primary"></i>
                        Updates customer, doctor, and notes on the existing invoice without altering stock history or financial totals.
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Customer / Patient Name *</label>
                        <input type="text" name="customer_name" id="editSaleCustomerName" class="form-control form-control-sm" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Mobile Number</label>
                        <input type="text" name="customer_mobile" id="editSaleCustomerMobile" class="form-control form-control-sm" placeholder="e.g. 9876543210">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Prescribing Doctor</label>
                        <input type="text" name="doctor_name" id="editSaleDoctorName" class="form-control form-control-sm" placeholder="e.g. Dr. A. Sharma">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Sale Notes / Remarks</label>
                        <textarea name="notes" id="editSaleNotes" class="form-control form-control-sm" rows="2" placeholder="Reference notes..."></textarea>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-emerald text-white fw-bold">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: 1-Month Bill Edit History & Comparative Snapshots -->
<div class="modal fade" id="billVersionHistoryModal" tabindex="-1" aria-labelledby="billVersionHistoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-warning-subtle border-bottom py-2.5 px-3">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-warning text-dark font-monospace px-2 py-1">
                        <i class="ti ti-history me-1"></i>30-DAY ARCHIVE
                    </span>
                    <h6 class="modal-title fw-bold text-dark mb-0" id="billVersionHistoryModalLabel">
                        Bill Revision History &amp; Pre-Edit Audit Trail
                    </h6>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3 bg-light">
                <div id="billHistoryModalLoading" class="text-center py-5">
                    <div class="spinner-border text-warning" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <div class="text-muted small mt-2">Retrieving archival version snapshots...</div>
                </div>
                <div id="billHistoryModalContent" style="display: none;">
                    <!-- Injected dynamically via JS -->
                </div>
            </div>
            <div class="modal-footer py-2 px-3 bg-white border-top d-flex justify-content-between">
                <small class="text-muted">
                    <i class="ti ti-info-circle me-1 text-primary"></i>
                    Historical pre-edit snapshots are retained for <strong>30 days (1 month)</strong> for auditing and compliance.
                </small>
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function escapeHtml(text) {
    if (!text) return '';
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(text).replace(/[&<>"']/g, m => map[m]);
}

function openBillHistoryModal(saleId) {
    const modalEl = document.getElementById('billVersionHistoryModal');
    const loadingEl = document.getElementById('billHistoryModalLoading');
    const contentEl = document.getElementById('billHistoryModalContent');
    const modal = new bootstrap.Modal(modalEl);

    loadingEl.style.display = 'block';
    contentEl.style.display = 'none';
    modal.show();

    fetch('monitoring.php?ajax_bill_history=1&sale_id=' + encodeURIComponent(saleId))
        .then(res => res.json())
        .then(data => {
            loadingEl.style.display = 'none';
            contentEl.style.display = 'block';

            if (!data.success || !data.history_versions || data.history_versions.length === 0) {
                contentEl.innerHTML = `
                    <div class="text-center py-4">
                        <i class="ti ti-clock-history fs-1 text-muted opacity-50 mb-2"></i>
                        <p class="text-muted mb-0">No pre-edit archival records found or the 30-day retention period has elapsed.</p>
                    </div>
                `;
                return;
            }

            renderBillHistory(data.current_sale, data.history_versions);
        })
        .catch(err => {
            loadingEl.style.display = 'none';
            contentEl.style.display = 'block';
            contentEl.innerHTML = `<div class="alert alert-danger py-2 small">Failed to load bill history: ${escapeHtml(err.message)}</div>`;
        });
}

function renderBillHistory(currSale, histories) {
    const contentEl = document.getElementById('billHistoryModalContent');
    let html = `
        <div class="alert alert-info py-2 px-3 small d-flex align-items-center justify-content-between mb-3 border shadow-xs">
            <div>
                <strong>Active / Current Bill:</strong> 
                <span class="font-monospace text-primary fw-bold">#${escapeHtml(currSale.sale_number)}</span> &bull; 
                Total: <span class="fw-bold text-success font-monospace">₹${parseFloat(currSale.grand_total).toFixed(2)}</span>
            </div>
            <span class="badge bg-primary font-monospace">${histories.length} Archival Version(s) Saved</span>
        </div>
    `;

    histories.forEach((h, idx) => {
        const vDate = new Date(h.created_at).toLocaleString();
        const expDate = new Date(h.retention_until).toLocaleDateString();
        
        html += `
            <div class="card border rounded-3 mb-3 bg-white shadow-xs overflow-hidden">
                <div class="card-header bg-warning-subtle border-bottom py-2.5 px-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-warning text-dark border border-warning-subtle fw-bold font-monospace px-2 py-1">
                            PRE-EDIT SNAPSHOT (v${h.version_number})
                        </span>
                        <strong class="text-dark font-monospace" style="font-size: 0.88rem;">#${escapeHtml(h.original_sale_number)}</strong>
                        <span class="text-muted small">&bull; Modified on: <strong>${vDate}</strong></span>
                        ${h.edited_by_name ? `<span class="badge bg-light text-secondary border font-monospace" style="font-size: 0.70rem;"><i class="ti ti-user me-0.5"></i>${escapeHtml(h.edited_by_name)}</span>` : ''}
                    </div>
                    <div class="small text-muted font-monospace" style="font-size: 0.74rem;">
                        Retained until: <strong>${expDate}</strong>
                    </div>
                </div>

                <div class="card-body p-3">
                    <div class="row g-2 mb-3 text-center">
                        <div class="col-sm-3 col-6">
                            <div class="p-2 border rounded-2 bg-light">
                                <div class="text-muted small" style="font-size: 0.70rem;">PRE-EDIT SUBTOTAL</div>
                                <div class="fw-bold font-monospace">₹${parseFloat(h.subtotal_amount).toFixed(2)}</div>
                            </div>
                        </div>
                        <div class="col-sm-3 col-6">
                            <div class="p-2 border rounded-2 bg-light">
                                <div class="text-muted small" style="font-size: 0.70rem;">PRE-EDIT GST</div>
                                <div class="fw-bold font-monospace">₹${parseFloat(h.gst_amount).toFixed(2)}</div>
                            </div>
                        </div>
                        <div class="col-sm-3 col-6">
                            <div class="p-2 border rounded-2 bg-light">
                                <div class="text-muted small" style="font-size: 0.70rem;">PRE-EDIT GRAND TOTAL</div>
                                <div class="fw-bold text-danger font-monospace">₹${parseFloat(h.grand_total).toFixed(2)}</div>
                            </div>
                        </div>
                        <div class="col-sm-3 col-6">
                            <div class="p-2 border rounded-2 bg-success-subtle text-dark border-success-subtle">
                                <div class="text-muted small" style="font-size: 0.70rem;">CURRENT UPDATED TOTAL</div>
                                <div class="fw-bold text-success font-monospace">₹${parseFloat(currSale.grand_total).toFixed(2)}</div>
                            </div>
                        </div>
                    </div>

                    <div class="table-responsive border rounded-2">
                        <table class="table table-sm table-hover align-middle mb-0" style="font-size: 0.80rem;">
                            <thead class="table-light small text-muted text-uppercase" style="font-size: 0.70rem;">
                                <tr>
                                    <th style="width: 35px;" class="text-center">#</th>
                                    <th>MEDICINE / ITEM NAME</th>
                                    <th>BATCH &amp; EXPIRY</th>
                                    <th style="width: 70px;" class="text-center">PRE-QTY</th>
                                    <th style="width: 90px;" class="text-end">UNIT RATE</th>
                                    <th style="width: 95px;" class="text-end">LINE TOTAL</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${(h.items || []).map((it, itIdx) => `
                                    <tr>
                                        <td class="text-center text-muted font-monospace">${itIdx + 1}</td>
                                        <td>
                                            <div class="fw-bold text-dark">${escapeHtml(it.medicine_name || 'Item #' + it.medicine_id)}</div>
                                            ${it.composition ? `<div class="small text-muted" style="font-size: 0.68rem;">${escapeHtml(it.composition)}</div>` : ''}
                                        </td>
                                        <td class="font-monospace text-muted small" style="font-size: 0.72rem;">
                                            ${escapeHtml(it.batch_number || 'GEN-01')} ${it.expiry_date ? '(' + escapeHtml(it.expiry_date) + ')' : ''}
                                        </td>
                                        <td class="text-center font-monospace fw-bold text-dark">${it.quantity}</td>
                                        <td class="text-end font-monospace text-muted">₹${parseFloat(it.unit_price).toFixed(2)}</td>
                                        <td class="text-end font-monospace fw-bold text-dark">₹${parseFloat(it.line_total).toFixed(2)}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;
    });

    contentEl.innerHTML = html;
}
function openPaymentModal(saleId, saleNum, due) {
    document.getElementById('paySaleId').value = saleId;
    document.getElementById('paySaleNumber').innerText = saleNum;
    document.getElementById('payDueBalance').innerText = '₹' + parseFloat(due).toFixed(2);
    document.getElementById('payAmountInput').value = parseFloat(due).toFixed(2);
    document.getElementById('payAmountInput').max = parseFloat(due).toFixed(2);
    new bootstrap.Modal(document.getElementById('addPaymentModal')).show();
}

function openRefundModal(saleId, saleNum, excessAmount) {
    document.getElementById('refundSaleId').value = saleId;
    document.getElementById('refundSaleNumber').innerText = saleNum;
    document.getElementById('refundExcessMax').innerText = '₹' + parseFloat(excessAmount).toFixed(2);
    const amtInput = document.getElementById('refundAmountInput');
    amtInput.value = parseFloat(excessAmount).toFixed(2);
    amtInput.max = parseFloat(excessAmount).toFixed(2);
    new bootstrap.Modal(document.getElementById('settleRefundModal')).show();
}

function openCancelModal(saleId, saleNum) {
    document.getElementById('cancelSaleId').value = saleId;
    document.getElementById('cancelSaleNumber').innerText = saleNum;
    new bootstrap.Modal(document.getElementById('cancelSaleModal')).show();
}

function openEditSaleModal(data) {
    document.getElementById('editSaleId').value = data.sale_id;
    document.getElementById('editSaleNumberDisplay').innerText = data.sale_number;
    document.getElementById('editSaleCustomerName').value = data.customer_name || '';
    document.getElementById('editSaleCustomerMobile').value = data.customer_mobile || '';
    document.getElementById('editSaleDoctorName').value = data.doctor_name || '';
    document.getElementById('editSaleNotes').value = data.notes || '';
    new bootstrap.Modal(document.getElementById('editSaleModal')).show();
}

// ----------------- INSTANT LIVE FILTER AUTOMATION -----------------
let filterAbortController = null;
let searchDebounceTimer = null;

function applyInstantFilter() {
    const form = document.getElementById('monitoringFilterForm');
    if (!form) return;

    const formData = new FormData(form);
    const params = new URLSearchParams();
    for (const [key, val] of formData.entries()) {
        const cleanVal = (val || '').trim();
        if (cleanVal !== '') {
            params.append(key, cleanVal);
        }
    }

    const newUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
    window.history.replaceState({}, '', newUrl);

    const tbody = document.getElementById('salesTableBody');
    const filterBtn = document.getElementById('btnSubmitFilter');
    if (filterBtn) {
        filterBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" style="width:0.8rem;height:0.8rem;"></span> Filter';
    }
    if (tbody) {
        tbody.style.opacity = '0.35';
        tbody.style.transition = 'opacity 0.15s ease';
    }

    if (filterAbortController) {
        filterAbortController.abort();
    }
    filterAbortController = new AbortController();

    fetch(newUrl, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        signal: filterAbortController.signal
    })
    .then(r => r.text())
    .then(html => {
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');

        const newTbody = doc.getElementById('salesTableBody');
        if (newTbody && tbody) {
            tbody.innerHTML = newTbody.innerHTML;
            tbody.style.opacity = '1';
        }

        const newBadge = doc.getElementById('filterBadgeWrapper');
        const oldBadge = document.getElementById('filterBadgeWrapper');
        if (newBadge && oldBadge) {
            oldBadge.innerHTML = newBadge.innerHTML;
        }

        const newReset = doc.getElementById('resetBtnWrapper');
        const oldReset = document.getElementById('resetBtnWrapper');
        if (newReset && oldReset) {
            oldReset.innerHTML = newReset.innerHTML;
        }

        if (filterBtn) {
            filterBtn.innerHTML = '<i class="ti ti-filter"></i> Filter';
        }
    })
    .catch(err => {
        if (err.name !== 'AbortError') {
            if (tbody) tbody.style.opacity = '1';
            if (filterBtn) filterBtn.innerHTML = '<i class="ti ti-filter"></i> Filter';
        }
    });
}

function resetAllFilters(e) {
    if (e) e.preventDefault();
    const form = document.getElementById('monitoringFilterForm');
    if (!form) return;
    const searchInp = form.querySelector('input[name="search"]');
    if (searchInp) searchInp.value = '';
    const saleSelect = form.querySelector('select[name="sale_type"]');
    if (saleSelect) saleSelect.value = '';
    const paySelect = form.querySelector('select[name="payment_status"]');
    if (paySelect) paySelect.value = '';
    const startInp = form.querySelector('input[name="start_date"]');
    if (startInp) startInp.value = '';
    const endInp = form.querySelector('input[name="end_date"]');
    if (endInp) endInp.value = '';
    applyInstantFilter();
}

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('monitoringFilterForm');
    if (!form) return;

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        applyInstantFilter();
    });

    const saleSelect = form.querySelector('select[name="sale_type"]');
    if (saleSelect) {
        saleSelect.addEventListener('change', applyInstantFilter);
    }

    const paySelect = form.querySelector('select[name="payment_status"]');
    if (paySelect) {
        paySelect.addEventListener('change', applyInstantFilter);
    }

    const startInp = form.querySelector('input[name="start_date"]');
    if (startInp) {
        startInp.addEventListener('change', applyInstantFilter);
    }

    const endInp = form.querySelector('input[name="end_date"]');
    if (endInp) {
        endInp.addEventListener('change', applyInstantFilter);
    }

    const searchInp = form.querySelector('input[name="search"]');
    if (searchInp) {
        searchInp.addEventListener('input', function() {
            clearTimeout(searchDebounceTimer);
            searchDebounceTimer = setTimeout(applyInstantFilter, 250);
        });
    }
});
</script>

<?php
include __DIR__ . '/../../includes/footer.php';
?>