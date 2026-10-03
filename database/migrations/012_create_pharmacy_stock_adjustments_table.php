<?php
// database/migrations/012_create_pharmacy_stock_adjustments_table.php

return function (PDO $pdo) {
    $sql = "
        CREATE TABLE IF NOT EXISTS pharmacy_stock_adjustments (
            adjustment_id INT AUTO_INCREMENT PRIMARY KEY,
            adjustment_no VARCHAR(50) NOT NULL UNIQUE,
            medicine_id INT NOT NULL,
            batch_id INT NULL,
            adjustment_type ENUM('Increase', 'Decrease', 'Damage', 'Expired', 'Count Reconciliation', 'Found Stock') NOT NULL,
            quantity INT NOT NULL,
            old_quantity INT NOT NULL DEFAULT 0,
            new_quantity INT NOT NULL DEFAULT 0,
            reason TEXT NOT NULL,
            created_by INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_medicine_id (medicine_id),
            KEY idx_batch_id (batch_id),
            KEY idx_adjustment_type (adjustment_type),
            CONSTRAINT fk_adj_medicine FOREIGN KEY (medicine_id) REFERENCES medicines (medicine_id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql);
};
