<?php
// app/Auth/AuthManager.php - Secure Pharmacy Authentication Manager

namespace Pharmacy\Auth;

require_once __DIR__ . '/../Services/AuditService.php';

use Pharmacy\Services\AuditService;
use PDO;
use Exception;

class AuthManager
{
    private PDO $pdo;
    private AuditService $auditService;

    public function __construct(PDO $pdo, ?AuditService $auditService = null)
    {
        $this->pdo = $pdo;
        $this->auditService = $auditService ?: new AuditService($pdo);
    }

    /**
     * Authenticate a user by username and password against pharmacy_users.
     *
     * @param string $username
     * @param string $password
     * @return array [success => bool, user => array|null, error => string|null]
     */
    public function attempt(string $username, string $password): array
    {
        $cleanUsername = trim($username);

        if ($cleanUsername === '' || $password === '') {
            return [
                'success' => false,
                'user'    => null,
                'error'   => 'Please enter both username and password.'
            ];
        }

        $stmt = $this->pdo->prepare("
            SELECT u.*, r.role_name 
            FROM pharmacy_users u
            JOIN pharmacy_roles r ON u.role_id = r.id
            WHERE u.username = ?
            LIMIT 1
        ");
        $stmt->execute([$cleanUsername]);
        $user = $stmt->fetch();

        if (!$user) {
            return [
                'success' => false,
                'user'    => null,
                'error'   => 'Invalid username or password.'
            ];
        }

        // Check if user account is deactivated
        if ((int)$user['status'] !== 1) {
            return [
                'success' => false,
                'user'    => null,
                'error'   => 'Your account is deactivated. Please contact the administrator.'
            ];
        }

        // Verify bcrypt password hash
        if (!password_verify($password, $user['password_hash'])) {
            return [
                'success' => false,
                'user'    => null,
                'error'   => 'Invalid username or password.'
            ];
        }

        // Authentication Success: Regenerate session ID
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION['pharmacy_logged_in'] = true;
        $_SESSION['pharmacy_user_id'] = (int)$user['id'];
        $_SESSION['pharmacy_username'] = $user['username'];
        $_SESSION['pharmacy_full_name'] = $user['full_name'];
        $_SESSION['pharmacy_role_id'] = (int)$user['role_id'];
        $_SESSION['pharmacy_role_name'] = $user['role_name'];

        // Update last login timestamp
        $updateStmt = $this->pdo->prepare("UPDATE pharmacy_users SET last_login_at = NOW() WHERE id = ?");
        $updateStmt->execute([$user['id']]);

        // Record audit log
        $this->auditService->log(
            'USER_LOGIN',
            'pharmacy_users',
            (string)$user['id'],
            null,
            ['username' => $user['username'], 'role' => $user['role_name']],
            (int)$user['id']
        );

        return [
            'success' => true,
            'user'    => $user,
            'error'   => null
        ];
    }

    /**
     * Sign out current user and destroy active session.
     */
    public function logout(): void
    {
        if (!empty($_SESSION['pharmacy_user_id'])) {
            $this->auditService->log(
                'USER_LOGOUT',
                'pharmacy_users',
                (string)$_SESSION['pharmacy_user_id'],
                null,
                null,
                (int)$_SESSION['pharmacy_user_id']
            );
        }

        $_SESSION = [];

        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /**
     * Check if a user is actively authenticated in the pharmacy system.
     */
    public static function check(): bool
    {
        return !empty($_SESSION['pharmacy_logged_in']) && !empty($_SESSION['pharmacy_user_id']);
    }

    /**
     * Get currently logged-in user ID.
     */
    public static function id(): ?int
    {
        return $_SESSION['pharmacy_user_id'] ?? null;
    }

    /**
     * Get currently logged-in user ID (convenience alias for id()).
     */
    public static function userId(): ?int
    {
        return self::id();
    }

    /**
     * Get current user role ID.
     */
    public static function roleId(): ?int
    {
        return $_SESSION['pharmacy_role_id'] ?? null;
    }

    /**
     * Get current user role name.
     */
    public static function roleName(): string
    {
        return $_SESSION['pharmacy_role_name'] ?? 'Guest';
    }

    /**
     * Get current full name.
     */
    public static function fullName(): string
    {
        return $_SESSION['pharmacy_full_name'] ?? ($_SESSION['pharmacy_username'] ?? 'User');
    }

    /**
     * Generate CSRF token.
     */
    public static function csrfToken(): string
    {
        if (empty($_SESSION['pharmacy_csrf_token'])) {
            $_SESSION['pharmacy_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['pharmacy_csrf_token'];
    }

    /**
     * Validate submitted CSRF token.
     */
    public static function verifyCsrf(?string $token = null): bool
    {
        $submitted = $token ?: ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        $expected = $_SESSION['pharmacy_csrf_token'] ?? '';

        if (!$submitted || !$expected) {
            return false;
        }

        return hash_equals($expected, $submitted);
    }
}
