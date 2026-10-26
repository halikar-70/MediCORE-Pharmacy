<?php
// login.php - Dedicated Pharmacy Management System Authentication (MediPro Aesthetic)
$is_public_page = true;

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/includes/functions.php';

use Pharmacy\Auth\AuthManager;

// If already authenticated, redirect to dashboard
if (auth_check()) {
    header("Location: index.php");
    exit;
}

$error = '';
$username_val = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $username_val = $username;

    $auth = new AuthManager($pdo);
    $res = $auth->attempt($username, $password);

    if ($res['success']) {
        header("Location: index.php");
        exit;
    } else {
        $error = $res['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?= APP_NAME ?></title>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>assets/images/wts_logo.png">
    <link rel="shortcut icon" type="image/png" href="<?= BASE_URL ?>assets/images/wts_logo.png">
    <link rel="apple-touch-icon" href="<?= BASE_URL ?>assets/images/wts_logo.png">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/fonts/inter.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/tabler-icons/tabler-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css?v=<?= time() ?>">
</head>
<body class="login-page">

    <div class="login-card">
        <!-- Vatsalya Hospital Brand Header -->
        <div class="text-center mb-3">
            <img src="<?= BASE_URL ?>assets/images/vatsalya_logo.png?v=<?= time() ?>" alt="Vatsalya Logo" class="mb-2" style="height: 72px; object-fit: contain;">
            <h4 class="mt-1 mb-0"><?= APP_NAME ?></h4>
            <small class="text-muted">Sign in to your clinical dispensing account</small>
        </div>

        <?php if (!empty($_GET['logged_out'])): ?>
            <div class="alert alert-info py-2 px-3 small d-flex align-items-center gap-2 mb-3">
                <i class="ti ti-info-circle fs-5"></i> You have been successfully signed out.
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2 px-3 small d-flex align-items-center gap-2 mb-3">
                <i class="ti ti-alert-circle fs-5"></i> <?= sanitize($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            
            <div class="mb-3 text-start">
                <label class="form-label" for="username">Username</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0"><i class="ti ti-user text-muted"></i></span>
                    <input type="text" id="username" name="username" class="form-control border-start-0" value="<?= sanitize($username_val) ?>" required autofocus placeholder="Enter staff username">
                </div>
            </div>

            <div class="mb-3 text-start">
                <label class="form-label" for="password">Password</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0"><i class="ti ti-lock text-muted"></i></span>
                    <input type="password" id="password" name="password" class="form-control border-start-0" required placeholder="Enter password">
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 mb-3" style="font-weight: 700;">
                <i class="ti ti-login me-1.5"></i> Sign In
            </button>
        </form>

        <!-- Quick Credentials Switcher -->
        <div class="mt-2 pt-2.5 border-top text-start">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="small fw-bold text-uppercase text-muted" style="font-size: 0.70rem; letter-spacing: 0.05em;">Quick Login:</span>
                <span class="badge bg-light text-muted border" style="font-size: 0.68rem;">Default: admin@123</span>
            </div>
            <div class="d-grid gap-1.5" style="gap: 6px;">
                <button type="button" class="btn btn-sm btn-outline-secondary text-start d-flex justify-content-between align-items-center py-1.5 px-2.5 rounded-2" onclick="quickLogin('admin', 'admin@123')" style="font-size: 0.80rem;">
                    <div>
                        <span class="badge bg-danger bg-opacity-10 text-danger me-1">Admin</span>
                        <strong class="text-dark">admin</strong>
                    </div>
                    <span class="text-muted small">&rarr;</span>
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary text-start d-flex justify-content-between align-items-center py-1.5 px-2.5 rounded-2" onclick="quickLogin('pharmacist', 'admin@123')" style="font-size: 0.80rem;">
                    <div>
                        <span class="badge bg-primary bg-opacity-10 text-primary me-1">Pharmacist</span>
                        <strong class="text-dark">pharmacist</strong>
                    </div>
                    <span class="text-muted small">&rarr;</span>
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary text-start d-flex justify-content-between align-items-center py-1.5 px-2.5 rounded-2" onclick="quickLogin('cashier', 'admin@123')" style="font-size: 0.80rem;">
                    <div>
                        <span class="badge bg-success bg-opacity-10 text-success me-1">Cashier</span>
                        <strong class="text-dark">cashier</strong>
                    </div>
                    <span class="text-muted small">&rarr;</span>
                </button>
            </div>
        </div>

        <script>
            function quickLogin(u, p) {
                document.getElementById('username').value = u;
                document.getElementById('password').value = p;
                document.getElementById('username').focus();
            }
        </script>

        <!-- WTS Company Branding Section (MediPro Exact Style) -->
        <div style="margin-top: 25px; padding-top: 18px; border-top: 1px dashed rgba(0,0,0,0.12);">
            <div class="text-center" style="font-family: 'Inter', sans-serif; line-height: 1.5; font-size: 11px; color: #6c757d;">
                <span>Developed &amp; Maintained by <a href="https://www.wtsindia.co.in/" target="_blank" rel="noopener noreferrer" style="color: var(--primary, #14b8a6); text-decoration: none; font-weight: 600;">WTS</a> &copy; 2026 All rights reserved.</span>
                <div class="mt-2.5">
                    <img src="<?= BASE_URL ?>assets/images/wts_logo.png" alt="WTS" style="height: 38px; object-fit: contain;">
                </div>
            </div>
        </div>
    </div>

    <script src="<?= BASE_URL ?>assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="<?= BASE_URL ?>assets/js/animations.js"></script>
</body>
</html>
