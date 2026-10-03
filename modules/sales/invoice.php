<?php
// modules/sales/invoice.php - Dual-Format GST Tax Invoice & Inpatient Bill of Supply (With Post-Confirmation Full Editing)

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/SalesService.php';

use Pharmacy\Services\SalesService;

$salesService = new SalesService($pdo);

// -----------------------------------------------------------------------------
// AJAX POST HANDLER: SAVE EDITED INVOICE DETAILS TO DATABASE
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_invoice_edits') {
    header('Content-Type: application/json');
    $saleId = (int)($_POST['sale_id'] ?? 0);
    if ($saleId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid sale ID specified.']);
        exit;
    }

    try {
        $patientName = trim($_POST['patient_name'] ?? '');
        $patientAddress = trim($_POST['patient_address'] ?? 'Kharadi, Pune');
        $doctorName = trim($_POST['doctor_name'] ?? 'Dr. Duty Doctor');
        $doctorAddress = trim($_POST['doctor_address'] ?? 'Vatsalya Hospital');
        $hospitalUhid = trim($_POST['hospital_uhid'] ?? '');
        $ipdWard = trim($_POST['ipd_ward'] ?? '');
        $ipdBed = trim($_POST['ipd_bed'] ?? '');
        $saleDateRaw = trim($_POST['sale_date'] ?? date('Y-m-d'));
        $saleDate = date('Y-m-d', strtotime($saleDateRaw));

        $discountAmount = (float)($_POST['discount_amount'] ?? 0.0);
        $totalGross = (float)($_POST['total_gross'] ?? 0.0);
        $totalGst = (float)($_POST['total_gst'] ?? 0.0);
        $grandTotal = (float)($_POST['grand_total'] ?? 0.0);
        if ($grandTotal <= 0.0) {
            $grandTotal = max(0.0, $totalGross + $totalGst - $discountAmount);
        }

        $itemsJson = $_POST['items_json'] ?? '[]';
        $items = json_decode($itemsJson, true) ?: [];

        $pdo->beginTransaction();

        // 1. Update pharmacy_sales main record
        $updSale = $pdo->prepare("
            UPDATE pharmacy_sales SET
                customer_name = ?,
                doctor_name = ?,
                ipd_ward = ?,
                ipd_bed = ?,
                subtotal_amount = ?,
                taxable_amount = ?,
                discount_amount = ?,
                gst_amount = ?,
                grand_total = ?,
                paid_amount = ?,
                balance_amount = 0.00,
                sale_date = ?,
                updated_at = NOW()
            WHERE sale_id = ?
        ");
        $updSale->execute([
            $patientName,
            $doctorName,
            $ipdWard,
            $ipdBed,
            $totalGross,
            $totalGross,
            $discountAmount,
            $totalGst,
            $grandTotal,
            $grandTotal,
            $saleDate,
            $saleId
        ]);

        // 2. Optionally update pharmacy_patients if linked
        if (!empty($hospitalUhid)) {
            $updPat = $pdo->prepare("UPDATE pharmacy_patients SET name = ?, updated_at = NOW() WHERE hospital_uhid = ?");
            $updPat->execute([$patientName, $hospitalUhid]);
        }

        // 3. Update sale items if array provided
        if (!empty($items)) {
            // Delete current items & batch records for this sale
            $delBatches = $pdo->prepare("
                DELETE b FROM pharmacy_sale_item_batches b
                JOIN pharmacy_sale_items i ON b.sale_item_id = i.sale_item_id
                WHERE i.sale_id = ?
            ");
            $delBatches->execute([$saleId]);

            $delItems = $pdo->prepare("DELETE FROM pharmacy_sale_items WHERE sale_id = ?");
            $delItems->execute([$saleId]);

            $insItem = $pdo->prepare("
                INSERT INTO pharmacy_sale_items (
                    sale_id, medicine_id, dosage_form, pack_size, quantity,
                    unit_price, mrp, discount_percent, discount_amount, taxable_amount,
                    gst_percent, gst_amount, line_total, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, 0.0, 0.0, ?,
                    ?, ?, ?, NOW()
                )
            ");

            $insBatch = $pdo->prepare("
                INSERT INTO pharmacy_sale_item_batches (
                    sale_item_id, batch_id, allocated_quantity, unit_price, gst_amount, line_total, created_at
                ) VALUES (
                    ?, 0, ?, ?, ?, ?, NOW()
                )
            ");

            foreach ($items as $it) {
                $medName = trim($it['description'] ?? 'Medicine');
                
                // Find or match medicine_id
                $medStmt = $pdo->prepare("SELECT medicine_id, dosage_form, pack_size, hsn_code FROM medicines WHERE medicine_name LIKE ? LIMIT 1");
                $medStmt->execute(['%' . $medName . '%']);
                $medRow = $medStmt->fetch(PDO::FETCH_ASSOC);

                $medId = $medRow ? (int)$medRow['medicine_id'] : 1;
                $dosageForm = trim($it['comp'] ?? ($medRow['dosage_form'] ?? 'Tablet'));
                $packSize = trim($it['pack'] ?? ($medRow['pack_size'] ?? '10 Tablets'));

                $qty = max(1, (int)($it['qty'] ?? 1));
                $rate = (float)($it['rate'] ?? 0.0);
                $mrp = (float)($it['mrp'] ?? $rate);
                
                $sgstPct = (float)($it['sgst_pct'] ?? 6.0);
                $cgstPct = (float)($it['cgst_pct'] ?? 6.0);
                $gstPct = $sgstPct + $cgstPct;

                $lineSub = round($qty * $rate, 2);
                $lineGst = round($lineSub * ($gstPct / 100.0), 2);
                $lineTot = $lineSub;

                $insItem->execute([
                    $saleId,
                    $medId,
                    $dosageForm,
                    $packSize,
                    $qty,
                    $rate,
                    $mrp,
                    $lineSub,
                    $gstPct,
                    $lineGst,
                    $lineTot
                ]);
                $newItemId = (int)$pdo->lastInsertId();

                $insBatch->execute([
                    $newItemId,
                    $qty,
                    $rate,
                    $lineGst,
                    $lineTot
                ]);
            }
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Invoice #' . $saleId . ' details updated successfully!'
        ]);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update invoice: ' . $e->getMessage()
        ]);
        exit;
    }
}

// -----------------------------------------------------------------------------
// GET REQUEST: FETCH SALE DATA & RENDER INVOICE
// -----------------------------------------------------------------------------
$saleId = (int)($_GET['id'] ?? $_GET['print_id'] ?? $_GET['sale_id'] ?? 0);

if ($saleId <= 0) {
    http_response_code(400);
    die("<h1>Invalid Request</h1><p>No valid Sale ID specified for invoice printing.</p>");
}

$sale = $salesService->getSale($saleId);
if (!$sale) {
    http_response_code(404);
    die("<h1>Invoice Not Found</h1><p>Sale #{$saleId} could not be found in the database.</p>");
}

// Determine if this is an IPD sale
$isIpdSale = ($sale['sale_type'] === 'IPD_SALE' || !empty($sale['ipd_admission_id']) || !empty($sale['ipd_ward']));

// Format selector: 'standard' (OPD retail GST invoice) or 'ipd_detailed' (Hospital Inpatient Bill of Supply)
$format = $_GET['format'] ?? ($isIpdSale && isset($_GET['ipd']) ? 'ipd_detailed' : 'standard');
if ($format !== 'ipd_detailed') {
    $format = 'standard';
}

// Pharmacy & Hospital Master Information
$hospitalName = "VATSALYA HOSPITAL KHARADI-PUNE";
$hospitalAddress = "22, 2A, Mundhwa - Kharadi Rd, near Galaxy Pathare Plaza, Kharadi, Pune-411014";
$hospitalCin = "U85110KA2003PTC033055";
$hospitalGstin = "27AAQFV6256M1Z8";
$hospitalPan = "AACCC2943F1";

$shopName = "VATSALYA MEDICAL";
$shopAddress1 = "1ST FLR, VATSALYA HOSPITAL, GALAXY PATHARE PLAZA,";
$shopAddress2 = "SAINATH NAGAR, KHARADI, PUNE-411014 Galaxy Pathare";
$dlNumber = "20-276335,21-276336-MH-PZ1";
$gstNumber = "27AAQFV6256M1Z8";

// Patient & Doctor Information
$patientName = !empty($sale['customer_name']) ? $sale['customer_name'] : 'Walk-in Customer';
$patientAddress = !empty($sale['patient_address']) ? $sale['patient_address'] : 'SR NO -54/1/8/ KIRTANE BAG ROAD, MUNDHWA PUNE 36, PUNE';
$doctorName = !empty($sale['doctor_name']) ? $sale['doctor_name'] : 'DR. VAISHALI LONDHE';
$doctorAddress = "VATSALYA HOSPITAL";
$cashierName = !empty($sale['cashier_name']) ? $sale['cashier_name'] : 'Administrator';

