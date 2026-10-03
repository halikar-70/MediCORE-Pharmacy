<?php
// database/migrations/033_support_direct_stock_in_out.php

return function (PDO $pdo) {
    // 1. Modify adjustment_type in pharmacy_stock_adjustments to VARCHAR(50) so it supports 'Direct Stock In' and 'Direct Stock Out'
    $pdo->exec("
        ALTER TABLE pharmacy_stock_adjustments 
        MODIFY COLUMN adjustment_type VARCHAR(50) NOT NULL
    ");

    // 2. Add reference_no and notes columns to pharmacy_stock_adjustments if not present
    $cols = $pdo->query("SHOW COLUMNS FROM pharmacy_stock_adjustments LIKE 'reference_no'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("
            ALTER TABLE pharmacy_stock_adjustments 
            ADD COLUMN reference_no VARCHAR(100) NULL AFTER reason
        ");
    }

    // 3. Ensure permissions exist for direct stock in/out and editing
    $permissions = [
        ['pharmacy.inventory.direct_in', 'Direct Stock In', 'Add stock directly to inventory without purchase invoice'],
        ['pharmacy.inventory.direct_out', 'Direct Stock Out', 'Remove stock directly from inventory for non-sale reasons'],
        ['pharmacy.purchase_orders.edit', 'Edit Purchase Orders', 'Modify draft and submitted purchase orders'],
        ['pharmacy.purchase_invoices.edit', 'Edit Purchase Invoices', 'Modify unpaid purchase invoices'],
        ['pharmacy.patients.edit', 'Edit Patient Profile', 'Update pharmacy patient demographic information'],
    ];

    $stmt = $pdo->prepare("
        INSERT INTO pharmacy_permissions (permission_key, permission_name, description, created_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name), description = VALUES(description)
    ");

    $grantAdmin = $pdo->prepare("
        INSERT IGNORE INTO pharmacy_role_permissions (role_id, permission_id) 
        SELECT 1, id FROM pharmacy_permissions WHERE permission_key = ?
    ");
    $grantPharmacist = $pdo->prepare("
        INSERT IGNORE INTO pharmacy_role_permissions (role_id, permission_id) 
        SELECT 2, id FROM pharmacy_permissions WHERE permission_key = ?
    ");

    foreach ($permissions as $p) {
        $stmt->execute($p);
        $grantAdmin->execute([$p[0]]);
        $grantPharmacist->execute([$p[0]]);
    }
};
