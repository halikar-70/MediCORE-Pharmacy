<?php
// modules/patients/history.php - Complete Patient Pharmacy History, Billing Ledger & Itemized Medication Profile

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/DocumentSequenceService.php';
require_once __DIR__ . '/../../app/Services/StockLedgerService.php';
require_once __DIR__ . '/../../app/Services/FefoService.php';
require_once __DIR__ . '/../../app/Services/AuditService.php';
require_once __DIR__ . '/../../app/Services/SalesService.php';

use Pharmacy\Database\Database;
use Pharmacy\Services\SalesService;

require_permission('pharmacy.patients.view');

// AJAX Endpoint: 1-Month Bill Edit History & Version Snapshots
if (isset($_GET['ajax_bill_history'])) {
    header('Content-Type: application/json');
    $saleId = (int)($_GET['sale_id'] ?? 0);
    $salesService = new SalesService($pdo);
    $currSale = $salesService->getSale($saleId);
    $histories = $salesService->getBillEditHistory($saleId);
    echo json_encode([
        'success' => true,
        'current_sale' => $currSale,
        'history_versions' => $histories
    ]);
    exit;
}

$page_title = 'Patient Pharmacy History & Billing Ledger';

$uhidQuery = trim($_GET['uhid'] ?? '');
$nameQuery = trim($_GET['name'] ?? $_GET['patient_name'] ?? $_GET['q'] ?? '');
$sourceQuery = strtoupper(trim($_GET['source'] ?? $_GET['type'] ?? ''));
$pharmacyIdQuery = (int)($_GET['pharmacy_id'] ?? 0);
$hospitalIdQuery = (int)($_GET['hospital_id'] ?? 0);
$genericId = (int)($_GET['id'] ?? $_GET['patient_id'] ?? 0);

// Backward-compat ID assignment based on source
if ($genericId > 0) {
    if ($sourceQuery === 'PHARMACY') {
        if ($pharmacyIdQuery === 0) $pharmacyIdQuery = $genericId;
    } else if ($sourceQuery === 'HOSPITAL' || $sourceQuery === 'IPD') {
        if ($hospitalIdQuery === 0) $hospitalIdQuery = $genericId;
    } else {
        if ($pharmacyIdQuery === 0 && $hospitalIdQuery === 0) {
            $pharmacyIdQuery = $genericId;
        }
    }
}

$patientInfo = null;
$salesHistory = [];
$totalBilled = 0.0;
$totalPaid = 0.0;
$totalBalance = 0.0;
$totalItemsCount = 0;
$medicinesCount = 0;
$equipmentCount = 0;

$hospitalPdo = Database::getHospitalConnection();

// 1. Resolve Patient Demographic Details with Strict Priority
// PRIORITY 1: Match by Exact UHID (e.g. VH3284 or PP-03284)
if (!empty($uhidQuery)) {
    // 1a. Check Hospital Database by Exact Code
    if ($hospitalPdo) {
        try {
            $hSql = "
                SELECT 
                    p.patient_id as hospital_patient_id,
                    COALESCE(p.patient_code, CONCAT('VH', p.patient_id)) as uhid,
                    CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.middle_name, ''), ' ', COALESCE(p.last_name, '')) as name,
                    COALESCE(p.phone, '') as mobile,
                    COALESCE(p.gender, 'Other') as gender,
                    p.dob,
                    COALESCE(p.city, '') as city,
                    COALESCE(p.address, '') as address,
                    COALESCE(
                        (SELECT d.name FROM opd_visits v JOIN doctors d ON d.doctor_id = v.doctor_id WHERE v.patient_id = p.patient_id ORDER BY v.visit_id DESC LIMIT 1),
                        (SELECT d.name FROM prescriptions pr JOIN doctors d ON d.doctor_id = pr.doctor_id WHERE pr.patient_id = p.patient_id ORDER BY pr.prescription_id DESC LIMIT 1),
                        (SELECT d.name FROM appointments a JOIN doctors d ON d.doctor_id = a.doctor_id WHERE a.patient_id = p.patient_id ORDER BY a.appointment_id DESC LIMIT 1),
                        (SELECT d.name FROM admissions adm JOIN doctors d ON d.doctor_id = adm.doctor_id WHERE adm.patient_id = p.patient_id ORDER BY adm.admission_id DESC LIMIT 1)
                    ) as doctor_name,
                    (SELECT adm.ipd_number FROM admissions adm WHERE adm.patient_id = p.patient_id AND adm.status = 'Admitted' ORDER BY adm.admission_id DESC LIMIT 1) as active_ipd_no,
                    (SELECT w.ward_name FROM admissions adm LEFT JOIN wards w ON adm.ward_id = w.ward_id WHERE adm.patient_id = p.patient_id AND adm.status = 'Admitted' ORDER BY adm.admission_id DESC LIMIT 1) as active_ward,
                    (SELECT b.bed_number FROM admissions adm LEFT JOIN beds b ON adm.bed_id = b.bed_id WHERE adm.patient_id = p.patient_id AND adm.status = 'Admitted' ORDER BY adm.admission_id DESC LIMIT 1) as active_bed
                FROM patients p
                WHERE (p.patient_code = ? OR CONCAT('VH', p.patient_id) = ?)
                LIMIT 1
            ";
            $hStmt = $hospitalPdo->prepare($hSql);
            $hStmt->execute([$uhidQuery, $uhidQuery]);
            $hRow = $hStmt->fetch(PDO::FETCH_ASSOC);

            if ($hRow) {
                // Find matching pharmacy_patients id if mapped
                $pharmId = null;
                $pFind = $pdo->prepare("SELECT id FROM pharmacy_patients WHERE hospital_uhid = ? OR hospital_patient_id = ? LIMIT 1");
                $pFind->execute([$hRow['uhid'], (int)$hRow['hospital_patient_id']]);
                if ($pr = $pFind->fetch(PDO::FETCH_ASSOC)) {
                    $pharmId = (int)$pr['id'];
                }

                $patientInfo = [
                    'source'              => 'Hospital Patient',
                    'uhid'                => $hRow['uhid'],
                    'name'                => preg_replace('/\s+/', ' ', trim($hRow['name'])),
                    'mobile'              => $hRow['mobile'],
                    'gender'              => $hRow['gender'],
                    'dob'                 => $hRow['dob'],
                    'city'                => $hRow['city'],
                    'address'             => $hRow['address'],
                    'doctor_name'         => $hRow['doctor_name'] ?: 'Dr. Duty Doctor',
                    'ipd_number'          => $hRow['active_ipd_no'],
                    'ward'                => $hRow['active_ward'],
                    'bed'                 => $hRow['active_bed'],
                    'hospital_patient_id' => (int)$hRow['hospital_patient_id'],
                    'pharmacy_patient_id' => $pharmId,
                    'patient_id'          => $pharmId ?: (int)$hRow['hospital_patient_id']
                ];
            }
        } catch (Exception $e) {}
    }

    // 1b. Check Pharmacy Patients Database by UHID
    if (!$patientInfo) {
        try {
            $pSql = "SELECT * FROM pharmacy_patients WHERE hospital_uhid = ? OR pharmacy_patient_no = ? LIMIT 1";
            $pStmt = $pdo->prepare($pSql);
            $pStmt->execute([$uhidQuery, $uhidQuery]);
            $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);

            if ($pRow) {
                $patientInfo = [
                    'source'              => 'Pharmacy Registered',
                    'uhid'                => $pRow['hospital_uhid'] ?: $pRow['pharmacy_patient_no'],
                    'name'                => $pRow['name'],
                    'mobile'              => $pRow['mobile'],
                    'gender'              => $pRow['gender'] ?: 'Other',
                    'dob'                 => $pRow['date_of_birth'],
                    'city'                => $pRow['city'],
                    'address'             => $pRow['address'],
                    'doctor_name'         => 'Dr. Duty Doctor',
                    'ipd_number'          => null,
                    'ward'                => '',
                    'bed'                 => '',
                    'hospital_patient_id' => (int)($pRow['hospital_patient_id'] ?? 0),
                    'pharmacy_patient_id' => (int)$pRow['id'],
                    'patient_id'          => (int)$pRow['id']
                ];
            }
        } catch (Exception $e) {}
    }
}

