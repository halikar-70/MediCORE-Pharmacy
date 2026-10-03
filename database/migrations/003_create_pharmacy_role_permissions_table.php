<?php
// database/migrations/003_create_pharmacy_role_permissions_table.php

return function (PDO $pdo) {
    $sql = "
        CREATE TABLE IF NOT EXISTS pharmacy_role_permissions (
            role_id INT NOT NULL,
            permission_id INT NOT NULL,
            PRIMARY KEY (role_id, permission_id),
            INDEX idx_role (role_id),
            INDEX idx_permission (permission_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql);

    // Fetch roles
    $rolesStmt = $pdo->query("SELECT id, role_name FROM pharmacy_roles");
    $roles = $rolesStmt->fetchAll(PDO::FETCH_KEY_PAIR); // [role_name => id]
    $roles = array_flip($roles); // [id => role_name] mapped to [role_name => id]

    // Fetch permissions
    $permStmt = $pdo->query("SELECT id, permission_key FROM pharmacy_permissions");
    $perms = $permStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $perms = array_flip($perms);

    $roleMap = [
        'ADMIN' => array_keys($perms), // All permissions
        'PHARMACY_MANAGER' => [
            'pharmacy.dashboard.view', 'pharmacy.patients.view', 'pharmacy.patients.create',
            'pharmacy.patients.edit', 'pharmacy.inventory.view', 'pharmacy.sales.view',
            'pharmacy.sales.create', 'pharmacy.purchases.view', 'pharmacy.purchases.create',
            'pharmacy.suppliers.view', 'pharmacy.suppliers.manage', 'pharmacy.reports.view',
            'pharmacy.settings.manage', 'pharmacy.audit.view'
        ],
        'PHARMACIST' => [
            'pharmacy.dashboard.view', 'pharmacy.patients.view', 'pharmacy.patients.create',
            'pharmacy.patients.edit', 'pharmacy.inventory.view', 'pharmacy.sales.view',
            'pharmacy.sales.create', 'pharmacy.purchases.view', 'pharmacy.reports.view'
        ],
        'PHARMACY_CASHIER' => [
            'pharmacy.dashboard.view', 'pharmacy.patients.view', 'pharmacy.patients.create',
            'pharmacy.sales.view', 'pharmacy.sales.create'
        ],
        'PHARMACY_STOREKEEPER' => [
            'pharmacy.dashboard.view', 'pharmacy.inventory.view', 'pharmacy.purchases.view',
            'pharmacy.purchases.create', 'pharmacy.suppliers.view'
        ],
        'PHARMACY_VIEWER' => [
            'pharmacy.dashboard.view', 'pharmacy.inventory.view', 'pharmacy.sales.view',
            'pharmacy.reports.view'
        ]
    ];

    $insertStmt = $pdo->prepare("INSERT IGNORE INTO pharmacy_role_permissions (role_id, permission_id) VALUES (?, ?)");

    foreach ($roleMap as $roleName => $assignedKeys) {
        if (!isset($roles[$roleName])) {
            continue;
        }
        $roleId = $roles[$roleName];
        foreach ($assignedKeys as $key) {
            if (isset($perms[$key])) {
                $insertStmt->execute([$roleId, $perms[$key]]);
            }
        }
    }
};
