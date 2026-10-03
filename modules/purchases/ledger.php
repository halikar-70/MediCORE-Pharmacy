<?php
// modules/purchases/ledger.php - Supplier Financial Account Ledger & Statement

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/SupplierPaymentService.php';

require_permission('pharmacy.supplier_ledger.view');

use Pharmacy\Services\SupplierPaymentService;

$payService = new SupplierPaymentService($pdo);
$page_title = 'Supplier Financial Ledger';

$suppliersList = $pdo->query("SELECT supplier_id, supplier_name, supplier_code FROM pharmacy_suppliers WHERE status != 'Inactive' ORDER BY supplier_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$supplierId = !empty($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : ($suppliersList[0]['supplier_id'] ?? null);

$ledgerData = null;
if ($supplierId) {
    try {
        $ledgerData = $payService->getSupplierLedger($supplierId);
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Print mode
$isPrint = isset($_GET['print']) && $ledgerData;
if ($isPrint) {
    $sup = $ledgerData['supplier'];
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Supplier Ledger - <?= htmlspecialchars($sup['supplier_name']) ?></title>
        <link href="<?= BASE_URL ?>assets/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 13px; color: #111; padding: 20px; }
            .ledger-header { border-bottom: 2px solid #059669; padding-bottom: 12px; margin-bottom: 20px; }
            .table-sm th, .table-sm td { padding: 6px 8px; }
            @media print { .no-print { display: none; } }
        </style>
    </head>
    <body>
        <div class="no-print mb-3 text-end">
            <button type="button" onclick="window.print()" class="btn btn-primary btn-sm">Print Statement</button>
            <button type="button" onclick="window.close()" class="btn btn-secondary btn-sm">Close</button>
        </div>
        <div class="ledger-header d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-3">
                <img src="<?= BASE_URL ?>assets/images/vatsalya_logo.png" alt="Vatsalya Hospital Logo" style="max-height: 52px; object-fit: contain;">
                <div>
                    <h3 class="fw-bold mb-0 text-success"><?= APP_NAME ?></h3>
                    <div class="text-muted small">Vatsalya Hospital | Supplier Ledger Statement</div>
                </div>
            </div>
            <div class="text-end">
                <h4 class="fw-bold mb-0">STATEMENT OF ACCOUNT</h4>
                <div class="fw-bold"><?= htmlspecialchars($sup['supplier_name']) ?> (<?= htmlspecialchars($sup['supplier_code']) ?>)</div>
                <div class="small">As of: <?= date('d-M-Y') ?></div>
            </div>
        </div>

        <table class="table table-bordered table-sm">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Particulars / Document Type</th>
                    <th>Document #</th>
                    <th>Ref / Bill #</th>
                    <th class="text-end">Debit (₹)</th>
                    <th class="text-end">Credit (₹)</th>
                    <th class="text-end">Balance (₹)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($ledgerData['entries'])): ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">No transactions recorded for this supplier.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($ledgerData['entries'] as $tx): ?>
                        <tr>
                            <td><?= date('d-M-Y', strtotime($tx['tx_date'])) ?></td>
                            <td>
                                <strong><?= htmlspecialchars($tx['doc_type']) ?></strong>
                                <?php if (!empty($tx['notes'])): ?>
                                    <div class="text-muted" style="font-size: 10px;"><?= htmlspecialchars($tx['notes']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="font-monospace"><?= htmlspecialchars($tx['doc_no']) ?></td>
                            <td class="font-monospace"><?= htmlspecialchars($tx['ref_no']) ?></td>
                            <td class="text-end"><?= (float)$tx['debit'] > 0 ? number_format((float)$tx['debit'], 2) : '—' ?></td>
                            <td class="text-end"><?= (float)$tx['credit'] > 0 ? number_format((float)$tx['credit'], 2) : '—' ?></td>
                            <td class="text-end fw-bold <?= (float)$tx['running_balance'] > 0 ? 'text-danger' : 'text-success' ?>">
                                ₹<?= number_format((float)$tx['running_balance'], 2) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="6" class="text-end fs-6">Closing Net Outstanding Balance:</th>
                    <th class="text-end fs-6 text-danger">₹<?= number_format((float)$ledgerData['closing_balance'], 2) ?></th>
                </tr>
            </tfoot>
        </table>
    </body>
    </html>
    <?php
    exit;
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
                <i class="ti ti-book-2 text-emerald me-2"></i>Supplier Financial Ledger
            </h4>
            <p class="text-muted small mb-0">Double-entry verified audit trail of purchases (Debits), payments (Credits), and running creditor balances.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="outstanding.php" class="btn btn-outline-danger rounded-pill px-3 py-2 small">
                <i class="ti ti-building-bank me-1"></i> Outstanding Aging
            </a>
            <?php if ($ledgerData): ?>
                <a href="ledger.php?supplier_id=<?= $supplierId ?>&print=1" target="_blank" class="btn btn-outline-dark rounded-pill px-3 py-2 small">
                    <i class="ti ti-printer me-1"></i> Print Statement
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Supplier Selector Card -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 bg-white">
        <div class="card-body p-3">
            <form method="GET" action="ledger.php" class="row g-2 align-items-center">
                <div class="col-md-9">
                    <select name="supplier_id" class="form-select bg-light border-0" onchange="this.form.submit()">
                        <?php foreach ($suppliersList as $s): ?>
                            <option value="<?= $s['supplier_id'] ?>" <?= $supplierId === (int)$s['supplier_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['supplier_name']) ?> (<?= htmlspecialchars($s['supplier_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-dark w-100 rounded-3">Load Ledger</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($ledgerData): ?>
        <?php $sup = $ledgerData['supplier']; ?>
        <!-- Ledger Summary Header -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm rounded-4 bg-white p-3 text-center">
                    <div class="text-muted small text-uppercase">Opening Balance</div>
                    <h5 class="fw-bold text-dark mb-0">₹<?= number_format((float)$ledgerData['opening_balance'], 2) ?></h5>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm rounded-4 bg-white p-3 text-center">
                    <div class="text-muted small text-uppercase">Total Transactions</div>
                    <h5 class="fw-bold text-primary mb-0"><?= count($ledgerData['entries']) ?> Records</h5>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm rounded-4 bg-white p-3 text-center">
                    <div class="text-muted small text-uppercase">Closing Outstanding Balance</div>
                    <h5 class="fw-bold <?= (float)$ledgerData['closing_balance'] > 0 ? 'text-danger' : 'text-success' ?> mb-0">
                        ₹<?= number_format((float)$ledgerData['closing_balance'], 2) ?>
                    </h5>
                </div>
            </div>
        </div>

        <!-- Ledger Entries Table -->
        <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr class="text-uppercase small text-muted">
                            <th class="ps-4">Transaction Date</th>
                            <th>Document Type</th>
                            <th>Document #</th>
                            <th>Reference / Vendor Bill #</th>
                            <th class="text-end">Debit (Purchases)</th>
                            <th class="text-end">Credit (Payments)</th>
                            <th class="text-end pe-4">Running Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($ledgerData['entries'])): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="ti ti-book-off fs-1 d-block mb-2 text-secondary"></i>
                                    No ledger entries recorded for <?= htmlspecialchars($sup['supplier_name']) ?>.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($ledgerData['entries'] as $tx): ?>
                                <tr>
                                    <td class="ps-4 small"><?= date('d-M-Y', strtotime($tx['tx_date'])) ?></td>
                                    <td>
                                        <?php if ($tx['doc_type'] === 'PURCHASE'): ?>
                                            <span class="badge bg-primary-subtle text-primary rounded-pill px-2 py-1">PURCHASE BILL</span>
                                        <?php elseif ($tx['doc_type'] === 'PAYMENT'): ?>
                                            <span class="badge bg-success-subtle text-success rounded-pill px-2 py-1">PAYMENT VOUCHER</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary rounded-pill px-2 py-1"><?= htmlspecialchars($tx['doc_type']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="font-monospace fw-semibold text-dark"><?= htmlspecialchars($tx['doc_no']) ?></td>
                                    <td class="font-monospace text-muted"><?= htmlspecialchars($tx['ref_no']) ?></td>
                                    <td class="text-end fw-semibold text-dark">
                                        <?= (float)$tx['debit'] > 0 ? '₹' . number_format((float)$tx['debit'], 2) : '—' ?>
                                    </td>
                                    <td class="text-end fw-semibold text-success">
                                        <?= (float)$tx['credit'] > 0 ? '₹' . number_format((float)$tx['credit'], 2) : '—' ?>
                                    </td>
                                    <td class="text-end pe-4 fw-bold <?= (float)$tx['running_balance'] > 0 ? 'text-danger' : 'text-success' ?>">
                                        ₹<?= number_format((float)$tx['running_balance'], 2) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-light">
                            <th colspan="6" class="text-end ps-4">Net Current Outstanding:</th>
                            <th class="text-end pe-4 fs-6 text-danger">₹<?= number_format((float)$ledgerData['closing_balance'], 2) ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
