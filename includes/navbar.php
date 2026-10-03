<?php
// includes/navbar.php - Pharmacy Top Navigation Bar
$user = auth_user();
?>
<header class="top-navbar">
    <div class="d-flex align-items-center gap-2.5">
        <!-- Mobile & Tablet Sidebar Hamburger Toggle -->
        <button class="btn btn-sm btn-light d-lg-none border-0 p-1.5" type="button" id="sidebarToggleBtn" aria-label="Toggle Navigation Sidebar">
            <i class="ti ti-menu-2 fs-4 text-dark"></i>
        </button>

        <!-- Breadcrumb & Context Title -->
        <div class="d-flex align-items-center gap-2">
            <span class="text-muted small fw-medium d-none d-md-inline">Pharmacy</span>
            <i class="ti ti-chevron-right text-muted small d-none d-md-inline" style="font-size: 0.7rem;"></i>
            <h1 class="h6 mb-0 fw-bold text-dark lh-1">
                <?= isset($page_title) ? sanitize($page_title) : 'Dashboard' ?>
            </h1>
        </div>
    </div>

    <!-- Right Side Actions & User Menu -->
    <div class="d-flex align-items-center gap-2">
        <span class="text-muted small d-none d-xl-inline me-2 font-monospace" style="font-size: 0.78rem;">
            <i class="ti ti-calendar-event me-1"></i><?= date('D, d-M-Y') ?>
        </span>

        <a href="<?= BASE_URL ?>modules/patients/search.php" class="btn btn-sm btn-outline-secondary d-none d-sm-inline-flex align-items-center gap-1.5" title="Search Patients">
            <i class="ti ti-search"></i>
            <span class="d-none d-md-inline">Search</span>
        </a>
        <a href="<?= BASE_URL ?>modules/patients/register.php" class="btn btn-sm btn-primary d-inline-flex align-items-center gap-1.5" title="Register New Patient">
            <i class="ti ti-user-plus"></i>
            <span>Register</span>
        </a>

        <!-- User Dropdown Menu -->
        <div class="dropdown ms-1">
            <button class="btn btn-sm btn-light border d-flex align-items-center gap-1.5 py-1 px-2 rounded-pill" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <div class="bg-emerald text-white rounded-circle d-flex align-items-center justify-content-center fw-bold" style="width: 26px; height: 26px; font-size: 0.75rem;">
                    <?= strtoupper(substr($user['username'] ?? 'P', 0, 1)) ?>
                </div>
                <span class="fw-semibold text-dark small d-none d-sm-inline"><?= sanitize($user['username'] ?? 'User') ?></span>
                <i class="ti ti-chevron-down text-muted" style="font-size: 0.7rem;"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end border shadow-sm mt-1.5" style="min-width: 190px;">
                <li class="px-3 py-2 border-bottom">
                    <div class="fw-bold small text-dark"><?= sanitize($user['full_name'] ?? 'Staff') ?></div>
                    <div class="text-muted small" style="font-size: 0.72rem;"><?= sanitize($user['role_name'] ?? 'Role') ?></div>
                </li>
                <li><a class="dropdown-item py-1.5 small" href="<?= BASE_URL ?>modules/dashboard/index.php"><i class="ti ti-layout-dashboard me-1.5"></i> Dashboard</a></li>
                <li><a class="dropdown-item py-1.5 small" href="<?= BASE_URL ?>modules/admin/settings.php"><i class="ti ti-settings me-1.5"></i> Settings</a></li>
                <li><hr class="dropdown-divider my-1"></li>
                <li><a class="dropdown-item py-1.5 small text-danger fw-semibold" href="<?= BASE_URL ?>logout.php"><i class="ti ti-power me-1.5"></i> Sign Out</a></li>
            </ul>
        </div>
    </div>
</header>
<main class="main-content">
