<?php
// database/migrations/001_create_pharmacy_roles_table.php

return function (PDO $pdo) {
    $sql = "
        CREATE TABLE IF NOT EXISTS pharmacy_roles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            role_name VARCHAR(50) NOT NULL UNIQUE,
            description VARCHAR(255) NULL,
            status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql);

    // Seed initial roles per STEP 7
    $roles = [
        ['ADMIN', 'System Administrator with full pharmacy access'],
        ['PHARMACY_MANAGER', 'Supervises dispensing, stock management, and financial summaries'],
        ['PHARMACIST', 'Licensed pharmacist managing dispensing and verification'],
        ['PHARMACY_CASHIER', 'Point of sale counter operator handling collections'],
        ['PHARMACY_STOREKEEPER', 'Inventory and warehouse stock handler'],
        ['PHARMACY_VIEWER', 'Read-only audit and reporting access']
    ];

    $stmt = $pdo->prepare("
        INSERT INTO pharmacy_roles (role_name, description, status, created_at, updated_at)
        VALUES (?, ?, 'Active', NOW(), NOW())
        ON DUPLICATE KEY UPDATE description = VALUES(description)
    ");

    foreach ($roles as $r) {
        $stmt->execute($r);
    }
};
