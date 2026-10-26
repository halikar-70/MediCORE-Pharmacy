<?php
// includes/navbar.php - Pharmacy Top Navigation Bar (MediPro Aesthetic)
$user = auth_user();
$currentUri = $_SERVER['PHP_SELF'] ?? '';

$ipdQueueCount = 0;
try {
    if (isset($pdo)) {
        $ipdQueueCount = (int)$pdo->query("SELECT COUNT(*) FROM pharmacy_prescriptions WHERE status IN ('Pending', 'Partial')")->fetchColumn();
    }
} catch (Exception $e) {}
?>
<header class="topbar">
    <div class="topbar-hero d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-2.5 w-100">
        <div class="topbar-hero-left d-flex align-items-center gap-2.5 min-w-0">
            <!-- Mobile & Tablet Sidebar Hamburger Toggle -->
            <button class="btn btn-icon btn-light d-lg-none me-1 flex-shrink-0" id="sidebarToggleBtn" type="button" aria-label="Toggle Navigation Sidebar">
                <i class="ti ti-menu-2 fs-5"></i>
            </button>
            <div class="topbar-hero-text min-w-0">
                <div class="topbar-label">
                    <i class="ti ti-pill"></i> Pharmacy ERP
                </div>
                <div class="topbar-title text-truncate"><?= isset($page_title) ? sanitize($page_title) : 'Dashboard' ?></div>
                <div class="topbar-description d-none d-md-block text-truncate">Welcome back, <?= sanitize($user['full_name'] ?? $user['username'] ?? 'User') ?>. Keep all dispensing, inventory, and clinical sales in sync.</div>
            </div>
            <!-- Mobile brand identifier -->
            <div class="d-lg-none fw-bold text-primary fs-6 ms-auto">Vatsalya</div>
        </div>

        <!-- Right Side Actions & User Menu - Perfect Single-Row Alignment -->
        <div class="topbar-hero-actions d-flex align-items-center gap-2 flex-shrink-0 ms-lg-auto">
            <div class="topbar-pill d-none d-xl-inline-flex align-items-center font-monospace">
                <i class="ti ti-calendar me-1.5 text-primary"></i>
                <span><?= date('D, d M Y') ?></span>
            </div>

            <a href="<?= BASE_URL ?>modules/patients/search.php" class="btn btn-outline-primary btn-sm text-nowrap d-none d-sm-inline-flex align-items-center rounded-pill fw-semibold">
                <i class="ti ti-search me-1.5"></i>Search Patient
            </a>
            <a href="<?= BASE_URL ?>modules/patients/register.php" class="btn btn-primary btn-sm text-nowrap d-inline-flex align-items-center rounded-pill fw-semibold shadow-2xs">
                <i class="ti ti-user-plus me-1.5"></i>New Patient
            </a>

            <!-- Profile Dropdown (MediPro Pill Style) -->
            <div class="dropdown">
                <a class="d-flex align-items-center text-decoration-none dropdown-toggle user-menu rounded-pill border bg-white shadow-2xs" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="gap: 7px;">
                    <span class="user-avatar p-0 overflow-hidden" style="width:28px; height:28px; border-radius:50%; background: linear-gradient(135deg, var(--primary), #0d9488); display:inline-flex; align-items:center; justify-content:center; color:#ffffff; font-weight:700; font-size: 0.82rem; box-shadow: 0 2px 5px rgba(20, 184, 166, 0.25);">
                        <?= strtoupper(substr($user['username'] ?? 'A', 0, 1)) ?>
                    </span>
                    <span class="user-name d-none d-md-inline fw-semibold text-dark small"><?= sanitize($user['username'] ?? 'User') ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2 mt-2" style="min-width: 220px; border-radius: 12px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.06) !important;">
                    <li class="px-3 py-2 border-bottom mb-1 bg-light rounded-3">
                        <div class="fw-bold text-dark text-truncate" style="font-size: 0.88rem;">
                            <?= sanitize($user['full_name'] ?? $user['username'] ?? 'Staff') ?>
                        </div>
                        <div class="d-flex align-items-center justify-content-between mt-1">
                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25" style="font-size: 0.7rem; font-weight: 600;">
                                <?= sanitize($user['role_name'] ?? 'Staff') ?>
                            </span>
                            <span class="text-success small d-inline-flex align-items-center" style="font-size: 0.7rem;">
                                <span class="rounded-circle bg-success me-1" style="width: 6px; height: 6px; display: inline-block;"></span> Active
                            </span>
                        </div>
                    </li>
                    <li>
                        <a class="dropdown-item rounded-2 py-1.5 small d-flex align-items-center gap-2" href="<?= BASE_URL ?>modules/dashboard/index.php">
                            <i class="ti ti-layout-dashboard text-primary"></i> Operations Dashboard
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item rounded-2 py-1.5 small d-flex align-items-center gap-2" href="<?= BASE_URL ?>modules/admin/settings.php">
                            <i class="ti ti-settings text-muted"></i> Pharmacy Settings
                        </a>
                    </li>
                    <li><hr class="dropdown-divider my-1"></li>
                    <li>
                        <a class="dropdown-item rounded-2 py-2 d-flex align-items-center text-danger fw-semibold" href="<?= BASE_URL ?>logout.php" style="gap: 6px; font-size: 0.85rem;">
                            <i class="ti ti-power text-danger fs-6"></i>
                            <span>Sign Out</span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <!-- MediPro Secondary Workflow Tabs Row -->
    <div class="topbar-row d-flex align-items-center justify-content-between gap-2 mt-2.5 flex-wrap">
        <div class="topbar-center d-flex align-items-center gap-1.5 flex-wrap">
            <a href="<?= BASE_URL ?>modules/sales/counter.php" class="topbar-tab <?= (stripos($currentUri, 'sales/counter') !== false) ? 'active' : '' ?>">
                <i class="ti ti-bolt text-warning me-1"></i>POS Counter Sale
            </a>
            <a href="<?= BASE_URL ?>modules/sales/regular.php" class="topbar-tab <?= (stripos($currentUri, 'sales/regular') !== false) ? 'active' : '' ?>">
                <i class="ti ti-receipt-2 me-1"></i>Create IPD Bill
            </a>
            <a href="<?= BASE_URL ?>modules/sales/monitoring.php" class="topbar-tab <?= (stripos($currentUri, 'sales/monitoring') !== false) ? 'active' : '' ?>">
                <i class="ti ti-chart-line me-1"></i>Sale Monitoring
            </a>
            <a href="<?= BASE_URL ?>modules/purchases/invoices.php" class="topbar-tab <?= (stripos($currentUri, 'purchases/') !== false) ? 'active' : '' ?>">
                <i class="ti ti-truck-delivery me-1"></i>Procurement
            </a>
            <a href="<?= BASE_URL ?>modules/inventory/products.php" class="topbar-tab <?= (stripos($currentUri, 'inventory/products') !== false || stripos($currentUri, 'inventory/profile') !== false) ? 'active' : '' ?>">
                <i class="ti ti-pill me-1"></i>Product Master
            </a>
            <a href="<?= BASE_URL ?>modules/inventory/batch_stock.php" class="topbar-tab <?= (stripos($currentUri, 'inventory/batch_stock') !== false) ? 'active' : '' ?>">
                <i class="ti ti-packages me-1"></i>Batch Stock
            </a>
        </div>
        <div class="ms-auto d-flex align-items-center gap-2 flex-wrap text-nowrap">
            <a href="<?= BASE_URL ?>modules/ipd/queue.php" class="topbar-tab <?= (stripos($currentUri, 'ipd/queue') !== false) ? 'active' : '' ?>" style="display: inline-flex; align-items: center; gap: 5px; color: #ef4444 !important; font-weight: 700;">
                <span class="rounded-circle bg-danger text-white d-inline-flex align-items-center justify-content-center shadow-2xs" style="width: 17px; height: 17px; font-size: 0.7rem; font-weight: 900; line-height: 1;">!</span>
                <span>IPD Queue</span>
                <?php if ($ipdQueueCount > 0): ?>
                    <span class="badge rounded-pill bg-danger text-white ms-1" style="font-size: 0.68rem;"><?= $ipdQueueCount ?></span>
                <?php endif; ?>
            </a>
            <a href="<?= BASE_URL ?>modules/sales/counter.php" class="btn btn-sm btn-primary rounded-pill px-3 py-1 shadow-2xs d-inline-flex align-items-center fw-semibold" style="font-size: 0.8rem; gap: 5px;">
                <i class="ti ti-plus"></i>
                <span>Quick POS</span>
            </a>
            <a href="<?= BASE_URL ?>modules/dashboard/index.php" class="topbar-tab <?= (stripos($currentUri, 'dashboard/') !== false) ? 'active' : '' ?>">Directives</a>
        </div>
    </div>
</header>
<main class="main-content">
