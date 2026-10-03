<?php
// app/Services/AuditService.php - Immutable Pharmacy Audit Logger

namespace Pharmacy\Services;

use PDO;
use Exception;

class AuditService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Record an auditable event.
     *
     * @param string $action
     * @param string $entityType
     * @param string|null $entityId
     * @param array|null $oldValues
     * @param array|null $newValues
     * @param int|null $userId
     * @return int Inserted audit log ID
     */
    public function log(
        string $action,
        string $entityType,
        ?string $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $userId = null
    ): int {
        if ($userId === null && !empty($_SESSION['pharmacy_user_id'])) {
            $userId = (int)$_SESSION['pharmacy_user_id'];
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'CLI / System';

        $stmt = $this->pdo->prepare("
            INSERT INTO pharmacy_audit_logs 
                (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            $userId,
            strtoupper($action),
            $entityType,
            $entityId,
            $oldValues !== null ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null,
            $newValues !== null ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null,
            $ip,
            substr($ua, 0, 255)
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Helper with ($userId, $action, $entityType, $entityId, $oldValues, $newValues) parameter order.
     */
    public function logAction(
        ?int $userId,
        string $action,
        string $entityType,
        ?string $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): int {
        return $this->log($action, $entityType, $entityId !== null ? (string)$entityId : null, $oldValues, $newValues, $userId);
    }

    /**
     * Retrieve audit records (Read-Only).
     *
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getLogs(int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare("
            SELECT a.*, u.username, u.full_name
            FROM pharmacy_audit_logs a
            LEFT JOIN pharmacy_users u ON a.user_id = u.id
            ORDER BY a.id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get total audit log count.
     *
     * @return int
     */
    public function count(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM pharmacy_audit_logs")->fetchColumn();
    }
}
