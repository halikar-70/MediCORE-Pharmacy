<?php
// app/Services/SupplierPaymentService.php - Supplier Payments, Payables & Financial Ledger

namespace Pharmacy\Services;

use PDO;
use Exception;
use InvalidArgumentException;
use RuntimeException;

class SupplierPaymentService
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
     * Record a supplier payment with invoice allocation.
     * Prevents over-allocation and enforces row-level locking.
     */
    public function recordPayment(array $header, array $allocations, int $userId): int
    {
        $supplierId = (int)($header['supplier_id'] ?? 0);
        if ($supplierId <= 0) {
            throw new InvalidArgumentException("A valid supplier must be selected.");
        }

        $amount = (float)($header['amount'] ?? 0.00);
        if ($amount <= 0.00) {
            throw new InvalidArgumentException("Payment amount must be greater than zero.");
        }

        $paymentDate = !empty($header['payment_date']) ? $header['payment_date'] : date('Y-m-d');
        $paymentMode = !empty($header['payment_mode']) ? $header['payment_mode'] : 'Bank Transfer';
        $referenceNo = !empty($header['reference_no']) ? trim($header['reference_no']) : null;
        $chequeDate = !empty($header['cheque_date']) ? $header['cheque_date'] : null;
        $bankName = !empty($header['bank_name']) ? trim($header['bank_name']) : null;
        $notes = !empty($header['notes']) ? trim($header['notes']) : null;

        $this->pdo->beginTransaction();
        try {
            // Generate dedicated Supplier Payment sequence
            $paymentNumber = $this->seqService->generate('SUPPLIER_PAYMENT', 'SP-');

            // Total allocated check
            $totalAllocated = 0.00;
            $processedAllocations = [];

            if (!empty($allocations)) {
                $invStmt = $this->pdo->prepare("
                    SELECT invoice_id, supplier_id, invoice_number, grand_total, amount_paid, outstanding_amount, payment_status
                    FROM pharmacy_purchase_invoices
                    WHERE invoice_id = ? FOR UPDATE
                ");

                foreach ($allocations as $idx => $alloc) {
                    $invId = (int)($alloc['invoice_id'] ?? 0);
                    $allocAmt = round((float)($alloc['allocated_amount'] ?? 0.00), 2);
                    if ($allocAmt <= 0) continue;

                    $invStmt->execute([$invId]);
                    $inv = $invStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$inv) {
                        throw new InvalidArgumentException("Allocation #" . ($idx + 1) . ": Invoice ID {$invId} not found.");
                    }

                    if ((int)$inv['supplier_id'] !== $supplierId) {
                        throw new InvalidArgumentException("Invoice '{$inv['invoice_number']}' belongs to a different supplier.");
                    }

                    $currentOutstanding = (float)$inv['outstanding_amount'];
                    if ($allocAmt > ($currentOutstanding + 0.01)) {
                        throw new InvalidArgumentException("Allocation of ₹{$allocAmt} exceeds outstanding balance of ₹{$currentOutstanding} for invoice '{$inv['invoice_number']}'.");
                    }

                    $totalAllocated += $allocAmt;
                    $processedAllocations[] = [
                        'invoice'     => $inv,
                        'alloc_amount'=> $allocAmt
                    ];
                }
            }

            if ($totalAllocated > ($amount + 0.01)) {
                throw new InvalidArgumentException("Total allocated amount (₹{$totalAllocated}) cannot exceed payment amount (₹{$amount}).");
            }

            // Insert Payment Header
            $stmtHeader = $this->pdo->prepare("
                INSERT INTO pharmacy_supplier_payments (
                    payment_number, supplier_id, payment_date, amount, payment_mode,
                    reference_no, cheque_date, bank_name, notes, status, created_by, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, 'Completed', ?, NOW(), NOW()
                )
            ");

            $stmtHeader->execute([
                $paymentNumber, $supplierId, $paymentDate, round($amount, 2), $paymentMode,
                $referenceNo, $chequeDate, $bankName, $notes, $userId
            ]);

            $paymentId = (int)$this->pdo->lastInsertId();

            // Insert Allocations and Update Invoices
            $stmtAlloc = $this->pdo->prepare("
                INSERT INTO pharmacy_supplier_payment_allocations (
                    payment_id, invoice_id, allocated_amount, created_at
                ) VALUES (
                    ?, ?, ?, NOW()
                )
            ");

            $stmtUpdInv = $this->pdo->prepare("
                UPDATE pharmacy_purchase_invoices SET
                    amount_paid = ?,
                    outstanding_amount = ?,
                    payment_status = ?,
                    updated_at = NOW()
                WHERE invoice_id = ?
            ");

            foreach ($processedAllocations as $item) {
                $inv = $item['invoice'];
                $allocAmt = $item['alloc_amount'];
                $newPaid = (float)$inv['amount_paid'] + $allocAmt;
                $newOutstanding = max(0.00, round((float)$inv['grand_total'] - $newPaid, 2));
                $newStatus = ($newOutstanding <= 0.01) ? 'PAID' : 'PARTIALLY_PAID';

                $stmtAlloc->execute([$paymentId, $inv['invoice_id'], $allocAmt]);
                $stmtUpdInv->execute([$newPaid, $newOutstanding, $newStatus, $inv['invoice_id']]);
            }

            $this->auditService->logAction(
                $userId,
                'RECORD_SUPPLIER_PAYMENT',
                'pharmacy_supplier_payments',
                $paymentId,
                null,
                [
                    'payment_number' => $paymentNumber,
                    'supplier_id'    => $supplierId,
                    'amount'         => $amount,
                    'allocated'      => $totalAllocated,
                    'payment_mode'   => $paymentMode
                ]
            );

            $this->pdo->commit();
            return $paymentId;
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Cancel/Reverse a supplier payment voucher and restore invoice balances.
     */
    public function cancelPayment(int $paymentId, string $reason, int $userId): bool
    {
        $stmt = $this->pdo->prepare("SELECT * FROM pharmacy_supplier_payments WHERE payment_id = ? FOR UPDATE");
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment) {
            throw new InvalidArgumentException("Payment voucher not found.");
        }
        if ($payment['status'] === 'Cancelled') {
            return true;
        }

        $this->pdo->beginTransaction();
        try {
            // Get allocations
            $allocStmt = $this->pdo->prepare("SELECT invoice_id, allocated_amount FROM pharmacy_supplier_payment_allocations WHERE payment_id = ?");
            $allocStmt->execute([$paymentId]);
            $allocations = $allocStmt->fetchAll(PDO::FETCH_ASSOC);

            $fetchInv = $this->pdo->prepare("SELECT invoice_id, grand_total, amount_paid FROM pharmacy_purchase_invoices WHERE invoice_id = ? FOR UPDATE");
            $updInv = $this->pdo->prepare("
                UPDATE pharmacy_purchase_invoices SET
                    amount_paid = ?,
                    outstanding_amount = ?,
                    payment_status = ?,
                    updated_at = NOW()
                WHERE invoice_id = ?
            ");

            foreach ($allocations as $a) {
                $iId = (int)$a['invoice_id'];
                $amt = (float)$a['allocated_amount'];
                $fetchInv->execute([$iId]);
                $invRow = $fetchInv->fetch(PDO::FETCH_ASSOC);
                if ($invRow) {
                    $revertedPaid = max(0.00, (float)$invRow['amount_paid'] - $amt);
                    $revertedOutstanding = max(0.00, round((float)$invRow['grand_total'] - $revertedPaid, 2));
                    $revertedStatus = ($revertedPaid <= 0.01) ? 'UNPAID' : 'PARTIALLY_PAID';
                    $updInv->execute([$revertedPaid, $revertedOutstanding, $revertedStatus, $iId]);
                }
            }

            // Mark payment cancelled
            $stmtUpd = $this->pdo->prepare("
                UPDATE pharmacy_supplier_payments SET
                    status = 'Cancelled',
                    notes = CONCAT(IFNULL(notes,''), '\n[CANCELLED: ', ?, ']'),
                    updated_at = NOW()
                WHERE payment_id = ?
            ");
            $stmtUpd->execute([$reason, $paymentId]);

            $this->auditService->logAction($userId, 'CANCEL_SUPPLIER_PAYMENT', 'pharmacy_supplier_payments', $paymentId, ['status' => 'Completed'], ['status' => 'Cancelled', 'reason' => $reason]);

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
     * Get detailed chronological financial ledger for a supplier.
     * Shows: Date, Document Type, Reference / Document No, Debit (Purchases), Credit (Payments), Running Balance.
     */
    public function getSupplierLedger(int $supplierId): array
    {
        $supStmt = $this->pdo->prepare("SELECT supplier_id, supplier_code, supplier_name, opening_balance FROM pharmacy_suppliers WHERE supplier_id = ?");
        $supStmt->execute([$supplierId]);
        $supplier = $supStmt->fetch(PDO::FETCH_ASSOC);
        if (!$supplier) {
            throw new InvalidArgumentException("Supplier not found.");
        }

        $openingBal = (float)$supplier['opening_balance'];

        // Collect all purchase invoices
        $invStmt = $this->pdo->prepare("
            SELECT 
                invoice_date as tx_date,
                'PURCHASE' as doc_type,
                invoice_number as doc_no,
                supplier_invoice_no as ref_no,
                grand_total as debit,
                0.00 as credit,
                notes,
                created_at
            FROM pharmacy_purchase_invoices
            WHERE supplier_id = ?
        ");
        $invStmt->execute([$supplierId]);
        $invoices = $invStmt->fetchAll(PDO::FETCH_ASSOC);

        // Collect all payments
        $payStmt = $this->pdo->prepare("
            SELECT 
                payment_date as tx_date,
                'PAYMENT' as doc_type,
                payment_number as doc_no,
                reference_no as ref_no,
                0.00 as debit,
                amount as credit,
                notes,
                created_at
            FROM pharmacy_supplier_payments
            WHERE supplier_id = ? AND status = 'Completed'
        ");
        $payStmt->execute([$supplierId]);
        $payments = $payStmt->fetchAll(PDO::FETCH_ASSOC);

        // Merge and sort chronologically
        $allTx = array_merge($invoices, $payments);
        usort($allTx, function ($a, $b) {
            $cmp = strcmp($a['tx_date'], $b['tx_date']);
            if ($cmp === 0) {
                return strcmp($a['created_at'], $b['created_at']);
            }
            return $cmp;
        });

        $runningBalance = $openingBal;
        $ledgerEntries = [];

        // First row: Opening Balance if non-zero
        if (abs($openingBal) > 0.001) {
            $ledgerEntries[] = [
                'tx_date'         => date('Y-m-d', strtotime('-1 day', strtotime($allTx[0]['tx_date'] ?? date('Y-m-d')))),
                'doc_type'        => 'OPENING_BALANCE',
                'doc_no'          => 'OB-001',
                'ref_no'          => 'Opening Balance',
                'debit'           => $openingBal > 0 ? $openingBal : 0.00,
                'credit'          => $openingBal < 0 ? abs($openingBal) : 0.00,
                'running_balance' => $runningBalance,
                'notes'           => 'Initial supplier ledger opening balance'
            ];
        }

        foreach ($allTx as $tx) {
            $debit = (float)$tx['debit'];
            $credit = (float)$tx['credit'];
            $runningBalance += ($debit - $credit);

            $ledgerEntries[] = [
                'tx_date'         => $tx['tx_date'],
                'doc_type'        => $tx['doc_type'],
                'doc_no'          => $tx['doc_no'],
                'ref_no'          => $tx['ref_no'],
                'debit'           => $debit,
                'credit'          => $credit,
                'running_balance' => round($runningBalance, 2),
                'notes'           => $tx['notes']
            ];
        }

        return [
            'supplier'        => $supplier,
            'opening_balance' => $openingBal,
            'closing_balance' => round($runningBalance, 2),
            'entries'         => $ledgerEntries
        ];
    }

    /**
     * Get supplier outstanding dues aging report.
     */
    public function getAgingReport(?int $supplierId = null): array
    {
        $sql = "
            SELECT 
                s.supplier_id, s.supplier_code, s.supplier_name, s.phone,
                pi.invoice_id, pi.invoice_number, pi.supplier_invoice_no, pi.invoice_date, pi.due_date,
                pi.grand_total, pi.amount_paid, pi.outstanding_amount,
                DATEDIFF(CURRENT_DATE(), pi.due_date) as days_overdue,
                CASE 
                    WHEN DATEDIFF(CURRENT_DATE(), pi.due_date) <= 0 THEN 'Current'
                    WHEN DATEDIFF(CURRENT_DATE(), pi.due_date) BETWEEN 1 AND 30 THEN '1-30 Days'
                    WHEN DATEDIFF(CURRENT_DATE(), pi.due_date) BETWEEN 31 AND 60 THEN '31-60 Days'
                    WHEN DATEDIFF(CURRENT_DATE(), pi.due_date) BETWEEN 61 AND 90 THEN '61-90 Days'
                    ELSE '90+ Days'
                END as aging_bucket
            FROM pharmacy_purchase_invoices pi
            JOIN pharmacy_suppliers s ON pi.supplier_id = s.supplier_id
            WHERE pi.outstanding_amount > 0.00 AND pi.payment_status != 'PAID'
        ";
        $params = [];

        if ($supplierId !== null) {
            $sql .= " AND s.supplier_id = ?";
            $params[] = $supplierId;
        }

        $sql .= " ORDER BY days_overdue DESC, pi.invoice_date ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
