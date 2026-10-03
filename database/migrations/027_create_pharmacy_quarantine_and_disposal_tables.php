<?php
// database/migrations/027_create_pharmacy_quarantine_and_disposal_tables.php

return function (PDO $pdo) {
    // 1. pharmacy_quarantine_records
    $sql1 = "
        CREATE TABLE IF NOT EXISTS pharmacy_quarantine_records (
            quarantine_id INT AUTO_INCREMENT PRIMARY KEY,
            quarantine_no VARCHAR(50) NOT NULL UNIQUE,
            medicine_id INT NOT NULL,
            batch_id INT NOT NULL,
            quantity INT NOT NULL,
            reason ENUM('EXPIRY_SUSPECT', 'PHYSICAL_DAMAGE', 'CUSTOMER_RETURN_INSPECTION', 'COLD_CHAIN_BREACH', 'MANUFACTURER_RECALL', 'OTHER') NOT NULL DEFAULT 'OTHER',
            source_type ENUM('INVENTORY', 'SALES_RETURN', 'GRN_REJECT') NOT NULL DEFAULT 'INVENTORY',
            source_id INT NULL,
            status ENUM('QUARANTINED', 'RELEASED_TO_STOCK', 'SENT_TO_DISPOSAL', 'CANCELLED') NOT NULL DEFAULT 'QUARANTINED',
            notes TEXT NULL,
            created_by INT NULL,
            released_by INT NULL,
            released_at DATETIME NULL,
            release_decision VARCHAR(50) NULL,
            release_notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_medicine_id (medicine_id),
            KEY idx_batch_id (batch_id),
            KEY idx_status (status),
            CONSTRAINT fk_qrn_medicine FOREIGN KEY (medicine_id) REFERENCES medicines (medicine_id) ON DELETE RESTRICT,
            CONSTRAINT fk_qrn_batch FOREIGN KEY (batch_id) REFERENCES medicine_batches (batch_id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql1);

    // 2. pharmacy_disposals
    $sql2 = "
        CREATE TABLE IF NOT EXISTS pharmacy_disposals (
            disposal_id INT AUTO_INCREMENT PRIMARY KEY,
            disposal_no VARCHAR(50) NOT NULL UNIQUE,
            disposal_date DATE NOT NULL,
            medicine_id INT NOT NULL,
            batch_id INT NOT NULL,
            quantity INT NOT NULL,
            reason ENUM('EXPIRED', 'DAMAGED', 'CONTAMINATED', 'RECALLED', 'QUARANTINE_REJECTED') NOT NULL,
            source_type ENUM('EXPIRED_STOCK', 'DAMAGE_REPORT', 'QUARANTINE', 'SALES_RETURN', 'MANUAL') NOT NULL DEFAULT 'MANUAL',
            source_id INT NULL,
            disposal_method ENUM('INCINERATION', 'CHEMICAL_DESTRUCTION', 'SECURE_LANDFILL', 'SUPPLIER_TAKEBACK', 'OTHER') NOT NULL DEFAULT 'INCINERATION',
            witness_name VARCHAR(100) NULL,
            status ENUM('PENDING', 'APPROVED', 'DISPOSED', 'CANCELLED') NOT NULL DEFAULT 'DISPOSED',
            idempotency_key VARCHAR(100) NULL UNIQUE,
            notes TEXT NULL,
            created_by INT NULL,
            approved_by INT NULL,
            executed_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_medicine_id (medicine_id),
            KEY idx_batch_id (batch_id),
            KEY idx_disposal_date (disposal_date),
            KEY idx_status (status),
            CONSTRAINT fk_disp_medicine FOREIGN KEY (medicine_id) REFERENCES medicines (medicine_id) ON DELETE RESTRICT,
            CONSTRAINT fk_disp_batch FOREIGN KEY (batch_id) REFERENCES medicine_batches (batch_id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql2);
};
