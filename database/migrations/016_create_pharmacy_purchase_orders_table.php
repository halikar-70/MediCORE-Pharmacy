<?php
// database/migrations/016_create_pharmacy_purchase_orders_table.php

return function (PDO $pdo) {
    // 1. Header table: pharmacy_purchase_orders
    $sqlHeader = "
        CREATE TABLE IF NOT EXISTS pharmacy_purchase_orders (
            po_id INT AUTO_INCREMENT PRIMARY KEY,
            po_number VARCHAR(50) NOT NULL UNIQUE,
            po_date DATE NOT NULL,
            supplier_id INT NOT NULL,
            expected_delivery_date DATE NULL,
            payment_terms VARCHAR(100) NULL,
            status ENUM('DRAFT', 'SUBMITTED', 'APPROVED', 'PARTIALLY_RECEIVED', 'FULLY_RECEIVED', 'CANCELLED', 'CLOSED') NOT NULL DEFAULT 'DRAFT',
            subtotal_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            notes TEXT NULL,
            approved_by INT NULL,
            approved_at DATETIME NULL,
            cancelled_by INT NULL,
            cancelled_at DATETIME NULL,
            cancellation_reason TEXT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_supplier_id (supplier_id),
            KEY idx_po_date (po_date),
            KEY idx_status (status),
            CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES pharmacy_suppliers (supplier_id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlHeader);

    // 2. Line items table: pharmacy_purchase_order_items
    $sqlItems = "
        CREATE TABLE IF NOT EXISTS pharmacy_purchase_order_items (
            po_item_id INT AUTO_INCREMENT PRIMARY KEY,
            po_id INT NOT NULL,
            medicine_id INT NOT NULL,
            pack_size VARCHAR(50) NULL DEFAULT '1',
            requested_qty INT NOT NULL,
            free_qty INT NOT NULL DEFAULT 0,
            received_qty INT NOT NULL DEFAULT 0,
            purchase_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            gst_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            expected_mrp DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            expected_sale_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            notes VARCHAR(255) NULL,
            KEY idx_po_id (po_id),
            KEY idx_medicine_id (medicine_id),
            CONSTRAINT fk_po_items_po FOREIGN KEY (po_id) REFERENCES pharmacy_purchase_orders (po_id) ON DELETE CASCADE,
            CONSTRAINT fk_po_items_medicine FOREIGN KEY (medicine_id) REFERENCES medicines (medicine_id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlItems);
};
