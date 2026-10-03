<?php
// database/migrations/009_create_medicines_table.php

return function (PDO $pdo) {
    $sql = "
        CREATE TABLE IF NOT EXISTS medicines (
            medicine_id INT AUTO_INCREMENT PRIMARY KEY,
            medicine_name VARCHAR(150) NOT NULL,
            generic_name VARCHAR(150) NULL,
            composition TEXT NULL,
            strength VARCHAR(50) NULL,
            dosage_form VARCHAR(50) NULL,
            brand_name VARCHAR(150) NULL,
            category VARCHAR(100) NULL,
            unit VARCHAR(50) NULL DEFAULT 'Unit',
            pack_size VARCHAR(50) NOT NULL DEFAULT '1',
            rack_location VARCHAR(50) NULL,
            shelf VARCHAR(50) NULL,
            box_bin VARCHAR(50) NULL,
            schedule_type VARCHAR(50) NOT NULL DEFAULT 'General',
            manufacturer VARCHAR(150) NULL,
            hsn_code VARCHAR(50) NULL,
            gst_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            purchase_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            stock_quantity INT NOT NULL DEFAULT 0,
            reorder_level INT NOT NULL DEFAULT 10,
            min_stock INT NOT NULL DEFAULT 5,
            max_stock INT NOT NULL DEFAULT 1000,
            reorder_qty INT NOT NULL DEFAULT 50,
            barcode VARCHAR(100) NULL,
            alternate_barcode VARCHAR(100) NULL,
            expiry_date DATE NULL,
            batch_number VARCHAR(50) NULL,
            status ENUM('Active', 'Inactive', 'Discontinued') NOT NULL DEFAULT 'Active',
            created_by INT NULL,
            updated_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL,
            KEY idx_medicine_name (medicine_name),
            KEY idx_generic_name (generic_name),
            UNIQUE KEY uq_barcode (barcode),
            KEY idx_category (category),
            KEY idx_status (status),
            KEY idx_stock_quantity (stock_quantity),
            KEY idx_rack_location (rack_location)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql);

    // Backfill baseline medicines from hospital_db if empty and hospital_db exists
    $count = (int)$pdo->query("SELECT COUNT(*) FROM medicines")->fetchColumn();
    if ($count === 0) {
        try {
            $hPdo = \Pharmacy\Database\Database::getHospitalConnection();
            if ($hPdo) {
                $check = $hPdo->query("SHOW TABLES LIKE 'medicines'")->rowCount();
                if ($check > 0) {
                    $rows = $hPdo->query("SELECT * FROM medicines")->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($rows)) {
                        $insertStmt = $pdo->prepare("
                            INSERT INTO medicines (
                                medicine_id, medicine_name, generic_name, brand_name, category,
                                unit, pack_size, rack_location, shelf, schedule_type,
                                manufacturer, batch_number, price, purchase_price, gst_percent,
                                hsn_code, stock_quantity, reorder_level, expiry_date, status,
                                created_at, updated_at
                            ) VALUES (
                                ?, ?, ?, ?, ?,
                                ?, ?, ?, ?, ?,
                                ?, ?, ?, ?, ?,
                                ?, ?, ?, ?, ?,
                                NOW(), NOW()
                            )
                        ");

                        foreach ($rows as $r) {
                            $status = in_array($r['status'] ?? 'Active', ['Active', 'Inactive', 'Discontinued']) ? $r['status'] : 'Active';
                            $insertStmt->execute([
                                $r['medicine_id'],
                                $r['medicine_name'],
                                $r['generic_name'] ?? null,
                                $r['brand_name'] ?? null,
                                $r['category'] ?? null,
                                $r['unit'] ?? 'Unit',
                                $r['pack_size'] ?? '1',
                                $r['rack_location'] ?? null,
                                $r['rack_location'] ?? null,
                                $r['schedule_type'] ?? 'General',
                                $r['manufacturer'] ?? null,
                                $r['batch_number'] ?? null,
                                $r['price'] ?? 0.00,
                                $r['purchase_price'] ?? 0.00,
                                $r['gst_percent'] ?? 0.00,
                                $r['hsn_code'] ?? null,
                                $r['stock_quantity'] ?? 0,
                                $r['reorder_level'] ?? 10,
                                $r['expiry_date'] ?? null,
                                $status
                            ]);
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // hospital_db connection optional during fresh standalone setup
        }
    }
};
