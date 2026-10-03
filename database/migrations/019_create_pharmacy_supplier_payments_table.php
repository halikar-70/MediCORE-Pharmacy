<?php
// database/migrations/019_create_pharmacy_supplier_payments_table.php

return function (PDO $pdo) {
    // 1. Header table: pharmacy_supplier_payments
    $sqlHeader = "
        CREATE TABLE IF NOT EXISTS pharmacy_supplier_payments (
            payment_id INT AUTO_INCREMENT PRIMARY KEY,
            payment_number VARCHAR(50) NOT NULL UNIQUE,
            supplier_id INT NOT NULL,
            payment_date DATE NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            payment_mode ENUM('Cash', 'UPI', 'Bank Transfer', 'Cheque', 'NEFT/RTGS', 'Other') NOT NULL DEFAULT 'Bank Transfer',
            reference_no VARCHAR(100) NULL,
            cheque_date DATE NULL,
            bank_name VARCHAR(100) NULL,
            notes TEXT NULL,
            status ENUM('Completed', 'Cancelled') NOT NULL DEFAULT 'Completed',
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_supplier_id (supplier_id),
            KEY idx_payment_date (payment_date),
            KEY idx_status (status),
            CONSTRAINT fk_spay_supplier FOREIGN KEY (supplier_id) REFERENCES pharmacy_suppliers (supplier_id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlHeader);

    // 2. Allocations table: pharmacy_supplier_payment_allocations
    $sqlAlloc = "
        CREATE TABLE IF NOT EXISTS pharmacy_supplier_payment_allocations (
            allocation_id INT AUTO_INCREMENT PRIMARY KEY,
            payment_id INT NOT NULL,
            invoice_id INT NOT NULL,
            allocated_amount DECIMAL(12,2) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_payment_id (payment_id),
            KEY idx_invoice_id (invoice_id),
            CONSTRAINT fk_spay_alloc_payment FOREIGN KEY (payment_id) REFERENCES pharmacy_supplier_payments (payment_id) ON DELETE CASCADE,
            CONSTRAINT fk_spay_alloc_invoice FOREIGN KEY (invoice_id) REFERENCES pharmacy_purchase_invoices (invoice_id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlAlloc);
};
