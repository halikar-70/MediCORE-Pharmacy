<?php
// includes/sidebar.php - Modular Clinical Collapsible Navigation Shell

$currentUri = $_SERVER['PHP_SELF'] ?? '';
$user = auth_user();

if (!function_exists('is_nav_active')) {
    function is_nav_active($needle, $uri): string {
        return (stripos($uri, $needle) !== false) ? 'active' : '';
    }
}

if (!function_exists('is_group_active')) {
    function is_group_active(array $needles, string $uri): bool {
        foreach ($needles as $n) {
            if (stripos($uri, $n) !== false) {
                return true;
            }
        }
        return false;
    }
}

// Group active checks
$salesActive = is_group_active(['modules/sales/'], $currentUri);
$purchasesActive = is_group_active(['modules/purchases/'], $currentUri);
$inventoryActive = is_group_active(['modules/inventory/'], $currentUri);
$patientsIpdActive = is_group_active(['modules/patients/', 'modules/ipd/'], $currentUri);
$reportsActive = is_group_active(['modules/reports/'], $currentUri);
$adminActive = is_group_active(['modules/admin/'], $currentUri);
?>
<!-- Mobile Overlay Backdrop -->
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<aside class="sidebar" id="appSidebar">
    <!-- Brand Header & Mobile Close -->
    <div class="sidebar-brand text-center position-relative py-3 px-3">
        <button type="button" class="btn-close d-lg-none position-absolute top-0 end-0 m-2.5 p-1" id="sidebarCloseBtn" aria-label="Close Navigation"></button>
        <a href="<?= BASE_URL ?>modules/dashboard/index.php" class="d-flex flex-column align-items-center text-decoration-none w-100">
            <div class="mb-1.5 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                <img src="<?= BASE_URL ?>assets/images/vatsalya_icon.png" alt="Vatsalya Logo" style="width: 44px; height: 44px; object-fit: contain;">
            </div>
            <div class="w-100 text-center">
                <div class="fw-bold text-dark lh-sm" style="font-size: 1rem; letter-spacing: -0.2px;">Vatsalya Hospital</div>
                <div class="text-emerald fw-semibold small" style="font-size: 0.72rem; letter-spacing: 0.3px;">Pharmacy Operations</div>
            </div>
        </a>
    </div>

    <!-- Active User Profile Chip -->
    <div class="mx-2.5 my-2 rounded-2 border d-flex align-items-center gap-2 p-2 bg-light">
        <div class="bg-emerald text-white rounded-circle fw-bold d-flex align-items-center justify-content-center flex-shrink-0" style="width: 30px; height: 30px; font-size: 0.8rem;">
            <?= strtoupper(substr($user['username'] ?? 'P', 0, 1)) ?>
        </div>
        <div class="d-flex flex-column justify-content-center flex-grow-1 overflow-hidden" style="min-width: 0;">
            <div class="fw-bold text-dark text-truncate lh-sm" style="font-size: 0.8rem;" title="<?= sanitize($user['full_name'] ?? 'Staff') ?>">
                <?= sanitize($user['full_name'] ?? 'Staff') ?>
            </div>
            <div class="mt-0.5 d-flex align-items-center gap-1">
                <span class="badge bg-emerald-subtle text-emerald py-0 px-1" style="font-size: 0.65rem; font-weight: 600;">
                    <i class="ti ti-shield-check me-0.5"></i><?= sanitize($user['role_name'] ?? 'Pharmacist') ?>
                </span>
            </div>
        </div>
        <a href="<?= BASE_URL ?>logout.php" class="btn btn-sm btn-light text-danger border-0 p-0 d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 26px; height: 26px;" title="Sign Out">
            <i class="ti ti-logout fs-5"></i>
        </a>
    </div>

    <!-- Navigation List with Collapsible Groups -->
    <nav class="sidebar-nav">
        <!-- 1. Standalone Dashboard -->
        <a class="nav-link <?= is_nav_active('modules/dashboard', $currentUri) ?> mb-1" href="<?= BASE_URL ?>modules/dashboard/index.php">
            <i class="ti ti-layout-dashboard"></i>
            <span>Dashboard</span>
        </a>

        <!-- 2. SALES (Collapsible) -->
        <div class="sidebar-group">
            <button class="sidebar-group-header <?= $salesActive ? 'active-group open' : '' ?>" type="button" data-group="groupSales" aria-expanded="<?= $salesActive ? 'true' : 'false' ?>">
                <div class="group-title-wrap">
                    <i class="ti ti-shopping-cart"></i>
                    <span>Sales</span>
                </div>
                <i class="ti ti-chevron-down group-chevron"></i>
            </button>
            <div class="sidebar-submenu <?= $salesActive ? 'show' : '' ?>" id="groupSales">
                <a class="nav-link <?= is_nav_active('modules/sales/counter.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/sales/counter.php">
                    <span>OPD Sales</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/sales/regular.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/sales/regular.php">
                    <span>Regular / IPD Sale</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/sales/monitoring.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/sales/monitoring.php">
                    <span>Sale Monitoring</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/sales/returns.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/sales/returns.php">
                    <span>Sale Returns</span>
                </a>
            </div>
        </div>

        <!-- 3. PURCHASE (Collapsible) -->
        <div class="sidebar-group">
            <button class="sidebar-group-header <?= $purchasesActive ? 'active-group open' : '' ?>" type="button" data-group="groupPurchases" aria-expanded="<?= $purchasesActive ? 'true' : 'false' ?>">
                <div class="group-title-wrap">
                    <i class="ti ti-truck-loading"></i>
                    <span>Purchase</span>
                </div>
                <i class="ti ti-chevron-down group-chevron"></i>
            </button>
            <div class="sidebar-submenu <?= $purchasesActive ? 'show' : '' ?>" id="groupPurchases">
                <a class="nav-link <?= is_nav_active('modules/purchases/suppliers.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/purchases/suppliers.php">
                    <span>Suppliers</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/purchases/orders.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/purchases/orders.php">
                    <span>Purchase Orders</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/purchases/grn.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/purchases/grn.php">
                    <span>Goods Received (GRN)</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/purchases/invoices.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/purchases/invoices.php">
                    <span>Purchase Invoices</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/purchases/payments.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/purchases/payments.php">
                    <span>Supplier Payments</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/purchases/outstanding.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/purchases/outstanding.php">
                    <span>Supplier Outstanding</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/purchases/ledger.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/purchases/ledger.php">
                    <span>Supplier Ledger</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/purchases/returns.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/purchases/returns.php">
                    <span>Purchase Returns</span>
                </a>
            </div>
        </div>

        <!-- 4. INVENTORY (Collapsible) -->
        <div class="sidebar-group">
            <button class="sidebar-group-header <?= $inventoryActive ? 'active-group open' : '' ?>" type="button" data-group="groupInventory" aria-expanded="<?= $inventoryActive ? 'true' : 'false' ?>">
                <div class="group-title-wrap">
                    <i class="ti ti-packages"></i>
                    <span>Inventory</span>
                </div>
                <i class="ti ti-chevron-down group-chevron"></i>
            </button>
            <div class="sidebar-submenu <?= $inventoryActive ? 'show' : '' ?>" id="groupInventory">
                <a class="nav-link <?= is_nav_active('modules/inventory/products.php', $currentUri) || is_nav_active('modules/inventory/add.php', $currentUri) || is_nav_active('modules/inventory/edit.php', $currentUri) || is_nav_active('modules/inventory/profile.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/inventory/products.php">
                    <span>Product Master</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/inventory/batch_stock.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/inventory/batch_stock.php">
                    <span>Batch Stock</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/inventory/ledger.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/inventory/ledger.php">
                    <span>Stock Ledger</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/inventory/expiry.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/inventory/expiry.php">
                    <span>Expiry Management</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/inventory/quarantine.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/inventory/quarantine.php">
                    <span>Quarantine</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/inventory/disposals.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/inventory/disposals.php">
                    <span>Disposal Register</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/inventory/adjustments.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/inventory/adjustments.php">
                    <span>Stock Adjustment</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/inventory/opening_stock.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/inventory/opening_stock.php">
                    <span>Opening Stock</span>
                </a>
            </div>
        </div>

        <!-- 5. PATIENTS & CLINICAL (Collapsible) -->
        <div class="sidebar-group">
            <button class="sidebar-group-header <?= $patientsIpdActive ? 'active-group open' : '' ?>" type="button" data-group="groupClinical" aria-expanded="<?= $patientsIpdActive ? 'true' : 'false' ?>">
                <div class="group-title-wrap">
                    <i class="ti ti-stethoscope"></i>
                    <span>Patients &amp; IPD</span>
                </div>
                <i class="ti ti-chevron-down group-chevron"></i>
            </button>
            <div class="sidebar-submenu <?= $patientsIpdActive ? 'show' : '' ?>" id="groupClinical">
                <a class="nav-link <?= is_nav_active('modules/patients/search.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/patients/search.php">
                    <span>Search Patient</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/patients/list.php', $currentUri) || is_nav_active('modules/patients/register.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/patients/list.php">
                    <span>Pharmacy Patients</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/patients/history.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/patients/history.php">
                    <span>Patient History</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/ipd/queue.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/ipd/queue.php">
                    <span>IPD Queue</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/ipd/indents.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/ipd/indents.php">
                    <span>IPD Indents</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/ipd/dispensing.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/ipd/dispensing.php">
                    <span>IPD Dispensing</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/ipd/mar.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/ipd/mar.php">
                    <span>Inpatient MAR</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/ipd/reconciliation.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/ipd/reconciliation.php">
                    <span>IPD Reconciliation</span>
                </a>
            </div>
        </div>

        <!-- 6. REPORTS (Collapsible) -->
        <div class="sidebar-group">
            <button class="sidebar-group-header <?= $reportsActive ? 'active-group open' : '' ?>" type="button" data-group="groupReports" aria-expanded="<?= $reportsActive ? 'true' : 'false' ?>">
                <div class="group-title-wrap">
                    <i class="ti ti-chart-bar"></i>
                    <span>Reports</span>
                </div>
                <i class="ti ti-chevron-down group-chevron"></i>
            </button>
            <div class="sidebar-submenu <?= $reportsActive ? 'show' : '' ?>" id="groupReports">
                <a class="nav-link <?= is_nav_active('modules/reports/sales.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/reports/sales.php">
                    <span>Sales Reports</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/reports/purchases.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/reports/purchases.php">
                    <span>Purchase Reports</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/reports/stock.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/reports/stock.php">
                    <span>Stock Reports</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/reports/mar_register.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/reports/mar_register.php">
                    <span>MAR Register</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/reports/administration_variance.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/reports/administration_variance.php">
                    <span>Admin Variance</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/reports/batch_traceability.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/reports/batch_traceability.php">
                    <span>Batch Traceability</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/reports/reconciliation.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/reports/reconciliation.php">
                    <span>Stock Reconciliation</span>
                </a>
            </div>
        </div>

        <!-- 7. ADMINISTRATION (Collapsible) -->
        <div class="sidebar-group">
            <button class="sidebar-group-header <?= $adminActive ? 'active-group open' : '' ?>" type="button" data-group="groupAdmin" aria-expanded="<?= $adminActive ? 'true' : 'false' ?>">
                <div class="group-title-wrap">
                    <i class="ti ti-settings"></i>
                    <span>Administration</span>
                </div>
                <i class="ti ti-chevron-down group-chevron"></i>
            </button>
            <div class="sidebar-submenu <?= $adminActive ? 'show' : '' ?>" id="groupAdmin">
                <a class="nav-link <?= is_nav_active('modules/admin/users.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/admin/users.php">
                    <span>Pharmacy Users</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/admin/roles.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/admin/roles.php">
                    <span>Roles &amp; Permissions</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/admin/settings.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/admin/settings.php">
                    <span>Pharmacy Settings</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/admin/audit_logs.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/admin/audit_logs.php">
                    <span>Audit Trail</span>
                </a>
            </div>
        </div>

        <div class="mt-3 mb-2 px-2">
            <a href="<?= BASE_URL ?>logout.php" class="btn btn-outline-secondary btn-sm w-100 d-flex align-items-center justify-content-center gap-1.5">
                <i class="ti ti-power"></i> Sign Out
            </a>
        </div>
    </nav>
</aside>
<div class="main-container">
