<?php
// database/migrations/018_create_pharmacy_purchase_invoices_table.php

return function (PDO $pdo) {
    // 1. Header table: pharmacy_purchase_invoices
    $sqlHeader = "
        CREATE TABLE IF NOT EXISTS pharmacy_purchase_invoices (
            invoice_id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_number VARCHAR(50) NOT NULL UNIQUE,
            supplier_invoice_no VARCHAR(100) NOT NULL,
            supplier_id INT NOT NULL,
            po_id INT NULL,
            grn_id INT NULL,
            invoice_date DATE NOT NULL,
            due_date DATE NULL,
            taxable_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            gst_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            other_charges DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            round_off DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            grand_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            outstanding_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            payment_status ENUM('UNPAID', 'PARTIALLY_PAID', 'PAID', 'OVERDUE', 'CANCELLED') NOT NULL DEFAULT 'UNPAID',
            match_status ENUM('MATCHED', 'QUANTITY_MISMATCH', 'PRICE_MISMATCH', 'TAX_MISMATCH', 'PENDING_REVIEW') NOT NULL DEFAULT 'MATCHED',
            payment_terms VARCHAR(100) NULL,
            notes TEXT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_supplier_id (supplier_id),
            KEY idx_supplier_invoice_no (supplier_invoice_no),
            KEY idx_po_id (po_id),
            KEY idx_grn_id (grn_id),
            KEY idx_invoice_date (invoice_date),
            KEY idx_payment_status (payment_status),
            CONSTRAINT fk_pinv_supplier FOREIGN KEY (supplier_id) REFERENCES pharmacy_suppliers (supplier_id) ON DELETE RESTRICT,
            CONSTRAINT fk_pinv_po FOREIGN KEY (po_id) REFERENCES pharmacy_purchase_orders (po_id) ON DELETE SET NULL,
            CONSTRAINT fk_pinv_grn FOREIGN KEY (grn_id) REFERENCES pharmacy_grn (grn_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlHeader);

    // 2. Junction table: pharmacy_purchase_invoice_grns (supports 1 Invoice -> Many GRNs)
    $sqlJunction = "
        CREATE TABLE IF NOT EXISTS pharmacy_purchase_invoice_grns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT NOT NULL,
            grn_id INT NOT NULL,
            UNIQUE KEY uq_invoice_grn (invoice_id, grn_id),
            CONSTRAINT fk_pig_invoice FOREIGN KEY (invoice_id) REFERENCES pharmacy_purchase_invoices (invoice_id) ON DELETE CASCADE,
            CONSTRAINT fk_pig_grn FOREIGN KEY (grn_id) REFERENCES pharmacy_grn (grn_id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlJunction);

    // 3. Line items table: pharmacy_purchase_invoice_items
    $sqlItems = "
        CREATE TABLE IF NOT EXISTS pharmacy_purchase_invoice_items (
            invoice_item_id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT NOT NULL,
            grn_item_id INT NULL,
            medicine_id INT NOT NULL,
            batch_id INT NULL,
            batch_number VARCHAR(50) NULL,
            quantity INT NOT NULL,
            free_qty INT NOT NULL DEFAULT 0,
            purchase_rate DECIMAL(10,2) NOT NULL,
            discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            gst_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            taxable_amount DECIMAL(12,2) NOT NULL,
            gst_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            line_total DECIMAL(12,2) NOT NULL,
            KEY idx_invoice_id (invoice_id),
            KEY idx_grn_item_id (grn_item_id),
            KEY idx_medicine_id (medicine_id),
            KEY idx_batch_id (batch_id),
            CONSTRAINT fk_pinv_items_invoice FOREIGN KEY (invoice_id) REFERENCES pharmacy_purchase_invoices (invoice_id) ON DELETE CASCADE,
            CONSTRAINT fk_pinv_items_medicine FOREIGN KEY (medicine_id) REFERENCES medicines (medicine_id) ON DELETE RESTRICT,
            CONSTRAINT fk_pinv_items_batch FOREIGN KEY (batch_id) REFERENCES medicine_batches (batch_id) ON DELETE SET NULL,
            CONSTRAINT fk_pinv_items_grn_item FOREIGN KEY (grn_item_id) REFERENCES pharmacy_grn_items (grn_item_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlItems);
};
