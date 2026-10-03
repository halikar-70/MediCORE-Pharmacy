<?php
// database/migrations/020_seed_chunk3_permissions_and_sequences.php

return function (PDO $pdo) {
    // 1. Seed Sequences
    $sequences = [
        ['sequence_key' => 'PURCHASE_ORDER',   'prefix' => 'PO-',   'current_value' => 0, 'pad_length' => 6, 'description' => 'Supplier purchase order sequence'],
        ['sequence_key' => 'GRN',              'prefix' => 'GRN-',  'current_value' => 0, 'pad_length' => 6, 'description' => 'Goods received note sequence'],
        ['sequence_key' => 'PURCHASE_INVOICE', 'prefix' => 'PINV-', 'current_value' => 0, 'pad_length' => 6, 'description' => 'Supplier purchase bill sequence'],
        ['sequence_key' => 'SUPPLIER_PAYMENT', 'prefix' => 'SP-',   'current_value' => 0, 'pad_length' => 6, 'description' => 'Supplier payment voucher sequence'],
        ['sequence_key' => 'SUPPLIER',         'prefix' => 'SUP-',  'current_value' => 0, 'pad_length' => 6, 'description' => 'Supplier master code sequence'],
    ];

    $checkSeq = $pdo->prepare("SELECT COUNT(*) FROM pharmacy_sequences WHERE sequence_key = ?");
    $insertSeq = $pdo->prepare("INSERT INTO pharmacy_sequences (sequence_key, prefix, current_value, pad_length, description) VALUES (?, ?, ?, ?, ?)");

    foreach ($sequences as $s) {
        $checkSeq->execute([$s['sequence_key']]);
        if ((int)$checkSeq->fetchColumn() === 0) {
            $insertSeq->execute([$s['sequence_key'], $s['prefix'], $s['current_value'], $s['pad_length'], $s['description']]);
        }
    }

    // 2. Seed Permissions
    $permissions = [
        ['pharmacy.suppliers.view',            'View Suppliers',             'Browse and inspect supplier master directory'],
        ['pharmacy.suppliers.manage',          'Manage Suppliers',           'Add, edit, duplicate check, and deactivate suppliers'],
        ['pharmacy.purchase_orders.view',      'View Purchase Orders',       'View purchase orders and order status'],
        ['pharmacy.purchase_orders.create',    'Create Purchase Orders',     'Create and draft purchase orders to suppliers'],
        ['pharmacy.purchase_orders.approve',   'Approve Purchase Orders',    'Approve purchase orders for transmission to suppliers'],
        ['pharmacy.purchase_orders.cancel',    'Cancel Purchase Orders',     'Cancel purchase orders'],
        ['pharmacy.grn.view',                  'View Goods Received Notes',  'View GRN records and receiving history'],
        ['pharmacy.grn.create',                'Create Goods Received Notes','Receive stock against purchase orders or direct deliveries'],
        ['pharmacy.grn.post',                  'Post GRN to Stock',          'Post accepted GRN quantities into batches and stock ledger'],
        ['pharmacy.grn.cancel',                'Cancel Goods Received Notes','Cancel or reverse goods received notes with stock compensation'],
        ['pharmacy.purchase_invoices.view',    'View Purchase Invoices',     'Inspect purchase invoices and bills'],
        ['pharmacy.purchase_invoices.create',  'Create Purchase Invoices',   'Record purchase invoices and perform 3-way matching'],
        ['pharmacy.purchase_invoices.manage',  'Manage Purchase Invoices',   'Edit, review discrepancies, and manage purchase invoices'],
        ['pharmacy.supplier_payments.view',    'View Supplier Payments',     'Inspect supplier payment vouchers'],
        ['pharmacy.supplier_payments.create',  'Create Supplier Payments',   'Record payments to suppliers and allocate across bills'],
        ['pharmacy.supplier_ledger.view',      'View Supplier Ledger',       'View detailed supplier financial transaction ledger'],
        ['pharmacy.supplier_outstanding.view', 'View Supplier Outstanding',  'View supplier outstanding dues and aging analysis'],
    ];

    $stmt = $pdo->prepare("
        INSERT INTO pharmacy_permissions (permission_key, permission_name, description, created_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name), description = VALUES(description)
    ");

    foreach ($permissions as $p) {
        $stmt->execute($p);
    }

    // Assign permissions to ADMIN, PHARMACY_MANAGER, PHARMACIST
    $adminRoles = $pdo->query("SELECT id FROM pharmacy_roles WHERE role_name IN ('ADMIN', 'Admin')")->fetchAll(PDO::FETCH_COLUMN);
    $mgrRoles   = $pdo->query("SELECT id FROM pharmacy_roles WHERE role_name IN ('PHARMACY_MANAGER', 'Pharmacy Manager')")->fetchAll(PDO::FETCH_COLUMN);
    $pharmRoles = $pdo->query("SELECT id FROM pharmacy_roles WHERE role_name IN ('PHARMACIST', 'Pharmacist')")->fetchAll(PDO::FETCH_COLUMN);

    $permIdStmt = $pdo->prepare("SELECT id FROM pharmacy_permissions WHERE permission_key = ?");
    $assignStmt = $pdo->prepare("INSERT IGNORE INTO pharmacy_role_permissions (role_id, permission_id) VALUES (?, ?)");

    foreach ($permissions as $p) {
        $permIdStmt->execute([$p[0]]);
        $permId = $permIdStmt->fetchColumn();
        if (!$permId) continue;

        // Admin gets all
        foreach ($adminRoles as $rid) {
            $assignStmt->execute([$rid, $permId]);
        }
        // Manager gets all
        foreach ($mgrRoles as $rid) {
            $assignStmt->execute([$rid, $permId]);
        }
        // Pharmacist gets operational permissions (except approve if admin-restricted)
        foreach ($pharmRoles as $rid) {
            $assignStmt->execute([$rid, $permId]);
        }
    }
};