// PRIORITY 2: Match by Explicit Hospital Patient ID
if (!$patientInfo && $hospitalIdQuery > 0 && $hospitalPdo) {
    try {
        $hSql = "
            SELECT 
                p.patient_id as hospital_patient_id,
                COALESCE(p.patient_code, CONCAT('VH', p.patient_id)) as uhid,
                CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.middle_name, ''), ' ', COALESCE(p.last_name, '')) as name,
                COALESCE(p.phone, '') as mobile,
                COALESCE(p.gender, 'Other') as gender,
                p.dob,
                COALESCE(p.city, '') as city,
                COALESCE(p.address, '') as address,
                COALESCE(
                    (SELECT d.name FROM opd_visits v JOIN doctors d ON d.doctor_id = v.doctor_id WHERE v.patient_id = p.patient_id ORDER BY v.visit_id DESC LIMIT 1),
                    (SELECT d.name FROM prescriptions pr JOIN doctors d ON d.doctor_id = pr.doctor_id WHERE pr.patient_id = p.patient_id ORDER BY pr.prescription_id DESC LIMIT 1),
                    (SELECT d.name FROM appointments a JOIN doctors d ON d.doctor_id = a.doctor_id WHERE a.patient_id = p.patient_id ORDER BY a.appointment_id DESC LIMIT 1),
                    (SELECT d.name FROM admissions adm JOIN doctors d ON d.doctor_id = adm.doctor_id WHERE adm.patient_id = p.patient_id ORDER BY adm.admission_id DESC LIMIT 1)
                ) as doctor_name,
                (SELECT adm.ipd_number FROM admissions adm WHERE adm.patient_id = p.patient_id AND adm.status = 'Admitted' ORDER BY adm.admission_id DESC LIMIT 1) as active_ipd_no,
                (SELECT w.ward_name FROM admissions adm LEFT JOIN wards w ON adm.ward_id = w.ward_id WHERE adm.patient_id = p.patient_id AND adm.status = 'Admitted' ORDER BY adm.admission_id DESC LIMIT 1) as active_ward,
                (SELECT b.bed_number FROM admissions adm LEFT JOIN beds b ON adm.bed_id = b.bed_id WHERE adm.patient_id = p.patient_id AND adm.status = 'Admitted' ORDER BY adm.admission_id DESC LIMIT 1) as active_bed
            FROM patients p
            WHERE p.patient_id = ?
            LIMIT 1
        ";
        $hStmt = $hospitalPdo->prepare($hSql);
        $hStmt->execute([$hospitalIdQuery]);
        $hRow = $hStmt->fetch(PDO::FETCH_ASSOC);

        if ($hRow) {
            $patientInfo = [
                'source'              => 'Hospital Patient',
                'uhid'                => $hRow['uhid'],
                'name'                => preg_replace('/\s+/', ' ', trim($hRow['name'])),
                'mobile'              => $hRow['mobile'],
                'gender'              => $hRow['gender'],
                'dob'                 => $hRow['dob'],
                'city'                => $hRow['city'],
                'address'             => $hRow['address'],
                'doctor_name'         => $hRow['doctor_name'] ?: 'Dr. Duty Doctor',
                'ipd_number'          => $hRow['active_ipd_no'],
                'ward'                => $hRow['active_ward'],
                'bed'                 => $hRow['active_bed'],
                'hospital_patient_id' => (int)$hRow['hospital_patient_id'],
                'pharmacy_patient_id' => null,
                'patient_id'          => (int)$hRow['hospital_patient_id']
            ];
        }
    } catch (Exception $e) {}
}

// PRIORITY 3: Match by Explicit Pharmacy Patient ID
if (!$patientInfo && $pharmacyIdQuery > 0) {
    try {
        $pSql = "SELECT * FROM pharmacy_patients WHERE id = ? LIMIT 1";
        $pStmt = $pdo->prepare($pSql);
        $pStmt->execute([$pharmacyIdQuery]);
        $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);

        if ($pRow) {
            $patientInfo = [
                'source'              => 'Pharmacy Registered',
                'uhid'                => $pRow['hospital_uhid'] ?: $pRow['pharmacy_patient_no'],
                'name'                => $pRow['name'],
                'mobile'              => $pRow['mobile'],
                'gender'              => $pRow['gender'] ?: 'Other',
                'dob'                 => $pRow['date_of_birth'],
                'city'                => $pRow['city'],
                'address'             => $pRow['address'],
                'doctor_name'         => 'Dr. Duty Doctor',
                'ipd_number'          => null,
                'ward'                => '',
                'bed'                 => '',
                'hospital_patient_id' => (int)($pRow['hospital_patient_id'] ?? 0),
                'pharmacy_patient_id' => (int)$pRow['id'],
                'patient_id'          => (int)$pRow['id']
            ];
        }
    } catch (Exception $e) {}
}

