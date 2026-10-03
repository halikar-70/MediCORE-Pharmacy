<?php
// app/Services/PurchaseReturnService.php - Supplier Purchase Returns & Payables Adjustment

namespace Pharmacy\Services;

use PDO;
use Exception;
use InvalidArgumentException;
use RuntimeException;

class PurchaseReturnService
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
     * Get purchase invoice details with line-by-line received, previously returned, and current available batch stock.
     */
    public function getReturnableInvoiceDetails(int $invoiceId): array
    {
        $invStmt = $this->pdo->prepare("
            SELECT pi.*, s.supplier_name, s.contact_person, s.phone AS supplier_phone, s.gstin
            FROM pharmacy_purchase_invoices pi
            JOIN pharmacy_suppliers s ON pi.supplier_id = s.supplier_id
            WHERE pi.invoice_id = ?
        ");
        $invStmt->execute([$invoiceId]);
        $invoice = $invStmt->fetch(PDO::FETCH_ASSOC);

        if (!$invoice) {
            throw new InvalidArgumentException("Purchase Invoice #{$invoiceId} not found.");
        }

        $itemStmt = $this->pdo->prepare("
            SELECT pii.*, m.medicine_name, m.generic_name,
                   mb.batch_number, mb.expiry_date, mb.quantity_available, mb.status AS batch_status,
                   COALESCE((
                       SELECT SUM(pri.quantity)
                       FROM pharmacy_purchase_return_items pri
                       JOIN pharmacy_purchase_returns pr ON pri.return_id = pr.return_id
                       WHERE pr.invoice_id = pii.invoice_id AND pri.medicine_id = pii.medicine_id
                         AND (pri.batch_id = pii.batch_id OR pri.batch_id IS NULL)
                         AND pr.status != 'CANCELLED'
                   ), 0) AS previously_returned_qty
            FROM pharmacy_purchase_invoice_items pii
            JOIN medicines m ON pii.medicine_id = m.medicine_id
            LEFT JOIN medicine_batches mb ON pii.batch_id = mb.batch_id
            WHERE pii.invoice_id = ?
        ");
        $itemStmt->execute([$invoiceId]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as &$it) {
            $received = (int)$it['quantity'];
            $prevRet = (int)$it['previously_returned_qty'];
            $avail = (int)($it['quantity_available'] ?? 0);
            $it['quantity'] = $received;
            $it['previously_returned_qty'] = $prevRet;
            $it['max_returnable_from_invoice'] = max(0, $received - $prevRet);
            $it['returnable_quantity'] = min($it['max_returnable_from_invoice'], $avail);
        }

        $invoice['items'] = $items;
        return $invoice;
    }

    /**
     * Create and post a purchase return atomically.
     * Decrements physical batch inventory, creates outward stock movement in ledger,
     * and adjusts supplier payables/outstanding balance.
     */
    public function createPurchaseReturn(array $header, array $items, int $userId): array
    {
        $invoiceId = (int)($header['invoice_id'] ?? 0);
        if ($invoiceId <= 0) {
            throw new InvalidArgumentException("A valid Purchase Invoice ID is required.");
        }

        if (empty($items)) {
            throw new InvalidArgumentException("Purchase return must contain at least one item.");
        }

        $idempotencyKey = trim($header['idempotency_key'] ?? '');
        if ($idempotencyKey !== '') {
            $dupCheck = $this->pdo->prepare("SELECT return_id, return_number FROM pharmacy_purchase_returns WHERE idempotency_key = ?");
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
            // 1. Lock purchase invoice
            $invStmt = $this->pdo->prepare("
                SELECT invoice_id, invoice_number, supplier_invoice_no, supplier_id, grn_id,
                       grand_total, amount_paid, outstanding_amount, payment_status
                FROM pharmacy_purchase_invoices
                WHERE invoice_id = ? FOR UPDATE
            ");
            $invStmt->execute([$invoiceId]);
            $invoice = $invStmt->fetch(PDO::FETCH_ASSOC);

            if (!$invoice) {
                throw new InvalidArgumentException("Purchase Invoice #{$invoiceId} does not exist.");
            }

            $supplierId = (int)$invoice['supplier_id'];

            // 2. Generate unique Return Number
            $returnNumber = $this->seqService->generate('PURCHASE_RETURN', 'PRT-');
            $returnDate = !empty($header['return_date']) ? $header['return_date'] : date('Y-m-d');
            $reason = trim($header['reason'] ?? 'Supplier Return');
            $notes = trim($header['notes'] ?? '');

            $totalRefundAmount = 0.00;
            $processedItems = [];

            // 3. Process line items with row-level locks
            $itemLockStmt = $this->pdo->prepare("
                SELECT invoice_item_id, invoice_id, medicine_id, batch_id, batch_number, quantity, purchase_rate, line_total
                FROM pharmacy_purchase_invoice_items
                WHERE invoice_item_id = ? AND invoice_id = ? FOR UPDATE
            ");

            $batchLockStmt = $this->pdo->prepare("
                SELECT batch_id, medicine_id, batch_number, quantity_available, purchase_price, sale_price, status
                FROM medicine_batches
                WHERE batch_id = ? FOR UPDATE
            ");

            $prevReturnStmt = $this->pdo->prepare("
                SELECT COALESCE(SUM(pri.quantity), 0)
                FROM pharmacy_purchase_return_items pri
                JOIN pharmacy_purchase_returns pr ON pri.return_id = pr.return_id
                WHERE pr.invoice_id = ? AND pri.medicine_id = ? AND (pri.batch_id = ? OR pri.batch_id IS NULL)
                  AND pr.status != 'CANCELLED'
            ");

            foreach ($items as $it) {
                $invoiceItemId = (int)($it['invoice_item_id'] ?? 0);
                $returnQty = (int)($it['return_quantity'] ?? 0);
                $lineReason = trim($it['reason'] ?? $reason);
                $condition = trim($it['condition_status'] ?? 'DEFECTIVE');

                if ($returnQty <= 0) {
                    continue;
                }

                $itemLockStmt->execute([$invoiceItemId, $invoiceId]);
                $origItem = $itemLockStmt->fetch(PDO::FETCH_ASSOC);
                if (!$origItem) {
                    throw new InvalidArgumentException("Invoice item #{$invoiceItemId} does not belong to Invoice #{$invoice['invoice_number']}.");
                }

                $batchId = (int)$origItem['batch_id'];
                $medId = (int)$origItem['medicine_id'];
                $receivedQty = (int)$origItem['quantity'];

                // Cumulative return check
                $prevReturnStmt->execute([$invoiceId, $medId, $batchId]);
                $previouslyReturned = (int)$prevReturnStmt->fetchColumn();

                if (($previouslyReturned + $returnQty) > $receivedQty) {
                    $maxPossible = max(0, $receivedQty - $previouslyReturned);
                    throw new InvalidArgumentException(
                        "Cannot return {$returnQty} units. Maximum remaining returnable quantity is {$maxPossible} for medicine #{$medId}."
                    );
                }

                // Check physical batch stock availability
                $batchLockStmt->execute([$batchId]);
                $batch = $batchLockStmt->fetch(PDO::FETCH_ASSOC);
                if (!$batch) {
                    throw new InvalidArgumentException("Batch #{$batchId} not found in inventory.");
                }

                $availQty = (int)$batch['quantity_available'];
                if ($availQty < $returnQty) {
                    throw new InvalidArgumentException(
                        "Insufficient stock in batch '{$batch['batch_number']}' for return. Available: {$availQty}, requested return: {$returnQty}."
                    );
                }

                $purchaseRate = (float)$origItem['purchase_rate'];
                $lineAmount = round($purchaseRate * $returnQty, 2);
                $totalRefundAmount += $lineAmount;

                $processedItems[] = [
                    'medicine_id'       => $medId,
                    'batch_id'          => $batchId,
                    'batch_number'      => $batch['batch_number'],
                    'quantity'          => $returnQty,
                    'received_quantity' => $receivedQty,
                    'refund_rate'       => $purchaseRate,
                    'amount'            => $lineAmount,
                    'sale_price'        => (float)$batch['sale_price'],
                    'reason'            => $lineReason,
                    'condition_status'  => $condition
                ];
            }

            if (empty($processedItems)) {
                throw new InvalidArgumentException("At least one item must have a valid return quantity > 0.");
            }

            // 4. Insert Purchase Return Header
            $insReturn = $this->pdo->prepare("
                INSERT INTO pharmacy_purchase_returns (
                    return_number, invoice_id, supplier_id, grn_id, return_date,
                    refund_amount, status, reason, notes, idempotency_key,
                    created_by, approved_by, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, 'POSTED', ?, ?, ?,
                    ?, ?, NOW()
                )
            ");
            $insReturn->execute([
                $returnNumber,
                $invoiceId,
                $supplierId,
                $invoice['grn_id'],
                $returnDate,
                $totalRefundAmount,
                $reason,
                $notes ?: null,
                $idempotencyKey ?: null,
                $userId,
                $userId
            ]);
            $returnId = (int)$this->pdo->lastInsertId();

            // 5. Insert Items, Deduct Stock & Write Stock Ledger
            $insItem = $this->pdo->prepare("
                INSERT INTO pharmacy_purchase_return_items (
                    return_id, medicine_id, batch_id, quantity, received_quantity,
                    refund_rate, amount, reason, condition_status
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?
                )
            ");

            foreach ($processedItems as $pItem) {
                $insItem->execute([
                    $returnId,
                    $pItem['medicine_id'],
                    $pItem['batch_id'],
                    $pItem['quantity'],
                    $pItem['received_quantity'],
                    $pItem['refund_rate'],
                    $pItem['amount'],
                    $pItem['reason'],
                    $pItem['condition_status']
                ]);

                $medId = $pItem['medicine_id'];
                $bId = $pItem['batch_id'];
                $rQty = $pItem['quantity'];

                // Deduct batch stock
                $updBatch = $this->pdo->prepare("
                    UPDATE medicine_batches
                    SET quantity_available = quantity_available - ?,
                        status = IF(quantity_available - ? <= 0, 'Depleted', status),
                        updated_at = NOW()
                    WHERE batch_id = ?
                ");
                $updBatch->execute([$rQty, $rQty, $bId]);

                // Deduct medicine aggregate stock
                $updMed = $this->pdo->prepare("
                    UPDATE medicines
                    SET stock_quantity = stock_quantity - ?, updated_at = NOW()
                    WHERE medicine_id = ?
                ");
                $updMed->execute([$rQty, $medId]);

                // Append PURCHASE_RETURN movement to stock ledger
                $this->ledgerService->recordEntry(
                    $medId,
                    $bId,
                    'PURCHASE_RETURN',
                    -$rQty, // Outward stock movement
                    $pItem['refund_rate'],
                    $pItem['sale_price'],
                    $returnId,
                    $returnNumber,
                    $userId,
                    "Purchase Return #{$returnNumber} to supplier: {$pItem['reason']}"
                );
            }

            // 6. Update Supplier Financials (Reduce Outstanding)
            $currentOutstanding = (float)$invoice['outstanding_amount'];
            $newOutstanding = max(0.00, round($currentOutstanding - $totalRefundAmount, 2));
            $newPaymentStatus = ($newOutstanding <= 0.00) ? 'PAID' : 'PARTIALLY_PAID';

            $updInvFin = $this->pdo->prepare("
                UPDATE pharmacy_purchase_invoices
                SET outstanding_amount = ?, payment_status = ?, updated_at = NOW()
                WHERE invoice_id = ?
            ");
            $updInvFin->execute([$newOutstanding, $newPaymentStatus, $invoiceId]);

            // 7. Audit log
            $this->auditService->logAction(
                $userId,
                'CREATE_PURCHASE_RETURN',
                'pharmacy_purchase_returns',
                $returnId,
                null,
                [
                    'return_number'       => $returnNumber,
                    'invoice_id'          => $invoiceId,
                    'supplier_id'         => $supplierId,
                    'refund_amount'       => $totalRefundAmount,
                    'items_count'         => count($processedItems)
                ]
            );

            $this->pdo->commit();

            return [
                'return_id'           => $returnId,
                'return_number'       => $returnNumber,
                'refund_amount'       => $totalRefundAmount,
                'items_count'         => count($processedItems),
                'new_outstanding'     => $newOutstanding,
                'already_processed'   => false
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
