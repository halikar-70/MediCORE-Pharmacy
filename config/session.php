<?php
// config/session.php - Standalone Pharmacy Session & Auth Middleware

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../app/Auth/AuthManager.php';
require_once __DIR__ . '/../app/Services/PermissionService.php';

use Pharmacy\Auth\AuthManager;
use Pharmacy\Services\PermissionService;

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Hardened Security Headers & Content Security Policy
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' data: https://cdn.jsdelivr.net https://fonts.gstatic.com; img-src 'self' data: blob:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self';");
}

/**
 * Check if the user is authenticated in Pharmacy.
 */
function auth_check(): bool
{
    return AuthManager::check();
}

/**
 * Get current authenticated user details.
 */
function auth_user(): ?array
{
    if (!auth_check()) {
        return null;
    }
    return [
        'id'        => $_SESSION['pharmacy_user_id'] ?? null,
        'username'  => $_SESSION['pharmacy_username'] ?? '',
        'full_name' => $_SESSION['pharmacy_full_name'] ?? '',
        'role_id'   => $_SESSION['pharmacy_role_id'] ?? null,
        'role_name' => $_SESSION['pharmacy_role_name'] ?? 'Guest'
    ];
}

/**
 * Enforce that the user must be authenticated.
 */
function require_login(string $redirectUrl = 'login.php'): void
{
    if (!auth_check()) {
        $loginUrl = BASE_URL . 'login.php';
        header("Location: " . $loginUrl);
        exit;
    }
}

/**
 * Check if the currently logged-in user possesses a permission.
 */
function has_permission(string $permissionKey): bool
{
    if (!auth_check()) {
        return false;
    }
    global $pdo;
    $service = new PermissionService($pdo);
    return $service->hasPermission($permissionKey, AuthManager::roleId());
}

/**
 * Enforce permission requirement. Halts with 403 Access Denied if missing.
 */
function require_permission(string $permissionKey): void
{
    require_login();

    if (!has_permission($permissionKey)) {
        http_response_code(403);
        $roleName = htmlspecialchars(AuthManager::roleName());
        $permName = htmlspecialchars($permissionKey);
        echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Access Denied - " . APP_NAME . "</title><link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css' rel='stylesheet'></head><body class='bg-light d-flex align-items-center justify-content-center min-vh-100'><div class='card border-0 shadow-sm p-4 text-center' style='max-width:480px;border-radius:16px;'><div class='text-danger fs-1 mb-2'>&#x26A0;</div><h4 class='fw-bold text-dark mb-2'>Access Denied (403)</h4><p class='text-muted small mb-3'>Your role <strong>{$roleName}</strong> does not possess the required permission: <code>{$permName}</code>.</p><a href='" . BASE_URL . "index.php' class='btn btn-primary rounded-pill px-4'>Return to Dashboard</a></div></body></html>";
        exit;
    }
}

/**
 * CSRF token generator.
 */
function csrf_token(): string
{
    return AuthManager::csrfToken();
}

/**
 * CSRF hidden input field.
 */
function csrf_field(): string
{
    $token = htmlspecialchars(csrf_token());
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Verify CSRF token or halt request.
 */
function verify_csrf(): void
{
    if (!AuthManager::verifyCsrf()) {
        http_response_code(403);
        die("<!DOCTYPE html><html><head><meta charset='UTF-8'><title>403 Forbidden - Security Token Expired</title><link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css' rel='stylesheet'></head><body class='bg-light d-flex align-items-center justify-content-center min-vh-100'><div class='card border-0 shadow-sm p-4 text-center' style='max-width:480px;border-radius:12px;'><div class='text-danger fs-1 mb-2'>&#x26A0;</div><h4 class='fw-bold text-dark mb-2'>Session Expired (403)</h4><p class='text-muted small mb-3'>Your security token has expired or is invalid. Please refresh the page and try again.</p><a href='" . BASE_URL . "login.php' class='btn btn-primary rounded-pill px-4'>Back to Login</a></div></body></html>");
    }
}

// Auto-protect non-public pages
$currentScript = basename($_SERVER['PHP_SELF'] ?? '');
if (!in_array($currentScript, ['login.php', 'logout.php']) && empty($is_public_page)) {
    require_login();
}
