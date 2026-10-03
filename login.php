<?php
// login.php - Dedicated Pharmacy Management System Authentication
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
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>assets/images/favicon.png">
    <link rel="shortcut icon" href="<?= BASE_URL ?>favicon.ico">
    <link rel="apple-touch-icon" href="<?= BASE_URL ?>assets/images/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
    <style>
        body {
            background-color: #f1f5f9;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        .login-wrapper {
            width: 100%;
            max-width: 400px;
        }

        .login-card {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 2.25rem 2rem 1.75rem 2rem;
            box-shadow: var(--shadow-md);
        }

        .login-logo-wrap {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.25rem;
        }

        .login-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-primary);
            letter-spacing: -0.02em;
            margin-bottom: 0.25rem;
        }

        .login-subtitle {
            font-size: 0.84rem;
            color: var(--text-muted);
            margin-bottom: 1.5rem;
        }

        .branding-footer {
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
            text-align: center;
            font-size: 0.72rem;
            color: var(--text-muted);
        }

        .branding-footer a {
            color: var(--info);
            text-decoration: none;
            font-weight: 600;
        }
    </style>
</head>
<body>

<div class="login-wrapper">
    <div class="login-card">
        <!-- Vatsalya Hospital Logo -->
        <div class="login-logo-wrap">
            <img src="<?= BASE_URL ?>assets/images/vatsalya_logo.png" alt="Vatsalya Hospital Logo" style="max-height: 75px; max-width: 200px; object-fit: contain;">
        </div>

        <div class="text-center">
            <h1 class="login-title">Pharmacy Management</h1>
            <p class="login-subtitle">Sign in to your clinical account</p>
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
                    <span class="input-group-text"><i class="ti ti-user"></i></span>
                    <input type="text" id="username" name="username" class="form-control" value="<?= sanitize($username_val) ?>" required autofocus placeholder="Enter staff username">
                </div>
            </div>

            <div class="mb-4 text-start">
                <label class="form-label" for="password">Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="ti ti-lock"></i></span>
                    <input type="password" id="password" name="password" class="form-control" required placeholder="Enter password">
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 btn-lg">
                <i class="ti ti-login me-1"></i> Sign In
            </button>
        </form>

        <div class="branding-footer">
            <div>
                Developed &amp; Maintained by <a href="https://www.wtsindia.co.in/" target="_blank" rel="noopener noreferrer">WTS</a> &copy; 2026
            </div>
        </div>
    </div>
</div>

</body>
</html>
