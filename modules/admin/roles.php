<?php
// modules/admin/roles.php - Roles & Permissions Overview

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';

require_permission('pharmacy.users.manage');

$page_title = 'Roles & Permissions';

$roles = $pdo->query("
    SELECT r.*, COUNT(rp.permission_id) AS permission_count,
           (SELECT COUNT(*) FROM pharmacy_users WHERE role_id = r.id) AS user_count
    FROM pharmacy_roles r
    LEFT JOIN pharmacy_role_permissions rp ON r.id = rp.role_id
    GROUP BY r.id
    ORDER BY r.id ASC
")->fetchAll();

$permissions = $pdo->query("SELECT * FROM pharmacy_permissions ORDER BY id ASC")->fetchAll();

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="mb-4">
    <h4 class="fw-bold mb-1 text-dark">
        <i class="ti ti-key text-emerald me-1.5"></i> Roles &amp; Permission Architecture
    </h4>
    <p class="text-muted small mb-0">Role-based access control matrix governing pharmacy permissions.</p>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card card-custom h-100">
            <div class="card-header bg-white border-bottom py-3 px-4">
                <h6 class="fw-bold mb-0 text-dark">Configured Pharmacy Roles (<?= count($roles) ?>)</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.88rem;">
                        <thead class="table-light">
                            <tr>
                                <th>Role</th>
                                <th>Description</th>
                                <th>Access Level</th>
                                <th>Assigned Users</th>
                                <th>Permissions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($roles as $r): ?>
                                <tr>
                                    <td class="fw-bold text-dark font-monospace"><?= sanitize($r['role_name']) ?></td>
                                    <td class="text-muted small"><?= sanitize($r['description'] ?: '-') ?></td>
                                    <td>
                                        <?php if (strtoupper($r['role_name']) === 'ADMIN' || strtoupper($r['role_name']) === 'ADMINISTRATOR'): ?>
                                            <span class="badge bg-success-subtle text-success"><i class="ti ti-shield-check me-1"></i>Full Access</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning-subtle text-dark border border-warning-subtle"><i class="ti ti-lock me-1"></i>Restricted Access</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary-subtle text-primary"><?= (int)$r['user_count'] ?> users</span>
                                    </td>
                                    <td>
                                        <span class="badge bg-success-subtle text-success"><?= (int)$r['permission_count'] ?> perms</span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card card-custom h-100">
            <div class="card-header bg-white border-bottom py-3 px-4">
                <h6 class="fw-bold mb-0 text-dark">Available Permission Keys (<?= count($permissions) ?>)</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 480px; overflow-y: auto;">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.84rem;">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>Key</th>
                                <th>Name</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($permissions as $p): ?>
                                <tr>
                                    <td><code><?= sanitize($p['permission_key']) ?></code></td>
                                    <td class="fw-semibold text-dark"><?= sanitize($p['permission_name']) ?></td>
                                    <td class="text-muted small"><?= sanitize($p['description'] ?: '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
