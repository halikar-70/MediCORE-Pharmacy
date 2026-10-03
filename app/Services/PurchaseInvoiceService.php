<?php
// app/Services/PurchaseInvoiceService.php - Purchase Invoice / Bill & 3-Way Matching

namespace Pharmacy\Services;

use PDO;
use Exception;
use InvalidArgumentException;

class PurchaseInvoiceService
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
     * Create a new Purchase Invoice.
     * ZERO stock impact. Stock is solely managed through GRN and stock ledger.
     */
    public function createPurchaseInvoice(array $header, array $items, array $grnIds = [], int $userId = 1): int
    {
        $supplierId = (int)($header['supplier_id'] ?? 0);
        if ($supplierId <= 0) {
            throw new InvalidArgumentException("A valid supplier must be selected.");
        }

        $supplierInvoiceNo = trim($header['supplier_invoice_no'] ?? '');
        if ($supplierInvoiceNo === '') {
            throw new InvalidArgumentException("Supplier Bill / Invoice Number is mandatory.");
        }

        // Duplicate invoice number check per supplier
        $dupCheck = $this->pdo->prepare("SELECT invoice_id FROM pharmacy_purchase_invoices WHERE supplier_id = ? AND supplier_invoice_no = ?");
        $dupCheck->execute([$supplierId, $supplierInvoiceNo]);
        if ($dupCheck->fetchColumn()) {
            throw new InvalidArgumentException("Invoice '{$supplierInvoiceNo}' from this supplier has already been recorded.");
        }

        $invoiceDate = !empty($header['invoice_date']) ? $header['invoice_date'] : date('Y-m-d');
        $dueDate = !empty($header['due_date']) ? $header['due_date'] : date('Y-m-d', strtotime($invoiceDate . ' +30 days'));
        $poId = !empty($header['po_id']) ? (int)$header['po_id'] : null;
        $primaryGrnId = !empty($grnIds) ? (int)$grnIds[0] : (!empty($header['grn_id']) ? (int)$header['grn_id'] : null);
        $paymentTerms = !empty($header['payment_terms']) ? trim($header['payment_terms']) : null;
        $notes = !empty($header['notes']) ? trim($header['notes']) : null;

        if (empty($items)) {
            throw new InvalidArgumentException("Invoice must contain at least one line item.");
        }

        $this->pdo->beginTransaction();
        try {
            // Generate dedicated Purchase Invoice internal sequence
            $internalInvNo = $this->seqService->generate('PURCHASE_INVOICE', 'PINV-');

            $taxableSum = 0.00;
            $discSum = 0.00;
            $gstSum = 0.00;
            $grandSum = 0.00;

            $hasQtyMismatch = false;
            $hasPriceMismatch = false;
            $hasTaxMismatch = false;

            $processedItems = [];

            foreach ($items as $idx => $item) {
                $lineNum = $idx + 1;
                $medId = (int)($item['medicine_id'] ?? 0);
                if ($medId <= 0) {
                    throw new InvalidArgumentException("Line #{$lineNum}: Invalid medicine.");
                }

                $qty = (int)($item['quantity'] ?? 0);
                if ($qty <= 0) {
                    throw new InvalidArgumentException("Line #{$lineNum}: Quantity must be positive.");
                }

                $freeQty = max(0, (int)($item['free_qty'] ?? 0));
                $rate = max(0.0, (float)($item['purchase_rate'] ?? 0.00));
                $discPct = max(0.0, min(100.0, (float)($item['discount_percent'] ?? 0.00)));
                $gstPct = max(0.0, (float)($item['gst_percent'] ?? 0.00));
                $batchId = !empty($item['batch_id']) ? (int)$item['batch_id'] : null;
                $batchNum = !empty($item['batch_number']) ? trim($item['batch_number']) : null;
                $grnItemId = !empty($item['grn_item_id']) ? (int)$item['grn_item_id'] : null;

                $gross = $qty * $rate;
                $discAmt = $gross * ($discPct / 100.0);
                $taxable = $gross - $discAmt;
                $gstAmt = $taxable * ($gstPct / 100.0);
                $lineTot = round($taxable + $gstAmt, 2);

                $taxableSum += $taxable;
                $discSum += $discAmt;
                $gstSum += $gstAmt;
                $grandSum += $lineTot;

                // 3-Way match check against linked GRN item if provided
                if ($grnItemId !== null) {
                    $gItemStmt = $this->pdo->prepare("SELECT received_qty, accepted_qty, purchase_rate, gst_percent FROM pharmacy_grn_items WHERE grn_item_id = ?");
                    $gItemStmt->execute([$grnItemId]);
                    $gItem = $gItemStmt->fetch(PDO::FETCH_ASSOC);
                    if ($gItem) {
                        if ((int)$gItem['accepted_qty'] !== $qty && (int)$gItem['received_qty'] !== $qty) {
                            $hasQtyMismatch = true;
                        }
                        if (abs((float)$gItem['purchase_rate'] - $rate) > 0.01) {
                            $hasPriceMismatch = true;
                        }
                        if (abs((float)$gItem['gst_percent'] - $gstPct) > 0.01) {
                            $hasTaxMismatch = true;
                        }
                    }
                }

                $processedItems[] = [
                    'grn_item_id'      => $grnItemId,
                    'medicine_id'      => $medId,
                    'batch_id'         => $batchId,
                    'batch_number'     => $batchNum,
                    'quantity'         => $qty,
                    'free_qty'         => $freeQty,
                    'purchase_rate'    => $rate,
                    'discount_percent' => $discPct,
                    'gst_percent'      => $gstPct,
                    'taxable_amount'   => round($taxable, 2),
                    'gst_amount'       => round($gstAmt, 2),
                    'line_total'       => $lineTot
                ];
            }

            $otherCharges = isset($header['other_charges']) ? (float)$header['other_charges'] : 0.00;
            $totalBeforeRound = $grandSum + $otherCharges;
            $roundedGrandTotal = round($totalBeforeRound);
            $roundOff = round($roundedGrandTotal - $totalBeforeRound, 2);

            // Determine 3-way match status
            $matchStatus = 'MATCHED';
            if ($hasQtyMismatch) {
                $matchStatus = 'QUANTITY_MISMATCH';
            } elseif ($hasPriceMismatch) {
                $matchStatus = 'PRICE_MISMATCH';
            } elseif ($hasTaxMismatch) {
                $matchStatus = 'TAX_MISMATCH';
            }

            // Outstanding starts as the full grand total
            $outstanding = $roundedGrandTotal;

            $stmtHeader = $this->pdo->prepare("
                INSERT INTO pharmacy_purchase_invoices (
                    invoice_number, supplier_invoice_no, supplier_id, po_id, grn_id,
                    invoice_date, due_date, taxable_amount, discount_amount, gst_amount,
                    other_charges, round_off, grand_total, amount_paid, outstanding_amount,
                    payment_status, match_status, payment_terms, notes, created_by, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, 0.00, ?,
                    'UNPAID', ?, ?, ?, ?, NOW(), NOW()
                )
            ");

            $stmtHeader->execute([
                $internalInvNo, $supplierInvoiceNo, $supplierId, $poId, $primaryGrnId,
                $invoiceDate, $dueDate, round($taxableSum, 2), round($discSum, 2), round($gstSum, 2),
                round($otherCharges, 2), $roundOff, $roundedGrandTotal, $outstanding,
                $matchStatus, $paymentTerms, $notes, $userId
            ]);

            $invoiceId = (int)$this->pdo->lastInsertId();

            // Link GRNs in junction table
            $junctionStmt = $this->pdo->prepare("INSERT IGNORE INTO pharmacy_purchase_invoice_grns (invoice_id, grn_id) VALUES (?, ?)");
            foreach ($grnIds as $gid) {
                $junctionStmt->execute([$invoiceId, (int)$gid]);
            }
            if ($primaryGrnId && !in_array($primaryGrnId, $grnIds, true)) {
                $junctionStmt->execute([$invoiceId, $primaryGrnId]);
            }

            // Insert line items
            $stmtItem = $this->pdo->prepare("
                INSERT INTO pharmacy_purchase_invoice_items (
                    invoice_id, grn_item_id, medicine_id, batch_id, batch_number,
                    quantity, free_qty, purchase_rate, discount_percent, gst_percent,
                    taxable_amount, gst_amount, line_total
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?
                )
            ");

            foreach ($processedItems as $p) {
                $stmtItem->execute([
                    $invoiceId, $p['grn_item_id'], $p['medicine_id'], $p['batch_id'], $p['batch_number'],
                    $p['quantity'], $p['free_qty'], $p['purchase_rate'], $p['discount_percent'], $p['gst_percent'],
                    $p['taxable_amount'], $p['gst_amount'], $p['line_total']
                ]);
            }

            $this->auditService->logAction(
                $userId,
                'CREATE_PURCHASE_INVOICE',
                'pharmacy_purchase_invoices',
                $invoiceId,
                null,
                [
                    'invoice_number'      => $internalInvNo,
                    'supplier_invoice_no' => $supplierInvoiceNo,
                    'supplier_id'         => $supplierId,
                    'grand_total'         => $roundedGrandTotal,
                    'match_status'        => $matchStatus
                ]
            );

            $this->pdo->commit();
            return $invoiceId;
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Update an existing purchase invoice.
     * Preserves original record ID and internal invoice number.
     * Respects payment settlement status.
     */
    public function updatePurchaseInvoice(int $invoiceId, array $header, array $items, int $userId = 1): bool
    {
        $existing = $this->getPurchaseInvoice($invoiceId);
        if (!$existing) {
            throw new InvalidArgumentException("Purchase invoice #{$invoiceId} not found.");
        }

        if ($existing['payment_status'] === 'PAID') {
            throw new InvalidArgumentException("Cannot modify invoice #{$invoiceId} because it has already been FULLY PAID.");
        }

        $amountPaid = (float)($existing['amount_paid'] ?? 0.0);
        $supplierId = (int)($header['supplier_id'] ?? $existing['supplier_id']);
        if ($supplierId <= 0) {
            throw new InvalidArgumentException("A valid supplier must be selected.");
        }

        $supplierInvoiceNo = trim($header['supplier_invoice_no'] ?? $existing['supplier_invoice_no']);
        if ($supplierInvoiceNo === '') {
            throw new InvalidArgumentException("Supplier Bill / Invoice Number is mandatory.");
        }

        // Duplicate invoice number check per supplier (excluding self)
        $dupCheck = $this->pdo->prepare("SELECT invoice_id FROM pharmacy_purchase_invoices WHERE supplier_id = ? AND supplier_invoice_no = ? AND invoice_id != ?");
        $dupCheck->execute([$supplierId, $supplierInvoiceNo, $invoiceId]);
        if ($dupCheck->fetchColumn()) {
            throw new InvalidArgumentException("Invoice '{$supplierInvoiceNo}' from this supplier has already been recorded on another bill.");
        }

        $invoiceDate = !empty($header['invoice_date']) ? $header['invoice_date'] : $existing['invoice_date'];
        $dueDate = !empty($header['due_date']) ? $header['due_date'] : $existing['due_date'];
        $paymentTerms = !empty($header['payment_terms']) ? trim($header['payment_terms']) : null;
        $notes = !empty($header['notes']) ? trim($header['notes']) : null;
        $otherCharges = isset($header['other_charges']) ? (float)$header['other_charges'] : (float)$existing['other_charges'];

        // If partially paid, items and amounts are locked to maintain accounting integrity
        if ($amountPaid > 0) {
            $stmt = $this->pdo->prepare("
                UPDATE pharmacy_purchase_invoices SET
                    supplier_invoice_no = ?,
                    due_date = ?,
                    payment_terms = ?,
                    notes = ?,
                    updated_at = NOW()
                WHERE invoice_id = ?
            ");
            $stmt->execute([$supplierInvoiceNo, $dueDate, $paymentTerms, $notes, $invoiceId]);

            $this->auditService->logAction(
                $userId,
                'UPDATE_PURCHASE_INVOICE_METADATA',
                'pharmacy_purchase_invoices',
                $invoiceId,
                ['notes' => $existing['notes'], 'supplier_invoice_no' => $existing['supplier_invoice_no']],
                ['notes' => $notes, 'supplier_invoice_no' => $supplierInvoiceNo]
            );
            return true;
        }

        if (empty($items)) {
            throw new InvalidArgumentException("Invoice must contain at least one line item.");
        }

        $this->pdo->beginTransaction();
        try {
            $taxableSum = 0.00;
            $discSum = 0.00;
            $gstSum = 0.00;
            $grandSum = 0.00;

            $hasQtyMismatch = false;
            $hasPriceMismatch = false;
            $hasTaxMismatch = false;

            $processedItems = [];

            foreach ($items as $idx => $item) {
                $lineNum = $idx + 1;
                $medId = (int)($item['medicine_id'] ?? 0);
                if ($medId <= 0) {
                    throw new InvalidArgumentException("Line #{$lineNum}: Invalid medicine.");
                }

                $qty = (int)($item['quantity'] ?? 0);
                if ($qty <= 0) {
                    throw new InvalidArgumentException("Line #{$lineNum}: Quantity must be positive.");
                }

                $freeQty = max(0, (int)($item['free_qty'] ?? 0));
                $rate = max(0.0, (float)($item['purchase_rate'] ?? 0.00));
                $discPct = max(0.0, min(100.0, (float)($item['discount_percent'] ?? 0.00)));
                $gstPct = max(0.0, (float)($item['gst_percent'] ?? 0.00));
                $batchId = !empty($item['batch_id']) ? (int)$item['batch_id'] : null;
                $batchNum = !empty($item['batch_number']) ? trim($item['batch_number']) : null;
                $grnItemId = !empty($item['grn_item_id']) ? (int)$item['grn_item_id'] : null;

                $gross = $qty * $rate;
                $discAmt = $gross * ($discPct / 100.0);
                $taxable = $gross - $discAmt;
                $gstAmt = $taxable * ($gstPct / 100.0);
                $lineTot = round($taxable + $gstAmt, 2);

                $taxableSum += $taxable;
                $discSum += $discAmt;
                $gstSum += $gstAmt;
                $grandSum += $lineTot;

                // 3-Way match check against linked GRN item if provided
                if ($grnItemId !== null) {
                    $gItemStmt = $this->pdo->prepare("SELECT received_qty, accepted_qty, purchase_rate, gst_percent FROM pharmacy_grn_items WHERE grn_item_id = ?");
                    $gItemStmt->execute([$grnItemId]);
                    $gItem = $gItemStmt->fetch(PDO::FETCH_ASSOC);
                    if ($gItem) {
                        if ((int)$gItem['accepted_qty'] !== $qty && (int)$gItem['received_qty'] !== $qty) {
                            $hasQtyMismatch = true;
                        }
                        if (abs((float)$gItem['purchase_rate'] - $rate) > 0.01) {
                            $hasPriceMismatch = true;
                        }
                        if (abs((float)$gItem['gst_percent'] - $gstPct) > 0.01) {
                            $hasTaxMismatch = true;
                        }
                    }
                }

                $processedItems[] = [
                    'grn_item_id'      => $grnItemId,
                    'medicine_id'      => $medId,
                    'batch_id'         => $batchId,
                    'batch_number'     => $batchNum,
                    'quantity'         => $qty,
                    'free_qty'         => $freeQty,
                    'purchase_rate'    => $rate,
                    'discount_percent' => $discPct,
                    'gst_percent'      => $gstPct,
                    'taxable_amount'   => round($taxable, 2),
                    'gst_amount'       => round($gstAmt, 2),
                    'line_total'       => $lineTot
                ];
            }

            $totalBeforeRound = $grandSum + $otherCharges;
            $roundedGrandTotal = round($totalBeforeRound);
            $roundOff = round($roundedGrandTotal - $totalBeforeRound, 2);

            $matchStatus = 'MATCHED';
            if ($hasQtyMismatch) {
                $matchStatus = 'QUANTITY_MISMATCH';
            } elseif ($hasPriceMismatch) {
                $matchStatus = 'PRICE_MISMATCH';
            } elseif ($hasTaxMismatch) {
                $matchStatus = 'TAX_MISMATCH';
            }

            $outstanding = $roundedGrandTotal - $amountPaid;

            $stmtHeader = $this->pdo->prepare("
                UPDATE pharmacy_purchase_invoices SET
                    supplier_id = ?,
                    supplier_invoice_no = ?,
                    invoice_date = ?,
                    due_date = ?,
                    taxable_amount = ?,
                    discount_amount = ?,
                    gst_amount = ?,
                    other_charges = ?,
                    round_off = ?,
                    grand_total = ?,
                    outstanding_amount = ?,
                    match_status = ?,
                    payment_terms = ?,
                    notes = ?,
                    updated_at = NOW()
                WHERE invoice_id = ?
            ");

            $stmtHeader->execute([
                $supplierId, $supplierInvoiceNo, $invoiceDate, $dueDate,
                round($taxableSum, 2), round($discSum, 2), round($gstSum, 2),
                round($otherCharges, 2), $roundOff, $roundedGrandTotal, $outstanding,
                $matchStatus, $paymentTerms, $notes, $invoiceId
            ]);

            // Replace line items
            $delStmt = $this->pdo->prepare("DELETE FROM pharmacy_purchase_invoice_items WHERE invoice_id = ?");
            $delStmt->execute([$invoiceId]);

            $stmtItem = $this->pdo->prepare("
                INSERT INTO pharmacy_purchase_invoice_items (
                    invoice_id, grn_item_id, medicine_id, batch_id, batch_number,
                    quantity, free_qty, purchase_rate, discount_percent, gst_percent,
                    taxable_amount, gst_amount, line_total
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?
                )
            ");

            foreach ($processedItems as $p) {
                $stmtItem->execute([
                    $invoiceId, $p['grn_item_id'], $p['medicine_id'], $p['batch_id'], $p['batch_number'],
                    $p['quantity'], $p['free_qty'], $p['purchase_rate'], $p['discount_percent'], $p['gst_percent'],
                    $p['taxable_amount'], $p['gst_amount'], $p['line_total']
                ]);
            }

            $this->auditService->logAction(
                $userId,
                'UPDATE_PURCHASE_INVOICE',
                'pharmacy_purchase_invoices',
                $invoiceId,
                [
                    'grand_total' => $existing['grand_total'],
                    'match_status' => $existing['match_status']
                ],
                [
                    'supplier_invoice_no' => $supplierInvoiceNo,
                    'grand_total'         => $roundedGrandTotal,
                    'match_status'        => $matchStatus
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
     * Get single purchase invoice with items, supplier, PO, GRN, and payment allocations.
     */
    public function getPurchaseInvoice(int $invoiceId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT pi.*, s.supplier_name, s.supplier_code, s.phone as supplier_phone, s.gstin as supplier_gstin,
                   s.drug_licence_no as supplier_drug_licence, s.address as supplier_address,
                   po.po_number, g.grn_number, u.full_name as creator_name
            FROM pharmacy_purchase_invoices pi
            JOIN pharmacy_suppliers s ON pi.supplier_id = s.supplier_id
            LEFT JOIN pharmacy_purchase_orders po ON pi.po_id = po.po_id
            LEFT JOIN pharmacy_grn g ON pi.grn_id = g.grn_id
            LEFT JOIN pharmacy_users u ON pi.created_by = u.id
            WHERE pi.invoice_id = ?
        ");
        $stmt->execute([$invoiceId]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inv) return null;

        $itemsStmt = $this->pdo->prepare("
            SELECT pii.*, m.medicine_name, m.generic_name, m.strength, m.dosage_form, m.manufacturer
            FROM pharmacy_purchase_invoice_items pii
            JOIN medicines m ON pii.medicine_id = m.medicine_id
            WHERE pii.invoice_id = ?
            ORDER BY pii.invoice_item_id ASC
        ");
        $itemsStmt->execute([$invoiceId]);
        $inv['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get linked GRNs
        $grnsStmt = $this->pdo->prepare("
            SELECT g.grn_id, g.grn_number, g.grn_date, g.total_amount
            FROM pharmacy_purchase_invoice_grns pig
            JOIN pharmacy_grn g ON pig.grn_id = g.grn_id
            WHERE pig.invoice_id = ?
        ");
        $grnsStmt->execute([$invoiceId]);
        $inv['linked_grns'] = $grnsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get payment allocations
        $allocStmt = $this->pdo->prepare("
            SELECT a.allocation_id, a.allocated_amount, a.created_at,
                   p.payment_id, p.payment_number, p.payment_date, p.payment_mode, p.reference_no
            FROM pharmacy_supplier_payment_allocations a
            JOIN pharmacy_supplier_payments p ON a.payment_id = p.payment_id
            WHERE a.invoice_id = ? AND p.status = 'Completed'
            ORDER BY a.allocation_id ASC
        ");
        $allocStmt->execute([$invoiceId]);
        $inv['payments'] = $allocStmt->fetchAll(PDO::FETCH_ASSOC);

        return $inv;
    }

    /**
     * List purchase invoices with filters.
     */
    public function listPurchaseInvoices(array $filters = []): array
    {
        $sql = "
            SELECT pi.*, s.supplier_name, s.supplier_code, po.po_number, g.grn_number,
                   DATEDIFF(CURRENT_DATE(), pi.due_date) as days_overdue
            FROM pharmacy_purchase_invoices pi
            JOIN pharmacy_suppliers s ON pi.supplier_id = s.supplier_id
            LEFT JOIN pharmacy_purchase_orders po ON pi.po_id = po.po_id
            LEFT JOIN pharmacy_grn g ON pi.grn_id = g.grn_id
            WHERE 1=1
        ";
        $params = [];

        if (!empty($filters['search'])) {
            $term = '%' . trim($filters['search']) . '%';
            $sql .= " AND (pi.invoice_number LIKE ? OR pi.supplier_invoice_no LIKE ? OR s.supplier_name LIKE ?)";
            $params = array_merge($params, [$term, $term, $term]);
        }

        if (!empty($filters['supplier_id'])) {
            $sql .= " AND pi.supplier_id = ?";
            $params[] = (int)$filters['supplier_id'];
        }

        if (!empty($filters['payment_status'])) {
            $sql .= " AND pi.payment_status = ?";
            $params[] = $filters['payment_status'];
        }

        if (!empty($filters['match_status'])) {
            $sql .= " AND pi.match_status = ?";
            $params[] = $filters['match_status'];
        }

        if (!empty($filters['overdue_only'])) {
            $sql .= " AND pi.due_date < CURRENT_DATE() AND pi.outstanding_amount > 0 AND pi.payment_status != 'PAID'";
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND pi.invoice_date >= ?";
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND pi.invoice_date <= ?";
            $params[] = $filters['date_to'];
        }

        $sql .= " ORDER BY pi.invoice_id DESC";

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
