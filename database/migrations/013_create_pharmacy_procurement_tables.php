<?php
// database/migrations/013_create_pharmacy_procurement_tables.php

return function (PDO $pdo) {
    // 1. pharmacy_suppliers
    $sql1 = "
        CREATE TABLE IF NOT EXISTS pharmacy_suppliers (
            supplier_id INT AUTO_INCREMENT PRIMARY KEY,
            supplier_name VARCHAR(150) NOT NULL,
            contact_person VARCHAR(100) NULL,
            phone VARCHAR(30) NULL,
            email VARCHAR(100) NULL,
            address TEXT NULL,
            gstin VARCHAR(25) NULL,
            pan VARCHAR(20) NULL,
            payment_terms VARCHAR(100) NULL,
            status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_supplier_name (supplier_name),
            KEY idx_phone (phone),
            KEY idx_gstin (gstin),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql1);

    // 2. pharmacy_purchases
    $sql2 = "
        CREATE TABLE IF NOT EXISTS pharmacy_purchases (
            purchase_id INT AUTO_INCREMENT PRIMARY KEY,
            purchase_order_no VARCHAR(50) NULL UNIQUE,
            supplier_id INT NULL,
            invoice_number VARCHAR(50) NULL,
            invoice_date DATE NULL,
            purchase_date DATE NOT NULL,
            total_amount DECIMAL(12,2) NOT NULL,
            gst_amount DECIMAL(10,2) NULL DEFAULT 0.00,
            discount_amount DECIMAL(10,2) NULL DEFAULT 0.00,
            net_amount DECIMAL(12,2) NOT NULL,
            payment_status ENUM('Paid', 'Pending', 'Partial') DEFAULT 'Pending',
            receiving_status ENUM('Pending', 'Partial', 'Received', 'Cancelled') NOT NULL DEFAULT 'Received',
            created_by INT NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_supplier_id (supplier_id),
            KEY idx_invoice_number (invoice_number),
            KEY idx_receiving_status (receiving_status),
            CONSTRAINT fk_purchase_supplier FOREIGN KEY (supplier_id) REFERENCES pharmacy_suppliers (supplier_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql2);

    // 3. pharmacy_purchase_items
    $sql3 = "
        CREATE TABLE IF NOT EXISTS pharmacy_purchase_items (
            item_id INT AUTO_INCREMENT PRIMARY KEY,
            purchase_id INT NOT NULL,
            medicine_id INT NOT NULL,
            batch_id INT NULL,
            batch_number VARCHAR(50) NULL,
            mfg_date DATE NULL,
            expiry_date DATE NULL,
            quantity INT NOT NULL,
            received_quantity INT NOT NULL DEFAULT 0,
            rate DECIMAL(10,2) NOT NULL,
            sale_rate DECIMAL(10,2) NULL,
            gst_percent DECIMAL(5,2) NULL DEFAULT 0.00,
            amount DECIMAL(12,2) NOT NULL,
            KEY idx_purchase_id (purchase_id),
            KEY idx_medicine_id (medicine_id),
            KEY idx_batch_id (batch_id),
            CONSTRAINT fk_pitems_purchase FOREIGN KEY (purchase_id) REFERENCES pharmacy_purchases (purchase_id) ON DELETE CASCADE,
            CONSTRAINT fk_pitems_medicine FOREIGN KEY (medicine_id) REFERENCES medicines (medicine_id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql3);

    // 4. pharmacy_purchase_returns
    $sql4 = "
        CREATE TABLE IF NOT EXISTS pharmacy_purchase_returns (
            return_id INT AUTO_INCREMENT PRIMARY KEY,
            purchase_id INT NOT NULL,
            return_date DATE NOT NULL,
            refund_amount DECIMAL(12,2) NOT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_purchase_id (purchase_id),
            CONSTRAINT fk_preturns_purchase FOREIGN KEY (purchase_id) REFERENCES pharmacy_purchases (purchase_id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql4);

    // 5. pharmacy_purchase_return_items
    $sql5 = "
        CREATE TABLE IF NOT EXISTS pharmacy_purchase_return_items (
            item_id INT AUTO_INCREMENT PRIMARY KEY,
            return_id INT NOT NULL,
            medicine_id INT NOT NULL,
            batch_id INT NULL,
            quantity INT NOT NULL,
            refund_rate DECIMAL(10,2) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            KEY idx_return_id (return_id),
            KEY idx_medicine_id (medicine_id),
            KEY idx_batch_id (batch_id),
            CONSTRAINT fk_pritems_return FOREIGN KEY (return_id) REFERENCES pharmacy_purchase_returns (return_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql5);
};
