<?php
// app/Services/AnalyticsService.php - Authoritative Analytics & Metrics Engine

namespace Pharmacy\Services;

use PDO;
use Exception;
use InvalidArgumentException;

class AnalyticsService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Standardized server-side date range boundary resolution.
     */
    public static function getDateRangeBounds(string $preset = 'today', ?string $customStart = null, ?string $customEnd = null): array
    {
        $today = date('Y-m-d');
        $start = $today;
        $end = $today;
        $label = 'Today';

        switch (strtolower($preset)) {
            case 'yesterday':
                $start = date('Y-m-d', strtotime('-1 day'));
                $end = $start;
                $label = 'Yesterday';
                break;

            case 'this_week':
                $start = date('Y-m-d', strtotime('monday this week'));
                $end = $today;
                $label = 'This Week';
                break;

            case 'this_month':
                $start = date('Y-m-01');
                $end = $today;
                $label = 'This Month';
                break;

            case 'prev_month':
                $start = date('Y-m-01', strtotime('first day of last month'));
                $end = date('Y-m-t', strtotime('last day of last month'));
                $label = 'Previous Month';
                break;

            case 'this_quarter':
                $currentMonth = (int)date('n');
                $quarterStartMonth = (int)(floor(($currentMonth - 1) / 3) * 3) + 1;
                $start = date('Y-') . str_pad($quarterStartMonth, 2, '0', STR_PAD_LEFT) . '-01';
                $end = $today;
                $label = 'This Quarter';
                break;

            case 'this_year':
                $start = date('Y-01-01');
                $end = $today;
                $label = 'This Year';
                break;

            case 'custom':
                if (!empty($customStart) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $customStart)) {
                    $start = $customStart;
                }
                if (!empty($customEnd) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $customEnd)) {
                    $end = $customEnd;
                }
                if ($start > $end) {
                    $tmp = $start;
                    $start = $end;
                    $end = $tmp;
                }
                $label = "Custom ({$start} to {$end})";
                break;

            case 'today':
            default:
                $start = $today;
                $end = $today;
                $label = 'Today';
                break;
        }

        return [
            'preset'    => $preset,
            'label'     => $label,
            'start_date'=> $start,
            'end_date'  => $end,
            'start'     => $start . ' 00:00:00',
            'end'       => $end . ' 23:59:59'
        ];
    }

    /**
     * Comprehensive Executive Dashboard KPI metrics.
     */
    public function getDashboardMetrics(array $dateRange): array
    {
        $startDate = $dateRange['start_date'];
        $endDate = $dateRange['end_date'];

        // 1. SALES METRICS
        $salesStmt = $this->pdo->prepare("
            SELECT 
                COALESCE(COUNT(*), 0) AS total_invoices,
                COALESCE(SUM(subtotal_amount), 0.00) AS gross_sales,
                COALESCE(SUM(discount_amount), 0.00) AS total_discount,
                COALESCE(SUM(gst_amount), 0.00) AS total_tax,
                COALESCE(SUM(grand_total), 0.00) AS net_sales,
                COALESCE(SUM(paid_amount), 0.00) AS total_paid,
                COALESCE(SUM(balance_amount), 0.00) AS total_outstanding,
                COALESCE(AVG(grand_total), 0.00) AS avg_invoice_value
            FROM pharmacy_sales
            WHERE sale_date BETWEEN ? AND ?
              AND status != 'CANCELLED'
        ");
        $salesStmt->execute([$startDate, $endDate]);
        $sales = $salesStmt->fetch(PDO::FETCH_ASSOC);

        // Sales by payment mode
        $pmStmt = $this->pdo->prepare("
            SELECT 
                payment_mode,
                COALESCE(SUM(grand_total), 0.00) AS amount
            FROM pharmacy_sales
            WHERE sale_date BETWEEN ? AND ?
              AND status != 'CANCELLED'
            GROUP BY payment_mode
        ");
        $pmStmt->execute([$startDate, $endDate]);
        $pmRows = $pmStmt->fetchAll(PDO::FETCH_ASSOC);
        $pmMap = ['CASH' => 0.0, 'CARD' => 0.0, 'UPI' => 0.0, 'CREDIT' => 0.0, 'OTHER' => 0.0];
        foreach ($pmRows as $r) {
            $m = strtoupper($r['payment_mode'] ?? 'OTHER');
            if (isset($pmMap[$m])) {
                $pmMap[$m] = (float)$r['amount'];
            } else {
                $pmMap['OTHER'] += (float)$r['amount'];
            }
        }

        // Sales Returns
        $srStmt = $this->pdo->prepare("
            SELECT 
                COALESCE(COUNT(*), 0) AS return_count,
                COALESCE(SUM(total_refund_amount), 0.00) AS return_value
            FROM pharmacy_sales_returns
            WHERE return_date BETWEEN ? AND ?
              AND status != 'CANCELLED'
        ");
        $srStmt->execute([$startDate, $endDate]);
        $salesReturns = $srStmt->fetch(PDO::FETCH_ASSOC);

        $netSalesAfterReturns = (float)$sales['net_sales'] - (float)$salesReturns['return_value'];

        // 1b. OPD & IPD CHANNEL PERFORMANCE BREAKDOWN
        $opdStmt = $this->pdo->prepare("
            SELECT 
                COALESCE(COUNT(*), 0) AS total_invoices,
                COALESCE(SUM(subtotal_amount), 0.00) AS gross_sales,
                COALESCE(SUM(grand_total), 0.00) AS net_sales,
                COALESCE(SUM(paid_amount), 0.00) AS paid_amount,
                COALESCE(SUM(balance_amount), 0.00) AS outstanding_amount,
                COALESCE(SUM(CASE WHEN payment_status IN ('UNPAID', 'PARTIALLY_PAID') THEN 1 ELSE 0 END), 0) AS pending_bills
            FROM pharmacy_sales
            WHERE sale_date BETWEEN ? AND ?
              AND status != 'CANCELLED'
              AND (sale_type IN ('COUNTER_SALE', 'PRESCRIPTION_SALE') OR sale_type IS NULL)
        ");
        $opdStmt->execute([$startDate, $endDate]);
        $opdData = $opdStmt->fetch(PDO::FETCH_ASSOC);

        $ipdStmt = $this->pdo->prepare("
            SELECT 
                COALESCE(COUNT(*), 0) AS total_invoices,
                COALESCE(SUM(subtotal_amount), 0.00) AS gross_sales,
                COALESCE(SUM(grand_total), 0.00) AS net_sales,
                COALESCE(SUM(paid_amount), 0.00) AS paid_amount,
                COALESCE(SUM(balance_amount), 0.00) AS outstanding_amount,
                COALESCE(SUM(CASE WHEN payment_status IN ('UNPAID', 'PARTIALLY_PAID', 'CREDIT') THEN 1 ELSE 0 END), 0) AS pending_bills
            FROM pharmacy_sales
            WHERE sale_date BETWEEN ? AND ?
              AND status != 'CANCELLED'
              AND sale_type = 'IPD_SALE'
        ");
        $ipdStmt->execute([$startDate, $endDate]);
        $ipdData = $ipdStmt->fetch(PDO::FETCH_ASSOC);

        $opdPendingPrescriptions = 0;
        $ipdPendingPrescriptions = 0;

        // 2. PURCHASES METRICS
        $purchStmt = $this->pdo->prepare("
            SELECT 
                COALESCE(COUNT(*), 0) AS total_invoices,
                COALESCE(SUM(taxable_amount), 0.00) AS gross_purchases,
                COALESCE(SUM(discount_amount), 0.00) AS total_discount,
                COALESCE(SUM(gst_amount), 0.00) AS total_tax,
                COALESCE(SUM(grand_total), 0.00) AS net_purchases,
                COALESCE(SUM(amount_paid), 0.00) AS total_paid,
                COALESCE(SUM(outstanding_amount), 0.00) AS total_outstanding
            FROM pharmacy_purchase_invoices
            WHERE invoice_date BETWEEN ? AND ?
              AND payment_status != 'CANCELLED'
        ");
        $purchStmt->execute([$startDate, $endDate]);
        $purch = $purchStmt->fetch(PDO::FETCH_ASSOC);

        // Purchase Returns
        $prStmt = $this->pdo->prepare("
            SELECT 
                COALESCE(COUNT(*), 0) AS return_count,
                COALESCE(SUM(refund_amount), 0.00) AS return_value
            FROM pharmacy_purchase_returns
            WHERE return_date BETWEEN ? AND ?
              AND status != 'CANCELLED'
        ");
        $prStmt->execute([$startDate, $endDate]);
        $purchReturns = $prStmt->fetch(PDO::FETCH_ASSOC);

        $netPurchasesAfterReturns = (float)$purch['net_purchases'] - (float)$purchReturns['return_value'];

        // 3. AUTHORITATIVE INVENTORY METRICS
        $invStmt = $this->pdo->query("
            SELECT 
                (SELECT COUNT(*) FROM medicines WHERE (status = 'Active' OR status IS NULL) AND deleted_at IS NULL) AS total_medicines,
                (SELECT COUNT(*) FROM medicine_batches WHERE status = 'Active' AND quantity_available > 0) AS active_batches,
                (SELECT COALESCE(SUM(quantity_available * purchase_price), 0.00) FROM medicine_batches WHERE status = 'Active' AND quantity_available > 0) AS available_stock_value,
                (SELECT COALESCE(SUM(quantity_available), 0) FROM medicine_batches WHERE status = 'Active' AND quantity_available > 0) AS available_units,
                (SELECT COALESCE(SUM(quantity_available), 0) FROM medicine_batches WHERE status = 'Quarantined') AS quarantined_units,
                (SELECT COALESCE(SUM(quantity_available), 0) FROM medicine_batches WHERE status = 'Expired') AS expired_units,
                (SELECT COALESCE(SUM(quantity_available), 0) FROM medicine_batches WHERE status = 'Damaged') AS damaged_units,
                (SELECT COALESCE(SUM(quantity_available), 0) FROM medicine_batches WHERE status = 'Disposed') AS disposed_units,
                (SELECT COUNT(*) FROM medicines WHERE (status = 'Active' OR status IS NULL) AND deleted_at IS NULL AND stock_quantity <= reorder_level AND stock_quantity > 0) AS low_stock_count,
                (SELECT COUNT(*) FROM medicines WHERE (status = 'Active' OR status IS NULL) AND deleted_at IS NULL AND stock_quantity <= 0) AS out_of_stock_count,
                (SELECT COUNT(*) FROM medicine_batches WHERE status = 'Active' AND quantity_available > 0 AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)) AS expiring_soon_batches
        ");
        $inventory = $invStmt->fetch(PDO::FETCH_ASSOC);

        // 4. OPERATIONS WORKFLOW COUNTS
        $opsStmt = $this->pdo->query("
            SELECT 
                (SELECT COUNT(*) FROM pharmacy_indents WHERE status IN ('SUBMITTED', 'PENDING', 'APPROVED')) AS pending_indents,
                (SELECT COUNT(*) FROM pharmacy_sales_returns WHERE status = 'PENDING') AS pending_sale_returns,
                (SELECT COUNT(*) FROM pharmacy_purchase_returns WHERE status = 'PENDING') AS pending_purchase_returns,
                (SELECT COUNT(*) FROM pharmacy_quarantine_records WHERE status = 'QUARANTINED') AS active_quarantine_records,
                (SELECT COUNT(*) FROM pharmacy_disposals WHERE status = 'PLANNED') AS pending_disposals
        ");
        $operations = $opsStmt->fetch(PDO::FETCH_ASSOC);

        // 5. CLINICAL MAR METRICS (TODAY)
        $marStmt = $this->pdo->query("
            SELECT 
                COALESCE(COUNT(*), 0) AS total_scheduled,
                COALESCE(SUM(CASE WHEN status = 'GIVEN' THEN 1 ELSE 0 END), 0) AS administered,
                COALESCE(SUM(CASE WHEN status = 'MISSED' THEN 1 ELSE 0 END), 0) AS missed,
                COALESCE(SUM(CASE WHEN status = 'HELD' THEN 1 ELSE 0 END), 0) AS held,
                COALESCE(SUM(CASE WHEN status = 'REFUSED' THEN 1 ELSE 0 END), 0) AS refused
            FROM pharmacy_mar_records
            WHERE scheduled_date = CURDATE()
        ");
        $mar = $marStmt->fetch(PDO::FETCH_ASSOC);

        return [
            'period' => $dateRange,
            'sales' => [
                'invoices_count'   => (int)$sales['total_invoices'],
                'gross_sales'      => (float)$sales['gross_sales'],
                'discount'         => (float)$sales['total_discount'],
                'tax'              => (float)$sales['total_tax'],
                'net_sales'        => (float)$sales['net_sales'],
                'paid'             => (float)$sales['total_paid'],
                'outstanding'      => (float)$sales['total_outstanding'],
                'avg_invoice'      => (float)$sales['avg_invoice_value'],
                'cash'             => $pmMap['CASH'],
                'card'             => $pmMap['CARD'],
                'upi'              => $pmMap['UPI'],
                'credit'           => $pmMap['CREDIT'],
                'returns_count'    => (int)$salesReturns['return_count'],
                'returns_value'    => (float)$salesReturns['return_value'],
                'net_after_returns'=> $netSalesAfterReturns
            ],
            'purchases' => [
                'invoices_count'   => (int)$purch['total_invoices'],
                'gross_purchases'  => (float)$purch['gross_purchases'],
                'discount'         => (float)$purch['total_discount'],
                'tax'              => (float)$purch['total_tax'],
                'net_purchases'    => (float)$purch['net_purchases'],
                'paid'             => (float)$purch['total_paid'],
                'outstanding'      => (float)$purch['total_outstanding'],
                'returns_count'    => (int)$purchReturns['return_count'],
                'returns_value'    => (float)$purchReturns['return_value'],
                'net_after_returns'=> $netPurchasesAfterReturns
            ],
            'inventory' => [
                'total_medicines'      => (int)$inventory['total_medicines'],
                'active_batches'       => (int)$inventory['active_batches'],
                'available_units'      => (int)$inventory['available_units'],
                'available_stock_value'=> (float)$inventory['available_stock_value'],
                'quarantined_units'    => (int)$inventory['quarantined_units'],
                'expired_units'        => (int)$inventory['expired_units'],
                'damaged_units'        => (int)$inventory['damaged_units'],
                'disposed_units'       => (int)$inventory['disposed_units'],
                'low_stock_medicines'  => (int)$inventory['low_stock_count'],
                'out_of_stock_medicines'=> (int)$inventory['out_of_stock_count'],
                'expiring_soon_batches'=> (int)$inventory['expiring_soon_batches']
            ],
            'operations' => [
                'pending_indents'       => (int)($operations['pending_indents'] ?? 0),
                'pending_sale_returns'  => (int)$operations['pending_sale_returns'],
                'pending_purchase_returns' => (int)$operations['pending_purchase_returns'],
                'active_quarantine'     => (int)$operations['active_quarantine_records'],
                'pending_disposals'     => (int)$operations['pending_disposals']
            ],
            'clinical_mar' => [
                'has_data'        => ((int)$mar['total_scheduled'] > 0),
                'total_scheduled' => (int)$mar['total_scheduled'],
                'administered'    => (int)$mar['administered'],
                'missed'          => (int)$mar['missed'],
                'held'            => (int)$mar['held'],
                'refused'         => (int)$mar['refused']
            ],
            'opd' => [
                'invoices_count'        => (int)$opdData['total_invoices'],
                'gross_sales'           => (float)$opdData['gross_sales'],
                'net_sales'             => (float)$opdData['net_sales'],
                'paid'                  => (float)$opdData['paid_amount'],
                'outstanding'           => (float)$opdData['outstanding_amount'],
                'pending_bills'         => (int)$opdData['pending_bills'],
                'pending_prescriptions' => $opdPendingPrescriptions,
            ],
            'ipd' => [
                'invoices_count'        => (int)$ipdData['total_invoices'],
                'gross_sales'           => (float)$ipdData['gross_sales'],
                'net_sales'             => (float)$ipdData['net_sales'],
                'paid'                  => (float)$ipdData['paid_amount'],
                'outstanding'           => (float)$ipdData['outstanding_amount'],
                'pending_bills'         => (int)$ipdData['pending_bills'],
                'pending_indents'       => (int)($operations['pending_indents'] ?? 0),
                'pending_prescriptions' => $ipdPendingPrescriptions,
            ]
        ];
    }

    /**
     * Sales breakdown by channel (Counter, Prescription, IPD).
     */
    public function getSalesSummaryByChannel(array $dateRange): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                sale_type,
                COUNT(*) AS transaction_count,
                COALESCE(SUM(subtotal_amount), 0.00) AS gross_sales,
                COALESCE(SUM(discount_amount), 0.00) AS discount,
                COALESCE(SUM(gst_amount), 0.00) AS tax,
                COALESCE(SUM(grand_total), 0.00) AS net_sales,
                COALESCE(SUM(paid_amount), 0.00) AS paid,
                COALESCE(SUM(balance_amount), 0.00) AS outstanding
            FROM pharmacy_sales
            WHERE sale_date BETWEEN ? AND ?
              AND status != 'CANCELLED'
            GROUP BY sale_type
        ");
        $stmt->execute([$dateRange['start_date'], $dateRange['end_date']]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Daily Sales Register with pagination and filtering.
     */
    public function getDailySalesRegister(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ["s.status != 'CANCELLED'"];
        $params = [];

        if (!empty($filters['start'])) {
            $where[] = "s.sale_date >= ?";
            $params[] = $filters['start'];
        }
        if (!empty($filters['end'])) {
            $where[] = "s.sale_date <= ?";
            $params[] = $filters['end'];
        }
        if (!empty($filters['sale_type'])) {
            $where[] = "s.sale_type = ?";
            $params[] = $filters['sale_type'];
        }
        if (!empty($filters['payment_status'])) {
            $where[] = "s.payment_status = ?";
            $params[] = $filters['payment_status'];
        }
        if (!empty($filters['search'])) {
            $where[] = "(s.sale_number LIKE ? OR s.customer_name LIKE ?)";
            $term = '%' . $filters['search'] . '%';
            $params[] = $term;
            $params[] = $term;
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM pharmacy_sales s WHERE {$whereSql}");
        $countStmt->execute($params);
        $totalRows = (int)$countStmt->fetchColumn();

        $sql = "
            SELECT 
                s.sale_id,
                s.sale_number,
                s.sale_date,
                s.sale_type,
                COALESCE(s.customer_name, 'Walk-in') AS customer_patient,
                s.subtotal_amount AS gross,
                s.discount_amount AS discount,
                s.gst_amount AS tax,
                s.grand_total AS net,
                s.paid_amount AS paid,
                s.balance_amount AS outstanding,
                s.payment_mode,
                s.payment_status,
                s.status,
                u.full_name AS cashier_name
            FROM pharmacy_sales s
            LEFT JOIN pharmacy_users u ON s.created_by = u.id
            WHERE {$whereSql}
            ORDER BY s.sale_date DESC, s.sale_id DESC
            LIMIT ? OFFSET ?
        ";

        $stmt = $this->pdo->prepare($sql);
        $execParams = array_merge($params, [$limit, $offset]);
        foreach ($execParams as $idx => $val) {
            $type = is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($idx + 1, $val, $type);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'total_rows' => $totalRows,
            'limit'      => $limit,
            'offset'     => $offset,
            'rows'       => $rows
        ];
    }

    /**
     * Medicine-level Sales Analysis.
     */
    public function getMedicineSalesAnalysis(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $where = ["s.status != 'CANCELLED'"];
        $params = [];

        if (!empty($filters['start'])) {
            $where[] = "s.sale_date >= ?";
            $params[] = $filters['start'];
        }
        if (!empty($filters['end'])) {
            $where[] = "s.sale_date <= ?";
            $params[] = $filters['end'];
        }
        if (!empty($filters['category_id'])) {
            $where[] = "m.category_id = ?";
            $params[] = (int)$filters['category_id'];
        }
        if (!empty($filters['medicine_id'])) {
            $where[] = "m.medicine_id = ?";
            $params[] = (int)$filters['medicine_id'];
        }
        if (!empty($filters['sale_type'])) {
            $where[] = "s.sale_type = ?";
            $params[] = $filters['sale_type'];
        }

        $whereSql = implode(' AND ', $where);

        $sql = "
            SELECT 
                m.medicine_id,
                m.medicine_name,
                m.generic_name,
                m.category AS category_name,
                m.manufacturer,
                COUNT(DISTINCT si.sale_id) AS invoices_count,
                COALESCE(SUM(si.quantity), 0) AS total_quantity_sold,
                COALESCE(SUM(si.taxable_amount), 0.00) AS gross_sales,
                COALESCE(SUM(si.discount_amount), 0.00) AS total_discount,
                COALESCE(SUM(si.gst_amount), 0.00) AS total_tax,
                COALESCE(SUM(si.line_total), 0.00) AS net_sales
            FROM pharmacy_sale_items si
            JOIN pharmacy_sales s ON si.sale_id = s.sale_id
            JOIN medicines m ON si.medicine_id = m.medicine_id
            WHERE {$whereSql}
            GROUP BY m.medicine_id, m.medicine_name, m.generic_name, m.category, m.manufacturer
            ORDER BY net_sales DESC
            LIMIT ? OFFSET ?
        ";

        $stmt = $this->pdo->prepare($sql);
        $execParams = array_merge($params, [$limit, $offset]);
        foreach ($execParams as $idx => $val) {
            $type = is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($idx + 1, $val, $type);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Batch Sales Analysis with Authoritative Historical Margin.
     * Calculated strictly from pharmacy_sale_item_batches.unit_cost and unit_price.
     */
    public function getBatchSalesAnalysis(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $where = ["s.status != 'CANCELLED'"];
        $params = [];

        if (!empty($filters['start'])) {
            $where[] = "s.sale_date >= ?";
            $params[] = $filters['start'];
        }
        if (!empty($filters['end'])) {
            $where[] = "s.sale_date <= ?";
            $params[] = $filters['end'];
        }
        if (!empty($filters['medicine_id'])) {
            $where[] = "m.medicine_id = ?";
            $params[] = (int)$filters['medicine_id'];
        }

        $whereSql = implode(' AND ', $where);

        $sql = "
            SELECT 
                m.medicine_name,
                mb.batch_number,
                mb.expiry_date,
                COALESCE(SUM(sib.allocated_quantity), 0) AS quantity_sold,
                sib.unit_cost AS historical_unit_cost,
                COALESCE(SUM(sib.allocated_quantity * sib.unit_price), 0.00) AS total_sale_value,
                COALESCE(SUM(sib.allocated_quantity * sib.unit_cost), 0.00) AS total_cogs,
                CASE 
                    WHEN sib.unit_cost IS NULL OR sib.unit_cost <= 0 THEN 'Margin unavailable'
                    ELSE CAST(ROUND(SUM(sib.allocated_quantity * (sib.unit_price - sib.unit_cost)), 2) AS CHAR)
                END AS gross_margin,
                CASE 
                    WHEN sib.unit_cost IS NULL OR sib.unit_cost <= 0 OR SUM(sib.allocated_quantity * sib.unit_price) <= 0 THEN NULL
                    ELSE ROUND((SUM(sib.allocated_quantity * (sib.unit_price - sib.unit_cost)) / SUM(sib.allocated_quantity * sib.unit_price)) * 100, 2)
                END AS margin_percentage
            FROM pharmacy_sale_item_batches sib
            JOIN pharmacy_sale_items si ON sib.sale_item_id = si.sale_item_id
            JOIN pharmacy_sales s ON sib.sale_id = s.sale_id
            JOIN medicines m ON sib.medicine_id = m.medicine_id
            JOIN medicine_batches mb ON sib.batch_id = mb.batch_id
            WHERE {$whereSql}
            GROUP BY m.medicine_name, mb.batch_number, mb.expiry_date, sib.unit_cost
            ORDER BY quantity_sold DESC
            LIMIT ? OFFSET ?
        ";

        $stmt = $this->pdo->prepare($sql);
        $execParams = array_merge($params, [$limit, $offset]);
        foreach ($execParams as $idx => $val) {
            $type = is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($idx + 1, $val, $type);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Expiry Analytics: Bucketed into Expired, 0-30 days, 31-60 days, 61-90 days, >90 days.
     */
    public function getExpiryAnalysis(): array
    {
        $sql = "
            SELECT 
                CASE 
                    WHEN mb.expiry_date < CURDATE() THEN 'EXPIRED'
                    WHEN mb.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'DAYS_0_30'
                    WHEN mb.expiry_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 31 DAY) AND DATE_ADD(CURDATE(), INTERVAL 60 DAY) THEN 'DAYS_31_60'
                    WHEN mb.expiry_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 61 DAY) AND DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN 'DAYS_61_90'
                    ELSE 'OVER_90_DAYS'
                END AS bucket,
                COUNT(*) AS batch_count,
                COALESCE(SUM(mb.quantity_available), 0) AS total_units,
                COALESCE(SUM(mb.quantity_available * mb.purchase_price), 0.00) AS total_value
            FROM medicine_batches mb
            WHERE mb.status = 'Active' 
              AND mb.quantity_available > 0
            GROUP BY bucket
        ";
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $buckets = [
            'EXPIRED'     => ['batch_count' => 0, 'units' => 0, 'value' => 0.0],
            'DAYS_0_30'   => ['batch_count' => 0, 'units' => 0, 'value' => 0.0],
            'DAYS_31_60'  => ['batch_count' => 0, 'units' => 0, 'value' => 0.0],
            'DAYS_61_90'  => ['batch_count' => 0, 'units' => 0, 'value' => 0.0],
            'OVER_90_DAYS'=> ['batch_count' => 0, 'units' => 0, 'value' => 0.0]
        ];

        foreach ($rows as $r) {
            $b = $r['bucket'];
            if (isset($buckets[$b])) {
                $buckets[$b] = [
                    'batch_count' => (int)$r['batch_count'],
                    'units'       => (int)$r['total_units'],
                    'value'       => (float)$r['total_value']
                ];
            }
        }

        $detailStmt = $this->pdo->query("
            SELECT 
                m.medicine_id,
                m.medicine_name,
                mb.batch_number,
                mb.expiry_date,
                mb.quantity_available,
                mb.purchase_price,
                (mb.quantity_available * mb.purchase_price) AS batch_value,
                s.supplier_name,
                DATEDIFF(mb.expiry_date, CURDATE()) AS days_remaining
            FROM medicine_batches mb
            JOIN medicines m ON mb.medicine_id = m.medicine_id
            LEFT JOIN pharmacy_suppliers s ON mb.supplier_id = s.supplier_id
            WHERE mb.status = 'Active' 
              AND mb.quantity_available > 0
              AND mb.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
            ORDER BY mb.expiry_date ASC
            LIMIT 100
        ");
        $atRiskRows = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

        $quantityAtRisk = $buckets['EXPIRED']['units'] + $buckets['DAYS_0_30']['units'] + $buckets['DAYS_31_60']['units'] + $buckets['DAYS_61_90']['units'];
        $valueAtRisk = $buckets['EXPIRED']['value'] + $buckets['DAYS_0_30']['value'] + $buckets['DAYS_31_60']['value'] + $buckets['DAYS_61_90']['value'];

        return [
            'summary'          => $buckets,
            'quantity_at_risk' => $quantityAtRisk,
            'value_at_risk'    => $valueAtRisk,
            'at_risk_batches'  => $atRiskRows
        ];
    }

    /**
     * Dead / Slow Moving Stock:
     */
    public function getSlowMovingStock(int $daysThreshold = 90): array
    {
        $sql = "
            SELECT 
                m.medicine_id,
                m.medicine_name,
                m.stock_quantity,
                MAX(s.sale_date) AS last_sale_date,
                DATEDIFF(NOW(), COALESCE(MAX(s.sale_date), m.created_at)) AS days_inactive,
                (SELECT COALESCE(SUM(mb.quantity_available * mb.purchase_price), 0.00) 
                 FROM medicine_batches mb 
                 WHERE mb.medicine_id = m.medicine_id AND mb.status = 'Active') AS inventory_value
            FROM medicines m
            LEFT JOIN pharmacy_sale_items si ON m.medicine_id = si.medicine_id
            LEFT JOIN pharmacy_sales s ON si.sale_id = s.sale_id AND s.status != 'CANCELLED'
            WHERE (m.status = 'Active' OR m.status IS NULL)
              AND m.deleted_at IS NULL
              AND m.stock_quantity > 0
            GROUP BY m.medicine_id, m.medicine_name, m.stock_quantity, m.created_at
            HAVING days_inactive >= ?
            ORDER BY days_inactive DESC, inventory_value DESC
            LIMIT 100
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$daysThreshold]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fast Moving Medicines (Velocity Analysis).
     */
    public function getFastMovingMedicines(array $dateRange, int $limit = 20): array
    {
        $sql = "
            SELECT 
                m.medicine_id,
                m.medicine_name,
                m.generic_name,
                COALESCE(SUM(si.quantity), 0) AS units_sold,
                COUNT(DISTINCT s.sale_id) AS transaction_count,
                COALESCE(SUM(si.line_total), 0.00) AS total_revenue
            FROM pharmacy_sale_items si
            JOIN pharmacy_sales s ON si.sale_id = s.sale_id
            JOIN medicines m ON si.medicine_id = m.medicine_id
            WHERE s.sale_date BETWEEN ? AND ?
              AND s.status != 'CANCELLED'
            GROUP BY m.medicine_id, m.medicine_name, m.generic_name
            ORDER BY units_sold DESC
            LIMIT ?
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(1, $dateRange['start_date'], PDO::PARAM_STR);
        $stmt->bindValue(2, $dateRange['end_date'], PDO::PARAM_STR);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * ABC Analysis of Inventory Value.
     */
    public function getAbcAnalysis(): array
    {
        $sql = "
            SELECT 
                m.medicine_id,
                m.medicine_name,
                m.stock_quantity,
                COALESCE(SUM(mb.quantity_available * mb.purchase_price), 0.00) AS total_val
            FROM medicines m
            JOIN medicine_batches mb ON m.medicine_id = mb.medicine_id
            WHERE mb.status = 'Active' AND mb.quantity_available > 0
            GROUP BY m.medicine_id, m.medicine_name, m.stock_quantity
            HAVING total_val > 0
            ORDER BY total_val DESC
        ";

        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $totalSystemValue = 0.0;
        foreach ($rows as $r) {
            $totalSystemValue += (float)$r['total_val'];
        }

        if ($totalSystemValue <= 0) {
            return [
                'status' => 'INSUFFICIENT_DATA',
                'reason' => 'Total active inventory valuation is zero.',
                'items'  => []
            ];
        }

        $cumulative = 0.0;
        $classified = [];
        $counts = ['A' => 0, 'B' => 0, 'C' => 0];

        foreach ($rows as $item) {
            $val = (float)$item['total_val'];
            $cumulative += $val;
            $cumPercent = ($cumulative / $totalSystemValue) * 100;

            if ($cumPercent <= 70.0) {
                $category = 'A';
            } elseif ($cumPercent <= 90.0) {
                $category = 'B';
            } else {
                $category = 'C';
            }

            $counts[$category]++;
            $classified[] = [
                'medicine_id'        => (int)$item['medicine_id'],
                'medicine_name'      => $item['medicine_name'],
                'stock_quantity'     => (int)$item['stock_quantity'],
                'value'              => $val,
                'percentage_of_total'=> round(($val / $totalSystemValue) * 100, 2),
                'cumulative_percent' => round($cumPercent, 2),
                'abc_class'          => $category
            ];
        }

        return [
            'status'             => 'OK',
            'total_system_value' => $totalSystemValue,
            'summary'            => $counts,
            'items'              => $classified
        ];
    }

    /**
     * Inventory Turnover Ratio.
     */
    public function getInventoryTurnover(array $dateRange): array
    {
        $cogsStmt = $this->pdo->prepare("
            SELECT COALESCE(SUM(sib.allocated_quantity * sib.unit_cost), 0.00) AS period_cogs
            FROM pharmacy_sale_item_batches sib
            JOIN pharmacy_sales s ON sib.sale_id = s.sale_id
            WHERE s.sale_date BETWEEN ? AND ?
              AND s.status != 'CANCELLED'
              AND sib.unit_cost > 0
        ");
        $cogsStmt->execute([$dateRange['start_date'], $dateRange['end_date']]);
        $cogs = (float)$cogsStmt->fetchColumn();

        $invStmt = $this->pdo->query("
            SELECT COALESCE(SUM(quantity_available * purchase_price), 0.00)
            FROM medicine_batches
            WHERE status = 'Active' AND quantity_available > 0
        ");
        $currentInvVal = (float)$invStmt->fetchColumn();

        if ($currentInvVal <= 0 || $cogs <= 0) {
            return [
                'status'         => 'INSUFFICIENT_DATA',
                'message'        => 'Reliable opening/closing stock balance snapshots or non-zero COGS not recorded for this window.',
                'cogs'           => $cogs,
                'turnover_ratio' => null
            ];
        }

        $turnover = round($cogs / $currentInvVal, 2);

        return [
            'status'            => 'OK',
            'cogs'              => $cogs,
            'current_stock_val' => $currentInvVal,
            'turnover_ratio'    => $turnover
        ];
    }

    /**
     * Supplier Performance and Turnaround Analytics.
     */
    public function getSupplierPerformance(): array
    {
        $sql = "
            SELECT 
                s.supplier_id,
                s.supplier_name,
                s.phone,
                COUNT(DISTINCT po.po_id) AS total_pos,
                COUNT(DISTINCT g.grn_id) AS total_grns,
                COALESCE(SUM(pi.grand_total), 0.00) AS total_purchase_value,
                COALESCE(SUM(pi.amount_paid), 0.00) AS total_paid,
                COALESCE(SUM(pi.outstanding_amount), 0.00) AS total_outstanding,
                (SELECT COALESCE(SUM(pr.refund_amount), 0.00) 
                 FROM pharmacy_purchase_returns pr 
                 WHERE pr.supplier_id = s.supplier_id AND pr.status != 'CANCELLED') AS total_returns,
                AVG(DATEDIFF(g.grn_date, po.po_date)) AS avg_delivery_days
            FROM pharmacy_suppliers s
            LEFT JOIN pharmacy_purchase_orders po ON s.supplier_id = po.supplier_id
            LEFT JOIN pharmacy_grn g ON po.po_id = g.po_id
            LEFT JOIN pharmacy_purchase_invoices pi ON s.supplier_id = pi.supplier_id AND pi.payment_status != 'CANCELLED'
            GROUP BY s.supplier_id, s.supplier_name, s.phone
            ORDER BY total_purchase_value DESC
        ";

        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($rows as $r) {
            $deliveryTime = $r['avg_delivery_days'] !== null ? round((float)$r['avg_delivery_days'], 1) . ' days' : 'No PO-GRN pairs';
            $results[] = [
                'supplier_id'      => (int)$r['supplier_id'],
                'supplier_name'    => $r['supplier_name'],
                'phone'            => $r['phone'],
                'total_pos'        => (int)$r['total_pos'],
                'total_grns'       => (int)$r['total_grns'],
                'purchase_value'   => (float)$r['total_purchase_value'],
                'paid'             => (float)$r['total_paid'],
                'outstanding'      => (float)$r['total_outstanding'],
                'returns'          => (float)$r['total_returns'],
                'avg_delivery_time'=> $deliveryTime
            ];
        }

        return $results;
    }
}
