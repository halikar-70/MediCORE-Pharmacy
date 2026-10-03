<?php
// database/migrations/026_enhance_purchase_returns_and_batches.php

return function (PDO $pdo) {
    // 1. Add quarantined_quantity and disposed_quantity to medicine_batches
    $mbCols = $pdo->query("DESCRIBE medicine_batches")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('quarantined_quantity', $mbCols, true)) {
        $pdo->exec("ALTER TABLE medicine_batches ADD COLUMN quarantined_quantity INT NOT NULL DEFAULT 0 AFTER damaged_quantity");
    }
    if (!in_array('disposed_quantity', $mbCols, true)) {
        $pdo->exec("ALTER TABLE medicine_batches ADD COLUMN disposed_quantity INT NOT NULL DEFAULT 0 AFTER quarantined_quantity");
    }

    // 2. Enhance pharmacy_purchase_returns
    $prCols = $pdo->query("DESCRIBE pharmacy_purchase_returns")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('return_number', $prCols, true)) {
        $pdo->exec("ALTER TABLE pharmacy_purchase_returns ADD COLUMN return_number VARCHAR(50) NULL UNIQUE AFTER return_id");
    }
    if (!in_array('invoice_id', $prCols, true)) {
        $pdo->exec("ALTER TABLE pharmacy_purchase_returns ADD COLUMN invoice_id INT NULL AFTER return_number");
        $pdo->exec("ALTER TABLE pharmacy_purchase_returns ADD CONSTRAINT fk_pr_invoice FOREIGN KEY (invoice_id) REFERENCES pharmacy_purchase_invoices (invoice_id) ON DELETE RESTRICT");
    }
    if (!in_array('supplier_id', $prCols, true)) {
        $pdo->exec("ALTER TABLE pharmacy_purchase_returns ADD COLUMN supplier_id INT NULL AFTER invoice_id");
        $pdo->exec("ALTER TABLE pharmacy_purchase_returns ADD CONSTRAINT fk_pr_supplier FOREIGN KEY (supplier_id) REFERENCES pharmacy_suppliers (supplier_id) ON DELETE RESTRICT");
    }
    if (!in_array('grn_id', $prCols, true)) {
        $pdo->exec("ALTER TABLE pharmacy_purchase_returns ADD COLUMN grn_id INT NULL AFTER supplier_id");
        $pdo->exec("ALTER TABLE pharmacy_purchase_returns ADD CONSTRAINT fk_pr_grn FOREIGN KEY (grn_id) REFERENCES pharmacy_grn (grn_id) ON DELETE SET NULL");
    }
    if (!in_array('status', $prCols, true)) {
        $pdo->exec("ALTER TABLE pharmacy_purchase_returns ADD COLUMN status ENUM('DRAFT', 'REQUESTED', 'APPROVED', 'POSTED', 'CANCELLED') NOT NULL DEFAULT 'POSTED' AFTER refund_amount");
    }
    if (!in_array('reason', $prCols, true)) {
        $pdo->exec("ALTER TABLE pharmacy_purchase_returns ADD COLUMN reason TEXT NULL AFTER status");
    }
    if (!in_array('notes', $prCols, true)) {
        $pdo->exec("ALTER TABLE pharmacy_purchase_returns ADD COLUMN notes TEXT NULL AFTER reason");
    }
    if (!in_array('approved_by', $prCols, true)) {
        $pdo->exec("ALTER TABLE pharmacy_purchase_returns ADD COLUMN approved_by INT NULL AFTER created_by");
    }
    if (!in_array('idempotency_key', $prCols, true)) {
        $pdo->exec("ALTER TABLE pharmacy_purchase_returns ADD COLUMN idempotency_key VARCHAR(100) NULL UNIQUE AFTER notes");
    }

    // Modify purchase_id to allow NULL so Chunk 3 invoices can be directly referenced
    try {
        $pdo->exec("ALTER TABLE pharmacy_purchase_returns MODIFY COLUMN purchase_id INT NULL");
    } catch (Exception $e) {
        // Ignore if already nullable
    }

    // 3. Enhance pharmacy_purchase_return_items
    $priCols = $pdo->query("DESCRIBE pharmacy_purchase_return_items")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('reason', $priCols, true)) {
        $pdo->exec("ALTER TABLE pharmacy_purchase_return_items ADD COLUMN reason VARCHAR(255) NULL AFTER amount");
    }
    if (!in_array('condition_status', $priCols, true)) {
        $pdo->exec("ALTER TABLE pharmacy_purchase_return_items ADD COLUMN condition_status VARCHAR(50) NOT NULL DEFAULT 'DEFECTIVE' AFTER reason");
    }
    if (!in_array('received_quantity', $priCols, true)) {
        $pdo->exec("ALTER TABLE pharmacy_purchase_return_items ADD COLUMN received_quantity INT NOT NULL DEFAULT 0 AFTER quantity");
    }
};
