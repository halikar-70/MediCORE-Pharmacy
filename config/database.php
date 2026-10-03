<?php
// config/database.php - Pharmacy Database Entrypoint

require_once __DIR__ . '/../app/Database/Database.php';

use Pharmacy\Database\Database;

try {
    $pdo = Database::getPharmacyConnection();
} catch (Exception $e) {
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "Database Error: " . $e->getMessage() . "\n");
        exit(1);
    } else {
        http_response_code(500);
        die("<h1>Service Temporarily Unavailable</h1><p>Pharmacy database connection is currently unavailable. Please contact the administrator.</p>");
    }
}