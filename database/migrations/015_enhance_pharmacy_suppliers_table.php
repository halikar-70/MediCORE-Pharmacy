<?php
// database/migrations/015_enhance_pharmacy_suppliers_table.php

return function (PDO $pdo) {
    // Check existing columns in pharmacy_suppliers
    $existingCols = $pdo->query("DESCRIBE pharmacy_suppliers")->fetchAll(PDO::FETCH_COLUMN);

    $addCols = [
        'supplier_code'       => "VARCHAR(50) NULL AFTER supplier_id",
        'legal_name'          => "VARCHAR(150) NULL AFTER supplier_name",
        'alternate_mobile'    => "VARCHAR(30) NULL AFTER phone",
        'city'                => "VARCHAR(100) NULL AFTER address",
        'state'               => "VARCHAR(100) NULL AFTER city",
        'pincode'             => "VARCHAR(20) NULL AFTER state",
        'drug_licence_no'     => "VARCHAR(100) NULL AFTER pan",
        'drug_licence_type'   => "VARCHAR(50) NULL AFTER drug_licence_no",
        'licence_expiry_date' => "DATE NULL AFTER drug_licence_type",
        'supplier_type'       => "VARCHAR(50) NOT NULL DEFAULT 'Distributor' AFTER licence_expiry_date",
        'credit_days'         => "INT NOT NULL DEFAULT 30 AFTER payment_terms",
        'credit_limit'        => "DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER credit_days",
        'opening_balance'     => "DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER credit_limit",
        'bank_name'           => "VARCHAR(100) NULL AFTER opening_balance",
        'bank_account_no'     => "VARCHAR(50) NULL AFTER bank_name",
        'bank_ifsc'           => "VARCHAR(20) NULL AFTER bank_account_no",
        'created_by'          => "INT NULL AFTER notes",
        'updated_by'          => "INT NULL AFTER created_by"
    ];

    foreach ($addCols as $col => $def) {
        if (!in_array($col, $existingCols, true)) {
            $pdo->exec("ALTER TABLE pharmacy_suppliers ADD COLUMN {$col} {$def}");
        }
    }

    // Modify status enum to include Blocked
    $pdo->exec("ALTER TABLE pharmacy_suppliers MODIFY COLUMN status ENUM('Active', 'Inactive', 'Blocked') NOT NULL DEFAULT 'Active'");

    // Add unique index on supplier_code if not present
    $indices = $pdo->query("SHOW INDEX FROM pharmacy_suppliers WHERE Key_name = 'uq_supplier_code'")->rowCount();
    if ($indices === 0) {
        $pdo->exec("ALTER TABLE pharmacy_suppliers ADD UNIQUE KEY uq_supplier_code (supplier_code)");
    }
};
