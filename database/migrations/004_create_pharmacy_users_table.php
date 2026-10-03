<?php
// database/migrations/004_create_pharmacy_users_table.php

return function (PDO $pdo) {
    $sql = "
        CREATE TABLE IF NOT EXISTS pharmacy_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(100) NOT NULL,
            mobile VARCHAR(20) NULL,
            email VARCHAR(100) NULL,
            role_id INT NOT NULL,
            status TINYINT(1) NOT NULL DEFAULT 1,
            last_login_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_role (role_id),
            INDEX idx_username_status (username, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql);

    // Fetch roles
    $rolesStmt = $pdo->query("SELECT role_name, id FROM pharmacy_roles");
    $roles = $rolesStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $adminRoleId = $roles['ADMIN'] ?? 1;
    $pharmacistRoleId = $roles['PHARMACIST'] ?? 3;
    $cashierRoleId = $roles['PHARMACY_CASHIER'] ?? 4;

    $users = [
        [
            'username'      => 'admin',
            'password_hash' => password_hash('Admin@12345', PASSWORD_DEFAULT),
            'full_name'     => 'Pharmacy Administrator',
            'mobile'        => '9876543210',
            'email'         => 'admin@pharmacy.local',
            'role_id'       => $adminRoleId,
            'status'        => 1
        ],
        [
            'username'      => 'pharmacist',
            'password_hash' => password_hash('Pharma@12345', PASSWORD_DEFAULT),
            'full_name'     => 'Senior Dispensing Pharmacist',
            'mobile'        => '9876543211',
            'email'         => 'pharmacist@pharmacy.local',
            'role_id'       => $pharmacistRoleId,
            'status'        => 1
        ],
        [
            'username'      => 'cashier',
            'password_hash' => password_hash('Cashier@12345', PASSWORD_DEFAULT),
            'full_name'     => 'POS Billing Cashier',
            'mobile'        => '9876543212',
            'email'         => 'cashier@pharmacy.local',
            'role_id'       => $cashierRoleId,
            'status'        => 1
        ],
        [
            'username'      => 'inactive_user',
            'password_hash' => password_hash('Inactive@12345', PASSWORD_DEFAULT),
            'full_name'     => 'Deactivated Staff Account',
            'mobile'        => '9876543213',
            'email'         => 'inactive@pharmacy.local',
            'role_id'       => $cashierRoleId,
            'status'        => 0 // Inactive
        ]
    ];

    $stmt = $pdo->prepare("
        INSERT INTO pharmacy_users (username, password_hash, full_name, mobile, email, role_id, status, created_at, updated_at)
        VALUES (:username, :password_hash, :full_name, :mobile, :email, :role_id, :status, NOW(), NOW())
        ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), role_id = VALUES(role_id), status = VALUES(status)
    ");

    foreach ($users as $u) {
        $stmt->execute($u);
    }
};
