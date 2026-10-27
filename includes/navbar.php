<?php
// includes/navbar.php - Pharmacy Top Navigation Bar (MediPro Aesthetic)
$user = auth_user();
$currentUri = $_SERVER['PHP_SELF'] ?? '';

$ipdQueueCount = 0;
$todaySalesCount = 0;
$stockAlertCount = 0;
if (empty($hide_navbar)) {
    try {
        if (isset($pdo)) {
            $ipdQueueCount = (int)$pdo->query("SELECT COUNT(*) FROM pharmacy_prescriptions WHERE status IN ('Pending', 'Partial')")->fetchColumn();
            $todaySalesCount = (int)$pdo->query("SELECT COUNT(*) FROM pharmacy_sales WHERE sale_date = CURDATE() AND status != 'CANCELLED'")->fetchColumn();
            $stockAlertCount = (int)$pdo->query("SELECT COUNT(*) FROM medicines WHERE status = 'Active' AND stock_quantity <= reorder_level")->fetchColumn();
        }
    } catch (Exception $e) {}
}
?>
<?php if (empty($hide_navbar)): ?>
<header class="topbar">
    <div class="topbar-hero d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3 w-100">
        <!-- Left: Brand, Module Title, and User Subtitle -->
        <div class="topbar-hero-left d-flex align-items-center gap-3 min-w-0">
            <!-- Mobile & Tablet Sidebar Hamburger Toggle -->
            <button class="btn btn-icon btn-light d-lg-none me-1 flex-shrink-0" id="sidebarToggleBtn" type="button" aria-label="Toggle Navigation Sidebar">
                <i class="ti ti-menu-2 fs-5"></i>
            </button>
            <div class="topbar-hero-text">
                <div class="d-flex align-items-center gap-2 mb-1.5 flex-wrap">
                    <span class="topbar-label"><i class="ti ti-pill"></i> Pharmacy ERP</span>
                    <span class="badge bg-emerald-subtle text-emerald border border-emerald-subtle rounded-pill px-2.5 py-1 text-nowrap d-none d-sm-inline-flex align-items-center gap-1" style="font-size: 0.72rem; font-weight: 600; line-height: 1.3;">
                        <i class="ti ti-building-hospital"></i> Vatsalya Central Pharmacy
                    </span>
                    <span class="badge bg-light text-secondary border rounded-pill px-2.5 py-1 text-nowrap d-none d-md-inline-flex align-items-center gap-1.5" style="font-size: 0.72rem; font-weight: 600; line-height: 1.3;">
                        <span class="rounded-circle bg-success" style="width: 6px; height: 6px; display: inline-block;"></span> POS Terminal #1
                    </span>
                </div>
                <h4 class="topbar-title fw-bold text-dark"><?= !empty($page_title) ? sanitize($page_title) : 'Operations Dashboard' ?></h4>
                <div class="topbar-description text-muted small mt-1 d-none d-md-flex align-items-center gap-1.5">
                    <i class="ti ti-user-check text-primary me-0.5"></i>Welcome back, <strong class="text-dark"><?= sanitize($user['full_name'] ?? $user['username'] ?? 'User') ?></strong> &bull; <span class="badge bg-light text-secondary border px-2 py-1 rounded-pill font-monospace" style="font-size: 0.68rem; line-height: 1.25;"><?= sanitize($user['role_name'] ?? 'Staff') ?></span>
                </div>
            </div>
            <!-- Mobile brand identifier -->
            <div class="d-lg-none fw-bold text-primary fs-6 ms-auto flex-shrink-0">Vatsalya</div>
        </div>

        <!-- Center: Live Operational KPI Chips (Fills the wide middle space with real-time value!) -->
        <div class="topbar-hero-center d-none d-xl-flex align-items-center gap-2.5 mx-auto">
            <a href="<?= BASE_URL ?>modules/ipd/queue.php" class="topbar-kpi-pill d-flex align-items-center gap-2">
                <div class="topbar-kpi-icon bg-danger-subtle text-danger">
                    <i class="ti ti-bell-ringing fs-5"></i>
                </div>
                <div class="d-flex flex-column text-start" style="line-height: 1.25; gap: 2px;">
                    <span class="text-uppercase text-danger fw-bold" style="font-size: 0.63rem; letter-spacing: 0.5px; line-height: 1.2;">IPD Orders</span>
                    <span class="fw-bold text-dark font-monospace" style="font-size: 0.85rem; line-height: 1.25;"><?= $ipdQueueCount ?> Pending</span>
                </div>
            </a>

            <a href="<?= BASE_URL ?>modules/sales/monitoring.php" class="topbar-kpi-pill d-flex align-items-center gap-2">
                <div class="topbar-kpi-icon bg-primary-subtle text-primary">
                    <i class="ti ti-receipt-2 fs-5"></i>
                </div>
                <div class="d-flex flex-column text-start" style="line-height: 1.25; gap: 2px;">
                    <span class="text-uppercase text-primary fw-bold" style="font-size: 0.63rem; letter-spacing: 0.5px; line-height: 1.2;">Today's Sales</span>
                    <span class="fw-bold text-dark font-monospace" style="font-size: 0.85rem; line-height: 1.25;"><?= $todaySalesCount ?> Invoices</span>
                </div>
            </a>

            <a href="<?= BASE_URL ?>modules/inventory/products.php?filter=low_stock" class="topbar-kpi-pill d-none d-xxl-flex align-items-center gap-2">
                <div class="topbar-kpi-icon bg-warning-subtle text-warning">
                    <i class="ti ti-alert-triangle fs-5"></i>
                </div>
                <div class="d-flex flex-column text-start" style="line-height: 1.25; gap: 2px;">
                    <span class="text-uppercase text-warning fw-bold" style="font-size: 0.63rem; letter-spacing: 0.5px; line-height: 1.2;">Stock Alerts</span>
                    <span class="fw-bold text-dark font-monospace" style="font-size: 0.85rem; line-height: 1.25;"><?= $stockAlertCount ?> Reorder</span>
                </div>
            </a>
        </div>

        <!-- Right Side Actions & User Menu -->
        <div class="topbar-hero-actions d-flex align-items-center gap-2 flex-shrink-0 ms-lg-auto">
            <div class="topbar-pill d-none d-md-inline-flex align-items-center font-monospace bg-light border px-3 rounded-pill shadow-2xs">
                <i class="ti ti-calendar me-1.5 text-primary"></i>
                <span><?= date('D, d M Y') ?></span>
            </div>

            <a href="<?= BASE_URL ?>modules/patients/search.php" class="btn btn-outline-primary btn-sm text-nowrap d-none d-sm-inline-flex align-items-center rounded-pill fw-semibold shadow-2xs hover-lift" style="height: 38px; padding: 0 0.95rem; line-height: 1.3;">
                <i class="ti ti-search me-1.5"></i>Search Patient
            </a>
            <a href="<?= BASE_URL ?>modules/patients/register.php" class="btn btn-primary btn-sm text-nowrap d-inline-flex align-items-center rounded-pill fw-semibold shadow-2xs hover-lift" style="height: 38px; padding: 0 1.05rem; line-height: 1.3; background: linear-gradient(135deg, #0d9488, #14b8a6); border: none;">
                <i class="ti ti-user-plus me-1.5"></i>New Patient
            </a>

            <!-- Profile Dropdown (MediPro Pill Style) -->
            <div class="dropdown">
                <a class="d-flex align-items-center text-decoration-none dropdown-toggle user-menu rounded-pill border bg-white shadow-2xs" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="gap: 8px; height: 38px; padding: 0 0.85rem 0 0.35rem;">
                    <span class="user-avatar p-0 overflow-hidden" style="width:30px; height:30px; border-radius:50%; background: linear-gradient(135deg, var(--primary), #0d9488); display:inline-flex; align-items:center; justify-content:center; color:#ffffff; font-weight:700; font-size: 0.82rem; box-shadow: 0 2px 5px rgba(20, 184, 166, 0.25);">
                        <?= strtoupper(substr($user['username'] ?? 'A', 0, 1)) ?>
                    </span>
                    <span class="user-name d-none d-md-inline fw-semibold text-dark small" style="line-height: 1.3;"><?= sanitize($user['username'] ?? 'User') ?></span>
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
    <div class="topbar-row d-flex align-items-center justify-content-between gap-2 flex-wrap">
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
                <span class="rounded-circle bg-danger text-white d-inline-flex align-items-center justify-content-center shadow-2xs" style="width: 18px; height: 18px; font-size: 0.7rem; font-weight: 900; line-height: 1;">!</span>
                <span>IPD Queue</span>
                <?php if ($ipdQueueCount > 0): ?>
                    <span class="badge rounded-pill bg-danger text-white ms-1 px-1.5 py-0.5" style="font-size: 0.68rem; line-height: 1.2;"><?= $ipdQueueCount ?></span>
                <?php endif; ?>
            </a>
            <a href="<?= BASE_URL ?>modules/sales/counter.php" class="btn btn-sm btn-primary rounded-pill px-3 shadow-2xs d-inline-flex align-items-center fw-semibold" style="font-size: 0.8rem; gap: 6px; height: 34px; line-height: 1.3; background: linear-gradient(135deg, #0d9488, #14b8a6); border: none;">
                <i class="ti ti-plus"></i>
                <span>Quick POS</span>
            </a>
            <a href="<?= BASE_URL ?>modules/dashboard/index.php" class="topbar-tab <?= (stripos($currentUri, 'dashboard/') !== false) ? 'active' : '' ?>">Directives</a>
        </div>
    </div>
</header>
<?php endif; ?>
<main class="main-content">
