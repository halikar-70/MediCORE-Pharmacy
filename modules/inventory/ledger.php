<?php
// modules/inventory/ledger.php - Stock Movement Ledger

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/AuditService.php';
require_once __DIR__ . '/../../app/Services/StockLedgerService.php';
require_once __DIR__ . '/../../app/Services/BatchService.php';
require_once __DIR__ . '/../../app/Services/MedicineService.php';

require_permission('pharmacy.ledger.view');

use Pharmacy\Services\StockLedgerService;

$ledgerService = new StockLedgerService($pdo);

$page_title = 'Stock Ledger';

$medId = (int)($_GET['medicine_id'] ?? 0);
$batchId = (int)($_GET['batch_id'] ?? 0);
$type = trim($_GET['transaction_type'] ?? '');
$startDate = trim($_GET['start_date'] ?? '');
$endDate = trim($_GET['end_date'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$filters = [];
if ($medId > 0) $filters['medicine_id'] = $medId;
if ($batchId > 0) $filters['batch_id'] = $batchId;
if ($type !== '' && $type !== 'All') $filters['transaction_type'] = $type;
if ($startDate !== '') $filters['start_date'] = $startDate;
if ($endDate !== '') $filters['end_date'] = $endDate;

$records = $ledgerService->getLedgerHistory($filters, $perPage, $offset);

// Count total
$countWhere = ["1=1"];
$countParams = [];
if ($medId > 0) { $countWhere[] = "medicine_id = ?"; $countParams[] = $medId; }
if ($batchId > 0) { $countWhere[] = "batch_id = ?"; $countParams[] = $batchId; }
if ($type !== '' && $type !== 'All') { $countWhere[] = "transaction_type = ?"; $countParams[] = $type; }
if ($startDate !== '') { $countWhere[] = "DATE(created_at) >= ?"; $countParams[] = $startDate; }
if ($endDate !== '') { $countWhere[] = "DATE(created_at) <= ?"; $countParams[] = $endDate; }

$countSql = "SELECT COUNT(*) FROM pharmacy_stock_ledger WHERE " . implode(' AND ', $countWhere);
$cStmt = $pdo->prepare($countSql);
$cStmt->execute($countParams);
$totalRecords = (int)$cStmt->fetchColumn();
$totalPages = max(1, ceil($totalRecords / $perPage));

$types = [
    'OPENING_STOCK', 'INITIAL_STOCK', 'PURCHASE', 'PURCHASE_RETURN', 'SUPPLIER_RETURN',
    'COUNTER_SALE', 'PRESCRIPTION_SALE', 'IPD_SALE', 'IPD_INDENT', 'SALE', 'SALE_RETURN',
    'ADJUSTMENT', 'STOCK_ADJUSTMENT', 'DAMAGE', 'EXPIRY', 'DISPOSAL', 'QUARANTINE_TRANSFER'
];

$medList = $pdo->query("SELECT medicine_id, medicine_name FROM medicines ORDER BY medicine_name ASC")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h4 class="fw-bold text-dark mb-1">
                <i class="ti ti-history text-emerald me-2"></i>Stock Movement Ledger
            </h4>
            <p class="text-muted small mb-0">Immutable, append-only chronological ledger tracking every single pharmaceutical inward and outward delta.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="adjustments.php" class="btn btn-outline-warning rounded-pill px-3 py-2 small">
                <i class="ti ti-adjustments me-1"></i> Stock Adjustment
            </a>
            <a href="batch_stock.php" class="btn btn-outline-secondary rounded-pill px-3 py-2 small">
                <i class="ti ti-packages me-1"></i> Current Stock
            </a>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 p-3 bg-white">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-12 col-md-3">
                <select name="medicine_id" id="ledgerMedicineSelect" class="form-select bg-light border-0">
                    <option value="0" <?= $medId === 0 ? 'selected' : '' ?>>All Medicines</option>
                    <?php foreach ($medList as $m): ?>
                        <option value="<?= $m['medicine_id'] ?>" <?= $medId === (int)$m['medicine_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($m['medicine_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <select name="transaction_type" class="form-select bg-light border-0">
                    <option value="All">All Transaction Types</option>
                    <?php foreach ($types as $t): ?>
                        <option value="<?= $t ?>" <?= $type === $t ? 'selected' : '' ?>><?= $t ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <input type="date" name="start_date" class="form-control bg-light border-0" value="<?= htmlspecialchars($startDate) ?>" placeholder="From Date">
            </div>
            <div class="col-6 col-md-2">
                <input type="date" name="end_date" class="form-control bg-light border-0" value="<?= htmlspecialchars($endDate) ?>" placeholder="To Date">
            </div>
            <div class="col-6 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-emerald text-white w-100 rounded-pill" style="background-color: #059669;">Filter</button>
                <a href="ledger.php" class="btn btn-light rounded-pill px-3"><i class="ti ti-refresh"></i></a>
            </div>
        </form>
    </div>

    <!-- Ledger Table -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white mb-5">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light text-muted small text-uppercase">
                    <tr>
                        <th class="ps-4">Tx ID</th>
                        <th>Timestamp</th>
                        <th>Medicine & Formulation</th>
                        <th>Batch</th>
                        <th>Transaction Type</th>
                        <th>Reference</th>
                        <th class="text-end">Quantity Delta</th>
                        <th class="text-end">Before &rarr; After</th>
                        <th>Audit Reason & User</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="ti ti-file-off fs-1 d-block mb-2 text-secondary"></i>
                                No stock ledger transactions found matching criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $r): ?>
                            <tr>
                                <td class="ps-4 font-monospace small text-muted">#<?= $r['ledger_id'] ?></td>
                                <td class="small text-muted font-monospace"><?= format_date($r['created_at'], 'd M Y H:i') ?></td>
                                <td>
                                    <div class="fw-bold text-dark">
                                        <a href="profile.php?id=<?= $r['medicine_id'] ?>" class="text-dark text-decoration-none hover-emerald">
                                            <?= htmlspecialchars($r['medicine_name']) ?>
                                        </a>
                                    </div>
                                    <div class="text-muted small"><?= htmlspecialchars($r['generic_name'] ?? '-') ?></div>
                                </td>
                                <td>
                                    <span class="font-monospace small bg-light px-2 py-1 rounded">
                                        <?= htmlspecialchars($r['batch_number'] ?? 'Master') ?>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                        $tt = $r['transaction_type'];
                                        $badgeClass = 'bg-light text-dark';
                                        if (in_array($tt, ['PURCHASE', 'OPENING_STOCK', 'INITIAL_STOCK', 'SALE_RETURN'])) {
                                            $badgeClass = 'bg-success-subtle text-success border border-success';
                                        } elseif (in_array($tt, ['COUNTER_SALE', 'PRESCRIPTION_SALE', 'IPD_SALE', 'IPD_INDENT', 'SALE'])) {
                                            $badgeClass = 'bg-primary-subtle text-primary border border-primary';
                                        } elseif (in_array($tt, ['DAMAGE', 'EXPIRY', 'DISPOSAL', 'PURCHASE_RETURN'])) {
                                            $badgeClass = 'bg-danger-subtle text-danger border border-danger';
                                        } elseif (in_array($tt, ['ADJUSTMENT', 'STOCK_ADJUSTMENT'])) {
                                            $badgeClass = 'bg-warning-subtle text-warning border border-warning';
                                        }
                                    ?>
                                    <span class="badge <?= $badgeClass ?> font-monospace"><?= htmlspecialchars($tt) ?></span>
                                </td>
                                <td class="font-monospace small text-muted"><?= htmlspecialchars($r['reference_no'] ?? '-') ?></td>
                                <td class="text-end fw-bold fs-6 <?= $r['quantity_change'] > 0 ? 'text-success' : 'text-danger' ?>">
                                    <?= ($r['quantity_change'] > 0 ? '+' : '') . $r['quantity_change'] ?>
                                </td>
                                <td class="text-end font-monospace small">
                                    <span class="text-muted"><?= number_format($r['balance_before']) ?></span>
                                    <span class="text-muted">&rarr;</span>
                                    <strong class="text-dark"><?= number_format($r['balance_after']) ?></strong>
                                </td>
                                <td class="small">
                                    <div class="text-dark"><?= htmlspecialchars($r['reason'] ?? '-') ?></div>
                                    <div class="text-muted" style="font-size: 0.75rem;"><i class="ti ti-user me-1"></i><?= htmlspecialchars($r['user_name'] ?? 'System') ?></div>
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
                    Showing <?= $offset + 1 ?> to <?= min($totalRecords, $offset + $perPage) ?> of <?= $totalRecords ?> transactions
                </div>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $page - 1 ?>&medicine_id=<?= $medId ?>&transaction_type=<?= urlencode($type) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>">Previous</a>
                        </li>
                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                <a class="page-link" href="?page=<?= $p ?>&medicine_id=<?= $medId ?>&transaction_type=<?= urlencode($type) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>"><?= $p ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $page + 1 ?>&medicine_id=<?= $medId ?>&transaction_type=<?= urlencode($type) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>">Next</a>
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