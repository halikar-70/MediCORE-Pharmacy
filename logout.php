<?php
// logout.php - Pharmacy Sign Out

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/app/Auth/AuthManager.php';

use Pharmacy\Auth\AuthManager;

$auth = new AuthManager($pdo);
$auth->logout();

header("Location: " . BASE_URL . "login.php?logged_out=1");
exit;
