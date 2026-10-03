<?php
// app/Services/GrnService.php - Goods Received Note, Atomic Stock Inward & Batch Integration

namespace Pharmacy\Services;

use PDO;
use Exception;
use InvalidArgumentException;
use RuntimeException;

class GrnService
{
    private PDO $pdo;
    private DocumentSequenceService $seqService;
    private AuditService $auditService;
    private StockLedgerService $ledgerService;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->seqService = new DocumentSequenceService($pdo);
        $this->auditService = new AuditService($pdo);
        $this->ledgerService = new StockLedgerService($pdo);
    }

    /**
     * Create and post a GRN atomically.
     * Can be posted against a Purchase Order or direct delivery from a Supplier.
     */
    public function createAndPostGrn(array $header, array $items, int $userId, bool $allowOverReceipt = false, ?string $overReceiptReason = null): int
    {
        // 1. Double-submission / Idempotency protection
        $idempotencyKey = !empty($header['idempotency_key']) ? trim($header['idempotency_key']) : null;
        if ($idempotencyKey !== null) {
            $idemStmt = $this->pdo->prepare("SELECT grn_id FROM pharmacy_grn WHERE idempotency_key = ?");
            $idemStmt->execute([$idempotencyKey]);
            $existingGrnId = $idemStmt->fetchColumn();
            if ($existingGrnId) {
                return (int)$existingGrnId; // Idempotent replay: return existing GRN without re-posting
            }
        }

        // 2. Validate Supplier
        $supplierId = (int)($header['supplier_id'] ?? 0);
        if ($supplierId <= 0) {
            throw new InvalidArgumentException("A valid supplier must be selected.");
        }
        $supStmt = $this->pdo->prepare("SELECT supplier_id, status FROM pharmacy_suppliers WHERE supplier_id = ?");
        $supStmt->execute([$supplierId]);
        $supplier = $supStmt->fetch(PDO::FETCH_ASSOC);
        if (!$supplier) {
            throw new InvalidArgumentException("Supplier ID {$supplierId} does not exist.");
        }
        if ($supplier['status'] === 'Blocked') {
            throw new InvalidArgumentException("Cannot receive goods from a Blocked supplier.");
        }

        // 3. Validate PO if specified
        $poId = !empty($header['po_id']) ? (int)$header['po_id'] : null;
        if ($poId !== null) {
            $poStmt = $this->pdo->prepare("SELECT po_id, supplier_id, status FROM pharmacy_purchase_orders WHERE po_id = ?");
            $poStmt->execute([$poId]);
            $po = $poStmt->fetch(PDO::FETCH_ASSOC);
            if (!$po) {
                throw new InvalidArgumentException("Linked purchase order does not exist.");
            }
            if ((int)$po['supplier_id'] !== $supplierId) {
                throw new InvalidArgumentException("Purchase order does not belong to the selected supplier.");
            }
            if (in_array($po['status'], ['CANCELLED', 'CLOSED', 'DRAFT'], true)) {
                throw new InvalidArgumentException("Cannot receive stock against a {$po['status']} purchase order.");
            }
        }

        if (empty($items)) {
            throw new InvalidArgumentException("GRN must contain at least one received item.");
        }

        $grnDate = !empty($header['grn_date']) ? $header['grn_date'] : date('Y-m-d');
        $supplierInvoiceNo = !empty($header['supplier_invoice_no']) ? trim($header['supplier_invoice_no']) : null;
        $supplierInvoiceDate = !empty($header['supplier_invoice_date']) ? $header['supplier_invoice_date'] : null;
        $receivingLocation = !empty($header['receiving_location']) ? trim($header['receiving_location']) : 'Main Pharmacy Store';
        $notes = !empty($header['notes']) ? trim($header['notes']) : null;

        $this->pdo->beginTransaction();
        try {
            // Lock PO items if PO exists to prevent concurrent over-receiving
            $poItemsMap = [];
            if ($poId !== null) {
                $poLockStmt = $this->pdo->prepare("
                    SELECT po_item_id, medicine_id, requested_qty, received_qty, purchase_rate
                    FROM pharmacy_purchase_order_items
                    WHERE po_id = ? FOR UPDATE
                ");
                $poLockStmt->execute([$poId]);
                $poRows = $poLockStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($poRows as $pr) {
                    $poItemsMap[(int)$pr['po_item_id']] = $pr;
                }
            }

            // Generate dedicated GRN sequence
            $grnNumber = $this->seqService->generate('GRN', 'GRN-');

            $subtotal = 0.00;
            $discountTotal = 0.00;
            $taxTotal = 0.00;
            $grandTotal = 0.00;

            // First pass: validate all line items, dates, batches, and quantities
            $today = date('Y-m-d');
            $preparedLines = [];

            foreach ($items as $idx => $item) {
                $lineNum = $idx + 1;
                $medId = (int)($item['medicine_id'] ?? 0);
                if ($medId <= 0) {
                    throw new InvalidArgumentException("Line #{$lineNum}: Invalid medicine selected.");
                }

                // Verify medicine exists and is active
                $medStmt = $this->pdo->prepare("SELECT medicine_id, medicine_name, status FROM medicines WHERE medicine_id = ? FOR UPDATE");
                $medStmt->execute([$medId]);
                $med = $medStmt->fetch(PDO::FETCH_ASSOC);
                if (!$med) {
                    throw new InvalidArgumentException("Line #{$lineNum}: Medicine ID {$medId} not found.");
                }
                if ($med['status'] !== 'Active') {
                    throw new InvalidArgumentException("Line #{$lineNum}: Medicine '{$med['medicine_name']}' is not Active.");
                }

                $batchNumber = trim($item['batch_number'] ?? '');
                if ($batchNumber === '') {
                    throw new InvalidArgumentException("Line #{$lineNum}: Batch number is mandatory.");
                }

                $expiryDate = trim($item['expiry_date'] ?? '');
                if (!BatchService::isValidDate($expiryDate)) {
                    throw new InvalidArgumentException("Line #{$lineNum}: Expiry date is invalid. Format must be YYYY-MM-DD.");
                }

                // Check manufacturing date
                $mfgDate = !empty($item['mfg_date']) && BatchService::isValidDate($item['mfg_date']) ? $item['mfg_date'] : null;
                if ($mfgDate !== null && $mfgDate > $expiryDate) {
                    throw new InvalidArgumentException("Line #{$lineNum}: Manufacturing date ({$mfgDate}) cannot be after expiry date ({$expiryDate}).");
                }

                // Reject already expired batch
                if ($expiryDate <= $today) {
                    throw new InvalidArgumentException("Line #{$lineNum}: Batch '{$batchNumber}' is already expired ({$expiryDate}). Expired stock cannot enter normal sellable inventory.");
                }

                $receivedQty = (int)($item['received_qty'] ?? 0);
                if ($receivedQty <= 0) {
                    throw new InvalidArgumentException("Line #{$lineNum}: Received quantity must be greater than zero.");
                }

                $freeQty = max(0, (int)($item['free_qty'] ?? 0));
                $rejectedQty = max(0, (int)($item['rejected_qty'] ?? 0));
                $damagedQty = max(0, (int)($item['damaged_qty'] ?? 0));

                if ($rejectedQty + $damagedQty > $receivedQty) {
                    throw new InvalidArgumentException("Line #{$lineNum}: Rejected + damaged quantities cannot exceed received quantity.");
                }

                $acceptedQty = $receivedQty - $rejectedQty - $damagedQty;

                // Over-receipt validation against PO item
                $poItemId = !empty($item['po_item_id']) ? (int)$item['po_item_id'] : null;
                $orderedQty = 0;

                if ($poItemId !== null && isset($poItemsMap[$poItemId])) {
                    $poi = $poItemsMap[$poItemId];
                    $orderedQty = (int)$poi['requested_qty'];
                    $priorReceived = (int)$poi['received_qty'];

                    if (($priorReceived + $receivedQty) > $orderedQty) {
                        if (!$allowOverReceipt) {
                            $excess = ($priorReceived + $receivedQty) - $orderedQty;
                            throw new InvalidArgumentException("Line #{$lineNum}: Over-receipt blocked! Receiving {$receivedQty} brings total to " . ($priorReceived + $receivedQty) . ", exceeding ordered quantity {$orderedQty} by {$excess}.");
                        }
                    }
                }

                $purchaseRate = max(0.0, (float)($item['purchase_rate'] ?? 0.00));
                $mrp = max(0.0, (float)($item['mrp'] ?? 0.00));
                $salePrice = max(0.0, (float)($item['sale_price'] ?? $mrp));
                $discPct = max(0.0, min(100.0, (float)($item['discount_percent'] ?? 0.00)));
                $gstPct = max(0.0, (float)($item['gst_percent'] ?? 0.00));
                $shelf = !empty($item['shelf_location']) ? trim($item['shelf_location']) : null;
                $remarks = !empty($item['remarks']) ? trim($item['remarks']) : null;

                // Line financials calculated on billed quantity (received_qty, or accepted_qty per policy)
                $billedUnits = $receivedQty;
                $gross = $billedUnits * $purchaseRate;
                $discAmt = $gross * ($discPct / 100.0);
                $taxable = $gross - $discAmt;
                $taxAmt = $taxable * ($gstPct / 100.0);
                $lineTot = round($taxable + $taxAmt, 2);

                $subtotal += $taxable;
                $discountTotal += $discAmt;
                $taxTotal += $taxAmt;
                $grandTotal += $lineTot;

                $preparedLines[] = [
                    'po_item_id'       => $poItemId,
                    'medicine_id'      => $medId,
                    'medicine_name'    => $med['medicine_name'],
                    'batch_number'     => $batchNumber,
                    'mfg_date'         => $mfgDate,
                    'expiry_date'      => $expiryDate,
                    'ordered_qty'      => $orderedQty,
                    'received_qty'     => $receivedQty,
                    'free_qty'         => $freeQty,
                    'rejected_qty'     => $rejectedQty,
                    'damaged_qty'      => $damagedQty,
                    'accepted_qty'     => $acceptedQty,
                    'purchase_rate'    => $purchaseRate,
                    'discount_percent' => $discPct,
                    'gst_percent'      => $gstPct,
                    'mrp'              => $mrp,
                    'sale_price'       => $salePrice,
                    'line_total'       => $lineTot,
                    'shelf_location'   => $shelf,
                    'remarks'          => $remarks
                ];
            }

            // Insert GRN Header
            $stmtHeader = $this->pdo->prepare("
                INSERT INTO pharmacy_grn (
                    grn_number, grn_date, supplier_id, po_id, supplier_invoice_no, supplier_invoice_date,
                    receiving_location, subtotal_amount, discount_amount, tax_amount, total_amount,
                    status, idempotency_key, notes, received_by, posted_by, posted_at, created_by, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    'POSTED', ?, ?, ?, ?, NOW(), ?, NOW(), NOW()
                )
            ");

            $stmtHeader->execute([
                $grnNumber, $grnDate, $supplierId, $poId, $supplierInvoiceNo, $supplierInvoiceDate,
                $receivingLocation, round($subtotal, 2), round($discountTotal, 2), round($taxTotal, 2), round($grandTotal, 2),
                $idempotencyKey, $notes, $userId, $userId, $userId
            ]);

            $grnId = (int)$this->pdo->lastInsertId();

            // Insert Items, Create/Update Batches, and Write Stock Ledger
            $stmtItem = $this->pdo->prepare("
                INSERT INTO pharmacy_grn_items (
                    grn_id, po_item_id, medicine_id, batch_id, batch_number, mfg_date, expiry_date,
                    ordered_qty, received_qty, free_qty, rejected_qty, damaged_qty, accepted_qty,
                    purchase_rate, discount_percent, gst_percent, mrp, sale_price, line_total,
                    shelf_location, remarks
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    ?, ?
                )
            ");

            foreach ($preparedLines as $line) {
                $medId = $line['medicine_id'];
                $batchNum = $line['batch_number'];
                $accepted = $line['accepted_qty'];

                // Check whether batch already exists for medicine (scoped uniqueness)
                $batchFind = $this->pdo->prepare("
                    SELECT batch_id, quantity_available, quantity_received, purchase_price, mrp, sale_price
                    FROM medicine_batches
                    WHERE medicine_id = ? AND batch_number = ?
                    FOR UPDATE
                ");
                $batchFind->execute([$medId, $batchNum]);
                $existingBatch = $batchFind->fetch(PDO::FETCH_ASSOC);

                if ($existingBatch) {
                    $batchId = (int)$existingBatch['batch_id'];

                    // Safely increase available and received quantities
                    if ($accepted > 0) {
                        $newQtyAvail = (int)$existingBatch['quantity_available'] + $accepted;
                        $newQtyRec = (int)$existingBatch['quantity_received'] + $accepted;
                        $newStatus = BatchService::calculateStatus($line['expiry_date'], $newQtyAvail);

                        $bUpd = $this->pdo->prepare("
                            UPDATE medicine_batches SET
                                quantity_available = ?,
                                quantity_received = ?,
                                status = ?,
                                updated_at = NOW()
                            WHERE batch_id = ?
                        ");
                        $bUpd->execute([$newQtyAvail, $newQtyRec, $newStatus, $batchId]);
                    }
                } else {
                    // Create new batch record
                    $batchStatus = BatchService::calculateStatus($line['expiry_date'], $accepted);
                    $bIns = $this->pdo->prepare("
                        INSERT INTO medicine_batches (
                            medicine_id, batch_number, manufacturing_date, expiry_date,
                            purchase_price, mrp, sale_price, quantity_received, quantity_available,
                            supplier_id, shelf_location, status, created_at, updated_at
                        ) VALUES (
                            ?, ?, ?, ?,
                            ?, ?, ?, ?, ?,
                            ?, ?, ?, NOW(), NOW()
                        )
                    ");
                    $bIns->execute([
                        $medId, $batchNum, $line['mfg_date'], $line['expiry_date'],
                        $line['purchase_rate'], $line['mrp'], $line['sale_price'],
                        $accepted, $accepted,
                        $supplierId, $line['shelf_location'], $batchStatus
                    ]);
                    $batchId = (int)$this->pdo->lastInsertId();
                }

                // Record GRN Item
                $stmtItem->execute([
                    $grnId, $line['po_item_id'], $medId, $batchId, $batchNum, $line['mfg_date'], $line['expiry_date'],
                    $line['ordered_qty'], $line['received_qty'], $line['free_qty'], $line['rejected_qty'], $line['damaged_qty'], $line['accepted_qty'],
                    $line['purchase_rate'], $line['discount_percent'], $line['gst_percent'], $line['mrp'], $line['sale_price'], $line['line_total'],
                    $line['shelf_location'], $line['remarks']
                ]);

                // Update physical sellable stock only for accepted quantity
                if ($accepted > 0) {
                    // 1. Append immutable stock ledger entry (records balance_before and balance_after)
                    $this->ledgerService->recordEntry(
                        $medId,
                        $batchId,
                        'PURCHASE',
                        $accepted,
                        $line['purchase_rate'],
                        $line['sale_price'],
                        $grnId,
                        $grnNumber,
                        $userId,
                        "GRN Inward: {$grnNumber}, Batch: {$batchNum} (Received: {$line['received_qty']}, Accepted: {$accepted})"
                    );

                    // 2. Increment medicine aggregate stock
                    $medUpd = $this->pdo->prepare("UPDATE medicines SET stock_quantity = stock_quantity + ? WHERE medicine_id = ?");
                    $medUpd->execute([$accepted, $medId]);
                }

                // Update PO Item received quantity if linked
                if ($line['po_item_id'] !== null) {
                    $poUpd = $this->pdo->prepare("
                        UPDATE pharmacy_purchase_order_items
                        SET received_qty = received_qty + ?
                        WHERE po_item_id = ?
                    ");
                    $poUpd->execute([$line['received_qty'], $line['po_item_id']]);
                }
            }

            // Update PO overall status if linked
            if ($poId !== null) {
                $checkPoStmt = $this->pdo->prepare("
                    SELECT 
                        SUM(requested_qty) as total_ordered,
                        SUM(received_qty) as total_received,
                        MIN(CASE WHEN received_qty >= requested_qty THEN 1 ELSE 0 END) as all_fulfilled
                    FROM pharmacy_purchase_order_items
                    WHERE po_id = ?
                ");
                $checkPoStmt->execute([$poId]);
                $poTotals = $checkPoStmt->fetch(PDO::FETCH_ASSOC);

                $allFulfilled = (int)($poTotals['all_fulfilled'] ?? 0);
                $totalRec = (int)($poTotals['total_received'] ?? 0);

                if ($allFulfilled === 1) {
                    $newPoStatus = 'FULLY_RECEIVED';
                } elseif ($totalRec > 0) {
                    $newPoStatus = 'PARTIALLY_RECEIVED';
                } else {
                    $newPoStatus = 'APPROVED';
                }

                $updatePoStatus = $this->pdo->prepare("UPDATE pharmacy_purchase_orders SET status = ?, updated_at = NOW() WHERE po_id = ?");
                $updatePoStatus->execute([$newPoStatus, $poId]);
            }

            $this->auditService->logAction(
                $userId,
                'POST_GRN',
                'pharmacy_grn',
                $grnId,
                null,
                [
                    'grn_number'   => $grnNumber,
                    'supplier_id'  => $supplierId,
                    'po_id'        => $poId,
                    'total_amount' => $grandTotal,
                    'items_count'  => count($preparedLines)
                ]
            );

            $this->pdo->commit();
            return $grnId;
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Cancel/Reverse a posted GRN.
     * Uses compensating stock movements to preserve immutable ledger integrity.
     */
    public function cancelGrn(int $grnId, string $reason, int $userId): bool
    {
        $grn = $this->getGrn($grnId);
        if (!$grn) {
            throw new InvalidArgumentException("GRN ID {$grnId} does not exist.");
        }
        if ($grn['status'] === 'CANCELLED') {
            return true;
        }

        $this->pdo->beginTransaction();
        try {
            foreach ($grn['items'] as $item) {
                $accepted = (int)$item['accepted_qty'];
                $medId = (int)$item['medicine_id'];
                $batchId = (int)$item['batch_id'];

                if ($accepted > 0) {
                    // Check batch availability
                    $bStmt = $this->pdo->prepare("SELECT quantity_available FROM medicine_batches WHERE batch_id = ? FOR UPDATE");
                    $bStmt->execute([$batchId]);
                    $currBatchAvail = (int)$bStmt->fetchColumn();

                    if ($currBatchAvail < $accepted) {
                        throw new RuntimeException("Cannot reverse GRN {$grn['grn_number']}: Batch '{$item['batch_number']}' stock has already been dispensed or depleted (Available: {$currBatchAvail}, Required for reversal: {$accepted}).");
                    }

                    // Decrement batch stock
                    $bUpd = $this->pdo->prepare("UPDATE medicine_batches SET quantity_available = quantity_available - ?, updated_at = NOW() WHERE batch_id = ?");
                    $bUpd->execute([$accepted, $batchId]);

                    // Record compensating ledger entry (immutable: negative quantity change)
                    $this->ledgerService->recordEntry(
                        $medId,
                        $batchId,
                        'PURCHASE_RETURN',
                        -$accepted,
                        (float)$item['purchase_rate'],
                        (float)$item['sale_price'],
                        $grnId,
                        $grn['grn_number'],
                        $userId,
                        "Compensating stock reversal for cancelled GRN: {$grn['grn_number']}. Reason: {$reason}"
                    );

                    // Decrement medicine stock
                    $mUpd = $this->pdo->prepare("UPDATE medicines SET stock_quantity = stock_quantity - ? WHERE medicine_id = ?");
                    $mUpd->execute([$accepted, $medId]);
                }

                // Revert PO item received quantity if linked
                if (!empty($item['po_item_id'])) {
                    $poItemUpd = $this->pdo->prepare("UPDATE pharmacy_purchase_order_items SET received_qty = GREATEST(0, received_qty - ?) WHERE po_item_id = ?");
                    $poItemUpd->execute([(int)$item['received_qty'], $item['po_item_id']]);
                }
            }

            // Recalculate linked PO status
            if (!empty($grn['po_id'])) {
                $poId = (int)$grn['po_id'];
                $checkPo = $this->pdo->prepare("SELECT SUM(requested_qty) as req, SUM(received_qty) as rec FROM pharmacy_purchase_order_items WHERE po_id = ?");
                $checkPo->execute([$poId]);
                $poRow = $checkPo->fetch(PDO::FETCH_ASSOC);
                $newPoStatus = ((int)$poRow['rec'] > 0) ? 'PARTIALLY_RECEIVED' : 'APPROVED';
                $this->pdo->prepare("UPDATE pharmacy_purchase_orders SET status = ? WHERE po_id = ?")->execute([$newPoStatus, $poId]);
            }

            // Update GRN status
            $stmt = $this->pdo->prepare("
                UPDATE pharmacy_grn SET
                    status = 'CANCELLED',
                    cancelled_by = ?,
                    cancelled_at = NOW(),
                    cancellation_reason = ?,
                    updated_at = NOW()
                WHERE grn_id = ?
            ");
            $stmt->execute([$userId, $reason, $grnId]);

            $this->auditService->logAction($userId, 'CANCEL_GRN', 'pharmacy_grn', $grnId, ['status' => 'POSTED'], ['status' => 'CANCELLED', 'reason' => $reason]);

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
     * Update GRN clerical metadata (supplier bill number, bill date, location, notes).
     * Preserves original record ID, GRN number, batches, and posted inventory ledger.
     */
    public function updateGrnMetadata(int $grnId, array $data, int $userId): bool
    {
        $existing = $this->getGrn($grnId);
        if (!$existing) {
            throw new InvalidArgumentException("GRN #{$grnId} not found.");
        }

        $supplierInvoiceNo = !empty($data['supplier_invoice_no']) ? trim($data['supplier_invoice_no']) : (!empty($data['invoice_number']) ? trim($data['invoice_number']) : $existing['supplier_invoice_no']);
        $supplierInvoiceDate = !empty($data['supplier_invoice_date']) ? $data['supplier_invoice_date'] : $existing['supplier_invoice_date'];
        $receivingLocation = !empty($data['receiving_location']) ? trim($data['receiving_location']) : $existing['receiving_location'];
        $notes = isset($data['notes']) ? trim($data['notes']) : $existing['notes'];

        $stmt = $this->pdo->prepare("
            UPDATE pharmacy_grn SET
                supplier_invoice_no = ?,
                supplier_invoice_date = ?,
                receiving_location = ?,
                notes = ?,
                updated_at = NOW()
            WHERE grn_id = ?
        ");
        $stmt->execute([$supplierInvoiceNo, $supplierInvoiceDate, $receivingLocation, $notes, $grnId]);

        $this->auditService->logAction(
            $userId,
            'UPDATE_GRN_METADATA',
            'pharmacy_grn',
            $grnId,
            [
                'supplier_invoice_no' => $existing['supplier_invoice_no'],
                'notes' => $existing['notes']
            ],
            [
                'supplier_invoice_no' => $supplierInvoiceNo,
                'notes' => $notes
            ]
        );

        return true;
    }

    /**
     * Update full GRN including clerical header, received items, batch numbers, expiry, rates, and quantities.
     * Synchronizes batch inventory and records adjustment entries in the stock ledger if quantities change.
     */
    public function updateFullGrn(int $grnId, array $header, array $items, int $userId): bool
    {
        $existing = $this->getGrn($grnId);
        if (!$existing) {
            throw new InvalidArgumentException("GRN #{$grnId} not found.");
        }
        if ($existing['status'] === 'CANCELLED') {
            throw new InvalidArgumentException("Cannot edit a CANCELLED GRN.");
        }

        $supplierInvoiceNo = !empty($header['supplier_invoice_no']) ? trim($header['supplier_invoice_no']) : null;
        $supplierInvoiceDate = !empty($header['supplier_invoice_date']) ? $header['supplier_invoice_date'] : null;
        $grnDate = !empty($header['grn_date']) ? $header['grn_date'] : $existing['grn_date'];
        $receivingLocation = !empty($header['receiving_location']) ? trim($header['receiving_location']) : 'Main Pharmacy Store';
        $notes = isset($header['notes']) ? trim($header['notes']) : $existing['notes'];

        $existingItemsMap = [];
        foreach ($existing['items'] as $it) {
            $existingItemsMap[(int)$it['grn_item_id']] = $it;
        }

        $this->pdo->beginTransaction();
        try {
            $subtotal = 0.00;
            $discountTotal = 0.00;
            $taxTotal = 0.00;
            $grandTotal = 0.00;

            $stmtUpdateItem = $this->pdo->prepare("
                UPDATE pharmacy_grn_items SET
                    batch_number = ?,
                    mfg_date = ?,
                    expiry_date = ?,
                    received_qty = ?,
                    free_qty = ?,
                    rejected_qty = ?,
                    damaged_qty = ?,
                    accepted_qty = ?,
                    purchase_rate = ?,
                    discount_percent = ?,
                    gst_percent = ?,
                    mrp = ?,
                    sale_price = ?,
                    line_total = ?,
                    shelf_location = ?,
                    remarks = ?
                WHERE grn_item_id = ? AND grn_id = ?
            ");

            $stmtInsertItem = $this->pdo->prepare("
                INSERT INTO pharmacy_grn_items (
                    grn_id, po_item_id, medicine_id, batch_id, batch_number, mfg_date, expiry_date,
                    ordered_qty, received_qty, free_qty, rejected_qty, damaged_qty, accepted_qty,
                    purchase_rate, discount_percent, gst_percent, mrp, sale_price, line_total,
                    shelf_location, remarks
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    ?, ?
                )
            ");

            $processedItemIds = [];

            foreach ($items as $idx => $line) {
                $lineNum = $idx + 1;
                $grnItemId = !empty($line['grn_item_id']) ? (int)$line['grn_item_id'] : 0;
                $medId = (int)($line['medicine_id'] ?? 0);
                if ($medId <= 0 && $grnItemId && isset($existingItemsMap[$grnItemId])) {
                    $medId = (int)$existingItemsMap[$grnItemId]['medicine_id'];
                }
                if ($medId <= 0) {
                    throw new InvalidArgumentException("Line #{$lineNum}: Invalid medicine selected.");
                }

                $batchNum = trim($line['batch_number'] ?? '');
                if ($batchNum === '') {
                    throw new InvalidArgumentException("Line #{$lineNum}: Batch number is mandatory.");
                }

                $expiryDate = trim($line['expiry_date'] ?? '');
                if (!BatchService::isValidDate($expiryDate)) {
                    throw new InvalidArgumentException("Line #{$lineNum}: Expiry date ({$expiryDate}) is invalid. Format must be YYYY-MM-DD.");
                }

                $mfgDate = !empty($line['mfg_date']) && BatchService::isValidDate($line['mfg_date']) ? $line['mfg_date'] : null;

                $receivedQty = max(1, (int)($line['received_qty'] ?? 0));
                $freeQty = max(0, (int)($line['free_qty'] ?? 0));
                $rejectedQty = max(0, (int)($line['rejected_qty'] ?? 0));
                $damagedQty = max(0, (int)($line['damaged_qty'] ?? 0));

                if ($rejectedQty + $damagedQty > $receivedQty) {
                    throw new InvalidArgumentException("Line #{$lineNum}: Rejected + damaged quantities cannot exceed received quantity.");
                }

                $acceptedQty = $receivedQty - $rejectedQty - $damagedQty;
                $purchaseRate = max(0.0, (float)($line['purchase_rate'] ?? 0.00));
                $mrp = max(0.0, (float)($line['mrp'] ?? 0.00));
                $salePrice = max(0.0, (float)($line['sale_price'] ?? $mrp));
                $discPct = max(0.0, min(100.0, (float)($line['discount_percent'] ?? 0.00)));
                $gstPct = max(0.0, (float)($line['gst_percent'] ?? 0.00));
                $shelf = !empty($line['shelf_location']) ? trim($line['shelf_location']) : null;
                $remarks = !empty($line['remarks']) ? trim($line['remarks']) : null;

                // Line financials
                $billedUnits = $receivedQty;
                $gross = $billedUnits * $purchaseRate;
                $discAmt = $gross * ($discPct / 100.0);
                $taxable = $gross - $discAmt;
                $taxAmt = $taxable * ($gstPct / 100.0);
                $lineTot = round($taxable + $taxAmt, 2);

                $subtotal += $taxable;
                $discountTotal += $discAmt;
                $taxTotal += $taxAmt;
                $grandTotal += $lineTot;

                if ($grnItemId > 0 && isset($existingItemsMap[$grnItemId])) {
                    $oldItem = $existingItemsMap[$grnItemId];
                    $processedItemIds[] = $grnItemId;
                    $batchId = (int)$oldItem['batch_id'];
                    $oldAccepted = (int)$oldItem['accepted_qty'];
                    $oldReceived = (int)$oldItem['received_qty'];
                    $acceptedDiff = $acceptedQty - $oldAccepted;
                    $receivedDiff = $receivedQty - $oldReceived;

                    // Update batch metadata & balance
                    if ($batchId > 0) {
                        $batchStatus = BatchService::calculateStatus($expiryDate, 1);
                        $bUpd = $this->pdo->prepare("
                            UPDATE medicine_batches SET
                                batch_number = ?,
                                manufacturing_date = ?,
                                expiry_date = ?,
                                purchase_price = ?,
                                mrp = ?,
                                sale_price = ?,
                                shelf_location = ?,
                                quantity_available = GREATEST(0, quantity_available + ?),
                                quantity_received = GREATEST(0, quantity_received + ?),
                                status = ?,
                                updated_at = NOW()
                            WHERE batch_id = ?
                        ");
                        $bUpd->execute([
                            $batchNum, $mfgDate, $expiryDate, $purchaseRate, $mrp, $salePrice,
                            $shelf, $acceptedDiff, $acceptedDiff, $batchStatus, $batchId
                        ]);

                        if ($acceptedDiff !== 0) {
                            $this->ledgerService->recordEntry(
                                $medId,
                                $batchId,
                                'ADJUSTMENT',
                                $acceptedDiff,
                                $purchaseRate,
                                $salePrice,
                                $grnId,
                                $existing['grn_number'],
                                $userId,
                                "GRN Line Edit Adjustment: {$existing['grn_number']}, Batch: {$batchNum} (Net Delta: {$acceptedDiff})"
                            );

                            $mUpd = $this->pdo->prepare("UPDATE medicines SET stock_quantity = GREATEST(0, stock_quantity + ?) WHERE medicine_id = ?");
                            $mUpd->execute([$acceptedDiff, $medId]);
                        }
                    }

                    // Update PO Item if linked
                    if (!empty($oldItem['po_item_id']) && $receivedDiff !== 0) {
                        $poUpd = $this->pdo->prepare("UPDATE pharmacy_purchase_order_items SET received_qty = GREATEST(0, received_qty + ?) WHERE po_item_id = ?");
                        $poUpd->execute([$receivedDiff, $oldItem['po_item_id']]);
                    }

                    // Update GRN item row
                    $stmtUpdateItem->execute([
                        $batchNum, $mfgDate, $expiryDate, $receivedQty, $freeQty, $rejectedQty, $damagedQty, $acceptedQty,
                        $purchaseRate, $discPct, $gstPct, $mrp, $salePrice, $lineTot, $shelf, $remarks,
                        $grnItemId, $grnId
                    ]);
                } else {
                    // Newly added item row in edit modal
                    $batchStatus = BatchService::calculateStatus($expiryDate, $acceptedQty);
                    $bIns = $this->pdo->prepare("
                        INSERT INTO medicine_batches (
                            medicine_id, batch_number, manufacturing_date, expiry_date,
                            purchase_price, mrp, sale_price, quantity_received, quantity_available,
                            supplier_id, shelf_location, status, created_at, updated_at
                        ) VALUES (
                            ?, ?, ?, ?,
                            ?, ?, ?, ?, ?,
                            ?, ?, ?, NOW(), NOW()
                        )
                    ");
                    $bIns->execute([
                        $medId, $batchNum, $mfgDate, $expiryDate,
                        $purchaseRate, $mrp, $salePrice,
                        $acceptedQty, $acceptedQty,
                        $existing['supplier_id'], $shelf, $batchStatus
                    ]);
                    $batchId = (int)$this->pdo->lastInsertId();

                    $stmtInsertItem->execute([
                        $grnId, null, $medId, $batchId, $batchNum, $mfgDate, $expiryDate,
                        $receivedQty, $receivedQty, $freeQty, $rejectedQty, $damagedQty, $acceptedQty,
                        $purchaseRate, $discPct, $gstPct, $mrp, $salePrice, $lineTot,
                        $shelf, $remarks
                    ]);

                    if ($acceptedQty > 0) {
                        $this->ledgerService->recordEntry(
                            $medId,
                            $batchId,
                            'PURCHASE',
                            $acceptedQty,
                            $purchaseRate,
                            $salePrice,
                            $grnId,
                            $existing['grn_number'],
                            $userId,
                            "GRN Inward (Added during edit): {$existing['grn_number']}, Batch: {$batchNum}"
                        );

                        $medUpd = $this->pdo->prepare("UPDATE medicines SET stock_quantity = stock_quantity + ? WHERE medicine_id = ?");
                        $medUpd->execute([$acceptedQty, $medId]);
                    }
                }
            }

            // Update GRN header totals & metadata
            $stmtHeader = $this->pdo->prepare("
                UPDATE pharmacy_grn SET
                    grn_date = ?,
                    supplier_invoice_no = ?,
                    supplier_invoice_date = ?,
                    receiving_location = ?,
                    notes = ?,
                    subtotal_amount = ?,
                    discount_amount = ?,
                    tax_amount = ?,
                    total_amount = ?,
                    updated_at = NOW()
                WHERE grn_id = ?
            ");
            $stmtHeader->execute([
                $grnDate, $supplierInvoiceNo, $supplierInvoiceDate, $receivingLocation, $notes,
                round($subtotal, 2), round($discountTotal, 2), round($taxTotal, 2), round($grandTotal, 2),
                $grnId
            ]);

            $this->auditService->logAction(
                $userId,
                'UPDATE_FULL_GRN',
                'pharmacy_grn',
                $grnId,
                [
                    'old_invoice_no' => $existing['supplier_invoice_no'],
                    'old_total' => $existing['total_amount'],
                    'old_items_count' => count($existing['items'])
                ],
                [
                    'new_invoice_no' => $supplierInvoiceNo,
                    'new_total' => round($grandTotal, 2),
                    'new_items_count' => count($items)
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
    public function getGrn(int $grnId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT g.*, s.supplier_name, s.supplier_code, s.phone as supplier_phone, s.gstin as supplier_gstin,
                   s.drug_licence_no as supplier_drug_licence, s.address as supplier_address,
                   po.po_number, u.full_name as receiver_name, p.full_name as poster_name
            FROM pharmacy_grn g
            JOIN pharmacy_suppliers s ON g.supplier_id = s.supplier_id
            LEFT JOIN pharmacy_purchase_orders po ON g.po_id = po.po_id
            LEFT JOIN pharmacy_users u ON g.received_by = u.id
            LEFT JOIN pharmacy_users p ON g.posted_by = p.id
            WHERE g.grn_id = ?
        ");
        $stmt->execute([$grnId]);
        $grn = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$grn) return null;

        $itemsStmt = $this->pdo->prepare("
            SELECT gi.*, m.medicine_name, m.generic_name, m.strength, m.dosage_form, m.manufacturer
            FROM pharmacy_grn_items gi
            JOIN medicines m ON gi.medicine_id = m.medicine_id
            WHERE gi.grn_id = ?
            ORDER BY gi.grn_item_id ASC
        ");
        $itemsStmt->execute([$grnId]);
        $grn['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        return $grn;
    }

    /**
     * List GRNs with filters.
     */
    public function listGrns(array $filters = []): array
    {
        $sql = "
            SELECT g.*, s.supplier_name, s.supplier_code, po.po_number,
                   (SELECT COUNT(*) FROM pharmacy_grn_items WHERE grn_id = g.grn_id) as total_items,
                   (SELECT IFNULL(SUM(received_qty), 0) FROM pharmacy_grn_items WHERE grn_id = g.grn_id) as total_received,
                   (SELECT IFNULL(SUM(accepted_qty), 0) FROM pharmacy_grn_items WHERE grn_id = g.grn_id) as total_accepted,
                   (SELECT IFNULL(SUM(rejected_qty + damaged_qty), 0) FROM pharmacy_grn_items WHERE grn_id = g.grn_id) as total_rejected
            FROM pharmacy_grn g
            JOIN pharmacy_suppliers s ON g.supplier_id = s.supplier_id
            LEFT JOIN pharmacy_purchase_orders po ON g.po_id = po.po_id
            WHERE 1=1
        ";
        $params = [];

        if (!empty($filters['search'])) {
            $term = '%' . trim($filters['search']) . '%';
            $sql .= " AND (g.grn_number LIKE ? OR s.supplier_name LIKE ? OR g.supplier_invoice_no LIKE ?)";
            $params = array_merge($params, [$term, $term, $term]);
        }

        if (!empty($filters['supplier_id'])) {
            $sql .= " AND g.supplier_id = ?";
            $params[] = (int)$filters['supplier_id'];
        }

        if (!empty($filters['po_id'])) {
            $sql .= " AND g.po_id = ?";
            $params[] = (int)$filters['po_id'];
        }

        if (!empty($filters['status'])) {
            $sql .= " AND g.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND g.grn_date >= ?";
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND g.grn_date <= ?";
            $params[] = $filters['date_to'];
        }

        $sql .= " ORDER BY g.grn_id DESC";

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
     * Alias for getGrn
     */
    public function getGrnById(int $grnId): ?array
    {
        return $this->getGrn($grnId);
    }
}
