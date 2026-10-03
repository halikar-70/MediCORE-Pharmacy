<?php
// modules/admin/audit_logs.php - Immutable Operational Audit Trail & Security Event Monitor (Chunk 7)

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/AuditService.php';
require_once __DIR__ . '/../../app/Services/ExportService.php';

use Pharmacy\Services\AuditService;
use Pharmacy\Services\ExportService;

require_permission('pharmacy.audit.view');

$page_title = 'Audit Trail & Security Events';
$auditService = new AuditService($pdo);

$tab = $_GET['tab'] ?? 'audit';
$actionFilter = trim($_GET['action'] ?? '');
$entityFilter = trim($_GET['entity'] ?? '');
$userIdFilter = !empty($_GET['user_id']) ? (int)$_GET['user_id'] : null;
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

// Export Handling
if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
    require_permission('pharmacy.reports.export');

    $where = ["DATE(a.created_at) BETWEEN ? AND ?"];
    $params = [$startDate, $endDate];

    if ($actionFilter !== '') {
        $where[] = "a.action = ?";
        $params[] = strtoupper($actionFilter);
    }
    if ($entityFilter !== '') {
        $where[] = "a.entity_type = ?";
        $params[] = $entityFilter;
    }
    if ($userIdFilter) {
        $where[] = "a.user_id = ?";
        $params[] = $userIdFilter;
    }

    $sql = "
        SELECT a.id, a.created_at, a.action, a.entity_type, a.entity_id,
               u.username, u.full_name, a.ip_address, a.old_values, a.new_values
        FROM pharmacy_audit_logs a
        LEFT JOIN pharmacy_users u ON a.user_id = u.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY a.id DESC
        LIMIT 2000
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $headers = ['Log ID', 'Timestamp', 'Action', 'Entity Type', 'Entity ID', 'Username', 'Full Name', 'IP Address', 'Old Values', 'New Values'];
    $rows = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rows[] = [
            $r['id'],
            $r['created_at'],
            $r['action'],
            $r['entity_type'],
            $r['entity_id'] ?? '',
            $r['username'] ?? 'System',
            $r['full_name'] ?? 'System',
            $r['ip_address'] ?? '',
            $r['old_values'] ?? '',
            $r['new_values'] ?? ''
        ];
    }
    ExportService::streamCsvDownload("audit_trail_{$startDate}_{$endDate}", $headers, $rows);
}

