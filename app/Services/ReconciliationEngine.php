<?php
// app/Services/ReconciliationEngine.php - Comprehensive Read-Only Audit & Discrepancy Detection

namespace Pharmacy\Services;

use PDO;
use Exception;

class ReconciliationEngine
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Reconcile Batch Quantities:
     * Calculates mathematical expected quantity from ledger movements vs current batch quantity_available.
     *
     * Expected = Opening + Purchases + Sale Returns + Adjustments_In - Sales - Purchase_Returns - Damage - Expiry - Disposal
     *
     * @param int|null $medicineId Optional filter by medicine
     * @return array
     */
    public function reconcileBatches(?int $medicineId = null): array
    {
        $where = ["mb.status != 'Disposed'"];
        $params = [];

        if ($medicineId !== null) {
            $where[] = "mb.medicine_id = ?";
            $params[] = $medicineId;
        }

        $whereSql = implode(' AND ', $where);

        $sql = "
            SELECT 
                mb.batch_id,
                mb.medicine_id,
                m.medicine_name,
                mb.batch_number,
                mb.quantity_available AS actual_quantity,
                mb.status,
                COALESCE((
                    SELECT SUM(quantity_change) 
                    FROM pharmacy_stock_ledger 
                    WHERE batch_id = mb.batch_id
                ), 0) AS ledger_net_sum,
                (SELECT COUNT(*) FROM pharmacy_stock_ledger WHERE batch_id = mb.batch_id) AS ledger_entry_count
            FROM medicine_batches mb
            JOIN medicines m ON mb.medicine_id = m.medicine_id
            WHERE {$whereSql}
            ORDER BY m.medicine_name ASC, mb.batch_id ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($rows as $r) {
            $actual = (int)$r['actual_quantity'];
            $entryCount = (int)$r['ledger_entry_count'];
            $ledgerSum = (int)$r['ledger_net_sum'];

            if ($entryCount === 0) {
                $status = 'INSUFFICIENT_DATA';
                $expected = $actual;
                $diff = 0;
            } else {
                $expected = $ledgerSum;
                $diff = $actual - $expected;
                $status = ($diff === 0) ? 'MATCH' : 'MISMATCH';
            }

            $results[] = [
                'batch_id'       => (int)$r['batch_id'],
                'medicine_id'    => (int)$r['medicine_id'],
                'medicine_name'  => $r['medicine_name'],
                'batch_number'   => $r['batch_number'],
                'status_label'   => $r['status'],
                'expected'       => $expected,
                'actual'         => $actual,
                'difference'     => $diff,
                'status'         => $status,
                'ledger_entries' => $entryCount
            ];
        }

        return $results;
    }

    /**
     * Medicine-level Reconciliation:
     * Compares `medicines.stock_quantity` vs `SUM(Active medicine_batches.quantity_available)`.
     */
    public function reconcileMedicines(): array
    {
        $sql = "
            SELECT 
                m.medicine_id,
                m.medicine_name,
                m.stock_quantity AS master_stock,
                COALESCE(SUM(CASE WHEN mb.status = 'Active' THEN mb.quantity_available ELSE 0 END), 0) AS active_batch_sum,
                COALESCE(SUM(mb.quantity_available), 0) AS total_batch_sum,
                COUNT(mb.batch_id) AS batch_count
            FROM medicines m
            LEFT JOIN medicine_batches mb ON m.medicine_id = mb.medicine_id AND mb.status != 'Disposed'
            WHERE (m.status = 'Active' OR m.status IS NULL) AND m.deleted_at IS NULL
            GROUP BY m.medicine_id, m.medicine_name, m.stock_quantity
            ORDER BY m.medicine_name ASC
        ";

        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($rows as $r) {
            $master = (int)$r['master_stock'];
            $batchSum = (int)$r['active_batch_sum'];
            $diff = $master - $batchSum;
            $status = ($diff === 0) ? 'MATCH' : 'MISMATCH';

            $results[] = [
                'medicine_id'   => (int)$r['medicine_id'],
                'medicine_name' => $r['medicine_name'],
                'master_stock'  => $master,
                'batch_sum'     => $batchSum,
                'total_batches' => (int)$r['batch_count'],
                'difference'    => $diff,
                'status'        => $status
            ];
        }

        return $results;
    }

    /**
     * Stock Ledger Audit:
     */
    public function auditStockLedger(int $limit = 100): array
    {
        $anomalies = [];

        // 1. Zero quantity movements
        $zeroStmt = $this->pdo->query("
            SELECT ledger_id, medicine_id, batch_id, transaction_type, quantity_change, created_at
            FROM pharmacy_stock_ledger
            WHERE quantity_change = 0
            LIMIT 50
        ");
        while ($row = $zeroStmt->fetch(PDO::FETCH_ASSOC)) {
            $anomalies[] = [
                'type'        => 'ZERO_QUANTITY',
                'severity'    => 'MEDIUM',
                'ledger_id'   => $row['ledger_id'],
                'description' => "Ledger entry #{$row['ledger_id']} has 0 quantity change for transaction {$row['transaction_type']}.",
                'created_at'  => $row['created_at']
            ];
        }

        // 2. Negative balances after movement
        $negStmt = $this->pdo->query("
            SELECT ledger_id, medicine_id, batch_id, transaction_type, balance_after, created_at
            FROM pharmacy_stock_ledger
            WHERE balance_after < 0
            LIMIT 50
        ");
        while ($row = $negStmt->fetch(PDO::FETCH_ASSOC)) {
            $anomalies[] = [
                'type'        => 'NEGATIVE_BALANCE',
                'severity'    => 'HIGH',
                'ledger_id'   => $row['ledger_id'],
                'description' => "Ledger entry #{$row['ledger_id']} resulted in negative balance ({$row['balance_after']}).",
                'created_at'  => $row['created_at']
            ];
        }

        // 3. Orphaned medicine references
        $orphanMedStmt = $this->pdo->query("
            SELECT l.ledger_id, l.medicine_id, l.created_at
            FROM pharmacy_stock_ledger l
            LEFT JOIN medicines m ON l.medicine_id = m.medicine_id
            WHERE m.medicine_id IS NULL
            LIMIT 50
        ");
        while ($row = $orphanMedStmt->fetch(PDO::FETCH_ASSOC)) {
            $anomalies[] = [
                'type'        => 'ORPHANED_MEDICINE',
                'severity'    => 'CRITICAL',
                'ledger_id'   => $row['ledger_id'],
                'description' => "Ledger entry #{$row['ledger_id']} references non-existent medicine ID {$row['medicine_id']}.",
                'created_at'  => $row['created_at']
            ];
        }

        // 4. Orphaned batch references
        $orphanBatchStmt = $this->pdo->query("
            SELECT l.ledger_id, l.batch_id, l.created_at
            FROM pharmacy_stock_ledger l
            LEFT JOIN medicine_batches mb ON l.batch_id = mb.batch_id
            WHERE l.batch_id IS NOT NULL AND mb.batch_id IS NULL
            LIMIT 50
        ");
        while ($row = $orphanBatchStmt->fetch(PDO::FETCH_ASSOC)) {
            $anomalies[] = [
                'type'        => 'ORPHANED_BATCH',
                'severity'    => 'CRITICAL',
                'ledger_id'   => $row['ledger_id'],
                'description' => "Ledger entry #{$row['ledger_id']} references non-existent batch ID {$row['batch_id']}.",
                'created_at'  => $row['created_at']
            ];
        }

        // 5. Missing transaction references
        $missingRefStmt = $this->pdo->query("
            SELECT l.ledger_id, l.transaction_type, l.created_at
            FROM pharmacy_stock_ledger l
            WHERE l.reference_id IS NULL 
              AND l.reference_no IS NULL
              AND l.transaction_type NOT IN ('OPENING_STOCK', 'ADJUSTMENT')
            LIMIT 50
        ");
        while ($row = $missingRefStmt->fetch(PDO::FETCH_ASSOC)) {
            $anomalies[] = [
                'type'        => 'MISSING_REFERENCE',
                'severity'    => 'LOW',
                'ledger_id'   => $row['ledger_id'],
                'description' => "Ledger entry #{$row['ledger_id']} ({$row['transaction_type']}) has no transaction reference ID/Number.",
                'created_at'  => $row['created_at']
            ];
        }

        return array_slice($anomalies, 0, $limit);
    }

    /**
     * Financial Reconciliation:
     * Verifies mathematical equilibrium across sales, payments, and supplier invoices.
     */
    public function reconcileFinancials(): array
    {
        // 1. Sales Math Integrity: Subtotal - Discount + Tax + RoundOff == GrandTotal
        // And Payment Equilibrium: Paid + Balance + Returns == GrandTotal
        $salesEqStmt = $this->pdo->query("
            SELECT 
                COUNT(*) AS total_sales_checked,
                COALESCE(SUM(CASE WHEN ROUND(s.subtotal_amount - s.discount_amount + s.gst_amount + s.round_off, 2) != ROUND(s.grand_total, 2) THEN 1 ELSE 0 END), 0) AS arithmetic_mismatches,
                COALESCE(SUM(CASE WHEN ROUND(s.paid_amount + s.balance_amount + COALESCE((SELECT SUM(sr.total_refund_amount) FROM pharmacy_sales_returns sr WHERE sr.sale_id = s.sale_id AND sr.status != 'CANCELLED'), 0), 2) != ROUND(s.grand_total, 2) THEN 1 ELSE 0 END), 0) AS payment_balance_mismatches
            FROM pharmacy_sales s
            WHERE s.status != 'CANCELLED'
        ");
        $salesEq = $salesEqStmt->fetch(PDO::FETCH_ASSOC);

        // 2. Purchase Math Integrity: Taxable - Discount + Tax + RoundOff + Other == GrandTotal
        // And Payment Equilibrium: Paid + Outstanding + Returns == GrandTotal
        $purchEqStmt = $this->pdo->query("
            SELECT 
                COUNT(*) AS total_purchases_checked,
                COALESCE(SUM(CASE WHEN ROUND(pi.taxable_amount + pi.gst_amount + pi.round_off + pi.other_charges, 2) != ROUND(pi.grand_total, 2) THEN 1 ELSE 0 END), 0) AS arithmetic_mismatches,
                COALESCE(SUM(CASE WHEN ROUND(pi.amount_paid + pi.outstanding_amount + COALESCE((SELECT SUM(pr.refund_amount) FROM pharmacy_purchase_returns pr WHERE pr.invoice_id = pi.invoice_id AND pr.status != 'CANCELLED'), 0), 2) != ROUND(pi.grand_total, 2) THEN 1 ELSE 0 END), 0) AS payment_balance_mismatches
            FROM pharmacy_purchase_invoices pi
            WHERE pi.payment_status != 'CANCELLED'
        ");
        $purchEq = $purchEqStmt->fetch(PDO::FETCH_ASSOC);

        // 3. System-wide Global Totals
        $globalSales = $this->pdo->query("
            SELECT 
                COALESCE(SUM(grand_total), 0.00) AS total_net_sales,
                COALESCE(SUM(paid_amount), 0.00) AS total_paid_sales,
                COALESCE(SUM(balance_amount), 0.00) AS total_outstanding_sales
            FROM pharmacy_sales WHERE status != 'CANCELLED'
        ")->fetch(PDO::FETCH_ASSOC);

        $globalReturns = $this->pdo->query("
            SELECT COALESCE(SUM(total_refund_amount), 0.00) AS total_sale_returns
            FROM pharmacy_sales_returns WHERE status != 'CANCELLED'
        ")->fetchColumn();

        $globalPurch = $this->pdo->query("
            SELECT 
                COALESCE(SUM(grand_total), 0.00) AS total_purchases,
                COALESCE(SUM(amount_paid), 0.00) AS total_paid_purchases,
                COALESCE(SUM(outstanding_amount), 0.00) AS total_outstanding_purchases
            FROM pharmacy_purchase_invoices WHERE payment_status != 'CANCELLED'
        ")->fetch(PDO::FETCH_ASSOC);

        $globalPurchReturns = $this->pdo->query("
            SELECT COALESCE(SUM(refund_amount), 0.00) AS total_purchase_returns
            FROM pharmacy_purchase_returns WHERE status != 'CANCELLED'
        ")->fetchColumn();

        return [
            'sales_math' => [
                'total_checked'        => (int)$salesEq['total_sales_checked'],
                'arithmetic_mismatches'=> (int)$salesEq['arithmetic_mismatches'],
                'balance_mismatches'   => (int)$salesEq['payment_balance_mismatches'],
                'status'               => ((int)$salesEq['arithmetic_mismatches'] === 0 && (int)$salesEq['payment_balance_mismatches'] === 0) ? 'MATCH' : 'MISMATCH'
            ],
            'purchases_math' => [
                'total_checked'        => (int)$purchEq['total_purchases_checked'],
                'arithmetic_mismatches'=> (int)$purchEq['arithmetic_mismatches'],
                'balance_mismatches'   => (int)$purchEq['payment_balance_mismatches'],
                'status'               => ((int)$purchEq['arithmetic_mismatches'] === 0 && (int)$purchEq['payment_balance_mismatches'] === 0) ? 'MATCH' : 'MISMATCH'
            ],
            'totals' => [
                'gross_sales'           => (float)$globalSales['total_net_sales'],
                'sale_returns'          => (float)$globalReturns,
                'net_sales'             => (float)$globalSales['total_net_sales'] - (float)$globalReturns,
                'sales_paid'            => (float)$globalSales['total_paid_sales'],
                'sales_outstanding'     => (float)$globalSales['total_outstanding_sales'],
                'gross_purchases'       => (float)$globalPurch['total_purchases'],
                'purchase_returns'      => (float)$globalPurchReturns,
                'net_purchases'         => (float)$globalPurch['total_purchases'] - (float)$globalPurchReturns,
                'purchases_paid'        => (float)$globalPurch['total_paid_purchases'],
                'purchases_outstanding' => (float)$globalPurch['total_outstanding_purchases']
            ]
        ];
    }

    /**
     * Return Invariants Reconciliation:
     * Validates that returned quantities do not exceed original sold/received quantities.
     */
    public function reconcileReturns(): array
    {
        $mismatches = [];

        // 1. Check Sales Returns vs Original Sale Items
        $srSql = "
            SELECT 
                sri.item_id,
                sr.return_number,
                s.sale_number,
                m.medicine_name,
                sri.return_quantity AS returned_qty,
                si.quantity AS original_sold_qty
            FROM pharmacy_sales_return_items sri
            JOIN pharmacy_sales_returns sr ON sri.return_id = sr.return_id
            JOIN pharmacy_sale_items si ON sri.sale_item_id = si.sale_item_id
            JOIN pharmacy_sales s ON sr.sale_id = s.sale_id
            JOIN medicines m ON sri.medicine_id = m.medicine_id
            WHERE sri.return_quantity > si.quantity
        ";
        $srRows = $this->pdo->query($srSql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($srRows as $r) {
            $mismatches[] = [
                'type'        => 'SALE_RETURN_EXCEEDS_SOLD',
                'reference'   => $r['return_number'] . ' (Sale: ' . $r['sale_number'] . ')',
                'medicine'    => $r['medicine_name'],
                'returned_qty'=> (int)$r['returned_qty'],
                'original_qty'=> (int)$r['original_sold_qty'],
                'status'      => 'MISMATCH'
            ];
        }

        // 2. Check Purchase Returns vs Original Purchase Invoice Items
        $prSql = "
            SELECT 
                pri.item_id,
                pr.return_number,
                m.medicine_name,
                pri.quantity AS returned_qty,
                pii.quantity AS original_received_qty
            FROM pharmacy_purchase_return_items pri
            JOIN pharmacy_purchase_returns pr ON pri.return_id = pr.return_id
            JOIN pharmacy_purchase_invoice_items pii ON pr.invoice_id = pii.invoice_id AND pri.medicine_id = pii.medicine_id
            JOIN medicines m ON pri.medicine_id = m.medicine_id
            WHERE pri.quantity > pii.quantity
        ";
        $prRows = $this->pdo->query($prSql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($prRows as $r) {
            $mismatches[] = [
                'type'        => 'PURCHASE_RETURN_EXCEEDS_RECEIVED',
                'reference'   => $r['return_number'],
                'medicine'    => $r['medicine_name'],
                'returned_qty'=> (int)$r['returned_qty'],
                'original_qty'=> (int)$r['original_received_qty'],
                'status'      => 'MISMATCH'
            ];
        }

        return [
            'total_mismatches' => count($mismatches),
            'status'           => (count($mismatches) === 0) ? 'MATCH' : 'MISMATCH',
            'mismatches'       => $mismatches
        ];
    }

    /**
     * Prescription Lifecycle Reconciliation:
     * For each prescription item: Prescribed == Dispensed + Undispensed.
     */
    public function reconcilePrescriptions(): array
    {
        $sql = "
            SELECT 
                p.prescription_number,
                COALESCE(pat.name, 'Unknown') AS patient_name,
                m.medicine_name,
                pi.prescribed_qty,
                pi.dispensed_qty,
                (pi.prescribed_qty - pi.dispensed_qty) AS undispensed_qty,
                CASE 
                    WHEN pi.dispensed_qty > pi.prescribed_qty THEN 'OVER_DISPENSED'
                    WHEN pi.dispensed_qty = pi.prescribed_qty THEN 'FULLY_DISPENSED'
                    WHEN pi.dispensed_qty > 0 THEN 'PARTIALLY_DISPENSED'
                    ELSE 'NOT_DISPENSED'
                END AS fulfillment_status
            FROM pharmacy_prescription_items pi
            JOIN pharmacy_prescriptions p ON pi.prescription_id = p.prescription_id
            JOIN medicines m ON pi.medicine_id = m.medicine_id
            LEFT JOIN pharmacy_patients pat ON p.patient_id = pat.id
            ORDER BY p.prescription_id DESC
            LIMIT 100
        ";

        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $overDispensed = 0;
        foreach ($rows as $r) {
            if ($r['fulfillment_status'] === 'OVER_DISPENSED') {
                $overDispensed++;
            }
        }

        return [
            'total_items_reviewed' => count($rows),
            'over_dispensed_count' => $overDispensed,
            'status'               => ($overDispensed === 0) ? 'MATCH' : 'MISMATCH',
            'items'                => $rows
        ];
    }
}
