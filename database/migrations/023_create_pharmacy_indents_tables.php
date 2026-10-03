<?php
// database/migrations/023_create_pharmacy_indents_tables.php

return function (PDO $pdo) {
    // 1. IPD Ward Indent Headers
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pharmacy_indents (
            indent_id INT AUTO_INCREMENT PRIMARY KEY,
            indent_number VARCHAR(50) NOT NULL UNIQUE,
            indent_date DATE NOT NULL,
            ward VARCHAR(100) NOT NULL,
            bed_number VARCHAR(50) NULL,
            patient_id INT NULL,
            patient_name VARCHAR(150) NULL,
            ipd_admission_no VARCHAR(50) NULL,
            requested_by VARCHAR(100) NOT NULL,
            priority ENUM('NORMAL', 'URGENT', 'STAT') NOT NULL DEFAULT 'NORMAL',
            status ENUM('SUBMITTED', 'APPROVED', 'PARTIALLY_FULFILLED', 'FULFILLED', 'REJECTED', 'CANCELLED') NOT NULL DEFAULT 'SUBMITTED',
            approved_by INT NULL,
            approved_at DATETIME NULL,
            rejected_by INT NULL,
            rejected_at DATETIME NULL,
            rejection_reason VARCHAR(255) NULL,
            notes TEXT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_indent_date (indent_date),
            INDEX idx_indent_ward (ward),
            INDEX idx_indent_status (status),
            INDEX idx_indent_priority (priority)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 2. IPD Ward Indent Items
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pharmacy_indent_items (
            item_id INT AUTO_INCREMENT PRIMARY KEY,
            indent_id INT NOT NULL,
            medicine_id INT NOT NULL,
            requested_qty INT NOT NULL,
            approved_qty INT NOT NULL DEFAULT 0,
            dispensed_qty INT NOT NULL DEFAULT 0,
            status ENUM('PENDING', 'APPROVED', 'DISPENSED', 'REJECTED') NOT NULL DEFAULT 'PENDING',
            notes VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_indent_item_indent (indent_id),
            INDEX idx_indent_item_med (medicine_id),
            CONSTRAINT fk_indent_items_indent FOREIGN KEY (indent_id) REFERENCES pharmacy_indents (indent_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
};