// Format date & bill number
$rawSaleDate = $sale['sale_date'] ?: $sale['created_at'];
$billDate = date('d-M-Y', strtotime($rawSaleDate));
$billDateYmd = date('Y-m-d', strtotime($rawSaleDate));
$billDateTime = date('d/m/Y : h:iA', strtotime($sale['created_at'] ?: $sale['sale_date']));
$billNo = $sale['sale_number'];
$regNo = !empty($sale['hospital_uhid']) ? $sale['hospital_uhid'] : (!empty($sale['patient_id']) ? 'VH' . $sale['patient_id'] : 'VH' . rand(1000, 9999));
$ipdNo = !empty($sale['ipd_admission_id']) ? $sale['ipd_admission_id'] : ('IPD/' . date('Y') . '/' . strtoupper(date('M')) . '/' . str_pad((string)$sale['sale_id'], 3, '0', STR_PAD_LEFT));
$wardName = !empty($sale['ipd_ward']) ? $sale['ipd_ward'] : 'Twin Sharing Room';
$bedNo = !empty($sale['ipd_bed']) ? $sale['ipd_bed'] : '105-1';
$payorName = ($sale['payment_mode'] === 'CREDIT') ? 'Hospital IPD Credit (Charge to Inpatient Account)' : ($sale['payment_mode'] . ' Settlement');

// Helper to abbreviate company/manufacturer name
function getCompAbbr($mfg, $medName) {
    $mfg = trim((string)$mfg);
    if (!empty($mfg)) {
        $clean = preg_replace('/[^a-zA-Z0-9\s]/', '', $mfg);
        $words = preg_split('/\s+/', $clean);
        if (count($words) >= 1 && strlen($words[0]) >= 3) {
            return strtoupper(substr($words[0], 0, 5));
        }
    }
    $mClean = preg_replace('/[^a-zA-Z0-9\s]/', '', $medName);
    $mWords = preg_split('/\s+/', $mClean);
    return strtoupper(substr($mWords[0] ?? 'GEN', 0, 5));
}

// Helper for pack formatting
function formatPack($packSize, $dosageForm, $unit) {
    if (!empty($packSize) && strlen($packSize) <= 12) {
        return strtoupper($packSize);
    }
    if (!empty($dosageForm)) {
        return strtoupper(substr($dosageForm, 0, 3) . (strpos(strtolower($dosageForm), 'syrup') !== false ? ' 100M' : ' 1 STR'));
    }
    return strtoupper($unit ?: '10 TABLETS');
}

// Flatten item allocations for itemized billing rows
$flatRows = [];
$ipdItems = [];
$gstSlabs = [];
$totalGross = 0.0;
$totalDisc = (float)($sale['discount_amount'] ?? 0.0);
$totalGst = (float)($sale['gst_amount'] ?? 0.0);
$grandTotal = (float)($sale['grand_total'] ?? 0.0);
$paidAmount = (float)($sale['paid_amount'] ?? 0.0);
$balanceDue = (float)($sale['balance_amount'] ?? 0.0);

foreach ($sale['items'] as $item) {
    $medName = $item['medicine_name'];
    $hsn = $item['hsn_code'] ?: '30049099';
    $pack = formatPack($item['pack_size'] ?? $item['med_pack_size'] ?? '', $item['dosage_form'] ?? $item['med_dosage_form'] ?? '', $item['med_unit'] ?? '');
    $comp = getCompAbbr($item['manufacturer'] ?? '', $medName);
    $gstPercent = (float)($item['gst_percent'] ?? 12.0);
    $sgstPercent = $gstPercent / 2.0;
    $cgstPercent = $gstPercent / 2.0;

    if (!empty($item['batches'])) {
        foreach ($item['batches'] as $b) {
            $qty = (int)$b['allocated_quantity'];
            $rate = (float)$b['unit_price'];
            $mrp = (float)(!empty($b['batch_mrp']) ? $b['batch_mrp'] : (!empty($item['med_mrp']) ? $item['med_mrp'] : $rate));
            $batchNo = $b['batch_number'] ?: 'ALG-3420';
            $expDate = !empty($b['expiry_date']) ? date('m/y', strtotime($b['expiry_date'])) : '09/27';
            
            $lineSubtotal = round($qty * $rate, 2);
            $totalGross += $lineSubtotal;

            $lineGst = round($lineSubtotal * ($gstPercent / 100.0), 2);
            $sgstAmt = round($lineGst / 2.0, 2);
            $cgstAmt = round($lineGst - $sgstAmt, 2);

            $lineAmount = $lineSubtotal;

            $slabKey = number_format($gstPercent, 0) . '% GST';
            if (!isset($gstSlabs[$slabKey])) {
                $gstSlabs[$slabKey] = 0.0;
            }
            $gstSlabs[$slabKey] += $lineGst;

            $flatRows[] = [
                'medicine_id' => $item['medicine_id'] ?? 0,
                'qty'         => $qty,
                'pack'        => $pack,
                'comp'        => $comp,
                'description' => strtoupper($medName),
                'batch'       => strtoupper($batchNo),
                'exp'         => $expDate,
                'mrp'         => $mrp,
                'rate'        => $rate,
                'hsn'         => $hsn,
                'sgst_pct'    => number_format($sgstPercent, 2),
                'sgst_amt'    => number_format($sgstAmt, 2),
                'cgst_pct'    => number_format($cgstPercent, 2),
                'cgst_amt'    => number_format($cgstAmt, 2),
                'amount'      => number_format($lineAmount, 2)
            ];

            $ipdItems[] = [
                'medicine_id' => $item['medicine_id'] ?? 0,
                'name'        => strtoupper($medName),
                'details'     => ($item['generic_name'] ? "({$item['generic_name']})" : "") . ($item['manufacturer'] ? ", " . strtoupper($item['manufacturer']) : ""),
                'batch'       => $batchNo,
                'qty'         => number_format($qty, 2),
                'price'       => number_format($rate, 2),
                'net_amount'  => number_format($lineSubtotal, 2)
            ];
        }
    } else {
        $qty = (int)$item['quantity'];
        $rate = (float)$item['unit_price'];
        $mrp = (float)(!empty($item['med_mrp']) ? $item['med_mrp'] : $rate);
        $batchNo = 'ALG-3420';
        $expDate = '09/27';

        $lineSubtotal = round($qty * $rate, 2);
        $totalGross += $lineSubtotal;

        $lineGst = (float)$item['gst_amount'] ?: round($lineSubtotal * ($gstPercent / 100.0), 2);
        $sgstAmt = round($lineGst / 2.0, 2);
        $cgstAmt = round($lineGst - $sgstAmt, 2);
        $lineAmount = (float)$item['line_total'] ?: $lineSubtotal;

        $slabKey = number_format($gstPercent, 0) . '% GST';
        if (!isset($gstSlabs[$slabKey])) {
            $gstSlabs[$slabKey] = 0.0;
        }
        $gstSlabs[$slabKey] += $lineGst;

        $flatRows[] = [
            'medicine_id' => $item['medicine_id'] ?? 0,
            'qty'         => $qty,
            'pack'        => $pack,
            'comp'        => $comp,
            'description' => strtoupper($medName),
            'batch'       => $batchNo,
            'exp'         => $expDate,
            'mrp'         => $mrp,
            'rate'        => $rate,
            'hsn'         => $hsn,
            'sgst_pct'    => number_format($sgstPercent, 2),
            'sgst_amt'    => number_format($sgstAmt, 2),
            'cgst_pct'    => number_format($cgstPercent, 2),
            'cgst_amt'    => number_format($cgstAmt, 2),
            'amount'      => number_format($lineAmount, 2)
        ];

        $ipdItems[] = [
            'medicine_id' => $item['medicine_id'] ?? 0,
            'name'        => strtoupper($medName),
            'details'     => ($item['generic_name'] ? "({$item['generic_name']})" : ""),
            'batch'       => $batchNo,
            'qty'         => number_format($qty, 2),
            'price'       => number_format($rate, 2),
            'net_amount'  => number_format($lineSubtotal, 2)
        ];
    }
}

