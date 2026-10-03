<?php
// app/Services/MedicineService.php - Medicine Master Business Logic

namespace Pharmacy\Services;

use PDO;
use Exception;
use InvalidArgumentException;

class MedicineService
{
    private PDO $pdo;
    private AuditService $auditService;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->auditService = new AuditService($pdo);
    }

    /**
     * Normalize medicine name for duplicate detection (strip whitespace, lowercase, remove symbols).
     */
    public static function normalizeName(string $name): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9]/', '', strtolower($name));
        return trim($clean ?? '');
    }

    /**
     * Detect similar or duplicate medicines in the catalog.
     */
    public function findPotentialDuplicates(string $name, ?int $excludeId = null): array
    {
        $normalized = self::normalizeName($name);
        if (empty($normalized)) {
            return [];
        }

        $sql = "SELECT medicine_id, medicine_name, generic_name, strength, dosage_form, status FROM medicines WHERE deleted_at IS NULL";
        $params = [];
        if ($excludeId !== null) {
            $sql .= " AND medicine_id != ?";
            $params[] = $excludeId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $all = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $duplicates = [];
        foreach ($all as $med) {
            $norm = self::normalizeName($med['medicine_name']);
            if ($norm === $normalized || soundex($med['medicine_name']) === soundex($name)) {
                $duplicates[] = $med;
            }
        }

        return $duplicates;
    }

    /**
     * Lookup medicine by barcode or alternate barcode.
     */
    public function findByBarcode(string $barcode): ?array
    {
        $barcode = trim($barcode);
        if ($barcode === '') {
            return null;
        }

        $stmt = $this->pdo->prepare("
            SELECT * FROM medicines 
            WHERE (barcode = ? OR alternate_barcode = ?) 
              AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([$barcode, $barcode]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Validate barcode uniqueness.
     */
    public function isBarcodeTaken(string $barcode, ?int $excludeId = null): bool
    {
        $barcode = trim($barcode);
        if ($barcode === '') {
            return false;
        }

        $sql = "SELECT COUNT(*) FROM medicines WHERE (barcode = ? OR alternate_barcode = ?) AND deleted_at IS NULL";
        $params = [$barcode, $barcode];
        if ($excludeId !== null) {
            $sql .= " AND medicine_id != ?";
            $params[] = $excludeId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Create a new Medicine in the master catalog.
     */
    public function createMedicine(array $data, int $userId): int
    {
        $name = trim($data['medicine_name'] ?? '');
        if ($name === '') {
            throw new InvalidArgumentException("Medicine name cannot be blank.");
        }

        $barcode = trim($data['barcode'] ?? '');
        if ($barcode !== '' && $this->isBarcodeTaken($barcode)) {
            throw new InvalidArgumentException("Barcode '{$barcode}' is already assigned to another medicine.");
        }

        $schedule = trim($data['schedule_type'] ?? 'General');
        $allowedSchedules = ['General', 'OTC', 'Schedule H', 'Schedule H1', 'Schedule X', 'Other'];
        if (!in_array($schedule, $allowedSchedules, true)) {
            $schedule = 'General';
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO medicines (
                medicine_name, generic_name, composition, strength, dosage_form,
                brand_name, category, unit, pack_size, rack_location, shelf, box_bin,
                schedule_type, manufacturer, hsn_code, gst_percent, price, purchase_price,
                stock_quantity, reorder_level, min_stock, max_stock, reorder_qty,
                barcode, alternate_barcode, status, created_by, updated_by, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                0, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, NOW(), NOW()
            )
        ");

        $stmt->execute([
            $name,
            !empty($data['generic_name']) ? trim($data['generic_name']) : null,
            !empty($data['composition']) ? trim($data['composition']) : null,
            !empty($data['strength']) ? trim($data['strength']) : null,
            !empty($data['dosage_form']) ? trim($data['dosage_form']) : null,
            !empty($data['brand_name']) ? trim($data['brand_name']) : null,
            !empty($data['category']) ? trim($data['category']) : 'Tablet',
            !empty($data['unit']) ? trim($data['unit']) : 'Strip',
            !empty($data['pack_size']) ? trim($data['pack_size']) : '10',
            !empty($data['rack_location']) ? trim($data['rack_location']) : null,
            !empty($data['shelf']) ? trim($data['shelf']) : null,
            !empty($data['box_bin']) ? trim($data['box_bin']) : null,
            $schedule,
            !empty($data['manufacturer']) ? trim($data['manufacturer']) : null,
            !empty($data['hsn_code']) ? trim($data['hsn_code']) : null,
            (float)($data['gst_percent'] ?? 0.00),
            (float)($data['price'] ?? 0.00),
            (float)($data['purchase_price'] ?? 0.00),
            (int)($data['reorder_level'] ?? 10),
            (int)($data['min_stock'] ?? 5),
            (int)($data['max_stock'] ?? 1000),
            (int)($data['reorder_qty'] ?? 50),
            $barcode !== '' ? $barcode : null,
            !empty($data['alternate_barcode']) ? trim($data['alternate_barcode']) : null,
            (!empty($data['status']) && in_array($data['status'], ['Active', 'Inactive', 'Discontinued'], true)) ? $data['status'] : 'Active',
            $userId,
            $userId
        ]);

        $medicineId = (int)$this->pdo->lastInsertId();

        $this->auditService->log(
            'MEDICINE_CREATED',
            'medicines',
            $medicineId,
            null,
            ['medicine_name' => $name, 'barcode' => $barcode, 'schedule' => $schedule],
            $userId
        );

        return $medicineId;
    }

    /**
     * Update an existing Medicine in the master catalog.
     */
    public function updateMedicine(int $medicineId, array $data, int $userId): bool
    {
        $name = trim($data['medicine_name'] ?? '');
        if ($name === '') {
            throw new InvalidArgumentException("Medicine name cannot be blank.");
        }

        $barcode = trim($data['barcode'] ?? '');
        if ($barcode !== '' && $this->isBarcodeTaken($barcode, $medicineId)) {
            throw new InvalidArgumentException("Barcode '{$barcode}' is already assigned to another medicine.");
        }

        $existing = $this->getMedicineById($medicineId);
        if (!$existing) {
            throw new Exception("Medicine #{$medicineId} not found.");
        }

        $schedule = trim($data['schedule_type'] ?? $existing['schedule_type']);
        $allowedSchedules = ['General', 'OTC', 'Schedule H', 'Schedule H1', 'Schedule X', 'Other'];
        if (!in_array($schedule, $allowedSchedules, true)) {
            $schedule = 'General';
        }

        $stmt = $this->pdo->prepare("
            UPDATE medicines SET
                medicine_name = ?, generic_name = ?, composition = ?, strength = ?, dosage_form = ?,
                brand_name = ?, category = ?, unit = ?, pack_size = ?, rack_location = ?,
                shelf = ?, box_bin = ?, schedule_type = ?, manufacturer = ?, hsn_code = ?,
                gst_percent = ?, price = ?, purchase_price = ?, reorder_level = ?,
                min_stock = ?, max_stock = ?, reorder_qty = ?, barcode = ?, alternate_barcode = ?,
                status = ?, updated_by = ?, updated_at = NOW()
            WHERE medicine_id = ?
        ");

        $rawStatus = $data['status'] ?? ($existing['status'] ?? 'Active');
        $status = in_array($rawStatus, ['Active', 'Inactive', 'Discontinued'], true) ? $rawStatus : 'Active';

        $stmt->execute([
            $name,
            !empty($data['generic_name']) ? trim($data['generic_name']) : null,
            !empty($data['composition']) ? trim($data['composition']) : null,
            !empty($data['strength']) ? trim($data['strength']) : null,
            !empty($data['dosage_form']) ? trim($data['dosage_form']) : null,
            !empty($data['brand_name']) ? trim($data['brand_name']) : null,
            !empty($data['category']) ? trim($data['category']) : $existing['category'],
            !empty($data['unit']) ? trim($data['unit']) : $existing['unit'],
            !empty($data['pack_size']) ? trim($data['pack_size']) : $existing['pack_size'],
            !empty($data['rack_location']) ? trim($data['rack_location']) : null,
            !empty($data['shelf']) ? trim($data['shelf']) : null,
            !empty($data['box_bin']) ? trim($data['box_bin']) : null,
            $schedule,
            !empty($data['manufacturer']) ? trim($data['manufacturer']) : null,
            !empty($data['hsn_code']) ? trim($data['hsn_code']) : null,
            (float)($data['gst_percent'] ?? $existing['gst_percent']),
            (float)($data['price'] ?? $existing['price']),
            (float)($data['purchase_price'] ?? $existing['purchase_price']),
            (int)($data['reorder_level'] ?? $existing['reorder_level']),
            (int)($data['min_stock'] ?? $existing['min_stock']),
            (int)($data['max_stock'] ?? $existing['max_stock']),
            (int)($data['reorder_qty'] ?? $existing['reorder_qty']),
            $barcode !== '' ? $barcode : null,
            !empty($data['alternate_barcode']) ? trim($data['alternate_barcode']) : null,
            $status,
            $userId,
            $medicineId
        ]);

        $this->auditService->log(
            'MEDICINE_UPDATED',
            'medicines',
            $medicineId,
            $existing,
            $data,
            $userId
        );

        return true;
    }

    /**
     * Toggle medicine status (Active / Inactive / Discontinued).
     */
    public function setStatus(int $medicineId, string $status, int $userId): bool
    {
        if (!in_array($status, ['Active', 'Inactive', 'Discontinued'], true)) {
            throw new InvalidArgumentException("Invalid medicine status '{$status}'.");
        }

        $stmt = $this->pdo->prepare("UPDATE medicines SET status = ?, updated_by = ?, updated_at = NOW() WHERE medicine_id = ?");
        $stmt->execute([$status, $userId, $medicineId]);

        $this->auditService->log(
            'MEDICINE_STATUS_CHANGED',
            'medicines',
            $medicineId,
            null,
            ['status' => $status],
            $userId
        );

        return true;
    }

    /**
     * Retrieve single medicine by ID.
     */
    public function getMedicineById(int $medicineId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM medicines WHERE medicine_id = ? AND deleted_at IS NULL");
        $stmt->execute([$medicineId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Search medicines for counter dispensing or inventory catalog.
     */
    public function search(string $query, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ["m.deleted_at IS NULL"];
        $params = [];

        if (!empty($filters['active_only'])) {
            $where[] = "m.status = 'Active'";
        } elseif (!empty($filters['status'])) {
            $where[] = "m.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['category'])) {
            $where[] = "m.category = ?";
            $params[] = $filters['category'];
        }

        if (!empty($filters['schedule_type'])) {
            $where[] = "m.schedule_type = ?";
            $params[] = $filters['schedule_type'];
        }

        if ($query !== '') {
            $where[] = "(m.medicine_name LIKE ? OR m.generic_name LIKE ? OR m.composition LIKE ? OR m.barcode LIKE ? OR m.manufacturer LIKE ? OR m.hsn_code LIKE ?)";
            $like = "%{$query}%";
            $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
        }

        $whereSql = implode(' AND ', $where);
        $sql = "
            SELECT m.*, 
                   (SELECT COUNT(*) FROM medicine_batches mb WHERE mb.medicine_id = m.medicine_id AND mb.quantity_available > 0) AS active_batches_count
            FROM medicines m
            WHERE {$whereSql}
            ORDER BY m.medicine_name ASC
            LIMIT {$limit} OFFSET {$offset}
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count total medicines matching criteria.
     */
    public function count(string $query = '', array $filters = []): int
    {
        $where = ["deleted_at IS NULL"];
        $params = [];

        if (!empty($filters['active_only'])) {
            $where[] = "status = 'Active'";
        } elseif (!empty($filters['status'])) {
            $where[] = "status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['category'])) {
            $where[] = "category = ?";
            $params[] = $filters['category'];
        }

        if ($query !== '') {
            $where[] = "(medicine_name LIKE ? OR generic_name LIKE ? OR barcode LIKE ?)";
            $like = "%{$query}%";
            $params = array_merge($params, [$like, $like, $like]);
        }

        $whereSql = implode(' AND ', $where);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM medicines WHERE {$whereSql}");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
}
