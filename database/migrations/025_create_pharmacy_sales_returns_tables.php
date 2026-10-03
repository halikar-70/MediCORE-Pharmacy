<?php
// database/migrations/025_create_pharmacy_sales_returns_tables.php

return function (PDO $pdo) {
    // 1. pharmacy_sales_returns Header Table
    $sql1 = "
        CREATE TABLE IF NOT EXISTS pharmacy_sales_returns (
            return_id INT AUTO_INCREMENT PRIMARY KEY,
            return_number VARCHAR(50) NOT NULL UNIQUE,
            return_date DATE NOT NULL,
            sale_id INT NOT NULL,
            sale_number VARCHAR(50) NOT NULL,
            patient_id INT NULL,
            customer_name VARCHAR(150) NULL,
            sale_type VARCHAR(50) NOT NULL DEFAULT 'COUNTER',
            total_refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            payment_mode ENUM('CASH', 'UPI', 'CARD', 'CREDIT', 'PATIENT_WALLET') NOT NULL DEFAULT 'CASH',
            refund_status ENUM('PENDING', 'PROCESSED', 'REJECTED') NOT NULL DEFAULT 'PROCESSED',
            reason TEXT NULL,
            status ENUM('DRAFT', 'REQUESTED', 'APPROVED', 'POSTED', 'REJECTED', 'CANCELLED') NOT NULL DEFAULT 'POSTED',
            idempotency_key VARCHAR(100) NULL UNIQUE,
            notes TEXT NULL,
            created_by INT NULL,
            approved_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_sale_id (sale_id),
            KEY idx_sale_number (sale_number),
            KEY idx_return_date (return_date),
            KEY idx_status (status),
            CONSTRAINT fk_sr_sale FOREIGN KEY (sale_id) REFERENCES pharmacy_sales (sale_id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql1);

    // 2. pharmacy_sales_return_items Line Items Table
    $sql2 = "
        CREATE TABLE IF NOT EXISTS pharmacy_sales_return_items (
            item_id INT AUTO_INCREMENT PRIMARY KEY,
            return_id INT NOT NULL,
            sale_item_id INT NOT NULL,
            medicine_id INT NOT NULL,
            batch_id INT NOT NULL,
            sold_quantity INT NOT NULL,
            previously_returned_qty INT NOT NULL DEFAULT 0,
            return_quantity INT NOT NULL,
            unit_price DECIMAL(10,2) NOT NULL,
            discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            refund_amount DECIMAL(12,2) NOT NULL,
            condition_status ENUM('SEALED_INTACT', 'OPENED', 'DAMAGED', 'EXPIRED_POST_SALE') NOT NULL DEFAULT 'SEALED_INTACT',
            restock_decision ENUM('SELLABLE_RESTOCK', 'QUARANTINE', 'NON_SELLABLE', 'DISPOSAL') NOT NULL DEFAULT 'SELLABLE_RESTOCK',
            return_reason VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_return_id (return_id),
            KEY idx_sale_item_id (sale_item_id),
            KEY idx_medicine_id (medicine_id),
            KEY idx_batch_id (batch_id),
            CONSTRAINT fk_sri_return FOREIGN KEY (return_id) REFERENCES pharmacy_sales_returns (return_id) ON DELETE CASCADE,
            CONSTRAINT fk_sri_sale_item FOREIGN KEY (sale_item_id) REFERENCES pharmacy_sale_items (sale_item_id) ON DELETE RESTRICT,
            CONSTRAINT fk_sri_medicine FOREIGN KEY (medicine_id) REFERENCES medicines (medicine_id) ON DELETE RESTRICT,
            CONSTRAINT fk_sri_batch FOREIGN KEY (batch_id) REFERENCES medicine_batches (batch_id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql2);
};
