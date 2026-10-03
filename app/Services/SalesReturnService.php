<?php
// app/Services/SalesReturnService.php - Customer/Patient Sales Returns & Restock Lifecycle

namespace Pharmacy\Services;

use PDO;
use Exception;
use InvalidArgumentException;
use RuntimeException;

class SalesReturnService
{
    private PDO $pdo;
    private DocumentSequenceService $seqService;
    private StockLedgerService $ledgerService;
    private AuditService $auditService;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->seqService = new DocumentSequenceService($pdo);
        $this->ledgerService = new StockLedgerService($pdo);
        $this->auditService = new AuditService($pdo);
    }

    /**
     * Fetch sale details with calculated return eligibility for every item and batch.
     * Re-reads authoritative database state.
     */
    public function getReturnableSaleDetails(int $saleId): array
    {
        $saleStmt = $this->pdo->prepare("
            SELECT s.*, 
                   COALESCE(p.name, s.customer_name, 'Walk-in Customer') AS patient_display_name,
                   p.pharmacy_patient_no, p.mobile AS patient_phone
            FROM pharmacy_sales s
            LEFT JOIN pharmacy_patients p ON s.patient_id = p.id
            WHERE s.sale_id = ?
        ");
        $saleStmt->execute([$saleId]);
        $sale = $saleStmt->fetch(PDO::FETCH_ASSOC);

        if (!$sale) {
            throw new InvalidArgumentException("Sale #{$saleId} does not exist.");
        }

        // Fetch line items with batch allocations
        $itemStmt = $this->pdo->prepare("
            SELECT si.sale_item_id, si.sale_id, si.medicine_id, m.medicine_name, m.generic_name,
                   si.quantity AS sold_quantity, si.unit_price, si.discount_percent,
                   si.discount_amount, si.gst_percent AS tax_percent, si.gst_amount AS tax_amount, si.line_total AS total_amount,
                   COALESCE((
                       SELECT SUM(sri.return_quantity)
                       FROM pharmacy_sales_return_items sri
                       JOIN pharmacy_sales_returns sr ON sri.return_id = sr.return_id
                       WHERE sri.sale_item_id = si.sale_item_id
                         AND sr.status != 'CANCELLED'
                   ), 0) AS previously_returned_qty
            FROM pharmacy_sale_items si
            JOIN medicines m ON si.medicine_id = m.medicine_id
            WHERE si.sale_id = ?
        ");
        $itemStmt->execute([$saleId]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        // Attach batch allocations per item
        $batchStmt = $this->pdo->prepare("
            SELECT sib.id AS allocation_id, sib.sale_item_id, sib.batch_id, sib.allocated_quantity,
                   mb.batch_number, mb.expiry_date, mb.quantity_available, mb.status AS batch_status,
                   mb.purchase_price, mb.sale_price,
                   COALESCE((
                       SELECT SUM(sri.return_quantity)
                       FROM pharmacy_sales_return_items sri
                       JOIN pharmacy_sales_returns sr ON sri.return_id = sr.return_id
                       WHERE sri.sale_item_id = sib.sale_item_id AND sri.batch_id = sib.batch_id
                         AND sr.status != 'CANCELLED'
                   ), 0) AS batch_previously_returned
            FROM pharmacy_sale_item_batches sib
            JOIN medicine_batches mb ON sib.batch_id = mb.batch_id
            WHERE sib.sale_id = ?
        ");
        $batchStmt->execute([$saleId]);
        $allBatches = $batchStmt->fetchAll(PDO::FETCH_ASSOC);

        $batchesByItem = [];
        foreach ($allBatches as $b) {
            $batchesByItem[$b['sale_item_id']][] = $b;
        }

        $marAdminStmt = $this->pdo->prepare("
            SELECT COALESCE(SUM(administered_qty), 0)
            FROM pharmacy_mar_records
            WHERE prescription_id = ? AND medicine_id = ? AND status = 'GIVEN'
        ");

        foreach ($items as &$item) {
            $item['sold_quantity'] = (int)$item['sold_quantity'];
            $item['previously_returned_qty'] = (int)$item['previously_returned_qty'];

            $adminQty = 0;
            if (!empty($sale['prescription_id'])) {
                $marAdminStmt->execute([$sale['prescription_id'], $item['medicine_id']]);
                $adminQty = (int)$marAdminStmt->fetchColumn();
            }
            $item['administered_quantity'] = $adminQty;

            $item['returnable_quantity'] = max(0, $item['sold_quantity'] - $item['previously_returned_qty'] - $adminQty);
            $item['batches'] = $batchesByItem[$item['sale_item_id']] ?? [];
            foreach ($item['batches'] as &$bRow) {
                $bRow['allocated_quantity'] = (int)$bRow['allocated_quantity'];
                $bRow['batch_previously_returned'] = (int)$bRow['batch_previously_returned'];
                $bRow['batch_returnable'] = max(0, $bRow['allocated_quantity'] - $bRow['batch_previously_returned']);
            }
        }

        $sale['items'] = $items;
        return $sale;
    }

    /**
     * Create and post a sales return atomically.
     * Enforces row-level locking, strict quantity validation, batch traceability,
     * restock condition triage, and compensating ledger entries.
     */
    public function createReturn(array $header, array $items, int $userId): array
    {
        $saleId = (int)($header['sale_id'] ?? 0);
        if ($saleId <= 0) {
            throw new InvalidArgumentException("Valid original sale ID is required.");
        }

        if (empty($items)) {
            throw new InvalidArgumentException("Return must contain at least one line item.");
        }

        $idempotencyKey = trim($header['idempotency_key'] ?? '');
        if ($idempotencyKey !== '') {
            $dupCheck = $this->pdo->prepare("SELECT return_id, return_number FROM pharmacy_sales_returns WHERE idempotency_key = ?");
            $dupCheck->execute([$idempotencyKey]);
            $existing = $dupCheck->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                return [
                    'return_id' => (int)$existing['return_id'],
                    'return_number' => $existing['return_number'],
                    'already_processed' => true
                ];
            }
        }

        $this->pdo->beginTransaction();
        try {
            // 1. Lock original sale
            $saleStmt = $this->pdo->prepare("
            SELECT sale_id, sale_number, sale_type, patient_id, customer_name, customer_mobile,
                   prescription_id, ipd_admission_id, ipd_ward, ipd_bed,
                   subtotal_amount, discount_amount, taxable_amount, gst_amount, grand_total,
                   paid_amount, balance_amount, payment_status, status, sale_date
            FROM pharmacy_sales
            WHERE sale_id = ? FOR UPDATE
        ");
        $saleStmt->execute([$saleId]);
        $sale = $saleStmt->fetch(PDO::FETCH_ASSOC);

        if (!$sale) {
            throw new InvalidArgumentException("Sale #{$saleId} does not exist.");
        }

            if ($sale['status'] === 'CANCELLED') {
                throw new InvalidArgumentException("Cannot process return for cancelled sale '{$sale['sale_number']}'.");
            }

            // 2. Generate unique Return Number
            $returnNumber = $this->seqService->generate('SALE_RETURN', 'SRT-');
            $returnDate = !empty($header['return_date']) ? $header['return_date'] : date('Y-m-d');
            $returnReason = trim($header['reason'] ?? 'Customer Return');
            $paymentMode = !empty($header['payment_mode']) ? $header['payment_mode'] : 'CASH';

            $totalRefundAmount = 0.00;
            $processedItems = [];

            // 3. Process and validate line items
            $itemLockStmt = $this->pdo->prepare("
                SELECT sale_item_id, medicine_id, quantity, unit_price, discount_percent, gst_percent AS tax_percent
                FROM pharmacy_sale_items
                WHERE sale_item_id = ? AND sale_id = ? FOR UPDATE
            ");

            $batchLockStmt = $this->pdo->prepare("
                SELECT sib.id AS allocation_id, sib.batch_id, sib.allocated_quantity, sib.unit_cost, sib.unit_price,
                mb.batch_number, mb.expiry_date, mb.quantity_available, mb.status
                FROM pharmacy_sale_item_batches sib
                JOIN medicine_batches mb ON sib.batch_id = mb.batch_id
                WHERE sib.sale_item_id = ? AND sib.batch_id = ? FOR UPDATE
            ");

            $prevReturnStmt = $this->pdo->prepare("
                SELECT COALESCE(SUM(sri.return_quantity), 0)
                FROM pharmacy_sales_return_items sri
                JOIN pharmacy_sales_returns sr ON sri.return_id = sr.return_id
                WHERE sri.sale_item_id = ? AND sri.batch_id = ?
                  AND sr.status != 'CANCELLED'
            ");

            foreach ($items as $idx => $it) {
                $saleItemId = (int)($it['sale_item_id'] ?? 0);
                $batchId = (int)($it['batch_id'] ?? 0);
                $returnQty = (int)($it['return_quantity'] ?? 0);
                $condition = trim($it['condition_status'] ?? 'SEALED_INTACT');
                $restockDecision = trim($it['restock_decision'] ?? 'SELLABLE_RESTOCK');
                $lineReason = trim($it['return_reason'] ?? $returnReason);

                if ($returnQty <= 0) {
                    continue; // Skip zero quantity returns
                }

                // Lock original sale item
                $itemLockStmt->execute([$saleItemId, $saleId]);
                $origItem = $itemLockStmt->fetch(PDO::FETCH_ASSOC);
                if (!$origItem) {
                    throw new InvalidArgumentException("Item #{$saleItemId} does not belong to Sale #{$sale['sale_number']}.");
                }

                // Lock batch allocation
                $batchLockStmt->execute([$saleItemId, $batchId]);
                $origBatchAlloc = $batchLockStmt->fetch(PDO::FETCH_ASSOC);
                if (!$origBatchAlloc) {
                    throw new InvalidArgumentException("Batch #{$batchId} was not allocated for item #{$saleItemId} in this sale.");
                }

                // Calculate cumulative returns for this batch
                $prevReturnStmt->execute([$saleItemId, $batchId]);
                $previouslyReturned = (int)$prevReturnStmt->fetchColumn();

                $allocatedQty = (int)$origBatchAlloc['allocated_quantity'];
                if (($previouslyReturned + $returnQty) > $allocatedQty) {
                    $maxPossible = max(0, $allocatedQty - $previouslyReturned);
                    throw new InvalidArgumentException(
                        "Return quantity ({$returnQty}) exceeds returnable quantity ({$maxPossible}) for batch '{$origBatchAlloc['batch_number']}'."
                    );
                }

                // Check MAR administered quantities if linked to a prescription
                if (!empty($sale['prescription_id'])) {
                    $marStmt = $this->pdo->prepare("
                        SELECT COALESCE(SUM(administered_qty), 0)
                        FROM pharmacy_mar_records
                        WHERE prescription_id = ? AND medicine_id = ? AND status = 'GIVEN'
                    ");
                    $marStmt->execute([$sale['prescription_id'], $origItem['medicine_id']]);
                    $clinicallyAdministered = (int)$marStmt->fetchColumn();

                    if ($clinicallyAdministered > 0) {
                        $totSoldStmt = $this->pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM pharmacy_sale_items WHERE sale_id = ? AND medicine_id = ?");
                        $totSoldStmt->execute([$saleId, $origItem['medicine_id']]);
                        $totalSoldForMed = (int)$totSoldStmt->fetchColumn();

                        $totRetStmt = $this->pdo->prepare("
                            SELECT COALESCE(SUM(sri.return_quantity), 0)
                            FROM pharmacy_sales_return_items sri
                            JOIN pharmacy_sales_returns sr ON sri.return_id = sr.return_id
                            WHERE sr.sale_id = ? AND sri.medicine_id = ? AND sr.status != 'CANCELLED'
                        ");
                        $totRetStmt->execute([$saleId, $origItem['medicine_id']]);
                        $totPrevReturnedMed = (int)$totRetStmt->fetchColumn();

                        $unadministeredAvailable = max(0, $totalSoldForMed - $totPrevReturnedMed - $clinicallyAdministered);
                        if ($returnQty > $unadministeredAvailable) {
                            throw new InvalidArgumentException(
                                "Cannot return {$returnQty} units: {$clinicallyAdministered} units have already been clinically administered on the MAR. Maximum unadministered returnable quantity is {$unadministeredAvailable}."
                            );
                        }
                    }
                }

                // Recalculate refund amount based on authoritative sale prices
                $unitPrice = (float)$origItem['unit_price'];
                $discPct = (float)$origItem['discount_percent'];
                $taxPct = (float)$origItem['tax_percent'];

                $discountedUnit = $unitPrice * (1 - ($discPct / 100));
                $taxUnit = $discountedUnit * ($taxPct / 100);
                $effectiveRate = $discountedUnit + $taxUnit;
                $lineRefund = round($effectiveRate * $returnQty, 2);

                $totalRefundAmount += $lineRefund;

                $processedItems[] = [
                    'sale_item_id'           => $saleItemId,
                    'medicine_id'            => (int)$origItem['medicine_id'],
                    'batch_id'               => $batchId,
                    'sold_quantity'          => (int)$origItem['quantity'],
                    'previously_returned_qty'=> $previouslyReturned,
                    'return_quantity'        => $returnQty,
                    'unit_price'             => $unitPrice,
                    'discount_percent'       => $discPct,
                    'tax_percent'            => $taxPct,
                    'refund_amount'          => $lineRefund,
                    'condition_status'       => $condition,
                    'restock_decision'       => $restockDecision,
                    'return_reason'          => $lineReason,
                    'unit_cost'              => (float)$origBatchAlloc['unit_cost'],
                    'batch_number'           => $origBatchAlloc['batch_number'],
                    'expiry_date'            => $origBatchAlloc['expiry_date'],
                    'current_batch_status'   => $origBatchAlloc['status']
                ];
            }

            if (empty($processedItems)) {
                throw new InvalidArgumentException("At least one item must have a valid return quantity > 0.");
            }

            // 4. Insert Return Header
            $insReturn = $this->pdo->prepare("
                INSERT INTO pharmacy_sales_returns (
                    return_number, return_date, sale_id, sale_number, patient_id,
                    customer_name, sale_type, total_refund_amount, payment_mode,
                    refund_status, reason, status, idempotency_key, notes,
                    created_by, approved_by, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    'PROCESSED', ?, 'POSTED', ?, ?,
                    ?, ?, NOW(), NOW()
                )
            ");
            $insReturn->execute([
                $returnNumber,
                $returnDate,
                $saleId,
                $sale['sale_number'],
                $sale['patient_id'],
                $sale['customer_name'],
                $sale['sale_type'],
                $totalRefundAmount,
                $paymentMode,
                $returnReason,
                $idempotencyKey ?: null,
                $header['notes'] ?? null,
                $userId,
                $userId
            ]);
            $returnId = (int)$this->pdo->lastInsertId();

            // 5. Insert Line Items and apply restock decision
            $insItem = $this->pdo->prepare("
                INSERT INTO pharmacy_sales_return_items (
                    return_id, sale_item_id, medicine_id, batch_id, sold_quantity,
                    previously_returned_qty, return_quantity, unit_price, discount_percent,
                    tax_percent, refund_amount, condition_status, restock_decision,
                    return_reason, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, NOW()
                )
            ");

            foreach ($processedItems as $pItem) {
                $insItem->execute([
                    $returnId,
                    $pItem['sale_item_id'],
                    $pItem['medicine_id'],
                    $pItem['batch_id'],
                    $pItem['sold_quantity'],
                    $pItem['previously_returned_qty'],
                    $pItem['return_quantity'],
                    $pItem['unit_price'],
                    $pItem['discount_percent'],
                    $pItem['tax_percent'],
                    $pItem['refund_amount'],
                    $pItem['condition_status'],
                    $pItem['restock_decision'],
                    $pItem['return_reason']
                ]);
                $returnItemId = (int)$this->pdo->lastInsertId();

                $medId = $pItem['medicine_id'];
                $bId = $pItem['batch_id'];
                $rQty = $pItem['return_quantity'];
                $decision = $pItem['restock_decision'];

                // Handle Inventory & Stock Ledger according to Restock Decision
                if ($decision === 'SELLABLE_RESTOCK') {
                    // Check expiry date
                    if (strtotime($pItem['expiry_date']) < strtotime(date('Y-m-d'))) {
                        throw new InvalidArgumentException("Batch '{$pItem['batch_number']}' has expired and cannot be restocked into sellable inventory. Choose Quarantine or Disposal.");
                    }
                    if ($pItem['current_batch_status'] === 'Disposed') {
                        throw new InvalidArgumentException("Batch '{$pItem['batch_number']}' has been permanently disposed and cannot be restocked.");
                    }

                    // Increment batch available stock
                    $updBatch = $this->pdo->prepare("
                        UPDATE medicine_batches
                        SET quantity_available = quantity_available + ?, status = 'Active', updated_at = NOW()
                        WHERE batch_id = ?
                    ");
                    $updBatch->execute([$rQty, $bId]);

                    // Increment medicine master stock
                    $updMed = $this->pdo->prepare("
                        UPDATE medicines
                        SET stock_quantity = stock_quantity + ?, updated_at = NOW()
                        WHERE medicine_id = ?
                    ");
                    $updMed->execute([$rQty, $medId]);

                    // Append SALE_RETURN to stock ledger
                    $this->ledgerService->recordEntry(
                        $medId,
                        $bId,
                        'SALE_RETURN',
                        $rQty,
                        $pItem['unit_cost'],
                        $pItem['unit_price'],
                        $returnId,
                        $returnNumber,
                        $userId,
                        "Sale Return #{$returnNumber} restocked: {$pItem['return_reason']}"
                    );
                } elseif ($decision === 'QUARANTINE') {
                    // Stock is held for inspection. Add to quarantined_quantity, not quantity_available!
                    $updBatch = $this->pdo->prepare("
                        UPDATE medicine_batches
                        SET quarantined_quantity = quarantined_quantity + ?, updated_at = NOW()
                        WHERE batch_id = ?
                    ");
                    $updBatch->execute([$rQty, $bId]);

                    // Create quarantine record
                    $qrnNo = $this->seqService->generate('QUARANTINE', 'QRN-');
                    $insQrn = $this->pdo->prepare("
                        INSERT INTO pharmacy_quarantine_records (
                            quarantine_no, medicine_id, batch_id, quantity, reason,
                            source_type, source_id, status, notes, created_by, created_at
                        ) VALUES (
                            ?, ?, ?, ?, 'CUSTOMER_RETURN_INSPECTION',
                            'SALES_RETURN', ?, 'QUARANTINED', ?, ?, NOW()
                        )
                    ");
                    $insQrn->execute([
                        $qrnNo,
                        $medId,
                        $bId,
                        $rQty,
                        $returnId,
                        "Quarantined from Return #{$returnNumber}: {$pItem['return_reason']}",
                        $userId
                    ]);
                } elseif ($decision === 'NON_SELLABLE') {
                    // Damaged stock. Add to damaged_quantity
                    $updBatch = $this->pdo->prepare("
                        UPDATE medicine_batches
                        SET damaged_quantity = damaged_quantity + ?, updated_at = NOW()
                        WHERE batch_id = ?
                    ");
                    $updBatch->execute([$rQty, $bId]);

                    // Append DAMAGE ledger entry
                    $this->ledgerService->recordEntry(
                        $medId,
                        $bId,
                        'DAMAGE',
                        0, // Zero available stock change
                        $pItem['unit_cost'],
                        $pItem['unit_price'],
                        $returnId,
                        $returnNumber,
                        $userId,
                        "Customer return damaged stock recorded in Return #{$returnNumber}"
                    );
                } elseif ($decision === 'DISPOSAL') {
                    // Direct to disposal
                    $dispNo = $this->seqService->generate('DISPOSAL', 'DISP-');
                    $insDisp = $this->pdo->prepare("
                        INSERT INTO pharmacy_disposals (
                            disposal_no, disposal_date, medicine_id, batch_id, quantity,
                            reason, source_type, source_id, status, notes, created_by, created_at
                        ) VALUES (
                            ?, CURDATE(), ?, ?, ?,
                            'DAMAGED', 'SALES_RETURN', ?, 'PENDING', ?, ?, NOW()
                        )
                    ");
                    $insDisp->execute([
                        $dispNo,
                        $medId,
                        $bId,
                        $rQty,
                        $returnId,
                        "Pending destruction from Return #{$returnNumber}",
                        $userId
                    ]);
                }
            }

            // 6. Handle Financial Adjustment / Refund
            $currentBalance = (float)$sale['balance_amount'];
            if ($currentBalance > 0) {
                // Deduct from outstanding balance first
                $balanceDeduction = min($currentBalance, $totalRefundAmount);
                $newBalance = round($currentBalance - $balanceDeduction, 2);
                $newPaymentStatus = ($newBalance <= 0) ? 'PAID' : 'PARTIALLY_PAID';

                $updSaleFin = $this->pdo->prepare("
                    UPDATE pharmacy_sales
                    SET balance_amount = ?, payment_status = ?, updated_at = NOW()
                    WHERE sale_id = ?
                ");
                $updSaleFin->execute([$newBalance, $newPaymentStatus, $saleId]);
            }

            // 7. Audit log
            $this->auditService->logAction(
                $userId,
                'CREATE_SALE_RETURN',
                'pharmacy_sales_returns',
                $returnId,
                null,
                [
                    'return_number'       => $returnNumber,
                    'sale_id'             => $saleId,
                    'total_refund_amount' => $totalRefundAmount,
                    'items_count'         => count($processedItems)
                ]
            );

            $this->pdo->commit();

            return [
                'return_id'           => $returnId,
                'return_number'       => $returnNumber,
                'total_refund_amount' => $totalRefundAmount,
                'items_count'         => count($processedItems),
                'already_processed'   => false
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Fetch complete return details including header, patient/customer, original sale, user, and line items with medicines and batches.
     */
    public function getReturnDetails(int $returnId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT sr.*, 
                   COALESCE(p.name, sr.customer_name, 'Walk-in Customer') AS patient_display_name,
                   p.pharmacy_patient_no, p.mobile AS patient_mobile, p.gender AS patient_gender, p.date_of_birth AS patient_dob, p.address AS patient_address,
                   s.sale_date AS original_sale_date, s.grand_total AS original_grand_total, s.sale_type AS original_sale_type, s.sale_number AS original_sale_number,
                   s.payment_status AS original_payment_status,
                   u.username AS created_by_username,
                   COALESCE(u.full_name, u.username) AS created_by_name
            FROM pharmacy_sales_returns sr
            LEFT JOIN pharmacy_patients p ON sr.patient_id = p.id
            LEFT JOIN pharmacy_sales s ON sr.sale_id = s.sale_id
            LEFT JOIN pharmacy_users u ON sr.created_by = u.id
            WHERE sr.return_id = ?
        ");
        $stmt->execute([$returnId]);
        $return = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$return) {
            return null;
        }

        $itemStmt = $this->pdo->prepare("
            SELECT sri.*,
                   m.medicine_name, m.generic_name,
                   mb.batch_number, mb.expiry_date
            FROM pharmacy_sales_return_items sri
            JOIN medicines m ON sri.medicine_id = m.medicine_id
            LEFT JOIN medicine_batches mb ON sri.batch_id = mb.batch_id
            WHERE sri.return_id = ?
            ORDER BY sri.item_id ASC
        ");
        $itemStmt->execute([$returnId]);
        $return['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        return $return;
    }
}
