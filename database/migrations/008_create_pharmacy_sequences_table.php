<?php
// database/migrations/008_create_pharmacy_sequences_table.php

return function (PDO $pdo) {
    $sql = "
        CREATE TABLE IF NOT EXISTS pharmacy_sequences (
            sequence_key VARCHAR(50) PRIMARY KEY,
            prefix VARCHAR(20) NOT NULL,
            current_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
            pad_length INT NOT NULL DEFAULT 6,
            description VARCHAR(150) NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql);

    // Baseline sequence definitions
    $sequences = [
        ['PATIENT', 'PP-', 0, 6, 'Pharmacy Patient ID sequence'],
        ['COUNTER_SALE', 'CS-', 0, 6, 'Counter OPD sale invoice sequence'],
        ['REGULAR_SALE', 'RS-', 0, 6, 'Regular IPD credit sale sequence'],
        ['PRESCRIPTION_SALE', 'RXS-', 0, 6, 'Prescription dispensed sale sequence'],
        ['IPD_INDENT', 'IND-', 0, 6, 'IPD Ward Indent dispensing sequence'],
        ['PURCHASE_ORDER', 'PO-', 0, 6, 'Supplier purchase order sequence'],
        ['PURCHASE_INVOICE', 'PINV-', 0, 6, 'Supplier purchase invoice / GRN sequence'],
        ['SALE_RETURN', 'SRT-', 0, 6, 'Customer sales return credit note sequence'],
        ['PURCHASE_RETURN', 'PRT-', 0, 6, 'Supplier return debit note sequence'],
        ['STOCK_ADJUSTMENT', 'ADJ-', 0, 6, 'Inventory audit adjustment sequence']
    ];

    $stmt = $pdo->prepare("
        INSERT INTO pharmacy_sequences (sequence_key, prefix, current_value, pad_length, description, updated_at)
        VALUES (?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE prefix = VALUES(prefix), pad_length = VALUES(pad_length), description = VALUES(description)
    ");

    foreach ($sequences as $seq) {
        $stmt->execute($seq);
    }
};
