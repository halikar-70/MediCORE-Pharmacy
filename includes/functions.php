<?php
// includes/functions.php - Global Pharmacy Utility Functions

if (!function_exists('sanitize')) {
    function sanitize(?string $val): string
    {
        return htmlspecialchars(trim($val ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('redirect')) {
    function redirect(string $path): void
    {
        header("Location: " . $path);
        exit;
    }
}

if (!function_exists('flash')) {
    function flash(string $key, ?string $message = null): ?string
    {
        if ($message === null) {
            $msg = $_SESSION['pharmacy_flash'][$key] ?? null;
            unset($_SESSION['pharmacy_flash'][$key]);
            return $msg;
        }
        $_SESSION['pharmacy_flash'][$key] = $message;
        return null;
    }
}

if (!function_exists('format_currency')) {
    function format_currency($amount, string $symbol = '₹'): string
    {
        return $symbol . ' ' . number_format((float)$amount, 2);
    }
}

if (!function_exists('format_date')) {
    function format_date(?string $date, string $format = 'd M Y'): string
    {
        if (!$date) return '-';
        return date($format, strtotime($date));
    }
}

if (!function_exists('generate_csrf_token')) {
    function generate_csrf_token(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['pharmacy_csrf_token'])) {
            $_SESSION['pharmacy_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['pharmacy_csrf_token'];
    }
}

if (!function_exists('verify_csrf_token')) {
    function verify_csrf_token(?string $token = null): bool
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        $token = $token ?? $_POST['csrf_token'] ?? '';
        return !empty($token) && hash_equals($_SESSION['pharmacy_csrf_token'] ?? '', $token);
    }
}