<?php
// database/migrations/014_seed_chunk2_permissions.php

return function (PDO $pdo) {
    $permissions = [
        ['pharmacy.medicines.view', 'View Medicine Master', 'Browse and search medicine catalog, formulations, and details'],
        ['pharmacy.medicines.manage', 'Manage Medicine Master', 'Create, edit, duplicate check, and deactivate medicines'],
        ['pharmacy.batches.view', 'View Medicine Batches', 'View batch stock levels, expiry dates, and MRP'],
        ['pharmacy.batches.manage', 'Manage Medicine Batches', 'Add and update batch records and storage locations'],
        ['pharmacy.ledger.view', 'View Stock Ledger', 'Inspect immutable stock movement transactions and audit balances'],
        ['pharmacy.adjustments.manage', 'Manage Stock Adjustments', 'Perform authorized stock reconciliations, damage, and corrections'],
        ['pharmacy.opening_stock.manage', 'Manage Opening Stock', 'Enter baseline opening stock batches with ledger entries'],
        ['pharmacy.expiry.view', 'View Expiry Management', 'Access expiry dashboards, 30/60/90 day alerts, and quarantined stock'],
        ['pharmacy.shelves.manage', 'Manage Storage Shelves', 'Assign and update rack, shelf, and bin locations for items']
    ];

    $stmt = $pdo->prepare("
        INSERT INTO pharmacy_permissions (permission_key, permission_name, description, created_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name), description = VALUES(description)
    ");

    foreach ($permissions as $p) {
        $stmt->execute($p);
    }

    // Assign to Pharmacist role (role_id 2)
    $pharmacistPerms = [
        'pharmacy.medicines.view',
        'pharmacy.medicines.manage',
        'pharmacy.batches.view',
        'pharmacy.batches.manage',
        'pharmacy.ledger.view',
        'pharmacy.adjustments.manage',
        'pharmacy.opening_stock.manage',
        'pharmacy.expiry.view',
        'pharmacy.shelves.manage'
    ];

    $roleStmt = $pdo->prepare("SELECT id FROM pharmacy_roles WHERE role_name IN ('PHARMACIST', 'PHARMACY_MANAGER')");
    $roleStmt->execute();
    $roleIds = $roleStmt->fetchAll(PDO::FETCH_COLUMN);

    $permIdStmt = $pdo->prepare("SELECT id FROM pharmacy_permissions WHERE permission_key = ?");
    $assignStmt = $pdo->prepare("INSERT IGNORE INTO pharmacy_role_permissions (role_id, permission_id) VALUES (?, ?)");

    foreach ($roleIds as $rId) {
        foreach ($pharmacistPerms as $pkey) {
            $permIdStmt->execute([$pkey]);
            $pId = $permIdStmt->fetchColumn();
            if ($pId) {
                $assignStmt->execute([$rId, $pId]);
            }
        }
    }
};
