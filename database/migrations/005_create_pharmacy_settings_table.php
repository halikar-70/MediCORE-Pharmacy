<?php
// database/migrations/005_create_pharmacy_settings_table.php

return function (PDO $pdo) {
    $sql = "
        CREATE TABLE IF NOT EXISTS pharmacy_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT NULL,
            setting_type VARCHAR(50) NOT NULL DEFAULT 'string',
            description TEXT NULL,
            updated_by INT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql);

    // Initial configuration settings per STEP 14
    $settings = [
        ['hospital_name', 'Vatsalya Hospital', 'string', 'Associated hospital healthcare facility name'],
        ['pharmacy_name', 'Vatsalya Central Pharmacy', 'string', 'Official pharmacy entity trade name'],
        ['pharmacy_address', 'Ground Floor, Main Hospital Building, Station Road', 'string', 'Physical premises address for invoicing'],
        ['drug_licence_number', 'DL-2026-MH-01928 / 20B & 21B', 'string', 'State drug control authority retail license number'],
        ['gstin', '27AAAAA0000A1Z5', 'string', 'Goods and Services Tax Identification Number'],
        ['state', 'Maharashtra', 'string', 'State of operation'],
        ['state_code', '27', 'string', 'State GST identification code'],
        ['phone', '+91 9876543210', 'string', 'Pharmacy direct counter telephone number'],
        ['email', 'pharmacy@vatsalyahospital.com', 'string', 'Official email address for purchase and billing queries'],
        ['invoice_prefix', 'PH-INV-', 'string', 'Default billing invoice prefix'],
        ['currency', 'INR', 'string', 'Base operational currency symbol/code'],
        ['default_gst_settings', json_encode(['default_rate' => 12, 'hsn_default' => '3004']), 'json', 'Standard GST tax rate and HSN classification'],
        ['near_expiry_warning_days', '90', 'number', 'Advance alert threshold for near-expiry batch monitoring'],
        ['default_fefo_behaviour', 'strict', 'string', 'First Expiry, First Out stock allocation enforcement policy'],
        ['allow_expiry_override', 'false', 'boolean', 'Whether managers can authorize dispensing expired medicines in emergency'],
        ['default_payment_modes', json_encode(['CASH', 'UPI', 'CARD', 'BANK', 'CREDIT', 'SPLIT']), 'json', 'Active payment channels enabled for billing']
    ];

    $stmt = $pdo->prepare("
        INSERT INTO pharmacy_settings (setting_key, setting_value, setting_type, description, updated_at)
        VALUES (?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE description = VALUES(description)
    ");

    foreach ($settings as $s) {
        $stmt->execute($s);
    }
};
