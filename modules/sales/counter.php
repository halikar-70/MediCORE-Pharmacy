<?php
// modules/sales/counter.php - Counter Sale (OPD / Walk-in) Point of Sale (POS)

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/DocumentSequenceService.php';
require_once __DIR__ . '/../../app/Services/StockLedgerService.php';
require_once __DIR__ . '/../../app/Services/FefoService.php';
require_once __DIR__ . '/../../app/Services/AuditService.php';
require_once __DIR__ . '/../../app/Services/SalesService.php';

use Pharmacy\Services\SalesService;
use Pharmacy\Services\FefoService;

require_permission('pharmacy.sales.view');

$salesService = new SalesService($pdo);
$fefoService = new FefoService($pdo);

// AJAX Endpoint: Medicine Search
if (isset($_GET['ajax_search'])) {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    $results = $salesService->searchMedicines($q, 15);
    echo json_encode(['success' => true, 'data' => $results]);
    exit;
}

// AJAX Endpoint: Preview FEFO Allocation
if (isset($_GET['ajax_preview_fefo'])) {
    header('Content-Type: application/json');
    $medId = (int)($_GET['medicine_id'] ?? 0);
    $qty = (int)($_GET['qty'] ?? 0);
    try {
        $allocations = $fefoService->previewAllocation($medId, $qty);
        echo json_encode(['success' => true, 'allocations' => $allocations]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$error = null;
$success = null;
$completedSale = null;

// AJAX: Fast Patient Registration for OPD Counter
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajax_new_opd_patient') {
    header('Content-Type: application/json');
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Security token invalid or expired.']);
        exit;
    }

    try {
        $name = trim($_POST['patient_name'] ?? '');
        $mobile = trim($_POST['mobile'] ?? '');
        $gender = trim($_POST['gender'] ?? 'Male');
        $doctor = trim($_POST['doctor_name'] ?? '');

        if ($name === '') {
            echo json_encode(['success' => false, 'message' => 'Patient name is required.']);
            exit;
        }

        $uhid = 'VH-' . date('Y') . '-' . str_pad((string)rand(100, 9999), 4, '0', STR_PAD_LEFT);
        $newPatientNo = 'PAT-' . date('Ymd') . '-' . rand(1000, 9999);

        $hospitalPatientId = null;
        try {
            $hospitalPdo = \Pharmacy\Database\Database::getHospitalConnection();
            if ($hospitalPdo) {
                $hPatStmt = $hospitalPdo->prepare("INSERT INTO patients (first_name, last_name, phone, gender, patient_code, status, created_at, updated_at) VALUES (?, '', ?, ?, ?, 'Active', NOW(), NOW())");
                $hPatStmt->execute([$name, $mobile, $gender, $uhid]);
                $hospitalPatientId = (int)$hospitalPdo->lastInsertId();
            }
        } catch (Exception $hex) {
            // Fallback gracefully
        }

        $insStmt = $pdo->prepare("
            INSERT INTO pharmacy_patients (pharmacy_patient_no, hospital_patient_id, hospital_uhid, name, mobile, gender, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 'Active', NOW(), NOW())
        ");
        $insStmt->execute([$newPatientNo, $hospitalPatientId, $uhid, $name, $mobile, $gender]);
        $newPharmacyPatientId = (int)$pdo->lastInsertId();

        echo json_encode([
            'success' => true,
            'message' => "Patient {$name} registered successfully!",
            'patient' => [
                'id'                  => $newPharmacyPatientId,
                'name'                => $name,
                'hospital_uhid'       => $uhid,
                'pharmacy_patient_no' => $newPatientNo,
                'mobile'              => $mobile,
                'doctor_name'         => $doctor,
                'label'               => "{$uhid} - {$name}" . ($mobile ? " ({$mobile})" : "")
            ]
        ]);
        exit;
    } catch (Exception $ex) {
        echo json_encode(['success' => false, 'message' => 'Error registering patient: ' . $ex->getMessage()]);
        exit;
    }
}

// POST: Process Sale Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_sale') {
    require_permission('pharmacy.sales.create');

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid or expired security token. Please refresh and try again.";
    } else {
        try {
            $customerName = trim($_POST['customer_name'] ?? 'Walk-in Customer');
            $customerMobile = trim($_POST['customer_mobile'] ?? '');
            $doctorName = trim($_POST['doctor_name'] ?? '');
            $discountType = strtoupper(trim($_POST['discount_type'] ?? 'PERCENT'));
            $discountVal = max(0.0, (float)($_POST['discount_value'] ?? 0.0));
            $discountPercent = (float)($_POST['discount_percent'] ?? 0.0);
            $paymentMode = trim($_POST['payment_mode'] ?? 'CASH');
            $paidAmount = (float)($_POST['paid_amount'] ?? 0.0);
            $paymentRef = trim($_POST['payment_ref'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            $idempotencyKey = trim($_POST['idempotency_key'] ?? '');

            // Decode cart items
            $rawItemsJson = $_POST['cart_items'] ?? '[]';
            $cartItems = json_decode($rawItemsJson, true);

            if (empty($cartItems) || !is_array($cartItems)) {
                throw new Exception("Your cart is empty. Please select at least one medicine.");
            }

            // If FLAT discount mode, accurately calculate effective percentage from raw items subtotal
            if ($discountType === 'FLAT' && $discountVal > 0) {
                $cartSubtotal = 0.0;
                foreach ($cartItems as $cItem) {
                    $medId = (int)$cItem['medicine_id'];
                    $q = (int)$cItem['quantity'];
                    $mStmt = $pdo->prepare("SELECT price FROM medicines WHERE medicine_id = ?");
                    $mStmt->execute([$medId]);
                    $price = (float)$mStmt->fetchColumn();
                    $cartSubtotal += ($price * $q);
                }
                if ($cartSubtotal > 0) {
                    $discountPercent = min(50.0, round(($discountVal / $cartSubtotal) * 100.0, 4));
                }
            }

            $itemsPayload = [];
            foreach ($cartItems as $cItem) {
                $pItem = [
                    'medicine_id'      => (int)$cItem['medicine_id'],
                    'quantity'         => (int)$cItem['quantity'],
                    'discount_percent' => (float)($cItem['discount_percent'] ?? 0.0)
                ];
                if (!empty($cItem['batch_id'])) {
                    $pItem['batch_id'] = (int)$cItem['batch_id'];
                }
                $itemsPayload[] = $pItem;
            }

            $hasOverride = has_permission('pharmacy.discount.override');

            $saleData = [
                'sale_type'        => 'COUNTER_SALE',
                'customer_name'    => $customerName !== '' ? $customerName : 'Walk-in Customer',
                'customer_mobile'  => $customerMobile !== '' ? $customerMobile : null,
                'doctor_name'      => $doctorName !== '' ? $doctorName : null,
                'discount_percent' => $discountPercent,
                'idempotency_key'  => $idempotencyKey !== '' ? $idempotencyKey : null,
                'notes'            => $notes !== '' ? $notes : null
            ];

            $paymentData = [
                'amount'    => $paidAmount,
                'mode'      => $paymentMode,
                'reference' => $paymentRef
            ];

            $completedSale = $salesService->createSale(
                $saleData,
                $itemsPayload,
                $paymentData,
                $_SESSION['user_id'] ?? null,
                $hasOverride
            );

            $success = "Sale completed successfully! Invoice #{$completedSale['sale_number']} generated.";
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Print mode
if (isset($_GET['print_id'])) {
    $_GET['id'] = $_GET['print_id'];
    require __DIR__ . '/invoice.php';
    exit;
}

$page_title = 'Create OPD Bill';

// Fetch Active Patients for Bill Information (Hospital Patients + Local Pharmacy Patients)
$patients = [];
try {
    $hospitalPdo = \Pharmacy\Database\Database::getHospitalConnection();
    if ($hospitalPdo) {
        $hPatients = $hospitalPdo->query("
            SELECT 
                patient_id as id,
                COALESCE(patient_code, CONCAT('VH', patient_id)) as hospital_uhid,
                COALESCE(patient_code, CONCAT('VH', patient_id)) as pharmacy_patient_no,
                CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) as name,
                COALESCE(phone, '') as mobile
            FROM patients
            WHERE (status IS NULL OR status = 'Active' OR status = 1)
            ORDER BY patient_id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($hPatients as $hp) {
            $hp['name'] = trim($hp['name']);
            if ($hp['name'] !== '') {
                $patients[] = $hp;
            }
        }
    }
} catch (Exception $e) {}

// Also merge any local pharmacy patients
try {
    $localPatients = $pdo->query("
        SELECT id, pharmacy_patient_no, hospital_uhid, name, mobile
        FROM pharmacy_patients
        WHERE status = 'Active'
        ORDER BY name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $existingUhids = array_flip(array_filter(array_column($patients, 'hospital_uhid')));
    foreach ($localPatients as $lp) {
        if (empty($lp['hospital_uhid']) || !isset($existingUhids[$lp['hospital_uhid']])) {
            $patients[] = $lp;
        }
    }
} catch (Exception $e) {}

// Available Doctors for selection from Hospital DB
$availableDoctors = [];
try {
    if ($hospitalPdo) {
        $availableDoctors = $hospitalPdo->query("SELECT name FROM doctors WHERE status = 'Active' ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Exception $e) {}
if (empty($availableDoctors)) {
    $availableDoctors = ['Dr. Suhas Londhe', 'Dr. Vaishali Londhe', 'Dr. Ujwala Deshmukh', 'Dr. Rohan Mhaske'];
}

// Fetch Active Medicines for Charge Items with all active batches (ordered by FEFO earliest expiry first)
$medRows = $pdo->query("
    SELECT m.medicine_id, m.medicine_name, m.generic_name, m.dosage_form, m.category, m.unit, m.strength,
           m.manufacturer, m.price as mrp, m.price, COALESCE(m.purchase_price, 0) as purchase_price,
           m.gst_percent,
           mb.batch_id,
           COALESCE(mb.batch_number, m.batch_number, 'GEN-01') as batch_number,
           COALESCE(DATE_FORMAT(mb.expiry_date, '%Y-%m-%d'), DATE_FORMAT(m.expiry_date, '%Y-%m-%d'), '2027-12-31') as expiry_date,
           COALESCE(DATE_FORMAT(mb.manufacturing_date, '%Y-%m-%d'), '2026-06-01') as manufacturing_date,
           COALESCE(mb.purchase_price, m.purchase_price, 0) as batch_purchase_price,
           COALESCE(mb.sale_price, mb.mrp, m.price, 0) as batch_sale_price,
           COALESCE(mb.quantity_available, m.stock_quantity, 0) as batch_stock
    FROM medicines m
    LEFT JOIN medicine_batches mb ON mb.medicine_id = m.medicine_id
         AND mb.status = 'Active'
         AND mb.quantity_available > 0
         AND mb.expiry_date >= CURDATE()
    WHERE m.status = 'Active'
    ORDER BY m.medicine_name ASC, mb.expiry_date ASC, mb.batch_id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$allMedicines = [];
foreach ($medRows as $r) {
    $mid = (int)$r['medicine_id'];
    if (!isset($allMedicines[$mid])) {
        $allMedicines[$mid] = [
            'medicine_id'     => $mid,
            'medicine_name'   => $r['medicine_name'],
            'generic_name'    => $r['generic_name'],
            'dosage_form'     => $r['dosage_form'],
            'category'        => $r['category'],
            'unit'            => $r['unit'],
            'strength'        => $r['strength'],
            'manufacturer'    => $r['manufacturer'],
            'mrp'             => (float)$r['mrp'],
            'price'           => (float)$r['price'],
            'purchase_price'  => (float)$r['purchase_price'],
            'gst_percent'     => (float)$r['gst_percent'],
            'stock_quantity'  => 0,
            'available_stock' => 0,
            'batches'         => []
        ];
    }
    if (!empty($r['batch_number'])) {
        $allMedicines[$mid]['batches'][] = [
            'batch_id'           => (int)($r['batch_id'] ?? 0),
            'batch_number'       => $r['batch_number'],
            'expiry_date'        => $r['expiry_date'],
            'manufacturing_date' => $r['manufacturing_date'],
            'purchase_price'     => (float)$r['batch_purchase_price'],
            'sale_price'         => (float)$r['batch_sale_price'],
            'stock'              => (int)$r['batch_stock']
        ];
        $allMedicines[$mid]['stock_quantity'] += (int)$r['batch_stock'];
        $allMedicines[$mid]['available_stock'] += (int)$r['batch_stock'];
    }
}
$allMedicines = array_values($allMedicines);

// Set default top-level batch info as the earliest expiring batch (FEFO)
foreach ($allMedicines as &$m) {
    if (!empty($m['batches'])) {
        $firstBatch = $m['batches'][0];
        $m['batch_id']           = $firstBatch['batch_id'];
        $m['batch_number']       = $firstBatch['batch_number'];
        $m['expiry_date']        = $firstBatch['expiry_date'];
        $m['manufacturing_date'] = $firstBatch['manufacturing_date'];
        $m['purchase_price']     = $firstBatch['purchase_price'];
        $m['price']              = $firstBatch['sale_price'];
    } else {
        $m['batch_id']           = 0;
        $m['batch_number']       = 'GEN-01';
        $m['expiry_date']        = '2028-12-31';
        $m['manufacturing_date'] = '2026-01-01';
    }
}
unset($m);

// Unique categories
$categories = [];
foreach ($allMedicines as $m) {
    $cat = trim($m['category'] ?? '');
    if ($cat !== '' && !in_array($cat, $categories)) {
        $categories[] = $cat;
    }
}
if (empty($categories)) {
    $categories = ['Tablets', 'Syrups', 'Injections', 'Infusions', 'Ointments', 'Consumables', 'General'];
}

$currentUser = auth_user();

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
include __DIR__ . '/../../includes/navbar.php';
?>

<style>
/* Dedicated High-Priority Medicine Search Autocomplete Styles */
.medicine-suggestions-menu {
    border-radius: 12px !important;
    border: 1px solid #cbd5e1 !important;
    box-shadow: 0 20px 45px -8px rgba(15, 23, 42, 0.28), 0 8px 18px -4px rgba(15, 23, 42, 0.12) !important;
    background: #ffffff !important;
    background-color: #ffffff !important;
    max-height: 380px !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    margin-top: 6px !important;
    padding: 0 !important;
    z-index: 1090 !important;
}
.med-suggest-item {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    padding: 10px 14px !important;
    border-bottom: 1px solid #f1f5f9 !important;
    background: #ffffff !important;
    background-color: #ffffff !important;
    cursor: pointer !important;
    transition: all 0.15s ease !important;
}
.med-suggest-item:hover, .med-suggest-item.active-nav {
    background-color: #f0fdf4 !important;
    border-left: 3px solid #0d9488 !important;
}
.search-highlight {
    background: transparent !important;
    background-color: transparent !important;
    color: #059669 !important;
    font-weight: 700 !important;
    padding: 0 !important;
}
.patient-match-color {
    background: transparent !important;
    background-color: transparent !important;
    color: #059669 !important;
    font-weight: 800 !important;
    padding: 0 1px !important;
}

/* Smooth keyboard sliding highlight for cart rows */
.cart-row {
    transition: background-color 0.2s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
    outline: none !important;
}
.cart-row:focus, .cart-row.active-row-focus {
    background-color: #f0fdf4 !important;
    box-shadow: inset 4px 0 0 #059669, 0 4px 14px rgba(5, 150, 105, 0.12) !important;
}
.cart-row:hover {
    background-color: #f8fafc;
}

/* Interactive Batch & Expiry Switcher Dropdown */
.batch-dropdown-container {
    position: relative;
    display: inline-block;
}
.expiry-picker-btn {
    transition: all 0.2s ease !important;
    cursor: pointer !important;
}
.expiry-picker-btn:hover {
    filter: brightness(0.95);
    transform: translateY(-1px);
    box-shadow: 0 3px 8px rgba(0, 0, 0, 0.1) !important;
}
.batch-expiry-menu {
    border-radius: 10px !important;
    border: 1px solid #cbd5e1 !important;
    background: #ffffff !important;
    box-shadow: 0 16px 36px -4px rgba(15, 23, 42, 0.22) !important;
    min-width: 340px !important;
    max-width: 380px !important;
    z-index: 1090 !important;
}
.batch-option-item {
    transition: all 0.15s ease !important;
    border-radius: 8px !important;
    border: 1px solid transparent !important;
}
.batch-option-item:hover {
    background-color: #f1f5f9 !important;
}
.batch-option-item.active-batch {
    background-color: #f0fdf4 !important;
    border-color: #059669 !important;
}

/* Dedicated Patient Suggestions Dropdown Styling */
.patient-suggestions-menu {
    border-radius: 12px !important;
    border: 1px solid #cbd5e1 !important;
    box-shadow: 0 20px 45px -8px rgba(15, 23, 42, 0.22), 0 8px 18px -4px rgba(15, 23, 42, 0.08) !important;
    background: #ffffff !important;
    background-color: #ffffff !important;
    max-height: 360px !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    margin-top: 6px !important;
    padding: 0 !important;
    z-index: 1095 !important;
    max-height: 400px !important;
}

.patient-dropdown-header {
    position: sticky !important;
    top: 0 !important;
    background: #f8fafc !important;
    border-bottom: 1px solid #e2e8f0 !important;
    padding: 8px 14px !important;
    z-index: 20 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 8px !important;
    flex-wrap: wrap !important;
}

.pat-filter-btn {
    font-size: 0.74rem !important;
    font-weight: 600 !important;
    padding: 4px 11px !important;
    border-radius: 9999px !important;
    border: 1px solid #cbd5e1 !important;
    background: #ffffff !important;
    color: #475569 !important;
    cursor: pointer !important;
    transition: all 0.15s ease !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 4px !important;
    user-select: none !important;
}

.pat-filter-btn:hover {
    background: #f1f5f9 !important;
    color: #0f172a !important;
    border-color: #94a3b8 !important;
}

.pat-filter-btn.active {
    background: #059669 !important;
    color: #ffffff !important;
    border-color: #059669 !important;
    box-shadow: 0 1px 3px rgba(5, 150, 105, 0.3) !important;
}

.patient-suggest-item {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    padding: 12px 18px !important;
    border-bottom: 1px solid #f1f5f9 !important;
    background: #ffffff !important;
    background-color: #ffffff !important;
    cursor: pointer !important;
    transition: all 0.15s ease !important;
}

.patient-suggest-item:last-child {
    border-bottom: none !important;
}

.patient-suggest-item:hover, .patient-suggest-item.active-nav {
    background-color: #f0fdf4 !important;
    border-left: 4px solid #059669 !important;
    padding-left: 15px !important;
}

.patient-suggest-item .pat-avatar {
    width: 38px !important;
    height: 38px !important;
    min-width: 38px !important;
    border-radius: 50% !important;
    background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%) !important;
    color: #059669 !important;
    border: 1px solid #a7f3d0 !important;
    font-weight: 700 !important;
    font-size: 0.95rem !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
    box-shadow: 0 1px 2px rgba(5, 150, 105, 0.12) !important;
}

.patient-suggest-item .pat-name {
    font-size: 0.95rem !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    line-height: 1.25 !important;
    margin-bottom: 4px !important;
}

.patient-suggest-item .pat-meta {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    flex-wrap: wrap !important;
    font-size: 0.76rem !important;
    color: #64748b !important;
}

.patient-suggest-item .pat-select-btn {
    font-size: 0.76rem !important;
    font-weight: 600 !important;
    padding: 5px 14px !important;
    border-radius: 9999px !important;
    border: 1px solid #a7f3d0 !important;
    background: #ecfdf5 !important;
    color: #059669 !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 4px !important;
    transition: all 0.15s ease !important;
    white-space: nowrap !important;
}

.patient-suggest-item:hover .pat-select-btn,
.patient-suggest-item.active-nav .pat-select-btn {
    background: #059669 !important;
    color: #ffffff !important;
    border-color: #059669 !important;
}

.patient-dropdown-footer {
    padding: 10px 18px !important;
    background: #f8fafc !important;
    border-top: 1px solid #e2e8f0 !important;
    font-size: 0.76rem !important;
    color: #64748b !important;
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
}
/* Dynamic Expiry Badges (Green -> Amber -> Red) */
.badge-exp-safe {
    background-color: #dcfce7 !important;
    color: #166534 !important;
    border: 1px solid #86efac !important;
    font-weight: 600 !important;
}
.badge-exp-warning {
    background-color: #fef3c7 !important;
    color: #b45309 !important;
    border: 1px solid #fcd34d !important;
    font-weight: 600 !important;
}
.badge-exp-critical {
    background-color: #fee2e2 !important;
    color: #b91c1c !important;
    border: 1px solid #f87171 !important;
    font-weight: 700 !important;
}
.badge-exp-expired {
    background-color: #fef2f2 !important;
    color: #dc2626 !important;
    border: 1px solid #ef4444 !important;
    font-weight: 800 !important;
}
</style>

<div class="container-fluid py-3 px-4">
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-xs border-danger-subtle mb-3" role="alert">
            <i class="ti ti-alert-circle me-2"></i><?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show d-flex justify-content-between align-items-center shadow-sm border-success-subtle mb-3 p-3" role="alert">
            <div class="d-flex align-items-center gap-2">
                <i class="ti ti-check-circle fs-4 text-success"></i>
                <div>
                    <strong><?= htmlspecialchars($success) ?></strong>
                    <div class="small text-muted">The GST Tax Invoice has been generated and is ready to print.</div>
                </div>
            </div>
            <?php if ($completedSale): ?>
                <div class="d-flex align-items-center gap-2">
                    <a href="invoice.php?id=<?= $completedSale['sale_id'] ?>&autoprint=1" target="_blank" class="btn btn-sm btn-success fw-bold text-white shadow-sm px-3 py-1.5 d-inline-flex align-items-center gap-1">
                        <i class="ti ti-printer"></i> Print GST Bill
                    </a>
                    <a href="counter.php" class="btn btn-sm btn-outline-secondary px-3 py-1.5">Next OPD Bill</a>
                </div>
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const printWin = window.open('invoice.php?id=<?= (int)$completedSale['sale_id'] ?>&autoprint=1', '_blank');
                    });
                </script>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Main Create OPD Bill Card (Unified Full-Width Tab) -->
    <div class="card border shadow-sm rounded-4 mb-4 bg-white" style="border: 1px solid #e2e8f0 !important; border-radius: 14px !important;">
        <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="d-inline-flex align-items-center justify-content-center shadow-xs" style="width: 44px; height: 44px; border-radius: 12px; background-color: #ecfdf5; color: #059669; border: 1px solid #a7f3d0;">
                    <i class="bi bi-file-earmark-medical fs-4"></i>
                </div>
                <div>
                    <h4 class="fw-bold mb-0 text-dark" style="font-size: 1.25rem; letter-spacing: -0.2px;">Create OPD Bill</h4>
                    <div class="text-muted small" style="font-size: 0.8rem;">
                        Outpatient Dispensing &amp; Counter POS Billing
                    </div>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <button type="button" onclick="resetBilling()" class="btn btn-sm rounded-pill px-3 py-1.5 fw-semibold d-inline-flex align-items-center gap-1 shadow-xs" style="font-size: 0.82rem; background-color: #f0fdf4; color: #059669; border: 1px solid #bbf7d0;">
                    <i class="ti ti-circle-x"></i> New Billing
                </button>
                <a href="<?= BASE_URL ?>modules/sales/monitoring.php" class="btn btn-sm btn-link text-muted text-decoration-none fw-semibold d-inline-flex align-items-center gap-1" style="font-size: 0.82rem;">
                    &larr; Back to Visits
                </a>
            </div>
        </div>

        <div class="card-body p-4">
            <!-- Form for backend submission -->
            <form method="POST" id="posSaleForm">
                <input type="hidden" name="action" value="complete_sale">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="cart_items" id="cartItemsJson" value="[]">
                <input type="hidden" name="idempotency_key" value="<?= bin2hex(random_bytes(16)) ?>">
                
                <!-- Hidden inputs passed to backend from modal / controls -->
                <input type="hidden" name="customer_name" id="hiddenCustomerName" value="Walk-in Customer">
                <input type="hidden" name="customer_mobile" id="hiddenCustomerMobile" value="">
                <input type="hidden" name="doctor_name" id="hiddenDoctorName" value="">
                <input type="hidden" name="discount_type" id="hiddenDiscountType" value="PERCENT">
                <input type="hidden" name="discount_percent" id="hiddenDiscountPercent" value="0.0">
                <input type="hidden" name="discount_value" id="hiddenDiscountValue" value="0.0">
                <input type="hidden" name="payment_mode" id="hiddenPaymentMode" value="CASH">
                <input type="hidden" name="paid_amount" id="hiddenPaidAmount" value="0.0">
                <input type="hidden" name="payment_ref" id="hiddenPaymentRef" value="">
                <input type="hidden" name="notes" id="hiddenNotes" value="">

                <!-- Unified Progressive Dispensing Flow -->
                <div class="unified-dispensing-container">
                    
                    <!-- STEP 1: PATIENT SEARCH SECTION -->
                    <div id="patientSearchSection" class="p-3 bg-light rounded-3 border mb-3" style="background-color: #fbfcfd !important;">
                        <div class="d-flex justify-content-between align-items-center mb-1.5">
                            <label class="form-label small fw-semibold text-muted mb-0">
                                <i class="ti ti-user-search text-emerald me-1"></i> Patient / Customer <span class="text-danger">*</span> <kbd class="kbd-chip ms-1">F4</kbd>
                            </label>
                            <button type="button" class="btn btn-sm py-1 px-3 text-decoration-none fw-semibold d-inline-flex align-items-center gap-1 rounded-pill" onclick="openNewOpdModal()" style="background-color: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; font-size: 0.78rem;" title="Register a new patient">
                                <i class="ti ti-plus"></i> New Register
                            </button>
                        </div>
                        <div class="position-relative">
                            <div class="input-group">
                                <span class="input-group-text bg-white text-muted border-end-0">
                                    <i class="ti ti-search text-emerald"></i>
                                </span>
                                <input type="text" 
                                       id="counterPatientSearchInput" 
                                       class="form-control form-control-lg fs-6 bg-white border-start-0 ps-1" 
                                       placeholder="Type patient name to search or select from list..." 
                                       autocomplete="off" 
                                       oninput="onCounterPatientSearchInput(this.value)" 
                                       onfocus="onCounterPatientSearchFocus()" 
                                       onkeydown="onCounterPatientSearchKeydown(event)">
                                <button type="button" 
                                        id="btnClearCounterPatient" 
                                        class="btn btn-outline-secondary border-start-0 border-light-subtle d-none" 
                                        onclick="resetToWalkInCustomer()" 
                                        title="Reset to Walk-in Customer">
                                    <i class="ti ti-x"></i>
                                </button>
                            </div>

                            <!-- Floating Live Patient Suggestions Dropdown -->
                            <div id="counterPatientSuggestionsList" 
                                 class="patient-suggestions-menu position-absolute d-none" 
                                 style="min-width: 100%; width: 100%; left: 0;">
                            </div>
                        </div>
                        <div class="form-text text-muted mt-1.5 d-flex justify-content-between align-items-center flex-wrap gap-1" style="font-size: 0.75rem;">
                            <span><i class="ti ti-info-circle me-1"></i>Search patient by name / UHID or default to Walk-in Customer.</span>
                            <span>Patient not in list? <a href="javascript:void(0)" onclick="openNewOpdModal()" class="fw-bold text-decoration-none text-emerald"><i class="ti ti-plus"></i> Register Patient</a></span>
                        </div>
                    </div>

                    <!-- STEP 2: SELECTED PATIENT INFO CARD -->
                    <div id="selectedPatientInfoCard" class="p-3 rounded-2 border mb-3 shadow-xs bg-white" style="border-color: #e2e8f0 !important;">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 pb-2 mb-2 border-bottom border-light-subtle">
                            <div class="d-flex align-items-center gap-2.5">
                                <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold shadow-xs bg-emerald" style="width: 38px; height: 38px; font-size: 1rem;" id="cardPatientAvatar">
                                    W
                                </div>
                                <div>
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <h6 class="fw-bold text-dark mb-0 fs-6" id="cardPatientName">Walk-in Customer</h6>
                                        <span class="badge bg-emerald text-white fw-bold px-2 py-0.5" style="font-size: 0.70rem;" id="cardPatientBadge"><i class="ti ti-check me-1"></i>Selected OPD Patient</span>
                                    </div>
                                    <div class="text-muted small mt-0.5" style="font-size: 0.74rem;" id="cardPatientMeta">UHID: Direct / OPD | Mobile: -</div>
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-3 rounded-pill fw-semibold d-inline-flex align-items-center gap-1 shadow-xs" onclick="clearSelectedPatient()" style="font-size: 0.78rem;">
                                    <i class="ti ti-arrows-exchange"></i> Change Patient
                                </button>
                            </div>
                        </div>

                        <!-- Patient Details Row -->
                        <div class="row g-2 pt-1 align-items-center">
                            <div class="col-md-3 col-sm-6">
                                <div class="p-2 bg-white rounded-2 border border-light-subtle">
                                    <div class="text-muted small fw-semibold" style="font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.4px;">Patient ID / UHID</div>
                                    <input type="text" id="displayPatientUhid" class="form-control-plaintext fw-bold text-dark p-0 font-monospace" style="font-size: 0.88rem;" value="OPD-DIRECT" readonly>
                                </div>
                            </div>
                            <div class="col-md-4 col-sm-6">
                                <div class="p-2 bg-white rounded-2 border border-light-subtle">
                                    <div class="text-muted small fw-semibold" style="font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.4px;">Mobile / Contact</div>
                                    <input type="text" id="displayPatientMobile" class="form-control form-control-sm border-0 bg-transparent p-0 fw-semibold text-dark" placeholder="Mobile number" oninput="document.getElementById('hiddenCustomerMobile').value = this.value;">
                                </div>
                            </div>
                            <div class="col-md-5 col-sm-12">
                                <div class="p-2 bg-white rounded-2 border border-light-subtle">
                                    <div class="d-flex justify-content-between align-items-center mb-0.5">
                                        <div class="text-muted small fw-semibold" style="font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.4px;">Doctor / Prescriber</div>
                                        <span class="text-muted" style="font-size: 0.65rem;"><i class="ti ti-edit"></i> Editable</span>
                                    </div>
                                    <input type="text" id="displayDoctorName" class="form-control form-control-sm border-0 bg-transparent p-0 fw-semibold text-dark" placeholder="Attending Doctor (Optional)" list="doctorDatalist" oninput="document.getElementById('hiddenDoctorName').value = this.value; document.getElementById('modalDoctorInput').value = this.value;">
                                    <datalist id="doctorDatalist">
                                        <?php foreach ($availableDoctors as $dName): ?>
                                            <option value="<?= htmlspecialchars($dName) ?>">
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Hidden Select for form compatibility -->
                    <select id="patientSelect" class="d-none">
                        <option value="" data-name="Walk-in Customer" data-mobile="" selected>100 2026 - Walk-in Customer</option>
                        <?php foreach ($patients as $p): ?>
                            <option value="<?= $p['id'] ?>" data-name="<?= htmlspecialchars($p['name']) ?>" data-mobile="<?= htmlspecialchars($p['mobile']) ?>" data-uhid="<?= htmlspecialchars($p['hospital_uhid'] ?: $p['pharmacy_patient_no']) ?>">
                                <?= htmlspecialchars(($p['hospital_uhid'] ?: $p['pharmacy_patient_no']) . ' - ' . $p['name'] . ($p['mobile'] ? ' (' . $p['mobile'] . ')' : '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <!-- STEP 3: SEARCH & ADD MEDICINE (Identical to IPD) -->
                    <div class="p-3 bg-light rounded-3 border mb-3" style="background-color: #fbfcfd !important;">
                        <div class="d-flex justify-content-between align-items-center mb-1.5">
                            <label class="form-label small fw-semibold text-muted mb-0">
                                <i class="ti ti-pill text-emerald me-1"></i> Search &amp; Add Medicine / Charge <span class="text-danger">*</span> <kbd class="kbd-chip ms-1">F2</kbd>
                            </label>
                            <span class="text-muted small" style="font-size: 0.72rem;">
                                <i class="ti ti-bolt text-warning me-0.5"></i> Select any medicine to automatically add it to charges
                            </span>
                        </div>
                        
                        <div class="position-relative">
                            <div class="input-group">
                                <span class="input-group-text bg-white text-muted border-end-0">
                                    <i class="ti ti-search text-emerald"></i>
                                </span>
                                <input type="text" 
                                       id="chargeMedicineInput" 
                                       class="form-control form-control-lg fs-6 bg-white border-start-0 ps-1" 
                                       placeholder="Type medicine name to search &amp; auto-add to charges..." 
                                       autocomplete="off" 
                                       oninput="onMedicineSearchInput(this.value)" 
                                       onfocus="onMedicineSearchFocus()" 
                                       onkeydown="onMedicineInputKeydown(event)">
                                <button type="button" class="btn btn-outline-secondary border-start-0 border-light-subtle" onclick="clearMedicineSearch()" title="Clear">
                                    <i class="ti ti-x"></i>
                                </button>
                            </div>

                            <!-- Live Autocomplete Suggestions List -->
                            <div id="medicineSuggestionsList" 
                                 class="medicine-suggestions-menu position-absolute d-none" 
                                 style="background: #ffffff !important; background-color: #ffffff !important; border: 1px solid #cbd5e1 !important; box-shadow: 0 20px 45px -8px rgba(15, 23, 42, 0.28), 0 8px 18px -4px rgba(15, 23, 42, 0.12) !important; border-radius: 12px !important; width: 100%; min-width: 100%; left: 0; z-index: 1090; max-height: 380px; overflow-y: auto;">
                            </div>
                        </div>

                        <!-- Toast alert for auto-added feedback -->
                        <div id="autoAddNotification" class="d-none mt-2 py-1.5 px-3 rounded-2 small fw-semibold d-flex align-items-center justify-content-between" style="background-color: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; transition: opacity 0.3s ease;">
                            <span id="autoAddNotificationText"><i class="ti ti-check me-1"></i> Medicine added to charges</span>
                            <span class="badge bg-emerald text-white">Added &check;</span>
                        </div>

                        <!-- Hidden compatibility inputs -->
                        <input type="hidden" id="selectedMedicineId" value="">
                        <input type="hidden" id="selectedBatchId" value="">
                        <input type="hidden" id="chargeCategorySelect" value="">
                        <input type="hidden" id="chargeExpiryInput" value="">
                        <input type="hidden" id="chargeMfgRateInput" value="0.00">
                        <input type="hidden" id="chargeRateInput" value="0.00">
                        <input type="hidden" id="chargeQtyInput" value="1">
                        <input type="hidden" id="chargeUnitInput" value="unit">
                        <input type="hidden" id="chargeTotalDisplay" value="0.00">
                        <select id="chargeBatchSelect" class="d-none"><option value="">Select Batch</option></select>
                        <span id="batchCountBadge" class="d-none"></span>
                    </div>

                    <!-- STEP 4: CHARGE DETAILS (Live Charges Table & Grand Total) -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div class="d-flex align-items-center gap-2">
                                <i class="ti ti-receipt text-muted fs-5"></i>
                                <span class="fw-bold text-dark small text-uppercase" style="letter-spacing: 0.5px; font-size: 0.82rem;">Charge Details</span>
                            </div>
                            <span class="badge rounded-pill px-3 py-1.5 fw-semibold font-monospace" style="background-color: #f0f9ff; color: #0284c7; border: 1px solid #bae6fd; font-size: 0.82rem;" id="chargeDetailsTotalBadge">
                                Total: ₹0.00
                            </span>
                        </div>
                        <div class="card border rounded-3 overflow-hidden shadow-xs">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0" id="cartTable">
                                    <thead class="table-light small text-muted text-uppercase" style="font-size: 0.74rem;">
                                        <tr>
                                            <th style="width: 5%;">S.NO</th>
                                            <th style="width: 32%;">CHARGE / MEDICINE NAME</th>
                                            <th style="width: 14%;">EXPIRY DATE</th>
                                            <th class="text-end" style="width: 11%;">MFG RATE</th>
                                            <th class="text-end" style="width: 11%;">BILL RATE</th>
                                            <th class="text-center" style="width: 10%;">QTY</th>
                                            <th class="text-end" style="width: 11%;">TOTAL</th>
                                            <th class="text-center" style="width: 6%;"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="cartTableBody">
                                        <tr id="emptyCartRow">
                                            <td colspan="8" class="text-center py-4 text-muted small">
                                                No charges added yet. Search a medicine above to automatically add to this bill.
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Grand Total Display -->
                        <div class="d-flex justify-content-end align-items-center py-3">
                            <span class="fw-bold text-muted me-3 small text-uppercase" style="letter-spacing: 0.6px; font-size: 0.82rem;">GRAND TOTAL AMOUNT:</span>
                            <span class="fw-bold font-monospace" style="font-size: 1.45rem; color: #059669;" id="lblGrandTotal">₹0.00</span>
                        </div>

                        <!-- Bottom Action Footer -->
                        <div class="d-flex justify-content-end align-items-center gap-2 pt-3 border-top">
                            <button type="button" class="btn btn-outline-emerald d-inline-flex align-items-center gap-1.5 px-3 py-2 fw-semibold" onclick="saveDraft()" style="border-radius: 8px;">
                                <i class="ti ti-device-floppy"></i> Save Draft
                            </button>
                            <button type="button" id="btnProceedPreview" class="btn btn-emerald d-inline-flex align-items-center gap-1.5 px-4 py-2 fw-semibold shadow-sm" onclick="openBillingPreviewModal()" style="border-radius: 8px;">
                                Proceed to Billing Preview <kbd class="kbd-chip ms-1" style="background: rgba(255,255,255,0.25); color: #fff;">F8</kbd> &rarr;
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Billing Preview & Payment -->
<div class="modal fade" id="billingPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" style="max-width: min(1080px, 95vw);">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden" style="border: 1px solid rgba(0,0,0,0.08) !important;">
            <!-- Modal Header -->
            <div class="modal-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-3">
                    <div class="d-inline-flex align-items-center justify-content-center shadow-xs" style="width: 42px; height: 42px; border-radius: 10px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: #fff;">
                        <i class="bi bi-receipt-cutoff fs-5"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <h5 class="modal-title fw-bold text-dark mb-0" style="font-size: 1.15rem; letter-spacing: -0.01em;">OPD Retail Billing &amp; Checkout Preview</h5>
                            <span class="badge rounded-pill fw-semibold" style="background-color: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; font-size: 0.72rem; padding: 4px 10px;">
                                <i class="bi bi-check-circle-fill me-1" style="font-size: 0.7rem;"></i>Retail Folio
                            </span>
                        </div>
                        <div class="text-muted small mt-0.5" style="font-size: 0.8rem;">Verify medication charges, review discounts &amp; settle payment</div>
                    </div>
                </div>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal" aria-label="Close" style="font-size: 0.8rem;"></button>
            </div>

            <div class="modal-body p-4" style="background-color: #f8fafc; box-sizing: border-box;">
                <style>
                    .opd-patient-strip { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
                    .opd-preview-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; align-items: stretch; }
                    @media (max-width: 992px) {
                        .opd-patient-strip { grid-template-columns: repeat(2, 1fr); }
                        .opd-preview-grid { grid-template-columns: 1fr; }
                    }
                    @media (max-width: 576px) {
                        .opd-patient-strip { grid-template-columns: 1fr; }
                    }
                </style>

                <!-- Patient Summary Strip: 4 Exact Equal-Height Cards -->
                <div class="opd-patient-strip mb-3">
                    <!-- Card 1: Patient / Customer Name -->
                    <div class="bg-white p-3 rounded-3 border d-flex flex-column justify-content-between shadow-2xs" style="min-height: 72px;">
                        <div class="d-flex align-items-center" style="font-size: 0.68rem; letter-spacing: 0.05em; font-weight: 700; color: #64748b; text-transform: uppercase;">
                            <i class="bi bi-person text-emerald" style="margin-right: 7px; font-size: 0.95rem;"></i>
                            <span>Patient Name</span>
                        </div>
                        <div class="mt-1 text-dark fs-6 fw-bold text-truncate" id="modalPatientName">Walk-in Customer</div>
                    </div>

                    <!-- Card 2: Hospital UHID / Patient ID -->
                    <div class="bg-white p-3 rounded-3 border d-flex flex-column justify-content-between shadow-2xs" style="min-height: 72px;">
                        <div class="d-flex align-items-center" style="font-size: 0.68rem; letter-spacing: 0.05em; font-weight: 700; color: #64748b; text-transform: uppercase;">
                            <i class="bi bi-upc-scan text-emerald" style="margin-right: 7px; font-size: 0.95rem;"></i>
                            <span>Patient UHID / ID</span>
                        </div>
                        <div class="mt-1 font-monospace fw-bold text-dark fs-6 text-truncate" id="modalPatientUhid">OPD-DIRECT</div>
                    </div>

                    <!-- Card 3: Contact / Mobile -->
                    <div class="bg-white p-3 rounded-3 border d-flex flex-column justify-content-between shadow-2xs" style="min-height: 72px;">
                        <div class="d-flex align-items-center" style="font-size: 0.68rem; letter-spacing: 0.05em; font-weight: 700; color: #64748b; text-transform: uppercase;">
                            <i class="bi bi-telephone text-emerald" style="margin-right: 7px; font-size: 0.95rem;"></i>
                            <span>Contact / Mobile</span>
                        </div>
                        <div class="mt-1 fw-bold text-dark fs-6 text-truncate" id="modalPatientMobile">-</div>
                    </div>

                    <!-- Card 4: Bill Type -->
                    <div class="bg-white p-3 rounded-3 border d-flex flex-column justify-content-between shadow-2xs" style="min-height: 72px;">
                        <div class="d-flex align-items-center" style="font-size: 0.68rem; letter-spacing: 0.05em; font-weight: 700; color: #64748b; text-transform: uppercase;">
                            <i class="bi bi-bookmark-check text-emerald" style="margin-right: 7px; font-size: 0.95rem;"></i>
                            <span>Bill Type</span>
                        </div>
                        <div class="mt-1 d-flex align-items-center">
                            <span class="badge rounded-pill fw-bold d-inline-flex align-items-center" style="background-color: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; font-size: 0.74rem; padding: 3px 8px;" id="modalBillType">
                                <i class="bi bi-check2" style="margin-right: 4px;"></i> OPD Retail Folio
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Itemized Bill List Table -->
                <div class="bg-white border rounded-3 overflow-hidden shadow-2xs mb-3">
                    <div class="table-responsive" style="max-height: 240px; overflow-y: auto;">
                        <table class="table table-hover align-middle mb-0" style="table-layout: fixed; width: 100%; font-size: 0.88rem;">
                            <thead class="table-light text-muted text-uppercase border-bottom sticky-top" style="font-size: 0.72rem; letter-spacing: 0.05em; background-color: #f1f5f9;">
                                <tr>
                                    <th style="width: 5%;" class="text-center py-2.5 px-3">#</th>
                                    <th style="width: 43%;" class="text-start py-2.5 px-3">Charge / Medicine Description</th>
                                    <th style="width: 22%;" class="text-start py-2.5 px-3">Batch &amp; Expiry</th>
                                    <th style="width: 10%;" class="text-center py-2.5 px-2">Qty</th>
                                    <th style="width: 10%;" class="text-end py-2.5 px-2">Rate</th>
                                    <th style="width: 10%;" class="text-end py-2.5 pe-3 ps-2">Amount</th>
                                </tr>
                            </thead>
                            <tbody id="modalChargesList" class="divide-y"></tbody>
                        </table>
                    </div>
                </div>

                <!-- Main Two-Column Section: 1fr 1fr Grid -->
                <div class="opd-preview-grid">
                    <!-- Left: Clinical & Prescriber Details -->
                    <div class="bg-white p-3.5 rounded-3 border shadow-2xs d-flex flex-column justify-content-between h-100">
                        <div>
                            <div class="d-flex align-items-center justify-content-between pb-2 mb-3 border-bottom" style="min-height: 32px;">
                                <span class="fw-bold text-dark d-flex align-items-center" style="font-size: 0.88rem;">
                                    <i class="bi bi-clipboard2-pulse text-emerald" style="margin-right: 8px; font-size: 1rem;"></i> Dispensing &amp; Clinician Details
                                </span>
                            </div>
                            
                            <div class="d-flex flex-column" style="gap: 12px;">
                                <div>
                                    <label class="form-label text-secondary fw-semibold mb-1" style="font-size: 0.78rem; display: block;">Attending Doctor / Prescriber</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text bg-light text-muted border-end-0" style="width: 38px; justify-content: center;"><i class="bi bi-person-badge"></i></span>
                                        <input type="text" id="modalDoctorInput" class="form-control form-control-sm fw-semibold border-start-0" style="height: 34px;" placeholder="Doctor name (Optional)" readonly>
                                    </div>
                                </div>
                                <div>
                                    <label class="form-label text-secondary fw-semibold mb-1" style="font-size: 0.78rem; display: block;">Dispensing Notes / Remarks</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text bg-light text-muted border-end-0" style="width: 38px; justify-content: center;"><i class="bi bi-card-text"></i></span>
                                        <input type="text" id="modalNotesInput" class="form-control form-control-sm border-start-0" style="height: 34px;" placeholder="e.g. OPD prescription dispensing...">
                                    </div>
                                </div>
                                <div>
                                    <label class="form-label text-secondary fw-semibold mb-1" style="font-size: 0.78rem; display: block;">Billing / Settlement Mode</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text bg-light text-muted border-end-0" style="width: 38px; justify-content: center;"><i class="bi bi-wallet2"></i></span>
                                        <select id="modalPaymentMode" class="form-select form-select-sm fw-semibold border-start-0" style="height: 34px;" onchange="onModalPaymentModeChange(this.value)">
                                            <option value="CASH" selected>Direct Cash Settlement</option>
                                            <option value="UPI">UPI / QR Code</option>
                                            <option value="CARD">Debit / Credit Card</option>
                                            <option value="BANK_TRANSFER">Bank Transfer / NEFT</option>
                                            <option value="CREDIT">Hospital Credit (Due)</option>
                                        </select>
                                    </div>
                                </div>
                                <div id="modalRefGroup" class="d-none">
                                    <label class="form-label text-secondary fw-semibold mb-1" style="font-size: 0.78rem; display: block;">Transaction Ref / UTR</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text bg-light text-muted border-end-0" style="width: 38px; justify-content: center;"><i class="bi bi-hash"></i></span>
                                        <input type="text" id="modalPaymentRef" class="form-control form-control-sm border-start-0" style="height: 34px;" placeholder="Ref / UTR / Card Auth code">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div style="height: 4px;"></div>
                    </div>

                    <!-- Right: Financial Summary & Net Calculations -->
                    <div class="bg-white p-3.5 rounded-3 border shadow-2xs d-flex flex-column justify-content-between h-100">
                        <div>
                            <div class="d-flex align-items-center justify-content-between pb-2 mb-3 border-bottom" style="min-height: 32px;">
                                <span class="fw-bold text-dark d-flex align-items-center" style="font-size: 0.88rem;">
                                    <i class="bi bi-calculator text-emerald" style="margin-right: 8px; font-size: 1rem;"></i> Settlement Summary
                                </span>
                                <span class="badge bg-light text-muted border fw-normal" style="font-size: 0.72rem;">All values in INR (₹)</span>
                            </div>

                            <div class="d-flex flex-column" style="gap: 8px;">
                                <!-- Subtotal Row -->
                                <div class="d-flex justify-content-between align-items-center" style="min-height: 30px;">
                                    <span class="text-secondary fw-semibold" style="font-size: 0.84rem;">Subtotal (Gross):</span>
                                    <span class="fw-bold font-monospace text-dark text-end" style="font-size: 0.92rem;" id="modalLblSubtotal">₹0.00</span>
                                </div>

                                <!-- Discount Row -->
                                <div class="d-flex justify-content-between align-items-center" style="min-height: 30px;">
                                    <span class="text-secondary fw-semibold" style="font-size: 0.84rem;">Discount (%):</span>
                                    <div class="d-flex align-items-center justify-content-end">
                                        <div class="input-group input-group-sm" style="width: 85px;">
                                            <input type="number" step="0.1" min="0" max="50" id="modalDiscountPercent" class="form-control form-control-sm text-end fw-bold font-monospace py-0.5 px-1.5" style="font-size: 0.85rem; height: 28px;" value="0.0" oninput="recalcModalTotals()">
                                            <span class="input-group-text bg-light text-muted py-0 px-1.5 font-monospace" style="font-size: 0.75rem; height: 28px;">%</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Discount Amount Row (Dynamic) -->
                                <div class="justify-content-between align-items-center text-danger d-none" id="modalRowDiscount" style="min-height: 30px;">
                                    <span class="fw-semibold" style="font-size: 0.84rem;">Discount Amount:</span>
                                    <span class="fw-bold font-monospace text-end" style="font-size: 0.92rem;" id="modalLblDiscountAmt">-₹0.00</span>
                                </div>

                                <!-- GST Row -->
                                <div class="d-flex justify-content-between align-items-center" style="min-height: 30px;">
                                    <span class="text-secondary fw-semibold" style="font-size: 0.84rem;">GST (Tax Inclusive):</span>
                                    <span class="fw-bold font-monospace text-dark text-end" style="font-size: 0.92rem;" id="modalLblGst">₹0.00</span>
                                </div>
                            </div>
                        </div>

                        <!-- Grand Total Highlight Card -->
                        <div class="p-3 rounded-3 border mt-3" style="background: #f0fdf4; border-color: #a7f3d0 !important;">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="text-uppercase fw-bold text-success" style="font-size: 0.74rem; letter-spacing: 0.05em;">Net Payable:</span>
                                <span class="fw-bold fs-3 font-monospace" style="color: #047857;" id="modalLblGrandTotal">₹0.00</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center pt-2 border-top border-emerald-subtle">
                                <span class="small fw-bold text-dark">Amount Tendered:</span>
                                <div style="width: 130px;">
                                    <input type="number" step="any" min="0" id="modalPaidInput" class="form-control form-control-sm text-end fw-bold font-monospace" placeholder="0.00" oninput="recalcModalChange()" onkeydown="if(event.key==='Enter'){event.preventDefault();submitFinalSale();}">
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between small text-muted px-1 mt-1">
                            <span>Change / Due Balance:</span>
                            <span class="fw-bold font-monospace text-danger fs-6" id="modalLblChangeOrDue">₹0.00</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal Action Footer: Clean Harmonious Single-Line -->
            <div class="modal-footer py-2.5 px-3.5 bg-white border-top d-flex justify-content-between align-items-center flex-nowrap w-100" style="flex-wrap: nowrap !important; box-sizing: border-box;">
                <button type="button" class="btn btn-light border text-secondary text-nowrap px-3 py-1.5 fw-semibold d-inline-flex align-items-center justify-content-center rounded-3 shadow-2xs" style="font-size: 0.84rem;" data-bs-dismiss="modal">
                    <i class="bi bi-arrow-left" style="margin-right: 6px; font-size: 0.95rem;"></i>
                    <span>Back to Edit</span>
                </button>
                <div class="d-flex align-items-center flex-nowrap" style="gap: 8px; flex-wrap: nowrap !important;">
                    <button type="button" class="btn text-nowrap px-3 py-1.5 fw-bold d-inline-flex align-items-center justify-content-center rounded-3 shadow-2xs" style="background-color: #f0f9ff; color: #0284c7; border: 1px solid #bae6fd; font-size: 0.84rem;" onclick="previewCurrentBill('standard')">
                        <i class="bi bi-printer" style="margin-right: 6px; font-size: 0.95rem;"></i>
                        <span>Preview / Print Bill</span>
                    </button>
                    <button type="button" id="modalBtnConfirmSale" class="btn btn-emerald text-white text-nowrap px-3.5 py-1.5 fw-bold d-inline-flex align-items-center justify-content-center rounded-2 shadow-xs" style="font-size: 0.86rem;" onclick="submitFinalSale()">
                        <i class="bi bi-check-lg" style="margin-right: 6px; font-size: 1.1rem;"></i>
                        <span>Confirm Sale</span>
                        <kbd class="kbd-chip ms-1.5" style="background: rgba(255,255,255,0.25); color: #fff; font-size: 0.72rem; padding: 2px 5px; border-radius: 4px; font-weight: 700;">F9</kbd>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: New OPD Patient Registration -->
<div class="modal fade" id="newOpdPatientModal" tabindex="-1" aria-labelledby="newOpdPatientModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-3 overflow-hidden">
            <div class="modal-header border-bottom py-2.5 px-3 bg-light-subtle">
                <div class="d-flex align-items-center gap-2">
                    <div class="d-inline-flex align-items-center justify-content-center rounded bg-emerald-subtle text-emerald" style="width: 32px; height: 32px;">
                        <i class="ti ti-user-plus fs-5"></i>
                    </div>
                    <div>
                        <h6 class="modal-title fw-bold mb-0 text-dark" id="newOpdPatientModalLabel">New Patient Registration</h6>
                        <div class="text-muted small" style="font-size: 0.74rem;">Register patient and select immediately for billing</div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="newOpdPatientForm" onsubmit="submitNewOpdPatient(event)">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <div class="modal-body p-4">
                    <div id="newOpdAlert" class="alert d-none mb-3 py-2 px-3 small"></div>

                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label small fw-semibold text-muted mb-1">Patient Full Name <span class="text-danger">*</span></label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light"><i class="ti ti-user"></i></span>
                                <input type="text" name="patient_name" id="regOpdPatientName" class="form-control form-control-sm" placeholder="e.g. Ramesh Kumar Sharma" required>
                            </div>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label small fw-semibold text-muted mb-1">Mobile / Phone Number</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light"><i class="ti ti-phone"></i></span>
                                <input type="tel" name="mobile" id="regOpdMobile" class="form-control form-control-sm" placeholder="e.g. 9876543210">
                            </div>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-semibold text-muted mb-1">Gender</label>
                            <select name="gender" id="regOpdGender" class="form-select form-select-sm">
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label small fw-semibold text-muted mb-1">Attending Doctor (Optional)</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light"><i class="ti ti-stethoscope"></i></span>
                                <input type="text" name="doctor_name" id="regOpdDoctor" class="form-control form-control-sm" placeholder="e.g. Dr. Anjali Mehta" list="doctorDatalist">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-2.5 px-4 bg-light border-top d-flex justify-content-between">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" id="btnSubmitNewOpd" class="btn btn-sm btn-emerald fw-bold d-inline-flex align-items-center gap-1.5 px-3">
                        <i class="ti ti-check"></i> Register &amp; Select
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Catalog of active medicines loaded from database
const catalogMedicines = <?= json_encode($allMedicines, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
let opdPatientsList = <?= json_encode($patients, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
let activeCounterPatientSuggestions = [];
let selectedCounterPatientIndex = -1;
let cart = [];

function escapeHtml(text) {
    if (!text) return '';
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(text).replace(/[&<>"']/g, m => map[m]);
}

// ----------------- LIVE PATIENT SEARCH SUITE (OPD) -----------------
function highlightPatientMatch(text, query) {
    if (!text) return '';
    if (!query) return escapeHtml(text);
    const escaped = escapeHtml(text);
    const qEscaped = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    // Color only at the start of a word (start of string or preceded by whitespace)
    const regex = new RegExp(`(^|\\s)(${qEscaped})`, 'gi');
    return escaped.replace(regex, '$1<span class="patient-match-color">$2</span>');
}

// ----------------- LIVE OPD PATIENT SEARCH SUITE -----------------
let opdPatientSortMode = 'recent'; // 'recent', 'az', 'za'

function setOpdPatientSortMode(mode, e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    opdPatientSortMode = mode;
    const input = document.getElementById('counterPatientSearchInput');
    onCounterPatientSearchInput(input ? input.value : '');
    if (input) input.focus();
}

function onCounterPatientSearchInput(query) {
    const list = document.getElementById('counterPatientSuggestionsList');
    if (!list) return;

    query = (query || '').trim().toLowerCase();
    if (query === 'walk-in customer') query = '';

    // 1. Filter matches
    let matches = opdPatientsList.filter(p => {
        if (!query) return true;
        const rawName = (p.name || '').trim().toLowerCase();
        const uhid = (p.hospital_uhid || p.pharmacy_patient_no || '').trim().toLowerCase();
        const mob = (p.mobile || '').trim().toLowerCase();
        const doc = (p.doctor_name || '').trim().toLowerCase();
        return rawName.includes(query) || uhid.includes(query) || mob.includes(query) || doc.includes(query);
    });

    // 2. Sort matches
    if (opdPatientSortMode === 'az') {
        matches.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
    } else if (opdPatientSortMode === 'za') {
        matches.sort((a, b) => (b.name || '').localeCompare(a.name || ''));
    } else {
        // 'recent' - default
        if (query) {
            matches.sort((a, b) => {
                const aStarts = (a.name || '').toLowerCase().startsWith(query);
                const bStarts = (b.name || '').toLowerCase().startsWith(query);
                if (aStarts && !bStarts) return -1;
                if (!aStarts && bStarts) return 1;
                return 0;
            });
        }
    }

    activeCounterPatientSuggestions = matches;
    selectedCounterPatientIndex = -1;

    // 3. Render Sticky Header with Sort / Filter Controls
    let headerHtml = `
        <div class="patient-dropdown-header" onclick="event.stopPropagation()">
            <div class="d-flex align-items-center gap-1.5">
                <span class="text-dark fw-bold" style="font-size: 0.78rem;">
                    <i class="bi bi-person-lines-fill text-emerald me-1"></i>Patients (${matches.length})
                </span>
            </div>
            <div class="d-flex align-items-center gap-1 flex-wrap">
                <span class="text-muted small me-1" style="font-size: 0.72rem;">Sort:</span>
                <button type="button" class="pat-filter-btn ${opdPatientSortMode === 'recent' ? 'active' : ''}" 
                        onclick="setOpdPatientSortMode('recent', event)" title="Sort by Recent">
                    <i class="bi bi-clock-history"></i> Recent
                </button>
                <button type="button" class="pat-filter-btn ${opdPatientSortMode === 'az' ? 'active' : ''}" 
                        onclick="setOpdPatientSortMode('az', event)" title="Sort Alphabetically A to Z">
                    <i class="bi bi-sort-alpha-down"></i> A - Z
                </button>
                <button type="button" class="pat-filter-btn ${opdPatientSortMode === 'za' ? 'active' : ''}" 
                        onclick="setOpdPatientSortMode('za', event)" title="Sort Alphabetically Z to A">
                    <i class="bi bi-sort-alpha-up-alt"></i> Z - A
                </button>
            </div>
        </div>
    `;

    let bodyHtml = '';
    // Always provide Walk-in Customer option at top
    bodyHtml += `
        <div class="patient-suggest-item p-2.5 border-bottom d-flex align-items-center justify-content-between cursor-pointer" 
             style="cursor: pointer; transition: background 0.15s ease;"
             onclick="resetToWalkInCustomer()">
            <div class="d-flex align-items-center gap-2.5">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 34px; height: 34px; background: #f1f5f9; color: #475569; font-weight: 700; font-size: 0.85rem;">
                    <i class="ti ti-user"></i>
                </div>
                <div>
                    <div class="fw-bold text-dark fs-6 lh-1 mb-0.5">Walk-in Customer</div>
                    <span class="text-muted small" style="font-size: 0.72rem;">Default Counter Sale (No Account Required)</span>
                </div>
            </div>
            <span class="badge bg-light text-secondary border fw-semibold" style="font-size: 0.70rem;">Select</span>
        </div>
    `;

    if (matches.length === 0) {
        bodyHtml += `
            <div class="p-4 text-center text-muted">
                <i class="ti ti-user-x fs-2 d-block mb-1 text-secondary opacity-50"></i>
                <div class="small fw-semibold text-dark">No patient found matching "${escapeHtml(query)}"</div>
                <div class="small text-muted mt-1">Is this a new patient?</div>
                <button type="button" class="btn btn-sm btn-emerald text-white mt-2 fw-semibold px-3 py-1 shadow-sm" onclick="openNewOpdModal('${escapeHtml(query)}')">
                    <i class="ti ti-plus me-1"></i>New Register Patient
                </button>
            </div>
        `;
    } else {
        matches.forEach((p, idx) => {
            bodyHtml += `
                <div class="patient-suggest-item" 
                     data-index="${idx}"
                     onmouseenter="highlightCounterPatientSuggestion(${idx})"
                     onclick="selectCounterPatientById(${p.id})">
                    <div class="d-flex align-items-center gap-3">
                        <div class="pat-avatar">
                            ${escapeHtml((p.name || 'P').charAt(0).toUpperCase())}
                        </div>
                        <div>
                            <div class="pat-name">${highlightPatientMatch(p.name, query && query !== 'walk-in customer' ? query : '')}</div>
                            <div class="pat-meta">
                                <span class="badge bg-light text-secondary border font-monospace px-1.5 py-0.5">${escapeHtml(p.hospital_uhid || p.pharmacy_patient_no || 'OPD')}</span>
                                ${p.mobile ? `<span class="d-inline-flex align-items-center gap-1"><i class="bi bi-telephone text-secondary"></i>${escapeHtml(p.mobile)}</span>` : ''}
                                ${p.doctor_name ? `<span class="d-inline-flex align-items-center gap-1"><i class="bi bi-person-badge text-primary"></i>${escapeHtml(p.doctor_name)}</span>` : ''}
                            </div>
                        </div>
                    </div>
                    <div class="text-end ps-3 flex-shrink-0">
                        <span class="pat-select-btn">Select <i class="bi bi-arrow-right"></i></span>
                    </div>
                </div>
            `;
        });
    }

    let footerHtml = `
        <div class="patient-dropdown-footer" onclick="event.stopPropagation()">
            <span class="text-muted d-inline-flex align-items-center gap-1"><i class="bi bi-people-fill text-emerald"></i><strong>${matches.length}</strong> matching patient${matches.length === 1 ? '' : 's'}</span>
            <a href="javascript:void(0)" onclick="openNewOpdModal()" class="fw-semibold text-decoration-none text-emerald d-inline-flex align-items-center gap-1">
                <i class="bi bi-plus-circle"></i> Register New Patient
            </a>
        </div>
    `;

    list.innerHTML = headerHtml + bodyHtml + footerHtml;
    list.classList.remove('d-none');
}

function onCounterPatientSearchFocus() {
    const input = document.getElementById('counterPatientSearchInput');
    const val = input ? input.value : '';
    onCounterPatientSearchInput(val);
}

function onCounterPatientSearchKeydown(e) {
    const list = document.getElementById('counterPatientSuggestionsList');
    if (list.classList.contains('d-none') || activeCounterPatientSuggestions.length === 0) {
        return;
    }

    const items = list.querySelectorAll('.patient-suggest-item');
    if (items.length === 0) return;

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        selectedCounterPatientIndex = (selectedCounterPatientIndex + 1) % items.length;
        updateActiveCounterPatientItem(items);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        selectedCounterPatientIndex = (selectedCounterPatientIndex - 1 + items.length) % items.length;
        updateActiveCounterPatientItem(items);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (selectedCounterPatientIndex >= 0 && selectedCounterPatientIndex < items.length) {
            items[selectedCounterPatientIndex].click();
        } else if (activeCounterPatientSuggestions.length > 0) {
            selectCounterPatientById(activeCounterPatientSuggestions[0].id);
        }
    } else if (e.key === 'Escape') {
        list.classList.add('d-none');
        selectedCounterPatientIndex = -1;
    }
}

function highlightCounterPatientSuggestion(idx) {
    selectedCounterPatientIndex = idx;
    const list = document.getElementById('counterPatientSuggestionsList');
    const items = list.querySelectorAll('.patient-suggest-item');
    updateActiveCounterPatientItem(items);
}

function updateActiveCounterPatientItem(items) {
    items.forEach((el, i) => {
        if (i === selectedCounterPatientIndex) {
            el.classList.add('active-nav');
            el.scrollIntoView({ block: 'nearest' });
        } else {
            el.classList.remove('active-nav');
        }
    });
}

function selectCounterPatientById(patientId) {
    const p = opdPatientsList.find(x => x.id == patientId);
    if (!p) return;

    document.getElementById('hiddenCustomerName').value = p.name;
    document.getElementById('hiddenCustomerMobile').value = p.mobile || '';

    const select = document.getElementById('patientSelect');
    if (select) select.value = p.id;

    // Update selected patient card
    const avatarEl = document.getElementById('cardPatientAvatar');
    if (avatarEl) avatarEl.textContent = p.name.charAt(0).toUpperCase();
    
    const nameEl = document.getElementById('cardPatientName');
    if (nameEl) nameEl.textContent = p.name;

    const metaEl = document.getElementById('cardPatientMeta');
    if (metaEl) metaEl.textContent = `UHID: ${p.hospital_uhid || p.pharmacy_patient_no || 'OPD'} | Phone: ${p.mobile || '-'}`;

    const uhidInp = document.getElementById('displayPatientUhid');
    if (uhidInp) uhidInp.value = p.hospital_uhid || p.pharmacy_patient_no || 'OPD-DIRECT';

    const mobInp = document.getElementById('displayPatientMobile');
    if (mobInp) mobInp.value = p.mobile || '';

    const input = document.getElementById('counterPatientSearchInput');
    if (input) input.value = p.name + (p.mobile ? ` (${p.mobile})` : '');

    const btnClear = document.getElementById('btnClearCounterPatient');
    if (btnClear) btnClear.classList.remove('d-none');

    if (p.doctor_name) {
        const docInp = document.getElementById('displayDoctorName');
        if (docInp) docInp.value = p.doctor_name;
        document.getElementById('hiddenDoctorName').value = p.doctor_name;
        document.getElementById('modalDoctorInput').value = p.doctor_name;
    }

    document.getElementById('counterPatientSuggestionsList').classList.add('d-none');
    selectedCounterPatientIndex = -1;

    setTimeout(() => {
        const medInput = document.getElementById('chargeMedicineInput');
        if (medInput) {
            medInput.focus();
            medInput.select();
        }
    }, 120);
}

function resetToWalkInCustomer() {
    document.getElementById('hiddenCustomerName').value = 'Walk-in Customer';
    document.getElementById('hiddenCustomerMobile').value = '';

    const select = document.getElementById('patientSelect');
    if (select) select.value = '';

    const avatarEl = document.getElementById('cardPatientAvatar');
    if (avatarEl) avatarEl.textContent = 'W';
    
    const nameEl = document.getElementById('cardPatientName');
    if (nameEl) nameEl.textContent = 'Walk-in Customer';

    const metaEl = document.getElementById('cardPatientMeta');
    if (metaEl) metaEl.textContent = 'UHID: Direct / OPD | Mobile: -';

    const uhidInp = document.getElementById('displayPatientUhid');
    if (uhidInp) uhidInp.value = 'OPD-DIRECT';

    const mobInp = document.getElementById('displayPatientMobile');
    if (mobInp) mobInp.value = '';

    const input = document.getElementById('counterPatientSearchInput');
    if (input) input.value = '';

    const btnClear = document.getElementById('btnClearCounterPatient');
    if (btnClear) btnClear.classList.add('d-none');

    document.getElementById('counterPatientSuggestionsList').classList.add('d-none');
    selectedCounterPatientIndex = -1;
}

function clearSelectedPatient() {
    resetToWalkInCustomer();
    const input = document.getElementById('counterPatientSearchInput');
    if (input) {
        input.focus();
    }
}

function openNewOpdModal(prefillName = '') {
    // Immediately hide the patient suggestions popover/dropdown
    const pList = document.getElementById('counterPatientSuggestionsList');
    if (pList) {
        pList.classList.add('d-none');
    }
    selectedCounterPatientIndex = -1;

    const modalEl = document.getElementById('newOpdPatientModal');
    if (!modalEl) return;
    const nameInput = document.getElementById('regOpdPatientName');
    if (nameInput) {
        nameInput.value = prefillName || '';
    }
    const alertBox = document.getElementById('newOpdAlert');
    if (alertBox) {
        alertBox.className = 'alert d-none mb-3 py-2 px-3 small';
        alertBox.textContent = '';
    }
    const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
    bsModal.show();
    setTimeout(() => {
        if (nameInput) nameInput.focus();
    }, 400);
}

function submitNewOpdPatient(e) {
    e.preventDefault();
    const form = document.getElementById('newOpdPatientForm');
    const btn = document.getElementById('btnSubmitNewOpd');
    const alertBox = document.getElementById('newOpdAlert');

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Registering...';

    const formData = new FormData(form);
    formData.append('action', 'ajax_new_opd_patient');

    fetch('counter.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="ti ti-check"></i> Register &amp; Select';

        if (data.success && data.patient) {
            const p = data.patient;
            opdPatientsList.unshift(p);

            const select = document.getElementById('patientSelect');
            if (select) {
                const opt = document.createElement('option');
                opt.value = p.id;
                opt.textContent = `${p.hospital_uhid} - ${p.name}` + (p.mobile ? ` (${p.mobile})` : '');
                opt.setAttribute('data-name', p.name);
                opt.setAttribute('data-mobile', p.mobile || '');
                opt.setAttribute('data-uhid', p.hospital_uhid);
                select.appendChild(opt);
            }

            selectCounterPatientById(p.id);

            const modalEl = document.getElementById('newOpdPatientModal');
            bootstrap.Modal.getInstance(modalEl)?.hide();

            const mainContainer = document.querySelector('.container-fluid');
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-success alert-dismissible fade show shadow-xs border-success-subtle mb-3';
            alertDiv.innerHTML = `<i class="ti ti-check me-2"></i><strong>${escapeHtml(p.name)}</strong> successfully registered and selected for billing! <button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
            mainContainer.insertBefore(alertDiv, mainContainer.children[1] || mainContainer.firstChild);
        } else {
            alertBox.className = 'alert alert-danger mb-3 py-2 px-3 small';
            alertBox.textContent = data.message || 'Registration failed. Please check inputs.';
            alertBox.classList.remove('d-none');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="ti ti-check"></i> Register &amp; Select';
        alertBox.className = 'alert alert-danger mb-3 py-2 px-3 small';
        alertBox.textContent = 'Server connection error. Please try again.';
        alertBox.classList.remove('d-none');
    });
}

// Autocomplete & Search for Medicines
let activeSuggestions = [];

let selectedSuggestionIndex = -1;

function highlightMatch(text, query) {
    if (!text) return '';
    if (!query) return escapeHtml(text);
    const escaped = escapeHtml(text);
    const qEscaped = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const regex = new RegExp(`(^|\\s)(${qEscaped})`, 'gi');
    return escaped.replace(regex, '$1<span class="search-highlight">$2</span>');
}

function getMedicineIconInfo(m) {
    const dosage = (m.dosage_form || '').toLowerCase();
    const cat = (m.category || '').toLowerCase();
    if (dosage.includes('syrup') || cat.includes('syrup') || dosage.includes('liquid') || dosage.includes('suspension')) {
        return { icon: 'bi-droplet-half', cls: 'syrup' };
    }
    if (dosage.includes('inj') || cat.includes('inj') || dosage.includes('iv') || dosage.includes('infusion')) {
        return { icon: 'bi-eyedropper', cls: 'injection' };
    }
    if (dosage.includes('tab') || dosage.includes('cap') || cat.includes('tab') || cat.includes('cap')) {
        return { icon: 'bi-capsule', cls: 'tablet' };
    }
    return { icon: 'bi-prescription2', cls: 'general' };
}

function getExpiryStatus(dateStr) {
    if (!dateStr || dateStr === 'N/A' || String(dateStr).trim() === '') {
        return {
            days: 9999,
            cls: 'bg-secondary-subtle text-secondary border border-secondary-subtle',
            badgeBg: '#f1f5f9',
            badgeColor: '#475569',
            badgeBorder: '#cbd5e1',
            inputBg: '#f8fafc',
            inputColor: '#334155',
            inputBorder: '#cbd5e1',
            icon: 'bi-calendar-event',
            label: 'N/A',
            tag: 'Unknown'
        };
    }

    const expDate = new Date(dateStr);
    if (isNaN(expDate.getTime())) {
        return {
            days: 9999,
            cls: 'bg-secondary-subtle text-secondary border border-secondary-subtle',
            badgeBg: '#f1f5f9',
            badgeColor: '#475569',
            badgeBorder: '#cbd5e1',
            inputBg: '#f8fafc',
            inputColor: '#334155',
            inputBorder: '#cbd5e1',
            icon: 'bi-calendar-event',
            label: dateStr,
            tag: 'Unknown'
        };
    }

    const today = new Date();
    expDate.setHours(0, 0, 0, 0);
    today.setHours(0, 0, 0, 0);
    const diffDays = Math.ceil((expDate.getTime() - today.getTime()) / (1000 * 60 * 60 * 24));

    if (diffDays <= 0) {
        // Expired (Solid Red)
        return {
            days: diffDays,
            cls: 'badge-exp-expired',
            badgeBg: '#fee2e2',
            badgeColor: '#991b1b',
            badgeBorder: '#ef4444',
            inputBg: '#fef2f2',
            inputColor: '#dc2626',
            inputBorder: '#ef4444',
            icon: 'bi-exclamation-octagon-fill',
            label: 'Expired',
            tag: 'Expired'
        };
    } else if (diffDays <= 60) {
        // Critical Near Expiry (<= 60 days: Bold Red)
        return {
            days: diffDays,
            cls: 'badge-exp-critical',
            badgeBg: '#fee2e2',
            badgeColor: '#b91c1c',
            badgeBorder: '#f87171',
            inputBg: '#fff1f2',
            inputColor: '#b91c1c',
            inputBorder: '#f87171',
            icon: 'bi-exclamation-triangle-fill',
            label: `${diffDays}d left`,
            tag: 'Near Expiry'
        };
    } else if (diffDays <= 180) {
        // Warning (61 - 180 days: Warm Amber/Orange)
        return {
            days: diffDays,
            cls: 'badge-exp-warning',
            badgeBg: '#fef3c7',
            badgeColor: '#b45309',
            badgeBorder: '#f59e0b',
            inputBg: '#fffbeb',
            inputColor: '#b45309',
            inputBorder: '#f59e0b',
            icon: 'bi-clock-history',
            label: `${Math.round(diffDays / 30)}m left`,
            tag: 'Expiring Soon'
        };
    } else {
        // Safe (> 180 days: Emerald Green)
        return {
            days: diffDays,
            cls: 'badge-exp-safe',
            badgeBg: '#dcfce7',
            badgeColor: '#166534',
            badgeBorder: '#22c55e',
            inputBg: '#f0fdf4',
            inputColor: '#166534',
            inputBorder: '#86efac',
            icon: 'bi-check-circle-fill',
            label: 'Valid',
            tag: 'Safe'
        };
    }
}

function rankMedicine(m, query) {
    const name = (m.medicine_name || '').toLowerCase();
    const generic = (m.generic_name || '').toLowerCase();
    const barcode = (m.barcode || '').toLowerCase();

    if (name.startsWith(query)) return 100;
    const words = name.split(/\s+/);
    if (words.some(w => w.startsWith(query))) return 80;
    if (name.includes(query)) return 60;
    if (generic.startsWith(query)) return 50;
    const genWords = generic.split(/\s+/);
    if (genWords.some(w => w.startsWith(query))) return 40;
    if (generic.includes(query)) return 30;
    if (barcode.includes(query)) return 20;
    return 0;
}

function onCategorySelectChange(cat) {
    const input = document.getElementById('chargeMedicineInput');
    if (input && input.value.trim().length > 0) {
        onMedicineSearchInput(input.value);
    }
}

function onMedicineSearchInput(query) {
    const suggestionsBox = document.getElementById('medicineSuggestionsList');
    if (!suggestionsBox) return;

    query = (query || '').trim().toLowerCase();

    // Do NOT show dropdown if input is empty - only visible as user searches by letters
    if (!query) {
        suggestionsBox.innerHTML = '';
        suggestionsBox.classList.add('d-none');
        activeSuggestions = [];
        selectedSuggestionIndex = -1;
        return;
    }

    // Search across ALL catalog medicines without category restriction
    const qLower = query.toLowerCase();
    let matches = catalogMedicines.filter(m => {
        const name = (m.medicine_name || '').trim().toLowerCase();
        const generic = (m.generic_name || '').trim().toLowerCase();
        const barcode = (m.barcode || '').trim().toLowerCase();
        if (barcode && barcode.includes(qLower)) return true;
        if (name.includes(qLower)) return true;
        if (generic && generic.includes(qLower)) return true;
        return false;
    });

    // Sort by relevance: first word starts with query first, then alphabetical
    matches.sort((a, b) => {
        const nameA = (a.medicine_name || '').trim().toLowerCase();
        const nameB = (b.medicine_name || '').trim().toLowerCase();
        const aStartsFirst = nameA.startsWith(qLower);
        const bStartsFirst = nameB.startsWith(qLower);
        if (aStartsFirst && !bStartsFirst) return -1;
        if (!aStartsFirst && bStartsFirst) return 1;
        return nameA.localeCompare(nameB);
    });

    activeSuggestions = matches;
    selectedSuggestionIndex = -1;

    if (matches.length === 0) {
        suggestionsBox.innerHTML = `
            <div class="p-3 text-center text-muted">
                <i class="ti ti-package-off text-secondary opacity-50 d-block fs-2 mb-1"></i>
                <div class="fw-semibold small text-dark">No matching medicines found for "${escapeHtml(query)}"</div>
                <div class="small text-muted mt-1">Try typing brand name or chemical composition</div>
            </div>
        `;
        suggestionsBox.classList.remove('d-none');
        return;
    }

    let html = '';
    matches.slice(0, 10).forEach((m, idx) => {
        const stock = parseInt(m.stock_quantity || 0);
        const isOutOfStock = stock <= 0;
        const iconInfo = getMedicineIconInfo(m);
        const price = parseFloat(m.price || 0).toFixed(2);
        
        let stockBadge = '';
        if (isOutOfStock) {
            stockBadge = `<span class="med-stock-pill out-of-stock"><i class="bi bi-x-circle"></i> Out of stock</span>`;
        } else if (stock <= 10) {
            stockBadge = `<span class="med-stock-pill low-stock"><i class="bi bi-exclamation-triangle"></i> Only ${stock} left</span>`;
        } else {
            stockBadge = `<span class="med-stock-pill in-stock"><i class="bi bi-check-circle"></i> ${stock} in stock</span>`;
        }

        const highlightedName = highlightMatch(m.medicine_name, query);
        const genericStr = m.generic_name ? highlightMatch(m.generic_name, query) : '';
        const dosageStr = m.dosage_form || m.unit || 'unit';
        const catStr = m.category || 'General';

        const iconBg = iconInfo.cls === 'tablet' ? '#eff6ff' : iconInfo.cls === 'syrup' ? '#fef3c7' : iconInfo.cls === 'injection' ? '#fce7f3' : '#f1f5f9';
        const iconColor = iconInfo.cls === 'tablet' ? '#2563eb' : iconInfo.cls === 'syrup' ? '#d97706' : iconInfo.cls === 'injection' ? '#db2777' : '#64748b';
        const mfgRate = parseFloat(m.purchase_price || 0).toFixed(2);
        const expDate = m.expiry_date || 'N/A';
        const expStatus = getExpiryStatus(expDate);
        const manufacturer = m.manufacturer || 'Standard Labs';

        html += `
            <div class="med-suggest-item ${isOutOfStock ? 'disabled' : ''}" data-index="${idx}" onclick="selectMedicineSuggestion(${m.medicine_id})"
                 style="background: #ffffff !important; background-color: #ffffff !important; display: flex !important; align-items: center !important; justify-content: space-between !important; padding: 10px 14px !important; border-bottom: 1px solid #f1f5f9 !important; cursor: pointer !important; text-decoration: none !important;">
                <div class="d-flex align-items-center me-2 text-start flex-grow-1" style="min-width: 0;">
                    <div class="med-icon-box ${iconInfo.cls}" style="width: 38px; height: 38px; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 1.1rem; margin-right: 12px; background-color: ${iconBg}; color: ${iconColor};">
                        <i class="bi ${iconInfo.icon}"></i>
                    </div>
                    <div style="min-width: 0;">
                        <div class="med-name-title text-truncate" style="font-weight: 600; font-size: 0.90rem; color: #0f172a; line-height: 1.28;">${highlightedName}</div>
                        <div class="med-meta-desc" style="font-size: 0.74rem; color: #64748b; margin-top: 3px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                            <span class="med-category-chip" style="font-size: 0.66rem; font-weight: 600; background: #f1f5f9; color: #475569; padding: 1px 6px; border-radius: 4px; border: 1px solid #e2e8f0;">${escapeHtml(catStr)}</span>
                            <span class="badge bg-light text-secondary border" style="font-size: 0.68rem;"><i class="bi bi-building me-1 text-primary"></i>${escapeHtml(manufacturer)}</span>
                            <span class="badge ${expStatus.cls}" style="font-size: 0.68rem; background-color: ${expStatus.badgeBg} !important; color: ${expStatus.badgeColor} !important; border: 1px solid ${expStatus.badgeBorder} !important;" title="${expStatus.tag}: ${expStatus.label}">
                                <i class="bi ${expStatus.icon} me-1"></i>Exp: ${escapeHtml(expDate)} <span class="fw-bold">(${expStatus.label})</span>
                            </span>
                            <span class="text-truncate">${genericStr ? genericStr + ' &bull; ' : ''}${escapeHtml(dosageStr)}</span>
                        </div>
                    </div>
                </div>
                <div class="text-end ps-2 flex-shrink-0">
                    <span class="med-price-display" style="font-family: monospace; font-size: 0.94rem; font-weight: 700; color: #0f172a; text-align: right; display: block;">₹${price}</span>
                    <div class="text-muted" style="font-size: 0.70rem;">Mfg Rate: <span class="fw-semibold text-secondary font-monospace">₹${mfgRate}</span></div>
                    ${stockBadge}
                </div>
            </div>
        `;
    });

    html += `
        <div class="med-dropdown-footer" style="padding: 7px 14px; background: #f8fafc; border-top: 1px solid #e2e8f0; font-size: 0.72rem; color: #64748b; display: flex; justify-content: space-between; align-items: center; border-bottom-left-radius: 12px; border-bottom-right-radius: 12px;">
            <span><i class="ti ti-pill me-1 text-emerald"></i><strong>${matches.length}</strong> matching medicine${matches.length > 1 ? 's' : ''}</span>
            <span class="d-none d-sm-inline">Use <kbd class="bg-white text-dark border px-1">↑</kbd> <kbd class="bg-white text-dark border px-1">↓</kbd> to navigate, <kbd class="bg-white text-dark border px-1">Enter</kbd> to select</span>
        </div>
    `;

    suggestionsBox.innerHTML = html;
    suggestionsBox.classList.remove('d-none');
}

function onMedicineSearchFocus() {
    const input = document.getElementById('chargeMedicineInput');
    const val = input ? input.value.trim() : '';
    if (val.length > 0) {
        onMedicineSearchInput(val);
    } else {
        const suggestionsBox = document.getElementById('medicineSuggestionsList');
        if (suggestionsBox) suggestionsBox.classList.add('d-none');
    }
}

function onMedicineInputKeydown(e) {
    const suggestionsBox = document.getElementById('medicineSuggestionsList');
    const isShowingSuggestions = suggestionsBox && !suggestionsBox.classList.contains('d-none') && activeSuggestions.length > 0;

    if (isShowingSuggestions) {
        const items = suggestionsBox.querySelectorAll('.med-suggest-item:not(.disabled)');
        if (items.length > 0) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                selectedSuggestionIndex = (selectedSuggestionIndex + 1) % items.length;
                updateActiveSuggestion(items);
                return;
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                selectedSuggestionIndex = (selectedSuggestionIndex - 1 + items.length) % items.length;
                updateActiveSuggestion(items);
                return;
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (selectedSuggestionIndex >= 0 && selectedSuggestionIndex < items.length) {
                    items[selectedSuggestionIndex].click();
                } else if (activeSuggestions.length > 0) {
                    selectMedicineSuggestion(activeSuggestions[0].medicine_id);
                }
                return;
            } else if (e.key === 'Escape') {
                e.preventDefault();
                suggestionsBox.classList.add('d-none');
                selectedSuggestionIndex = -1;
                return;
            }
        }
    }

    // When search is empty or no suggestions showing:
    // Pressing Enter, Tab, or ArrowDown escapes the search box and slides down into the table rows
    if (e.key === 'Enter' || e.key === 'ArrowDown') {
        if (cart.length > 0) {
            e.preventDefault();
            focusCartRow(0);
        }
    }
}

function updateActiveSuggestion(items) {
    items.forEach((el, i) => {
        if (i === selectedSuggestionIndex) {
            el.classList.add('active-nav');
            el.scrollIntoView({ block: 'nearest' });
        } else {
            el.classList.remove('active-nav');
        }
    });
}

function populateBatchSelector(med) {
    const batchSelect = document.getElementById('chargeBatchSelect');
    const badge = document.getElementById('batchCountBadge');
    if (!batchSelect) return;
    
    batchSelect.innerHTML = '';
    const batches = (med && Array.isArray(med.batches) && med.batches.length > 0) ? med.batches : [];
    
    if (badge) {
        badge.textContent = batches.length > 1 ? `${batches.length} Batches` : (batches.length === 1 ? '1 Batch' : '');
    }

    if (batches.length === 0) {
        const opt = document.createElement('option');
        opt.value = med ? (med.batch_number || 'GEN-01') : '';
        opt.textContent = med ? `${med.batch_number || 'GEN-01'} (Exp: ${med.expiry_date || 'N/A'})` : 'No Batches';
        opt.dataset.batchId = med ? (med.batch_id || 0) : 0;
        opt.dataset.expiry = med ? (med.expiry_date || '') : '';
        opt.dataset.mfgRate = med ? (med.purchase_price || 0) : 0;
        opt.dataset.salePrice = med ? (med.price || 0) : 0;
        opt.dataset.stock = med ? (med.stock_quantity || 0) : 0;
        batchSelect.appendChild(opt);
        return;
    }

    batches.forEach((b, index) => {
        const opt = document.createElement('option');
        opt.value = b.batch_number;
        const prefix = index === 0 ? '[FEFO] ' : '';
        opt.textContent = `${prefix}${b.batch_number} (Exp: ${b.expiry_date} | Stock: ${b.stock})`;
        opt.dataset.batchId = b.batch_id;
        opt.dataset.expiry = b.expiry_date;
        opt.dataset.mfgRate = b.purchase_price;
        opt.dataset.salePrice = b.sale_price;
        opt.dataset.stock = b.stock;
        batchSelect.appendChild(opt);
    });

    batchSelect.selectedIndex = 0;
}

function onBatchSelectChange(batchNum) {
    const batchSelect = document.getElementById('chargeBatchSelect');
    if (!batchSelect) return;
    const selectedOpt = batchSelect.options[batchSelect.selectedIndex];
    if (!selectedOpt) return;

    const batchId = selectedOpt.dataset.batchId || '';
    const expiry = selectedOpt.dataset.expiry || '';
    const mfgRate = parseFloat(selectedOpt.dataset.mfgRate || 0);
    const salePrice = parseFloat(selectedOpt.dataset.salePrice || 0);
    const stock = parseInt(selectedOpt.dataset.stock || 0);

    document.getElementById('selectedBatchId').value = batchId;
    
    // Update Expiry Date and dynamic color
    const expInput = document.getElementById('chargeExpiryInput');
    expInput.value = expiry;
    const expStatus = getExpiryStatus(expiry);
    expInput.style.backgroundColor = expStatus.inputBg;
    expInput.style.color = expStatus.inputColor;
    expInput.style.borderColor = expStatus.inputBorder;
    expInput.style.fontWeight = '700';
    expInput.title = `Expiry: ${expiry || 'N/A'} (${expStatus.tag}: ${expStatus.label})`;

    // Update Mfg Rate & Bill Rate
    document.getElementById('chargeMfgRateInput').value = mfgRate.toFixed(2);
    if (salePrice > 0) {
        document.getElementById('chargeRateInput').value = salePrice.toFixed(2);
    }

    // Set max on Qty
    const qtyInput = document.getElementById('chargeQtyInput');
    if (stock > 0 && parseInt(qtyInput.value) > stock) {
        qtyInput.value = stock;
    }

    calculateChargeRowTotal();
}

function showAutoAddNotice(text, type = 'success') {
    const notice = document.getElementById('autoAddNotification');
    const noticeText = document.getElementById('autoAddNotificationText');
    if (!notice || !noticeText) return;

    if (type === 'warning') {
        notice.style.backgroundColor = '#fffbeb';
        notice.style.color = '#b45309';
        notice.style.borderColor = '#fde68a';
        noticeText.innerHTML = `<i class="ti ti-alert-triangle me-1"></i> ${escapeHtml(text)}`;
    } else {
        notice.style.backgroundColor = '#ecfdf5';
        notice.style.color = '#047857';
        notice.style.borderColor = '#a7f3d0';
        noticeText.innerHTML = `<i class="ti ti-check me-1"></i> ${escapeHtml(text)}`;
    }

    notice.classList.remove('d-none');
    if (window._autoAddNoticeTimer) clearTimeout(window._autoAddNoticeTimer);
    window._autoAddNoticeTimer = setTimeout(() => {
        notice.classList.add('d-none');
    }, 2500);
}

function selectMedicineSuggestion(medicineId) {
    const med = catalogMedicines.find(m => m.medicine_id === medicineId);
    if (!med) return;

    // Pick best batch (FEFO - first batch in batches array with stock, or default)
    let batchNumber = med.batch_number || 'GEN-01';
    let batchId = med.batch_id || 0;
    let expiry = med.expiry_date || 'N/A';
    let mfgRate = parseFloat(med.purchase_price || 0);
    let price = parseFloat(med.mrp || med.price || 0);
    let maxStock = parseInt(med.available_stock || med.stock_quantity || 999);

    if (Array.isArray(med.batches) && med.batches.length > 0) {
        const availableBatch = med.batches.find(b => parseInt(b.stock) > 0) || med.batches[0];
        if (availableBatch) {
            batchNumber = availableBatch.batch_number;
            batchId = availableBatch.batch_id;
            expiry = availableBatch.expiry_date;
            mfgRate = parseFloat(availableBatch.purchase_price || 0);
            price = parseFloat(availableBatch.sale_price || med.mrp || 0);
            maxStock = parseInt(availableBatch.stock || 0);
        }
    }

    const unit = med.dosage_form || med.unit || 'unit';
    const category = med.category || 'General';
    const manufacturer = med.manufacturer || '';
    const gstPercent = parseFloat(med.gst_percent || 0);

    // Auto add to cart
    const existingIdx = cart.findIndex(c => c.medicine_id === med.medicine_id && c.batch_number === batchNumber);
    if (existingIdx !== -1) {
        const newQty = cart[existingIdx].quantity + 1;
        if (maxStock > 0 && newQty > maxStock) {
            showAutoAddNotice(`Cannot add more. Max stock for batch ${batchNumber} is ${maxStock}.`, 'warning');
            return;
        }
        cart[existingIdx].quantity = newQty;
        showAutoAddNotice(`Increased "${med.medicine_name}" quantity to ${newQty}`, 'success');
    } else {
        if (maxStock <= 0) {
            showAutoAddNotice(`Warning: "${med.medicine_name}" is out of stock (Stock: 0).`, 'warning');
        } else {
            showAutoAddNotice(`Added "${med.medicine_name}" to charges`, 'success');
        }
        cart.push({
            medicine_id: med.medicine_id,
            medicine_name: med.medicine_name,
            batch_id: batchId,
            batch_number: batchNumber,
            manufacturer: manufacturer,
            expiry_date: expiry,
            purchase_price: mfgRate,
            category: category,
            unit: unit,
            quantity: 1,
            price: price,
            max_stock: maxStock > 0 ? maxStock : 999,
            gst_percent: gstPercent
        });
    }

    // Immediately render updated cart
    renderCart();

    // Clear medicine search input and refocus for rapid subsequent addition
    const medInput = document.getElementById('chargeMedicineInput');
    if (medInput) {
        medInput.value = '';
        medInput.focus();
    }
    const catInput = document.getElementById('chargeCategorySelect');
    if (catInput) catInput.value = '';
    const medIdInput = document.getElementById('selectedMedicineId');
    if (medIdInput) medIdInput.value = '';
    const suggestionsBox = document.getElementById('medicineSuggestionsList');
    if (suggestionsBox) {
        suggestionsBox.classList.add('d-none');
        suggestionsBox.innerHTML = '';
    }
    selectedSuggestionIndex = -1;
    activeSuggestions = [];
}

function clearMedicineSearch() {
    const medInput = document.getElementById('chargeMedicineInput');
    if (medInput) {
        medInput.value = '';
        medInput.focus();
    }
    const suggestionsBox = document.getElementById('medicineSuggestionsList');
    if (suggestionsBox) {
        suggestionsBox.classList.add('d-none');
        suggestionsBox.innerHTML = '';
    }
    selectedSuggestionIndex = -1;
    activeSuggestions = [];
}

function renderCart() {
    const tbody = document.getElementById('cartTableBody');
    const totalBadge = document.getElementById('chargeDetailsTotalBadge');
    const grandTotalLbl = document.getElementById('lblGrandTotal');

    if (cart.length === 0) {
        tbody.innerHTML = `
            <tr id="emptyCartRow">
                <td colspan="8" class="text-center py-4 text-muted small">
                    No charges added yet. Search a medicine above to automatically add to this bill.
                </td>
            </tr>
        `;
        totalBadge.textContent = 'Total: ₹0.00';
        grandTotalLbl.textContent = '₹0.00';
        recalculateCartTotalsSummary();
        return;
    }

    let html = '';
    let grandTotal = 0;

    cart.forEach((item, idx) => {
        const lineTotal = item.quantity * item.price;
        grandTotal += lineTotal;

        const med = catalogMedicines.find(m => m.medicine_id === item.medicine_id);
        const batches = (med && Array.isArray(med.batches)) ? med.batches : [];
        const expStatus = getExpiryStatus(item.expiry_date);
        let expiryCellHtml = '';

        if (batches.length > 1) {
            let batchItemsHtml = '';
            batches.forEach((b, bIdx) => {
                const isSelected = (String(b.batch_number).trim() === String(item.batch_number).trim()) || (b.batch_id && b.batch_id == item.batch_id);
                const bExp = getExpiryStatus(b.expiry_date);
                batchItemsHtml += `
                    <div class="batch-option-item p-2 mb-1 cursor-pointer d-flex align-items-center justify-content-between ${isSelected ? 'active-batch' : ''}"
                         onclick="selectCartBatch(${idx}, ${b.batch_id || 0}, '${escapeHtml(b.batch_number)}')"
                         title="Switch to Batch ${escapeHtml(b.batch_number)} (Exp: ${escapeHtml(b.expiry_date)})">
                        <div>
                            <div class="d-flex align-items-center gap-1.5">
                                <span class="font-monospace fw-bold text-dark" style="font-size: 0.82rem;">${escapeHtml(b.batch_number)}</span>
                                ${bIdx === 0 ? '<span class="badge bg-emerald-subtle text-emerald border border-emerald-subtle px-1 py-0.2" style="font-size: 0.64rem;"><i class="bi bi-star-fill text-warning me-1"></i>FEFO Default</span>' : ''}
                                ${isSelected ? '<span class="badge bg-primary text-white px-1.5 py-0.2" style="font-size: 0.64rem;">Selected</span>' : ''}
                            </div>
                            <div class="d-flex align-items-center gap-2 mt-1" style="font-size: 0.72rem;">
                                <span class="badge ${bExp.cls}" style="background-color: ${bExp.badgeBg} !important; color: ${bExp.badgeColor} !important; border: 1px solid ${bExp.badgeBorder} !important; font-size: 0.68rem;">
                                    Exp: ${escapeHtml(b.expiry_date)} (${bExp.label})
                                </span>
                                <span class="text-muted">₹${(b.sale_price || item.price).toFixed(2)}</span>
                            </div>
                        </div>
                        <div class="text-end ps-2">
                            <span class="badge ${b.stock <= 5 ? 'bg-danger-subtle text-danger border-danger-subtle' : 'bg-light text-secondary border'} fw-semibold" style="font-size: 0.72rem;">
                                ${b.stock} in stock
                            </span>
                        </div>
                    </div>
                `;
            });

            expiryCellHtml = `
                <div class="dropdown d-inline-block position-relative batch-dropdown-container">
                    <button type="button" 
                            class="btn btn-sm py-1 px-2 font-monospace d-inline-flex align-items-center gap-1.5 border shadow-2xs expiry-picker-btn"
                            style="background-color: ${expStatus.badgeBg} !important; color: ${expStatus.badgeColor} !important; border-color: ${expStatus.badgeBorder} !important; font-size: 0.76rem; border-radius: 6px; font-weight: 600;"
                            onclick="toggleBatchExpiryMenu(event, ${idx})"
                            title="Click to switch batch (${batches.length} available)">
                        <i class="bi ${expStatus.icon}"></i>
                        <span>${escapeHtml(item.expiry_date || 'N/A')}</span>
                        <span class="small opacity-75">(${expStatus.label})</span>
                        <span class="badge bg-white text-dark border ms-1 px-1.5 py-0.5" style="font-size: 0.68rem; font-weight: 700;">${batches.length} batches ▾</span>
                    </button>
                    <div id="batchExpiryMenu_${idx}" class="dropdown-menu shadow-lg p-0 border-0 batch-expiry-menu d-none position-absolute" style="top: 100%; left: 0; margin-top: 4px; min-width: 320px; z-index: 1095;">
                        <div class="px-3 py-2 border-bottom bg-light d-flex justify-content-between align-items-center rounded-top-2" style="font-size: 0.74rem;">
                            <span class="fw-bold text-dark"><i class="ti ti-layers me-1 text-emerald"></i>Select Batch / Expiry</span>
                            <span class="badge bg-emerald-subtle text-emerald border" style="font-size: 0.68rem;">FEFO Earliest First</span>
                        </div>
                        <div class="p-1.5" style="max-height: 230px; overflow-y: auto;">
                            ${batchItemsHtml}
                        </div>
                    </div>
                </div>
            `;
        } else {
            expiryCellHtml = `
                <span class="badge font-monospace px-2 py-1 ${expStatus.cls}" style="font-size: 0.76rem; background-color: ${expStatus.badgeBg} !important; color: ${expStatus.badgeColor} !important; border: 1px solid ${expStatus.badgeBorder} !important;" title="${expStatus.tag}: ${expStatus.label}">
                    <i class="bi ${expStatus.icon} me-1"></i>${escapeHtml(item.expiry_date || 'N/A')}
                    <span class="ms-1 small opacity-75">(${expStatus.label})</span>
                </span>
            `;
        }

        html += `
            <tr class="align-middle cart-row" tabindex="0" data-cart-index="${idx}">
                <td class="text-muted fw-semibold small">${idx + 1}</td>
                <td>
                    <div class="fw-bold text-dark">${escapeHtml(item.medicine_name)}</div>
                    <div class="text-muted small" style="font-size: 0.71rem; display: flex; align-items: center; gap: 4px; flex-wrap: wrap; margin-top: 2px;">
                        <span class="badge font-monospace" style="background:#e0e7ff; color:#3730a3; font-size:0.68rem; padding: 1px 5px;"><i class="ti ti-package me-1"></i>Batch: ${escapeHtml(item.batch_number || 'GEN-01')}</span>
                        ${item.manufacturer ? '<span class="text-primary fw-semibold">' + escapeHtml(item.manufacturer) + '</span> &bull; ' : ''}Cat: ${escapeHtml(item.category)} &bull; ${escapeHtml(item.unit)} &bull; Stock: ${item.max_stock}
                    </div>
                </td>
                <td>${expiryCellHtml}</td>
                <td class="text-end font-monospace text-muted" style="font-size: 0.84rem;">₹${(item.purchase_price || 0).toFixed(2)}</td>
                <td class="text-end fw-bold text-dark font-monospace" style="font-size: 0.88rem;">₹${item.price.toFixed(2)}</td>
                <td class="text-center">
                    <input type="number" min="1" max="${item.max_stock}" class="form-control form-control-sm text-center fw-bold px-2 m-auto cart-qty-input" style="width: 65px; border-radius: 6px; font-size: 0.88rem;" value="${item.quantity}" oninput="onCartQtyInput(this, ${idx})" onblur="onCartQtyBlur(this, ${idx})" onfocus="this.select()" aria-label="Quantity for ${escapeHtml(item.medicine_name)}">
                </td>
                <td class="text-end fw-bold text-dark font-monospace item-line-total">₹${lineTotal.toFixed(2)}</td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger p-1 rounded-2" onclick="removeItemFromCart(${idx})" title="Remove">
                        <i class="ti ti-trash"></i>
                    </button>
                </td>
            </tr>
        `;
    });

    tbody.innerHTML = html;
    recalculateCartTotalsSummary();
}

function toggleBatchExpiryMenu(e, cartIdx) {
    e.stopPropagation();
    const menu = document.getElementById(`batchExpiryMenu_${cartIdx}`);
    if (!menu) return;
    const isHidden = menu.classList.contains('d-none');
    document.querySelectorAll('.batch-expiry-menu').forEach(m => m.classList.add('d-none'));
    if (isHidden) {
        menu.classList.remove('d-none');
    }
}

function selectCartBatch(cartIdx, batchId, batchNumber) {
    if (!cart[cartIdx]) return;
    const med = catalogMedicines.find(m => m.medicine_id === cart[cartIdx].medicine_id);
    if (!med || !med.batches) return;
    
    const targetBatch = med.batches.find(b => (batchId > 0 && b.batch_id == batchId) || b.batch_number === batchNumber);
    if (!targetBatch) return;

    cart[cartIdx].batch_id = targetBatch.batch_id;
    cart[cartIdx].batch_number = targetBatch.batch_number;
    cart[cartIdx].expiry_date = targetBatch.expiry_date;
    cart[cartIdx].purchase_price = targetBatch.purchase_price;
    if (targetBatch.sale_price > 0) {
        cart[cartIdx].price = targetBatch.sale_price;
    }
    cart[cartIdx].max_stock = targetBatch.stock > 0 ? targetBatch.stock : 999;
    if (cart[cartIdx].quantity > cart[cartIdx].max_stock) {
        cart[cartIdx].quantity = cart[cartIdx].max_stock;
        showAutoAddNotice(`Quantity adjusted to ${cart[cartIdx].max_stock} (Max stock for batch ${targetBatch.batch_number})`, 'warning');
    } else {
        showAutoAddNotice(`Switched to Batch ${targetBatch.batch_number} (Exp: ${targetBatch.expiry_date})`, 'success');
    }

    renderCart();
}

function onCartQtyInput(inputElem, idx) {
    let val = parseInt(inputElem.value);
    if (isNaN(val) || val < 1) {
        val = 1;
    }

    const currentItem = cart[idx];
    if (currentItem.max_stock > 0 && val > currentItem.max_stock) {
        alert(`Maximum available stock for batch ${currentItem.batch_number} is ${currentItem.max_stock}.`);
        val = currentItem.max_stock;
        inputElem.value = val;
    }

    currentItem.quantity = val;
    const lineTotal = val * currentItem.price;
    const row = inputElem.closest('tr');
    if (row) {
        const lineTotalCell = row.querySelector('.item-line-total');
        if (lineTotalCell) {
            lineTotalCell.textContent = `₹${lineTotal.toFixed(2)}`;
        }
    }

    recalculateCartTotalsSummary();
}

function onCartQtyBlur(inputElem, idx) {
    let val = parseInt(inputElem.value);
    if (isNaN(val) || val < 1) {
        val = 1;
        inputElem.value = 1;
    }
    cart[idx].quantity = val;
    recalculateCartTotalsSummary();
}

function recalculateCartTotalsSummary() {
    let grandTotal = 0;
    cart.forEach(item => {
        grandTotal += (item.quantity * item.price);
    });

    const totalBadge = document.getElementById('chargeDetailsTotalBadge');
    const grandTotalLbl = document.getElementById('lblGrandTotal');
    const cartJsonInput = document.getElementById('cartItemsJson');

    if (totalBadge) totalBadge.textContent = `Total: ₹${grandTotal.toFixed(2)}`;
    if (grandTotalLbl) grandTotalLbl.textContent = `₹${grandTotal.toFixed(2)}`;

    const payload = cart.map(c => ({
        medicine_id: c.medicine_id,
        quantity: c.quantity,
        discount_percent: 0.0
    }));
    if (cartJsonInput) cartJsonInput.value = JSON.stringify(payload);
}

function removeItemFromCart(idx) {
    cart.splice(idx, 1);
    renderCart();
}

function resetBilling() {
    if (cart.length > 0 && !confirm('Are you sure you want to start a new billing session? Current charges will be cleared.')) {
        return;
    }
    cart = [];
    clearMedicineSearch();
    renderCart();
}

function saveDraft() {
    if (cart.length === 0) {
        alert('Cart is empty. Add charges before saving a draft.');
        return;
    }
    localStorage.setItem('pharmacy_opd_bill_draft', JSON.stringify({
        patient: document.getElementById('patientSelect').value,
        cart: cart,
        saved_at: new Date().toISOString()
    }));
    alert('Draft bill saved successfully in local cache!');
}

function openBillingPreviewModal() {
    if (cart.length === 0) {
        alert('Your bill has no charges. Please add at least one charge item.');
        return;
    }

    const patientName = document.getElementById('hiddenCustomerName').value.trim() || 'Walk-in Customer';
    const patientUhid = document.getElementById('displayPatientUhid').value.trim() || 'OPD-DIRECT';
    const patientMobile = document.getElementById('hiddenCustomerMobile').value.trim() || '-';
    const doctorName = document.getElementById('displayDoctorName').value.trim() || document.getElementById('hiddenDoctorName').value.trim();

    document.getElementById('modalPatientName').textContent = patientName;
    document.getElementById('modalPatientUhid').textContent = patientUhid;
    document.getElementById('modalPatientMobile').textContent = patientMobile;
    document.getElementById('modalDoctorInput').value = doctorName || '';

    // Render Modal Items (Itemized Bill List Table)
    const listBody = document.getElementById('modalChargesList');
    let html = '';
    let subtotal = 0;
    let totalGst = 0;

    cart.forEach((item, idx) => {
        const itemTotal = item.quantity * item.price;
        subtotal += itemTotal;
        const gstVal = itemTotal * ((item.gst_percent || 0) / 100);
        totalGst += gstVal;
        const expStatus = getExpiryStatus(item.expiry_date);

        html += `
            <tr>
                <td class="text-center text-muted fw-semibold py-2.5 px-3" style="font-size: 0.82rem;">${idx + 1}</td>
                <td class="text-start py-2.5 px-3">
                    <div class="fw-bold text-dark" style="font-size: 0.88rem;">${escapeHtml(item.medicine_name)}</div>
                    <div class="text-muted small mt-0.5" style="font-size: 0.72rem;">
                        ${item.manufacturer ? '<span class="text-emerald fw-semibold">' + escapeHtml(item.manufacturer) + '</span> &bull; ' : ''}
                        <span>${escapeHtml(item.category || 'General')}</span> &bull; 
                        <span class="badge bg-light text-dark border px-1.5 py-0.2">${escapeHtml(item.unit || 'unit')}</span>
                    </div>
                </td>
                <td class="text-start py-2.5 px-3">
                    <div class="font-monospace fw-bold text-dark" style="font-size: 0.82rem;">${escapeHtml(item.batch_number || 'STD-01')}</div>
                    <div class="mt-0.5">
                        <span class="badge ${expStatus.cls}" style="background-color: ${expStatus.badgeBg} !important; color: ${expStatus.badgeColor} !important; border: 1px solid ${expStatus.badgeBorder} !important; font-size: 0.68rem;">
                            Exp: ${escapeHtml(item.expiry_date || 'N/A')}
                        </span>
                    </div>
                </td>
                <td class="text-center fw-bold text-dark font-monospace py-2.5 px-2" style="font-size: 0.88rem;">${item.quantity}</td>
                <td class="text-end font-monospace text-secondary py-2.5 px-2" style="font-size: 0.88rem;">₹${item.price.toFixed(2)}</td>
                <td class="text-end font-monospace fw-bold text-dark py-2.5 pe-3 ps-2" style="font-size: 0.92rem;">₹${itemTotal.toFixed(2)}</td>
            </tr>
        `;
    });
    listBody.innerHTML = html;

    recalcModalTotals();

    const previewModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('billingPreviewModal'));
    previewModal.show();
}

function recalcModalTotals() {
    let subtotal = 0;
    let totalGst = 0;
    cart.forEach(item => {
        const itemTotal = item.quantity * item.price;
        subtotal += itemTotal;
        totalGst += itemTotal * ((item.gst_percent || 0) / 100);
    });

    const discPercent = Math.min(50, Math.max(0, parseFloat(document.getElementById('modalDiscountPercent').value) || 0));
    const discountAmt = subtotal * (discPercent / 100);
    const taxable = subtotal - discountAmt;
    const grandTotal = Math.round((taxable + totalGst) * 100) / 100;

    document.getElementById('modalLblSubtotal').textContent = `₹${subtotal.toFixed(2)}`;
    const discRow = document.getElementById('modalRowDiscount');
    if (discRow) {
        if (discountAmt > 0) {
            discRow.classList.remove('d-none');
            discRow.classList.add('d-flex');
            document.getElementById('modalLblDiscountAmt').textContent = `-₹${discountAmt.toFixed(2)}`;
        } else {
            discRow.classList.add('d-none');
            discRow.classList.remove('d-flex');
        }
    }
    document.getElementById('modalLblGst').textContent = `₹${totalGst.toFixed(2)}`;
    document.getElementById('modalLblGrandTotal').textContent = `₹${grandTotal.toFixed(2)}`;

    // Set paid amount default
    const paidInput = document.getElementById('modalPaidInput');
    if (paidInput && (!paidInput.dataset.touched || paidInput.dataset.touched !== 'true')) {
        paidInput.value = grandTotal.toFixed(2);
    }
    recalcModalChange();
}

function recalcModalChange() {
    const grandTotal = parseFloat(document.getElementById('modalLblGrandTotal').textContent.replace('₹', '')) || 0;
    const paidInput = document.getElementById('modalPaidInput');
    const paid = parseFloat(paidInput ? paidInput.value : 0) || 0;
    const diff = paid - grandTotal;
    const lbl = document.getElementById('modalLblChangeOrDue');

    if (lbl) {
        if (diff >= 0) {
            lbl.className = 'fw-bold font-monospace text-success';
            lbl.textContent = `Change: ₹${diff.toFixed(2)}`;
        } else {
            lbl.className = 'fw-bold font-monospace text-danger';
            lbl.textContent = `Due: ₹${Math.abs(diff).toFixed(2)}`;
        }
    }
}

function onModalPaymentModeChange(mode) {
    const refGroup = document.getElementById('modalRefGroup');
    if (refGroup) {
        if (mode === 'UPI' || mode === 'CARD' || mode === 'BANK_TRANSFER') {
            refGroup.classList.remove('d-none');
        } else {
            refGroup.classList.add('d-none');
        }
    }
}

function previewCurrentBill(format = 'standard') {
    if (cart.length === 0) {
        alert('Your cart is empty. Please select at least one medicine.');
        return;
    }

    const patientName = document.getElementById('hiddenCustomerName').value.trim() || 'Walk-in Customer';
    const patientAddress = 'KHARADI, PUNE';
    const doctorName = document.getElementById('modalDoctorInput').value.trim() || 'DR. VAISHALI LONDHE';
    const subtotal = parseFloat(document.getElementById('modalLblSubtotal').textContent.replace('₹', '')) || 0;
    const discountPercent = parseFloat(document.getElementById('modalDiscountPercent').value) || 0.0;
    const discountAmt = parseFloat(document.getElementById('modalLblDiscountAmt')?.textContent.replace('-₹', '').replace('₹', '') || '0') || 0;
    const gstAmt = parseFloat(document.getElementById('modalLblGst').textContent.replace('₹', '')) || 0;
    const grandTotal = parseFloat(document.getElementById('modalLblGrandTotal').textContent.replace('₹', '')) || 0;
    const billDate = new Date().toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }).replace(/ /g, '-');

    let rowsHtml = '';
    cart.forEach((ci) => {
        const qty = ci.quantity;
        const rate = ci.price;
        const lineSub = (qty * rate).toFixed(2);
        const gstPct = ci.gst_percent || 12;
        const sgstPct = (gstPct / 2).toFixed(2);
        const cgstPct = (gstPct / 2).toFixed(2);
        const lineGst = (lineSub * (gstPct / 100)).toFixed(2);
        const sgstAmt = (lineGst / 2).toFixed(2);
        const cgstAmt = (lineGst - sgstAmt).toFixed(2);
        const exp = ci.expiry_date ? ci.expiry_date.substring(5, 7) + '/' + ci.expiry_date.substring(2, 4) : '--/--';
        const batch = ci.batch_number || 'STD-01';
        const pack = ci.pack_size || '1 STR';
        const comp = ci.manufacturer ? ci.manufacturer.substring(0, 5).toUpperCase() : 'GEN';

        rowsHtml += `
            <tr class="item-row">
                <td class="text-center">${qty}</td>
                <td class="text-center">${pack}</td>
                <td class="text-center">${comp}</td>
                <td class="text-left" style="padding-left: 4px;">${ci.medicine_name.toUpperCase()}</td>
                <td class="text-center">${batch}</td>
                <td class="text-center">${exp}</td>
                <td class="text-right" style="padding-right: 4px;">${rate.toFixed(2)}</td>
                <td class="text-right" style="padding-right: 4px;">${rate.toFixed(2)}</td>
                <td class="text-center">30049</td>
                <td class="text-right" style="font-size: 9px;">${sgstPct}</td>
                <td class="text-right" style="font-size: 9px;">${sgstAmt}</td>
                <td class="text-right" style="font-size: 9px;">${cgstPct}</td>
                <td class="text-right" style="font-size: 9px;">${cgstAmt}</td>
                <td class="text-right fw-bold" style="padding-right: 4px;">${lineSub}</td>
            </tr>
        `;
    });

    for (let i = cart.length; i < 10; i++) {
        rowsHtml += `<tr class="blank-row"><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr>`;
    }

    const previewDoc = `
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Bill Preview - ${patientName}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, "Helvetica Neue", Helvetica, sans-serif; font-size: 11px; line-height: 1.35; color: #000; background: #525659; min-height: 100vh; display: flex; flex-direction: column; align-items: center; padding: 20px 10px; }
        .no-print-bar { width: 100%; max-width: 900px; margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; background: #fff; padding: 10px 18px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 16px; font-size: 12.5px; font-weight: 600; border-radius: 6px; cursor: pointer; border: none; }
        .btn-primary { background: #0284c7; color: #fff; }
        .btn-secondary { background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; }
        .invoice-sheet { background: #fff; width: 100%; max-width: 900px; padding: 12px 14px; border: 1px solid #000; color: #000; }
        .invoice-title { text-align: center; font-size: 11.5px; font-weight: 700; padding-bottom: 4px; margin-bottom: 4px; border-bottom: 1px solid #000; }
        .header-grid { display: grid; grid-template-columns: 42% 23% 35%; border: 1px solid #000; margin-bottom: 4px; font-size: 10.5px; line-height: 1.35; }
        .header-box { padding: 4px 6px; }
        .header-box:not(:last-child) { border-right: 1px solid #000; }
        .shop-name { font-size: 13px; font-weight: 800; margin-bottom: 2px; }
        .header-line { display: flex; margin-bottom: 1px; }
        .header-label { font-weight: 600; min-width: 80px; }
        .invoice-table { width: 100%; border-collapse: collapse; font-size: 10px; table-layout: fixed; margin-bottom: 4px; }
        .invoice-table th { border: 1px solid #000; padding: 3px 2px; font-weight: 700; text-align: center; font-size: 9.5px; }
        .invoice-table td { border-left: 1px solid #000; border-right: 1px solid #000; padding: 2.5px 3px; font-size: 10px; }
        .invoice-table tr.item-row td { height: 18px; }
        .invoice-table tr.blank-row td { height: 16px; }
        .text-center { text-align: center; } .text-right { text-align: right; } .text-left { text-align: left; } .fw-bold { font-weight: 700; }
        .footer-grid { display: grid; grid-template-columns: 33% 37% 30%; border: 1px solid #000; font-size: 10.5px; min-height: 85px; }
        .footer-box { padding: 4px 6px; display: flex; flex-direction: column; justify-content: space-between; }
        .footer-box:not(:last-child) { border-right: 1px solid #000; }
        .summary-line { display: flex; justify-content: space-between; margin-bottom: 1.5px; }
        .summary-label { font-weight: 600; } .summary-val { font-weight: 700; min-width: 65px; text-align: right; }
        @media print {
            @page { size: A4 portrait; margin: 6mm; }
            body { background: #fff !important; padding: 0 !important; }
            .no-print-bar { display: none !important; }
            .invoice-sheet { box-shadow: none !important; padding: 6mm !important; }
        }
    </style>
</head>
<body>
    <div class="no-print-bar">
        <div style="font-weight:700; display:flex; align-items:center; gap:8px;"><i class="bi bi-receipt text-primary"></i> Bill Preview (Unconfirmed Draft)</div>
        <div style="display:flex; gap:8px;">
            <button class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print Preview</button>
            <button class="btn btn-secondary" onclick="window.close()"><i class="bi bi-x-lg"></i> Close Preview</button>
        </div>
    </div>
    <div class="invoice-sheet">
        <div class="invoice-title">GST TAX INVOICE</div>
        <div class="header-grid">
            <div class="header-box">
                <div class="shop-name">VATSALYA MEDICAL</div>
                <div>1ST FLR, VATSALYA HOSPITAL, GALAXY PATHARE PLAZA,</div>
                <div>SAINATH NAGAR, KHARADI, PUNE-411014 Galaxy Pathare</div>
                <div><strong>DL No.</strong> : 20-276335,21-276336-MH-PZ1</div>
                <div><strong>GST No.</strong> : 27AAQFV6256M1Z8</div>
            </div>
            <div class="header-box">
                <div class="header-line"><span class="header-label">Bill No.</span><span>: <strong>'CR' DRAFT-PREVIEW</strong></span></div>
                <div class="header-line"><span class="header-label">Date</span><span>: ${billDate}</span></div>
            </div>
            <div class="header-box">
                <div class="header-line"><span class="header-label">Patient Name</span><span>: ${patientName.toUpperCase()}</span></div>
                <div class="header-line"><span class="header-label">Patient Add</span><span>: ${patientAddress.toUpperCase()}</span></div>
                <div class="header-line"><span class="header-label">Doctor Name</span><span>: ${doctorName.toUpperCase()}</span></div>
                <div class="header-line"><span class="header-label">Doctor Address</span><span>: VATSALYA HOSPITAL</span></div>
            </div>
        </div>
        <table class="invoice-table">
            <colgroup>
                <col style="width: 4%;"><col style="width: 7%;"><col style="width: 6%;"><col style="width: 29%;">
                <col style="width: 10%;"><col style="width: 6%;"><col style="width: 7%;"><col style="width: 7%;">
                <col style="width: 6%;"><col style="width: 4%;"><col style="width: 5%;"><col style="width: 4%;">
                <col style="width: 5%;"><col style="width: 9%;">
            </colgroup>
            <thead>
                <tr>
                    <th rowspan="2">Qty</th><th rowspan="2">Pack</th><th rowspan="2">Comp</th><th rowspan="2" class="text-left" style="padding-left:4px;">Description</th>
                    <th rowspan="2">Batch</th><th rowspan="2">Exp</th><th rowspan="2">MRP</th><th rowspan="2">Rate</th><th rowspan="2">HSN</th>
                    <th colspan="2" style="border-bottom:1px solid #000;">SGST</th><th colspan="2" style="border-bottom:1px solid #000;">CGST</th><th rowspan="2">Amount</th>
                </tr>
                <tr><th style="font-size:8.5px; padding:1px;">%</th><th style="font-size:8.5px; padding:1px;">Amt</th><th style="font-size:8.5px; padding:1px;">%</th><th style="font-size:8.5px; padding:1px;">Amt</th></tr>
            </thead>
            <tbody>${rowsHtml}</tbody>
        </table>
        <div class="footer-grid">
            <div class="footer-box">
                <div>5% / 12% GST : ${gstAmt.toFixed(2)}</div>
                <div style="font-size:9px; border-top:1px dashed #777; margin-top:auto; padding-top:2px;">E &amp; O E Subject to Pune Jurisdiction</div>
            </div>
            <div class="footer-box" style="text-align:center; align-items:center;">
                <div style="font-weight:700;">GET WELL SOON..............</div>
                <div style="font-size:10px; font-weight:600;">For VATSALYA MEDICAL</div>
                <div style="height:28px;"></div>
                <div style="font-size:9.5px; font-weight:700;">PHARMACIST SIGN</div>
            </div>
            <div class="footer-box">
                <div class="summary-line"><span class="summary-label">GST Amt</span><span class="summary-val">: ${gstAmt.toFixed(2)}</span></div>
                <div class="summary-line"><span class="summary-label">Gross Amt</span><span class="summary-val">: ${subtotal.toFixed(2)}</span></div>
                <div class="summary-line"><span class="summary-label">Disc</span><span class="summary-val">: ${discountAmt.toFixed(2)}</span></div>
                <div class="summary-line" style="font-size:11px;"><span class="summary-label"><strong>Amount</strong></span><span class="summary-val">: <strong>${grandTotal.toFixed(2)}</strong></span></div>
                <div style="font-size:9px; text-align:right; border-top:1px dotted #888; margin-top:auto; padding-top:2px;">Page No. : 1</div>
            </div>
        </div>
    </div>
</body>
</html>
    `;

    const win = window.open('', '_blank');
    if (win) {
        win.document.open();
        win.document.write(previewDoc);
        win.document.close();
    }
}

// 8. Final Submit Sale
function submitFinalSale() {
    const grandTotal = parseFloat(document.getElementById('modalLblGrandTotal').textContent.replace('₹', '')) || 0;
    const paidAmount = parseFloat(document.getElementById('modalPaidInput').value) || grandTotal;
    const discountPercent = parseFloat(document.getElementById('modalDiscountPercent').value) || 0.0;
    const paymentMode = document.getElementById('modalPaymentMode').value;
    const paymentRef = document.getElementById('modalPaymentRef').value.trim();
    const doctor = document.getElementById('modalDoctorInput').value.trim();
    const notes = document.getElementById('modalNotesInput').value.trim();

    document.getElementById('hiddenPaidAmount').value = paidAmount;
    document.getElementById('hiddenDiscountPercent').value = discountPercent;
    document.getElementById('hiddenDiscountValue').value = discountPercent;
    document.getElementById('hiddenPaymentMode').value = paymentMode;
    document.getElementById('hiddenPaymentRef').value = paymentRef;
    document.getElementById('hiddenDoctorName').value = doctor;
    document.getElementById('hiddenNotes').value = notes;

    const btn = document.getElementById('modalBtnConfirmSale');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Generating Bill...';

    document.getElementById('posSaleForm').submit();
}

// Initialize on page load: trigger Category change to load initial medicines
document.addEventListener('DOMContentLoaded', function() {
    onCategorySelectChange('');

    // Ensure patient suggestion dropdown disappears whenever New Patient modal opens or closes
    const newOpdModalEl = document.getElementById('newOpdPatientModal');
    if (newOpdModalEl) {
        newOpdModalEl.addEventListener('show.bs.modal', function () {
            const pList = document.getElementById('counterPatientSuggestionsList');
            if (pList) pList.classList.add('d-none');
            const mList = document.getElementById('medicineSuggestionsList');
            if (mList) mList.classList.add('d-none');
            selectedCounterPatientIndex = -1;
        });
        newOpdModalEl.addEventListener('hidden.bs.modal', function () {
            const pList = document.getElementById('counterPatientSuggestionsList');
            if (pList) pList.classList.add('d-none');
            selectedCounterPatientIndex = -1;
        });
    }

    // Close suggestions on click outside
    document.addEventListener('click', function(e) {
        const pList = document.getElementById('counterPatientSuggestionsList');
        const pInput = document.getElementById('counterPatientSearchInput');
        if (pList && !pList.contains(e.target) && e.target !== pInput) {
            pList.classList.add('d-none');
        }

        const mList = document.getElementById('medicineSuggestionsList');
        const mInput = document.getElementById('chargeMedicineInput');
        if (mList && !mList.contains(e.target) && e.target !== mInput) {
            mList.classList.add('d-none');
        }

        if (!e.target.closest('.batch-dropdown-container')) {
            document.querySelectorAll('.batch-expiry-menu').forEach(m => m.classList.add('d-none'));
        }
    });

    // Global keyboard shortcuts: F4 for Patient search, F2 for Medicine search
    document.addEventListener('keydown', function(e) {
        if (e.key === 'F4') {
            e.preventDefault();
            const pInput = document.getElementById('counterPatientSearchInput');
            if (pInput) {
                pInput.focus();
                pInput.select();
                onCounterPatientSearchFocus();
            }
        } else if (e.key === 'F2') {
            e.preventDefault();
            const mInput = document.getElementById('chargeMedicineInput');
            if (mInput) {
                mInput.focus();
                mInput.select();
            }
        }
    });
});
</script>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
