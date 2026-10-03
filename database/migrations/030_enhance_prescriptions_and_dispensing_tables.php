<?php
// database/migrations/030_enhance_prescriptions_and_dispensing_tables.php

return function (PDO $pdo) {
    // 1. Safe helper function to add column if not exists
    $addColumnIfNotExists = function(PDO $pdo, string $table, string $column, string $definition) {
        $check = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'")->rowCount();
        if ($check === 0) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    };

    // 2. Enhance pharmacy_prescriptions
    $pdo->exec("
        ALTER TABLE pharmacy_prescriptions 
        MODIFY COLUMN status ENUM('DRAFT', 'ACTIVE', 'PENDING', 'VERIFIED', 'PARTIALLY_DISPENSED', 'DISPENSED', 'COMPLETED', 'DISCONTINUED', 'CANCELLED', 'EXPIRED') NOT NULL DEFAULT 'PENDING'
    ");

    $addColumnIfNotExists($pdo, 'pharmacy_prescriptions', 'start_date', 'DATE NULL AFTER prescription_date');
    $addColumnIfNotExists($pdo, 'pharmacy_prescriptions', 'end_date', 'DATE NULL AFTER start_date');
    $addColumnIfNotExists($pdo, 'pharmacy_prescriptions', 'ward', 'VARCHAR(100) NULL AFTER ipd_admission_no');
    $addColumnIfNotExists($pdo, 'pharmacy_prescriptions', 'bed_number', 'VARCHAR(50) NULL AFTER ward');
    $addColumnIfNotExists($pdo, 'pharmacy_prescriptions', 'discontinued_by', 'INT NULL AFTER notes');
    $addColumnIfNotExists($pdo, 'pharmacy_prescriptions', 'discontinued_at', 'DATETIME NULL AFTER discontinued_by');
    $addColumnIfNotExists($pdo, 'pharmacy_prescriptions', 'discontinue_reason', 'VARCHAR(255) NULL AFTER discontinued_at');
    $addColumnIfNotExists($pdo, 'pharmacy_prescriptions', 'cancelled_by', 'INT NULL AFTER discontinue_reason');
    $addColumnIfNotExists($pdo, 'pharmacy_prescriptions', 'cancelled_at', 'DATETIME NULL AFTER cancelled_by');
    $addColumnIfNotExists($pdo, 'pharmacy_prescriptions', 'cancellation_reason', 'VARCHAR(255) NULL AFTER cancelled_at');
    $addColumnIfNotExists($pdo, 'pharmacy_prescriptions', 'idempotency_key', 'VARCHAR(100) NULL UNIQUE AFTER cancellation_reason');

    // 3. Enhance pharmacy_prescription_items
    $addColumnIfNotExists($pdo, 'pharmacy_prescription_items', 'dose', 'DECIMAL(10,2) NULL AFTER medicine_id');
    $addColumnIfNotExists($pdo, 'pharmacy_prescription_items', 'dose_unit', 'VARCHAR(50) NOT NULL DEFAULT \'mg\' AFTER dose');
    $addColumnIfNotExists($pdo, 'pharmacy_prescription_items', 'route', 'VARCHAR(50) NOT NULL DEFAULT \'ORAL\' AFTER dose_unit');
    $addColumnIfNotExists($pdo, 'pharmacy_prescription_items', 'schedule', 'VARCHAR(100) NULL AFTER route');
    $addColumnIfNotExists($pdo, 'pharmacy_prescription_items', 'start_date', 'DATE NULL AFTER duration_days');
    $addColumnIfNotExists($pdo, 'pharmacy_prescription_items', 'end_date', 'DATE NULL AFTER start_date');
    $addColumnIfNotExists($pdo, 'pharmacy_prescription_items', 'status', 'ENUM(\'ACTIVE\', \'DISCONTINUED\', \'CANCELLED\', \'COMPLETED\') NOT NULL DEFAULT \'ACTIVE\' AFTER notes');
    $addColumnIfNotExists($pdo, 'pharmacy_prescription_items', 'discontinued_at', 'DATETIME NULL AFTER status');
    $addColumnIfNotExists($pdo, 'pharmacy_prescription_items', 'discontinue_reason', 'VARCHAR(255) NULL AFTER discontinued_at');

    // 4. Create pharmacy_prescription_amendments table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pharmacy_prescription_amendments (
            amendment_id INT AUTO_INCREMENT PRIMARY KEY,
            prescription_id INT NOT NULL,
            prescription_item_id INT NULL,
            field_name VARCHAR(100) NOT NULL,
            old_value TEXT NULL,
            new_value TEXT NULL,
            amendment_reason VARCHAR(255) NOT NULL,
            amended_by INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_amend_rx (prescription_id),
            INDEX idx_amend_item (prescription_item_id),
            INDEX idx_amend_user (amended_by),
            CONSTRAINT fk_amend_rx FOREIGN KEY (prescription_id) REFERENCES pharmacy_prescriptions (prescription_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 5. Create pharmacy_dispensing_records table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pharmacy_dispensing_records (
            dispensing_id INT AUTO_INCREMENT PRIMARY KEY,
            dispensing_number VARCHAR(50) NOT NULL UNIQUE,
            prescription_id INT NULL,
            indent_id INT NULL,
            sale_id INT NULL,
            patient_type ENUM('IPD', 'OPD', 'WARD', 'EXTERNAL') NOT NULL DEFAULT 'IPD',
            patient_id INT NULL,
            patient_name VARCHAR(150) NOT NULL,
            ipd_admission_no VARCHAR(50) NULL,
            ward VARCHAR(100) NULL,
            bed VARCHAR(50) NULL,
            dispensing_date DATETIME NOT NULL,
            status ENUM('COMPLETED', 'PARTIAL', 'CANCELLED') NOT NULL DEFAULT 'COMPLETED',
            notes TEXT NULL,
            dispensed_by INT NOT NULL,
            idempotency_key VARCHAR(100) NULL UNIQUE,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_dsp_rx (prescription_id),
            INDEX idx_dsp_indent (indent_id),
            INDEX idx_dsp_sale (sale_id),
            INDEX idx_dsp_patient (patient_id),
            INDEX idx_dsp_ipd (ipd_admission_no),
            INDEX idx_dsp_date (dispensing_date),
            INDEX idx_dsp_dispenser (dispensed_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 6. Create pharmacy_dispensing_batches table (Traceability)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pharmacy_dispensing_batches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            dispensing_id INT NOT NULL,
            prescription_item_id INT NULL,
            indent_item_id INT NULL,
            medicine_id INT NOT NULL,
            batch_id INT NOT NULL,
            batch_number VARCHAR(100) NOT NULL,
            expiry_date DATE NOT NULL,
            dispensed_qty INT NOT NULL,
            unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            stock_ledger_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_dsp_b_record (dispensing_id),
            INDEX idx_dsp_b_rx_item (prescription_item_id),
            INDEX idx_dsp_b_indent_item (indent_item_id),
            INDEX idx_dsp_b_med (medicine_id),
            INDEX idx_dsp_b_batch (batch_id),
            INDEX idx_dsp_b_ledger (stock_ledger_id),
            CONSTRAINT fk_dsp_b_record FOREIGN KEY (dispensing_id) REFERENCES pharmacy_dispensing_records (dispensing_id) ON DELETE CASCADE,
            CONSTRAINT fk_dsp_b_batch FOREIGN KEY (batch_id) REFERENCES medicine_batches (batch_id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
};
