<?php
// database/migrations/031_seed_chunk6_permissions_and_sequences.php

return function (PDO $pdo) {
    // 1. Insert Chunk 6 permissions
    $permissions = [
        ['pharmacy.prescriptions.create', 'Create Prescriptions', 'Register electronic prescriptions for OPD/IPD patients'],
        ['pharmacy.prescriptions.amend', 'Amend Prescriptions', 'Auditably modify active prescription items and dosages'],
        ['pharmacy.prescriptions.discontinue', 'Discontinue Prescriptions', 'Discontinue active prescriptions with auditable reasons'],
        ['pharmacy.dispensing.view', 'View Dispensing Queue', 'View IPD and OPD clinical medication dispensing queues'],
        ['pharmacy.dispensing.manage', 'Execute Clinical Dispensing', 'Dispense medications from batch inventory with FEFO'],
        ['pharmacy.mar.view', 'View MAR Sheets', 'View Medication Administration Records for admitted patients'],
        ['pharmacy.mar.schedule', 'Generate MAR Schedules', 'Generate timed administration schedules from prescriptions'],
        ['pharmacy.mar.administer', 'Administer Medication (MAR)', 'Record actual doses given, held, refused, or missed'],
        ['pharmacy.mar.correct', 'Correct MAR Records', 'Auditably correct previously documented administration events']
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

    // 2. Ensure STAFF_NURSE role exists
    $chkRole = $pdo->query("SELECT id FROM pharmacy_roles WHERE role_name = 'STAFF_NURSE'");
    $nurseRoleId = $chkRole->fetchColumn();

    if (!$nurseRoleId) {
        $pdo->exec("
            INSERT INTO pharmacy_roles (role_name, description)
            VALUES ('STAFF_NURSE', 'Clinical Inpatient Ward Staff Nurse for Medication Administration Record')
        ");
        $nurseRoleId = (int)$pdo->lastInsertId();
    }

    // 3. Helper to assign permission to role
    $assignPerm = function(int $roleId, string $permKey) use ($pdo) {
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO pharmacy_role_permissions (role_id, permission_id)
            SELECT ?, id FROM pharmacy_permissions WHERE permission_key = ?
        ");
        $stmt->execute([$roleId, $permKey]);
    };

    // Role IDs
    $adminRoleId = (int)$pdo->query("SELECT id FROM pharmacy_roles WHERE role_name = 'ADMIN'")->fetchColumn();
    $pharmacistRoleId = (int)$pdo->query("SELECT id FROM pharmacy_roles WHERE role_name = 'PHARMACIST'")->fetchColumn();

    // Assign to ADMIN (Everything)
    if ($adminRoleId > 0) {
        foreach ($permissions as $p) {
            $assignPerm($adminRoleId, $p[0]);
        }
    }

    // Assign to PHARMACIST (Prescriptions, Dispensing, Indents, MAR View)
    if ($pharmacistRoleId > 0) {
        $assignPerm($pharmacistRoleId, 'pharmacy.prescriptions.create');
        $assignPerm($pharmacistRoleId, 'pharmacy.prescriptions.amend');
        $assignPerm($pharmacistRoleId, 'pharmacy.prescriptions.discontinue');
        $assignPerm($pharmacistRoleId, 'pharmacy.dispensing.view');
        $assignPerm($pharmacistRoleId, 'pharmacy.dispensing.manage');
        $assignPerm($pharmacistRoleId, 'pharmacy.mar.view');
        $assignPerm($pharmacistRoleId, 'pharmacy.mar.schedule');
        // Note: PHARMACIST deliberately NOT granted pharmacy.mar.administer by default to maintain separation
    }

    // Assign to STAFF_NURSE (MAR administration, MAR view, MAR correct, Prescriptions view)
    if ($nurseRoleId > 0) {
        $assignPerm($nurseRoleId, 'pharmacy.mar.view');
        $assignPerm($nurseRoleId, 'pharmacy.mar.administer');
        $assignPerm($nurseRoleId, 'pharmacy.mar.correct');
        $assignPerm($nurseRoleId, 'pharmacy.prescriptions.view');
        $assignPerm($nurseRoleId, 'pharmacy.dashboard.view');
        // STRICTLY NO: pharmacy.sales.post, pharmacy.batches.manage, pharmacy.disposal.approve, pharmacy.dispensing.manage
    }

    // 4. Register Document Sequences
    $sequences = [
        ['DISPENSING', 'DSP-', 0, 6, 'Clinical dispensing transaction sequence'],
        ['MAR', 'MAR-', 0, 6, 'Medication administration record sequence'],
        ['PRESCRIPTION_AMEND', 'AMD-', 0, 6, 'Prescription amendment audit sequence']
    ];

    $insSeq = $pdo->prepare("
        INSERT INTO pharmacy_sequences (sequence_key, prefix, current_value, pad_length, description, updated_at)
        VALUES (?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE 
            prefix = VALUES(prefix),
            description = VALUES(description),
            pad_length = VALUES(pad_length)
    ");

    foreach ($sequences as $s) {
        $insSeq->execute($s);
    }
};