// PRIORITY 4: Match by Patient Name or Search Term
if (!$patientInfo && $nameQuery !== '') {
    // 4a. Check pharmacy_patients exact name
    try {
        $pStmt = $pdo->prepare("SELECT * FROM pharmacy_patients WHERE name = ? LIMIT 1");
        $pStmt->execute([$nameQuery]);
        if ($pRow = $pStmt->fetch(PDO::FETCH_ASSOC)) {
            $patientInfo = [
                'source'              => 'Pharmacy Registered',
                'uhid'                => $pRow['hospital_uhid'] ?: $pRow['pharmacy_patient_no'],
                'name'                => $pRow['name'],
                'mobile'              => $pRow['mobile'],
                'gender'              => $pRow['gender'] ?: 'Other',
                'dob'                 => $pRow['date_of_birth'],
                'city'                => $pRow['city'],
                'address'             => $pRow['address'],
                'doctor_name'         => 'Dr. Duty Doctor',
                'ipd_number'          => null,
                'ward'                => '',
                'bed'                 => '',
                'hospital_patient_id' => (int)($pRow['hospital_patient_id'] ?? 0),
                'pharmacy_patient_id' => (int)$pRow['id'],
                'patient_id'          => (int)$pRow['id']
            ];
        }
    } catch (Exception $e) {}

    // 4b. Check hospital patients by full name
    if (!$patientInfo && $hospitalPdo) {
        try {
            $hSql = "
                SELECT 
                    p.patient_id as hospital_patient_id,
                    COALESCE(p.patient_code, CONCAT('VH', p.patient_id)) as uhid,
                    CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.middle_name, ''), ' ', COALESCE(p.last_name, '')) as name,
                    COALESCE(p.phone, '') as mobile,
                    COALESCE(p.gender, 'Other') as gender,
                    p.dob,
                    COALESCE(p.city, '') as city,
                    COALESCE(p.address, '') as address,
                    COALESCE(
                        (SELECT d.name FROM opd_visits v JOIN doctors d ON d.doctor_id = v.doctor_id WHERE v.patient_id = p.patient_id ORDER BY v.visit_id DESC LIMIT 1),
                        (SELECT d.name FROM prescriptions pr JOIN doctors d ON d.doctor_id = pr.doctor_id WHERE pr.patient_id = p.patient_id ORDER BY pr.prescription_id DESC LIMIT 1),
                        (SELECT d.name FROM appointments a JOIN doctors d ON d.doctor_id = a.doctor_id WHERE a.patient_id = p.patient_id ORDER BY a.appointment_id DESC LIMIT 1),
                        (SELECT d.name FROM admissions adm JOIN doctors d ON d.doctor_id = adm.doctor_id WHERE adm.patient_id = p.patient_id ORDER BY adm.admission_id DESC LIMIT 1)
                    ) as doctor_name,
                    (SELECT adm.ipd_number FROM admissions adm WHERE adm.patient_id = p.patient_id AND adm.status = 'Admitted' ORDER BY adm.admission_id DESC LIMIT 1) as active_ipd_no,
                    (SELECT w.ward_name FROM admissions adm LEFT JOIN wards w ON adm.ward_id = w.ward_id WHERE adm.patient_id = p.patient_id AND adm.status = 'Admitted' ORDER BY adm.admission_id DESC LIMIT 1) as active_ward,
                    (SELECT b.bed_number FROM admissions adm LEFT JOIN beds b ON adm.bed_id = b.bed_id WHERE adm.patient_id = p.patient_id AND adm.status = 'Admitted' ORDER BY adm.admission_id DESC LIMIT 1) as active_bed
                FROM patients p
                WHERE (
                    CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, '')) = ?
                    OR CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.middle_name, ''), ' ', COALESCE(p.last_name, '')) = ?
                    OR CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.middle_name, ''), ' ', COALESCE(p.last_name, '')) LIKE ?
                )
                LIMIT 1
            ";
            $hStmt = $hospitalPdo->prepare($hSql);
            $hStmt->execute([$nameQuery, $nameQuery, '%' . $nameQuery . '%']);
            $hRow = $hStmt->fetch(PDO::FETCH_ASSOC);

            if ($hRow) {
                $patientInfo = [
                    'source'              => 'Hospital Patient',
                    'uhid'                => $hRow['uhid'],
                    'name'                => preg_replace('/\s+/', ' ', trim($hRow['name'])),
                    'mobile'              => $hRow['mobile'],
                    'gender'              => $hRow['gender'],
                    'dob'                 => $hRow['dob'],
                    'city'                => $hRow['city'],
                    'address'             => $hRow['address'],
                    'doctor_name'         => $hRow['doctor_name'] ?: 'Dr. Duty Doctor',
                    'ipd_number'          => $hRow['active_ipd_no'],
                    'ward'                => $hRow['active_ward'],
                    'bed'                 => $hRow['active_bed'],
                    'hospital_patient_id' => (int)$hRow['hospital_patient_id'],
                    'pharmacy_patient_id' => null,
                    'patient_id'          => (int)$hRow['hospital_patient_id']
                ];
            }
        } catch (Exception $e) {}
    }
}

// PRIORITY 5: Fallback if sales exist under customer name or UHID
if (!$patientInfo && ($nameQuery !== '' || $uhidQuery !== '')) {
    $patientInfo = [
        'source'              => 'Pharmacy Sales Record',
        'uhid'                => $uhidQuery ?: 'N/A',
        'name'                => $nameQuery ?: ($uhidQuery ?: 'Walk-in Patient'),
        'mobile'              => '',
        'gender'              => 'Other',
        'dob'                 => '',
        'city'                => '',
        'address'             => '',
        'doctor_name'         => 'Dr. Duty Doctor',
        'ipd_number'          => null,
        'ward'                => '',
        'bed'                 => '',
        'hospital_patient_id' => 0,
        'pharmacy_patient_id' => null,
        'patient_id'          => 0
    ];
}

