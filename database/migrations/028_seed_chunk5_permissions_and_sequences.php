<?php
// database/migrations/028_seed_chunk5_permissions_and_sequences.php

return function (PDO $pdo) {
    // 1. Seed Sequences
    $sequences = [
        ['SALE_RETURN', 'SRT-', 0, 6, 'Customer sales return note sequence'],
        ['PURCHASE_RETURN', 'PRT-', 0, 6, 'Supplier purchase return debit note sequence'],
        ['QUARANTINE', 'QRN-', 0, 6, 'Batch quarantine isolation record sequence'],
        ['DISPOSAL', 'DISP-', 0, 6, 'Medicine destruction and disposal register sequence']
    ];

    $seqStmt = $pdo->prepare("
        INSERT INTO pharmacy_sequences (sequence_key, prefix, current_value, pad_length, description)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE prefix = VALUES(prefix), pad_length = VALUES(pad_length), description = VALUES(description)
    ");
    foreach ($sequences as $s) {
        $seqStmt->execute($s);
    }

    // 2. Seed Permissions
    $permissions = [
        ['pharmacy.sales_returns.view', 'View Sales Returns', 'Browse customer medicine returns and credit notes'],
        ['pharmacy.sales_returns.create', 'Create Sales Returns', 'Initiate sales return requests for previously dispensed medicines'],
        ['pharmacy.sales_returns.approve', 'Approve Sales Returns', 'Authorize customer sales returns and restock decisions'],
        ['pharmacy.sales_returns.post', 'Post Sales Returns', 'Post customer sales returns with inventory restocking or quarantine'],
        ['pharmacy.sales_returns.cancel', 'Cancel Sales Returns', 'Cancel draft or unposted customer sales returns'],

        ['pharmacy.purchase_returns.view', 'View Purchase Returns', 'Browse supplier debit notes and returns'],
        ['pharmacy.purchase_returns.create', 'Create Purchase Returns', 'Draft returns of stock to pharmaceutical suppliers'],
        ['pharmacy.purchase_returns.approve', 'Approve Purchase Returns', 'Authorize outward stock debit notes to suppliers'],
        ['pharmacy.purchase_returns.post', 'Post Purchase Returns', 'Post purchase returns, deduct inventory, and adjust payables'],

        ['pharmacy.expiry.quarantine', 'Quarantine Expired Stock', 'Transfer expired medicine batches into quarantine isolation'],
        ['pharmacy.expiry.dispose', 'Dispose Expired Stock', 'Initiate permanent disposal of expired batches'],

        ['pharmacy.damage.view', 'View Damaged Stock', 'Monitor broken, spoiled, or damaged inventory'],
        ['pharmacy.damage.create', 'Report Damaged Stock', 'Record damaged or unusable pharmaceutical batches'],
        ['pharmacy.damage.approve', 'Approve Damage Reports', 'Authorize damage write-offs and quarantine transfers'],

        ['pharmacy.quarantine.view', 'View Quarantined Stock', 'Inspect suspect or isolated pharmaceutical batches'],
        ['pharmacy.quarantine.release', 'Release from Quarantine', 'Authorize returning quarantined stock to sellable inventory'],
        ['pharmacy.quarantine.dispose', 'Send Quarantine to Disposal', 'Condemn quarantined stock and transfer to disposal queue'],

        ['pharmacy.disposal.view', 'View Disposals', 'Access pharmaceutical destruction register and records'],
        ['pharmacy.disposal.create', 'Create Disposal Record', 'Prepare batch destruction and disposal manifests'],
        ['pharmacy.disposal.approve', 'Approve Disposals', 'Authorize permanent batch destruction protocols'],
        ['pharmacy.disposal.post', 'Execute Disposal', 'Finalize stock disposal and write permanent ledger entries']
    ];

    $insStmt = $pdo->prepare("
        INSERT INTO pharmacy_permissions (permission_key, permission_name, description, created_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name), description = VALUES(description)
    ");
    foreach ($permissions as $p) {
        $insStmt->execute($p);
    }

    // 3. Assign Permissions
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

        // Pharmacist gets operational returns, damage report, quarantine view
        if (
            str_contains($permKey, 'sales_returns.view') ||
            str_contains($permKey, 'sales_returns.create') ||
            str_contains($permKey, 'sales_returns.post') ||
            str_contains($permKey, 'purchase_returns.view') ||
            str_contains($permKey, 'purchase_returns.create') ||
            str_contains($permKey, 'damage.view') ||
            str_contains($permKey, 'damage.create') ||
            str_contains($permKey, 'quarantine.view') ||
            str_contains($permKey, 'expiry.quarantine') ||
            str_contains($permKey, 'disposal.view')
        ) {
            $rolePermStmt->execute([2, $permId]);
        }
    }
};
