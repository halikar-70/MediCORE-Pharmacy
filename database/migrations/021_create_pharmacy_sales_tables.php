<?php
// database/migrations/021_create_pharmacy_sales_tables.php

return function (PDO $pdo) {
    // 1. Sales Master / Invoices Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pharmacy_sales (
            sale_id INT AUTO_INCREMENT PRIMARY KEY,
            sale_number VARCHAR(50) NOT NULL UNIQUE,
            sale_type ENUM('COUNTER_SALE', 'PRESCRIPTION_SALE', 'IPD_SALE') NOT NULL DEFAULT 'COUNTER_SALE',
            sale_date DATE NOT NULL,
            patient_type ENUM('WALK_IN', 'REGISTERED', 'IPD') NOT NULL DEFAULT 'WALK_IN',
            patient_id INT NULL,
            customer_name VARCHAR(150) NOT NULL,
            customer_mobile VARCHAR(25) NULL,
            doctor_name VARCHAR(150) NULL,
            prescription_id INT NULL,
            ipd_admission_id VARCHAR(50) NULL,
            ipd_ward VARCHAR(50) NULL,
            ipd_bed VARCHAR(50) NULL,
            subtotal_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            discount_reason VARCHAR(255) NULL,
            discount_authorized_by INT NULL,
            taxable_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            gst_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            round_off DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            grand_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            balance_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            payment_status ENUM('UNPAID', 'PARTIALLY_PAID', 'PAID', 'CREDIT', 'CANCELLED') NOT NULL DEFAULT 'UNPAID',
            payment_mode VARCHAR(50) NOT NULL DEFAULT 'CASH',
            idempotency_key VARCHAR(100) NULL UNIQUE,
            status ENUM('DRAFT', 'COMPLETED', 'CANCELLED') NOT NULL DEFAULT 'COMPLETED',
            notes TEXT NULL,
            created_by INT NULL,
            cancelled_by INT NULL,
            cancelled_at DATETIME NULL,
            cancellation_reason VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_sale_type (sale_type),
            INDEX idx_sale_date (sale_date),
            INDEX idx_customer_mobile (customer_mobile),
            INDEX idx_patient_id (patient_id),
            INDEX idx_prescription_id (prescription_id),
            INDEX idx_payment_status (payment_status),
            INDEX idx_created_by (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 2. Sale Items Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pharmacy_sale_items (
            sale_item_id INT AUTO_INCREMENT PRIMARY KEY,
            sale_id INT NOT NULL,
            medicine_id INT NOT NULL,
            dosage_form VARCHAR(50) NULL,
            pack_size VARCHAR(50) NULL,
            quantity INT NOT NULL,
            unit_price DECIMAL(10,2) NOT NULL,
            mrp DECIMAL(10,2) NOT NULL,
            discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            taxable_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            gst_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            gst_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            line_total DECIMAL(12,2) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sale_id (sale_id),
            INDEX idx_medicine_id (medicine_id),
            CONSTRAINT fk_sale_items_sale FOREIGN KEY (sale_id) REFERENCES pharmacy_sales (sale_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 3. Sale Item Batches (Granular multi-batch traceability for returns and audit)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pharmacy_sale_item_batches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sale_item_id INT NOT NULL,
            sale_id INT NOT NULL,
            medicine_id INT NOT NULL,
            batch_id INT NOT NULL,
            batch_number VARCHAR(100) NOT NULL,
            expiry_date DATE NOT NULL,
            allocated_quantity INT NOT NULL,
            unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sale_item_id (sale_item_id),
            INDEX idx_batch_id (batch_id),
            INDEX idx_medicine_id (medicine_id),
            CONSTRAINT fk_sale_item_batches_item FOREIGN KEY (sale_item_id) REFERENCES pharmacy_sale_items (sale_item_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 4. Sale Payment Receipts Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pharmacy_sale_payments (
            payment_id INT AUTO_INCREMENT PRIMARY KEY,
            payment_number VARCHAR(50) NOT NULL UNIQUE,
            sale_id INT NOT NULL,
            payment_date DATE NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            payment_mode ENUM('CASH', 'UPI', 'CARD', 'BANK_TRANSFER', 'CHEQUE', 'CREDIT') NOT NULL DEFAULT 'CASH',
            reference_number VARCHAR(100) NULL,
            notes VARCHAR(255) NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sale_id (sale_id),
            INDEX idx_payment_date (payment_date),
            CONSTRAINT fk_sale_payments_sale FOREIGN KEY (sale_id) REFERENCES pharmacy_sales (sale_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
};
