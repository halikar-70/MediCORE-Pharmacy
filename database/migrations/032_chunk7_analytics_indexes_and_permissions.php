<?php
// database/migrations/032_chunk7_analytics_indexes_and_permissions.php

return function (PDO $pdo) {
    // Helper to safely add an index if it does not exist
    $addIndexIfNotExists = function(PDO $pdo, string $table, string $indexName, string $columns) {
        $check = $pdo->query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$indexName}'")->rowCount();
        if ($check === 0) {
            $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$indexName}` ({$columns})");
        }
    };

    // 1. Additive performance indexes for high-volume analytics queries
    $addIndexIfNotExists($pdo, 'pharmacy_sales', 'idx_sales_date_status', 'sale_date, status');
    $addIndexIfNotExists($pdo, 'pharmacy_sale_items', 'idx_sale_items_med', 'medicine_id, sale_id');
    $addIndexIfNotExists($pdo, 'pharmacy_purchase_invoices', 'idx_pinv_date_paystatus', 'invoice_date, payment_status');
    $addIndexIfNotExists($pdo, 'pharmacy_stock_ledger', 'idx_ledger_med_created', 'medicine_id, created_at');
    $addIndexIfNotExists($pdo, 'pharmacy_mar_records', 'idx_mar_date_status', 'scheduled_date, status');
    $addIndexIfNotExists($pdo, 'pharmacy_audit_logs', 'idx_audit_action_date', 'action, created_at');

    // 2. Seed Chunk 7 permissions
    $permissions = [
        ['pharmacy.reports.financial', 'View Financial Reports', 'Access to profit margins, revenue analytics, and valuation'],
        ['pharmacy.reports.reconciliation', 'View Reconciliation', 'Access to physical vs ledger and financial reconciliation audits'],
        ['pharmacy.audit.security', 'View Security Audit', 'Access to security events, failed auth attempts, and RBAC logs']
    ];

    $insPerm = $pdo->prepare("
        INSERT INTO pharmacy_permissions (permission_key, permission_name, description)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            permission_name = VALUES(permission_name),
            description = VALUES(description)
    ");

    foreach ($permissions as $p) {
        $insPerm->execute($p);
    }

    // 3. Helper to assign permission to role
    $assignPerm = function(int $roleId, string $permKey) use ($pdo) {
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO pharmacy_role_permissions (role_id, permission_id)
            SELECT ?, id FROM pharmacy_permissions WHERE permission_key = ?
        ");
        $stmt->execute([$roleId, $permKey]);
    };

    $adminRoleId = (int)$pdo->query("SELECT id FROM pharmacy_roles WHERE role_name = 'ADMIN'")->fetchColumn();
    $managerRoleId = (int)$pdo->query("SELECT id FROM pharmacy_roles WHERE role_name = 'PHARMACY_MANAGER'")->fetchColumn();
    $pharmacistRoleId = (int)$pdo->query("SELECT id FROM pharmacy_roles WHERE role_name = 'PHARMACIST'")->fetchColumn();

    if ($adminRoleId > 0) {
        foreach ($permissions as $p) {
            $assignPerm($adminRoleId, $p[0]);
        }
    }

    if ($managerRoleId > 0) {
        $assignPerm($managerRoleId, 'pharmacy.reports.financial');
        $assignPerm($managerRoleId, 'pharmacy.reports.reconciliation');
    }

    if ($pharmacistRoleId > 0) {
        $assignPerm($pharmacistRoleId, 'pharmacy.reports.reconciliation');
    }
};
