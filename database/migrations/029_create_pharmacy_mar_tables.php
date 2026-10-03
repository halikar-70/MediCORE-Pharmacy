<?php
// database/migrations/029_create_pharmacy_mar_tables.php

return function (PDO $pdo) {
    // 1. Medication Administration Record (MAR) Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pharmacy_mar_records (
            mar_id INT AUTO_INCREMENT PRIMARY KEY,
            mar_number VARCHAR(50) NOT NULL UNIQUE,
            prescription_id INT NOT NULL,
            prescription_item_id INT NOT NULL,
            patient_id INT NULL,
            patient_name VARCHAR(150) NOT NULL,
            ipd_admission_no VARCHAR(50) NULL,
            ward VARCHAR(100) NULL,
            bed_number VARCHAR(50) NULL,
            medicine_id INT NOT NULL,
            scheduled_date DATE NOT NULL,
            scheduled_time TIME NOT NULL,
            scheduled_dose DECIMAL(10,2) NOT NULL DEFAULT 1.00,
            dose_unit VARCHAR(50) NOT NULL DEFAULT 'tablet',
            route VARCHAR(50) NOT NULL DEFAULT 'ORAL',
            status ENUM('SCHEDULED', 'GIVEN', 'HELD', 'REFUSED', 'MISSED', 'OMITTED', 'CANCELLED') NOT NULL DEFAULT 'SCHEDULED',
            actual_admin_time DATETIME NULL,
            administered_qty INT NOT NULL DEFAULT 0,
            administered_dose DECIMAL(10,2) NULL,
            administered_by INT NULL,
            witnessed_by VARCHAR(100) NULL,
            not_given_reason VARCHAR(255) NULL,
            notes TEXT NULL,
            idempotency_key VARCHAR(100) NULL UNIQUE,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_mar_prescription (prescription_id),
            INDEX idx_mar_prescription_item (prescription_item_id),
            INDEX idx_mar_patient (patient_id),
            INDEX idx_mar_ipd (ipd_admission_no),
            INDEX idx_mar_scheduled (scheduled_date, scheduled_time),
            INDEX idx_mar_status (status),
            INDEX idx_mar_medicine (medicine_id),
            CONSTRAINT fk_mar_prescription FOREIGN KEY (prescription_id) REFERENCES pharmacy_prescriptions (prescription_id) ON DELETE CASCADE,
            CONSTRAINT fk_mar_medicine FOREIGN KEY (medicine_id) REFERENCES medicines (medicine_id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 2. MAR Corrections Audit Table (Non-destructive correction history)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pharmacy_mar_corrections (
            correction_id INT AUTO_INCREMENT PRIMARY KEY,
            mar_id INT NOT NULL,
            old_status VARCHAR(50) NOT NULL,
            new_status VARCHAR(50) NOT NULL,
            old_administered_qty INT NOT NULL DEFAULT 0,
            new_administered_qty INT NOT NULL DEFAULT 0,
            old_administered_dose DECIMAL(10,2) NULL,
            new_administered_dose DECIMAL(10,2) NULL,
            correction_reason VARCHAR(255) NOT NULL,
            corrected_by INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_corr_mar (mar_id),
            INDEX idx_corr_user (corrected_by),
            CONSTRAINT fk_corr_mar FOREIGN KEY (mar_id) REFERENCES pharmacy_mar_records (mar_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
};
