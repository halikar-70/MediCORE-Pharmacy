<?php
// app/Services/PermissionService.php - Server-Side RBAC Enforcement

namespace Pharmacy\Services;

use PDO;
use Exception;

class PermissionService
{
    private PDO $pdo;
    private static array $rolePermCache = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Check if a role possesses a specific permission.
     *
     * @param string $permissionKey
     * @param int|null $roleId
     * @return bool
     */
    public function hasPermission(string $permissionKey, ?int $roleId = null): bool
    {
        if ($roleId === null) {
            $roleId = $_SESSION['pharmacy_role_id'] ?? null;
        }

        if (!$roleId) {
            return false;
        }

        // Check if role is ADMIN (role_id 1 or role_name 'ADMIN')
        if ($this->isAdminRole($roleId)) {
            return true;
        }

        $perms = $this->getPermissionsForRole($roleId);
        return in_array($permissionKey, $perms, true);
    }

    /**
     * Check if role is Admin.
     *
     * @param int $roleId
     * @return bool
     */
    public function isAdminRole(int $roleId): bool
    {
        $stmt = $this->pdo->prepare("SELECT role_name FROM pharmacy_roles WHERE id = ?");
        $stmt->execute([$roleId]);
        $name = $stmt->fetchColumn();
        return strtoupper((string)$name) === 'ADMIN';
    }

    /**
     * Get all permission keys granted to a specific role.
     *
     * @param int $roleId
     * @return array
     */
    public function getPermissionsForRole(int $roleId): array
    {
        if (isset(self::$rolePermCache[$roleId])) {
            return self::$rolePermCache[$roleId];
        }

        $stmt = $this->pdo->prepare("
            SELECT p.permission_key
            FROM pharmacy_role_permissions rp
            JOIN pharmacy_permissions p ON rp.permission_id = p.id
            WHERE rp.role_id = ?
        ");
        $stmt->execute([$roleId]);
        $keys = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        self::$rolePermCache[$roleId] = $keys;
        return $keys;
    }

    /**
     * Get all roles.
     *
     * @return array
     */
    public function getAllRoles(): array
    {
        return $this->pdo->query("SELECT * FROM pharmacy_roles ORDER BY id ASC")->fetchAll();
    }

    /**
     * Get all available permissions.
     *
     * @return array
     */
    public function getAllPermissions(): array
    {
        return $this->pdo->query("SELECT * FROM pharmacy_permissions ORDER BY id ASC")->fetchAll();
    }
}