if ($totalGross <= 0.0) {
    $totalGross = $grandTotal;
}
if ($totalGst <= 0.0) {
    $totalGst = round($totalGross * 0.12, 2);
}
if ($grandTotal <= 0.0) {
    $grandTotal = round($totalGross + $totalGst - $totalDisc, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $format === 'ipd_detailed' ? 'Inpatient Bill of Supply' : 'GST Tax Invoice' ?> - <?= htmlspecialchars($billNo) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: Arial, "Helvetica Neue", Helvetica, sans-serif;
            font-size: 11px;
            color: #000000;
            background-color: #525659;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 20px 10px;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* Utility classes */
        .d-none {
            display: none !important;
        }
        .d-inline-flex {
            display: inline-flex !important;
        }
        .d-flex {
            display: flex !important;
        }

        /* Top Action Bar (Hidden on Print) */
        .no-print-bar {
            width: 100%;
            max-width: 1040px;
            margin-bottom: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #ffffff;
            padding: 10px 18px;
            border-radius: 8px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.2);
            gap: 10px;
        }
        .no-print-bar .title {
            font-size: 13.5px;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }
        .no-print-bar .title .badge-bill {
            background: #f1f5f9;
            color: #0f172a;
            border: 1px solid #cbd5e1;
            padding: 2px 8px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 12px;
            font-weight: 700;
        }
        .btn-group {
            display: inline-flex;
            gap: 6px;
            align-items: center;
            flex-wrap: nowrap;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            height: 34px;
            padding: 0 14px;
            font-size: 12px;
            font-weight: 600;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #334155;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
            transition: all 0.15s ease;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .btn:hover {
            background: #f1f5f9;
            color: #0f172a;
        }
        .btn-primary {
            background: #0284c7;
            color: #ffffff;
            border-color: #0284c7;
        }
        .btn-primary:hover {
            background: #0369a1;
            border-color: #0369a1;
            color: #ffffff;
        }
        .btn-success {
            background: #16a34a;
            color: #ffffff;
            border-color: #16a34a;
        }
        .btn-success:hover {
            background: #15803d;
            border-color: #15803d;
            color: #ffffff;
        }
        .btn-warning {
            background: #d97706;
            color: #ffffff;
            border-color: #d97706;
        }
        .btn-warning:hover {
            background: #b45309;
            border-color: #b45309;
            color: #ffffff;
        }
        .btn-secondary {
            background: #f8fafc;
            color: #475569;
            border-color: #cbd5e1;
        }
        .btn-secondary:hover {
            background: #e2e8f0;
            color: #1e293b;
        }
        .btn-active-toggle {
            background: #0f172a !important;
            color: #ffffff !important;
            border-color: #0f172a !important;
        }

        /* Notification Banner */
        #editNoticeBanner {
            width: 100%;
            max-width: 1040px;
            background: #fefce8;
            color: #854d0e;
            border: 1px solid #fef08a;
            border-radius: 8px;
            padding: 10px 16px;
            margin-bottom: 14px;
            font-size: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        /* Format 1: Standard GST Tax Invoice Styles */
        .invoice-sheet {
            width: 100%;
            max-width: 1040px;
            background-color: #ffffff;
            padding: 16px 20px;
            border: 1.5px solid #000000;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            color: #000000;
            position: relative;
        }
        .invoice-title {
            text-align: center;
            font-size: 13.5px;
            font-weight: 800;
            letter-spacing: 0.5px;
            padding-bottom: 6px;
            margin-bottom: 6px;
            border-bottom: 1.5px solid #000000;
            text-transform: uppercase;
        }
        .header-grid {
            display: grid;
            grid-template-columns: 42% 23% 35%;
            border: 1px solid #000000;
            margin-bottom: 6px;
            font-size: 12px;
            line-height: 1.45;
        }
        .header-box {
            padding: 6px 9px;
        }
        .header-box:not(:last-child) {
            border-right: 1px solid #000000;
        }
        .shop-name {
            font-size: 15px;
            font-weight: 800;
            letter-spacing: 0.3px;
            margin-bottom: 3px;
        }
        .header-line {
            display: flex;
            margin-bottom: 2px;
            align-items: baseline;
        }
        .header-label {
            font-weight: 700;
            white-space: nowrap;
            min-width: 85px;
        }
        .header-val {
            font-weight: 500;
            word-break: break-word;
            flex-grow: 1;
        }
        .table-container {
            width: 100%;
            min-height: 330px;
            margin-bottom: 6px;
        }
        .invoice-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11.5px;
            table-layout: fixed;
        }
        .invoice-table th {
            border: 1px solid #000000;
            padding: 5px 3px;
            font-weight: 700;
            text-align: center;
            background: #ffffff;
            text-transform: uppercase;
            font-size: 11px;
        }
        .invoice-table td {
            border-left: 1px solid #000000;
            border-right: 1px solid #000000;
            border-bottom: none;
            border-top: none;
            padding: 3px 2px;
            font-size: 11px;
            line-height: 1.3;
            vertical-align: middle;
            box-sizing: border-box;
        }
        .invoice-table tr.item-row td {
            height: 24px;
        }
        .invoice-table tr.blank-row td {
            height: 22px;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .fw-bold { font-weight: 700; }

        .footer-grid {
            display: grid;
            grid-template-columns: 33% 37% 30%;
            border: 1px solid #000000;
            font-size: 12px;
            min-height: 100px;
        }
        .footer-box {
            padding: 6px 9px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .footer-box:not(:last-child) {
            border-right: 1px solid #000000;
        }
        .footer-box-left {
            font-size: 11.5px;
            line-height: 1.45;
        }
        .gst-slab-line {
            font-weight: 700;
            margin-bottom: 3px;
        }
        .jurisdiction {
            font-size: 10px;
            font-weight: 600;
            color: #222;
            margin-top: auto;
            border-top: 1px dashed #777;
            padding-top: 4px;
        }
        .footer-box-center {
            text-align: center;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            align-items: center;
        }
        .wish-text {
            font-weight: 800;
            font-size: 12.5px;
            letter-spacing: 0.5px;
        }
        .shop-sign-for {
            font-size: 10.5px;
            font-weight: 600;
            margin-top: 4px;
        }
        .sign-placeholder {
            height: 35px;
        }
        .sign-caption {
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        .footer-box-right {
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            font-size: 12px;
        }
        .summary-line {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3px;
            line-height: 1.4;
        }
        .summary-label {
            font-weight: 600;
        }
        .summary-val {
            font-weight: 700;
            font-family: inherit;
        }
        .page-no {
            margin-top: auto;
            text-align: right;
            font-size: 10px;
            color: #333;
        }

        /* Format 2: Inpatient Bill of Supply (PDF Style) */
        .ipd-pdf-sheet {
            width: 100%;
            max-width: 1040px;
            background-color: #ffffff;
            padding: 28px 36px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            color: #000000;
            font-family: Arial, "Helvetica Neue", Helvetica, sans-serif;
        }
        .ipd-hospital-title {
            text-align: center;
            font-size: 18px;
            font-weight: 800;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
        }
        .ipd-address-line {
            text-align: center;
            font-size: 12px;
            color: #333;
            line-height: 1.4;
        }
        .ipd-date-line {
            text-align: right;
            font-size: 12.5px;
            font-weight: 700;
            margin-top: 8px;
        }
        .ipd-divider {
            border-bottom: 1.5px solid #000000;
            margin: 8px 0;
        }
        .ipd-banner-title {
            text-align: center;
            font-size: 15px;
            font-weight: 800;
            letter-spacing: 0.5px;
            margin: 10px 0;
        }
        .ipd-bill-meta-row {
            display: flex;
            justify-content: space-between;
            font-weight: 700;
            font-size: 13px;
            margin-bottom: 4px;
        }
        .ipd-meta-grid {
            display: grid;
            grid-template-columns: 55% 45%;
            column-gap: 20px;
            row-gap: 4px;
            font-size: 12.5px;
            margin: 8px 0;
        }
        .ipd-meta-item {
            display: flex;
        }
        .ipd-meta-lbl {
            width: 140px;
            font-weight: 700;
            flex-shrink: 0;
        }
        .ipd-meta-val {
            font-weight: normal;
            word-break: break-word;
        }
        .ipd-table-header {
            display: flex;
            font-weight: 700;
            padding: 6px 0;
            border-top: 1.5px dashed #000000;
            border-bottom: 1.5px dashed #000000;
            margin: 8px 0 10px 0;
            font-size: 13px;
        }
        .ipd-col-idx { width: 4%; text-align: left; }
        .ipd-col-item { width: 56%; text-align: left; padding-right: 12px; }
        .ipd-col-qty { width: 10%; text-align: right; padding-right: 14px; }
        .ipd-col-price { width: 14%; text-align: right; padding-right: 14px; }
        .ipd-col-net { width: 16%; text-align: right; }

        .ipd-section-heading {
            font-weight: 700;
            margin: 10px 0 6px 0;
            font-size: 13px;
        }
        .ipd-item-row {
            margin-bottom: 10px;
        }
        .ipd-item-line {
            display: flex;
            align-items: flex-start;
            font-size: 12.5px;
        }
        .ipd-sub-info {
            padding-left: 4%;
            font-size: 11.5px;
            color: #222;
        }
        .ipd-subtotal-row {
            display: flex;
            justify-content: flex-end;
            margin: 10px 0 14px 0;
            border-top: 1px dashed #777;
            padding-top: 6px;
        }
        .ipd-subtotal-label {
            font-weight: 700;
            margin-right: 32px;
            font-size: 13px;
        }
        .ipd-subtotal-val {
            font-weight: 700;
            width: 140px;
            text-align: right;
            font-size: 13px;
        }

        .ipd-summary-block {
            border-top: 1.5px dashed #000000;
            border-bottom: 1.5px dashed #000000;
            padding: 8px 0;
            margin: 14px 0;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            font-size: 13px;
        }
        .ipd-summary-row {
            display: flex;
            width: 320px;
            justify-content: space-between;
            margin-bottom: 3px;
        }
        .ipd-summary-lbl {
            font-weight: 700;
        }
        .ipd-summary-val {
            font-weight: 700;
        }

        .ipd-footer-sign {
            margin-top: 28px;
            font-size: 12px;
            font-weight: 700;
        }
        .ipd-page-foot {
            text-align: center;
            font-size: 11.5px;
            margin-top: 24px;
        }

        /* ----------------------------------------------------------------- */
        /* LIVE EDITABLE MODE STYLING                                        */
        /* ----------------------------------------------------------------- */
        .editable-field {
            display: inline-block;
            transition: all 0.15s ease;
        }
        .edit-mode-active .editable-field {
            background-color: rgba(2, 132, 199, 0.10) !important;
            border: none !important;
            border-radius: 3px !important;
            padding: 1px 4px !important;
            cursor: text !important;
            outline: none !important;
            min-width: 24px !important;
        }
        .edit-mode-active .editable-field:focus {
            background-color: rgba(2, 132, 199, 0.22) !important;
            outline: none !important;
            box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.25) !important;
        }

        .edit-ui-control {
            display: none;
        }
        .edit-mode-active .edit-ui-control {
            display: inline-flex !important;
            align-items: center;
            justify-content: center;
        }

        .btn-del-row {
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fca5a5;
            border-radius: 4px;
            cursor: pointer;
            font-size: 11px;
            padding: 2px 5px;
            line-height: 1;
        }
        .btn-del-row:hover {
            background: #ef4444;
            color: #ffffff;
        }

        .btn-add-item-row {
            border: 1px dashed #0284c7;
            background: #f0f9ff;
            color: #0284c7;
            padding: 5px 12px;
            font-size: 11.5px;
            font-weight: 700;
            border-radius: 5px;
            cursor: pointer;
            margin: 6px 0;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .btn-add-item-row:hover {
            background: #0284c7;
            color: #ffffff;
        }

        /* Print Media Styles */
        @media print {
            @page {
                size: A4 portrait;
                margin: 5mm;
            }
            html, body {
                background: #ffffff !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                min-height: auto !important;
                display: block !important;
                font-size: 10px !important;
            }
            .no-print-bar, #editNoticeBanner, .edit-ui-control {
                display: none !important;
            }
            .editable-field {
                background: transparent !important;
                border: none !important;
                padding: 0 !important;
                box-shadow: none !important;
            }
            .invoice-sheet, .ipd-pdf-sheet {
                max-width: 100% !important;
                width: 100% !important;
                margin: 0 auto !important;
                padding: 4mm !important;
                box-shadow: none !important;
                page-break-after: avoid;
            }
            .invoice-sheet {
                border: 1px solid #000000 !important;
            }
            .header-grid {
                font-size: 9.5px !important;
                line-height: 1.3 !important;
                margin-bottom: 3px !important;
            }
            .header-box {
                padding: 3px 5px !important;
            }
            .shop-name {
                font-size: 12px !important;
            }
            .table-container {
                min-height: 240px !important;
                margin-bottom: 3px !important;
            }
            .invoice-table {
                font-size: 9.5px !important;
            }
            .invoice-table th {
                padding: 2.5px 2px !important;
                font-size: 9px !important;
            }
            .invoice-table td {
                padding: 2px 3px !important;
                font-size: 9.5px !important;
            }
            .invoice-table tr.item-row td {
                height: 16px !important;
            }
            .invoice-table tr.blank-row td {
                height: 14px !important;
            }
            .footer-grid {
                font-size: 9.5px !important;
                min-height: 75px !important;
            }
            .footer-box {
                padding: 3px 5px !important;
            }
            .sign-placeholder {
                height: 22px !important;
            }
            .ipd-pdf-sheet {
                border: none !important;
                padding: 4mm !important;
                font-size: 10.5px !important;
            }
            .ipd-hospital-title {
                font-size: 14px !important;
            }
            .ipd-table-header {
                font-size: 10.5px !important;
                padding: 3px 0 !important;
            }
        }
    </style>
</head>
<body>

    <!-- Screen action controls (hidden in print) -->
    <div class="no-print-bar">
        <div class="title">
            <i class="bi bi-file-earmark-text text-primary"></i>
            <span>Invoice</span>
            <span class="badge-bill"><?= htmlspecialchars($billNo) ?></span>
        </div>
        <div class="btn-group">
            <?php if ($isIpdSale): ?>
                <a href="invoice.php?id=<?= $saleId ?>&format=standard" class="btn <?= $format === 'standard' ? 'btn-active-toggle' : 'btn-secondary' ?>" title="Switch to Standard Retail GST Invoice">
                    <i class="bi bi-receipt"></i> Standard Retail
                </a>
                <a href="invoice.php?id=<?= $saleId ?>&format=ipd_detailed" class="btn <?= $format === 'ipd_detailed' ? 'btn-active-toggle' : 'btn-secondary' ?>" title="Switch to Inpatient Bill of Supply (PDF Style)">
                    <i class="bi bi-file-earmark-pdf"></i> Inpatient PDF Style
                </a>
            <?php endif; ?>

            <!-- Edit Details Toggle Button -->
            <button type="button" id="btnEditToggle" class="btn btn-warning" onclick="toggleEditMode()" title="Enable editing details on this invoice">
                <i class="bi bi-pencil-square"></i> Edit Details
            </button>

            <!-- Save Edits Button (Visible when editing) -->
            <button type="button" id="btnSaveEdits" class="btn btn-success d-none" onclick="saveInvoiceEditsToServer()" title="Save modified details to database">
                <i class="bi bi-check-circle-fill"></i> Save Changes
            </button>

            <!-- Cancel Button (Visible when editing) -->
            <button type="button" id="btnCancelEdit" class="btn btn-secondary d-none" onclick="cancelEditMode()" title="Cancel changes and reload">
                <i class="bi bi-arrow-counterclockwise"></i> Cancel
            </button>

            <button type="button" class="btn btn-primary" onclick="window.print()" title="Print this invoice">
                <i class="bi bi-printer"></i> Print Bill
            </button>
            <button type="button" class="btn btn-secondary" onclick="window.close()" title="Close invoice window">
                <i class="bi bi-x-lg"></i> Close
            </button>
        </div>
    </div>

    <!-- Edit Mode Active Notification Banner (Hidden by default) -->
    <div id="editNoticeBanner" class="d-none">
        <div>
            <i class="bi bi-info-circle-fill me-1"></i>
            <strong>Edit Mode Active:</strong> Click any field (patient, doctor, bed, medicine, qty, rate) to edit. Totals recalculate live.
        </div>
        <div style="display: flex; gap: 6px;">
            <button type="button" class="btn btn-success btn-sm" onclick="saveInvoiceEditsToServer()" style="height: 26px; padding: 0 10px; font-size: 11px;">
                <i class="bi bi-check-circle-fill"></i> Save to Database
            </button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="cancelEditMode()" style="height: 26px; padding: 0 10px; font-size: 11px;">
                <i class="bi bi-arrow-counterclockwise"></i> Cancel
            </button>
        </div>
    </div>

    <?php if ($format === 'ipd_detailed'): ?>
        <!-- ================================================================= -->
        <!-- FORMAT 2: INPATIENT BILL OF SUPPLY - DETAIL (PDF FORMAT)          -->
        <!-- ================================================================= -->
        <div class="ipd-pdf-sheet" id="invoiceContainer">
            <div class="ipd-hospital-title"><?= htmlspecialchars($hospitalName) ?></div>
            <div class="ipd-address-line"><?= htmlspecialchars($hospitalAddress) ?></div>
            <div class="ipd-address-line">CIN: <?= htmlspecialchars($hospitalCin) ?></div>

            <div class="ipd-date-line">Date: <span class="editable-field" id="ipdDateVal" data-field="sale_date"><?= htmlspecialchars($billDateTime) ?></span></div>
            <div class="ipd-divider"></div>

            <div class="ipd-banner-title">INPATIENT BILL OF SUPPLY - DETAIL</div>
            <div class="ipd-bill-meta-row">
                <div>Bill No.: <span class="font-mono"><?= htmlspecialchars($billNo) ?></span></div>
                <div>Payor: <?= htmlspecialchars($payorName) ?></div>
            </div>
            <div class="ipd-bill-meta-row" style="font-weight: normal; font-size: 11px;">
                <div>TPA ID: 123</div>
                <div>Auth. Code: claim</div>
            </div>

            <div class="ipd-divider"></div>

            <div class="ipd-meta-grid">
                <div class="ipd-meta-item">
                    <span class="ipd-meta-lbl">Name</span>
                    <span class="ipd-meta-val">: <strong class="editable-field" id="ipdPatientName" data-field="patient_name"><?= strtoupper(htmlspecialchars($patientName)) ?></strong></span>
                </div>
                <div class="ipd-meta-item">
                    <span class="ipd-meta-lbl">Reg No.</span>
                    <span class="ipd-meta-val">: <span class="editable-field" id="ipdRegNo" data-field="hospital_uhid"><?= htmlspecialchars($regNo) ?></span></span>
                </div>
                <div class="ipd-meta-item">
                    <span class="ipd-meta-lbl">Age/Sex</span>
                    <span class="ipd-meta-val">: Adult / Other</span>
                </div>
                <div class="ipd-meta-item">
                    <span class="ipd-meta-lbl">InPatient No</span>
                    <span class="ipd-meta-val">: <span class="editable-field" id="ipdInpatientNo" data-field="ipd_number"><?= htmlspecialchars($ipdNo) ?></span></span>
                </div>
                <div class="ipd-meta-item">
                    <span class="ipd-meta-lbl">Address</span>
                    <span class="ipd-meta-val">: <span class="editable-field" id="ipdPatientAddress" data-field="patient_address"><?= strtoupper(htmlspecialchars($patientAddress)) ?></span></span>
                </div>
                <div class="ipd-meta-item">
                    <span class="ipd-meta-lbl">Admission Date</span>
                    <span class="ipd-meta-val">: <span class="editable-field" id="ipdAdmissionDate" data-field="sale_date"><?= htmlspecialchars($billDate) ?></span></span>
                </div>
                <div class="ipd-meta-item">
                    <span class="ipd-meta-lbl">Ward</span>
                    <span class="ipd-meta-val">: <span class="editable-field" id="ipdWardName" data-field="ipd_ward"><?= strtoupper(htmlspecialchars($wardName)) ?></span></span>
                </div>
                <div class="ipd-meta-item">
                    <span class="ipd-meta-lbl">Admission Time</span>
                    <span class="ipd-meta-val">: <?= date('h:iA', strtotime($sale['created_at'])) ?></span>
                </div>
                <div class="ipd-meta-item">
                    <span class="ipd-meta-lbl">Bed</span>
                    <span class="ipd-meta-val">: <span class="editable-field" id="ipdBedNo" data-field="ipd_bed"><?= strtoupper(htmlspecialchars($bedNo)) ?></span></span>
                </div>
                <div class="ipd-meta-item">
                    <span class="ipd-meta-lbl">Discharge Date</span>
                    <span class="ipd-meta-val">: Admitted (Ongoing)</span>
                </div>
                <div class="ipd-meta-item">
                    <span class="ipd-meta-lbl">Dept.</span>
                    <span class="ipd-meta-val">: PHARMACY IPD</span>
                </div>
                <div class="ipd-meta-item">
                    <span class="ipd-meta-lbl">No. of Days</span>
                    <span class="ipd-meta-val">: 1</span>
                </div>
                <div class="ipd-meta-item">
                    <span class="ipd-meta-lbl">Doctor</span>
                    <span class="ipd-meta-val">: <span class="editable-field" id="ipdDoctorName" data-field="doctor_name"><?= strtoupper(htmlspecialchars($doctorName)) ?></span></span>
                </div>
                <div class="ipd-meta-item">
                    <span class="ipd-meta-lbl">PAN No</span>
                    <span class="ipd-meta-val">: <?= htmlspecialchars($hospitalPan) ?></span>
                </div>
                <div class="ipd-meta-item">
                    <span class="ipd-meta-lbl">GSTIN</span>
                    <span class="ipd-meta-val">: <?= htmlspecialchars($hospitalGstin) ?></span>
                </div>
                <div class="ipd-meta-item">
                    <span class="ipd-meta-lbl">Payment Status</span>
                    <span class="ipd-meta-val">: <?= htmlspecialchars($sale['payment_status']) ?></span>
                </div>
            </div>

            <div class="ipd-table-header">
                <div class="ipd-col-idx">#</div>
                <div class="ipd-col-item">Ref. No. Order Item</div>
                <div class="ipd-col-qty">Qty</div>
                <div class="ipd-col-price">Price</div>
                <div class="ipd-col-net">Amount(Rs.) Net</div>
            </div>

            <div class="ipd-section-heading">
                1 Pharmacy Drugs &nbsp;&nbsp; SAC:999311
            </div>

            <div id="ipdItemsContainer">
                <?php foreach ($ipdItems as $idx => $it): ?>
                    <div class="ipd-item-row ipd-item-data-row" data-index="<?= $idx ?>">
                        <div class="ipd-item-line">
                            <div class="ipd-col-idx"><?= ($idx + 1) ?></div>
                            <div class="ipd-col-item">
                                <strong class="editable-field ipd-item-name" data-field="description"><?= htmlspecialchars($it['name']) ?></strong>
                                <?php if (!empty($it['details'])): ?>
                                    <div style="font-size: 10.5px;"><?= htmlspecialchars($it['details']) ?></div>
                                <?php endif; ?>
                                <div class="ipd-sub-info">
                                    Batch: <span class="editable-field ipd-item-batch" data-field="batch"><?= htmlspecialchars($it['batch']) ?></span> | Packed: <span class="editable-field ipd-item-qty-packed"><?= $it['qty'] ?></span>, Returned: 0.00 | Charged: <span class="editable-field ipd-item-qty" data-field="qty" oninput="onIpdItemChange(<?= $idx ?>)"><?= (int)$it['qty'] ?></span>
                                </div>
                            </div>
                            <div class="ipd-col-qty">
                                <span class="editable-field ipd-item-qty-col" data-field="qty" oninput="onIpdItemChange(<?= $idx ?>)"><?= $it['qty'] ?></span>
                            </div>
                            <div class="ipd-col-price">
                                <span class="editable-field ipd-item-price" data-field="rate" oninput="onIpdItemChange(<?= $idx ?>)"><?= $it['price'] ?></span>
                            </div>
                            <div class="ipd-col-net ipd-item-net">
                                <?= $it['net_amount'] ?>
                            </div>
                            <div class="edit-ui-control ms-2">
                                <button type="button" class="btn-del-row" onclick="deleteIpdRow(<?= $idx ?>)" title="Remove Item"><i class="bi bi-trash"></i></button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="edit-ui-control my-2">
                <button type="button" class="btn-add-item-row" onclick="addNewIpdRow()">
                    <i class="bi bi-plus-circle"></i> Add Inpatient Drug Row
                </button>
            </div>

            <div class="ipd-subtotal-row">
                <div class="ipd-subtotal-label">Sub Total</div>
                <div class="ipd-subtotal-val" id="ipdSubtotalVal"><?= number_format($totalGross, 2) ?></div>
            </div>

            <div class="ipd-summary-block">
                <div class="ipd-summary-row">
                    <span class="ipd-summary-lbl">Total</span>
                    <span class="ipd-summary-val" id="ipdSummaryTotal"><?= number_format($totalGross, 2) ?></span>
                </div>
                <div class="ipd-summary-row">
                    <span class="ipd-summary-lbl">Discount</span>
                    <span class="ipd-summary-val editable-field" id="ipdDiscountVal" data-field="discount_amount" oninput="recalculateIpdTotals()"><?= number_format($totalDisc, 2) ?></span>
                </div>
                <div class="ipd-summary-row">
                    <span class="ipd-summary-lbl">Net Total</span>
                    <span class="ipd-summary-val" id="ipdNetTotal"><?= number_format($grandTotal, 2) ?></span>
                </div>
                <div class="ipd-summary-row">
                    <span class="ipd-summary-lbl">Net Amount</span>
                    <span class="ipd-summary-val" id="ipdNetAmount"><?= number_format($grandTotal, 2) ?></span>
                </div>
                <div class="ipd-summary-row">
                    <span class="ipd-summary-lbl">Patient Share</span>
                    <span class="ipd-summary-val" id="ipdPatientShare"><?= number_format($paidAmount, 2) ?></span>
                </div>
                <div class="ipd-summary-row">
                    <span class="ipd-summary-lbl">Payments</span>
                    <span class="ipd-summary-val"><?= number_format($paidAmount, 2) ?></span>
                </div>
                <div class="ipd-summary-row" style="font-weight: 800; border-top: 1px dotted #000; padding-top: 2px; margin-top: 2px;">
                    <span class="ipd-summary-lbl">Net Payable</span>
                    <span class="ipd-summary-val" id="ipdNetPayable"><?= number_format($balanceDue, 2) ?></span>
                </div>
            </div>

            <div class="ipd-footer-sign">
                <div>For <?= htmlspecialchars($hospitalName) ?></div>
                <div style="margin-top: 14px;">Prepared by ( <?= htmlspecialchars($cashierName) ?> ) &nbsp;&nbsp;&nbsp;&nbsp; Accounts / Pharmacy Officer</div>
            </div>

            <div class="ipd-page-foot">
                Page 1 of 1
            </div>
        </div>

    <?php else: ?>
        <!-- ================================================================= -->
        <!-- FORMAT 1: STANDARD GST TAX INVOICE (OPD & IPD RETAIL STYLE)       -->
        <!-- ================================================================= -->
        <div class="invoice-sheet" id="invoiceContainer">
            <!-- Center Top Heading -->
            <div class="invoice-title">GST TAX INVOICE</div>

            <!-- 3-Box Header -->
            <div class="header-grid">
                <!-- Left Box: Medical Pharmacy Details -->
                <div class="header-box">
                    <div class="shop-name"><?= htmlspecialchars($shopName) ?></div>
                    <div><?= htmlspecialchars($shopAddress1) ?></div>
                    <div><?= htmlspecialchars($shopAddress2) ?></div>
                    <div style="margin-top: 2px;"><strong>DL No.</strong> : <?= htmlspecialchars($dlNumber) ?></div>
                    <div><strong>GST No.</strong> : <?= htmlspecialchars($gstNumber) ?></div>
                </div>

                <!-- Middle Box: Bill & Date Info -->
                <div class="header-box">
                    <div class="header-line">
                        <span class="header-label">Bill No.</span>
                        <span class="header-val">: <strong>'CR' <?= htmlspecialchars($billNo) ?></strong></span>
                    </div>
                    <div class="header-line">
                        <span class="header-label">Date</span>
                        <span class="header-val">: <span class="editable-field" id="fieldBillDate" data-field="sale_date"><?= htmlspecialchars($billDate) ?></span></span>
                    </div>
                    <div class="header-line">
                        <span class="header-label">Ward/Bed</span>
                        <span class="header-val">: <span class="editable-field" id="fieldWardBed" data-field="ward_bed"><?= htmlspecialchars($wardName . ($bedNo ? ' / ' . $bedNo : '')) ?></span></span>
                    </div>
                    <div class="header-line">
                        <span class="header-label">UHID</span>
                        <span class="header-val">: <span class="editable-field" id="fieldUhid" data-field="hospital_uhid"><?= htmlspecialchars($regNo) ?></span></span>
                    </div>
                </div>

                <!-- Right Box: Patient & Doctor Info -->
                <div class="header-box">
                    <div class="header-line">
                        <span class="header-label">Patient Name</span>
                        <span class="header-val">: <span class="editable-field fw-bold" id="fieldPatientName" data-field="patient_name"><?= strtoupper(htmlspecialchars($patientName)) ?></span></span>
                    </div>
                    <div class="header-line">
                        <span class="header-label">Patient Add</span>
                        <span class="header-val">: <span class="editable-field" id="fieldPatientAddress" data-field="patient_address"><?= strtoupper(htmlspecialchars($patientAddress)) ?></span></span>
                    </div>
                    <div class="header-line">
                        <span class="header-label">Doctor Name</span>
                        <span class="header-val">: <span class="editable-field fw-bold" id="fieldDoctorName" data-field="doctor_name"><?= strtoupper(htmlspecialchars($doctorName)) ?></span></span>
                    </div>
                    <div class="header-line">
                        <span class="header-label">Doctor Address</span>
                        <span class="header-val">: <span class="editable-field" id="fieldDoctorAddress" data-field="doctor_address"><?= htmlspecialchars($doctorAddress) ?></span></span>
                    </div>
                </div>
            </div>

            <!-- Table Container -->
            <div class="table-container">
                <table class="invoice-table" id="itemsTable">
                    <colgroup>
                        <col style="width: 4%;">   <!-- Qty -->
                        <col style="width: 7%;">   <!-- Pack -->
                        <col style="width: 6%;">   <!-- Comp -->
                        <col style="width: 25%;">  <!-- Description -->
                        <col style="width: 9%;">   <!-- Batch -->
                        <col style="width: 6%;">   <!-- Exp -->
                        <col style="width: 6.5%;"> <!-- MRP -->
                        <col style="width: 6.5%;"> <!-- Rate -->
                        <col style="width: 9%;">   <!-- HSN -->
                        <col style="width: 4.5%;"> <!-- SGST % -->
                        <col style="width: 5%;">   <!-- SGST Amt -->
                        <col style="width: 4.5%;"> <!-- CGST % -->
                        <col style="width: 5%;">   <!-- CGST Amt -->
                        <col style="width: 7.5%;"> <!-- Amount -->
                        <col class="edit-ui-control" style="width: 4%;"> <!-- Actions -->
                    </colgroup>
                    <thead>
                        <tr>
                            <th rowspan="2" class="text-center">Qty</th>
                            <th rowspan="2" class="text-center">Pack</th>
                            <th rowspan="2" class="text-center">Comp</th>
                            <th rowspan="2" class="text-left" style="padding-left: 4px;">Description</th>
                            <th rowspan="2" class="text-center">Batch</th>
                            <th rowspan="2" class="text-center">Exp</th>
                            <th rowspan="2" class="text-right" style="padding-right: 4px;">MRP</th>
                            <th rowspan="2" class="text-right" style="padding-right: 4px;">Rate</th>
                            <th rowspan="2" class="text-center">HSN</th>
                            <th colspan="2" class="text-center" style="border-bottom: 1px solid #000;">SGST</th>
                            <th colspan="2" class="text-center" style="border-bottom: 1px solid #000;">CGST</th>
                            <th rowspan="2" class="text-right" style="padding-right: 4px;">Amount</th>
                            <th rowspan="2" class="edit-ui-control text-center"></th>
                        </tr>
                        <tr>
                            <th class="text-center" style="font-size: 9px; padding: 2px 1px;">%</th>
                            <th class="text-right" style="font-size: 9px; padding: 2px 2px;">Amt</th>
                            <th class="text-center" style="font-size: 9px; padding: 2px 1px;">%</th>
                            <th class="text-right" style="font-size: 9px; padding: 2px 2px;">Amt</th>
                        </tr>
                    </thead>
                    <tbody id="itemsTableBody">
                        <?php 
                        $renderedCount = 0;
                        foreach ($flatRows as $idx => $row): 
                            $renderedCount++;
                        ?>
                            <tr class="item-row data-row" data-row-index="<?= $idx ?>">
                                <td class="text-center"><span class="editable-field item-qty" data-field="qty" oninput="onStandardRowChange(<?= $idx ?>)"><?= $row['qty'] ?></span></td>
                                <td class="text-center"><span class="editable-field item-pack" data-field="pack"><?= htmlspecialchars($row['pack']) ?></span></td>
                                <td class="text-center"><span class="editable-field item-comp" data-field="comp"><?= htmlspecialchars($row['comp']) ?></span></td>
                                <td class="text-left" style="padding-left: 4px;"><span class="editable-field fw-bold item-desc" data-field="description"><?= htmlspecialchars($row['description']) ?></span></td>
                                <td class="text-center"><span class="editable-field item-batch font-mono" data-field="batch"><?= htmlspecialchars($row['batch']) ?></span></td>
                                <td class="text-center"><span class="editable-field item-exp" data-field="exp"><?= htmlspecialchars($row['exp']) ?></span></td>
                                <td class="text-right" style="padding-right: 4px;"><span class="editable-field item-mrp" data-field="mrp"><?= number_format($row['mrp'], 2) ?></span></td>
                                <td class="text-right" style="padding-right: 4px;"><span class="editable-field item-rate" data-field="rate" oninput="onStandardRowChange(<?= $idx ?>)"><?= number_format($row['rate'], 2) ?></span></td>
                                <td class="text-center"><span class="editable-field item-hsn" data-field="hsn"><?= htmlspecialchars($row['hsn']) ?></span></td>
                                <td class="text-center" style="font-size: 10px;"><span class="editable-field item-sgst-pct" data-field="sgst_pct" oninput="onStandardRowChange(<?= $idx ?>)"><?= $row['sgst_pct'] ?></span></td>
                                <td class="text-right item-sgst-amt" style="font-size: 10px; padding-right: 2px;"><?= $row['sgst_amt'] ?></td>
                                <td class="text-center" style="font-size: 10px;"><span class="editable-field item-cgst-pct" data-field="cgst_pct" oninput="onStandardRowChange(<?= $idx ?>)"><?= $row['cgst_pct'] ?></span></td>
                                <td class="text-right item-cgst-amt" style="font-size: 10px; padding-right: 2px;"><?= $row['cgst_amt'] ?></td>
                                <td class="text-right fw-bold item-amount" style="padding-right: 4px;"><?= $row['amount'] ?></td>
                                <td class="edit-ui-control text-center">
                                    <button type="button" class="btn-del-row" onclick="deleteStandardRow(<?= $idx ?>)" title="Remove item"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php 
                        $minRows = 8;
                        for ($i = $renderedCount; $i < $minRows; $i++): 
                        ?>
                            <tr class="blank-row">
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td class="edit-ui-control"></td>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>

                <div class="edit-ui-control my-1 text-start">
                    <button type="button" class="btn-add-item-row" onclick="addNewStandardRow()">
                        <i class="bi bi-plus-circle"></i> Add Medicine Row
                    </button>
                </div>
            </div>

            <!-- 3-Box Footer -->
            <div class="footer-grid">
                <!-- Left Box: GST Slab Breakup & Legal -->
                <div class="footer-box footer-box-left">
                    <div id="gstSlabsContainer">
                        <?php if (!empty($gstSlabs)): ?>
                            <?php foreach ($gstSlabs as $slab => $amt): ?>
                                <div class="gst-slab-line"><?= htmlspecialchars($slab) ?> : <?= number_format($amt, 2) ?></div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="gst-slab-line">12% GST : <?= number_format($totalGst, 2) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="jurisdiction">
                        E &amp; O E Subject to Pune Jurisdiction
                    </div>
                </div>

                <!-- Center Box: Greetings & Pharmacist Sign -->
                <div class="footer-box footer-box-center">
                    <div class="wish-text">GET WELL SOON..............</div>
                    <div class="shop-sign-for">For VATSALYA MEDICAL</div>
                    <div class="sign-placeholder"></div>
                    <div class="sign-caption">PHARMACIST SIGN</div>
                </div>

                <!-- Right Box: Summary Totals & Page No -->
                <div class="footer-box footer-box-right">
                    <div class="summary-line">
                        <span class="summary-label">GST Amt</span>
                        <span class="summary-val" id="lblTotalGst">: <?= number_format($totalGst, 2) ?></span>
                    </div>
                    <div class="summary-line">
                        <span class="summary-label">Gross Amt</span>
                        <span class="summary-val" id="lblTotalGross">: <?= number_format($totalGross, 2) ?></span>
                    </div>
                    <div class="summary-line">
                        <span class="summary-label">Disc</span>
                        <span class="summary-val">: <span class="editable-field" id="fieldDiscountAmount" data-field="discount_amount" oninput="recalculateStandardTotals()"><?= number_format($totalDisc, 2) ?></span></span>
                    </div>
                    <div class="summary-line" style="font-size: 11px;">
                        <span class="summary-label"><strong>Amount</strong></span>
                        <span class="summary-val" style="font-size: 11.5px;" id="lblGrandTotal">: <strong><?= number_format($grandTotal, 2) ?></strong></span>
                    </div>
                    <div class="page-no">Page No. : 1</div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- ----------------------------------------------------------------- -->
    <!-- JAVASCRIPT: LIVE EDITING, AUTO-CALCULATION & SERVER PERSISTENCE   -->
    <!-- ----------------------------------------------------------------- -->
    <script>
        let isEditMode = false;
        const currentSaleId = <?= (int)$saleId ?>;
        const currentFormat = '<?= $format ?>';

        function toggleEditMode() {
            isEditMode = !isEditMode;
            const container = document.getElementById('invoiceContainer');
            const editNotice = document.getElementById('editNoticeBanner');
            const btnToggle = document.getElementById('btnEditToggle');
            const btnSave = document.getElementById('btnSaveEdits');
            const btnCancel = document.getElementById('btnCancelEdit');

            if (isEditMode) {
                container.classList.add('edit-mode-active');
                if (editNotice) editNotice.classList.remove('d-none');
                if (btnToggle) btnToggle.classList.add('d-none');
                if (btnSave) btnSave.classList.remove('d-none');
                if (btnCancel) btnCancel.classList.remove('d-none');

                // Enable contenteditable on all .editable-field
                document.querySelectorAll('.editable-field').forEach(el => {
                    el.setAttribute('contenteditable', 'true');
                });
            } else {
                container.classList.remove('edit-mode-active');
                if (editNotice) editNotice.classList.add('d-none');
                if (btnToggle) btnToggle.classList.remove('d-none');
                if (btnSave) btnSave.classList.add('d-none');
                if (btnCancel) btnCancel.classList.add('d-none');

                document.querySelectorAll('.editable-field').forEach(el => {
                    el.setAttribute('contenteditable', 'false');
                });
            }
        }

        function cancelEditMode() {
            if (confirm('Cancel editing and restore original invoice details?')) {
                window.location.reload();
            }
        }

        // Standard Invoice Line Recalculation
        function onStandardRowChange(rowIdx) {
            const row = document.querySelector(`.data-row[data-row-index="${rowIdx}"]`);
            if (!row) return;

            const qty = parseFloat(row.querySelector('.item-qty')?.innerText || 1) || 1;
            const rate = parseFloat(row.querySelector('.item-rate')?.innerText || 0) || 0;
            const sgstPct = parseFloat(row.querySelector('.item-sgst-pct')?.innerText || 6) || 6;
            const cgstPct = parseFloat(row.querySelector('.item-cgst-pct')?.innerText || 6) || 6;
            const gstPct = sgstPct + cgstPct;

            const lineSub = qty * rate;
            const lineGst = lineSub * (gstPct / 100);
            const sgstAmt = lineGst / 2;
            const cgstAmt = lineGst / 2;

            if (row.querySelector('.item-sgst-amt')) row.querySelector('.item-sgst-amt').innerText = sgstAmt.toFixed(2);
            if (row.querySelector('.item-cgst-amt')) row.querySelector('.item-cgst-amt').innerText = cgstAmt.toFixed(2);
            if (row.querySelector('.item-amount')) row.querySelector('.item-amount').innerText = lineSub.toFixed(2);

            recalculateStandardTotals();
        }

        function recalculateStandardTotals() {
            let gross = 0.0;
            let totalGst = 0.0;
            const slabs = {};

            document.querySelectorAll('.data-row').forEach(row => {
                const qty = parseFloat(row.querySelector('.item-qty')?.innerText || 0) || 0;
                const rate = parseFloat(row.querySelector('.item-rate')?.innerText || 0) || 0;
                const sgstPct = parseFloat(row.querySelector('.item-sgst-pct')?.innerText || 6) || 6;
                const cgstPct = parseFloat(row.querySelector('.item-cgst-pct')?.innerText || 6) || 6;
                const gstPct = sgstPct + cgstPct;

                const lineSub = qty * rate;
                const lineGst = lineSub * (gstPct / 100);

                gross += lineSub;
                totalGst += lineGst;

                const slabKey = `${gstPct.toFixed(0)}% GST`;
                slabs[slabKey] = (slabs[slabKey] || 0) + lineGst;
            });

            const discount = parseFloat(document.getElementById('fieldDiscountAmount')?.innerText || 0) || 0;
            const grandTotal = gross + totalGst - discount;

            if (document.getElementById('lblTotalGross')) document.getElementById('lblTotalGross').innerHTML = `: ${gross.toFixed(2)}`;
            if (document.getElementById('lblTotalGst')) document.getElementById('lblTotalGst').innerHTML = `: ${totalGst.toFixed(2)}`;
            if (document.getElementById('lblGrandTotal')) document.getElementById('lblGrandTotal').innerHTML = `: <strong>${grandTotal.toFixed(2)}</strong>`;

            // Update GST Slabs in left footer
            const slabsContainer = document.getElementById('gstSlabsContainer');
            if (slabsContainer) {
                let slabHtml = '';
                for (const [k, v] of Object.entries(slabs)) {
                    slabHtml += `<div class="gst-slab-line">${k} : ${v.toFixed(2)}</div>`;
                }
                if (!slabHtml) slabHtml = `<div class="gst-slab-line">12% GST : ${totalGst.toFixed(2)}</div>`;
                slabsContainer.innerHTML = slabHtml;
            }
        }

        function deleteStandardRow(rowIdx) {
            const row = document.querySelector(`.data-row[data-row-index="${rowIdx}"]`);
            if (row) {
                row.remove();
                recalculateStandardTotals();
            }
        }

        function addNewStandardRow() {
            const tbody = document.getElementById('itemsTableBody');
            if (!tbody) return;

            const newIdx = document.querySelectorAll('.data-row').length + 100;
            const tr = document.createElement('tr');
            tr.className = 'item-row data-row';
            tr.setAttribute('data-row-index', newIdx);
            tr.innerHTML = `
                <td class="text-center"><span class="editable-field item-qty" data-field="qty" contenteditable="true" oninput="onStandardRowChange(${newIdx})">1</span></td>
                <td class="text-center"><span class="editable-field item-pack" data-field="pack" contenteditable="true">10 TABLETS</span></td>
                <td class="text-center"><span class="editable-field item-comp" data-field="comp" contenteditable="true">GEN</span></td>
                <td class="text-left" style="padding-left: 4px;"><span class="editable-field fw-bold item-desc" data-field="description" contenteditable="true">NEW MEDICINE ITEM</span></td>
                <td class="text-center"><span class="editable-field item-batch font-mono" data-field="batch" contenteditable="true">BAT-01</span></td>
                <td class="text-center"><span class="editable-field item-exp" data-field="exp" contenteditable="true">12/28</span></td>
                <td class="text-right" style="padding-right: 4px;"><span class="editable-field item-mrp" data-field="mrp" contenteditable="true">100.00</span></td>
                <td class="text-right" style="padding-right: 4px;"><span class="editable-field item-rate" data-field="rate" contenteditable="true" oninput="onStandardRowChange(${newIdx})">100.00</span></td>
                <td class="text-center"><span class="editable-field item-hsn" data-field="hsn" contenteditable="true">30049099</span></td>
                <td class="text-center" style="font-size: 10px;"><span class="editable-field item-sgst-pct" data-field="sgst_pct" contenteditable="true" oninput="onStandardRowChange(${newIdx})">6.00</span></td>
                <td class="text-right item-sgst-amt" style="font-size: 10px; padding-right: 2px;">6.00</td>
                <td class="text-center" style="font-size: 10px;"><span class="editable-field item-cgst-pct" data-field="cgst_pct" contenteditable="true" oninput="onStandardRowChange(${newIdx})">6.00</span></td>
                <td class="text-right item-cgst-amt" style="font-size: 10px; padding-right: 2px;">6.00</td>
                <td class="text-right fw-bold item-amount" style="padding-right: 4px;">100.00</td>
                <td class="edit-ui-control text-center">
                    <button type="button" class="btn-del-row" onclick="deleteStandardRow(${newIdx})" title="Remove item"><i class="bi bi-trash"></i></button>
                </td>
            `;

            // Insert before the first blank row if any, else append
            const firstBlank = tbody.querySelector('.blank-row');
            if (firstBlank) {
                tbody.insertBefore(tr, firstBlank);
            } else {
                tbody.appendChild(tr);
            }

            recalculateStandardTotals();
        }

        // IPD Bill Line Recalculation
        function onIpdItemChange(rowIdx) {
            const row = document.querySelector(`.ipd-item-data-row[data-index="${rowIdx}"]`);
            if (!row) return;

            const qty = parseFloat(row.querySelector('.ipd-item-qty')?.innerText || 1) || 1;
            const price = parseFloat(row.querySelector('.ipd-item-price')?.innerText || 0) || 0;
            const lineNet = qty * price;

            if (row.querySelector('.ipd-item-qty-packed')) row.querySelector('.ipd-item-qty-packed').innerText = qty.toFixed(2);
            if (row.querySelector('.ipd-item-qty-col')) row.querySelector('.ipd-item-qty-col').innerText = qty.toFixed(2);
            if (row.querySelector('.ipd-item-net')) row.querySelector('.ipd-item-net').innerText = lineNet.toFixed(2);

            recalculateIpdTotals();
        }

        function recalculateIpdTotals() {
            let gross = 0.0;
            document.querySelectorAll('.ipd-item-data-row').forEach(row => {
                const qty = parseFloat(row.querySelector('.ipd-item-qty')?.innerText || 0) || 0;
                const price = parseFloat(row.querySelector('.ipd-item-price')?.innerText || 0) || 0;
                gross += (qty * price);
            });

            const discount = parseFloat(document.getElementById('ipdDiscountVal')?.innerText || 0) || 0;
            const net = Math.max(0, gross - discount);

            if (document.getElementById('ipdSubtotalVal')) document.getElementById('ipdSubtotalVal').innerText = gross.toFixed(2);
            if (document.getElementById('ipdSummaryTotal')) document.getElementById('ipdSummaryTotal').innerText = gross.toFixed(2);
            if (document.getElementById('ipdNetTotal')) document.getElementById('ipdNetTotal').innerText = net.toFixed(2);
            if (document.getElementById('ipdNetAmount')) document.getElementById('ipdNetAmount').innerText = net.toFixed(2);
            if (document.getElementById('ipdPatientShare')) document.getElementById('ipdPatientShare').innerText = net.toFixed(2);
            if (document.getElementById('ipdNetPayable')) document.getElementById('ipdNetPayable').innerText = net.toFixed(2);
        }

        function deleteIpdRow(rowIdx) {
            const row = document.querySelector(`.ipd-item-data-row[data-index="${rowIdx}"]`);
            if (row) {
                row.remove();
                recalculateIpdTotals();
            }
        }

        function addNewIpdRow() {
            const container = document.getElementById('ipdItemsContainer');
            if (!container) return;

            const newIdx = document.querySelectorAll('.ipd-item-data-row').length + 100;
            const div = document.createElement('div');
            div.className = 'ipd-item-row ipd-item-data-row';
            div.setAttribute('data-index', newIdx);
            div.innerHTML = `
                <div class="ipd-item-line">
                    <div class="ipd-col-idx">${document.querySelectorAll('.ipd-item-data-row').length + 1}</div>
                    <div class="ipd-col-item">
                        <strong class="editable-field ipd-item-name" data-field="description" contenteditable="true">NEW INPATIENT MEDICATION</strong>
                        <div class="ipd-sub-info">
                            Batch: <span class="editable-field ipd-item-batch" data-field="batch" contenteditable="true">GEN-01</span> | Packed: <span class="editable-field ipd-item-qty-packed">1.00</span>, Returned: 0.00 | Charged: <span class="editable-field ipd-item-qty" data-field="qty" contenteditable="true" oninput="onIpdItemChange(${newIdx})">1</span>
                        </div>
                    </div>
                    <div class="ipd-col-qty">
                        <span class="editable-field ipd-item-qty-col" data-field="qty" contenteditable="true" oninput="onIpdItemChange(${newIdx})">1.00</span>
                    </div>
                    <div class="ipd-col-price">
                        <span class="editable-field ipd-item-price" data-field="rate" contenteditable="true" oninput="onIpdItemChange(${newIdx})">100.00</span>
                    </div>
                    <div class="ipd-col-net ipd-item-net">
                        100.00
                    </div>
                    <div class="edit-ui-control ms-2">
                        <button type="button" class="btn-del-row" onclick="deleteIpdRow(${newIdx})" title="Remove Item"><i class="bi bi-trash"></i></button>
                    </div>
                </div>
            `;
            container.appendChild(div);
            recalculateIpdTotals();
        }

        // Save Edits to Database via AJAX
        function saveInvoiceEditsToServer() {
            const btnSave = document.getElementById('btnSaveEdits');
            btnSave.disabled = true;
            btnSave.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';

            let patientName = '';
            let patientAddress = '';
            let doctorName = '';
            let doctorAddress = 'Vatsalya Hospital';
            let uhid = '';
            let ward = '';
            let bed = '';
            let saleDate = '<?= $billDateYmd ?>';
            let discountAmount = 0.0;
            let totalGross = 0.0;
            let totalGst = 0.0;
            let grandTotal = 0.0;
            const items = [];

            if (currentFormat === 'standard') {
                patientName = (document.getElementById('fieldPatientName')?.innerText || '').trim();
                patientAddress = (document.getElementById('fieldPatientAddress')?.innerText || '').trim();
                doctorName = (document.getElementById('fieldDoctorName')?.innerText || '').trim();
                doctorAddress = (document.getElementById('fieldDoctorAddress')?.innerText || '').trim();
                uhid = (document.getElementById('fieldUhid')?.innerText || '').trim();
                
                const wardBedStr = (document.getElementById('fieldWardBed')?.innerText || '').trim();
                if (wardBedStr.includes('/')) {
                    const parts = wardBedStr.split('/');
                    ward = parts[0].trim();
                    bed = parts[1].trim();
                } else {
                    ward = wardBedStr;
                }

                discountAmount = parseFloat(document.getElementById('fieldDiscountAmount')?.innerText || 0) || 0;

                document.querySelectorAll('.data-row').forEach(row => {
                    const desc = (row.querySelector('.item-desc')?.innerText || '').trim();
                    if (!desc) return;

                    const qty = parseFloat(row.querySelector('.item-qty')?.innerText || 1) || 1;
                    const pack = (row.querySelector('.item-pack')?.innerText || '10 TABLETS').trim();
                    const comp = (row.querySelector('.item-comp')?.innerText || 'GEN').trim();
                    const batch = (row.querySelector('.item-batch')?.innerText || 'GEN-01').trim();
                    const exp = (row.querySelector('.item-exp')?.innerText || '12/28').trim();
                    const mrp = parseFloat(row.querySelector('.item-mrp')?.innerText || 0) || 0;
                    const rate = parseFloat(row.querySelector('.item-rate')?.innerText || 0) || 0;
                    const hsn = (row.querySelector('.item-hsn')?.innerText || '30049099').trim();
                    const sgstPct = parseFloat(row.querySelector('.item-sgst-pct')?.innerText || 6) || 6;
                    const cgstPct = parseFloat(row.querySelector('.item-cgst-pct')?.innerText || 6) || 6;

                    items.push({
                        description: desc,
                        qty: qty,
                        pack: pack,
                        comp: comp,
                        batch: batch,
                        exp: exp,
                        mrp: mrp,
                        rate: rate,
                        hsn: hsn,
                        sgst_pct: sgstPct,
                        cgst_pct: cgstPct,
                        gst_percent: sgstPct + cgstPct
                    });

                    const lineSub = qty * rate;
                    const lineGst = lineSub * ((sgstPct + cgstPct) / 100);
                    totalGross += lineSub;
                    totalGst += lineGst;
                });

                grandTotal = totalGross + totalGst - discountAmount;

            } else {
                // IPD Detailed Bill format
                patientName = (document.getElementById('ipdPatientName')?.innerText || '').trim();
                patientAddress = (document.getElementById('ipdPatientAddress')?.innerText || '').trim();
                doctorName = (document.getElementById('ipdDoctorName')?.innerText || '').trim();
                uhid = (document.getElementById('ipdRegNo')?.innerText || '').trim();
                ward = (document.getElementById('ipdWardName')?.innerText || '').trim();
                bed = (document.getElementById('ipdBedNo')?.innerText || '').trim();
                discountAmount = parseFloat(document.getElementById('ipdDiscountVal')?.innerText || 0) || 0;

                document.querySelectorAll('.ipd-item-data-row').forEach(row => {
                    const desc = (row.querySelector('.ipd-item-name')?.innerText || '').trim();
                    if (!desc) return;

                    const qty = parseFloat(row.querySelector('.ipd-item-qty')?.innerText || 1) || 1;
                    const price = parseFloat(row.querySelector('.ipd-item-price')?.innerText || 0) || 0;
                    const batch = (row.querySelector('.ipd-item-batch')?.innerText || 'GEN-01').trim();

                    items.push({
                        description: desc,
                        qty: qty,
                        rate: price,
                        mrp: price,
                        batch: batch,
                        exp: '12/28',
                        hsn: '30049099',
                        gst_percent: 12.0
                    });

                    totalGross += (qty * price);
                });

                totalGst = totalGross * 0.12;
                grandTotal = totalGross + totalGst - discountAmount;
            }

            const formData = new FormData();
            formData.append('action', 'save_invoice_edits');
            formData.append('sale_id', currentSaleId);
            formData.append('patient_name', patientName);
            formData.append('patient_address', patientAddress);
            formData.append('doctor_name', doctorName);
            formData.append('doctor_address', doctorAddress);
            formData.append('hospital_uhid', uhid);
            formData.append('ipd_ward', ward);
            formData.append('ipd_bed', bed);
            formData.append('sale_date', saleDate);
            formData.append('discount_amount', discountAmount);
            formData.append('total_gross', totalGross);
            formData.append('total_gst', totalGst);
            formData.append('grand_total', grandTotal);
            formData.append('items_json', JSON.stringify(items));

            fetch('invoice.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                btnSave.disabled = false;
                btnSave.innerHTML = '<i class="bi bi-check-circle-fill"></i> Save Changes';
                if (data.success) {
                    alert('✓ Invoice details saved successfully to database!');
                    window.location.reload();
                } else {
                    alert('Error saving changes: ' + (data.message || 'Unknown error.'));
                }
            })
            .catch(err => {
                btnSave.disabled = false;
                btnSave.innerHTML = '<i class="bi bi-check-circle-fill"></i> Save Changes';
                alert('Connection error while saving changes. Please try again.');
            });
        }
    </script>

    <?php if (isset($_GET['autoprint']) && $_GET['autoprint'] == '1'): ?>
    <script>
        window.addEventListener('load', function() {
            setTimeout(function() {
                window.print();
            }, 350);
        });
    </script>
    <?php endif; ?>

</body>
</html>
