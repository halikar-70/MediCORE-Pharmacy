<?php
// modules/admin/users.php - Pharmacy Staff & User Accounts Management

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';

require_permission('pharmacy.users.manage');

$page_title = 'Pharmacy Staff Users';

$stmt = $pdo->query("
    SELECT u.*, r.role_name 
    FROM pharmacy_users u
    JOIN pharmacy_roles r ON u.role_id = r.id
    ORDER BY u.id ASC
");
$users = $stmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-1 text-dark">
            <i class="ti ti-user-cog text-emerald me-1.5"></i> Pharmacy Staff Accounts
        </h4>
        <p class="text-muted small mb-0">Authorized pharmacy operators, pharmacists, cashiers, and managers.</p>
    </div>
</div>

<div class="card card-custom">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size: 0.88rem;">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Username</th>
                        <th>Full Name</th>
                        <th>Role</th>
                        <th>Mobile / Email</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td class="text-muted small"><?= (int)$u['id'] ?></td>
                            <td class="fw-bold text-dark font-monospace"><?= sanitize($u['username']) ?></td>
                            <td class="fw-semibold text-dark"><?= sanitize($u['full_name']) ?></td>
                            <td>
                                <span class="badge bg-secondary-subtle text-secondary font-monospace">
                                    <?= sanitize($u['role_name']) ?>
                                </span>
                            </td>
                            <td>
                                <div><?= sanitize($u['mobile'] ?: '-') ?></div>
                                <div class="text-muted small"><?= sanitize($u['email'] ?: '-') ?></div>
                            </td>
                            <td>
                                <?php if ((int)$u['status'] === 1): ?>
                                    <span class="badge bg-success-subtle text-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger">Deactivated</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small"><?= format_date($u['last_login_at'], 'd M Y, H:i') ?></td>
                            <td class="text-muted small"><?= format_date($u['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
