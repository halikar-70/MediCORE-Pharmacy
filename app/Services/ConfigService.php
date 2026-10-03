<?php
// app/Services/ConfigService.php - Dynamic Pharmacy Settings Provider

namespace Pharmacy\Services;

use PDO;
use Exception;

class ConfigService
{
    private PDO $pdo;
    private static array $cache = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Get a configuration value.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $stmt = $this->pdo->prepare("SELECT setting_value, setting_type FROM pharmacy_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        if (!$row) {
            return $default;
        }

        $value = $this->castValue($row['setting_value'], $row['setting_type']);
        self::$cache[$key] = $value;
        return $value;
    }

    /**
     * Retrieve all configuration entries.
     *
     * @return array
     */
    public function getAll(): array
    {
        $stmt = $this->pdo->query("SELECT setting_key, setting_value, setting_type, description, updated_at FROM pharmacy_settings ORDER BY id ASC");
        $rows = $stmt->fetchAll();
        $result = [];

        foreach ($rows as $r) {
            $result[$r['setting_key']] = [
                'value'       => $this->castValue($r['setting_value'], $r['setting_type']),
                'raw_value'   => $r['setting_value'],
                'type'        => $r['setting_type'],
                'description' => $r['description'],
                'updated_at'  => $r['updated_at']
            ];
            self::$cache[$r['setting_key']] = $result[$r['setting_key']]['value'];
        }

        return $result;
    }

    /**
     * Update a configuration setting.
     *
     * @param string $key
     * @param mixed $value
     * @param int|null $updatedBy
     * @return bool
     */
    public function set(string $key, $value, ?int $updatedBy = null): bool
    {
        $serialized = is_array($value) ? json_encode($value) : (string)$value;

        $stmt = $this->pdo->prepare("
            UPDATE pharmacy_settings 
            SET setting_value = ?, updated_by = ?, updated_at = NOW() 
            WHERE setting_key = ?
        ");
        $success = $stmt->execute([$serialized, $updatedBy, $key]);

        if ($success) {
            unset(self::$cache[$key]);
        }

        return $success;
    }

    private function castValue(?string $val, string $type)
    {
        if ($val === null) {
            return null;
        }

        switch (strtolower($type)) {
            case 'number':
            case 'int':
            case 'integer':
                return is_numeric($val) ? (int)$val : 0;
            case 'float':
                return is_numeric($val) ? (float)$val : 0.0;
            case 'boolean':
            case 'bool':
                return in_array(strtolower($val), ['1', 'true', 'yes', 'on'], true);
            case 'json':
            case 'array':
                $decoded = json_decode($val, true);
                return json_last_error() === JSON_ERROR_NONE ? $decoded : [];
            default:
                return $val;
        }
    }
}
