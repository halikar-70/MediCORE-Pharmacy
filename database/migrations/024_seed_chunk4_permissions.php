<?php
// database/migrations/024_seed_chunk4_permissions.php

return function (PDO $pdo) {
    $permissions = [
        ['pharmacy.sales.post', 'Post Sales', 'Finalize sale transactions and deduct inventory'],
        ['pharmacy.sales.cancel', 'Cancel Sales', 'Cancel sale transactions and generate compensating stock movements'],
        ['pharmacy.prescriptions.view', 'View Prescriptions', 'Browse and search incoming electronic prescriptions'],
        ['pharmacy.prescriptions.verify', 'Verify Prescriptions', 'Clinical verification of prescribed medicines and dosage'],
        ['pharmacy.prescriptions.dispense', 'Dispense Prescriptions', 'Fulfill and bill prescribed items via FEFO'],
        ['pharmacy.ipd_sales.view', 'View IPD Sales', 'Inspect patient-linked hospital inpatient sales'],
        ['pharmacy.ipd_sales.create', 'Create IPD Sales', 'Create and charge medication to admitted IPD patients'],
        ['pharmacy.ipd_sales.post', 'Post IPD Sales', 'Post IPD sales transactions with inventory deduction'],
        ['pharmacy.indents.view', 'View Ward Indents', 'Browse ward and IPD medication requisitions'],
        ['pharmacy.indents.approve', 'Approve Ward Indents', 'Authorize medication indents requested by nursing staff'],
        ['pharmacy.indents.fulfill', 'Fulfill Ward Indents', 'Fulfill and dispense ward indents using FEFO'],
        ['pharmacy.indents.cancel', 'Cancel Ward Indents', 'Reject or cancel ward requisitions'],
        ['pharmacy.discount.override', 'Override Sale Discounts', 'Authorize discounts higher than standard cashier thresholds']
    ];

    $insStmt = $pdo->prepare("
        INSERT INTO pharmacy_permissions (permission_key, permission_name, description, created_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name), description = VALUES(description)
    ");

    foreach ($permissions as $p) {
        $insStmt->execute($p);
    }

    // Assign permissions to Admin (role_id 1) and Pharmacist (role_id 2)
    $allPermKeys = array_column($permissions, 0);
    $inClause = implode(',', array_fill(0, count($allPermKeys), '?'));
    $permStmt = $pdo->prepare("SELECT id, permission_key FROM pharmacy_permissions WHERE permission_key IN ($inClause)");
    $permStmt->execute($allPermKeys);
    $permMap = $permStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $rolePermStmt = $pdo->prepare("
        INSERT IGNORE INTO pharmacy_role_permissions (role_id, permission_id)
        VALUES (?, ?)
    ");

    // Roles: 1 = Admin, 2 = Pharmacist
    foreach ($permMap as $permId => $permKey) {
        // Admin gets all permissions
        $rolePermStmt->execute([1, $permId]);

        // Pharmacist gets operational dispensing permissions
        if ($permKey !== 'pharmacy.discount.override') {
            $rolePermStmt->execute([2, $permId]);
        }
    }
};
