<?php
// database/migrations/007_create_pharmacy_patients_table.php

return function (PDO $pdo) {
    $sql = "
        CREATE TABLE IF NOT EXISTS pharmacy_patients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pharmacy_patient_no VARCHAR(30) NOT NULL UNIQUE,
            hospital_patient_id INT NULL,
            hospital_uhid VARCHAR(50) NULL,
            name VARCHAR(100) NOT NULL,
            mobile VARCHAR(20) NULL,
            gender ENUM('Male', 'Female', 'Other') NOT NULL DEFAULT 'Other',
            date_of_birth DATE NULL,
            address TEXT NULL,
            city VARCHAR(50) NULL,
            state VARCHAR(50) NULL,
            pincode VARCHAR(10) NULL,
            doctor_id INT NULL,
            status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_mobile (mobile),
            INDEX idx_hospital_uhid (hospital_uhid),
            INDEX idx_hospital_patient (hospital_patient_id),
            INDEX idx_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql);
};
