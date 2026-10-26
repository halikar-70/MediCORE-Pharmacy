<?php
// includes/header.php - Pharmacy Standalone Layout Header
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($page_title) ? sanitize($page_title) . ' — ' . APP_NAME : APP_NAME ?></title>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>assets/images/wts_logo.png">
    <link rel="shortcut icon" type="image/png" href="<?= BASE_URL ?>assets/images/wts_logo.png">
    <link rel="apple-touch-icon" href="<?= BASE_URL ?>assets/images/wts_logo.png">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/fonts/inter.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/tabler-icons/tabler-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/dashboard.css?v=<?= time() ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/loading.css?v=<?= time() ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/animations.css?v=<?= time() ?>">
    <script src="<?= BASE_URL ?>assets/vendor/chartjs/chart.umd.min.js" defer onerror="this.onerror=null;this.src='https://cdn.jsdelivr.net/npm/chart.js';"></script>
</head>
<body class="app-body">
<div class="app-wrapper d-flex">
