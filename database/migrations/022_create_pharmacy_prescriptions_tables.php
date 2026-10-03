<?php
// database/migrations/022_create_pharmacy_prescriptions_tables.php

return function (PDO $pdo) {
    // 1. Prescriptions Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pharmacy_prescriptions (
            prescription_id INT AUTO_INCREMENT PRIMARY KEY,
            prescription_number VARCHAR(50) NOT NULL UNIQUE,
            prescription_date DATE NOT NULL,
            patient_type ENUM('OPD', 'IPD', 'EXTERNAL') NOT NULL DEFAULT 'OPD',
            patient_id INT NULL,
            patient_name VARCHAR(150) NOT NULL,
            patient_mobile VARCHAR(25) NULL,
            doctor_name VARCHAR(150) NOT NULL,
            doctor_registration_no VARCHAR(100) NULL,
            department VARCHAR(100) NULL,
            ipd_admission_no VARCHAR(50) NULL,
            status ENUM('PENDING', 'VERIFIED', 'PARTIALLY_DISPENSED', 'DISPENSED', 'CANCELLED') NOT NULL DEFAULT 'PENDING',
            verified_by INT NULL,
            verified_at DATETIME NULL,
            notes TEXT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_rx_date (prescription_date),
            INDEX idx_rx_patient_id (patient_id),
            INDEX idx_rx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 2. Prescription Line Items Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pharmacy_prescription_items (
            item_id INT AUTO_INCREMENT PRIMARY KEY,
            prescription_id INT NOT NULL,
            medicine_id INT NOT NULL,
            prescribed_qty INT NOT NULL,
            dispensed_qty INT NOT NULL DEFAULT 0,
            dosage_instructions VARCHAR(255) NULL,
            frequency VARCHAR(50) NULL,
            duration_days INT NULL,
            notes VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_rx_item_rx (prescription_id),
            INDEX idx_rx_item_med (medicine_id),
            CONSTRAINT fk_rx_items_rx FOREIGN KEY (prescription_id) REFERENCES pharmacy_prescriptions (prescription_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
};
