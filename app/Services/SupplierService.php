<?php
// app/Services/SupplierService.php - Supplier Master & Validation Service

namespace Pharmacy\Services;

use PDO;
use Exception;
use InvalidArgumentException;

class SupplierService
{
    private PDO $pdo;
    private DocumentSequenceService $seqService;
    private AuditService $auditService;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->seqService = new DocumentSequenceService($pdo);
        $this->auditService = new AuditService($pdo);
    }

    /**
     * Validate Indian GSTIN format (15 characters).
     * Format: 2 digits state code + 10 char PAN + 1 entity code + 'Z' + 1 checksum digit/char.
     */
    public static function isValidGstin(string $gstin): bool
    {
        $gstin = strtoupper(trim($gstin));
        if ($gstin === '' || $gstin === 'N/A' || $gstin === 'NA') return true; // Optional unless required
        // Standard 15-character Indian GSTIN pattern
        if (preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}[A-Z0-9]{1}[0-9A-Z]{1}$/', $gstin)) {
            return true;
        }
        // General business/test tax registration identifier (5 to 20 alphanumeric characters)
        return (bool)preg_match('/^[A-Z0-9\-_]{5,20}$/', $gstin);
    }

    /**
     * Normalize string for fuzzy/duplicate detection.
     */
    public static function normalizeName(string $name): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9]/', '', strtolower($name));
        return trim($clean);
    }

    /**
     * Check for potential duplicate suppliers.
     */
    public function checkDuplicate(string $name, ?string $gstin = null, ?string $dlNo = null, ?string $phone = null, ?int $excludeId = null): array
    {
        $warnings = [];
        $matches = [];

        $gstin = trim((string)$gstin);
        $dlNo = trim((string)$dlNo);
        $phone = trim((string)$phone);
        $normName = self::normalizeName($name);

        // 1. Check exact GSTIN match
        if ($gstin !== '') {
            $sql = "SELECT supplier_id, supplier_code, supplier_name, gstin FROM pharmacy_suppliers WHERE UPPER(TRIM(gstin)) = ?";
            $params = [strtoupper($gstin)];
            if ($excludeId !== null) {
                $sql .= " AND supplier_id != ?";
                $params[] = $excludeId;
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $warnings[] = "A supplier with GSTIN '{$gstin}' already exists (" . htmlspecialchars($rows[0]['supplier_name']) . ").";
                $matches['gstin'] = $rows;
            }
        }

        // 2. Check exact Drug Licence match
        if ($dlNo !== '') {
            $sql = "SELECT supplier_id, supplier_code, supplier_name, drug_licence_no FROM pharmacy_suppliers WHERE UPPER(TRIM(drug_licence_no)) = ?";
            $params = [strtoupper($dlNo)];
            if ($excludeId !== null) {
                $sql .= " AND supplier_id != ?";
                $params[] = $excludeId;
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $warnings[] = "A supplier with Drug Licence '{$dlNo}' already exists (" . htmlspecialchars($rows[0]['supplier_name']) . ").";
                $matches['drug_licence'] = $rows;
            }
        }

        // 3. Check normalized name match
        if ($normName !== '') {
            $allSuppliers = $this->pdo->query("SELECT supplier_id, supplier_code, supplier_name FROM pharmacy_suppliers")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($allSuppliers as $sup) {
                if ($excludeId !== null && (int)$sup['supplier_id'] === $excludeId) {
                    continue;
                }
                if (self::normalizeName($sup['supplier_name']) === $normName) {
                    $warnings[] = "Supplier name is phonetically or structurally identical to existing supplier '{$sup['supplier_name']}' ({$sup['supplier_code']}).";
                    $matches['name'][] = $sup;
                    break;
                }
            }
        }

        // 4. Check exact phone match
        if ($phone !== '') {
            $sql = "SELECT supplier_id, supplier_code, supplier_name, phone FROM pharmacy_suppliers WHERE phone = ?";
            $params = [$phone];
            if ($excludeId !== null) {
                $sql .= " AND supplier_id != ?";
                $params[] = $excludeId;
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $warnings[] = "Phone number matches existing supplier '{$rows[0]['supplier_name']}'.";
                $matches['phone'] = $rows;
            }
        }

        return [
            'has_duplicate' => !empty($warnings),
            'warnings' => $warnings,
            'matches' => $matches
        ];
    }

    /**
     * Create a new supplier.
     */
    public function createSupplier(array $data, int $userId, bool $allowDuplicate = false): int
    {
        $name = trim($data['supplier_name'] ?? '');
        if ($name === '') {
            throw new InvalidArgumentException("Supplier name is mandatory.");
        }

        $gstin = !empty($data['gstin']) ? strtoupper(trim($data['gstin'])) : null;
        if ($gstin !== null && !self::isValidGstin($gstin)) {
            throw new InvalidArgumentException("Invalid GSTIN format. Expected standard 15-character alphanumeric format.");
        }

        $dlNo = !empty($data['drug_licence_no']) ? trim($data['drug_licence_no']) : null;
        $phone = !empty($data['phone']) ? trim($data['phone']) : null;

        // Duplicate check
        $dupCheck = $this->checkDuplicate($name, $gstin, $dlNo, $phone);
        if ($dupCheck['has_duplicate'] && !$allowDuplicate) {
            throw new InvalidArgumentException("Duplicate supplier detected: " . implode(" ", $dupCheck['warnings']));
        }

        // Generate supplier code if not provided
        $supplierCode = !empty($data['supplier_code']) ? trim($data['supplier_code']) : null;
        if (!$supplierCode) {
            $supplierCode = $this->seqService->generate('SUPPLIER', 'SUP-');
        }

        $legalName = !empty($data['legal_name']) ? trim($data['legal_name']) : null;
        $contactPerson = !empty($data['contact_person']) ? trim($data['contact_person']) : null;
        $altPhone = !empty($data['alternate_mobile']) ? trim($data['alternate_mobile']) : null;
        $email = !empty($data['email']) ? trim($data['email']) : null;
        $address = !empty($data['address']) ? trim($data['address']) : null;
        $city = !empty($data['city']) ? trim($data['city']) : null;
        $state = !empty($data['state']) ? trim($data['state']) : null;
        $pincode = !empty($data['pincode']) ? trim($data['pincode']) : null;
        $pan = !empty($data['pan']) ? strtoupper(trim($data['pan'])) : null;
        $dlType = !empty($data['drug_licence_type']) ? trim($data['drug_licence_type']) : null;
        $dlExpiry = !empty($data['licence_expiry_date']) ? trim($data['licence_expiry_date']) : null;
        $supplierType = !empty($data['supplier_type']) ? trim($data['supplier_type']) : 'Distributor';
        $paymentTerms = !empty($data['payment_terms']) ? trim($data['payment_terms']) : '30 Days Net';
        $creditDays = isset($data['credit_days']) ? max(0, (int)$data['credit_days']) : 30;
        $creditLimit = isset($data['credit_limit']) ? max(0.0, (float)$data['credit_limit']) : 0.00;
        $openingBalance = isset($data['opening_balance']) ? (float)$data['opening_balance'] : 0.00;
        $bankName = !empty($data['bank_name']) ? trim($data['bank_name']) : null;
        $bankAcc = !empty($data['bank_account_no']) ? trim($data['bank_account_no']) : null;
        $bankIfsc = !empty($data['bank_ifsc']) ? strtoupper(trim($data['bank_ifsc'])) : null;
        $status = in_array($data['status'] ?? 'Active', ['Active', 'Inactive', 'Blocked'], true) ? ($data['status'] ?? 'Active') : 'Active';
        $notes = !empty($data['notes']) ? trim($data['notes']) : null;

        $stmt = $this->pdo->prepare("
            INSERT INTO pharmacy_suppliers (
                supplier_code, supplier_name, legal_name, contact_person, phone, alternate_mobile,
                email, address, city, state, pincode, gstin, pan,
                drug_licence_no, drug_licence_type, licence_expiry_date, supplier_type,
                payment_terms, credit_days, credit_limit, opening_balance,
                bank_name, bank_account_no, bank_ifsc, status, notes,
                created_by, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, NOW(), NOW()
            )
        ");

        $stmt->execute([
            $supplierCode, $name, $legalName, $contactPerson, $phone, $altPhone,
            $email, $address, $city, $state, $pincode, $gstin, $pan,
            $dlNo, $dlType, $dlExpiry, $supplierType,
            $paymentTerms, $creditDays, $creditLimit, $openingBalance,
            $bankName, $bankAcc, $bankIfsc, $status, $notes,
            $userId
        ]);

        $supplierId = (int)$this->pdo->lastInsertId();

        $this->auditService->logAction(
            $userId,
            'CREATE_SUPPLIER',
            'pharmacy_suppliers',
            $supplierId,
            null,
            ['supplier_code' => $supplierCode, 'supplier_name' => $name, 'gstin' => $gstin]
        );

        return $supplierId;
    }

    /**
     * Update an existing supplier.
     */
    public function updateSupplier(int $supplierId, array $data, int $userId): bool
    {
        $existing = $this->getSupplier($supplierId);
        if (!$existing) {
            throw new InvalidArgumentException("Supplier ID {$supplierId} does not exist.");
        }

        $name = trim($data['supplier_name'] ?? $existing['supplier_name']);
        if ($name === '') {
            throw new InvalidArgumentException("Supplier name cannot be empty.");
        }

        $gstin = isset($data['gstin']) ? (!empty($data['gstin']) ? strtoupper(trim($data['gstin'])) : null) : $existing['gstin'];
        if ($gstin !== null && !self::isValidGstin($gstin)) {
            throw new InvalidArgumentException("Invalid GSTIN format.");
        }

        $legalName = $data['legal_name'] ?? $existing['legal_name'];
        $contactPerson = $data['contact_person'] ?? $existing['contact_person'];
        $phone = $data['phone'] ?? $existing['phone'];
        $altPhone = $data['alternate_mobile'] ?? $existing['alternate_mobile'];
        $email = $data['email'] ?? $existing['email'];
        $address = $data['address'] ?? $existing['address'];
        $city = $data['city'] ?? $existing['city'];
        $state = $data['state'] ?? $existing['state'];
        $pincode = $data['pincode'] ?? $existing['pincode'];
        $pan = isset($data['pan']) ? strtoupper(trim($data['pan'])) : $existing['pan'];
        $dlNo = $data['drug_licence_no'] ?? $existing['drug_licence_no'];
        $dlType = $data['drug_licence_type'] ?? $existing['drug_licence_type'];
        $dlExpiry = $data['licence_expiry_date'] ?? $existing['licence_expiry_date'];
        $supplierType = $data['supplier_type'] ?? $existing['supplier_type'];
        $paymentTerms = $data['payment_terms'] ?? $existing['payment_terms'];
        $creditDays = isset($data['credit_days']) ? max(0, (int)$data['credit_days']) : $existing['credit_days'];
        $creditLimit = isset($data['credit_limit']) ? max(0.0, (float)$data['credit_limit']) : $existing['credit_limit'];
        $bankName = $data['bank_name'] ?? $existing['bank_name'];
        $bankAcc = $data['bank_account_no'] ?? $existing['bank_account_no'];
        $bankIfsc = isset($data['bank_ifsc']) ? strtoupper(trim($data['bank_ifsc'])) : $existing['bank_ifsc'];
        $status = in_array($data['status'] ?? $existing['status'], ['Active', 'Inactive', 'Blocked'], true) ? ($data['status'] ?? $existing['status']) : 'Active';
        $notes = $data['notes'] ?? $existing['notes'];

        $stmt = $this->pdo->prepare("
            UPDATE pharmacy_suppliers SET
                supplier_name = ?, legal_name = ?, contact_person = ?, phone = ?, alternate_mobile = ?,
                email = ?, address = ?, city = ?, state = ?, pincode = ?, gstin = ?, pan = ?,
                drug_licence_no = ?, drug_licence_type = ?, licence_expiry_date = ?, supplier_type = ?,
                payment_terms = ?, credit_days = ?, credit_limit = ?,
                bank_name = ?, bank_account_no = ?, bank_ifsc = ?, status = ?, notes = ?,
                updated_by = ?, updated_at = NOW()
            WHERE supplier_id = ?
        ");

        $stmt->execute([
            $name, $legalName, $contactPerson, $phone, $altPhone,
            $email, $address, $city, $state, $pincode, $gstin, $pan,
            $dlNo, $dlType, $dlExpiry, $supplierType,
            $paymentTerms, $creditDays, $creditLimit,
            $bankName, $bankAcc, $bankIfsc, $status, $notes,
            $userId, $supplierId
        ]);

        $this->auditService->logAction(
            $userId,
            'UPDATE_SUPPLIER',
            'pharmacy_suppliers',
            $supplierId,
            $existing,
            ['supplier_name' => $name, 'status' => $status, 'gstin' => $gstin]
        );

        return true;
    }

    /**
     * Soft-deactivate supplier. Never physically delete historical suppliers.
     */
    public function deactivateSupplier(int $supplierId, int $userId): bool
    {
        $existing = $this->getSupplier($supplierId);
        if (!$existing) {
            throw new InvalidArgumentException("Supplier not found.");
        }

        $stmt = $this->pdo->prepare("UPDATE pharmacy_suppliers SET status = 'Inactive', updated_by = ?, updated_at = NOW() WHERE supplier_id = ?");
        $stmt->execute([$userId, $supplierId]);

        $this->auditService->logAction($userId, 'DEACTIVATE_SUPPLIER', 'pharmacy_suppliers', $supplierId, ['status' => $existing['status']], ['status' => 'Inactive']);
        return true;
    }

    /**
     * Block supplier.
     */
    public function blockSupplier(int $supplierId, int $userId, string $reason = ''): bool
    {
        $existing = $this->getSupplier($supplierId);
        if (!$existing) {
            throw new InvalidArgumentException("Supplier not found.");
        }

        $stmt = $this->pdo->prepare("UPDATE pharmacy_suppliers SET status = 'Blocked', notes = CONCAT(IFNULL(notes,''), '\n[BLOCKED: ', ?, ']'), updated_by = ?, updated_at = NOW() WHERE supplier_id = ?");
        $stmt->execute([$reason, $userId, $supplierId]);

        $this->auditService->logAction($userId, 'BLOCK_SUPPLIER', 'pharmacy_suppliers', $supplierId, ['status' => $existing['status']], ['status' => 'Blocked', 'reason' => $reason]);
        return true;
    }

    /**
     * Retrieve single supplier by ID.
     */
    public function getSupplier(int $supplierId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM pharmacy_suppliers WHERE supplier_id = ?");
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * List suppliers with filters.
     */
    public function listSuppliers(array $filters = []): array
    {
        $sql = "
            SELECT s.*,
                   (SELECT COUNT(*) FROM pharmacy_purchase_orders WHERE supplier_id = s.supplier_id) as total_pos,
                   (SELECT COUNT(*) FROM pharmacy_grn WHERE supplier_id = s.supplier_id) as total_grns,
                   (SELECT IFNULL(SUM(grand_total), 0.00) FROM pharmacy_purchase_invoices WHERE supplier_id = s.supplier_id) as total_billed,
                   (SELECT IFNULL(SUM(amount_paid), 0.00) FROM pharmacy_purchase_invoices WHERE supplier_id = s.supplier_id) as total_paid,
                   (SELECT IFNULL(SUM(outstanding_amount), 0.00) FROM pharmacy_purchase_invoices WHERE supplier_id = s.supplier_id AND payment_status != 'PAID') as current_outstanding
            FROM pharmacy_suppliers s
            WHERE 1=1
        ";
        $params = [];

        if (!empty($filters['search'])) {
            $term = '%' . trim($filters['search']) . '%';
            $sql .= " AND (s.supplier_name LIKE ? OR s.supplier_code LIKE ? OR s.phone LIKE ? OR s.gstin LIKE ?)";
            $params = array_merge($params, [$term, $term, $term, $term]);
        }

        if (!empty($filters['status'])) {
            $sql .= " AND s.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['city'])) {
            $sql .= " AND s.city = ?";
            $params[] = $filters['city'];
        }

        $sql .= " ORDER BY s.supplier_name ASC";

        if (isset($filters['limit'])) {
            $limit = max(1, (int)$filters['limit']);
            $offset = max(0, (int)($filters['offset'] ?? 0));
            $sql .= " LIMIT {$limit} OFFSET {$offset}";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get financial and operational statistics for a supplier.
     */
    public function getSupplierStats(int $supplierId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(DISTINCT i.invoice_id) as invoice_count,
                IFNULL(SUM(i.grand_total), 0.00) as total_invoiced,
                IFNULL(SUM(i.amount_paid), 0.00) as total_paid,
                IFNULL(SUM(i.outstanding_amount), 0.00) as total_outstanding,
                (SELECT COUNT(*) FROM pharmacy_purchase_orders WHERE supplier_id = ? AND status NOT IN ('CANCELLED', 'CLOSED', 'FULLY_RECEIVED')) as active_pos,
                (SELECT COUNT(*) FROM pharmacy_grn WHERE supplier_id = ? AND status = 'POSTED') as total_grns
            FROM pharmacy_suppliers s
            LEFT JOIN pharmacy_purchase_invoices i ON s.supplier_id = i.supplier_id
            WHERE s.supplier_id = ?
        ");
        $stmt->execute([$supplierId, $supplierId, $supplierId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'invoice_count' => 0,
            'total_invoiced' => 0.00,
            'total_paid' => 0.00,
            'total_outstanding' => 0.00,
            'active_pos' => 0,
            'total_grns' => 0
        ];
    }
}
