<?php
// app/Services/PurchaseOrderService.php - Purchase Order Management & Workflow

namespace Pharmacy\Services;

use PDO;
use Exception;
use InvalidArgumentException;

class PurchaseOrderService
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
     * Create a new purchase order. Never alters inventory stock.
     */
    public function createPurchaseOrder(array $header, array $items, int $userId): int
    {
        $supplierId = (int)($header['supplier_id'] ?? 0);
        if ($supplierId <= 0) {
            throw new InvalidArgumentException("Valid supplier must be selected.");
        }

        // Validate supplier is Active
        $supStmt = $this->pdo->prepare("SELECT supplier_id, status FROM pharmacy_suppliers WHERE supplier_id = ?");
        $supStmt->execute([$supplierId]);
        $supplier = $supStmt->fetch(PDO::FETCH_ASSOC);
        if (!$supplier) {
            throw new InvalidArgumentException("Supplier does not exist.");
        }
        if ($supplier['status'] === 'Blocked') {
            throw new InvalidArgumentException("Cannot place purchase orders to a Blocked supplier.");
        }

        $poDate = !empty($header['po_date']) ? $header['po_date'] : date('Y-m-d');
        $expectedDelivery = !empty($header['expected_delivery_date']) ? $header['expected_delivery_date'] : null;
        $paymentTerms = !empty($header['payment_terms']) ? trim($header['payment_terms']) : null;
        $notes = !empty($header['notes']) ? trim($header['notes']) : null;
        $statusParam = $header['status'] ?? 'DRAFT';
        $initialStatus = in_array($statusParam, ['DRAFT', 'SUBMITTED', 'APPROVED'], true) ? $statusParam : 'DRAFT';

        if (empty($items)) {
            throw new InvalidArgumentException("Purchase order must contain at least one medicine item.");
        }

        $this->pdo->beginTransaction();
        try {
            // Generate dedicated PO sequence number
            $poNumber = $this->seqService->generate('PURCHASE_ORDER', 'PO-');

            $subtotal = 0.00;
            $discountTotal = 0.00;
            $taxTotal = 0.00;
            $grandTotal = 0.00;

            // Validate all items exist in medicine master and compute totals
            $medStmt = $this->pdo->prepare("SELECT medicine_id, medicine_name, status FROM medicines WHERE medicine_id = ?");
            $processedItems = [];

            foreach ($items as $idx => $item) {
                $medId = (int)($item['medicine_id'] ?? 0);
                $medStmt->execute([$medId]);
                $med = $medStmt->fetch(PDO::FETCH_ASSOC);
                if (!$med) {
                    throw new InvalidArgumentException("Line #" . ($idx + 1) . ": Medicine ID {$medId} not found in Medicine Master.");
                }
                if ($med['status'] !== 'Active') {
                    throw new InvalidArgumentException("Line #" . ($idx + 1) . ": Medicine '{$med['medicine_name']}' is not Active.");
                }

                $qty = (int)($item['requested_qty'] ?? 0);
                if ($qty <= 0) {
                    throw new InvalidArgumentException("Line #" . ($idx + 1) . ": Quantity must be greater than zero.");
                }

                $freeQty = max(0, (int)($item['free_qty'] ?? 0));
                $rate = max(0.0, (float)($item['purchase_rate'] ?? 0.00));
                $discPct = max(0.0, min(100.0, (float)($item['discount_percent'] ?? 0.00)));
                $gstPct = max(0.0, (float)($item['gst_percent'] ?? 0.00));
                $expMrp = max(0.0, (float)($item['expected_mrp'] ?? 0.00));
                $expSale = max(0.0, (float)($item['expected_sale_price'] ?? $expMrp));
                $packSize = !empty($item['pack_size']) ? trim($item['pack_size']) : '1';
                $lineNote = !empty($item['notes']) ? trim($item['notes']) : null;

                $gross = $qty * $rate;
                $discAmt = $gross * ($discPct / 100.0);
                $taxable = $gross - $discAmt;
                $taxAmt = $taxable * ($gstPct / 100.0);
                $lineTot = round($taxable + $taxAmt, 2);

                $subtotal += $taxable;
                $discountTotal += $discAmt;
                $taxTotal += $taxAmt;
                $grandTotal += $lineTot;

                $processedItems[] = [
                    'medicine_id'         => $medId,
                    'pack_size'           => $packSize,
                    'requested_qty'       => $qty,
                    'free_qty'            => $freeQty,
                    'purchase_rate'       => $rate,
                    'discount_percent'    => $discPct,
                    'gst_percent'         => $gstPct,
                    'expected_mrp'        => $expMrp,
                    'expected_sale_price' => $expSale,
                    'line_total'          => $lineTot,
                    'notes'               => $lineNote
                ];
            }

            $stmtHeader = $this->pdo->prepare("
                INSERT INTO pharmacy_purchase_orders (
                    po_number, po_date, supplier_id, expected_delivery_date, payment_terms,
                    status, subtotal_amount, discount_amount, tax_amount, total_amount,
                    notes, approved_by, approved_at, created_by, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, NOW(), NOW()
                )
            ");

            $approvedBy = ($initialStatus === 'APPROVED') ? $userId : null;
            $approvedAt = ($initialStatus === 'APPROVED') ? date('Y-m-d H:i:s') : null;

            $stmtHeader->execute([
                $poNumber, $poDate, $supplierId, $expectedDelivery, $paymentTerms,
                $initialStatus, round($subtotal, 2), round($discountTotal, 2), round($taxTotal, 2), round($grandTotal, 2),
                $notes, $approvedBy, $approvedAt, $userId
            ]);

            $poId = (int)$this->pdo->lastInsertId();

            $stmtItem = $this->pdo->prepare("
                INSERT INTO pharmacy_purchase_order_items (
                    po_id, medicine_id, pack_size, requested_qty, free_qty, received_qty,
                    purchase_rate, discount_percent, gst_percent, expected_mrp, expected_sale_price,
                    line_total, notes
                ) VALUES (
                    ?, ?, ?, ?, ?, 0,
                    ?, ?, ?, ?, ?,
                    ?, ?
                )
            ");

            foreach ($processedItems as $pItem) {
                $stmtItem->execute([
                    $poId, $pItem['medicine_id'], $pItem['pack_size'], $pItem['requested_qty'], $pItem['free_qty'],
                    $pItem['purchase_rate'], $pItem['discount_percent'], $pItem['gst_percent'],
                    $pItem['expected_mrp'], $pItem['expected_sale_price'],
                    $pItem['line_total'], $pItem['notes']
                ]);
            }

            $this->auditService->logAction(
                $userId,
                'CREATE_PURCHASE_ORDER',
                'pharmacy_purchase_orders',
                $poId,
                null,
                ['po_number' => $poNumber, 'supplier_id' => $supplierId, 'total_amount' => $grandTotal, 'items_count' => count($processedItems)]
            );

            $this->pdo->commit();
            return $poId;
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Update an existing purchase order.
     * Preserves original record ID and PO number.
     * Allowed only if PO has not received items.
     */
    public function updatePurchaseOrder(int $poId, array $header, array $items, int $userId): bool
    {
        $existingPo = $this->getPurchaseOrder($poId);
        if (!$existingPo) {
            throw new InvalidArgumentException("Purchase order #{$poId} not found.");
        }

        if (in_array($existingPo['status'], ['PARTIALLY_RECEIVED', 'FULLY_RECEIVED', 'CANCELLED', 'CLOSED'], true)) {
            throw new InvalidArgumentException("Cannot modify purchase order in status '{$existingPo['status']}'.");
        }

        $receivedCount = (int)($this->pdo->query("SELECT IFNULL(SUM(received_qty), 0) FROM pharmacy_purchase_order_items WHERE po_id = {$poId}")->fetchColumn() ?: 0);
        if ($receivedCount > 0) {
            throw new InvalidArgumentException("Cannot modify purchase order that has already received stock.");
        }

        $supplierId = (int)($header['supplier_id'] ?? $existingPo['supplier_id']);
        if ($supplierId <= 0) {
            throw new InvalidArgumentException("Valid supplier must be selected.");
        }

        $supStmt = $this->pdo->prepare("SELECT supplier_id, status FROM pharmacy_suppliers WHERE supplier_id = ?");
        $supStmt->execute([$supplierId]);
        $supplier = $supStmt->fetch(PDO::FETCH_ASSOC);
        if (!$supplier) {
            throw new InvalidArgumentException("Supplier does not exist.");
        }
        if ($supplier['status'] === 'Blocked') {
            throw new InvalidArgumentException("Cannot place purchase orders to a Blocked supplier.");
        }

        $poDate = !empty($header['po_date']) ? $header['po_date'] : $existingPo['po_date'];
        $expectedDelivery = !empty($header['expected_delivery_date']) ? $header['expected_delivery_date'] : null;
        $paymentTerms = !empty($header['payment_terms']) ? trim($header['payment_terms']) : null;
        $notes = !empty($header['notes']) ? trim($header['notes']) : null;

        if (empty($items)) {
            throw new InvalidArgumentException("Purchase order must contain at least one medicine item.");
        }

        $this->pdo->beginTransaction();
        try {
            $subtotal = 0.00;
            $discountTotal = 0.00;
            $taxTotal = 0.00;
            $grandTotal = 0.00;

            $medStmt = $this->pdo->prepare("SELECT medicine_id, medicine_name, status FROM medicines WHERE medicine_id = ?");
            $processedItems = [];

            foreach ($items as $idx => $item) {
                $medId = (int)($item['medicine_id'] ?? 0);
                $medStmt->execute([$medId]);
                $med = $medStmt->fetch(PDO::FETCH_ASSOC);
                if (!$med) {
                    throw new InvalidArgumentException("Line #" . ($idx + 1) . ": Medicine ID {$medId} not found in Medicine Master.");
                }
                if ($med['status'] !== 'Active') {
                    throw new InvalidArgumentException("Line #" . ($idx + 1) . ": Medicine '{$med['medicine_name']}' is not Active.");
                }

                $qty = (int)($item['requested_qty'] ?? 0);
                if ($qty <= 0) {
                    throw new InvalidArgumentException("Line #" . ($idx + 1) . ": Quantity must be greater than zero.");
                }

                $freeQty = max(0, (int)($item['free_qty'] ?? 0));
                $rate = max(0.0, (float)($item['purchase_rate'] ?? 0.00));
                $discPct = max(0.0, min(100.0, (float)($item['discount_percent'] ?? 0.00)));
                $gstPct = max(0.0, (float)($item['gst_percent'] ?? 0.00));
                $expMrp = max(0.0, (float)($item['expected_mrp'] ?? 0.00));
                $expSale = max(0.0, (float)($item['expected_sale_price'] ?? $expMrp));
                $packSize = !empty($item['pack_size']) ? trim($item['pack_size']) : '1';
                $lineNote = !empty($item['notes']) ? trim($item['notes']) : null;

                $gross = $qty * $rate;
                $discAmt = $gross * ($discPct / 100.0);
                $taxable = $gross - $discAmt;
                $taxAmt = $taxable * ($gstPct / 100.0);
                $lineTot = round($taxable + $taxAmt, 2);

                $subtotal += $taxable;
                $discountTotal += $discAmt;
                $taxTotal += $taxAmt;
                $grandTotal += $lineTot;

                $processedItems[] = [
                    'medicine_id'         => $medId,
                    'pack_size'           => $packSize,
                    'requested_qty'       => $qty,
                    'free_qty'            => $freeQty,
                    'purchase_rate'       => $rate,
                    'discount_percent'    => $discPct,
                    'gst_percent'         => $gstPct,
                    'expected_mrp'        => $expMrp,
                    'expected_sale_price' => $expSale,
                    'line_total'          => $lineTot,
                    'notes'               => $lineNote
                ];
            }

            // Update Header (preserves po_id and po_number)
            $updateHeaderStmt = $this->pdo->prepare("
                UPDATE pharmacy_purchase_orders SET
                    supplier_id = ?,
                    po_date = ?,
                    expected_delivery_date = ?,
                    payment_terms = ?,
                    subtotal_amount = ?,
                    discount_amount = ?,
                    tax_amount = ?,
                    total_amount = ?,
                    notes = ?,
                    updated_at = NOW()
                WHERE po_id = ?
            ");

            $updateHeaderStmt->execute([
                $supplierId, $poDate, $expectedDelivery, $paymentTerms,
                round($subtotal, 2), round($discountTotal, 2), round($taxTotal, 2), round($grandTotal, 2),
                $notes, $poId
            ]);

            // Replace line items
            $delItemsStmt = $this->pdo->prepare("DELETE FROM pharmacy_purchase_order_items WHERE po_id = ?");
            $delItemsStmt->execute([$poId]);

            $stmtItem = $this->pdo->prepare("
                INSERT INTO pharmacy_purchase_order_items (
                    po_id, medicine_id, pack_size, requested_qty, free_qty, received_qty,
                    purchase_rate, discount_percent, gst_percent, expected_mrp, expected_sale_price,
                    line_total, notes
                ) VALUES (
                    ?, ?, ?, ?, ?, 0,
                    ?, ?, ?, ?, ?,
                    ?, ?
                )
            ");

            foreach ($processedItems as $pItem) {
                $stmtItem->execute([
                    $poId, $pItem['medicine_id'], $pItem['pack_size'], $pItem['requested_qty'], $pItem['free_qty'],
                    $pItem['purchase_rate'], $pItem['discount_percent'], $pItem['gst_percent'],
                    $pItem['expected_mrp'], $pItem['expected_sale_price'],
                    $pItem['line_total'], $pItem['notes']
                ]);
            }

            $this->auditService->logAction(
                $userId,
                'UPDATE_PURCHASE_ORDER',
                'pharmacy_purchase_orders',
                $poId,
                [
                    'supplier_id' => $existingPo['supplier_id'],
                    'total_amount' => $existingPo['total_amount'],
                    'items_count' => count($existingPo['items'] ?? [])
                ],
                [
                    'supplier_id' => $supplierId,
                    'total_amount' => $grandTotal,
                    'items_count' => count($processedItems)
                ]
            );

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Approve a purchase order. Zero stock impact.
     */
    public function approvePurchaseOrder(int $poId, int $userId): bool
    {
        $po = $this->getPurchaseOrder($poId);
        if (!$po) {
            throw new InvalidArgumentException("Purchase order not found.");
        }

        if (in_array($po['status'], ['APPROVED', 'PARTIALLY_RECEIVED', 'FULLY_RECEIVED'], true)) {
            return true; // Already approved
        }

        if ($po['status'] === 'CANCELLED' || $po['status'] === 'CLOSED') {
            throw new InvalidArgumentException("Cannot approve a {$po['status']} purchase order.");
        }

        $stmt = $this->pdo->prepare("
            UPDATE pharmacy_purchase_orders SET
                status = 'APPROVED',
                approved_by = ?,
                approved_at = NOW(),
                updated_at = NOW()
            WHERE po_id = ?
        ");
        $stmt->execute([$userId, $poId]);

        $this->auditService->logAction($userId, 'APPROVE_PURCHASE_ORDER', 'pharmacy_purchase_orders', $poId, ['status' => $po['status']], ['status' => 'APPROVED']);
        return true;
    }

    /**
     * Cancel a purchase order. Fails if items have already been received. Zero stock impact.
     */
    public function cancelPurchaseOrder(int $poId, string $reason, int $userId): bool
    {
        $po = $this->getPurchaseOrder($poId);
        if (!$po) {
            throw new InvalidArgumentException("Purchase order not found.");
        }

        if (in_array($po['status'], ['PARTIALLY_RECEIVED', 'FULLY_RECEIVED'], true)) {
            throw new InvalidArgumentException("Cannot cancel a purchase order that has already received stock.");
        }

        if ($po['status'] === 'CANCELLED') {
            return true;
        }

        $stmt = $this->pdo->prepare("
            UPDATE pharmacy_purchase_orders SET
                status = 'CANCELLED',
                cancelled_by = ?,
                cancelled_at = NOW(),
                cancellation_reason = ?,
                updated_at = NOW()
            WHERE po_id = ?
        ");
        $stmt->execute([$userId, $reason, $poId]);

        $this->auditService->logAction($userId, 'CANCEL_PURCHASE_ORDER', 'pharmacy_purchase_orders', $poId, ['status' => $po['status']], ['status' => 'CANCELLED', 'reason' => $reason]);
        return true;
    }

    /**
     * Get single purchase order with items and supplier info.
     */
    public function getPurchaseOrder(int $poId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT po.*, s.supplier_name, s.supplier_code, s.phone as supplier_phone, s.email as supplier_email,
                   s.gstin as supplier_gstin, s.drug_licence_no as supplier_drug_licence, s.address as supplier_address,
                   s.city as supplier_city, s.state as supplier_state, s.pincode as supplier_pincode,
                   u.full_name as creator_name, ap.full_name as approver_name
            FROM pharmacy_purchase_orders po
            JOIN pharmacy_suppliers s ON po.supplier_id = s.supplier_id
            LEFT JOIN pharmacy_users u ON po.created_by = u.id
            LEFT JOIN pharmacy_users ap ON po.approved_by = ap.id
            WHERE po.po_id = ?
        ");
        $stmt->execute([$poId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) return null;

        $itemsStmt = $this->pdo->prepare("
            SELECT poi.*, m.medicine_name, m.generic_name, m.strength, m.dosage_form, m.manufacturer, m.hsn_code
            FROM pharmacy_purchase_order_items poi
            JOIN medicines m ON poi.medicine_id = m.medicine_id
            WHERE poi.po_id = ?
            ORDER BY poi.po_item_id ASC
        ");
        $itemsStmt->execute([$poId]);
        $order['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        return $order;
    }

    /**
     * List purchase orders with filters.
     */
    public function listPurchaseOrders(array $filters = []): array
    {
        $sql = "
            SELECT po.*, s.supplier_name, s.supplier_code,
                   (SELECT COUNT(*) FROM pharmacy_purchase_order_items WHERE po_id = po.po_id) as item_count,
                   (SELECT IFNULL(SUM(received_qty), 0) FROM pharmacy_purchase_order_items WHERE po_id = po.po_id) as total_received_units,
                   (SELECT IFNULL(SUM(requested_qty), 0) FROM pharmacy_purchase_order_items WHERE po_id = po.po_id) as total_requested_units
            FROM pharmacy_purchase_orders po
            JOIN pharmacy_suppliers s ON po.supplier_id = s.supplier_id
            WHERE 1=1
        ";
        $params = [];

        if (!empty($filters['search'])) {
            $term = '%' . trim($filters['search']) . '%';
            $sql .= " AND (po.po_number LIKE ? OR s.supplier_name LIKE ?)";
            $params = array_merge($params, [$term, $term]);
        }

        if (!empty($filters['supplier_id'])) {
            $sql .= " AND po.supplier_id = ?";
            $params[] = (int)$filters['supplier_id'];
        }

        if (!empty($filters['status'])) {
            $sql .= " AND po.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND po.po_date >= ?";
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND po.po_date <= ?";
            $params[] = $filters['date_to'];
        }

        $sql .= " ORDER BY po.po_id DESC";

        if (isset($filters['limit'])) {
            $limit = max(1, (int)$filters['limit']);
            $offset = max(0, (int)($filters['offset'] ?? 0));
            $sql .= " LIMIT {$limit} OFFSET {$offset}";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