// Fetch Users for dropdown
$users = $pdo->query("SELECT id, username, full_name FROM pharmacy_users ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Distinct Actions & Entities for filters
$actions = $pdo->query("SELECT DISTINCT action FROM pharmacy_audit_logs ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);
$entities = $pdo->query("SELECT DISTINCT entity_type FROM pharmacy_audit_logs ORDER BY entity_type ASC")->fetchAll(PDO::FETCH_COLUMN);

// Build Filtered Logs Query
$where = ["DATE(a.created_at) BETWEEN ? AND ?"];
$params = [$startDate, $endDate];

if ($tab === 'security') {
    $where[] = "a.action IN ('UNAUTHORIZED_ACCESS', 'PERMISSION_DENIED', 'CSRF_FAILED', 'IDOR_ATTEMPT', 'LOGIN_FAILED', 'AUTH_FAILURE')";
} else {
    if ($actionFilter !== '') {
        $where[] = "a.action = ?";
        $params[] = strtoupper($actionFilter);
    }
}

if ($entityFilter !== '') {
    $where[] = "a.entity_type = ?";
    $params[] = $entityFilter;
}
if ($userIdFilter) {
    $where[] = "a.user_id = ?";
    $params[] = $userIdFilter;
}

$whereSql = implode(' AND ', $where);

// Count total
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM pharmacy_audit_logs a WHERE {$whereSql}");
$countStmt->execute($params);
$totalLogs = (int)$countStmt->fetchColumn();
$totalPages = ceil($totalLogs / $limit);

// Fetch paginated logs
$sql = "
    SELECT a.*, u.username, u.full_name
    FROM pharmacy_audit_logs a
    LEFT JOIN pharmacy_users u ON a.user_id = u.id
    WHERE {$whereSql}
    ORDER BY a.id DESC
    LIMIT ? OFFSET ?
";
$stmt = $pdo->prepare($sql);
$execParams = array_merge($params, [$limit, $offset]);
foreach ($execParams as $idx => $val) {
    $type = is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR;
    $stmt->bindValue($idx + 1, $val, $type);
}
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="container-fluid px-4 py-3">
    <!-- Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div>
            <h4 class="fw-bold mb-1 text-dark">
                <i class="ti ti-shield-lock text-emerald me-2"></i>Audit Trail &amp; Operational Security
            </h4>
            <p class="text-muted small mb-0">Immutable, non-destructive ledger recording transactional mutations, authorization events, and security exceptions.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="?tab=<?= urlencode($tab) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&action=<?= urlencode($actionFilter) ?>&entity=<?= urlencode($entityFilter) ?>&user_id=<?= (int)$userIdFilter ?>&export=csv" class="btn btn-sm btn-outline-success">
                <i class="ti ti-file-spreadsheet me-1"></i> Export CSV
            </a>
            <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-secondary">
                <i class="ti ti-printer me-1"></i> Print
            </button>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'audit' ? 'active fw-bold text-emerald border-bottom border-emerald border-2' : 'text-muted' ?>" href="?tab=audit&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>">
                <i class="ti ti-list-check me-1"></i>Operational Audit Trail
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'security' ? 'active fw-bold text-danger border-bottom border-danger border-2' : 'text-muted' ?>" href="?tab=security&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>">
                <i class="ti ti-shield-x me-1"></i>Security Events Monitor
            </a>
        </li>
    </ul>

    <!-- Filter Bar -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-center">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
                <div class="col-md-3">
                    <label class="small text-muted mb-1">Date Range</label>
                    <div class="d-flex gap-1 align-items-center">
                        <input type="date" name="start_date" class="form-control form-control-sm" value="<?= htmlspecialchars($startDate) ?>">
                        <span>-</span>
                        <input type="date" name="end_date" class="form-control form-control-sm" value="<?= htmlspecialchars($endDate) ?>">
                    </div>
                </div>

                <?php if ($tab !== 'security'): ?>
                <div class="col-md-2">
                    <label class="small text-muted mb-1">Action</label>
                    <select name="action" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Actions</option>
                        <?php foreach ($actions as $act): ?>
                            <option value="<?= htmlspecialchars($act) ?>" <?= $actionFilter === $act ? 'selected' : '' ?>>
                                <?= htmlspecialchars($act) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="col-md-2">
                    <label class="small text-muted mb-1">Entity</label>
                    <select name="entity" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Entities</option>
                        <?php foreach ($entities as $ent): ?>
                            <option value="<?= htmlspecialchars($ent) ?>" <?= $entityFilter === $ent ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ent) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="small text-muted mb-1">Staff / User</label>
                    <select name="user_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Users</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= $userIdFilter === (int)$u['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['full_name']) ?> (<?= htmlspecialchars($u['username']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-sm btn-emerald w-100"><i class="ti ti-filter me-1"></i>Filter</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Logs Table -->
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
            <h6 class="fw-bold mb-0 text-dark">
                <?= $tab === 'security' ? 'Security & Access Violations' : 'Recorded Audit Events' ?> 
                <span class="text-muted fw-normal small">(<?= number_format($totalLogs) ?> total)</span>
            </h6>
            <span class="badge bg-secondary-subtle text-secondary font-monospace">IMMUTABLE LEDGER</span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($logs)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="ti ti-folder-off fs-2 mb-2 d-block"></i>
                    No audit records match the current criteria.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-uppercase text-muted">
                            <tr>
                                <th>ID</th>
                                <th>Timestamp</th>
                                <th>Action</th>
                                <th>Entity</th>
                                <th>Entity Ref</th>
                                <th>User</th>
                                <th>IP Address</th>
                                <th>Before / After Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $l): ?>
                                <tr>
                                    <td class="font-monospace text-muted small">#<?= (int)$l['id'] ?></td>
                                    <td class="small text-muted"><?= htmlspecialchars($l['created_at']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= str_contains($l['action'], 'DELETE') || str_contains($l['action'], 'CANCEL') || str_contains($l['action'], 'UNAUTHORIZED') ? 'danger' : 'dark' ?> font-monospace" style="font-size: 0.72rem;">
                                            <?= htmlspecialchars($l['action']) ?>
                                        </span>
                                    </td>
                                    <td><code><?= htmlspecialchars($l['entity_type']) ?></code></td>
                                    <td class="font-monospace"><?= htmlspecialchars($l['entity_id'] ?: '-') ?></td>
                                    <td class="fw-semibold text-dark"><?= htmlspecialchars($l['full_name'] ?? 'System') ?></td>
                                    <td class="font-monospace small text-muted"><?= htmlspecialchars($l['ip_address'] ?: '-') ?></td>
                                    <td>
                                        <?php if ($l['old_values'] || $l['new_values']): ?>
                                            <details>
                                                <summary class="small text-primary cursor-pointer">View Diff</summary>
                                                <div class="mt-1 p-2 bg-light rounded small" style="max-width: 400px; max-height: 120px; overflow: auto; font-size: 0.72rem;">
                                                    <?php if ($l['old_values']): ?>
                                                        <div class="text-danger fw-bold">Before:</div>
                                                        <pre class="mb-1 font-monospace"><?= htmlspecialchars($l['old_values']) ?></pre>
                                                    <?php endif; ?>
                                                    <?php if ($l['new_values']): ?>
                                                        <div class="text-success fw-bold">After:</div>
                                                        <pre class="mb-0 font-monospace"><?= htmlspecialchars($l['new_values']) ?></pre>
                                                    <?php endif; ?>
                                                </div>
                                            </details>
                                        <?php else: ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="p-3 border-top d-flex justify-content-between align-items-center">
                    <span class="small text-muted">Showing <?= $offset + 1 ?> to <?= min($totalLogs, $offset + $limit) ?> of <?= $totalLogs ?> records</span>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?tab=<?= urlencode($tab) ?>&start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&action=<?= urlencode($actionFilter) ?>&entity=<?= urlencode($entityFilter) ?>&user_id=<?= (int)$userIdFilter ?>&page=<?= $p ?>"><?= $p ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
