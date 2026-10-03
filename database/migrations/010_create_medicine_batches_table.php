<?php
// database/migrations/010_create_medicine_batches_table.php

return function (PDO $pdo) {
    $sql = "
        CREATE TABLE IF NOT EXISTS medicine_batches (
            batch_id INT AUTO_INCREMENT PRIMARY KEY,
            medicine_id INT NOT NULL,
            batch_number VARCHAR(50) NOT NULL,
            manufacturing_date DATE NULL,
            expiry_date DATE NOT NULL,
            purchase_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            mrp DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            sale_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            quantity_received INT NOT NULL DEFAULT 0,
            quantity_available INT NOT NULL DEFAULT 0,
            reserved_quantity INT NOT NULL DEFAULT 0,
            damaged_quantity INT NOT NULL DEFAULT 0,
            supplier_id INT NULL,
            purchase_id INT NULL,
            shelf_location VARCHAR(50) NULL,
            status ENUM('Active', 'Near Expiry', 'Expired', 'Blocked', 'Depleted', 'Quarantined', 'Disposed') NOT NULL DEFAULT 'Active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_medicine_id (medicine_id),
            KEY idx_batch_number (batch_number),
            KEY idx_expiry_date (expiry_date),
            KEY idx_status (status),
            KEY idx_quantity_available (quantity_available),
            UNIQUE KEY uq_medicine_batch (medicine_id, batch_number),
            CONSTRAINT fk_batches_medicine FOREIGN KEY (medicine_id) REFERENCES medicines (medicine_id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql);

    // Backfill baseline batches from hospital_db if empty
    $count = (int)$pdo->query("SELECT COUNT(*) FROM medicine_batches")->fetchColumn();
    if ($count === 0) {
        try {
            $hPdo = \Pharmacy\Database\Database::getHospitalConnection();
            if ($hPdo) {
                $check = $hPdo->query("SHOW TABLES LIKE 'medicine_batches'")->rowCount();
                if ($check > 0) {
                    $rows = $hPdo->query("SELECT * FROM medicine_batches")->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($rows)) {
                        $insertStmt = $pdo->prepare("
                            INSERT IGNORE INTO medicine_batches (
                                batch_id, medicine_id, batch_number, manufacturing_date, expiry_date,
                                purchase_price, mrp, sale_price, quantity_received, quantity_available,
                                purchase_id, status, created_at, updated_at
                            ) VALUES (
                                ?, ?, ?, ?, ?,
                                ?, ?, ?, ?, ?,
                                ?, ?, ?, ?
                            )
                        ");

                        foreach ($rows as $r) {
                            $mrp = $r['sale_price'] ?? 0.00;
                            $insertStmt->execute([
                                $r['batch_id'],
                                $r['medicine_id'],
                                $r['batch_number'],
                                $r['manufacturing_date'] ?? null,
                                $r['expiry_date'],
                                $r['purchase_price'] ?? 0.00,
                                $mrp,
                                $r['sale_price'] ?? 0.00,
                                $r['quantity_received'] ?? 0,
                                $r['quantity_available'] ?? 0,
                                $r['purchase_id'] ?? null,
                                $r['status'] ?? 'Active',
                                $r['created_at'] ?? date('Y-m-d H:i:s'),
                                $r['updated_at'] ?? date('Y-m-d H:i:s')
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