// 2. Fetch All Billing & Dispensing Transactions for this Specific Patient
if ($patientInfo) {
    $whereClauses = [];
    $params = [];

    if (!empty($patientInfo['pharmacy_patient_id']) && (int)$patientInfo['pharmacy_patient_id'] > 0) {
        $whereClauses[] = "s.patient_id = ?";
        $params[] = (int)$patientInfo['pharmacy_patient_id'];
    }

    if (!empty($patientInfo['name'])) {
        $whereClauses[] = "s.customer_name = ?";
        $params[] = $patientInfo['name'];
    }

    if (!empty($patientInfo['uhid']) && $patientInfo['uhid'] !== 'N/A') {
        $whereClauses[] = "s.notes LIKE ?";
        $params[] = '%' . $patientInfo['uhid'] . '%';

        $whereClauses[] = "s.customer_name LIKE ?";
        $params[] = '%' . $patientInfo['uhid'] . '%';
    }

    if (!empty($patientInfo['ipd_number'])) {
        $whereClauses[] = "s.ipd_admission_id = ?";
        $params[] = $patientInfo['ipd_number'];
    }

    $whereSql = !empty($whereClauses) ? implode(' OR ', $whereClauses) : '1=0';

    $salesSql = "
        SELECT 
            s.sale_id,
            s.sale_number,
            s.sale_type,
            s.patient_type,
            s.customer_name,
            s.customer_mobile,
            s.doctor_name,
            s.ipd_ward,
            s.ipd_bed,
            s.subtotal_amount,
            s.discount_amount,
            s.taxable_amount,
            s.gst_amount,
            s.round_off,
            s.grand_total,
            s.paid_amount,
            s.balance_amount,
            s.payment_status,
            s.payment_mode,
            s.status,
            s.cancellation_reason,
            s.notes,
            s.created_at
        FROM pharmacy_sales s
        WHERE ($whereSql) AND (s.status IS NULL OR s.status != 'CANCELLED')
        ORDER BY s.sale_id DESC
    ";

    $salesStmt = $pdo->prepare($salesSql);
    $salesStmt->execute($params);
    $sales = $salesStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch Line Items for each Sale
        foreach ($sales as &$sale) {
            $totalBilled += (float)$sale['grand_total'];
            $totalPaid += (float)$sale['paid_amount'];
            $totalBalance += (float)$sale['balance_amount'];

            $itemsSql = "
                SELECT 
                    si.sale_item_id,
                    si.medicine_id,
                    si.dosage_form,
                    si.pack_size,
                    si.quantity,
                    si.unit_price,
                    si.mrp,
                    si.discount_amount,
                    si.taxable_amount,
                    si.gst_percent,
                    si.gst_amount,
                    si.line_total,
                    m.medicine_name,
                    m.generic_name,
                    m.composition,
                    m.brand_name,
                    COALESCE(
                        (SELECT GROUP_CONCAT(CONCAT(b.batch_number, ' (Exp: ', b.expiry_date, ')') SEPARATOR ', ') 
                         FROM pharmacy_sale_item_batches b WHERE b.sale_item_id = si.sale_item_id),
                        'Standard'
                    ) as batch_info
                FROM pharmacy_sale_items si
                LEFT JOIN medicines m ON m.medicine_id = si.medicine_id
                WHERE si.sale_id = ?
                ORDER BY si.sale_item_id ASC
            ";
            $itemsStmt = $pdo->prepare($itemsSql);
            $itemsStmt->execute([(int)$sale['sale_id']]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            // Categorize as Medicine vs Equipment / Consumables
            foreach ($items as &$it) {
                $totalItemsCount += (int)$it['quantity'];
                $form = strtolower($it['dosage_form'] ?? '');
                $name = strtolower($it['medicine_name'] ?? '');
                
                $isEquipment = (
                    str_contains($form, 'equipment') || str_contains($form, 'surgical') || 
                    str_contains($form, 'device') || str_contains($form, 'needle') || 
                    str_contains($form, 'glove') || str_contains($form, 'syringe') || 
                    str_contains($form, 'bandage') || str_contains($form, 'gauze') || 
                    str_contains($form, 'catheter') || str_contains($form, 'cannula') || 
                    str_contains($form, 'mask') || str_contains($form, 'tube') ||
                    str_contains($name, 'needle') || str_contains($name, 'syringe') || 
                    str_contains($name, 'gauze') || str_contains($name, 'cotton') || 
                    str_contains($name, 'glove') || str_contains($name, 'cannula')
                );

                $it['item_type'] = $isEquipment ? 'EQUIPMENT' : 'MEDICINE';
                if ($isEquipment) {
                    $equipmentCount += (int)$it['quantity'];
                } else {
                    $medicinesCount += (int)$it['quantity'];
                }
            }
            unset($it);

            $sale['items'] = $items;
        }
        unset($sale);

        $salesHistory = $sales;
    }

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<style>
.patient-profile-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.kpi-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 1.1rem 1.25rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.06);
}
.kpi-icon-wrap {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
}
.bill-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    margin-bottom: 1.25rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    overflow: hidden;
}
.bill-card-header {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 12px 18px;
}
.badge-pill-status {
    font-size: 0.75rem;
    font-weight: 700;
    padding: 4px 10px;
    border-radius: 9999px;
    letter-spacing: 0.3px;
}
.badge-item-med {
    background-color: #f0fdf4;
    color: #166534;
    border: 1px solid #bbf7d0;
    font-size: 0.70rem;
    font-weight: 700;
    border-radius: 6px;
    padding: 2px 6px;
}
.badge-item-equip {
    background-color: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
    font-size: 0.70rem;
    font-weight: 700;
    border-radius: 6px;
    padding: 2px 6px;
}
@media print {
    .no-print { display: none !important; }
    .sidebar, .navbar, .page-footer { display: none !important; }
    .container-fluid { padding: 0 !important; }
    .card { border: none !important; box-shadow: none !important; }
}
</style>

