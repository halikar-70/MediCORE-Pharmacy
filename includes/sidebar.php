<?php
// includes/sidebar.php - Modular Clinical Navigation Shell (MediPro Aesthetic)

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
    <!-- Brand Header -->
    <div class="sidebar-brand">
        <button type="button" class="btn-close d-lg-none position-absolute top-0 end-0 m-2.5 p-1" id="sidebarCloseBtn" aria-label="Close Navigation"></button>
        <a href="<?= BASE_URL ?>modules/dashboard/index.php" class="d-flex flex-column align-items-center text-decoration-none w-100">
            <img src="<?= BASE_URL ?>assets/images/vatsalya_logo.png?v=<?= time() ?>" alt="Vatsalya Hospital" class="sidebar-brand-logo">
            <div class="sidebar-brand-text">
                <div class="sidebar-brand-title">VATSALYA HOSPITAL</div>
                <div class="sidebar-brand-subtitle">PHARMACY MANAGEMENT SYSTEM</div>
            </div>
        </a>
    </div>

    <!-- Quick Action Button (MediPro Style) -->
    <div class="px-3 pt-2.5 pb-1">
        <a href="<?= BASE_URL ?>modules/sales/counter.php" class="btn btn-primary w-100 d-flex align-items-center justify-content-center" style="border-radius: 10px; font-weight: 700; font-size: 0.82rem; height: 38px; box-shadow: 0 4px 12px rgba(20, 184, 166, 0.25);">
            <i class="ti ti-bolt me-1.5 fs-6"></i>Quick POS Sale
        </a>
    </div>

    <!-- User Profile Card (MediPro Style) -->
    <?php
        $rawName = $user['full_name'] ?? $user['username'] ?? 'Pharmacy Admin';
        $cleanName = str_ireplace('Pharmacy Administrator', 'Pharmacy Admin', $rawName);
        $userRole = strtoupper($user['role_name'] ?? 'ADMIN');
        $userInitial = strtoupper(substr($user['username'] ?? ($cleanName ?? 'A'), 0, 1));
    ?>
    <div class="sidebar-user-card">
        <div class="position-relative flex-shrink-0">
            <div class="avatar-pill">
                <?= $userInitial ?>
            </div>
            <span class="avatar-status-dot" title="Online"></span>
        </div>
        <div class="d-flex flex-column overflow-hidden flex-grow-1" style="min-width: 0; gap: 2px;">
            <div class="sidebar-user-name text-truncate" title="<?= sanitize($cleanName) ?>">
                <?= sanitize($cleanName) ?>
            </div>
            <div>
                <span class="sidebar-role-badge">
                    <?= sanitize($userRole) ?>
                </span>
            </div>
        </div>
        <a href="<?= BASE_URL ?>logout.php" class="sidebar-logout-btn" title="Sign Out">
            <i class="ti ti-power"></i>
        </a>
    </div>

    <!-- Navigation List with Collapsible Groups -->
    <nav class="sidebar-nav">
        <!-- 1. Standalone Dashboard -->
        <div class="nav-item">
            <a class="nav-link menu-dashboard <?= is_nav_active('modules/dashboard', $currentUri) ?>" href="<?= BASE_URL ?>modules/dashboard/index.php">
                <div class="sidebar-icon-wrap">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M3 15C3 10.03 7.03 6 12 6C16.97 6 21 10.03 21 15" stroke="#059669" stroke-width="2.6" stroke-linecap="round" />
                        <circle cx="6.5" cy="11.5" r="0.8" fill="#059669" />
                        <circle cx="12" cy="9" r="0.8" fill="#059669" />
                        <circle cx="17.5" cy="11.5" r="0.8" fill="#059669" />
                        <path d="M12 15L15.5 10.5" stroke="#047857" stroke-width="2.5" stroke-linecap="round" />
                        <circle cx="12" cy="15" r="2.2" fill="#047857" />
                        <rect x="4" y="17" width="16" height="3" rx="1.5" fill="#a7f3d0" />
                    </svg>
                </div>
                <span>Dashboard</span>
            </a>
        </div>

        <div class="sidebar-group-title mt-2">Operations</div>

        <!-- 2. SALES & POS (Direct Top-Level Items - No Dropdown) -->
        <div class="nav-item">
            <a class="nav-link menu-sales-counter <?= is_nav_active('modules/sales/counter.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/sales/counter.php">
                <div class="sidebar-icon-wrap">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M3 3H5.2L7.1 14.2C7.3 15.3 8.2 16 9.3 16H18.2C19.2 16 20.1 15.3 20.3 14.3L21.7 7.5C21.9 6.7 21.2 6 20.4 6H6.1" stroke="#0d9488" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <rect x="8.5" y="8" width="4.5" height="5" rx="1" fill="#14b8a6"/>
                        <rect x="14" y="9.5" width="4" height="3.5" rx="0.8" fill="#5eead4"/>
                        <path d="M10.75 9.5V11.5M9.75 10.5H11.75" stroke="#ffffff" stroke-width="1.2" stroke-linecap="round"/>
                        <circle cx="9.5" cy="19.5" r="1.8" fill="#0f766e"/>
                        <circle cx="9.5" cy="19.5" r="0.6" fill="#ffffff"/>
                        <circle cx="17.5" cy="19.5" r="1.8" fill="#0f766e"/>
                        <circle cx="17.5" cy="19.5" r="0.6" fill="#ffffff"/>
                    </svg>
                </div>
                <span>OPD Counter Sale</span>
            </a>
        </div>

        <div class="nav-item">
            <a class="nav-link menu-sales-regular <?= is_nav_active('modules/sales/regular.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/sales/regular.php">
                <div class="sidebar-icon-wrap">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect x="4" y="3" width="16" height="18" rx="2" fill="#0284c7" />
                        <rect x="7" y="7" width="10" height="2" rx="1" fill="#ffffff" />
                        <rect x="7" y="11" width="6" height="2" rx="1" fill="#bae6fd" />
                        <path d="M13 14H17M15 12V16" stroke="#ffffff" stroke-width="1.8" stroke-linecap="round"/>
                        <circle cx="8" cy="15" r="1.2" fill="#38bdf8"/>
                    </svg>
                </div>
                <span>Regular / IPD Sale</span>
            </a>
        </div>

        <div class="nav-item">
            <a class="nav-link menu-sales-monitoring <?= is_nav_active('modules/sales/monitoring.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/sales/monitoring.php">
                <div class="sidebar-icon-wrap">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect x="2" y="3" width="20" height="14" rx="2" stroke="#6366f1" stroke-width="1.8" fill="#eef2ff"/>
                        <path d="M5 13L9 9L13 12L19 6" stroke="#4f46e5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M8 21H16M12 17V21" stroke="#6366f1" stroke-width="2" stroke-linecap="round"/>
                        <circle cx="19" cy="6" r="1.5" fill="#4338ca"/>
                    </svg>
                </div>
                <span>Sale Monitoring</span>
            </a>
        </div>

        <div class="nav-item">
            <a class="nav-link menu-sales-returns <?= is_nav_active('modules/sales/returns.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/sales/returns.php">
                <div class="sidebar-icon-wrap">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <!-- Receipt Document Body -->
                        <path d="M4 3.5C4 2.67157 4.67157 2 5.5 2H13.5L18 6.5V20.5C18 21.3284 17.3284 22 16.5 22H5.5C4.67157 22 4 21.3284 4 20.5V3.5Z" fill="#f43f5e"/>
                        <!-- Folded Receipt Corner -->
                        <path d="M13.5 2V6.5H18L13.5 2Z" fill="#fda4af"/>
                        <!-- Receipt Text Lines -->
                        <rect x="6.5" y="6.5" width="5" height="1.8" rx="0.9" fill="#ffffff"/>
                        <rect x="6.5" y="10" width="8" height="1.8" rx="0.9" fill="#ffe4e6"/>
                        <rect x="6.5" y="13.5" width="4.5" height="1.8" rx="0.9" fill="#ffe4e6"/>
                        <!-- Circular Refund/Return Badge with White Outer Depth Ring -->
                        <circle cx="15" cy="15.5" r="5.5" fill="#ffffff"/>
                        <circle cx="15" cy="15.5" r="4.6" fill="#e11d48"/>
                        <!-- White Refund U-Turn Arrow -->
                        <path d="M16.5 13.8C16.5 12.8 15.7 12.2 14.8 12.2C13.8 12.2 13 13 13 14C13 15 13.8 15.8 14.8 15.8H16.8" stroke="#ffffff" stroke-width="1.3" stroke-linecap="round"/>
                        <path d="M14.2 11.3L12.8 12.4L14.2 13.5" stroke="#ffffff" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <span>Sale Returns</span>
            </a>
        </div>

        <!-- 3. PURCHASE (Collapsible) -->
        <div class="sidebar-group">
            <button class="sidebar-group-header menu-purchases <?= $purchasesActive ? 'active-group open' : '' ?>" type="button" data-group="groupPurchases" aria-expanded="<?= $purchasesActive ? 'true' : 'false' ?>">
                <div class="d-flex align-items-center gap-2">
                    <div class="sidebar-icon-wrap">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect x="2" y="5" width="12" height="11" rx="2" fill="#f59e0b"/>
                            <rect x="6.5" y="5" width="3" height="11" fill="#fbbf24"/>
                            <path d="M14 8H18.2C18.8 8 19.3 8.3 19.6 8.8L21.6 12.1C21.9 12.5 22 13 22 13.5V16H14V8Z" fill="#d97706"/>
                            <path d="M15.5 10H18.2L19.8 12.5H15.5V10Z" fill="#fef3c7"/>
                            <circle cx="6.5" cy="18" r="2.2" fill="#78350f"/>
                            <circle cx="6.5" cy="18" r="0.8" fill="#ffffff"/>
                            <circle cx="17.5" cy="18" r="2.2" fill="#78350f"/>
                            <circle cx="17.5" cy="18" r="0.8" fill="#ffffff"/>
                        </svg>
                    </div>
                    <span>Procurement</span>
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
            <button class="sidebar-group-header menu-inventory <?= $inventoryActive ? 'active-group open' : '' ?>" type="button" data-group="groupInventory" aria-expanded="<?= $inventoryActive ? 'true' : 'false' ?>">
                <div class="d-flex align-items-center gap-2">
                    <div class="sidebar-icon-wrap">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <!-- Top Cube -->
                            <polygon points="12,2 17,5 12,8 7,5" fill="#a78bfa" />
                            <polygon points="7,5 12,8 12,13 7,10" fill="#7c3aed" />
                            <polygon points="12,8 17,5 17,10 12,13" fill="#6d28d9" />
                            <!-- Bottom Left Cube -->
                            <polygon points="6,10.5 11,13.5 6,16.5 1,13.5" fill="#c4b5fd" />
                            <polygon points="1,13.5 6,16.5 6,21.5 1,18.5" fill="#7c3aed" />
                            <polygon points="6,16.5 11,13.5 11,18.5 6,21.5" fill="#5b21b6" />
                            <!-- Bottom Right Cube -->
                            <polygon points="18,10.5 23,13.5 18,16.5 13,13.5" fill="#ddd6fe" />
                            <polygon points="13,13.5 18,16.5 18,21.5 13,18.5" fill="#8b5cf6" />
                            <polygon points="18,16.5 23,13.5 23,18.5 18,21.5" fill="#6d28d9" />
                        </svg>
                    </div>
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

        <div class="sidebar-group-title mt-2">Clinical</div>

        <!-- 5. PATIENTS & CLINICAL (Collapsible) -->
        <div class="sidebar-group">
            <button class="sidebar-group-header menu-clinical <?= $patientsIpdActive ? 'active-group open' : '' ?>" type="button" data-group="groupClinical" aria-expanded="<?= $patientsIpdActive ? 'true' : 'false' ?>">
                <div class="d-flex align-items-center gap-2">
                    <div class="sidebar-icon-wrap">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <!-- Back person -->
                            <circle cx="17" cy="7" r="3" fill="#60a5fa" />
                            <path d="M12.5 14.5C12.5 12.3 14.3 10.5 16.5 10.5H17.5C19.7 10.5 21.5 12.3 21.5 14.5V17.5H12.5V14.5Z" fill="#60a5fa" />
                            <!-- Front person -->
                            <circle cx="7" cy="6" r="4.5" fill="#0284c7" />
                            <path d="M1 15C1 11.7 3.7 9 7 9C10.3 9 13 11.7 13 15V19.5H1V15Z" fill="#0284c7" />
                            <!-- Plus sign on front person -->
                            <path d="M7 12V16M5 14H9" stroke="white" stroke-width="1.8" stroke-linecap="round" />
                        </svg>
                    </div>
                    <span>Patients &amp; IPD</span>
                </div>
                <i class="ti ti-chevron-down group-chevron"></i>
            </button>
            <div class="sidebar-submenu <?= $patientsIpdActive ? 'show' : '' ?>" id="groupClinical">
                <a class="nav-link <?= is_nav_active('modules/patients/search.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/patients/search.php">
                    <span>Search Patient</span>
                </a>
                <a class="nav-link <?= is_nav_active('modules/patients/list.php', $currentUri) || is_nav_active('modules/patients/register.php', $currentUri) ?>" href="<?= BASE_URL ?>modules/patients/list.php">
                    <span>Patients Directory</span>
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
            </div>
        </div>

        <div class="sidebar-group-title mt-2">Intelligence &amp; System</div>

        <!-- 6. REPORTS (Collapsible) -->
        <div class="sidebar-group">
            <button class="sidebar-group-header menu-reports <?= $reportsActive ? 'active-group open' : '' ?>" type="button" data-group="groupReports" aria-expanded="<?= $reportsActive ? 'true' : 'false' ?>">
                <div class="d-flex align-items-center gap-2">
                    <div class="sidebar-icon-wrap">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <!-- Document Sheet -->
                            <rect x="3" y="2" width="18" height="20" rx="3" fill="#4f46e5" />
                            <!-- Bar chart lines -->
                            <rect x="6" y="12" width="2" height="6" rx="0.5" fill="#c7d2fe" />
                            <rect x="9" y="9" width="2" height="9" rx="0.5" fill="#c7d2fe" />
                            <rect x="12" y="14" width="2" height="4" rx="0.5" fill="#c7d2fe" />
                            <!-- Pie chart shape -->
                            <circle cx="16" cy="7" r="3" fill="#818cf8" />
                            <path d="M16 7L18.12 9.12C18.68 8.56 19 7.82 19 7H16Z" fill="#c7d2fe" />
                        </svg>
                    </div>
                    <span>Analytics &amp; Reports</span>
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
            <button class="sidebar-group-header menu-admin <?= $adminActive ? 'active-group open' : '' ?>" type="button" data-group="groupAdmin" aria-expanded="<?= $adminActive ? 'true' : 'false' ?>">
                <div class="d-flex align-items-center gap-2">
                    <div class="sidebar-icon-wrap">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <!-- Outer Gear -->
                            <path d="M19.4 13C19.5 12.7 19.5 12.3 19.5 12C19.5 11.7 19.5 11.3 19.4 11L21.5 9.4C21.7 9.2 21.8 8.9 21.6 8.7L19.6 5.3C19.5 5.1 19.2 5 19 5.1L16.5 6.1C16 5.7 15.4 5.4 14.8 5.2L14.4 2.5C14.4 2.2 14.1 2 13.9 2H9.9C9.6 2 9.4 2.2 9.4 2.5L9 5.2C8.4 5.4 7.8 5.7 7.3 6.1L4.8 5.1C4.6 5 4.3 5.1 4.2 5.3L2.2 8.7C2 8.9 2.1 9.2 2.3 9.4L4.4 11C4.3 11.3 4.3 11.7 4.3 12C4.3 12.3 4.3 12.7 4.4 13L2.3 14.6C2.1 14.8 2 15.1 2.2 15.3L4.2 18.7C4.3 18.9 4.6 19 4.8 18.9L7.3 17.9C7.8 18.3 8.4 18.6 9 18.8L9.4 21.5C9.4 21.8 9.6 22 9.9 22H13.9C14.1 22 14.4 21.8 14.4 21.5L14.8 18.8C15.4 18.6 16 18.3 16.5 17.9L19 18.9C19.2 19 19.5 18.9 19.6 18.7L21.6 15.3C21.8 15.1 21.7 14.8 21.5 14.6L19.4 13Z" fill="#ea580c"/>
                            <!-- Inner Ring -->
                            <circle cx="12" cy="12" r="5" fill="#fed7aa"/>
                            <!-- Center Core -->
                            <circle cx="12" cy="12" r="2.8" fill="#9a3412"/>
                        </svg>
                    </div>
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

        <div class="mt-3 mb-2 px-1">
            <a href="<?= BASE_URL ?>logout.php" class="btn btn-outline-secondary btn-sm w-100 d-flex align-items-center justify-content-center gap-1.5" style="border-radius: 8px;">
                <i class="ti ti-power"></i> Sign Out
            </a>
        </div>
    </nav>
</aside>
<div class="content-wrapper main-container">
