<?php
// modules/pharmacy/nav.php - Pharmacy Department Sub-Navigation Bar
$current_page = basename($_SERVER['PHP_SELF']);
$current_type = $_GET['type'] ?? '';

$low_stock_badge = 0;
$expired_badge = 0;

try {
    global $pdo;
    if (isset($pdo)) {
        $low_stock_badge = (int)$pdo->query("SELECT COUNT(*) FROM medicines WHERE deleted_at IS NULL AND stock_quantity <= reorder_level")->fetchColumn();
        $expired_badge = (int)$pdo->query("SELECT COUNT(*) FROM medicines WHERE deleted_at IS NULL AND expiry_date IS NOT NULL AND expiry_date < CURDATE()")->fetchColumn();
    }
} catch (Exception $e) {}
?>
<style>
.pharm-nav-btn {
    border-radius: 8px;
    font-size: 0.84rem;
    font-weight: 600;
    transition: all 0.2s ease-in-out;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.45rem 0.85rem;
}
.pharm-nav-btn:hover {
    transform: translateY(-1px);
}
</style>
<div class="card border-0 shadow-sm mb-4" style="border-radius: 12px; background: #ffffff;">
    <div class="card-body p-2">
        <div class="d-flex flex-wrap gap-1 align-items-center" id="pharmacySubNav">
            <!-- 1. Dashboard -->
            <a href="index.php" class="btn pharm-nav-btn <?= $current_page === 'index.php' ? 'btn-primary text-white shadow-sm' : 'btn-light text-secondary border-0' ?>">
                <i class="bi bi-capsule"></i> Pharmacy Dashboard
            </a>

            <!-- 2. Counter Sale (Walk-in / OPD) -->
            <a href="sales_add.php?type=counter" class="btn pharm-nav-btn <?= ($current_page === 'sales_add.php' && $current_type === 'counter') ? 'btn-success text-white shadow-sm' : 'btn-light text-secondary border-0' ?>">
                <i class="bi bi-shop"></i> Counter Sale (OPD)
            </a>

            <!-- 3. Regular / Patient Sale (IPD) -->
            <a href="sales_add.php?type=regular" class="btn pharm-nav-btn <?= ($current_page === 'sales_add.php' && ($current_type === 'regular' || $current_type === '')) ? 'btn-info text-dark shadow-sm' : 'btn-light text-secondary border-0' ?>">
                <i class="bi bi-person-badge"></i> Regular Sale (IPD)
            </a>

            <!-- 4. Sales Registry -->
            <a href="sales_list.php" class="btn pharm-nav-btn <?= in_array($current_page, ['sales_list.php', 'sales_profile.php', 'sales_receipt.php']) ? 'btn-primary text-white shadow-sm' : 'btn-light text-secondary border-0' ?>">
                <i class="bi bi-journal-text"></i> Sales Registry
            </a>

            <!-- 5. Sales Return -->
            <a href="sales_return.php" class="btn pharm-nav-btn <?= $current_page === 'sales_return.php' ? 'btn-danger text-white shadow-sm' : 'btn-light text-secondary border-0' ?>">
                <i class="bi bi-arrow-counterclockwise"></i> Sales Return
            </a>

            <!-- 5.0 Return Registry -->
            <a href="returns_list.php" class="btn pharm-nav-btn <?= $current_page === 'returns_list.php' ? 'btn-danger text-white shadow-sm' : 'btn-light text-secondary border-0' ?>">
                <i class="bi bi-clock-history"></i> Return Registry
            </a>


            <!-- 5.2 IPD Dispensing History -->
            <?php if (has_permission('view_dispensing_history') || has_permission('view_sales') || has_role('Admin')): ?>
                <a href="ipd_dispensing_history.php" class="btn pharm-nav-btn <?= $current_page === 'ipd_dispensing_history.php' ? 'btn-info text-dark shadow-sm' : 'btn-light text-secondary border-0' ?>">
                    <i class="bi bi-clock-history"></i> IPD History
                </a>
            <?php endif; ?>

            <!-- 5.3 IPD Reconciliation -->
            <?php if (has_permission('view_ipd_pharmacy_billing') || has_permission('view_sales') || has_role('Admin')): ?>
                <a href="ipd_reconciliation.php" class="btn pharm-nav-btn <?= $current_page === 'ipd_reconciliation.php' ? 'btn-success text-white shadow-sm' : 'btn-light text-secondary border-0' ?>">
                    <i class="bi bi-shield-check"></i> IPD Reconciliation
                </a>
            <?php endif; ?>

            <!-- 6. Product Master -->
            <a href="list.php" class="btn pharm-nav-btn <?= in_array($current_page, ['list.php', 'add.php', 'edit.php', 'profile.php']) ? 'btn-primary text-white shadow-sm' : 'btn-light text-secondary border-0' ?>">
                <i class="bi bi-capsule"></i> Product Master
                <?php if ($low_stock_badge > 0): ?>
                    <span class="badge rounded-pill bg-warning text-dark ms-1" title="<?= $low_stock_badge ?> low stock items"><?= $low_stock_badge ?> Low</span>
                <?php endif; ?>
            </a>

            <!-- 7. Batch Inventory -->
            <?php if (has_permission('view_inventory')): ?>
                <a href="inventory.php" class="btn pharm-nav-btn <?= $current_page === 'inventory.php' ? 'btn-primary text-white shadow-sm' : 'btn-light text-secondary border-0' ?>">
                    <i class="bi bi-box-seam"></i> Batch Inventory
                    <?php if ($expired_badge > 0): ?>
                        <span class="badge rounded-pill bg-danger ms-1" title="<?= $expired_badge ?> expired items"><?= $expired_badge ?> Exp</span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>

            <!-- 8. Stock Adjustments -->
            <?php if (has_permission('stock_adjustment')): ?>
                <a href="adjustments.php" class="btn pharm-nav-btn <?= $current_page === 'adjustments.php' ? 'btn-warning text-dark shadow-sm' : 'btn-light text-secondary border-0' ?>">
                    <i class="bi bi-sliders"></i> Adjustments
                </a>
            <?php endif; ?>

            <!-- 9. Stock Ledger -->
            <?php if (has_permission('view_stock_ledger')): ?>
                <a href="ledger.php" class="btn pharm-nav-btn <?= $current_page === 'ledger.php' ? 'btn-info text-dark shadow-sm' : 'btn-light text-secondary border-0' ?>">
                    <i class="bi bi-journal-text"></i> Ledger
                </a>
            <?php endif; ?>

            <!-- 10. Purchases & Goods Receipt -->
            <?php if (has_permission('view_purchases') || has_permission('pharmacy_purchase')): ?>
                <a href="purchase_list.php" class="btn pharm-nav-btn <?= in_array($current_page, ['purchase_list.php', 'purchase_add.php', 'purchase_profile.php', 'purchase_receive.php', 'supplier_return.php']) ? 'btn-primary text-white shadow-sm' : 'btn-light text-secondary border-0' ?>">
                    <i class="bi bi-bag-plus"></i> Purchases
                </a>
            <?php endif; ?>

            <!-- 11. Suppliers Master -->
            <?php if (has_permission('manage_suppliers') || has_permission('view_purchases')): ?>
                <a href="suppliers.php" class="btn pharm-nav-btn <?= $current_page === 'suppliers.php' ? 'btn-primary text-white shadow-sm' : 'btn-light text-secondary border-0' ?>">
                    <i class="bi bi-truck"></i> Suppliers
                </a>
            <?php endif; ?>

            <!-- 12. Expiry & Quarantine Management -->
            <?php if (has_permission('manage_pharmacy_expiry')): ?>
                <a href="expiry.php" class="btn pharm-nav-btn <?= $current_page === 'expiry.php' ? 'btn-warning text-dark shadow-sm' : 'btn-light text-secondary border-0' ?>">
                    <i class="bi bi-exclamation-triangle"></i> Expiry
                </a>
            <?php endif; ?>

            <!-- 13. Pharmacy Reports -->
            <?php if (has_permission('view_pharmacy_reports')): ?>
                <a href="reports.php" class="btn pharm-nav-btn <?= $current_page === 'reports.php' ? 'btn-dark text-white shadow-sm' : 'btn-light text-secondary border-0' ?>">
                    <i class="bi bi-bar-chart"></i> Reports
                </a>
            <?php endif; ?>

        </div>
    </div>
</div>

