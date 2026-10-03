<?php
// index.php - Pharmacy Main Router Entrypoint

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/session.php';

// Redirect to dashboard
header("Location: " . BASE_URL . "modules/dashboard/index.php");
exit;
