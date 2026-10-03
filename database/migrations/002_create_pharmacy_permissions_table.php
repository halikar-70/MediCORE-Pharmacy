<?php
// database/migrations/002_create_pharmacy_permissions_table.php

return function (PDO $pdo) {
    $sql = "
        CREATE TABLE IF NOT EXISTS pharmacy_permissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            permission_key VARCHAR(100) NOT NULL UNIQUE,
            permission_name VARCHAR(150) NOT NULL,
            description VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql);

    // Initial permissions per STEP 7
    $permissions = [
        ['pharmacy.dashboard.view', 'View Dashboard', 'Access pharmacy operations overview and dashboard KPIs'],
        ['pharmacy.patients.view', 'View Patients', 'Look up hospital and pharmacy registered patients'],
        ['pharmacy.patients.create', 'Register Patient', 'Create and register local pharmacy patients'],
        ['pharmacy.patients.edit', 'Edit Patient', 'Update pharmacy patient demographic information'],
        ['pharmacy.inventory.view', 'View Inventory', 'Inspect product master, batch inventory, and stock levels'],
        ['pharmacy.sales.view', 'View Sales', 'Access sales registries, invoices, and sale monitoring'],
        ['pharmacy.sales.create', 'Create Sales', 'Process counter sales, prescription sales, and regular IPD sales'],
        ['pharmacy.purchases.view', 'View Purchases', 'Inspect purchase orders and invoices'],
        ['pharmacy.purchases.create', 'Create Purchases', 'Create purchase orders and record incoming supplier deliveries'],
        ['pharmacy.suppliers.view', 'View Suppliers', 'Browse supplier master directory'],
        ['pharmacy.suppliers.manage', 'Manage Suppliers', 'Add or modify supplier credentials and terms'],
        ['pharmacy.reports.view', 'View Reports', 'Generate and export financial, sales, stock, and audit reports'],
        ['pharmacy.settings.manage', 'Manage Settings', 'Configure pharmacy operational settings, licenses, and tax defaults'],
        ['pharmacy.audit.view', 'View Audit Logs', 'Review immutable system activity and change records'],
        ['pharmacy.users.manage', 'Manage Users', 'Create, update, and manage pharmacy staff accounts and roles']
    ];

    $stmt = $pdo->prepare("
        INSERT INTO pharmacy_permissions (permission_key, permission_name, description, created_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name), description = VALUES(description)
    ");

    foreach ($permissions as $p) {
        $stmt->execute($p);
    }
};
