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

    public const DEFAULT_RETENTION_DAYS = 7;
    private static bool $hasAutoPurged = false;

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
        // Automatically purge audit logs older than 1 week (7 days) once per request cycle
        if (!self::$hasAutoPurged) {
            self::$hasAutoPurged = true;
            $this->purgeOldLogs();
        }

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

    /**
     * Retrieve configured retention threshold in days (default: 7 days / 1 week).
     *
     * @return int
     */
    public function getRetentionDays(): int
    {
        try {
            $stmt = $this->pdo->prepare("SELECT setting_value FROM pharmacy_settings WHERE setting_key = 'audit_log_retention_days'");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            if ($val !== false && is_numeric($val) && (int)$val > 0) {
                return (int)$val;
            }
        } catch (Exception $e) {
            // fallback to default
        }
        return self::DEFAULT_RETENTION_DAYS;
    }

    /**
     * Purge audit records older than the retention threshold (default: 7 days / 1 week).
     *
     * @param int|null $retentionDays Number of days to retain (defaults to configured setting or 7)
     * @return int Number of purged records
     */
    public function purgeOldLogs(?int $retentionDays = null): int
    {
        $days = $retentionDays !== null && $retentionDays > 0 ? $retentionDays : $this->getRetentionDays();
        return $this->purgeExpiredLogs($days);
    }

    /**
     * Delete expired audit log records strictly older than the given number of days.
     *
     * @param int $retentionDays Number of days to retain (e.g. 7 for 1 week)
     * @return int Number of purged records
     */
    public function purgeExpiredLogs(int $retentionDays = self::DEFAULT_RETENTION_DAYS): int
    {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM pharmacy_audit_logs 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->bindValue(1, $retentionDays, PDO::PARAM_INT);
            $stmt->execute();
            return (int)$stmt->rowCount();
        } catch (Exception $e) {
            error_log("Failed to purge expired audit logs: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Reset auto-purge flag (primarily for testing and long-running workers).
     */
    public static function resetAutoPurgeFlag(): void
    {
        self::$hasAutoPurged = false;
    }
}
