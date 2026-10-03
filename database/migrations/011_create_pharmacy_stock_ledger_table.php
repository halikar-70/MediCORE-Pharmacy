<?php
// database/migrations/011_create_pharmacy_stock_ledger_table.php

return function (PDO $pdo) {
    $sql = "
        CREATE TABLE IF NOT EXISTS pharmacy_stock_ledger (
            ledger_id INT AUTO_INCREMENT PRIMARY KEY,
            medicine_id INT NOT NULL,
            batch_id INT NULL,
            transaction_type VARCHAR(50) NOT NULL,
            reference_type VARCHAR(50) NULL,
            reference_id INT NULL,
            reference_no VARCHAR(50) NULL,
            quantity_change INT NOT NULL,
            balance_before INT NOT NULL DEFAULT 0,
            balance_after INT NOT NULL DEFAULT 0,
            unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            reason TEXT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_medicine_id (medicine_id),
            KEY idx_batch_id (batch_id),
            KEY idx_transaction_type (transaction_type),
            KEY idx_reference_no (reference_no),
            KEY idx_created_at (created_at),
            CONSTRAINT fk_ledger_medicine FOREIGN KEY (medicine_id) REFERENCES medicines (medicine_id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql);

    // Backfill baseline ledger from hospital_db if empty
    $count = (int)$pdo->query("SELECT COUNT(*) FROM pharmacy_stock_ledger")->fetchColumn();
    if ($count === 0) {
        try {
            $hPdo = \Pharmacy\Database\Database::getHospitalConnection();
            if ($hPdo) {
                $check = $hPdo->query("SHOW TABLES LIKE 'pharmacy_stock_ledger'")->rowCount();
                if ($check > 0) {
                    $rows = $hPdo->query("SELECT * FROM pharmacy_stock_ledger")->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($rows)) {
                        $insertStmt = $pdo->prepare("
                            INSERT IGNORE INTO pharmacy_stock_ledger (
                                ledger_id, medicine_id, batch_id, transaction_type, reference_id,
                                reference_no, quantity_change, balance_before, balance_after,
                                unit_cost, unit_price, reason, created_by, created_at
                            ) VALUES (
                                ?, ?, ?, ?, ?,
                                ?, ?, ?, ?,
                                ?, ?, ?, ?, ?
                            )
                        ");

                        foreach ($rows as $r) {
                            $insertStmt->execute([
                                $r['ledger_id'],
                                $r['medicine_id'],
                                $r['batch_id'] ?? null,
                                $r['transaction_type'],
                                $r['reference_id'] ?? null,
                                $r['reference_no'] ?? null,
                                $r['quantity_change'],
                                $r['balance_before'],
                                $r['balance_after'],
                                $r['unit_cost'] ?? 0.00,
                                $r['unit_price'] ?? 0.00,
                                $r['reason'] ?? null,
                                $r['created_by'] ?? null,
                                $r['created_at'] ?? date('Y-m-d H:i:s')
                            ]);
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // hospital_db connection optional
        }
    }
};
