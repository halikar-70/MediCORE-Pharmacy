<?php
// app/Services/IndentService.php - IPD Ward Indent Requisition & Dispensing Fulfillment Engine

namespace Pharmacy\Services;

use PDO;
use Exception;
use InvalidArgumentException;

class IndentService
{
    private PDO $pdo;
    private FefoService $fefoService;
    private DocumentSequenceService $seqService;
    private AuditService $auditService;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->fefoService = new FefoService($pdo);
        $this->seqService = new DocumentSequenceService($pdo);
        $this->auditService = new AuditService($pdo);
    }

    /**
     * Create a new IPD ward medication requisition.
     */
    public function createIndent(array $data, array $items, ?int $userId = null): array
    {
        if (empty($items)) {
            throw new InvalidArgumentException("Indent must contain at least one requested medicine.");
        }

        $ward = trim($data['ward'] ?? '');
        if ($ward === '') {
            throw new InvalidArgumentException("Ward name is required.");
        }

        $requestedBy = trim($data['requested_by'] ?? '');
        if ($requestedBy === '') {
            throw new InvalidArgumentException("Requested by (nurse/staff name) is required.");
        }

        $bedNumber = !empty($data['bed_number']) ? trim($data['bed_number']) : null;
        $patientId = !empty($data['patient_id']) ? (int)$data['patient_id'] : null;
        $patientName = !empty($data['patient_name']) ? trim($data['patient_name']) : null;
        $ipdAdmissionNo = !empty($data['ipd_admission_no']) ? trim($data['ipd_admission_no']) : null;
        $priority = in_array($data['priority'] ?? '', ['NORMAL', 'URGENT', 'STAT'], true) ? $data['priority'] : 'NORMAL';
        $notes = !empty($data['notes']) ? trim($data['notes']) : null;

        $this->pdo->beginTransaction();
        try {
            $indentNumber = $this->seqService->generate('IPD_INDENT');

            $insIndent = $this->pdo->prepare("
                INSERT INTO pharmacy_indents (
                    indent_number, indent_date, ward, bed_number, patient_id,
                    patient_name, ipd_admission_no, requested_by, priority,
                    status, notes, created_by, created_at
                ) VALUES (
                    ?, CURDATE(), ?, ?, ?,
                    ?, ?, ?, ?,
                    'SUBMITTED', ?, ?, NOW()
                )
            ");
            $insIndent->execute([
                $indentNumber,
                $ward,
                $bedNumber,
                $patientId,
                $patientName,
                $ipdAdmissionNo,
                $requestedBy,
                $priority,
                $notes,
                $userId
            ]);

            $indentId = (int)$this->pdo->lastInsertId();

            $insItem = $this->pdo->prepare("
                INSERT INTO pharmacy_indent_items (
                    indent_id, medicine_id, requested_qty, approved_qty, dispensed_qty,
                    status, notes, created_at
                ) VALUES (
                    ?, ?, ?, 0, 0,
                    'PENDING', ?, NOW()
                )
            ");

            foreach ($items as $it) {
                $medId = (int)($it['medicine_id'] ?? 0);
                $qty = (int)($it['requested_qty'] ?? 0);

                if ($medId <= 0 || $qty <= 0) {
                    throw new InvalidArgumentException("Each item must have a valid medicine and requested quantity > 0.");
                }

                $itNotes = $it['notes'] ?? null;
                $insItem->execute([$indentId, $medId, $qty, $itNotes]);
            }

            $this->auditService->logAction(
                $userId,
                'INDENT_CREATED',
                'pharmacy_indents',
                (string)$indentId,
                null,
                ['ward' => $ward, 'priority' => $priority, 'items_count' => count($items)]
            );

            $this->pdo->commit();
            return $this->getIndent($indentId);

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Authorize / Approve a submitted indent before dispensing.
     */
    public function approveIndent(int $indentId, ?array $approvedQuantities = null, ?int $userId = null): array
    {
        $indent = $this->getIndent($indentId);
        if (!$indent) {
            throw new Exception("Indent #{$indentId} not found.");
        }
        if ($indent['status'] !== 'SUBMITTED') {
            throw new Exception("Only SUBMITTED indents can be approved. Current status: {$indent['status']}.");
        }

        $this->pdo->beginTransaction();
        try {
            // Update item approved quantities
            foreach ($indent['items'] as $item) {
                $itemId = (int)$item['item_id'];
                $appQty = (int)$item['requested_qty'];

                if ($approvedQuantities !== null && isset($approvedQuantities[$itemId])) {
                    $appQty = max(0, min((int)$item['requested_qty'], (int)$approvedQuantities[$itemId]));
                }

                $itemStatus = ($appQty > 0) ? 'APPROVED' : 'REJECTED';
                $updItem = $this->pdo->prepare("
                    UPDATE pharmacy_indent_items
                    SET approved_qty = ?, status = ?, updated_at = NOW()
                    WHERE item_id = ?
                ");
                $updItem->execute([$appQty, $itemStatus, $itemId]);
            }

            $updIndent = $this->pdo->prepare("
                UPDATE pharmacy_indents
                SET status = 'APPROVED', approved_by = ?, approved_at = NOW(), updated_at = NOW()
                WHERE indent_id = ?
            ");
            $updIndent->execute([$userId, $indentId]);

            $this->auditService->logAction(
                $userId,
                'INDENT_APPROVED',
                'pharmacy_indents',
                (string)$indentId,
                ['status' => 'SUBMITTED'],
                ['status' => 'APPROVED']
            );

            $this->pdo->commit();
            return $this->getIndent($indentId);

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Fulfill ward indent by dispensing stock through FEFO.
     * Uses transaction type 'IPD_INDENT' in StockLedger.
     *
     * @param int $indentId
     * @param array $fulfillmentItems Array of ['item_id' => int, 'quantity' => int]
     * @param int|null $userId
     * @return array Updated indent record
     */
    public function fulfillIndent(int $indentId, array $fulfillmentItems, ?int $userId = null): array
    {
        $indent = $this->getIndent($indentId);
        if (!$indent) {
            throw new Exception("Indent #{$indentId} not found.");
        }

        if (!in_array($indent['status'], ['APPROVED', 'SUBMITTED', 'PARTIALLY_FULFILLED'], true)) {
            throw new Exception("Cannot fulfill indent with status '{$indent['status']}'.");
        }

        $itemMap = [];
        foreach ($indent['items'] as $it) {
            $itemMap[(int)$it['item_id']] = $it;
        }

        $this->pdo->beginTransaction();
        try {
            foreach ($fulfillmentItems as $fItem) {
                $itemId = (int)($fItem['item_id'] ?? 0);
                $qty = (int)($fItem['quantity'] ?? 0);

                if ($qty <= 0) {
                    continue;
                }

                if (!isset($itemMap[$itemId])) {
                    throw new Exception("Item #{$itemId} does not belong to Indent {$indent['indent_number']}.");
                }

                $item = $itemMap[$itemId];
                $medId = (int)$item['medicine_id'];
                $reqQty = (int)$item['requested_qty'];
                $alreadyDispensed = (int)$item['dispensed_qty'];
                $maxAllowable = $reqQty - $alreadyDispensed;

                if ($qty > $maxAllowable) {
                    throw new Exception("Fulfillment quantity ({$qty}) exceeds pending quantity ({$maxAllowable}) for '{$item['medicine_name']}'. Over-dispensing blocked.");
                }

                // 1. Allocate non-expired stock via FEFO with FOR UPDATE row locks
                $allocations = $this->fefoService->allocate($medId, $qty, true);

                // 2. Execute stock deduction under transaction type 'IPD_INDENT'
                $this->fefoService->executeDeduction(
                    $allocations,
                    'IPD_INDENT',
                    $indentId,
                    $indent['indent_number'],
                    $userId,
                    "IPD Ward Indent #{$indent['indent_number']} Dispatched"
                );

                // 3. Update indent item dispensed qty
                $newDispensed = $alreadyDispensed + $qty;
                $newStatus = ($newDispensed >= $reqQty) ? 'DISPENSED' : 'APPROVED';
                $updItem = $this->pdo->prepare("
                    UPDATE pharmacy_indent_items
                    SET dispensed_qty = ?, status = ?, updated_at = NOW()
                    WHERE item_id = ?
                ");
                $updItem->execute([$newDispensed, $newStatus, $itemId]);
            }

            // Re-evaluate overall indent status
            $chkItems = $this->pdo->prepare("SELECT requested_qty, dispensed_qty FROM pharmacy_indent_items WHERE indent_id = ?");
            $chkItems->execute([$indentId]);
            $rows = $chkItems->fetchAll(PDO::FETCH_ASSOC);

            $allCompleted = true;
            $anyCompleted = false;
            foreach ($rows as $r) {
                if ($r['dispensed_qty'] < $r['requested_qty']) {
                    $allCompleted = false;
                }
                if ($r['dispensed_qty'] > 0) {
                    $anyCompleted = true;
                }
            }

            $overallStatus = 'APPROVED';
            if ($allCompleted) {
                $overallStatus = 'FULFILLED';
            } elseif ($anyCompleted) {
                $overallStatus = 'PARTIALLY_FULFILLED';
            }

            $updInd = $this->pdo->prepare("
                UPDATE pharmacy_indents
                SET status = ?, updated_at = NOW()
                WHERE indent_id = ?
            ");
            $updInd->execute([$overallStatus, $indentId]);

            $this->auditService->logAction(
                $userId,
                'INDENT_FULFILLED',
                'pharmacy_indents',
                (string)$indentId,
                ['previous_status' => $indent['status']],
                ['new_status' => $overallStatus]
            );

            $this->pdo->commit();
            return $this->getIndent($indentId);

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Reject or cancel an indent requisition.
     */
    public function rejectIndent(int $indentId, string $reason, ?int $userId = null): array
    {
        $indent = $this->getIndent($indentId);
        if (!$indent) {
            throw new Exception("Indent #{$indentId} not found.");
        }
        if (in_array($indent['status'], ['FULFILLED', 'REJECTED', 'CANCELLED'], true)) {
            throw new Exception("Cannot reject indent with status {$indent['status']}.");
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException("Rejection reason is required.");
        }

        $upd = $this->pdo->prepare("
            UPDATE pharmacy_indents
            SET status = 'REJECTED', rejected_by = ?, rejected_at = NOW(), rejection_reason = ?, updated_at = NOW()
            WHERE indent_id = ?
        ");
        $upd->execute([$userId, $reason, $indentId]);

        $this->auditService->logAction(
            $userId,
            'INDENT_REJECTED',
            'pharmacy_indents',
            (string)$indentId,
            ['status' => $indent['status']],
            ['status' => 'REJECTED', 'reason' => $reason]
        );

        return $this->getIndent($indentId);
    }

    /**
     * Get single indent with line items and stock availability.
     */
    public function getIndent(int $indentId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT ind.*, u.full_name as requested_by_user, a.full_name as approved_by_name
            FROM pharmacy_indents ind
            LEFT JOIN pharmacy_users u ON ind.created_by = u.id
            LEFT JOIN pharmacy_users a ON ind.approved_by = a.id
            WHERE ind.indent_id = ?
        ");
        $stmt->execute([$indentId]);
        $indent = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$indent) {
            return null;
        }

        $itemStmt = $this->pdo->prepare("
            SELECT ii.*, m.medicine_name, m.generic_name, m.dosage_form, m.strength,
                   (ii.requested_qty - ii.dispensed_qty) as pending_qty,
                   COALESCE((
                       SELECT SUM(mb.quantity_available)
                       FROM medicine_batches mb
                       WHERE mb.medicine_id = m.medicine_id
                         AND mb.status = 'Active'
                         AND mb.quantity_available > 0
                         AND mb.expiry_date >= CURDATE()
                   ), 0) as available_stock
            FROM pharmacy_indent_items ii
            JOIN medicines m ON ii.medicine_id = m.medicine_id
            WHERE ii.indent_id = ?
            ORDER BY ii.item_id ASC
        ");
        $itemStmt->execute([$indentId]);
        $indent['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        return $indent;
    }

    /**
     * List indents with filtering by status, ward, priority, and date.
     */
    public function listIndents(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ["1=1"];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "ind.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['priority'])) {
            $where[] = "ind.priority = ?";
            $params[] = $filters['priority'];
        }

        if (!empty($filters['ward'])) {
            $where[] = "ind.ward = ?";
            $params[] = $filters['ward'];
        }

        if (!empty($filters['start_date'])) {
            $where[] = "ind.indent_date >= ?";
            $params[] = $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $where[] = "ind.indent_date <= ?";
            $params[] = $filters['end_date'];
        }

        if (!empty($filters['search'])) {
            $search = '%' . trim($filters['search']) . '%';
            $where[] = "(ind.indent_number LIKE ? OR ind.ward LIKE ? OR ind.requested_by LIKE ? OR ind.patient_name LIKE ?)";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $whereSql = implode(' AND ', $where);
        $sql = "
            SELECT ind.*,
                   (SELECT COUNT(*) FROM pharmacy_indent_items WHERE indent_id = ind.indent_id) as items_count
            FROM pharmacy_indents ind
            WHERE {$whereSql}
            ORDER BY 
                CASE ind.priority 
                    WHEN 'STAT' THEN 1 
                    WHEN 'URGENT' THEN 2 
                    ELSE 3 
                END,
                ind.indent_id DESC
            LIMIT {$limit} OFFSET {$offset}
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