<div class="container-fluid py-3 px-3 px-md-4">
    <!-- Top Action Bar & Live Search -->
    <div class="d-flex justify-content-between align-items-center mb-3.5 flex-wrap gap-3 no-print">
        <div class="d-flex align-items-center gap-2.5">
            <a href="<?= BASE_URL ?>modules/patients/search.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1.5 fw-semibold d-inline-flex align-items-center gap-1 shadow-xs">
                <i class="bi bi-arrow-left"></i> Back to Patient Directory
            </a>
            <h4 class="fw-bold mb-0 text-dark" style="font-size: 1.25rem;">
                Patient Billing &amp; Dispensing History
            </h4>
        </div>

        <!-- Search Another Patient Form -->
        <div class="d-flex align-items-center gap-2 flex-grow-1" style="max-width: 480px;">
            <form method="GET" action="history.php" class="w-100">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
                    <input type="text" name="name" class="form-control bg-white border-start-0 ps-1" placeholder="Search patient name, UHID, or mobile..." value="<?= htmlspecialchars($nameQuery ?: $uhidQuery) ?>" autocomplete="off" required>
                    <button class="btn btn-emerald text-white fw-bold px-3" style="background-color: #0d9488; border-color: #0d9488;" type="submit">
                        Search
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if (!$patientInfo): ?>
        <!-- No Patient Selected State -->
        <div class="card border shadow-sm rounded-4 p-5 text-center bg-white my-4">
            <div class="rounded-circle d-inline-flex align-items-center justify-content-center mx-auto mb-3" style="width: 64px; height: 64px; background-color: #f0fdf4; color: #0d9488; border: 1px solid #99f6e4;">
                <i class="bi bi-search fs-2"></i>
            </div>
            <h5 class="fw-bold text-dark mb-1">Search or Select a Patient</h5>
            <p class="text-muted small mx-auto mb-4" style="max-width: 460px;">
                Enter a patient name, hospital UHID, or mobile number in the search bar above to view complete billing history, paid/credit balances, and itemized medication dispensing timeline.
            </p>
            <div class="d-flex justify-content-center gap-2">
                <a href="<?= BASE_URL ?>modules/patients/search.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3.5 py-1.5 fw-semibold">
                    <i class="bi bi-people me-1"></i> Browse Patient Directory
                </a>
            </div>
        </div>
    <?php else: ?>
        <!-- 1. Patient Profile Header Card -->
        <div class="patient-profile-card mb-4 p-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold shadow-xs flex-shrink-0" style="width: 52px; height: 52px; background-color: #0d9488; font-size: 1.4rem;">
                        <?= strtoupper(substr(trim($patientInfo['name']), 0, 1)) ?>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <h4 class="fw-bold text-dark mb-0" style="font-size: 1.35rem; letter-spacing: -0.2px;">
                                <?= htmlspecialchars($patientInfo['name']) ?>
                            </h4>
                            <span class="badge bg-light text-dark border font-monospace px-2 py-1" style="font-size: 0.82rem;">
                                UHID: <?= htmlspecialchars($patientInfo['uhid']) ?>
                            </span>
                            <?php if (!empty($patientInfo['ipd_number'])): ?>
                                <span class="badge badge-type-paidr px-2.5 py-1" style="font-size: 0.76rem;">
                                    <i class="bi bi-bed me-1"></i> Admitted IPD: <?= htmlspecialchars($patientInfo['ipd_number']) ?>
                                </span>
                            <?php else: ?>
                                <span class="badge badge-type-hospital px-2 py-1" style="font-size: 0.74rem;">
                                    <i class="bi bi-hospital me-1"></i> <?= htmlspecialchars($patientInfo['source']) ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="text-muted small mt-1 d-flex align-items-center gap-3 flex-wrap" style="font-size: 0.82rem;">
                            <span><i class="bi bi-telephone me-1 text-secondary"></i><?= htmlspecialchars($patientInfo['mobile'] ?: 'No Phone') ?></span>
                            <span><i class="bi bi-gender-ambiguous me-1 text-secondary"></i><?= htmlspecialchars($patientInfo['gender']) ?><?= $patientInfo['dob'] ? ' &bull; ' . htmlspecialchars($patientInfo['dob']) : '' ?></span>
                            <span><i class="bi bi-geo-alt me-1 text-secondary"></i><?= htmlspecialchars($patientInfo['city'] ?: ($patientInfo['address'] ?: 'Pune')) ?></span>
                            <span><i class="bi bi-person-badge me-1 text-emerald"></i><strong><?= htmlspecialchars($patientInfo['doctor_name']) ?></strong></span>
                            <?php if (!empty($patientInfo['ward'])): ?>
                                <span><i class="bi bi-door-open me-1 text-primary"></i><strong><?= htmlspecialchars($patientInfo['ward']) ?></strong> (Bed <?= htmlspecialchars($patientInfo['bed']) ?>)</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-2 no-print">
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1.5 fw-semibold d-inline-flex align-items-center gap-1 shadow-xs" onclick="window.print()" style="font-size: 0.82rem;">
                        <i class="bi bi-printer text-primary"></i> Print Statement (PDF)
                    </button>
                    <a href="<?= BASE_URL ?>modules/sales/counter.php?patient_id=<?= urlencode($patientInfo['patient_id']) ?>&patient_name=<?= urlencode($patientInfo['name']) ?>" class="btn btn-sm text-white rounded-pill px-3.5 py-1.5 fw-bold d-inline-flex align-items-center gap-1 shadow-sm" style="background-color: #0d9488; border-color: #0d9488; font-size: 0.82rem;">
                        <i class="bi bi-receipt"></i> + Create OPD Bill
                    </a>
                    <a href="<?= BASE_URL ?>modules/sales/regular.php?patient_id=<?= urlencode($patientInfo['patient_id']) ?>&patient_name=<?= urlencode($patientInfo['name']) ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1.5 fw-bold d-inline-flex align-items-center gap-1 shadow-xs" style="font-size: 0.82rem;">
                        <i class="bi bi-capsule"></i> + IPD Dispense
                    </a>
                </div>
            </div>
        </div>

        <!-- 2. Financial Summary KPI Metric Cards -->
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-sm-6">
                <div class="kpi-card">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-muted small fw-bold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">Total Billed</span>
                        <div class="kpi-icon-wrap" style="background-color: #f8fafc; color: #475569; border: 1px solid #e2e8f0;">
                            <i class="bi bi-cash-stack"></i>
                        </div>
                    </div>
                    <h3 class="fw-bold text-dark mb-0 font-monospace" style="font-size: 1.55rem;">
                        ₹<?= number_format($totalBilled, 2) ?>
                    </h3>
                    <div class="text-muted small mt-1" style="font-size: 0.74rem;">
                        Across <?= count($salesHistory) ?> total pharmacy bills
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-sm-6">
                <div class="kpi-card">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-muted small fw-bold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">Total Paid</span>
                        <div class="kpi-icon-wrap" style="background-color: #f0fdf4; color: #166534; border: 1px solid #bbf7d0;">
                            <i class="bi bi-check2-circle"></i>
                        </div>
                    </div>
                    <h3 class="fw-bold text-success mb-0 font-monospace" style="font-size: 1.55rem;">
                        ₹<?= number_format($totalPaid, 2) ?>
                    </h3>
                    <div class="text-muted small mt-1" style="font-size: 0.74rem;">
                        Cleared via Cash / UPI / Online
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-sm-6">
                <div class="kpi-card">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-muted small fw-bold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">Credited / Due</span>
                        <div class="kpi-icon-wrap" style="background-color: <?= $totalBalance > 0 ? '#fff1f2' : '#f8fafc' ?>; color: <?= $totalBalance > 0 ? '#be123c' : '#64748b' ?>; border: 1px solid <?= $totalBalance > 0 ? '#fecdd3' : '#e2e8f0' ?>;">
                            <i class="bi bi-hourglass-split"></i>
                        </div>
                    </div>
                    <h3 class="fw-bold <?= $totalBalance > 0 ? 'text-danger' : 'text-dark' ?> mb-0 font-monospace" style="font-size: 1.55rem;">
                        ₹<?= number_format($totalBalance, 2) ?>
                    </h3>
                    <div class="text-muted small mt-1" style="font-size: 0.74rem;">
                        <?= $totalBalance > 0 ? 'Pending credit / discharge clearance' : 'No outstanding dues' ?>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-sm-6">
                <div class="kpi-card">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-muted small fw-bold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">Items Dispensed</span>
                        <div class="kpi-icon-wrap" style="background-color: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe;">
                            <i class="bi bi-capsule"></i>
                        </div>
                    </div>
                    <h3 class="fw-bold text-primary mb-0 font-monospace" style="font-size: 1.55rem;">
                        <?= $totalItemsCount ?> Units
                    </h3>
                    <div class="text-muted small mt-1 d-flex gap-2" style="font-size: 0.74rem;">
                        <span class="badge-item-med">💊 <?= $medicinesCount ?> Medicines</span>
                        <span class="badge-item-equip">🩺 <?= $equipmentCount ?> Equipments</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. Billing & Dispensing Transactions List -->
        <div class="card border shadow-sm rounded-4 mb-4 bg-white" style="border: 1px solid #e2e8f0 !important; border-radius: 14px !important;">
            <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                    <div class="d-inline-flex align-items-center justify-content-center shadow-xs" style="width: 36px; height: 36px; border-radius: 10px; background-color: #f0fdf4; color: #0d9488; border: 1px solid #99f6e4;">
                        <i class="bi bi-journal-text fs-5"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-dark" style="font-size: 1.1rem;">
                            All Pharmacy Bills &amp; Itemized Timeline
                        </h5>
                        <div class="text-muted small" style="font-size: 0.78rem;">
                            Chronological history of medicines, medical equipment, date/time, and credit/paid details.
                        </div>
                    </div>
                </div>
                <span class="badge bg-light text-secondary border font-monospace px-2.5 py-1.5" style="font-size: 0.80rem;">
                    <?= count($salesHistory) ?> Total Invoices
                </span>
            </div>

            <div class="card-body p-4">
                <?php if (empty($salesHistory)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-receipt fs-1 d-block mb-2 text-secondary opacity-50"></i>
                        <h6 class="fw-bold text-dark mb-1">No Pharmacy Bills Found for this Patient</h6>
                        <p class="small text-muted mb-3">No OPD receipts or IPD medication dispenses have been billed yet.</p>
                        <a href="<?= BASE_URL ?>modules/sales/counter.php?patient_id=<?= urlencode($patientInfo['patient_id']) ?>&patient_name=<?= urlencode($patientInfo['name']) ?>" class="btn btn-sm text-white rounded-pill px-3.5 py-1.5 fw-bold shadow-xs" style="background-color: #0d9488; border-color: #0d9488;">
                            <i class="bi bi-plus-lg me-1"></i> Create First Bill
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($salesHistory as $saleIdx => $sale): ?>
                        <?php
                            $isPaid = strtoupper($sale['payment_status']) === 'PAID';
                            $isCredit = strtoupper($sale['payment_status']) === 'CREDIT' || (float)$sale['balance_amount'] > 0;
                            $statusClass = $isPaid ? 'bg-success-subtle text-success border border-success-subtle' : ($isCredit ? 'bg-danger-subtle text-danger border border-danger-subtle' : 'bg-warning-subtle text-warning border border-warning-subtle');
                            
                            $saleTypeLabel = match($sale['sale_type']) {
                                'IPD_SALE' => 'IPD Regular Sale',
                                'COUNTER_SALE' => 'OPD Counter Sale',
                                'PRESCRIPTION_SALE' => 'Prescription Bill',
                                default => 'Pharmacy Sale'
                            };

                            $formattedDateTime = date('d-M-Y \a\t h:i A', strtotime($sale['created_at']));
                        ?>
                        <div class="bill-card">
                            <div class="bill-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <div class="d-flex align-items-center gap-3 flex-wrap">
                                    <span class="badge bg-white text-dark border font-monospace fw-bold px-2.5 py-1.5 fs-6 shadow-2xs">
                                        <i class="bi bi-receipt me-1 text-emerald"></i> <?= htmlspecialchars($sale['sale_number']) ?>
                                    </span>
                                    <span class="badge bg-light text-secondary border fw-semibold px-2 py-1" style="font-size: 0.76rem;">
                                        <?= htmlspecialchars($saleTypeLabel) ?>
                                    </span>
                                    <?php if (stripos($sale['notes'] ?? '', 'EDITED') !== false): ?>
                                        <span class="badge bg-warning text-dark border border-warning-subtle fw-bold px-2 py-1 shadow-2xs" style="font-size: 0.76rem;">
                                            <i class="bi bi-pencil-square me-1"></i> EDITED BILL
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($sale['status'] === 'CANCELLED'): ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle fw-bold px-2 py-1 shadow-2xs" style="font-size: 0.76rem;">
                                            <i class="bi bi-x-circle me-1"></i> CANCELLED / REPLACED
                                        </span>
                                    <?php endif; ?>
                                    <span class="text-muted small d-inline-flex align-items-center gap-1 font-monospace" style="font-size: 0.80rem;">
                                        <i class="bi bi-calendar3 text-secondary"></i> <?= $formattedDateTime ?>
                                    </span>
                                    <?php if (!empty($sale['doctor_name'])): ?>
                                        <span class="text-dark small fw-semibold" style="font-size: 0.80rem;">
                                            <i class="bi bi-person-badge text-emerald me-0.5"></i> <?= htmlspecialchars($sale['doctor_name']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="d-flex align-items-center gap-2">
                                    <span class="badge-pill-status <?= $statusClass ?>">
                                        <?= strtoupper($sale['payment_status']) ?> (<?= htmlspecialchars($sale['payment_mode']) ?>)
                                    </span>
                                    <?php if ($sale['status'] !== 'CANCELLED'): ?>
                                        <?php
                                            $editTargetModule = ($sale['sale_type'] === 'IPD_SALE') ? 'regular.php' : 'counter.php';
                                        ?>
                                        <a href="<?= BASE_URL ?>modules/sales/<?= $editTargetModule ?>?edit_sale_id=<?= $sale['sale_id'] ?>" class="btn btn-sm btn-outline-warning text-dark rounded-pill px-3 py-1 fw-bold shadow-2xs no-print d-inline-flex align-items-center gap-1" style="font-size: 0.78rem; border-color: #f59e0b; background-color: #fffbeb;" title="View bill and edit medicines">
                                            <i class="bi bi-pencil-square text-warning"></i> View / Edit Bill
                                        </a>
                                        <a href="<?= BASE_URL ?>modules/sales/returns.php?sale_id=<?= $sale['sale_id'] ?>" class="btn btn-sm btn-outline-danger text-danger rounded-pill px-3 py-1 fw-bold shadow-2xs no-print d-inline-flex align-items-center gap-1" style="font-size: 0.78rem; border-color: #fca5a5; background-color: #fef2f2;" title="Return medicines from this bill">
                                            <i class="bi bi-arrow-return-left"></i> Return Medicine
                                        </a>
                                    <?php endif; ?>
                                    <a href="<?= BASE_URL ?>modules/sales/invoice.php?id=<?= $sale['sale_id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1 fw-bold shadow-2xs no-print" style="font-size: 0.78rem;" title="Print official invoice">
                                        <i class="bi bi-printer text-primary"></i> Print Invoice
                                    </a>
                                </div>
                            </div>

                            <?php if (!empty($sale['notes']) || !empty($sale['cancellation_reason'])): ?>
                                <div class="px-3 py-1.5 border-bottom bg-warning-subtle text-dark small d-flex align-items-center gap-2 flex-wrap" style="font-size: 0.78rem;">
                                    <i class="bi bi-info-circle-fill text-warning"></i>
                                    <?php if (!empty($sale['notes'])): ?>
                                        <span><strong>Bill Remark:</strong> <?= htmlspecialchars($sale['notes']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($sale['cancellation_reason'])): ?>
                                        <span class="text-danger">&bull; <strong>Status Reason:</strong> <?= htmlspecialchars($sale['cancellation_reason']) ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <div class="p-3">
                                <!-- Financial Summary Strip for this Bill -->
                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2.5 pb-2 border-bottom bg-light rounded-2 px-3 py-2">
                                    <div class="small text-muted">
                                        Subtotal: <span class="text-dark fw-bold font-monospace">₹<?= number_format((float)$sale['subtotal_amount'], 2) ?></span>
                                        <?php if ((float)$sale['discount_amount'] > 0): ?>
                                            &bull; Discount: <span class="text-danger fw-bold font-monospace">-₹<?= number_format((float)$sale['discount_amount'], 2) ?></span>
                                        <?php endif; ?>
                                        &bull; GST: <span class="text-dark fw-bold font-monospace">₹<?= number_format((float)$sale['gst_amount'], 2) ?></span>
                                    </div>
                                    <div class="d-flex align-items-center gap-3 font-monospace" style="font-size: 0.88rem;">
                                        <div>Grand Total: <strong class="text-dark">₹<?= number_format((float)$sale['grand_total'], 2) ?></strong></div>
                                        <div class="text-success">Paid: <strong>₹<?= number_format((float)$sale['paid_amount'], 2) ?></strong></div>
                                        <?php if ((float)$sale['balance_amount'] > 0): ?>
                                            <div class="text-danger">Credited / Due: <strong>₹<?= number_format((float)$sale['balance_amount'], 2) ?></strong></div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Itemized Line Items Table -->
                                <div class="table-responsive border rounded-2 overflow-hidden">
                                    <table class="table table-sm table-hover align-middle mb-0" style="font-size: 0.82rem;">
                                        <thead class="table-light small text-muted text-uppercase" style="font-size: 0.70rem; letter-spacing: 0.4px;">
                                            <tr>
                                                <th style="width: 40px;" class="text-center">#</th>
                                                <th style="width: 100px;">TYPE</th>
                                                <th>MEDICINE / MEDICAL EQUIPMENT NAME</th>
                                                <th>DOSAGE / FORM</th>
                                                <th>BATCH &amp; EXPIRY</th>
                                                <th style="width: 80px;" class="text-center">QTY</th>
                                                <th style="width: 90px;" class="text-end">UNIT RATE</th>
                                                <th style="width: 100px;" class="text-end">TOTAL</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($sale['items'] as $itemIdx => $it): ?>
                                                <tr>
                                                    <td class="text-center text-muted font-monospace"><?= $itemIdx + 1 ?></td>
                                                    <td>
                                                        <?php if ($it['item_type'] === 'EQUIPMENT'): ?>
                                                            <span class="badge-item-equip"><i class="bi bi-bandaid me-0.5"></i> Equipment</span>
                                                        <?php else: ?>
                                                            <span class="badge-item-med"><i class="bi bi-capsule me-0.5"></i> Medicine</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="fw-bold text-dark"><?= htmlspecialchars($it['medicine_name'] ?: 'Item #' . $it['medicine_id']) ?></div>
                                                        <?php if (!empty($it['composition']) || !empty($it['generic_name'])): ?>
                                                            <div class="text-muted small" style="font-size: 0.70rem;"><?= htmlspecialchars($it['composition'] ?: $it['generic_name']) ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-muted">
                                                        <?= htmlspecialchars($it['dosage_form'] ?: 'Standard') ?>
                                                        <?= !empty($it['pack_size']) ? ' (' . htmlspecialchars($it['pack_size']) . ')' : '' ?>
                                                    </td>
                                                    <td class="font-monospace text-muted small" style="font-size: 0.74rem;">
                                                        <?= htmlspecialchars($it['batch_info']) ?>
                                                    </td>
                                                    <td class="text-center font-monospace fw-bold text-dark">
                                                        <?= (int)$it['quantity'] ?>
                                                    </td>
                                                    <td class="text-end font-monospace text-muted">
                                                        ₹<?= number_format((float)$it['unit_price'], 2) ?>
                                                    </td>
                                                    <td class="text-end font-monospace fw-bold text-dark">
                                                        ₹<?= number_format((float)$it['line_total'], 2) ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- 1-Month Bill Edit History & Comparative Snapshot Modal -->
<div class="modal fade" id="billVersionHistoryModal" tabindex="-1" aria-labelledby="billVersionHistoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-dark text-white py-3 px-4">
                <div class="d-flex align-items-center gap-2.5">
                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px; background: rgba(255,255,255,0.15);">
                        <i class="bi bi-clock-history fs-5 text-info"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="billVersionHistoryModalLabel">
                            Bill Edit History &amp; 30-Day Audit Archive
                        </h5>
                        <div class="text-white-50 small" style="font-size: 0.78rem;">
                            Historical medication versions retained in compliance with 1-month record policy
                        </div>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 bg-light" id="billHistoryModalContent">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary mb-2" role="status"></div>
                    <div class="text-muted small">Loading version history &amp; snapshot comparisons...</div>
                </div>
            </div>
            <div class="modal-footer bg-white border-top py-2 px-4 d-flex justify-content-between">
                <div class="small text-muted font-monospace" style="font-size: 0.75rem;">
                    <i class="bi bi-shield-check text-success me-1"></i> Automatic 30-day retention store
                </div>
                <button type="button" class="btn btn-sm btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function openBillVersionHistoryModal(saleId, saleNumber) {
    const modalEl = document.getElementById('billVersionHistoryModal');
    const contentEl = document.getElementById('billHistoryModalContent');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    
    contentEl.innerHTML = `
        <div class="text-center py-5">
            <div class="spinner-border text-primary mb-2" role="status"></div>
            <div class="text-muted small">Loading 30-day archive for Bill #${saleNumber}...</div>
        </div>
    `;
    modal.show();

    fetch(`<?= BASE_URL ?>modules/patients/history.php?ajax_bill_history=1&sale_id=${saleId}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success || !data.current_sale) {
                contentEl.innerHTML = `
                    <div class="alert alert-warning text-center p-4">
                        <i class="bi bi-exclamation-triangle fs-3 d-block mb-2"></i>
                        <strong>No historical edit versions found</strong>
                        <div class="small text-muted mt-1">This bill has not been modified or past versions have exceeded the 30-day retention window.</div>
                    </div>
                `;
                return;
            }

            renderBillHistoryComparison(data.current_sale, data.history_versions || []);
        })
        .catch(err => {
            contentEl.innerHTML = `
                <div class="alert alert-danger text-center p-4">
                    <i class="bi bi-x-circle fs-3 d-block mb-2"></i>
                    <strong>Error loading history archive</strong>
                    <div class="small text-muted mt-1">${err.message || 'Server communication error'}</div>
                </div>
            `;
        });
}

function renderBillHistoryComparison(currSale, histories) {
    const contentEl = document.getElementById('billHistoryModalContent');
    
    if (histories.length === 0) {
        contentEl.innerHTML = `
            <div class="card border rounded-3 p-4 text-center bg-white shadow-xs">
                <i class="bi bi-info-circle fs-2 text-info d-block mb-2"></i>
                <h6 class="fw-bold text-dark">Active Bill #${currSale.sale_number}</h6>
                <p class="text-muted small mb-0">This bill was generated recently. Prior pre-edit snapshots are either archived or this is the first revision.</p>
            </div>
        `;
        return;
    }

    let html = `
        <!-- Top Status Banner -->
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 bg-white p-3 rounded-3 border shadow-2xs">
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-success text-white font-monospace px-2 py-1">CURRENT ACTIVE: ${escapeHtml(currSale.sale_number)}</span>
                <span class="text-muted small">&bull; Total Versions Tracked: <strong>${histories.length + 1}</strong></span>
            </div>
            <div class="d-flex align-items-center gap-1.5 text-muted small">
                <i class="bi bi-hourglass-split text-warning"></i>
                <span>Retention: <strong class="text-dark">${histories[0].days_remaining !== undefined ? histories[0].days_remaining : 30} days</strong> remaining in active 1-month store</span>
            </div>
        </div>
    `;

    // Render Version Accordions
    histories.forEach((h, idx) => {
        const vDate = new Date(h.created_at).toLocaleString();
        const expDate = new Date(h.retention_until).toLocaleDateString();
        
        html += `
            <div class="card border rounded-3 mb-3 bg-white shadow-xs overflow-hidden">
                <div class="card-header bg-warning-subtle border-bottom py-2.5 px-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-warning text-dark border border-warning-subtle fw-bold font-monospace px-2 py-1">
                            PRE-EDIT SNAPSHOT (v${h.version_number})
                        </span>
                        <strong class="text-dark font-monospace" style="font-size: 0.88rem;">#${escapeHtml(h.original_sale_number)}</strong>
                        <span class="text-muted small">&bull; Modified on: <strong>${vDate}</strong></span>
                        ${h.edited_by_name ? `<span class="badge bg-light text-secondary border font-monospace" style="font-size: 0.70rem;"><i class="bi bi-person me-0.5"></i>${escapeHtml(h.edited_by_name)}</span>` : ''}
                    </div>
                    <div class="small text-muted font-monospace" style="font-size: 0.74rem;">
                        Retained until: <strong>${expDate}</strong>
                    </div>
                </div>

                <div class="card-body p-3">
                    <!-- Financial Difference Summary -->
                    <div class="row g-2 mb-3 text-center">
                        <div class="col-sm-3 col-6">
                            <div class="p-2 border rounded-2 bg-light">
                                <div class="text-muted small" style="font-size: 0.70rem;">PRE-EDIT SUBTOTAL</div>
                                <div class="fw-bold font-monospace">₹${parseFloat(h.subtotal_amount).toFixed(2)}</div>
                            </div>
                        </div>
                        <div class="col-sm-3 col-6">
                            <div class="p-2 border rounded-2 bg-light">
                                <div class="text-muted small" style="font-size: 0.70rem;">PRE-EDIT GST</div>
                                <div class="fw-bold font-monospace">₹${parseFloat(h.gst_amount).toFixed(2)}</div>
                            </div>
                        </div>
                        <div class="col-sm-3 col-6">
                            <div class="p-2 border rounded-2 bg-light">
                                <div class="text-muted small" style="font-size: 0.70rem;">PRE-EDIT GRAND TOTAL</div>
                                <div class="fw-bold text-danger font-monospace">₹${parseFloat(h.grand_total).toFixed(2)}</div>
                            </div>
                        </div>
                        <div class="col-sm-3 col-6">
                            <div class="p-2 border rounded-2 bg-success-subtle text-dark border-success-subtle">
                                <div class="text-muted small" style="font-size: 0.70rem;">CURRENT UPDATED TOTAL</div>
                                <div class="fw-bold text-success font-monospace">₹${parseFloat(currSale.grand_total).toFixed(2)}</div>
                            </div>
                        </div>
                    </div>

                    <!-- Items Table Snapshot -->
                    <div class="table-responsive border rounded-2">
                        <table class="table table-sm table-hover align-middle mb-0" style="font-size: 0.80rem;">
                            <thead class="table-light small text-muted text-uppercase" style="font-size: 0.70rem;">
                                <tr>
                                    <th style="width: 35px;" class="text-center">#</th>
                                    <th>MEDICINE / ITEM NAME</th>
                                    <th>BATCH &amp; EXPIRY</th>
                                    <th style="width: 70px;" class="text-center">PRE-QTY</th>
                                    <th style="width: 90px;" class="text-end">UNIT RATE</th>
                                    <th style="width: 95px;" class="text-end">LINE TOTAL</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${(h.items || []).map((it, itIdx) => `
                                    <tr>
                                        <td class="text-center text-muted font-monospace">${itIdx + 1}</td>
                                        <td>
                                            <div class="fw-bold text-dark">${escapeHtml(it.medicine_name || 'Item #' + it.medicine_id)}</div>
                                            ${it.composition ? `<div class="small text-muted" style="font-size: 0.68rem;">${escapeHtml(it.composition)}</div>` : ''}
                                        </td>
                                        <td class="font-monospace text-muted small" style="font-size: 0.72rem;">
                                            ${escapeHtml(it.batch_number || 'GEN-01')} ${it.expiry_date ? '(' + escapeHtml(it.expiry_date) + ')' : ''}
                                        </td>
                                        <td class="text-center font-monospace fw-bold text-dark">${it.quantity}</td>
                                        <td class="text-end font-monospace text-muted">₹${parseFloat(it.unit_price).toFixed(2)}</td>
                                        <td class="text-end font-monospace fw-bold text-dark">₹${parseFloat(it.line_total).toFixed(2)}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;
    });

    contentEl.innerHTML = html;
}
</script>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
