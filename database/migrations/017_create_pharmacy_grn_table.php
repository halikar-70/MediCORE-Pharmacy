<?php
// database/migrations/017_create_pharmacy_grn_table.php

return function (PDO $pdo) {
    // 1. Header table: pharmacy_grn
    $sqlHeader = "
        CREATE TABLE IF NOT EXISTS pharmacy_grn (
            grn_id INT AUTO_INCREMENT PRIMARY KEY,
            grn_number VARCHAR(50) NOT NULL UNIQUE,
            grn_date DATE NOT NULL,
            supplier_id INT NOT NULL,
            po_id INT NULL,
            supplier_invoice_no VARCHAR(100) NULL,
            supplier_invoice_date DATE NULL,
            receiving_location VARCHAR(100) NULL DEFAULT 'Main Pharmacy Store',
            subtotal_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            status ENUM('DRAFT', 'POSTED', 'CANCELLED') NOT NULL DEFAULT 'DRAFT',
            idempotency_key VARCHAR(64) NULL UNIQUE,
            notes TEXT NULL,
            received_by INT NULL,
            posted_by INT NULL,
            posted_at DATETIME NULL,
            cancelled_by INT NULL,
            cancelled_at DATETIME NULL,
            cancellation_reason TEXT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_supplier_id (supplier_id),
            KEY idx_po_id (po_id),
            KEY idx_grn_date (grn_date),
            KEY idx_status (status),
            CONSTRAINT fk_grn_supplier FOREIGN KEY (supplier_id) REFERENCES pharmacy_suppliers (supplier_id) ON DELETE RESTRICT,
            CONSTRAINT fk_grn_po FOREIGN KEY (po_id) REFERENCES pharmacy_purchase_orders (po_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlHeader);

    // 2. Line items table: pharmacy_grn_items
    $sqlItems = "
        CREATE TABLE IF NOT EXISTS pharmacy_grn_items (
            grn_item_id INT AUTO_INCREMENT PRIMARY KEY,
            grn_id INT NOT NULL,
            po_item_id INT NULL,
            medicine_id INT NOT NULL,
            batch_id INT NULL,
            batch_number VARCHAR(50) NOT NULL,
            mfg_date DATE NULL,
            expiry_date DATE NOT NULL,
            ordered_qty INT NOT NULL DEFAULT 0,
            received_qty INT NOT NULL DEFAULT 0,
            free_qty INT NOT NULL DEFAULT 0,
            rejected_qty INT NOT NULL DEFAULT 0,
            damaged_qty INT NOT NULL DEFAULT 0,
            accepted_qty INT NOT NULL DEFAULT 0,
            purchase_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            gst_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            mrp DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            sale_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            shelf_location VARCHAR(50) NULL,
            remarks VARCHAR(255) NULL,
            KEY idx_grn_id (grn_id),
            KEY idx_po_item_id (po_item_id),
            KEY idx_medicine_id (medicine_id),
            KEY idx_batch_id (batch_id),
            CONSTRAINT fk_grn_items_grn FOREIGN KEY (grn_id) REFERENCES pharmacy_grn (grn_id) ON DELETE CASCADE,
            CONSTRAINT fk_grn_items_medicine FOREIGN KEY (medicine_id) REFERENCES medicines (medicine_id) ON DELETE RESTRICT,
            CONSTRAINT fk_grn_items_batch FOREIGN KEY (batch_id) REFERENCES medicine_batches (batch_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlItems);
};
