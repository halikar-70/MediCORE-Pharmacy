<?php
// config/app.php - Standalone Pharmacy Management System Configuration

if (!defined('APP_NAME')) {
    define('APP_NAME', 'Vatsalya Pharmacy');
}
if (!defined('APP_SHORT_NAME')) {
    define('APP_SHORT_NAME', 'Vatsalya');
}
if (!defined('APP_VERSION')) {
    define('APP_VERSION', '1.0.0-chunk1');
}

// Calculate BASE_URL dynamically based on file depth
if (!defined('BASE_URL')) {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    // Find position of 'Pharmacy' or root
    $pharmacyPos = stripos($scriptDir, '/pharmacy');
    if ($pharmacyPos !== false) {
        $baseUrl = substr($scriptDir, 0, $pharmacyPos + 9) . '/';
    } else {
        $baseUrl = '/';
    }
    define('BASE_URL', $baseUrl);
}

// Ensure timezone
date_default_timezone_set('Asia/Kolkata');

// Server-side error logging configuration (prevents exposure of internal paths/SQL in production)
ini_set('log_errors', '1');
if (getenv('APP_ENV') === 'production') {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

// Supported Payment Modes (Architecture Baseline)
if (!defined('PAYMENT_MODES')) {
    define('PAYMENT_MODES', [
        'CASH'   => 'Cash Payment',
        'UPI'    => 'UPI / QR Code',
        'CARD'   => 'Debit / Credit Card',
        'BANK'   => 'Direct Bank Transfer / NEFT',
        'CREDIT' => 'Hospital / IPD Credit (Cashless)',
        'SPLIT'  => 'Split Tender'
    ]);
}

// PSR-4 Autoloader for Pharmacy namespace
spl_autoload_register(function ($class) {
    $prefix = 'Pharmacy\\';
    $baseDir = dirname(__DIR__) . '/app/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
