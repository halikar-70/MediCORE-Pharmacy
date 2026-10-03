<?php
// modules/sales/prescription.php - Prescription Module Disabled / Redirected
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/session.php';

header("Location: " . BASE_URL . "modules/sales/counter.php");
exit;