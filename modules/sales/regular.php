<?php
// modules/sales/regular.php - Patient-Linked IPD / Regular Sale Interface

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

require_permission('pharmacy.sales.view');

$salesService = new SalesService($pdo);

$error = null;
$success = null;
$completedSale = null;

// AJAX Handler for Instant IPD Patient Registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_ipd_register') {
    header('Content-Type: application/json');
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid or expired security token.']);
        exit;
    }

    $name = trim($_POST['patient_name'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $gender = trim($_POST['gender'] ?? 'Other');
    $ward = trim($_POST['ward_name'] ?? 'General Ward');
    $bed = trim($_POST['bed_number'] ?? 'Bed-01');
    $doctor = trim($_POST['doctor_name'] ?? 'Duty Doctor');
    $diagnosis = trim($_POST['diagnosis'] ?? 'Inpatient Care');

    if ($name === '') {
        echo json_encode(['success' => false, 'message' => 'Patient name is required.']);
        exit;
    }

    try {
        require_once __DIR__ . '/../../app/Services/DocumentSequenceService.php';
        $seqSvc = new Pharmacy\Services\DocumentSequenceService($pdo);
        $newPatientNo = $seqSvc->generate('PATIENT_NO');

        $uhid = 'VH-' . date('Y') . '-' . str_pad((string)rand(100, 9999), 4, '0', STR_PAD_LEFT);
        $ipdNo = 'IPD/' . date('Y') . '/' . strtoupper(date('M')) . '/' . str_pad((string)rand(1, 99), 2, '0', STR_PAD_LEFT);

        // Try inserting into hospital DB if connected
        $hospitalPatientId = null;
        try {
            $hospitalPdo = \Pharmacy\Database\Database::getHospitalConnection();
            if ($hospitalPdo) {
                $hPatStmt = $hospitalPdo->prepare("INSERT INTO patients (first_name, last_name, phone, gender, patient_code, status, created_at, updated_at) VALUES (?, '', ?, ?, ?, 'Active', NOW(), NOW())");
                $hPatStmt->execute([$name, $mobile, $gender, $uhid]);
                $hospitalPatientId = (int)$hospitalPdo->lastInsertId();

                $wStmt = $hospitalPdo->prepare("SELECT ward_id FROM wards WHERE ward_name = ? LIMIT 1");
                $wStmt->execute([$ward]);
                $wardId = $wStmt->fetchColumn() ?: null;

                $bStmt = $hospitalPdo->prepare("SELECT bed_id FROM beds WHERE bed_number = ? LIMIT 1");
                $bStmt->execute([$bed]);
                $bedId = $bStmt->fetchColumn() ?: null;

                $dStmt = $hospitalPdo->prepare("SELECT doctor_id FROM doctors WHERE name LIKE ? LIMIT 1");
                $dStmt->execute(['%' . $doctor . '%']);
                $doctorId = $dStmt->fetchColumn() ?: null;

                $admStmt = $hospitalPdo->prepare("
                    INSERT INTO admissions (patient_id, doctor_id, admission_type, ward_id, bed_id, admission_date, diagnosis, status, ipd_number, created_at)
                    VALUES (?, ?, 'Emergency', ?, ?, NOW(), ?, 'Admitted', ?, NOW())
                ");
                $admStmt->execute([$hospitalPatientId, $doctorId, $wardId, $bedId, $diagnosis, $ipdNo]);
            }
        } catch (Exception $hex) {
            // Keep local fallback if hospital insertion encounters constraints
        }

        // Insert into pharmacy_patients
        $insStmt = $pdo->prepare("
            INSERT INTO pharmacy_patients (pharmacy_patient_no, hospital_patient_id, hospital_uhid, name, mobile, gender, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 'Active', NOW(), NOW())
        ");
        $insStmt->execute([$newPatientNo, $hospitalPatientId, $uhid, $name, $mobile, $gender]);
        $newPharmacyPatientId = (int)$pdo->lastInsertId();

        echo json_encode([
            'success' => true,
            'message' => "Patient {$name} registered to IPD successfully!",
            'patient' => [
                'patient_id'       => $newPharmacyPatientId,
                'name'             => $name,
                'hospital_uhid'    => $uhid,
                'ipd_admission_no' => $ipdNo,
                'ipd_ward'         => $ward,
                'ipd_bed'          => $bed,
                'doctor_name'      => $doctor,
                'mobile'           => $mobile,
                'label'            => "{$uhid} - {$name} ({$ward} / {$bed})"
            ]
        ]);
        exit;
    } catch (Exception $ex) {
        echo json_encode(['success' => false, 'message' => 'Error registering IPD patient: ' . $ex->getMessage()]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_ipd_sale') {
    require_permission('pharmacy.sales.create');

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid or expired security token.";
    } else {
        try {
            $patientId = !empty($_POST['patient_id']) ? (int)$_POST['patient_id'] : null;
            $customerName = trim($_POST['patient_name'] ?? '');
            $hospitalUhid = trim($_POST['hospital_uhid'] ?? '');
            $ipdAdmissionId = trim($_POST['ipd_admission_no'] ?? '');
            $ipdWard = trim($_POST['ipd_ward'] ?? '');
            $ipdBed = trim($_POST['ipd_bed'] ?? '');
            $doctorName = trim($_POST['doctor_name'] ?? '');
            $paymentMode = trim($_POST['payment_mode'] ?? 'CREDIT');
            $paidAmount = (float)($_POST['paid_amount'] ?? 0.0);
            $notes = trim($_POST['notes'] ?? '');
            $discountType = strtoupper(trim($_POST['discount_type'] ?? 'PERCENT'));
            $discountVal = max(0.0, (float)($_POST['discount_value'] ?? 0.0));
            $discountPercent = 0.0;

            if ($customerName === '') {
                throw new Exception("Please search and select an inpatient or enter the patient name.");
            }

            // Auto-link or register pharmacy_patient record if UHID exists
            if (empty($patientId) && !empty($hospitalUhid)) {
                $pStmt = $pdo->prepare("SELECT id FROM pharmacy_patients WHERE hospital_uhid = ? LIMIT 1");
                $pStmt->execute([$hospitalUhid]);
                $foundId = $pStmt->fetchColumn();
                if ($foundId) {
                    $patientId = (int)$foundId;
                } else {
                    try {
                        require_once __DIR__ . '/../../app/Services/DocumentSequenceService.php';
                        $seqSvc = new Pharmacy\Services\DocumentSequenceService($pdo);
                        $newPNo = $seqSvc->generate('PATIENT_NO');
                        $ins = $pdo->prepare("
                            INSERT INTO pharmacy_patients (pharmacy_patient_no, hospital_uhid, name, status, created_at, updated_at)
                            VALUES (?, ?, ?, 'Active', NOW(), NOW())
                        ");
                        $ins->execute([$newPNo, $hospitalUhid, $customerName]);
                        $patientId = (int)$pdo->lastInsertId();
                    } catch (Exception $ex) {
                        // Keep patientId null if insertion error
                    }
                }
            }

            $medIds = $_POST['medicine_id'] ?? [];
            $batchIds = $_POST['batch_id'] ?? [];
            $quantities = $_POST['quantity'] ?? [];
            $itemsPayload = [];

            foreach ($medIds as $k => $mId) {
                $mId = (int)$mId;
                $q = (int)($quantities[$k] ?? 0);
                $bId = (int)($batchIds[$k] ?? 0);
                if ($mId > 0 && $q > 0) {
                    $item = [
                        'medicine_id'      => $mId,
                        'quantity'         => $q,
                        'discount_percent' => 0.0
                    ];
                    if ($bId > 0) {
                        $item['batch_id'] = $bId;
                    }
                    $itemsPayload[] = $item;
                }
            }

            if (empty($itemsPayload) && !empty($_POST['cart_items'])) {
                $rawCart = json_decode($_POST['cart_items'], true);
                if (is_array($rawCart)) {
                    foreach ($rawCart as $ci) {
                        $mId = (int)($ci['medicine_id'] ?? 0);
                        $q = (int)($ci['quantity'] ?? 0);
                        $bId = (int)($ci['batch_id'] ?? 0);
                        if ($mId > 0 && $q > 0) {
                            $item = [
                                'medicine_id'      => $mId,
                                'quantity'         => $q,
                                'discount_percent' => 0.0
                            ];
                            if ($bId > 0) {
                                $item['batch_id'] = $bId;
                            }
                            $itemsPayload[] = $item;
                        }
                    }
                }
            }

            if (empty($itemsPayload)) {
                throw new Exception("Please add at least one medication item.");
            }

            if ($discountType === 'FLAT' && $discountVal > 0) {
                $itemsSubtotal = 0.0;
                foreach ($itemsPayload as $it) {
                    $mId = (int)$it['medicine_id'];
                    $q = (int)$it['quantity'];
                    if ($mId > 0 && $q > 0) {
                        $mStmt = $pdo->prepare("SELECT price FROM medicines WHERE medicine_id = ?");
                        $mStmt->execute([$mId]);
                        $price = (float)$mStmt->fetchColumn();
                        $itemsSubtotal += ($price * $q);
                    }
                }
                if ($itemsSubtotal > 0) {
                    $discountPercent = min(50.0, round(($discountVal / $itemsSubtotal) * 100.0, 4));
                }
            } else {
                $discountPercent = min(50.0, $discountVal);
            }

            $saleData = [
                'sale_type'        => 'IPD_SALE',
                'patient_id'       => $patientId,
                'customer_name'    => $customerName !== '' ? $customerName : 'IPD Patient',
                'ipd_admission_id' => $ipdAdmissionId,
                'ipd_ward'         => $ipdWard,
                'ipd_bed'          => $ipdBed,
                'doctor_name'      => $doctorName,
                'discount_percent' => $discountPercent,
                'is_credit'        => ($paymentMode === 'CREDIT'),
                'notes'            => $notes
            ];

            $paymentData = [
                'amount' => $paidAmount,
                'mode'   => $paymentMode
            ];

            $completedSale = $salesService->createSale(
                $saleData,
                $itemsPayload,
                $paymentData,
                $_SESSION['user_id'] ?? null,
                has_permission('pharmacy.discount.override')
            );

            $success = "IPD Sale #{$completedSale['sale_number']} charged successfully to {$customerName}!";
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Fetch ONLY genuine Admitted Inpatients from Hospital and active IPD records
$patientsMap = [];

// Available Wards & Doctors for New IPD Registry Modal
$availableWards = ['General Ward-209', 'General Ward-205', 'Private AC', 'Private Room', 'Deluxe Room', 'Emergency Ward'];
$availableDoctors = [];

try {
    $hospitalPdo = \Pharmacy\Database\Database::getHospitalConnection();
    if ($hospitalPdo) {
        // Fetch real hospital wards and doctors
        $wList = $hospitalPdo->query("SELECT ward_name FROM wards ORDER BY ward_name ASC")->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($wList)) {
            $availableWards = array_values(array_unique(array_filter($wList)));
        }
        $dList = $hospitalPdo->query("SELECT name FROM doctors WHERE status = 'Active' ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($dList)) {
            $availableDoctors = array_values(array_unique(array_filter($dList)));
        }

        // Fetch actively admitted IPD patients with comprehensive hospital metadata
        $hList = $hospitalPdo->query("
            SELECT 
                a.admission_id,
                a.ipd_number,
                a.admission_date,
                a.admission_type,
                a.is_mediclaim,
                a.diagnosis,
                a.patient_id as hospital_patient_id,
                p.patient_code as hospital_uhid,
                p.patient_prefix,
                p.first_name,
                p.last_name,
                CONCAT(COALESCE(p.patient_prefix, ''), ' ', COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, '')) as name,
                p.phone as mobile,
                p.gender,
                p.referred_by,
                w.ward_name as ipd_ward,
                b.bed_number as ipd_bed,
                d.name as doctor_name
            FROM admissions a
            JOIN patients p ON a.patient_id = p.patient_id
            LEFT JOIN wards w ON a.ward_id = w.ward_id
            LEFT JOIN beds b ON a.bed_id = b.bed_id
            LEFT JOIN doctors d ON a.doctor_id = d.doctor_id
            WHERE a.status = 'Admitted'
            ORDER BY a.admission_id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Pre-calculate pharmacy charges for these admissions
        $chargesMap = [];
        try {
            $chargeRows = $pdo->query("
                SELECT ipd_admission_id, SUM(net_amount) as total_charges
                FROM pharmacy_sales
                WHERE sale_type = 'IPD_SALE' AND ipd_admission_id IS NOT NULL AND ipd_admission_id != ''
                GROUP BY ipd_admission_id
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($chargeRows as $cr) {
                $chargesMap[trim($cr['ipd_admission_id'])] = (float)$cr['total_charges'];
            }
        } catch (Exception $ce) {}

        // Pre-fetch matching pharmacy_patients to link pharmacy_patient_id
        $uhids = array_filter(array_column($hList, 'hospital_uhid'));
        $pharmMap = [];
        if (!empty($uhids)) {
            $inClause = implode(',', array_fill(0, count($uhids), '?'));
            $pharmStmt = $pdo->prepare("SELECT id, hospital_uhid FROM pharmacy_patients WHERE hospital_uhid IN ($inClause)");
            $pharmStmt->execute(array_values($uhids));
            foreach ($pharmStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $pharmMap[strtoupper(trim($row['hospital_uhid']))] = (int)$row['id'];
            }
        }

        foreach ($hList as $hp) {
            $uhid = trim($hp['hospital_uhid'] ?? '');
            $key = $uhid !== '' ? 'UHID_' . strtoupper($uhid) : 'HADM_' . $hp['admission_id'];
            $pId = $uhid !== '' && isset($pharmMap[strtoupper($uhid)]) ? $pharmMap[strtoupper($uhid)] : null;
            
            $fullName = trim(($hp['patient_prefix'] ? $hp['patient_prefix'] . ' ' : '') . $hp['first_name'] . ' ' . $hp['last_name']);
            if ($fullName === '') $fullName = 'Inpatient #' . $hp['admission_id'];

            $admDateFormatted = !empty($hp['admission_date']) ? date('d-m-y h:i A', strtotime($hp['admission_date'])) : date('d-m-y h:i A');
            $admType = !empty($hp['admission_type']) ? $hp['admission_type'] : ($hp['is_mediclaim'] === 'Yes' ? 'Cashless' : 'Paid');
            $ipdNo = $hp['ipd_number'] ?: ('IPD/' . date('Y') . '/' . str_pad($hp['admission_id'], 4, '0', STR_PAD_LEFT));
            $chargesTotal = $chargesMap[$ipdNo] ?? ($chargesMap[$hp['admission_id']] ?? 0.0);

            $patientsMap[$key] = [
                'patient_id'         => $pId,
                'hospital_patient_id'=> (int)$hp['hospital_patient_id'],
                'admission_id'       => (int)$hp['admission_id'],
                'pharmacy_patient_no'=> '',
                'hospital_uhid'      => $uhid,
                'name'               => $fullName,
                'mobile'             => $hp['mobile'] ?? '',
                'gender'             => $hp['gender'] ?? '',
                'ipd_admission_no'   => $ipdNo,
                'admission_date'     => $admDateFormatted,
                'admission_type'     => $admType,
                'referred_by'        => $hp['referred_by'] ?: 'Self',
                'diagnosis'          => $hp['diagnosis'] ?: 'Inpatient Care',
                'ipd_ward'           => $hp['ipd_ward'] ?: 'General Ward',
                'ipd_bed'            => $hp['ipd_bed'] ?: 'Bed-01',
                'doctor_name'        => $hp['doctor_name'] ?: 'Duty Doctor',
                'charges_total'      => $chargesTotal,
                'source'             => 'Admitted Inpatient'
            ];
        }
    }
} catch (Exception $e) {
    // Graceful fallback
}

// Fallback/Standalone: Only patients with recorded IPD sales (NOT regular OPD patients)
if (empty($patientsMap)) {
    $ipdSalesPatients = $pdo->query("
        SELECT 
            p.id,
            p.pharmacy_patient_no,
            p.hospital_uhid,
            p.name,
            p.mobile,
            p.gender,
            s.ipd_admission_id as ipd_admission_no,
            s.ipd_ward,
            s.ipd_bed,
            s.doctor_name
        FROM pharmacy_sales s
        JOIN pharmacy_patients p ON s.patient_id = p.id
        WHERE s.sale_type = 'IPD_SALE' AND s.ipd_admission_id IS NOT NULL AND s.ipd_admission_id != ''
        ORDER BY s.sale_id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($ipdSalesPatients as $sp) {
        $key = !empty($sp['hospital_uhid']) ? 'UHID_' . strtoupper(trim($sp['hospital_uhid'])) : 'PID_' . $sp['id'];
        if (!isset($patientsMap[$key])) {
            $patientsMap[$key] = [
                'patient_id'         => (int)$sp['id'],
                'pharmacy_patient_no'=> $sp['pharmacy_patient_no'] ?? '',
                'hospital_uhid'      => $sp['hospital_uhid'] ?? '',
                'name'               => $sp['name'],
                'mobile'             => $sp['mobile'] ?? '',
                'gender'             => $sp['gender'] ?? '',
                'ipd_admission_no'   => $sp['ipd_admission_no'],
                'ipd_ward'           => $sp['ipd_ward'] ?: 'General Ward',
                'ipd_bed'            => $sp['ipd_bed'] ?: 'Bed-01',
                'doctor_name'        => $sp['doctor_name'] ?: 'Duty Doctor',
                'source'             => 'Admitted Inpatient'
            ];
        }
    }
}

if (empty($availableDoctors)) {
    $availableDoctors = ['Dr. Anjali Mehta', 'Dr. Ramesh Joshi', 'Dr. Vikram Patil', 'Dr. Sneha Shah', 'Duty Doctor'];
}

usort($patientsMap, function($a, $b) {
    $cleanA = preg_replace('/^(mr\.|mrs\.|miss|master|baby|b\/o|s\/o|d\/o|w\/o|dr\.)\s+/i', '', trim($a['name'] ?? ''));
    $cleanB = preg_replace('/^(mr\.|mrs\.|miss|master|baby|b\/o|s\/o|d\/o|w\/o|dr\.)\s+/i', '', trim($b['name'] ?? ''));
    return strcasecmp($cleanA, $cleanB);
});

$ipdPatientsList = array_values($patientsMap);

// Fetch active medicines with all their batches, expiry date, manufacturer, and mfg rate (FEFO sorted)
$medRows = $pdo->query("
    SELECT m.medicine_id, m.medicine_name, m.generic_name, m.composition, m.dosage_form, m.category, m.unit, m.strength,
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
            'composition'     => $r['composition'] ?? '',
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
$medicinesCatalog = array_values($allMedicines);

// Set default top-level batch info as the earliest expiring batch (FEFO)
foreach ($medicinesCatalog as &$m) {
    if (!empty($m['batches'])) {
        $firstBatch = $m['batches'][0];
        $m['batch_id']           = $firstBatch['batch_id'];
        $m['batch_number']       = $firstBatch['batch_number'];
        $m['expiry_date']        = $firstBatch['expiry_date'];
        $m['manufacturing_date'] = $firstBatch['manufacturing_date'];
        $m['purchase_price']     = $firstBatch['purchase_price'];
        $m['price']              = $firstBatch['sale_price'];
        $m['mrp']                = $firstBatch['sale_price'];
    } else {
        $m['batch_id']           = 0;
        $m['batch_number']       = 'GEN-01';
        $m['expiry_date']        = '2028-12-31';
        $m['manufacturing_date'] = '2026-01-01';
    }
}
unset($m);

// Extract unique categories
$categories = [];
foreach ($medicinesCatalog as $m) {
    $cat = trim($m['category'] ?? '');
    if ($cat !== '' && !in_array($cat, $categories)) {
        $categories[] = $cat;
    }
}
if (empty($categories)) {
    $categories = ['Tablets', 'Syrups', 'Injections', 'Infusions', 'Ointments', 'Consumables', 'General'];
}

$currentUser = auth_user();
$page_title = 'Create IPD Bill';

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
    border-left: 4px solid transparent !important;
    background-color: #ffffff;
    cursor: pointer !important;
    transition: all 0.15s ease !important;
}
.med-suggest-item:hover, .med-suggest-item.active-nav {
    background-color: #ecfdf5 !important;
    border-left: 4px solid #059669 !important;
}
.med-suggest-item:hover .med-name-title, .med-suggest-item.active-nav .med-name-title {
    color: #047857 !important;
}
.search-highlight {
    background: transparent !important;
    background-color: transparent !important;
    color: #059669 !important;
    font-weight: 800 !important;
    padding: 0 !important;
    border-radius: 0 !important;
}
.patient-match-color {
    background: transparent !important;
    background-color: transparent !important;
    color: #059669 !important;
    font-weight: 800 !important;
    padding: 0 !important;
    border-radius: 0 !important;
}
#cartTable tbody tr.cart-row td {
    padding: 10px 10px !important;
    vertical-align: middle !important;
    height: auto !important;
}
.batch-dropdown-container {
    position: relative !important;
    display: inline-block !important;
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
    position: absolute !important;
    top: calc(100% + 4px) !important;
    left: 0 !important;
    border-radius: 10px !important;
    border: 1px solid #cbd5e1 !important;
    background: #ffffff !important;
    background-color: #ffffff !important;
    box-shadow: 0 16px 36px -4px rgba(15, 23, 42, 0.28), 0 4px 12px rgba(0, 0, 0, 0.12) !important;
    min-width: 320px !important;
    max-width: 380px !important;
    z-index: 1099 !important;
}
.batch-option-item {
    transition: all 0.15s ease !important;
    border-radius: 8px !important;
    border: 1px solid transparent !important;
    cursor: pointer !important;
}
.batch-option-item:hover {
    background-color: #f1f5f9 !important;
}
.batch-option-item.active-batch {
    background-color: #f0fdf4 !important;
    border-color: #059669 !important;
}

/* Pharmacy Inpatient Registry & Table Styling */
.badge-type-cashless {
    background-color: #fee2e2 !important;
    color: #dc2626 !important;
    border: 1px solid #fca5a5 !important;
    font-weight: 700 !important;
    font-size: 0.72rem !important;
    padding: 3px 8px !important;
    border-radius: 6px !important;
}
.badge-type-paid {
    background-color: #ecfdf5 !important;
    color: #059669 !important;
    border: 1px solid #6ee7b7 !important;
    font-weight: 700 !important;
    font-size: 0.72rem !important;
    padding: 3px 8px !important;
    border-radius: 6px !important;
}
.badge-type-paidr {
    background-color: #f0fdf4 !important;
    color: #16a34a !important;
    border: 1px solid #86efac !important;
    font-weight: 700 !important;
    font-size: 0.72rem !important;
    padding: 3px 8px !important;
    border-radius: 6px !important;
}
.ipd-number-link {
    color: #b91c1c !important;
    font-weight: 700 !important;
    font-family: var(--bs-font-monospace) !important;
    font-size: 0.84rem !important;
    letter-spacing: -0.2px;
}
.ipd-table-row {
    cursor: pointer;
    transition: background-color 0.15s ease;
}
.ipd-table-row:hover {
    background-color: #f0f9ff !important;
}

/* Category Filter Tabs */
.filter-tab-btn {
    font-size: 0.76rem !important;
    font-weight: 600 !important;
    padding: 5px 14px !important;
    border-radius: 9999px !important;
    border: 1px solid #e2e8f0 !important;
    background: #f8fafc !important;
    color: #475569 !important;
    cursor: pointer !important;
    transition: all 0.15s ease !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 4px !important;
}
.filter-tab-btn:hover {
    background: #e0f2fe !important;
    color: #0284c7 !important;
    border-color: #bae6fd !important;
}
.filter-tab-btn.active {
    background: #0284c7 !important;
    color: #ffffff !important;
    border-color: #0284c7 !important;
    box-shadow: 0 1px 3px rgba(2, 132, 199, 0.3) !important;
}

/* Expiry Badges */
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
/* Patient Detail Cards */
.patient-detail-card {
    background: #ffffff !important;
    border: 1px solid #d0e1fd !important;
    border-radius: 8px !important;
    padding: 9px 12px !important;
    height: 100% !important;
    display: flex !important;
    flex-direction: column !important;
    justify-content: center !important;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04) !important;
    transition: all 0.15s ease !important;
}
.patient-detail-card:hover {
    border-color: #93c5fd !important;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.06) !important;
}
.patient-card-label {
    font-size: 0.68rem !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.4px !important;
    color: #64748b !important;
    margin-bottom: 3px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    line-height: 1.2 !important;
}
.patient-card-label i {
    font-size: 0.80rem !important;
}
.patient-card-value-wrap {
    display: flex !important;
    align-items: center !important;
    min-height: 24px !important;
}
.patient-card-input {
    width: 100% !important;
    border: none !important;
    background: transparent !important;
    padding: 0 !important;
    margin: 0 !important;
    font-size: 0.90rem !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    line-height: 1.3 !important;
    outline: none !important;
    box-shadow: none !important;
}
.patient-card-input::placeholder {
    color: #94a3b8 !important;
    font-weight: 500 !important;
}
.patient-card-input:focus {
    outline: none !important;
    background: transparent !important;
}

/* Unified Medicine Search Bar */
.medicine-search-bar-unified {
    display: flex !important;
    align-items: center !important;
    background: #ffffff !important;
    border: 1.5px solid #cbd5e1 !important;
    border-radius: 10px !important;
    padding: 3px 10px 3px 14px !important;
    transition: all 0.2s ease !important;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03) !important;
}
.medicine-search-bar-unified:focus-within {
    border-color: #0284c7 !important;
    box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15) !important;
}
.medicine-search-bar-unified .search-icon {
    font-size: 1.05rem !important;
    color: #0284c7 !important;
    margin-right: 10px !important;
    display: flex !important;
    align-items: center !important;
    flex-shrink: 0 !important;
}
.medicine-search-bar-unified .search-input {
    flex: 1 1 auto !important;
    border: none !important;
    outline: none !important;
    background: transparent !important;
    font-size: 0.95rem !important;
    color: #0f172a !important;
    padding: 9px 0 !important;
    box-shadow: none !important;
}
.medicine-search-bar-unified .search-input::placeholder {
    color: #94a3b8 !important;
    font-weight: 400 !important;
}
.medicine-search-bar-unified .search-clear-btn {
    border: none !important;
    background: transparent !important;
    color: #94a3b8 !important;
    padding: 6px 10px !important;
    border-radius: 6px !important;
    cursor: pointer !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    transition: all 0.15s ease !important;
}
.medicine-search-bar-unified .search-clear-btn:hover {
    color: #0f172a !important;
    background: #f1f5f9 !important;
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
                    <div class="small text-muted">Inpatient dispensing completed. Choose a bill format below:</div>
                </div>
            </div>
            <?php if ($completedSale): ?>
                <div class="d-flex align-items-center gap-2">
                    <a href="invoice.php?id=<?= $completedSale['sale_id'] ?>&format=standard&autoprint=1" target="_blank" class="btn btn-sm btn-primary fw-bold text-white shadow-sm px-3 py-1.5 d-inline-flex align-items-center gap-1">
                        <i class="ti ti-printer"></i> Print Retail Bill
                    </a>
                    <a href="invoice.php?id=<?= $completedSale['sale_id'] ?>&format=ipd_detailed&autoprint=1" target="_blank" class="btn btn-sm btn-dark fw-bold text-white shadow-sm px-3 py-1.5 d-inline-flex align-items-center gap-1">
                        <i class="ti ti-file-text"></i> Print Detailed Bill (PDF Format)
                    </a>
                    <a href="regular.php" class="btn btn-sm btn-outline-secondary px-3 py-1.5">Next IPD Dispense</a>
                </div>
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const printWin = window.open('invoice.php?id=<?= (int)$completedSale['sale_id'] ?>&format=ipd_detailed&autoprint=1', '_blank');
                    });
                </script>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- VIEW 1: IPD ADMISSIONS REGISTRY & DISPENSING QUEUE -->
    <div id="ipdRegistryTableView">
        <div class="card border shadow-sm rounded-4 mb-4 bg-white" style="border: 1px solid #e2e8f0 !important; border-radius: 14px !important;">
            <!-- Header matching reference image -->
            <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="d-inline-flex align-items-center justify-content-center shadow-xs" style="width: 44px; height: 44px; border-radius: 12px; background-color: #f0fdf4; color: #0d9488; border: 1px solid #99f6e4;">
                        <i class="bi bi-hospital fs-4"></i>
                    </div>
                    <div>
                        <h4 class="fw-bold mb-0 text-dark" style="font-size: 1.32rem; letter-spacing: -0.3px;">
                            IPD Admissions
                        </h4>
                        <div class="text-muted small" style="font-size: 0.83rem;">
                            Manage and track currently admitted inpatient records, beds, and billing.
                        </div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3.5 py-1.5 fw-semibold d-inline-flex align-items-center gap-1.5 shadow-xs" onclick="printIpdPatientsCensusReport()" style="font-size: 0.82rem;">
                        <i class="bi bi-file-earmark-pdf text-danger"></i> Print Admitted List (PDF)
                    </button>
                    <button type="button" class="btn btn-sm text-white rounded-pill px-3.5 py-1.5 fw-bold d-inline-flex align-items-center gap-1.5 shadow-sm" style="background-color: #0d9488; border-color: #0d9488; font-size: 0.82rem;" onclick="openNewIpdModal()">
                        <i class="bi bi-plus-lg"></i> New Admission
                    </button>
                </div>
            </div>

            <div class="card-body p-4">
                <!-- Filter Toolbar matching reference image -->
                <div class="row g-2 align-items-center mb-3.5">
                    <div class="col-lg-4 col-md-5">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search text-muted"></i></span>
                            <input type="text" id="tablePatientSearch" class="form-control bg-white border-start-0 ps-1" placeholder="Search patient name, code, phone, doctor..." oninput="filterPatientsTable()" onkeydown="onTableSearchKeydown(event)" autocomplete="off">
                            <button type="button" id="btnClearTableSearch" class="btn btn-outline-secondary border-start-0 d-none" onclick="clearTableSearch()" title="Clear Search">
                                <i class="ti ti-x"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-3">
                        <select id="tableStatusFilter" class="form-select" onchange="filterPatientsTable()">
                            <option value="Admitted" selected>Admitted</option>
                            <option value="ALL">All Statuses</option>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <select id="tableWardFilter" class="form-select" onchange="filterPatientsTable()">
                            <option value="ALL">All Wards</option>
                            <?php foreach ($availableWards as $w): ?>
                                <option value="<?= htmlspecialchars($w) ?>"><?= htmlspecialchars($w) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-4 col-md-12 d-flex align-items-center gap-2">
                        <button type="button" class="btn text-white fw-bold px-3.5 shadow-xs d-inline-flex align-items-center gap-1" style="background-color: #0d9488; border-color: #0d9488;" onclick="filterPatientsTable()">
                            <i class="bi bi-funnel"></i> Filter
                        </button>
                        <button type="button" class="btn btn-link text-muted fw-semibold text-decoration-none px-2" onclick="resetTableFilters()">
                            Reset
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm ms-auto rounded-pill px-3 py-1.5 fw-semibold d-inline-flex align-items-center gap-1" onclick="printIpdPatientsCensusReport()" style="font-size: 0.80rem;">
                            <i class="bi bi-file-earmark-pdf text-danger"></i> Print Filtered List (PDF)
                        </button>
                    </div>
                </div>

                <!-- Admitted Inpatients Table matching reference screenshot -->
                <div class="table-responsive border rounded-3 overflow-hidden shadow-xs">
                    <table class="table table-hover align-middle mb-0" id="admittedPatientsTable">
                        <thead class="table-light small text-muted text-uppercase" style="font-size: 0.74rem; letter-spacing: 0.5px; background-color: #f8fafc;">
                            <tr>
                                <th style="width: 50px;" class="text-center">SR NO.</th>
                                <th style="width: 140px;">IPD NO</th>
                                <th>PATIENT NAME</th>
                                <th style="width: 110px;">ADMISSION TYPE</th>
                                <th>DOCTOR NAME</th>
                                <th>WARD WITH BED NO</th>
                                <th style="width: 140px;">ADMISSION DATE</th>
                                <th style="width: 100px;">REF.DR NAME</th>
                                <th class="text-center" style="width: 140px;">ADD MEDICINE</th>
                            </tr>
                        </thead>
                        <tbody id="admittedTableBody">
                            <!-- Populated dynamically by JavaScript -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- VIEW 2: MEDICINE DISPENSING TAB (Shown when patient is selected) -->
    <div id="ipdMedicineDispenseView" class="d-none">
        <div class="card border shadow-sm rounded-4 mb-4 bg-white" style="border: 1px solid #e2e8f0 !important; border-radius: 14px !important;">
            <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div class="d-flex align-items-center gap-3">
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1.5 fw-semibold d-inline-flex align-items-center gap-1 shadow-xs" onclick="backToPatientRegistry()" style="font-size: 0.82rem;">
                        <i class="bi bi-arrow-left"></i> Back to Inpatient List
                    </button>
                    <div class="d-inline-flex align-items-center justify-content-center shadow-xs" style="width: 40px; height: 40px; border-radius: 10px; background-color: #f0f9ff; color: #0284c7; border: 1px solid #bae6fd;">
                        <i class="bi bi-capsule fs-5"></i>
                    </div>
                    <div>
                        <h4 class="fw-bold mb-0 text-dark" style="font-size: 1.28rem; letter-spacing: -0.2px;">
                            IPD Medication Dispensing &amp; Billing
                        </h4>
                        <div class="text-muted small" style="font-size: 0.82rem;">
                            Dispense pharmaceuticals with FEFO stock deduction and itemized hospital charge tracking.
                        </div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" onclick="backToPatientRegistry()" class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1.5 fw-semibold d-inline-flex align-items-center gap-1 shadow-xs" style="font-size: 0.82rem;">
                        <i class="bi bi-person-lines-fill"></i> Change Inpatient
                    </button>
                    <button type="button" onclick="resetBilling()" class="btn btn-sm rounded-pill px-3 py-1.5 fw-semibold d-inline-flex align-items-center gap-1 shadow-xs" style="font-size: 0.82rem; background-color: #f0f9ff; color: #0284c7; border: 1px solid #bae6fd;">
                        <i class="ti ti-circle-x"></i> Clear Cart
                    </button>
                </div>
            </div>

            <div class="card-body p-4">
                <!-- Form for backend submission -->
                <form method="POST" id="ipdSaleForm">
                    <input type="hidden" name="action" value="complete_ipd_sale">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <input type="hidden" name="cart_items" id="cartItemsJson" value="[]">

                    <!-- Hidden inputs for patient details -->
                    <input type="hidden" name="patient_id" id="inpPatientId" value="">
                    <input type="hidden" name="patient_name" id="inpPatientName" value="" required>
                    <input type="hidden" name="hospital_uhid" id="inpHospitalUhid" value="">
                    <input type="hidden" name="ipd_admission_no" id="inpIpdAdmissionNo" value="">
                    <input type="hidden" name="ipd_ward" id="inpIpdWard" value="">
                    <input type="hidden" name="ipd_bed" id="inpIpdBed" value="">
                    <input type="hidden" name="doctor_name" id="inpDoctorName" value="">
                    <input type="hidden" name="discount_type" id="hiddenDiscountType" value="PERCENT">
                    <input type="hidden" name="discount_percent" id="hiddenDiscountPercent" value="0.0">
                    <input type="hidden" name="discount_value" id="hiddenDiscountValue" value="0.0">
                    <input type="hidden" name="payment_mode" id="hiddenPaymentMode" value="CREDIT">
                    <input type="hidden" name="paid_amount" id="hiddenPaidAmount" value="0.0">
                    <input type="hidden" name="notes" id="hiddenNotes" value="">

                    <!-- STEP 1: TOP PATIENT INFORMATION 4-CARD STRIP -->
                    <div class="p-3.5 rounded-2 border mb-3 shadow-xs bg-white" style="border-color: #e2e8f0 !important; padding: 16px 18px !important;">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 pb-2.5 mb-2.5 border-bottom border-light-subtle">
                            <div class="d-flex align-items-center gap-3">
                                <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold shadow-xs flex-shrink-0 bg-primary" style="width: 40px; height: 40px; font-size: 1.05rem;" id="cardPatientAvatar">
                                    P
                                </div>
                                <div>
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <h5 class="fw-bold text-dark mb-0 fs-6" id="cardPatientName">-</h5>
                                        <span class="badge bg-primary text-white fw-bold px-2 py-0.5" style="font-size: 0.70rem;"><i class="bi bi-check-circle-fill me-1"></i>Selected Inpatient</span>
                                    </div>
                                    <div class="text-muted small mt-0.5" style="font-size: 0.76rem;" id="cardPatientMeta">UHID: - &bull; Phone: -</div>
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary py-1.5 px-3 rounded-pill fw-semibold d-inline-flex align-items-center gap-1.5 shadow-xs" onclick="backToPatientRegistry()" style="font-size: 0.78rem;">
                                    <i class="bi bi-arrow-left-right"></i> Choose Different Inpatient
                                </button>
                            </div>
                        </div>

                        <!-- 4 Patient Detail Cards in 1 Row -->
                        <div class="row g-2.5 pt-0.5 align-items-stretch">
                            <div class="col-md-3 col-sm-6">
                                <div class="patient-detail-card">
                                    <div class="patient-card-label">
                                        <div class="d-flex align-items-center gap-1.5">
                                            <i class="bi bi-file-earmark-text text-primary"></i>
                                            <span>Inpatient / Admission #</span>
                                        </div>
                                    </div>
                                    <div class="patient-card-value-wrap">
                                        <input type="text" id="displayIpdAdmissionNo" class="patient-card-input font-monospace" placeholder="Admission #" readonly>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 col-sm-6">
                                <div class="patient-detail-card">
                                    <div class="patient-card-label">
                                        <div class="d-flex align-items-center gap-1.5">
                                            <i class="bi bi-upc-scan text-primary"></i>
                                            <span>Hospital UHID Code</span>
                                        </div>
                                    </div>
                                    <div class="patient-card-value-wrap">
                                        <input type="text" id="displayPatientUhid" class="patient-card-input font-monospace" placeholder="UHID" readonly>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 col-sm-6">
                                <div class="patient-detail-card">
                                    <div class="patient-card-label">
                                        <div class="d-flex align-items-center gap-1.5">
                                            <i class="bi bi-door-open text-primary"></i>
                                            <span>Ward &amp; Bed Assignment</span>
                                        </div>
                                    </div>
                                    <div class="patient-card-value-wrap">
                                        <input type="text" id="displayIpdWardBed" class="patient-card-input" placeholder="Ward / Bed" readonly>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 col-sm-6">
                                <div class="patient-detail-card">
                                    <div class="patient-card-label">
                                        <div class="d-flex align-items-center gap-1.5">
                                            <i class="bi bi-person-badge text-primary"></i>
                                            <span>Doctor / Prescriber</span>
                                        </div>
                                        <span class="text-muted" style="font-size: 0.70rem;" title="Editable Prescriber"><i class="bi bi-pencil"></i></span>
                                    </div>
                                    <div class="patient-card-value-wrap">
                                        <input type="text" id="displayDoctorName" class="patient-card-input" placeholder="Attending Doctor" oninput="document.getElementById('inpDoctorName').value = this.value;">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Hidden Select for compatibility -->
                    <select id="ipdPatientSelect" class="d-none">
                        <option value="">-- None --</option>
                        <?php foreach ($ipdPatientsList as $p): ?>
                            <option value="<?= $p['patient_id'] ?>" 
                                    data-name="<?= htmlspecialchars($p['name']) ?>" 
                                    data-uhid="<?= htmlspecialchars($p['hospital_uhid']) ?>" 
                                    data-adm="<?= htmlspecialchars($p['ipd_admission_no']) ?>" 
                                    data-ward="<?= htmlspecialchars($p['ipd_ward']) ?>" 
                                    data-bed="<?= htmlspecialchars($p['ipd_bed']) ?>" 
                                    data-doctor="<?= htmlspecialchars($p['doctor_name']) ?>"
                                    data-mobile="<?= htmlspecialchars($p['mobile']) ?>">
                                <?= htmlspecialchars(($p['hospital_uhid'] ?: 'IPD') . ' - ' . $p['name'] . ' (' . $p['ipd_ward'] . ' / ' . $p['ipd_bed'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <!-- STEP 2: NORMAL OPTION TO CHOOSE MEDICINE (Directly beneath patient details) -->
                    <div class="rounded-3 border mb-3.5 shadow-xs" style="background-color: #fbfcfd !important; border-color: #e2e8f0 !important; padding: 18px 20px !important;">
                        <div class="d-flex justify-content-between align-items-center mb-2.5">
                            <label class="form-label small fw-bold text-dark mb-0 d-inline-flex align-items-center gap-2" style="font-size: 0.84rem; letter-spacing: 0.3px;">
                                <i class="bi bi-capsule text-primary fs-6"></i>
                                <span>Search &amp; Add Medicine / Charge</span>
                                <span class="text-danger">*</span>
                                <kbd class="kbd-chip ms-1" style="font-size: 0.70rem; background: #e2e8f0; color: #334155; border: 1px solid #cbd5e1;">F2</kbd>
                            </label>
                            <span class="text-muted small d-inline-flex align-items-center gap-1.5" style="font-size: 0.76rem;">
                                <i class="bi bi-lightning-charge-fill text-warning"></i>
                                <span>Select any medicine to automatically add it to charges</span>
                            </span>
                        </div>
                        
                        <div class="position-relative">
                            <div class="medicine-search-bar-unified">
                                <span class="search-icon">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input type="text" 
                                       id="chargeMedicineInput" 
                                       class="search-input" 
                                       placeholder="Type medicine name to search &amp; auto-add to charges..." 
                                       autocomplete="off" 
                                       oninput="onMedicineSearchInput(this.value)" 
                                       onfocus="onMedicineSearchFocus()" 
                                       onkeydown="onMedicineInputKeydown(event)">
                                <button type="button" class="search-clear-btn" onclick="clearMedicineSearch()" title="Clear search">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>

                            <!-- Live Autocomplete Suggestions List -->
                            <div id="medicineSuggestionsList" 
                                 class="medicine-suggestions-menu position-absolute d-none" 
                                 style="background: #ffffff !important; background-color: #ffffff !important; border: 1px solid #cbd5e1 !important; box-shadow: 0 20px 45px -8px rgba(15, 23, 42, 0.28), 0 8px 18px -4px rgba(15, 23, 42, 0.12) !important; border-radius: 12px !important; width: 100%; min-width: 100%; left: 0; z-index: 1090; max-height: 380px; overflow-y: auto;">
                            </div>
                        </div>

                        <!-- Toast alert for auto-added feedback -->
                        <div id="autoAddNotification" class="d-none mt-2.5 py-2 px-3 rounded-2 small fw-semibold d-flex align-items-center justify-content-between" style="background-color: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; transition: opacity 0.3s ease;">
                            <span id="autoAddNotificationText"><i class="bi bi-check-circle-fill me-1"></i> Medicine added to charges</span>
                            <span class="badge bg-emerald text-white">Added &check;</span>
                        </div>

                        <!-- Hidden compatibility inputs for legacy/script references -->
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

                    <!-- STEP 3: CHARGE DETAILS (Live Charges Table & Grand Total) -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-receipt text-muted fs-5"></i>
                                <span class="fw-bold text-dark small text-uppercase" style="letter-spacing: 0.5px; font-size: 0.82rem;">Charge Details</span>
                            </div>
                            <span class="badge rounded-pill px-3 py-1.5 fw-semibold font-monospace" style="background-color: #f0f9ff; color: #0284c7; border: 1px solid #bae6fd; font-size: 0.82rem;" id="chargeDetailsTotalBadge">
                                Total: ₹0.00
                            </span>
                        </div>
                        <div class="card border rounded-3 shadow-xs" style="overflow: visible !important;">
                            <div class="table-responsive" style="overflow: visible !important;">
                                <table class="table table-hover align-middle mb-0" id="cartTable">
                                    <thead class="table-light small text-muted text-uppercase" style="font-size: 0.74rem;">
                                        <tr>
                                            <th class="text-center" style="width: 4%;">S.NO</th>
                                            <th style="width: 25%;">CHARGE / MEDICINE NAME</th>
                                            <th style="width: 20%;">CONTAINS / COMPOSITION</th>
                                            <th style="width: 15%; min-width: 160px;">EXPIRY DATE</th>
                                            <th class="text-end" style="width: 9%;">MFG RATE</th>
                                            <th class="text-end" style="width: 9%;">BILL RATE</th>
                                            <th class="text-center" style="width: 7%;">QTY</th>
                                            <th class="text-end" style="width: 8%;">TOTAL</th>
                                            <th class="text-center" style="width: 3%;"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="cartTableBody">
                                        <tr id="emptyCartRow">
                                            <td colspan="9" class="text-center py-4 text-muted small">
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
                            <span class="fw-bold font-monospace" style="font-size: 1.45rem; color: #0284c7;" id="lblGrandTotal">₹0.00</span>
                        </div>

                        <!-- Bottom Action Footer -->
                        <div class="d-flex justify-content-end align-items-center gap-2 pt-3 border-top">
                            <button type="button" class="btn btn-outline-secondary d-inline-flex align-items-center gap-1.5 px-3 py-2 fw-semibold" onclick="saveDraft()" style="border-radius: 8px;">
                                <i class="bi bi-save"></i> Save Draft
                            </button>
                            <button type="button" id="btnProceedPreview" class="btn btn-primary d-inline-flex align-items-center gap-1.5 px-4 py-2 fw-semibold shadow-sm" onclick="openBillingPreviewModal()" style="border-radius: 8px; background-color: #0284c7; border-color: #0284c7;">
                                Proceed to Billing Preview <kbd class="kbd-chip ms-1" style="background: rgba(255,255,255,0.25); color: #fff;">F8</kbd> &rarr;
                            </button>
                        </div>
                    </div>
                </form>
            </div>
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
                    <div class="d-inline-flex align-items-center justify-content-center shadow-xs" style="width: 42px; height: 42px; border-radius: 10px; background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%); color: #fff;">
                        <i class="bi bi-receipt-cutoff fs-5"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <h5 class="modal-title fw-bold text-dark mb-0" style="font-size: 1.15rem; letter-spacing: -0.01em;">IPD Dispensing &amp; Billing Preview</h5>
                            <span class="badge rounded-pill fw-semibold" style="background-color: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; font-size: 0.72rem; padding: 4px 10px;">
                                <i class="bi bi-check-circle-fill me-1" style="font-size: 0.7rem;"></i>Inpatient Folio
                            </span>
                        </div>
                        <div class="text-muted small mt-0.5" style="font-size: 0.8rem;">Verify inpatient medication charges, review taxes &amp; confirm dispensing</div>
                    </div>
                </div>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal" aria-label="Close" style="font-size: 0.8rem;"></button>
            </div>

            <div class="modal-body p-4" style="background-color: #f8fafc; box-sizing: border-box;">
                <style>
                    .ipd-patient-strip { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
                    .ipd-preview-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; align-items: stretch; }
                    @media (max-width: 992px) {
                        .ipd-patient-strip { grid-template-columns: repeat(2, 1fr); }
                        .ipd-preview-grid { grid-template-columns: 1fr; }
                    }
                    @media (max-width: 576px) {
                        .ipd-patient-strip { grid-template-columns: 1fr; }
                    }
                
/* Inpatient Admissions Registry Table Styles */
.badge-type-cashless {
    background-color: #fee2e2 !important;
    color: #dc2626 !important;
    border: 1px solid #fca5a5 !important;
    font-weight: 700 !important;
    font-size: 0.72rem !important;
    padding: 3px 8px !important;
    border-radius: 6px !important;
}
.badge-type-paid {
    background-color: #ecfdf5 !important;
    color: #059669 !important;
    border: 1px solid #6ee7b7 !important;
    font-weight: 700 !important;
    font-size: 0.72rem !important;
    padding: 3px 8px !important;
    border-radius: 6px !important;
}
.badge-type-paidr {
    background-color: #f0fdf4 !important;
    color: #16a34a !important;
    border: 1px solid #86efac !important;
    font-weight: 700 !important;
    font-size: 0.72rem !important;
    padding: 3px 8px !important;
    border-radius: 6px !important;
}
.ipd-number-link {
    color: #b91c1c !important;
    font-weight: 700 !important;
    font-family: var(--bs-font-monospace) !important;
    font-size: 0.84rem !important;
    letter-spacing: -0.2px;
}
.ipd-table-row {
    cursor: pointer;
    transition: background-color 0.15s ease;
}
.discount-segmented-control {
    display: inline-flex;
    align-items: center;
    background-color: #f1f5f9;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 3px;
    gap: 3px;
    user-select: none;
    box-sizing: border-box;
}
.discount-seg-btn {
    border: none !important;
    background: transparent !important;
    color: #475569 !important;
    font-weight: 600 !important;
    font-size: 0.78rem !important;
    padding: 4px 11px !important;
    border-radius: 6px !important;
    cursor: pointer !important;
    transition: all 0.15s ease-in-out !important;
    line-height: 1.25 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    white-space: nowrap !important;
    box-shadow: none !important;
}
.discount-seg-btn:hover:not(.active) {
    color: #0f172a !important;
    background-color: #e2e8f0 !important;
}
.discount-seg-btn.active {
    background-color: #0284c7 !important;
    color: #ffffff !important;
    font-weight: 700 !important;
    box-shadow: 0 1px 3px rgba(2, 132, 199, 0.3) !important;
}
</style>

                <!-- Patient Summary Strip: 4 Exact Equal-Height Cards -->
                <div class="ipd-patient-strip mb-3">
                    <!-- Card 1: Inpatient Name -->
                    <div class="bg-white p-3 rounded-3 border d-flex flex-column justify-content-between shadow-2xs" style="min-height: 72px;">
                        <div class="d-flex align-items-center" style="font-size: 0.68rem; letter-spacing: 0.05em; font-weight: 700; color: #64748b; text-transform: uppercase;">
                            <i class="bi bi-person text-primary" style="margin-right: 7px; font-size: 0.95rem;"></i>
                            <span>Inpatient Name</span>
                        </div>
                        <div class="mt-1 text-dark fs-6 fw-bold text-truncate" id="modalPatientName">-</div>
                    </div>

                    <!-- Card 2: Hospital UHID -->
                    <div class="bg-white p-3 rounded-3 border d-flex flex-column justify-content-between shadow-2xs" style="min-height: 72px;">
                        <div class="d-flex align-items-center" style="font-size: 0.68rem; letter-spacing: 0.05em; font-weight: 700; color: #64748b; text-transform: uppercase;">
                            <i class="bi bi-upc-scan text-primary" style="margin-right: 7px; font-size: 0.95rem;"></i>
                            <span>Hospital UHID</span>
                        </div>
                        <div class="mt-1 font-monospace fw-bold text-dark fs-6 text-truncate" id="modalPatientUhid">-</div>
                    </div>

                    <!-- Card 3: Ward & Bed -->
                    <div class="bg-white p-3 rounded-3 border d-flex flex-column justify-content-between shadow-2xs" style="min-height: 72px;">
                        <div class="d-flex align-items-center" style="font-size: 0.68rem; letter-spacing: 0.05em; font-weight: 700; color: #64748b; text-transform: uppercase;">
                            <i class="bi bi-hospital text-primary" style="margin-right: 7px; font-size: 0.95rem;"></i>
                            <span>Ward &amp; Bed</span>
                        </div>
                        <div class="mt-1 fw-bold text-dark fs-6 text-truncate" id="modalWardBed">-</div>
                    </div>

                    <!-- Card 4: Bill Type -->
                    <div class="bg-white p-3 rounded-3 border d-flex flex-column justify-content-between shadow-2xs" style="min-height: 72px;">
                        <div class="d-flex align-items-center" style="font-size: 0.68rem; letter-spacing: 0.05em; font-weight: 700; color: #64748b; text-transform: uppercase;">
                            <i class="bi bi-bookmark-check text-primary" style="margin-right: 7px; font-size: 0.95rem;"></i>
                            <span>Bill Type</span>
                        </div>
                        <div class="mt-1 d-flex align-items-center">
                            <span class="badge rounded-pill fw-bold d-inline-flex align-items-center" style="background-color: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; font-size: 0.74rem; padding: 3px 8px;">
                                <i class="bi bi-check2" style="margin-right: 4px;"></i> IPD Credit Folio
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
                <div class="ipd-preview-grid">
                    <!-- Left: Clinical & Dispensing Parameters -->
                    <div class="bg-white p-3.5 rounded-3 border shadow-2xs d-flex flex-column justify-content-between h-100">
                        <div>
                            <div class="d-flex align-items-center justify-content-between pb-2 mb-3 border-bottom" style="min-height: 32px;">
                                <span class="fw-bold text-dark d-flex align-items-center" style="font-size: 0.88rem;">
                                    <i class="bi bi-clipboard2-pulse text-primary" style="margin-right: 8px; font-size: 1rem;"></i> Dispensing &amp; Clinician Details
                                </span>
                            </div>
                            
                            <div class="d-flex flex-column" style="gap: 14px;">
                                <div>
                                    <label class="form-label text-secondary fw-semibold mb-1" style="font-size: 0.78rem; display: block;">Attending Doctor</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text bg-light text-muted border-end-0" style="width: 38px; justify-content: center;"><i class="bi bi-person-badge"></i></span>
                                        <input type="text" id="modalDoctorInput" class="form-control form-control-sm fw-semibold border-start-0" style="height: 34px;" placeholder="Doctor name" readonly>
                                    </div>
                                </div>
                                <div>
                                    <label class="form-label text-secondary fw-semibold mb-1" style="font-size: 0.78rem; display: block;">Ward Dispensing Notes</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text bg-light text-muted border-end-0" style="width: 38px; justify-content: center;"><i class="bi bi-card-text"></i></span>
                                        <input type="text" id="modalNotesInput" class="form-control form-control-sm border-start-0" style="height: 34px;" placeholder="e.g. Ward routine dispensing..." onkeydown="if(event.key==='Enter'||(event.key==='Tab'&&!event.shiftKey)){event.preventDefault();document.getElementById('modalPaymentMode').focus();}">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="p-2.5 rounded-2 bg-light border text-muted small mt-3" style="font-size: 0.74rem;">
                            <i class="ti ti-keyboard text-primary me-1"></i> <span class="fw-semibold">Keyboard Flow:</span> Use <kbd class="bg-white border px-1">Tab</kbd> or <kbd class="bg-white border px-1">Enter</kbd> to move from Settlement Mode &rarr; Discount &rarr; Confirm (<kbd class="bg-white border px-1">F9</kbd>).
                        </div>
                    </div>

                    <!-- Right: Financial Summary & Net Calculations -->
                    <div class="bg-white p-3.5 rounded-3 border shadow-2xs d-flex flex-column justify-content-between h-100">
                        <div>
                            <div class="d-flex align-items-center justify-content-between pb-2 mb-3 border-bottom" style="min-height: 32px;">
                                <span class="fw-bold text-dark d-flex align-items-center" style="font-size: 0.88rem;">
                                    <i class="bi bi-calculator text-primary" style="margin-right: 8px; font-size: 1rem;"></i> Financial &amp; Settlement Summary
                                </span>
                                <span class="badge bg-light text-muted border fw-normal" style="font-size: 0.72rem;">All values in INR (₹)</span>
                            </div>

                            <div class="d-flex flex-column" style="gap: 10px;">
                                <!-- 1. Billing / Settlement Mode Row -->
                                <div>
                                    <label class="form-label text-secondary fw-semibold mb-1" style="font-size: 0.78rem; display: block;">Billing / Settlement Mode</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text bg-light text-muted border-end-0" style="width: 38px; justify-content: center;"><i class="bi bi-wallet2"></i></span>
                                        <select id="modalPaymentMode" class="form-select form-select-sm fw-semibold border-start-0" style="height: 34px;" onchange="onModalPaymentModeChange(this.value)" onkeydown="handleModalModeKeydown(event)">
                                            <option value="CREDIT" selected>Hospital IPD Credit (Charge to Inpatient Folio)</option>
                                            <option value="CASH">Direct Cash Settlement</option>
                                            <option value="UPI">UPI / QR Code</option>
                                            <option value="CARD">Debit / Credit Card</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="border-top pt-2 d-flex flex-column" style="gap: 8px;">
                                    <!-- Subtotal Row -->
                                    <div class="d-flex justify-content-between align-items-center" style="min-height: 28px;">
                                        <span class="text-secondary fw-semibold" style="font-size: 0.84rem;">Subtotal (Gross):</span>
                                        <span class="fw-bold font-monospace text-dark text-end" style="font-size: 0.92rem;" id="modalLblSubtotal">₹0.00</span>
                                    </div>

                                    <!-- Discount Row (Visible Segmented Options: Discount % / Flat ₹) -->
                                    <div class="d-flex justify-content-between align-items-center" style="min-height: 34px;">
                                        <div class="d-flex align-items-center">
                                            <input type="hidden" id="modalDiscountType" value="PERCENT">
                                            <div class="discount-segmented-control" id="discountTypeBtnGroup">
                                                <button type="button" class="discount-seg-btn active" id="btnDiscPercent" onclick="setDiscountMode('PERCENT')">
                                                    % Discount
                                                </button>
                                                <button type="button" class="discount-seg-btn" id="btnDiscFlat" onclick="setDiscountMode('FLAT')">
                                                    ₹ Flat
                                                </button>
                                            </div>
                                        </div>
                                        <div class="d-flex align-items-center justify-content-end">
                                            <div class="input-group input-group-sm" style="width: 105px;">
                                                <input type="number" step="1" min="0" max="50" id="modalDiscountValue" class="form-control form-control-sm text-end fw-bold font-monospace px-2" style="font-size: 0.88rem; height: 30px;" value="0" placeholder="0" oninput="recalcModalTotals()" onkeydown="handleModalDiscountKeydown(event)">
                                                <span class="input-group-text bg-light text-muted px-2 font-monospace fw-bold" id="modalDiscountAddon" style="font-size: 0.78rem; height: 30px;">%</span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Discount Amount Row (Dynamic) -->
                                    <div class="justify-content-between align-items-center text-danger d-none" id="modalRowDiscount" style="min-height: 28px;">
                                        <span class="fw-semibold" style="font-size: 0.84rem;">Discount Amount:</span>
                                        <span class="fw-bold font-monospace text-end" style="font-size: 0.92rem;" id="modalLblDiscountAmt">-₹0.00</span>
                                    </div>

                                    <!-- GST Row -->
                                    <div class="d-flex justify-content-between align-items-center" style="min-height: 28px;">
                                        <span class="text-secondary fw-semibold" style="font-size: 0.84rem;">GST (Tax Inclusive):</span>
                                        <span class="fw-bold font-monospace text-dark text-end" style="font-size: 0.92rem;" id="modalLblGst">₹0.00</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Net Amount Highlight Box -->
                        <div class="p-3 rounded-3 border d-flex justify-content-between align-items-center mt-3" style="background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 100%); border-color: #a7f3d0 !important; min-height: 64px;">
                            <div>
                                <span class="text-uppercase fw-bold text-success d-block" style="font-size: 0.72rem; letter-spacing: 0.05em; line-height: 1.2;">Net Payable Amount</span>
                                <div class="small text-muted mt-1 d-flex align-items-center" id="modalCreditNotice" style="font-size: 0.75rem; line-height: 1.2;">
                                    <i class="bi bi-check-circle-fill text-success" style="margin-right: 6px;"></i>
                                    <span>Billed to Inpatient Account</span>
                                </div>
                            </div>
                            <span class="fw-bold fs-3 font-monospace text-end" style="color: #047857; line-height: 1;" id="modalLblGrandTotal">₹0.00</span>
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
                        <span>Retail Bill</span>
                    </button>
                    <button type="button" class="btn text-nowrap px-3 py-1.5 fw-bold d-inline-flex align-items-center justify-content-center rounded-3 shadow-2xs" style="background-color: #f8fafc; color: #334155; border: 1px solid #cbd5e1; font-size: 0.84rem;" onclick="previewCurrentBill('ipd_detailed')">
                        <i class="bi bi-file-earmark-text" style="margin-right: 6px; font-size: 0.95rem;"></i>
                        <span>Inpatient Bill (PDF)</span>
                    </button>
                    <button type="button" id="modalBtnConfirmSale" class="btn btn-primary text-white text-nowrap px-3.5 py-1.5 fw-bold d-inline-flex align-items-center justify-content-center rounded-2 shadow-xs" style="font-size: 0.86rem;" onclick="submitFinalSale()">
                        <i class="bi bi-check-lg" style="margin-right: 6px; font-size: 1.1rem;"></i>
                        <span>Confirm &amp; Dispense</span>
                        <kbd class="kbd-chip ms-1.5" style="background: rgba(255,255,255,0.25); color: #fff; font-size: 0.72rem; padding: 2px 5px; border-radius: 4px; font-weight: 700;">F9</kbd>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: New IPD Admission / Patient Registration -->
<div class="modal fade" id="newIpdPatientModal" tabindex="-1" aria-labelledby="newIpdPatientModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow rounded-3 overflow-hidden">
            <div class="modal-header border-bottom py-2.5 px-3 bg-light-subtle">
                <div class="d-flex align-items-center gap-2">
                    <div class="d-inline-flex align-items-center justify-content-center rounded bg-primary-subtle text-primary" style="width: 32px; height: 32px;">
                        <i class="bi bi-hospital fs-5"></i>
                    </div>
                    <div>
                        <h6 class="modal-title fw-bold mb-0 text-dark" id="newIpdPatientModalLabel">New IPD Patient Registry / Admission</h6>
                        <div class="text-muted small" style="font-size: 0.74rem;">Register and admit a new patient directly into the IPD list for dispensing</div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="newIpdForm" onsubmit="submitNewIpdPatient(event)">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <div class="modal-body p-4">
                    <div id="newIpdAlert" class="alert d-none mb-3 py-2 px-3 small"></div>

                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label small fw-semibold text-muted mb-1">Patient Full Name <span class="text-danger">*</span></label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light"><i class="ti ti-user"></i></span>
                                <input type="text" name="patient_name" id="regPatientName" class="form-control form-control-sm" placeholder="e.g. Ramesh Kumar Sharma" required>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-semibold text-muted mb-1">Mobile / Phone Number</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light"><i class="ti ti-phone"></i></span>
                                <input type="tel" name="mobile" id="regMobile" class="form-control form-control-sm" placeholder="e.g. 9876543210">
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-muted mb-1">Gender</label>
                            <select name="gender" id="regGender" class="form-select form-select-sm">
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-muted mb-1">Ward Name <span class="text-danger">*</span></label>
                            <select name="ward_name" id="regWardName" class="form-select form-select-sm">
                                <?php foreach ($availableWards as $wName): ?>
                                    <option value="<?= htmlspecialchars($wName) ?>"><?= htmlspecialchars($wName) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-muted mb-1">Bed Number <span class="text-danger">*</span></label>
                            <input type="text" name="bed_number" id="regBedNumber" class="form-control form-control-sm" placeholder="e.g. 209-1, Bed-05" value="Bed-01" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-muted mb-1">Attending Doctor / Prescriber</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light"><i class="ti ti-stethoscope"></i></span>
                                <input type="text" name="doctor_name" id="regDoctorName" class="form-control form-control-sm" placeholder="e.g. Dr. Anjali Mehta" value="<?= htmlspecialchars($availableDoctors[0] ?? 'Duty Doctor') ?>" list="doctorDatalist">
                                <datalist id="doctorDatalist">
                                    <?php foreach ($availableDoctors as $dName): ?>
                                        <option value="<?= htmlspecialchars($dName) ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-muted mb-1">Initial Diagnosis / Note</label>
                            <input type="text" name="diagnosis" id="regDiagnosis" class="form-control form-control-sm" placeholder="e.g. Acute Bronchitis, Post-Op Care" value="Admitted Inpatient Care">
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-2.5 px-4 bg-light border-top d-flex justify-content-between">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" id="btnSubmitNewIpd" class="btn btn-sm text-white fw-bold d-inline-flex align-items-center gap-1.5 px-3 shadow-sm" style="background-color: #0284c7; border-color: #0284c7;">
                        <i class="ti ti-check"></i> Register &amp; Select for Dispense
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>

// ----------------- IPD ADMISSIONS REGISTRY TABLE LOGIC -----------------
let currentFilteredPatients = [];

function highlightIpdMatch(text, query) {
    if (!text) return '';
    if (!query) return escapeHtml(text);
    const escaped = escapeHtml(text);
    const qEscaped = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const regex = new RegExp(`(${qEscaped})`, 'gi');
    return escaped.replace(regex, '<span class="search-highlight">$1</span>');
}

function matchIpdPatient(p, q, wardVal) {
    if (wardVal && wardVal !== 'ALL' && (p.ipd_ward || '').toLowerCase() !== wardVal.toLowerCase()) {
        return false;
    }
    if (!q) return true;

    const name = (p.name || '').toLowerCase();
    const cleanName = name.replace(/^(mr\.|mrs\.|miss|master|baby|b\/o|s\/o|d\/o|w\/o|dr\.)\s+/i, '').trim();
    const uhid = (p.hospital_uhid || '').toLowerCase();
    const ipdNo = (p.ipd_admission_no || '').toLowerCase();
    const mob = (p.mobile || '').toLowerCase();
    const doc = (p.doctor_name || '').toLowerCase();
    const cleanDoc = doc.replace(/^dr\.\s+/i, '').trim();
    const ward = (p.ipd_ward || '').toLowerCase();
    const bed = (p.ipd_bed || '').toLowerCase();
    const ref = (p.referred_by || '').toLowerCase();

    // Word lists
    const nameWords = name.split(/[\s,./-]+/).filter(Boolean);
    const docWords = doc.split(/[\s,./-]+/).filter(Boolean);
    const wardWords = ward.split(/[\s,./-]+/).filter(Boolean);

    // If query is short (1 or 2 chars), match by prefix / word start / number
    if (q.length <= 2) {
        if (name.startsWith(q) || cleanName.startsWith(q)) return true;
        if (nameWords.some(w => w.startsWith(q))) return true;
        if (uhid.startsWith(q) || uhid.includes(q)) return true;
        if (ipdNo.includes(q)) return true;
        if (doc.startsWith(q) || cleanDoc.startsWith(q) || docWords.some(w => w.startsWith(q))) return true;
        if (ward.startsWith(q) || wardWords.some(w => w.startsWith(q)) || bed.includes(q)) return true;
        if (mob.startsWith(q)) return true;
        if (ref.startsWith(q)) return true;
        return false;
    }

    // For 3+ chars, allow substring match anywhere
    return name.includes(q) || uhid.includes(q) || ipdNo.includes(q) || 
           mob.includes(q) || doc.includes(q) || ward.includes(q) || bed.includes(q) || ref.includes(q);
}

function rankIpdPatient(p, q) {
    if (!q) return 0;
    const name = (p.name || '').toLowerCase();
    const cleanName = name.replace(/^(mr\.|mrs\.|miss|master|baby|b\/o|s\/o|d\/o|w\/o|dr\.)\s+/i, '').trim();
    const uhid = (p.hospital_uhid || '').toLowerCase();
    const ipdNo = (p.ipd_admission_no || '').toLowerCase();
    const doc = (p.doctor_name || '').toLowerCase();
    const cleanDoc = doc.replace(/^dr\.\s+/i, '').trim();
    const nameWords = name.split(/[\s,./-]+/).filter(Boolean);
    const docWords = doc.split(/[\s,./-]+/).filter(Boolean);

    // Exact or prefix matches
    if (name.startsWith(q) || cleanName.startsWith(q)) return 100;
    if (nameWords.some(w => w.startsWith(q))) return 90;
    if (uhid.startsWith(q) || uhid.includes(q)) return 85;
    if (ipdNo.endsWith(q) || ipdNo.includes(q)) return 80;
    if (doc.startsWith(q) || cleanDoc.startsWith(q) || docWords.some(w => w.startsWith(q))) return 70;
    if (name.includes(q)) return 50;
    if (doc.includes(q)) return 40;
    return 10;
}

function renderAdmittedPatientsTable(patients) {
    currentFilteredPatients = patients || [];
    const tbody = document.getElementById('admittedTableBody');
    const countBadge = document.getElementById('tableCountBadge');
    const searchVal = (document.getElementById('tablePatientSearch')?.value || '').trim().toLowerCase();
    if (!tbody) return;

    if (!patients || patients.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="9" class="text-center py-5 text-muted">
                    <i class="bi bi-people fs-2 d-block mb-2 text-secondary opacity-50"></i>
                    <div class="fw-semibold text-dark mb-1">No admitted inpatients found matching "${escapeHtml(searchVal)}"</div>
                    <div class="small text-muted mb-2">Check spelling or clear filters to view all admitted inpatients</div>
                    <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="resetTableFilters()">Reset Filters</button>
                </td>
            </tr>
        `;
        if (countBadge) countBadge.textContent = '0 Inpatients';
        return;
    }

    if (countBadge) countBadge.textContent = `${patients.length} Inpatients`;

    let html = '';
    patients.forEach((p, idx) => {
        let typeClass = 'badge-type-paid';
        const typeStr = (p.admission_type || '').toLowerCase();
        if (typeStr.includes('cashless')) {
            typeClass = 'badge-type-cashless';
        } else if (typeStr.includes('paid r') || typeStr.includes('paid-r')) {
            typeClass = 'badge-type-paidr';
        }

        const highlightedName = highlightIpdMatch(p.name, searchVal);
        const highlightedIpd = highlightIpdMatch(p.ipd_admission_no, searchVal);
        const highlightedUhid = p.hospital_uhid ? highlightIpdMatch(p.hospital_uhid, searchVal) : '';
        const highlightedDoc = p.doctor_name ? highlightIpdMatch(p.doctor_name, searchVal) : 'Dr. Duty Doctor';
        const highlightedWard = highlightIpdMatch(p.ipd_ward, searchVal);
        const highlightedBed = highlightIpdMatch(p.ipd_bed, searchVal);

        html += `
            <tr class="ipd-table-row" tabindex="0" data-patient-index="${idx}" onkeydown="if(event.key==='Enter'){openDispenseForPatientIndex(${idx});}" onclick="openDispenseForPatientIndex(${idx})">
                <td class="text-muted text-center font-monospace" style="font-size: 0.82rem;">${idx + 1}</td>
                <td>
                    <span class="fw-bold" style="color: #be123c; font-family: monospace; font-size: 0.84rem;">${highlightedIpd}</span>
                </td>
                <td>
                    <div class="fw-bold text-dark" style="font-size: 0.88rem;">${highlightedName}</div>
                    ${highlightedUhid ? `<div class="small text-muted font-monospace" style="font-size: 0.70rem;">${highlightedUhid}</div>` : ''}
                </td>
                <td>
                    <span class="badge ${typeClass}" style="font-size: 0.72rem; padding: 4px 8px;">${escapeHtml(p.admission_type || 'Paid')}</span>
                </td>
                <td class="text-dark fw-semibold" style="font-size: 0.84rem;">
                    ${highlightedDoc}
                </td>
                <td class="text-dark" style="font-size: 0.84rem;">
                    <strong>${highlightedWard}</strong> <span class="badge bg-light text-secondary border ms-1 font-monospace" style="font-size: 0.72rem;">Bed ${highlightedBed}</span>
                </td>
                <td class="text-muted small font-monospace" style="font-size: 0.78rem;">
                    ${escapeHtml(p.admission_date || '-')}
                </td>
                <td class="text-muted small" style="font-size: 0.80rem;">
                    ${escapeHtml(p.referred_by || 'Self')}
                </td>
                <td class="text-center" onclick="event.stopPropagation()">
                    <button type="button" class="btn btn-sm btn-outline-info text-dark fw-bold px-3 py-1 shadow-xs d-inline-flex align-items-center gap-1 rounded-pill" 
                            style="border-color: #38bdf8; font-size: 0.78rem;" 
                            onclick="openDispenseForPatientIndex(${idx})">
                        Add Medicine
                    </button>
                </td>
            </tr>
        `;
    });

    tbody.innerHTML = html;
}

function filterPatientsTable() {
    const searchInp = document.getElementById('tablePatientSearch');
    const searchVal = (searchInp?.value || '').trim().toLowerCase();
    const wardVal = document.getElementById('tableWardFilter')?.value || 'ALL';
    const btnClear = document.getElementById('btnClearTableSearch');

    if (btnClear) {
        if (searchVal) btnClear.classList.remove('d-none');
        else btnClear.classList.add('d-none');
    }

    let filtered = ipdPatientsList.filter(p => matchIpdPatient(p, searchVal, wardVal));

    filtered.sort((a, b) => {
        if (searchVal) {
            const rankA = rankIpdPatient(a, searchVal);
            const rankB = rankIpdPatient(b, searchVal);
            if (rankA !== rankB) return rankB - rankA;
        }
        const nameA = (a.name || '').replace(/^(mr\.|mrs\.|miss|master|baby|b\/o|s\/o|d\/o|w\/o|dr\.)\s+/i, '').trim();
        const nameB = (b.name || '').replace(/^(mr\.|mrs\.|miss|master|baby|b\/o|s\/o|d\/o|w\/o|dr\.)\s+/i, '').trim();
        return nameA.localeCompare(nameB);
    });

    renderAdmittedPatientsTable(filtered);
}

function clearTableSearch() {
    const inp = document.getElementById('tablePatientSearch');
    if (inp) {
        inp.value = '';
        inp.focus();
    }
    const btnClear = document.getElementById('btnClearTableSearch');
    if (btnClear) btnClear.classList.add('d-none');
    filterPatientsTable();
}

function onTableSearchKeydown(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        if (currentFilteredPatients && currentFilteredPatients.length > 0) {
            openDispenseForPatientIndex(0);
        }
    } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        const firstRow = document.querySelector('.ipd-table-row');
        if (firstRow) firstRow.focus();
    } else if (e.key === 'Escape') {
        clearTableSearch();
    }
}

function resetTableFilters() {
    const searchInp = document.getElementById('tablePatientSearch');
    if (searchInp) searchInp.value = '';
    const wardSelect = document.getElementById('tableWardFilter');
    if (wardSelect) wardSelect.value = 'ALL';
    const statusSelect = document.getElementById('tableStatusFilter');
    if (statusSelect) statusSelect.value = 'Admitted';
    renderAdmittedPatientsTable(ipdPatientsList);
}

function printIpdPatientsCensusReport() {
    const searchVal = encodeURIComponent((document.getElementById('tablePatientSearch')?.value || '').trim());
    const statusVal = encodeURIComponent(document.getElementById('tableStatusFilter')?.value || 'Admitted');
    const wardVal = encodeURIComponent(document.getElementById('tableWardFilter')?.value || 'ALL');
    const url = `print_ipd_patients.php?search=${searchVal}&status=${statusVal}&ward=${wardVal}&autoprint=1`;
    window.open(url, '_blank');
}

function openDispenseForPatientIndex(idx) {
    const p = (currentFilteredPatients && currentFilteredPatients[idx]) ? currentFilteredPatients[idx] : ipdPatientsList[idx];
    if (p) {
        selectPatient(p);
    }
}

function selectPatient(p) {
    if (!p) return;

    // Set hidden form inputs
    if (document.getElementById('inpPatientId')) document.getElementById('inpPatientId').value = p.patient_id || '';
    if (document.getElementById('inpPatientName')) document.getElementById('inpPatientName').value = p.name || '';
    if (document.getElementById('inpHospitalUhid')) document.getElementById('inpHospitalUhid').value = p.hospital_uhid || '';
    if (document.getElementById('inpIpdAdmissionNo')) document.getElementById('inpIpdAdmissionNo').value = p.ipd_admission_no || '';
    if (document.getElementById('inpIpdWard')) document.getElementById('inpIpdWard').value = p.ipd_ward || '';
    if (document.getElementById('inpIpdBed')) document.getElementById('inpIpdBed').value = p.ipd_bed || '';
    if (document.getElementById('inpDoctorName')) document.getElementById('inpDoctorName').value = p.doctor_name || '';

    // Set display inputs
    if (document.getElementById('displayIpdAdmissionNo')) document.getElementById('displayIpdAdmissionNo').value = p.ipd_admission_no || '';
    if (document.getElementById('displayPatientUhid')) document.getElementById('displayPatientUhid').value = p.hospital_uhid || 'IPD';
    if (document.getElementById('displayIpdWardBed')) document.getElementById('displayIpdWardBed').value = (p.ipd_ward || p.ipd_bed) ? `${p.ipd_ward} / ${p.ipd_bed}` : '';
    if (document.getElementById('displayDoctorName')) document.getElementById('displayDoctorName').value = p.doctor_name || '';

    // Update patient card info in Medicine Dispense View
    const nameEl = document.getElementById('cardPatientName');
    if (nameEl) nameEl.textContent = p.name;
    const avatarEl = document.getElementById('cardPatientAvatar');
    if (avatarEl) avatarEl.textContent = (p.name || 'P').trim().charAt(0).toUpperCase();
    const metaEl = document.getElementById('cardPatientMeta');
    if (metaEl) metaEl.textContent = `UHID: ${p.hospital_uhid || 'IPD'} • Phone: ${p.mobile || 'N/A'}`;

    // Sync hidden select if exists
    const select = document.getElementById('ipdPatientSelect');
    if (select && p.patient_id) select.value = p.patient_id;

    // Switch Views from Inpatient Registry to Medicine Dispense View
    const regView = document.getElementById('ipdRegistryTableView');
    const medView = document.getElementById('ipdMedicineDispenseView');
    if (regView) regView.classList.add('d-none');
    if (medView) medView.classList.remove('d-none');

    // Smoothly scroll to top & focus medicine search
    window.scrollTo({ top: 0, behavior: 'smooth' });
    setTimeout(() => {
        const medInput = document.getElementById('chargeMedicineInput');
        if (medInput) {
            medInput.focus();
            medInput.select();
        }
    }, 150);
}

function openDispenseForPatient(patientId) {
    selectPatientById(patientId);
}

function selectPatientById(patientId) {
    const p = ipdPatientsList.find(x => (x.patient_id && x.patient_id == patientId) || (x.hospital_patient_id && x.hospital_patient_id == patientId) || (x.admission_id && x.admission_id == patientId) || (x.hospital_uhid && x.hospital_uhid === patientId));
    if (p) {
        selectPatient(p);
    }
}

function backToPatientRegistry() {
    const regView = document.getElementById('ipdRegistryTableView');
    const medView = document.getElementById('ipdMedicineDispenseView');
    if (regView) regView.classList.remove('d-none');
    if (medView) medView.classList.add('d-none');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

const catalogMedicines = <?= json_encode($medicinesCatalog, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
let ipdPatientsList = <?= json_encode($ipdPatientsList, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
let activePatientSuggestions = [];
let selectedPatientIndex = -1;
let cart = [];
let activeSuggestions = [];

function escapeHtml(text) {
    if (!text) return '';
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(text).replace(/[&<>"']/g, m => map[m]);
}

function openNewIpdModal(initialName = '') {
    const modalEl = document.getElementById('newIpdPatientModal');
    if (modalEl) {
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        document.getElementById('newIpdAlert').classList.add('d-none');
        document.getElementById('regPatientName').value = initialName || '';
        document.getElementById('regMobile').value = '';
        modal.show();
        setTimeout(() => {
            const input = document.getElementById('regPatientName');
            if (input) {
                input.focus();
                if (initialName) input.select();
            }
        }, 300);
    }
}

function submitNewIpdPatient(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSubmitNewIpd');
    const alertBox = document.getElementById('newIpdAlert');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Registering...';
    alertBox.classList.add('d-none');

    const form = document.getElementById('newIpdForm');
    const formData = new FormData(form);
    formData.append('action', 'quick_ipd_register');

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="ti ti-check"></i> Register &amp; Select for Dispense';
        if (data.success && data.patient) {
            const p = data.patient;
            
            // Add to client-side list so it is searchable
            ipdPatientsList.unshift(p);
            
            // Add option to hidden select
            const select = document.getElementById('ipdPatientSelect');
            if (select) {
                const newOpt = document.createElement('option');
                newOpt.value = p.patient_id;
                newOpt.dataset.name = p.name;
                newOpt.dataset.uhid = p.hospital_uhid;
                newOpt.dataset.adm = p.ipd_admission_no;
                newOpt.dataset.ward = p.ipd_ward;
                newOpt.dataset.bed = p.ipd_bed;
                newOpt.dataset.doctor = p.doctor_name;
                newOpt.dataset.mobile = p.mobile;
                newOpt.textContent = p.label || `${p.hospital_uhid} - ${p.name} (${p.ipd_ward} / ${p.ipd_bed})`;
                select.appendChild(newOpt);
            }

            selectPatientById(p.patient_id);

            const modalEl = document.getElementById('newIpdPatientModal');
            bootstrap.Modal.getInstance(modalEl)?.hide();

            const mainContainer = document.querySelector('.container-fluid');
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-success alert-dismissible fade show shadow-xs border-success-subtle mb-3';
            alertDiv.innerHTML = `<i class="ti ti-check me-2"></i><strong>${escapeHtml(p.name)}</strong> successfully admitted to IPD (${escapeHtml(p.ipd_ward)} / ${escapeHtml(p.ipd_bed)}) and selected for billing! <button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
            mainContainer.insertBefore(alertDiv, mainContainer.children[1] || mainContainer.firstChild);
        } else {
            alertBox.className = 'alert alert-danger mb-3 py-2 px-3 small';
            alertBox.textContent = data.message || 'Registration failed. Please check inputs.';
            alertBox.classList.remove('d-none');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="ti ti-check"></i> Register &amp; Select for Dispense';
        alertBox.className = 'alert alert-danger mb-3 py-2 px-3 small';
        alertBox.textContent = 'Server connection error. Please try again.';
        alertBox.classList.remove('d-none');
    });
}

// ----------------- LIVE PATIENT SEARCH SUITE (WITH SORTING & FILTERING) -----------------
let patientSortMode = 'recent'; // 'recent', 'az', 'za'
let patientWardFilter = 'ALL';

function setPatientSortMode(mode, e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    patientSortMode = mode;
    const input = document.getElementById('ipdPatientSearchInput');
    onPatientSearchInput(input ? input.value : '');
    if (input) input.focus();
}

function setPatientWardFilter(ward, e) {
    if (e) {
        e.stopPropagation();
    }
    patientWardFilter = ward;
    const input = document.getElementById('ipdPatientSearchInput');
    onPatientSearchInput(input ? input.value : '');
    if (input) input.focus();
}

function highlightPatientMatch(text, query) {
    if (!text) return '';
    if (!query) return escapeHtml(text);
    const escaped = escapeHtml(text);
    const qEscaped = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const regex = new RegExp(`(^|\\s)(${qEscaped})`, 'gi');
    return escaped.replace(regex, '$1<span class="patient-match-color">$2</span>');
}

function onPatientSearchInput(query) {
    const list = document.getElementById('patientSuggestionsList');
    if (!list) return;

    query = (query || '').trim().toLowerCase();

    if (!query) {
        list.classList.add('d-none');
        list.innerHTML = '';
        activePatientSuggestions = [];
        selectedPatientIndex = -1;
        return;
    }

    // 1. Filter by Query and Ward
    let matches = ipdPatientsList.filter(p => {
        // Ward filter
        if (patientWardFilter !== 'ALL') {
            const w = (p.ipd_ward || '').trim().toLowerCase();
            if (w !== patientWardFilter.toLowerCase()) return false;
        }

        const rawName = (p.name || '').trim().toLowerCase();
        const uhid = (p.hospital_uhid || '').trim().toLowerCase();
        const ward = (p.ipd_ward || '').trim().toLowerCase();
        const bed = (p.ipd_bed || '').trim().toLowerCase();
        const mob = (p.mobile || '').trim().toLowerCase();
        const adm = (p.ipd_admission_no || '').trim().toLowerCase();

        return rawName.includes(query) || uhid.includes(query) || ward.includes(query) || bed.includes(query) || mob.includes(query) || adm.includes(query);
    });

    // 2. Sort according to patientSortMode
    if (patientSortMode === 'az') {
        matches.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
    } else if (patientSortMode === 'za') {
        matches.sort((a, b) => (b.name || '').localeCompare(a.name || ''));
    } else {
        // 'recent' - Default admission order. If user types a search term, prioritize names starting with query
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

    activePatientSuggestions = matches;
    selectedPatientIndex = -1;

    // Collect unique wards for quick filter
    const uniqueWards = ['ALL'];
    ipdPatientsList.forEach(p => {
        if (p.ipd_ward && !uniqueWards.includes(p.ipd_ward)) {
            uniqueWards.push(p.ipd_ward);
        }
    });

    // 3. Render Sticky Header with Sort / Filter Controls
    let headerHtml = `
        <div class="patient-dropdown-header" onclick="event.stopPropagation()">
            <div class="d-flex align-items-center gap-1.5">
                <span class="text-dark fw-bold" style="font-size: 0.78rem;">
                    <i class="bi bi-person-lines-fill text-primary me-1"></i>Admitted Patients (${matches.length})
                </span>
            </div>
            <div class="d-flex align-items-center gap-1 flex-wrap">
                <span class="text-muted small me-1" style="font-size: 0.72rem;">Sort:</span>
                <button type="button" class="pat-filter-btn ${patientSortMode === 'recent' ? 'active' : ''}" 
                        onclick="setPatientSortMode('recent', event)" title="Sort by Most Recent Admission">
                    <i class="bi bi-clock-history"></i> Recent
                </button>
                <button type="button" class="pat-filter-btn ${patientSortMode === 'az' ? 'active' : ''}" 
                        onclick="setPatientSortMode('az', event)" title="Sort Alphabetically A to Z">
                    <i class="bi bi-sort-alpha-down"></i> A - Z
                </button>
                <button type="button" class="pat-filter-btn ${patientSortMode === 'za' ? 'active' : ''}" 
                        onclick="setPatientSortMode('za', event)" title="Sort Alphabetically Z to A">
                    <i class="bi bi-sort-alpha-up-alt"></i> Z - A
                </button>
                ${uniqueWards.length > 2 ? `
                <select class="form-select form-select-sm py-0 px-2 fw-semibold text-secondary border-secondary-subtle rounded-pill ms-1" 
                        style="font-size: 0.72rem; height: 26px; width: auto;" 
                        onchange="setPatientWardFilter(this.value, event)">
                    ${uniqueWards.map(w => `<option value="${escapeHtml(w)}" ${patientWardFilter === w ? 'selected' : ''}>${w === 'ALL' ? 'All Wards' : escapeHtml(w)}</option>`).join('')}
                </select>
                ` : ''}
            </div>
        </div>
    `;

    let bodyHtml = '';
    if (matches.length === 0) {
        bodyHtml = `
            <div class="p-4 text-center text-muted">
                <i class="ti ti-user-x fs-2 d-block mb-1 text-secondary opacity-50"></i>
                <div class="small fw-semibold text-dark">No admitted inpatient found matching "${escapeHtml(query)}"</div>
                <div class="small text-muted mt-1">Check filter criteria or register a new inpatient:</div>
                <button type="button" class="btn btn-sm text-white mt-2 fw-semibold px-3 py-1 shadow-sm" style="background-color: #0284c7; border-color: #0284c7;" onclick="openNewIpdModal('${escapeHtml(query)}')">
                    <i class="ti ti-plus me-1"></i>New Register to IPD
                </button>
            </div>
        `;
    } else {
        matches.forEach((p, idx) => {
            bodyHtml += `
                <div class="patient-suggest-item" 
                     data-index="${idx}"
                     onmouseenter="highlightPatientSuggestion(${idx})"
                     onclick="selectPatientById(${p.patient_id})">
                    <div class="d-flex align-items-center gap-3">
                        <div class="pat-avatar">
                            ${escapeHtml((p.name || 'P').charAt(0).toUpperCase())}
                        </div>
                        <div>
                            <div class="pat-name">${highlightPatientMatch(p.name, query)}</div>
                            <div class="pat-meta">
                                <span class="badge bg-light text-secondary border font-monospace px-1.5 py-0.5">${escapeHtml(p.hospital_uhid || 'IPD')}</span>
                                <span class="badge bg-emerald-subtle text-emerald border border-emerald-subtle px-1.5 py-0.5"><i class="bi bi-hospital me-1"></i>${escapeHtml(p.ipd_ward)} / ${escapeHtml(p.ipd_bed)}</span>
                                ${p.doctor_name ? `<span class="d-inline-flex align-items-center gap-1"><i class="bi bi-person-badge text-primary"></i>${escapeHtml(p.doctor_name)}</span>` : ''}
                                ${p.mobile ? `<span class="d-inline-flex align-items-center gap-1"><i class="bi bi-telephone text-secondary"></i>${escapeHtml(p.mobile)}</span>` : ''}
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
            <span class="text-muted d-inline-flex align-items-center gap-1"><i class="bi bi-people-fill text-primary"></i><strong>${matches.length}</strong> admitted inpatient${matches.length === 1 ? '' : 's'}</span>
            <a href="javascript:void(0)" onclick="openNewIpdModal()" class="fw-semibold text-decoration-none d-inline-flex align-items-center gap-1" style="color: #0284c7;">
                <i class="bi bi-plus-circle"></i> Register New Inpatient
            </a>
        </div>
    `;

    list.innerHTML = headerHtml + bodyHtml + footerHtml;
    list.classList.remove('d-none');
}

function onPatientSearchFocus() {
    const input = document.getElementById('ipdPatientSearchInput');
    const val = input ? input.value.trim() : '';
    if (val) {
        onPatientSearchInput(val);
    } else {
        const list = document.getElementById('patientSuggestionsList');
        if (list) {
            list.classList.add('d-none');
            list.innerHTML = '';
        }
    }
}

function onPatientSearchKeydown(e) {
    const list = document.getElementById('patientSuggestionsList');
    const isShowing = list && !list.classList.contains('d-none') && activePatientSuggestions.length > 0;

    if (isShowing) {
        const items = list.querySelectorAll('.patient-suggest-item');
        if (items.length > 0) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                selectedPatientIndex = (selectedPatientIndex + 1) % items.length;
                updateActivePatientItem(items);
                return;
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                selectedPatientIndex = (selectedPatientIndex - 1 + items.length) % items.length;
                updateActivePatientItem(items);
                return;
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (selectedPatientIndex >= 0 && selectedPatientIndex < items.length) {
                    items[selectedPatientIndex].click();
                } else if (activePatientSuggestions.length > 0) {
                    selectPatientById(activePatientSuggestions[0].patient_id);
                }
                return;
            } else if (e.key === 'Escape') {
                list.classList.add('d-none');
                selectedPatientIndex = -1;
                return;
            }
        }
    }

    // When Enter or Tab is pressed on Patient input:
    if (e.key === 'Enter' || (e.key === 'Tab' && !e.shiftKey)) {
        e.preventDefault();
        if (isShowing && activePatientSuggestions.length > 0) {
            selectPatientById(activePatientSuggestions[0].patient_id);
        } else {
            const medInput = document.getElementById('chargeMedicineInput');
            if (medInput) {
                medInput.focus();
                medInput.select();
            }
        }
    }
}

function highlightPatientSuggestion(idx) {
    selectedPatientIndex = idx;
    const list = document.getElementById('patientSuggestionsList');
    const items = list.querySelectorAll('.patient-suggest-item');
    updateActivePatientItem(items);
}

function updateActivePatientItem(items) {
    items.forEach((el, i) => {
        if (i === selectedPatientIndex) {
            el.classList.add('active-nav');
            el.scrollIntoView({ block: 'nearest' });
        } else {
            el.classList.remove('active-nav');
        }
    });
}

function selectPatientById(patientId) {
    const p = ipdPatientsList.find(x => x.patient_id == patientId);
    if (!p) return;

    // Set hidden form inputs
    document.getElementById('inpPatientId').value = p.patient_id;
    document.getElementById('inpPatientName').value = p.name;
    document.getElementById('inpHospitalUhid').value = p.hospital_uhid || '';
    document.getElementById('inpIpdAdmissionNo').value = p.ipd_admission_no || '';
    document.getElementById('inpIpdWard').value = p.ipd_ward || '';
    document.getElementById('inpIpdBed').value = p.ipd_bed || '';
    document.getElementById('inpDoctorName').value = p.doctor_name || '';

    // Set display inputs
    if (document.getElementById('displayIpdAdmissionNo')) document.getElementById('displayIpdAdmissionNo').value = p.ipd_admission_no || '';
    if (document.getElementById('displayPatientUhid')) document.getElementById('displayPatientUhid').value = p.hospital_uhid || 'IPD';
    if (document.getElementById('displayIpdWardBed')) document.getElementById('displayIpdWardBed').value = (p.ipd_ward || p.ipd_bed) ? `${p.ipd_ward} / ${p.ipd_bed}` : '';
    if (document.getElementById('displayDoctorName')) document.getElementById('displayDoctorName').value = p.doctor_name || '';

    // Update patient card info
    const card = document.getElementById('selectedPatientInfoCard');
    const searchSection = document.getElementById('patientSearchSection');
    if (card) {
        document.getElementById('cardPatientName').textContent = p.name;
        document.getElementById('cardPatientAvatar').textContent = (p.name || 'P').trim().charAt(0).toUpperCase();
        document.getElementById('cardPatientMeta').textContent = `UHID: ${p.hospital_uhid || 'IPD'} • Phone: ${p.mobile || 'N/A'}`;
        card.classList.remove('d-none');
    }
    if (searchSection) {
        searchSection.classList.add('d-none');
    }

    // Hide dropdown list
    document.getElementById('patientSuggestionsList').classList.add('d-none');
    selectedPatientIndex = -1;

    // Sync hidden select
    const select = document.getElementById('ipdPatientSelect');
    if (select) select.value = p.patient_id;

    // Smoothly focus to medicine search
    setTimeout(() => {
        const medInput = document.getElementById('chargeMedicineInput');
        if (medInput) {
            medInput.focus();
            medInput.select();
        }
    }, 150);
}

function clearSelectedPatient() {
    document.getElementById('inpPatientId').value = '';
    document.getElementById('inpPatientName').value = '';
    document.getElementById('inpHospitalUhid').value = '';
    document.getElementById('inpIpdAdmissionNo').value = '';
    document.getElementById('inpIpdWard').value = '';
    document.getElementById('inpIpdBed').value = '';
    document.getElementById('inpDoctorName').value = '';

    document.getElementById('displayIpdAdmissionNo').value = '';
    document.getElementById('displayIpdWardBed').value = '';
    document.getElementById('displayDoctorName').value = '';

    const searchInput = document.getElementById('ipdPatientSearchInput');
    if (searchInput) searchInput.value = '';
    
    const card = document.getElementById('selectedPatientInfoCard');
    if (card) card.classList.add('d-none');

    const searchSection = document.getElementById('patientSearchSection');
    if (searchSection) searchSection.classList.remove('d-none');

    document.getElementById('patientSuggestionsList')?.classList.add('d-none');

    const select = document.getElementById('ipdPatientSelect');
    if (select) select.value = '';

    if (searchInput) {
        setTimeout(() => {
            searchInput.focus();
            searchInput.select();
        }, 100);
    }
}

function onIpdPatientChange(selectElem) {
    if (selectElem.value === '__NEW_IPD_REGISTER__') {
        openNewIpdModal();
        selectElem.value = '';
        return;
    }
    if (selectElem.value) {
        selectPatientById(selectElem.value);
    }
}

let selectedSuggestionIndex = -1;

function highlightMatch(text, query) {
    if (!text) return '';
    if (!query) return escapeHtml(text);
    const escaped = escapeHtml(text);
    const qEscaped = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const regex = new RegExp(`(${qEscaped})`, 'gi');
    return escaped.replace(regex, '<span class="search-highlight">$1</span>');
}

function getMedicineIconInfo(m) {
    const dosage = (m.dosage_form || '').toLowerCase();
    const cat = (m.category || '').toLowerCase();
    const name = (m.medicine_name || '').toLowerCase();

    // 1. Injections / IV / Syringe / Vials / Ampoules / Infusion
    if (dosage.includes('inj') || cat.includes('inj') || name.includes('inj') ||
        dosage.includes('iv') || dosage.includes('infusion') || dosage.includes('vial') ||
        dosage.includes('ampoule') || dosage.includes('syringe') || cat.includes('inject')) {
        return { icon: 'ti ti-syringe', cls: 'injection', bg: '#fce7f3', color: '#db2777' };
    }

    // 2. Syrups / Liquids / Suspensions / Solutions / Oral Drops
    if (dosage.includes('syrup') || cat.includes('syrup') || name.includes('syrup') ||
        dosage.includes('susp') || dosage.includes('liquid') || dosage.includes('oral') ||
        dosage.includes('solution') || cat.includes('susp')) {
        return { icon: 'ti ti-bottle', cls: 'syrup', bg: '#fef3c7', color: '#d97706' };
    }

    // 3. Inhaler / MDI / Spray / Respules
    if (dosage.includes('inhal') || cat.includes('inhal') || name.includes('inhal') ||
        dosage.includes('spray') || name.includes('mdi') || dosage.includes('resp') ||
        name.includes('spray')) {
        return { icon: 'ti ti-spray', cls: 'inhaler', bg: '#ecfeff', color: '#0891b2' };
    }

    // 4. Drops (Eye / Ear)
    if (dosage.includes('drop') || cat.includes('drop') || name.includes('drop')) {
        return { icon: 'ti ti-droplet', cls: 'drops', bg: '#e0f2fe', color: '#0284c7' };
    }

    // 5. Creams / Ointments / Gels / Balms
    if (dosage.includes('cream') || dosage.includes('ointment') || dosage.includes('gel') ||
        cat.includes('cream') || cat.includes('ointment') || name.includes('gel') ||
        name.includes('cream') || name.includes('ointment')) {
        return { icon: 'ti ti-bandage', cls: 'ointment', bg: '#fdf4ff', color: '#a21caf' };
    }

    // 6. Tablets / Capsules / Pills / Strip / Lozenges / Cap / Tab
    if (dosage.includes('tab') || cat.includes('tab') || name.includes('tablet') || name.includes(' tab') ||
        dosage.includes('cap') || cat.includes('cap') || name.includes('capsule') || name.includes(' cap') ||
        dosage.includes('pill') || dosage.includes('strip') || dosage.includes('lozenge')) {
        return { icon: 'ti ti-pill', cls: 'tablet', bg: '#eff6ff', color: '#2563eb' };
    }

    // Default for medicines (Tablet / Pill icon)
    return { icon: 'ti ti-pill', cls: 'tablet', bg: '#eff6ff', color: '#2563eb' };
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

    if (!query) {
        suggestionsBox.classList.add('d-none');
        suggestionsBox.innerHTML = '';
        activeSuggestions = [];
        selectedSuggestionIndex = -1;
        return;
    }

    const qLower = query.toLowerCase();
    let matches = catalogMedicines.filter(m => {
        const name = (m.medicine_name || '').trim().toLowerCase();
        const generic = (m.generic_name || '').trim().toLowerCase();
        const comp = (m.composition || '').trim().toLowerCase();
        const barcode = (m.barcode || '').trim().toLowerCase();
        if (barcode && barcode.includes(qLower)) return true;
        if (name.includes(qLower)) return true;
        if (generic && generic.includes(qLower)) return true;
        if (comp && comp.includes(qLower)) return true;
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
                <div class="small text-muted mt-1">Try typing brand name, generic name, or active composition</div>
            </div>
        `;
        suggestionsBox.classList.remove('d-none');
        return;
    }

    let html = '';
    matches.slice(0, 10).forEach((m, idx) => {
        const stock = parseInt(m.available_stock || 0);
        const isOutOfStock = stock <= 0;
        const iconInfo = getMedicineIconInfo(m);
        const price = parseFloat(m.mrp || 0).toFixed(2);
        
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
        const compStr = m.composition ? highlightMatch(m.composition, query) : '';
        const dosageStr = m.dosage_form || m.unit || 'unit';
        const catStr = m.category || 'General';

        const iconBg = iconInfo.bg || '#eff6ff';
        const iconColor = iconInfo.color || '#2563eb';
        const mfgRate = parseFloat(m.purchase_price || 0).toFixed(2);
        const expDate = m.expiry_date || 'N/A';
        const expStatus = getExpiryStatus(expDate);
        const manufacturer = m.manufacturer || 'Standard Labs';

        html += `
            <div class="med-suggest-item ${isOutOfStock ? 'disabled' : ''}" data-index="${idx}" onclick="selectMedicineSuggestion(${m.medicine_id})"
                 style="display: flex !important; align-items: center !important; justify-content: space-between !important; padding: 10px 14px !important; border-bottom: 1px solid #f1f5f9 !important; cursor: pointer !important; text-decoration: none !important;">
                <div class="d-flex align-items-center me-2 text-start flex-grow-1" style="min-width: 0;">
                    <div class="med-icon-box ${iconInfo.cls}" style="width: 38px; height: 38px; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 1.25rem; margin-right: 12px; background-color: ${iconBg}; color: ${iconColor};">
                        <i class="${iconInfo.icon}"></i>
                    </div>
                    <div style="min-width: 0;">
                        <div class="med-name-title text-truncate" style="font-weight: 600; font-size: 0.90rem; color: #0f172a; line-height: 1.28;">${highlightedName}</div>
                        ${compStr ? `
                            <div class="med-composition-badge mt-0.5" style="font-size: 0.72rem; color: #047857; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 4px; padding: 1px 6px; display: inline-flex; align-items: center; gap: 3px;">
                                <i class="ti ti-flask"></i><span class="fw-bold">Contains:</span> ${compStr}
                            </div>
                        ` : ''}
                        <div class="med-meta-desc" style="font-size: 0.74rem; color: #64748b; margin-top: 3px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                            <span class="med-category-chip" style="font-size: 0.66rem; font-weight: 600; background: #f1f5f9; color: #475569; padding: 1px 6px; border-radius: 4px; border: 1px solid #e2e8f0;">${escapeHtml(catStr)}</span>
                            <span class="badge bg-light text-secondary border" style="font-size: 0.68rem;"><i class="bi bi-building me-1 text-primary"></i>${escapeHtml(manufacturer)}</span>
                            <span class="badge ${expStatus.cls}" style="font-size: 0.68rem; background-color: ${expStatus.badgeBg} !important; color: ${expStatus.badgeColor} !important; border: 1px solid ${expStatus.badgeBorder} !important;" title="${expStatus.tag}: ${expStatus.label}">
                                <i class="bi ${expStatus.icon} me-1"></i>Exp: ${escapeHtml(expDate)} <span class="fw-bold">(${expStatus.label})</span>
                            </span>
                            <span class="text-truncate">${genericStr && genericStr !== compStr ? genericStr + ' &bull; ' : ''}${escapeHtml(dosageStr)}</span>
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
    if (val) {
        onMedicineSearchInput(val);
    } else {
        const suggestionsBox = document.getElementById('medicineSuggestionsList');
        if (suggestionsBox) {
            suggestionsBox.classList.add('d-none');
            suggestionsBox.innerHTML = '';
        }
    }
}

function clearMedicineSearch() {
    const input = document.getElementById('chargeMedicineInput');
    if (input) {
        input.value = '';
        input.focus();
    }
    const suggestionsBox = document.getElementById('medicineSuggestionsList');
    if (suggestionsBox) {
        suggestionsBox.innerHTML = '';
        suggestionsBox.classList.add('d-none');
    }
    activeSuggestions = [];
    selectedSuggestionIndex = -1;
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

    // When Enter is pressed in medicine search:
    if (e.key === 'Enter') {
        if (activeSuggestions.length > 0) {
            e.preventDefault();
            selectMedicineSuggestion(activeSuggestions[0].medicine_id);
            return;
        }
    }

    // When Tab is pressed: directly triggers and opens Proceed to Billing Preview modal!
    if (e.key === 'Tab' && !e.shiftKey) {
        if (cart.length > 0) {
            e.preventDefault();
            if (suggestionsBox) suggestionsBox.classList.add('d-none');
            openBillingPreviewModal();
            return;
        }
    }

    // ArrowDown redirects directly into the quantity input of the selected medicine
    if (e.key === 'ArrowDown') {
        if (cart.length > 0) {
            e.preventDefault();
            focusCartQty(0);
            return;
        }
    }

    // ArrowUp redirects back up to the Patient search input if present
    if (e.key === 'ArrowUp') {
        const pInput = document.getElementById('tablePatientSearch') || document.getElementById('patientSearchInput');
        if (pInput) {
            e.preventDefault();
            pInput.focus();
            pInput.select();
            return;
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
        opt.dataset.salePrice = med ? (med.price || med.mrp || 0) : 0;
        opt.dataset.stock = med ? (med.stock_quantity || med.available_stock || 0) : 0;
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

let autoAddTimer = null;
function showAutoAddNotice(msg, type = 'success') {
    const notice = document.getElementById('autoAddNotification');
    const text = document.getElementById('autoAddNotificationText');
    if (!notice || !text) return;

    if (type === 'warning') {
        notice.style.backgroundColor = '#fffbeb';
        notice.style.color = '#b45309';
        notice.style.borderColor = '#fcd34d';
        text.innerHTML = `<i class="ti ti-alert-triangle me-1"></i> ${escapeHtml(msg)}`;
    } else {
        notice.style.backgroundColor = '#ecfdf5';
        notice.style.color = '#047857';
        notice.style.borderColor = '#a7f3d0';
        text.innerHTML = `<i class="ti ti-check me-1"></i> ${escapeHtml(msg)}`;
    }

    notice.classList.remove('d-none');
    clearTimeout(autoAddTimer);
    autoAddTimer = setTimeout(() => {
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
            generic_name: med.generic_name || '',
            composition: med.composition || '',
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

    const targetIdx = existingIdx !== -1 ? existingIdx : cart.length - 1;

    // Immediately render updated cart
    renderCart();

    // Clear medicine search input
    const medInput = document.getElementById('chargeMedicineInput');
    if (medInput) {
        medInput.value = '';
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

    // Automatically focus the quantity (QTY) field of the selected medicine
    setTimeout(() => {
        focusCartQty(targetIdx);
    }, 60);
}

function onQtyKeydown(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        addCurrentChargeToCart();
        const searchInput = document.getElementById('chargeMedicineInput');
        searchInput.focus();
        searchInput.select();
    }
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('#chargeMedicineInput') && !e.target.closest('#medicineSuggestionsList')) {
        document.getElementById('medicineSuggestionsList')?.classList.add('d-none');
    }
});

function calculateChargeRowTotal() {
    const qty = Math.max(1, parseInt(document.getElementById('chargeQtyInput')?.value) || 1);
    const rate = Math.max(0, parseFloat(document.getElementById('chargeRateInput')?.value) || 0);
    const total = qty * rate;
    const totalDisp = document.getElementById('chargeTotalDisplay');
    if (totalDisp) totalDisp.value = total.toFixed(2);
}

function clearCurrentChargeInputs() {
    const medInput = document.getElementById('chargeMedicineInput');
    if (medInput) medInput.value = '';
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

function addCurrentChargeToCart() {
    let medId = parseInt(document.getElementById('selectedMedicineId')?.value || 0);
    let medName = (document.getElementById('chargeMedicineInput')?.value || '').trim();

    if (!medId && medName) {
        const match = catalogMedicines.find(m => m.medicine_name.toLowerCase() === medName.toLowerCase()) || activeSuggestions[0];
        if (match) {
            medId = match.medicine_id;
        }
    }

    if (medId) {
        selectMedicineSuggestion(medId);
    } else {
        alert('Please search and select a Medicine first.');
        document.getElementById('chargeMedicineInput')?.focus();
    }
}

function renderCart() {
    const tbody = document.getElementById('cartTableBody');
    const totalBadge = document.getElementById('chargeDetailsTotalBadge');
    const grandTotalLbl = document.getElementById('lblGrandTotal');

    if (cart.length === 0) {
        tbody.innerHTML = `
            <tr id="emptyCartRow">
                <td colspan="9" class="text-center py-4 text-muted small">
                    No charges added yet. Search a medicine and charge above.
                </td>
            </tr>
        `;
        totalBadge.textContent = 'Total: ₹0.00';
        grandTotalLbl.textContent = '₹0.00';
        updateHiddenFormInputs();
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
                            class="btn btn-sm py-1 px-2.5 font-monospace d-inline-flex align-items-center gap-1.5 border shadow-2xs expiry-picker-btn text-nowrap"
                            style="background-color: ${expStatus.badgeBg} !important; color: ${expStatus.badgeColor} !important; border-color: ${expStatus.badgeBorder} !important; font-size: 0.76rem; border-radius: 6px; font-weight: 600;"
                            onclick="toggleBatchExpiryMenu(event, ${idx})"
                            title="Click to view & switch between ${batches.length} available batches">
                        <i class="bi ${expStatus.icon}"></i>
                        <span>${escapeHtml(item.expiry_date || 'N/A')}</span>
                        <span class="small opacity-75">(${expStatus.label})</span>
                        <span class="badge bg-white text-dark border ms-1 px-1.5 py-0.5" style="font-size: 0.68rem; font-weight: 700;">${batches.length} batches ▾</span>
                    </button>
                    <div id="batchExpiryMenu_${idx}" class="batch-expiry-menu shadow-lg p-0 border d-none" style="min-width: 320px;">
                        <div class="px-3 py-2 border-bottom bg-light d-flex justify-content-between align-items-center rounded-top-2" style="font-size: 0.74rem;">
                            <span class="fw-bold text-dark"><i class="ti ti-layers me-1 text-primary"></i>Select Batch / Expiry</span>
                            <span class="badge bg-primary-subtle text-primary border" style="font-size: 0.68rem;">FEFO Earliest First</span>
                        </div>
                        <div class="p-1.5" style="max-height: 230px; overflow-y: auto;">
                            ${batchItemsHtml}
                        </div>
                    </div>
                </div>
            `;
        } else {
            expiryCellHtml = `
                <span class="badge font-monospace px-2.5 py-1 text-nowrap ${expStatus.cls}" style="font-size: 0.76rem; background-color: ${expStatus.badgeBg} !important; color: ${expStatus.badgeColor} !important; border: 1px solid ${expStatus.badgeBorder} !important; white-space: nowrap !important; display: inline-flex; align-items: center; gap: 5px;" title="${expStatus.tag}: ${expStatus.label}">
                    <i class="bi ${expStatus.icon}"></i>
                    <span style="white-space: nowrap;">${escapeHtml(item.expiry_date || 'N/A')}</span>
                    <span class="small opacity-75" style="white-space: nowrap;">(${expStatus.label})</span>
                </span>
            `;
        }

        const containsRemark = item.composition || item.generic_name || '';

        html += `
            <tr class="align-middle cart-row" tabindex="0" data-cart-index="${idx}" onkeydown="onCartRowKeydown(event, ${idx})" onfocus="this.classList.add('active-row-focus')" onblur="this.classList.remove('active-row-focus')">
                <td class="text-center text-muted fw-semibold small">${idx + 1}</td>
                <td class="align-middle">
                    <div class="fw-bold text-dark fs-6" style="line-height: 1.25;">${escapeHtml(item.medicine_name)}</div>
                    <div class="text-muted small" style="font-size: 0.72rem; display: flex; align-items: center; gap: 5px; flex-wrap: wrap; margin-top: 3px;">
                        <span class="badge font-monospace" style="background:#e0e7ff; color:#3730a3; font-size:0.68rem; padding: 1px 6px; border-radius: 4px;"><i class="ti ti-package me-1"></i>Batch: ${escapeHtml(item.batch_number || 'GEN-01')}</span>
                        ${item.manufacturer ? '<span class="text-primary fw-semibold">' + escapeHtml(item.manufacturer) + '</span> &bull; ' : ''}Cat: ${escapeHtml(item.category)} &bull; ${escapeHtml(item.unit)} &bull; Stock: ${item.max_stock}
                    </div>
                </td>
                <td class="align-middle">
                    ${containsRemark ? `
                        <div class="small fw-semibold d-inline-flex align-items-center gap-1.5 px-2 py-1 rounded" style="font-size: 0.76rem; color: #047857; background-color: #ecfdf5; border: 1px solid #a7f3d0; max-width: 100%; word-break: break-word;">
                            <i class="ti ti-flask flex-shrink-0" style="font-size: 0.85rem;"></i>
                            <span>${escapeHtml(containsRemark)}</span>
                        </div>
                    ` : '<span class="text-muted small fst-italic" style="font-size: 0.75rem;">—</span>'}
                </td>
                <td class="align-middle" style="white-space: nowrap;">${expiryCellHtml}</td>
                <td class="text-end font-monospace text-muted align-middle" style="font-size: 0.84rem;">₹${(item.purchase_price || 0).toFixed(2)}</td>
                <td class="text-end fw-bold text-dark font-monospace align-middle" style="font-size: 0.88rem;">₹${item.price.toFixed(2)}</td>
                <td class="text-center align-middle">
                    <input type="number" id="cart_qty_${idx}" min="1" max="${item.max_stock}" class="form-control form-control-sm text-center fw-bold px-2 m-auto cart-qty-input shadow-xs" style="width: 60px; height: 32px; border-radius: 6px; font-size: 0.88rem;" value="${item.quantity}" oninput="onCartQtyInput(this, ${idx})" onblur="onCartQtyBlur(this, ${idx})" onfocus="this.select()" onkeydown="onCartQtyKeydown(event, ${idx})" aria-label="Quantity for ${escapeHtml(item.medicine_name)}">
                </td>
                <td class="text-end fw-bold text-dark font-monospace item-line-total align-middle" style="font-size: 0.88rem;">₹${lineTotal.toFixed(2)}</td>
                <td class="text-center align-middle">
                    <button type="button" class="btn btn-sm btn-outline-danger p-1 rounded-2" onclick="removeItemFromCart(${idx})" title="Remove">
                        <i class="ti ti-trash"></i>
                    </button>
                </td>
            </tr>
        `;
    });

    // Add inline quick helper / search indicator row at the bottom of the table
    html += `
        <tr class="table-keyboard-helper-row" style="background-color: #f8fafc; border-top: 1px dashed #cbd5e1;">
            <td colspan="9" class="py-2.5 px-3">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2 small">
                        <button type="button" class="btn btn-sm btn-outline-primary py-1 px-3 rounded-pill d-inline-flex align-items-center gap-1.5 fw-semibold" onclick="const mi=document.getElementById('chargeMedicineInput'); if(mi){mi.focus(); mi.select();}">
                            <i class="bi bi-search"></i>
                            <span>Add More Medicines</span>
                            <kbd class="bg-white text-dark border px-1" style="font-size: 0.68rem;">Enter / F2</kbd>
                        </button>
                        <span class="text-muted" style="font-size: 0.76rem;">Type medicine name &amp; press <kbd class="bg-white text-dark border px-1">Enter</kbd> to add continuously</span>
                    </div>
                    <div class="small text-end">
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1.5 rounded-2 d-inline-flex align-items-center gap-1.5" style="font-size: 0.76rem; font-weight: 600;">
                            <i class="bi bi-arrow-right-circle-fill"></i>
                            <span>All medicines added? Press <kbd class="bg-white text-dark border px-1.5 py-0.5 rounded shadow-2xs fw-bold">Tab</kbd> to Proceed to Billing</span>
                        </span>
                    </div>
                </div>
            </td>
        </tr>
    `;

    tbody.innerHTML = html;
    recalculateCartTotalsSummary();
}

function toggleBatchExpiryMenu(e, cartIdx) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
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

function focusCartQty(idx) {
    if (cart.length === 0) return;
    
    if (idx < 0) {
        // Slide back up to the medicine search input
        const searchInput = document.getElementById('chargeMedicineInput');
        if (searchInput) {
            document.querySelectorAll('.cart-row').forEach(r => r.classList.remove('active-row-focus'));
            searchInput.focus();
            searchInput.select();
        }
        return;
    }

    if (idx >= cart.length) {
        // Slide past the last row to Proceed to Billing Preview button
        const proceedBtn = document.getElementById('btnProceedPreview') || document.getElementById('btnSubmitSale');
        if (proceedBtn) {
            document.querySelectorAll('.cart-row').forEach(r => r.classList.remove('active-row-focus'));
            proceedBtn.focus();
        }
        return;
    }

    const qtyInp = document.getElementById(`cart_qty_${idx}`);
    if (qtyInp) {
        document.querySelectorAll('.cart-row').forEach(r => r.classList.remove('active-row-focus'));
        const row = qtyInp.closest('.cart-row');
        if (row) row.classList.add('active-row-focus');
        qtyInp.focus();
        qtyInp.select();
        qtyInp.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}

function focusCartRow(idx) {
    focusCartQty(idx);
}

function onCartRowKeydown(e, idx) {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.target.tagName === 'BUTTON') {
        return;
    }

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        focusCartQty(idx + 1);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        focusCartQty(idx - 1);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        focusCartQty(idx);
    } else if (e.key === 'Escape') {
        e.preventDefault();
        focusCartQty(-1);
    } else if (e.key === 'Delete' || (e.ctrlKey && e.key === 'Delete')) {
        e.preventDefault();
        removeItemFromCart(idx);
    } else if (e.key >= '1' && e.key <= '9') {
        const qty = document.getElementById(`cart_qty_${idx}`);
        if (qty) {
            qty.focus();
            qty.value = e.key;
            onCartQtyInput(qty, idx);
        }
    }
}

function onCartQtyKeydown(e, idx) {
    if (e.key === 'Enter') {
        e.preventDefault();
        e.stopPropagation();
        // Return to medicine search to add next medicine
        const searchInput = document.getElementById('chargeMedicineInput');
        if (searchInput) {
            searchInput.value = '';
            searchInput.focus();
            searchInput.select();
        }
        return;
    } else if (e.key === 'Tab') {
        e.preventDefault();
        if (e.shiftKey) {
            if (idx > 0) {
                focusCartQty(idx - 1);
            } else {
                const searchInput = document.getElementById('chargeMedicineInput');
                if (searchInput) searchInput.focus();
            }
        } else {
            if (cart.length > 0) {
                openBillingPreviewModal();
            } else {
                const proceedBtn = document.getElementById('btnProceedPreview') || document.getElementById('btnSubmitSale');
                if (proceedBtn) proceedBtn.focus();
            }
        }
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        focusCartQty(idx - 1);
    } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (idx < cart.length - 1) {
            focusCartQty(idx + 1);
        } else {
            if (cart.length > 0) {
                openBillingPreviewModal();
            } else {
                const proceedBtn = document.getElementById('btnProceedPreview') || document.getElementById('btnSubmitSale');
                if (proceedBtn) proceedBtn.focus();
            }
        }
    } else if (e.key === 'Delete' || (e.key === 'Backspace' && e.ctrlKey)) {
        e.preventDefault();
        removeItemFromCart(idx);
    }
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

function splitCartItemAcrossBatches(cartIdx, totalQty) {
    const item = cart[cartIdx];
    const med = catalogMedicines.find(m => m.medicine_id === item.medicine_id);
    if (!med || !med.batches || med.batches.length <= 1) {
        item.quantity = Math.min(totalQty, item.max_stock);
        renderCart();
        return;
    }

    let remaining = totalQty;
    const newItems = [];

    med.batches.forEach(b => {
        if (remaining > 0 && b.stock > 0) {
            const take = Math.min(remaining, b.stock);
            newItems.push({
                medicine_id: med.medicine_id,
                medicine_name: med.medicine_name,
                batch_id: b.batch_id,
                batch_number: b.batch_number,
                manufacturer: med.manufacturer,
                expiry_date: b.expiry_date,
                purchase_price: b.purchase_price,
                category: med.category,
                unit: med.unit,
                quantity: take,
                price: b.sale_price > 0 ? b.sale_price : med.price,
                max_stock: b.stock,
                gst_percent: med.gst_percent
            });
            remaining -= take;
        }
    });

    if (newItems.length > 0) {
        cart.splice(cartIdx, 1, ...newItems);
        showAutoAddNotice(`Allocated ${totalQty} units across ${newItems.length} batches in FEFO order!`, 'success');
        renderCart();
    }
}

function onCartQtyInput(inputElem, idx) {
    let val = parseInt(inputElem.value);
    if (isNaN(val) || val < 1) {
        val = 1;
    }

    const currentItem = cart[idx];
    const med = catalogMedicines.find(m => m.medicine_id === currentItem.medicine_id);
    const batches = (med && Array.isArray(med.batches)) ? med.batches : [];
    const totalAvailStock = batches.reduce((sum, b) => sum + (b.stock || 0), 0) || currentItem.max_stock;

    if (val > totalAvailStock) {
        alert(`Maximum total available stock across all batches is ${totalAvailStock}.`);
        val = totalAvailStock;
        inputElem.value = val;
    }

    if (val > currentItem.max_stock && batches.length > 1) {
        const confirmSplit = confirm(`Batch ${currentItem.batch_number} only has ${currentItem.max_stock} units available.\n\nDo you want to automatically allocate ${currentItem.max_stock} from Batch ${currentItem.batch_number} and the remaining ${val - currentItem.max_stock} from the next earliest expiring batch?`);
        if (confirmSplit) {
            splitCartItemAcrossBatches(idx, val);
            return;
        } else {
            val = currentItem.max_stock;
            inputElem.value = val;
        }
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

    if (totalBadge) totalBadge.textContent = `Total: ₹${grandTotal.toFixed(2)}`;
    if (grandTotalLbl) grandTotalLbl.textContent = `₹${grandTotal.toFixed(2)}`;

    updateHiddenFormInputs();
}

function updateHiddenFormInputs() {
    const cartJsonInput = document.getElementById('cartItemsJson');
    const form = document.getElementById('ipdSaleForm');
    
    // Remove old dynamic medicine_id[] and quantity[] inputs
    form.querySelectorAll('.dynamic-item-input').forEach(e => e.remove());

    const payload = cart.map(c => ({
        medicine_id: c.medicine_id,
        batch_id: c.batch_id || 0,
        batch_number: c.batch_number || '',
        quantity: c.quantity,
        discount_percent: 0.0
    }));
    if (cartJsonInput) cartJsonInput.value = JSON.stringify(payload);

    // Also append hidden medicine_id[], batch_id[] and quantity[] inputs for traditional POST
    cart.forEach(c => {
        const mInput = document.createElement('input');
        mInput.type = 'hidden';
        mInput.name = 'medicine_id[]';
        mInput.value = c.medicine_id;
        mInput.className = 'dynamic-item-input';
        form.appendChild(mInput);

        const bInput = document.createElement('input');
        bInput.type = 'hidden';
        bInput.name = 'batch_id[]';
        bInput.value = c.batch_id || 0;
        bInput.className = 'dynamic-item-input';
        form.appendChild(bInput);

        const qInput = document.createElement('input');
        qInput.type = 'hidden';
        qInput.name = 'quantity[]';
        qInput.value = c.quantity;
        qInput.className = 'dynamic-item-input';
        form.appendChild(qInput);
    });
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
    clearSelectedPatient();
    clearCurrentChargeInputs(true);
    renderCart();
}

function saveDraft() {
    if (cart.length === 0) {
        alert('Cart is empty. Add charges before saving a draft.');
        return;
    }
    localStorage.setItem('pharmacy_ipd_bill_draft', JSON.stringify({
        patient_id: document.getElementById('inpPatientId').value,
        patient_name: document.getElementById('inpPatientName').value,
        cart: cart,
        saved_at: new Date().toISOString()
    }));
    alert('Draft IPD bill saved successfully in local cache!');
}

function openBillingPreviewModal() {
    const patientName = document.getElementById('inpPatientName').value.trim();
    if (!patientName) {
        alert('Please search and select an Inpatient first.');
        const pInput = document.getElementById('ipdPatientSearchInput');
        if (pInput) {
            pInput.focus();
            pInput.select();
        }
        return;
    }

    if (cart.length === 0) {
        alert('Your bill has no charges. Please add at least one medication item.');
        return;
    }

    document.getElementById('modalPatientName').textContent = patientName;
    document.getElementById('modalPatientUhid').textContent = document.getElementById('inpHospitalUhid').value || 'IPD';
    document.getElementById('modalWardBed').textContent = document.getElementById('displayIpdWardBed').value || '-';
    document.getElementById('modalDoctorInput').value = document.getElementById('displayDoctorName').value || '-';

    const listBody = document.getElementById('modalChargesList');
    let html = '';
    let subtotal = 0;
    let totalGst = 0;

    cart.forEach((item, idx) => {
        const itemTotal = item.quantity * item.price;
        subtotal += itemTotal;
        totalGst += itemTotal * ((item.gst_percent || 0) / 100);

        html += `
            <tr>
                <td class="text-center text-muted fw-semibold py-2.5 px-3">${idx + 1}</td>
                <td class="text-start py-2.5 px-3">
                    <div class="fw-bold text-dark text-truncate" style="max-width: 380px;">${escapeHtml(item.medicine_name)}</div>
                    ${item.composition || item.generic_name ? `
                        <div class="small mt-0.5" style="font-size: 0.70rem; color: #047857;">
                            <i class="ti ti-flask me-0.5"></i><span class="fw-semibold">Contains:</span> ${escapeHtml(item.composition || item.generic_name)}
                        </div>
                    ` : ''}
                    <div class="text-muted small mt-0.5 d-flex align-items-center flex-wrap" style="font-size: 0.74rem; gap: 4px;">
                        ${item.manufacturer ? '<span class="text-primary fw-medium">' + escapeHtml(item.manufacturer) + '</span> &bull; ' : ''}
                        <span>${escapeHtml(item.category)}</span> &bull; 
                        <span class="badge bg-light text-dark border px-1.5 py-0.2">${escapeHtml(item.unit)}</span>
                    </div>
                </td>
                <td class="text-start py-2.5 px-3">
                    <div class="d-flex flex-column" style="gap: 3px;">
                        <span class="font-monospace fw-bold text-dark" style="font-size: 0.8rem;"><i class="bi bi-box-seam" style="margin-right: 5px; color: #64748b;"></i>${escapeHtml(item.batch_number || 'GEN-01')}</span>
                        <span class="badge px-2 py-0.5 ${getExpiryStatus(item.expiry_date).cls}" style="background-color: ${getExpiryStatus(item.expiry_date).badgeBg} !important; color: ${getExpiryStatus(item.expiry_date).badgeColor} !important; border: 1px solid ${getExpiryStatus(item.expiry_date).badgeBorder} !important; font-size: 0.7rem; width: fit-content;">
                            <i class="bi ${getExpiryStatus(item.expiry_date).icon}" style="margin-right: 4px;"></i>Exp: ${escapeHtml(item.expiry_date || 'N/A')}
                        </span>
                    </div>
                </td>
                <td class="text-center fw-bold text-dark font-monospace py-2.5 px-2">${item.quantity}</td>
                <td class="text-end font-monospace text-muted py-2.5 px-2">₹${item.price.toFixed(2)}</td>
                <td class="text-end font-monospace fw-bold text-dark py-2.5 pe-3 ps-2" style="font-size: 0.95rem;">₹${itemTotal.toFixed(2)}</td>
            </tr>
        `;
    });
    listBody.innerHTML = html;

    recalcModalTotals();

    const previewModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('billingPreviewModal'));
    previewModal.show();

    // Automatically focus the Settlement Mode when modal opens
    setTimeout(() => {
        const modeSelect = document.getElementById('modalPaymentMode');
        if (modeSelect) {
            modeSelect.focus();
        }
    }, 350);
}

function handleModalModeKeydown(e) {
    if (e.key === 'Enter' || (e.key === 'Tab' && !e.shiftKey)) {
        e.preventDefault();
        const discInp = document.getElementById('modalDiscountValue');
        if (discInp) { discInp.focus(); discInp.select(); }
    }
}

function handleModalDiscountKeydown(e) {
    if (e.key === 'Enter' || (e.key === 'Tab' && !e.shiftKey)) {
        e.preventDefault();
        const confirmBtn = document.getElementById('modalBtnConfirmSale');
        if (confirmBtn) confirmBtn.focus();
    } else if (e.key === 'ArrowLeft' && e.altKey) {
        e.preventDefault();
        setDiscountMode('PERCENT');
    } else if (e.key === 'ArrowRight' && e.altKey) {
        e.preventDefault();
        setDiscountMode('FLAT');
    }
}

function setDiscountMode(mode) {
    const hiddenType = document.getElementById('modalDiscountType');
    if (hiddenType) hiddenType.value = mode;

    const btnPercent = document.getElementById('btnDiscPercent');
    const btnFlat = document.getElementById('btnDiscFlat');
    const dInput = document.getElementById('modalDiscountValue');
    const dAddon = document.getElementById('modalDiscountAddon');

    if (mode === 'FLAT') {
        if (btnFlat) btnFlat.classList.add('active');
        if (btnPercent) btnPercent.classList.remove('active');
        if (dAddon) dAddon.textContent = '₹';
        if (dInput) {
            dInput.step = '1';
            dInput.removeAttribute('max');
            dInput.placeholder = '0';
        }
    } else {
        if (btnPercent) btnPercent.classList.add('active');
        if (btnFlat) btnFlat.classList.remove('active');
        if (dAddon) dAddon.textContent = '%';
        if (dInput) {
            dInput.step = '1';
            dInput.max = '50';
            dInput.placeholder = '0';
        }
    }
    recalcModalTotals();
    if (dInput) {
        dInput.focus();
        dInput.select();
    }
}

function onDiscountTypeChange() {
    const dType = document.getElementById('modalDiscountType') ? document.getElementById('modalDiscountType').value : 'PERCENT';
    setDiscountMode(dType);
}

function recalcModalTotals() {
    let subtotal = 0;
    let totalGst = 0;
    cart.forEach(item => {
        const itemTotal = item.quantity * item.price;
        subtotal += itemTotal;
        totalGst += itemTotal * ((item.gst_percent || 0) / 100);
    });

    const discType = document.getElementById('modalDiscountType') ? document.getElementById('modalDiscountType').value : 'PERCENT';
    const rawVal = Math.max(0, parseInt(document.getElementById('modalDiscountValue')?.value, 10) || 0);
    
    let discountAmt = 0;
    let effectivePercent = 0;

    if (discType === 'FLAT') {
        discountAmt = Math.min(subtotal, rawVal);
        effectivePercent = subtotal > 0 ? (discountAmt / subtotal) * 100 : 0;
    } else {
        effectivePercent = Math.min(50, rawVal);
        discountAmt = subtotal * (effectivePercent / 100);
    }

    const taxable = Math.max(0, subtotal - discountAmt);
    const grandTotal = Math.round((taxable + totalGst) * 100) / 100;

    document.getElementById('modalLblSubtotal').textContent = `₹${subtotal.toFixed(2)}`;
    const discRow = document.getElementById('modalRowDiscount');
    if (discRow) {
        if (discountAmt > 0) {
            discRow.classList.remove('d-none');
            discRow.classList.add('d-flex');
            document.getElementById('modalLblDiscountAmt').textContent = `-₹${discountAmt.toFixed(2)}` + (discType === 'FLAT' ? ` (${effectivePercent.toFixed(1)}%)` : '');
        } else {
            discRow.classList.add('d-none');
            discRow.classList.remove('d-flex');
        }
    }
    document.getElementById('modalLblGst').textContent = `₹${totalGst.toFixed(2)}`;
    document.getElementById('modalLblGrandTotal').textContent = `₹${grandTotal.toFixed(2)}`;
}

function onModalPaymentModeChange(mode) {
    const notice = document.getElementById('modalCreditNotice');
    if (mode === 'CREDIT') {
        notice.classList.remove('d-none');
    } else {
        notice.classList.add('d-none');
    }
}

function previewCurrentBill(format = 'standard') {
    if (cart.length === 0) {
        alert('Your cart is empty. Please select at least one medication.');
        return;
    }

    const patientName = document.getElementById('modalPatientName').textContent.trim() || 'IPD Patient';
    const patientUhid = document.getElementById('modalPatientUhid').textContent.trim() || 'VH-2026-0001';
    const wardBed = document.getElementById('modalWardBed').textContent.trim() || 'General Ward / Bed-01';
    const doctorName = document.getElementById('modalDoctorInput').value.trim() || 'DR. VAISHALI LONDHE';
    const subtotal = parseFloat(document.getElementById('modalLblSubtotal').textContent.replace('₹', '')) || 0;
    const discType = document.getElementById('modalDiscountType') ? document.getElementById('modalDiscountType').value : 'PERCENT';
    const discVal = parseFloat(document.getElementById('modalDiscountValue')?.value) || 0.0;
    const discountPercent = discType === 'FLAT' ? (subtotal > 0 ? (discVal / subtotal) * 100 : 0) : discVal;
    const discountAmt = discType === 'FLAT' ? Math.min(subtotal, discVal) : (subtotal * (discountPercent / 100));
    const gstAmt = parseFloat(document.getElementById('modalLblGst').textContent.replace('₹', '')) || 0;
    const grandTotal = parseFloat(document.getElementById('modalLblGrandTotal').textContent.replace('₹', '')) || 0;
    const paymentMode = document.getElementById('modalPaymentMode').value;
    const billDate = new Date().toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }).replace(/ /g, '-');
    const billDateTime = new Date().toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric' }) + ' : ' + new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });

    let itemsHtml = '';
    cart.forEach((it, idx) => {
        const qty = it.quantity || 1;
        const price = it.price || 0;
        const netAmt = (qty * price).toFixed(2);
        let expFormatted = '--/--';
        if (it.expiry_date) {
            const parts = it.expiry_date.split('-');
            if (parts.length >= 2) {
                expFormatted = parts[1] + '/' + (parts[0].length === 4 ? parts[0].substring(2) : parts[0]);
            } else {
                expFormatted = it.expiry_date;
            }
        }

        itemsHtml += `
            <tr style="border-bottom: 1px dashed #ccc;">
                <td style="padding: 7px 4px; text-align: left;">${idx + 1}</td>
                <td style="padding: 7px 4px; text-align: left;">${billDate}</td>
                <td style="padding: 7px 4px; text-align: left;"><strong>${it.medicine_name.toUpperCase()}</strong></td>
                <td style="padding: 7px 4px; text-align: center; font-family: monospace;">${expFormatted}</td>
                <td style="padding: 7px 4px; text-align: center;">${qty}</td>
                <td style="padding: 7px 4px; text-align: right;">${price.toFixed(2)}</td>
                <td style="padding: 7px 4px; text-align: right;"><strong>${netAmt}</strong></td>
            </tr>
        `;
    });

    previewDoc = `
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Bill - ${patientName}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: "Segoe UI", Arial, sans-serif; font-size: 13px; line-height: 1.5; color: #111; background: #525659; min-height: 100vh; display: flex; flex-direction: column; align-items: center; padding: 20px 10px; }
        .no-print-bar { width: 100%; max-width: 800px; margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; background: #fff; padding: 10px 18px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); font-family: Arial, sans-serif; }
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 16px; font-size: 12.5px; font-weight: 600; border-radius: 6px; cursor: pointer; border: none; }
        .btn-primary { background: #0d9488; color: #fff; }
        .btn-secondary { background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; }
        .bill-sheet { background: #fff; width: 100%; max-width: 800px; min-height: 480px; padding: 32px 36px; box-shadow: 0 8px 24px rgba(0,0,0,0.25); border-radius: 4px; }
        .bill-header-line { font-size: 17px; font-weight: 800; text-align: center; letter-spacing: 0.5px; padding-bottom: 10px; border-bottom: 2px solid #000; margin-bottom: 14px; }
        .patient-line { display: flex; justify-content: space-between; font-size: 13.5px; font-weight: 700; margin-bottom: 16px; }
        .med-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 12.5px; }
        .med-table th { border-bottom: 2px solid #000; border-top: 1.5px solid #000; padding: 8px 4px; text-align: left; font-weight: 700; }
        .total-block { display: flex; justify-content: flex-end; padding-top: 12px; border-top: 2px solid #000; font-size: 15.5px; font-weight: 800; }
        @media print {
            @page { size: A4 portrait; margin: 10mm; }
            body { background: #fff !important; padding: 0 !important; }
            .no-print-bar { display: none !important; }
            .bill-sheet { box-shadow: none !important; padding: 0 !important; width: 100% !important; }
        }
    </style>
</head>
<body>
    <div class="no-print-bar">
        <div style="font-weight:700; display:flex; align-items:center; gap:8px;"><i class="bi bi-receipt text-success"></i> Inpatient Bill Preview</div>
        <div style="display:flex; gap:8px;">
            <button class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print Bill</button>
            <button class="btn btn-secondary" onclick="window.close()"><i class="bi bi-x-lg"></i> Close</button>
        </div>
    </div>
    <div class="bill-sheet">
        <div class="bill-header-line">Vatsalya &nbsp;&nbsp;&nbsp;&nbsp; GSTIN: 27AAQFV6256M1Z8</div>
        <div class="patient-line">
            <div><strong>Patient Name:</strong> ${patientName.toUpperCase()}</div>
            <div><strong>Date:</strong> ${billDate}</div>
        </div>
        <table class="med-table">
            <thead>
                <tr>
                    <th style="width: 5%;">#</th>
                    <th style="width: 14%;">Date</th>
                    <th style="width: 43%;">Medicine Name</th>
                    <th style="width: 14%; text-align: center;">Expiry Date</th>
                    <th style="width: 7%; text-align: center;">Qty</th>
                    <th style="width: 8%; text-align: right;">Price</th>
                    <th style="width: 9%; text-align: right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                ${itemsHtml}
            </tbody>
        </table>
        <div class="total-block">
            <div style="display: flex; width: 240px; justify-content: space-between;">
                <span>Total:</span>
                <span>₹${grandTotal.toFixed(2)}</span>
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

function submitFinalSale() {
    if (!cart || cart.length === 0) {
        alert('Your cart is empty. Please select at least one medication item.');
        return;
    }

    const grandTotal = parseFloat(document.getElementById('modalLblGrandTotal').textContent.replace('₹', '')) || 0;
    const discountType = document.getElementById('modalDiscountType') ? document.getElementById('modalDiscountType').value : 'PERCENT';
    const discountVal = parseFloat(document.getElementById('modalDiscountValue')?.value) || 0.0;
    const subtotal = parseFloat(document.getElementById('modalLblSubtotal').textContent.replace('₹', '')) || 0.0;
    let discountPercent = 0.0;

    if (discountType === 'FLAT') {
        discountPercent = subtotal > 0 ? Math.min(50.0, (discountVal / subtotal) * 100.0) : 0.0;
    } else {
        discountPercent = Math.min(50.0, discountVal);
    }

    const paymentMode = document.getElementById('modalPaymentMode').value;
    const notes = document.getElementById('modalNotesInput').value.trim();

    // Populate cart items JSON
    document.getElementById('cartItemsJson').value = JSON.stringify(cart);
    document.getElementById('hiddenPaidAmount').value = paymentMode === 'CREDIT' ? 0.0 : grandTotal;
    document.getElementById('hiddenDiscountType').value = discountType;
    document.getElementById('hiddenDiscountValue').value = discountVal;
    document.getElementById('hiddenDiscountPercent').value = discountPercent.toFixed(4);
    document.getElementById('hiddenPaymentMode').value = paymentMode;
    document.getElementById('hiddenNotes').value = notes;

    const btn = document.getElementById('modalBtnConfirmSale');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Processing Dispense...';

    document.getElementById('ipdSaleForm').submit();
}

document.addEventListener('DOMContentLoaded', function() {
    renderAdmittedPatientsTable(ipdPatientsList);

    // Close suggestions lists on click outside
    document.addEventListener('click', function(e) {
        const pList = document.getElementById('patientSuggestionsList');
        const pInput = document.getElementById('ipdPatientSearchInput');
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
            const searchSection = document.getElementById('patientSearchSection');
            if (searchSection && searchSection.classList.contains('d-none')) {
                clearSelectedPatient();
            } else {
                const pInput = document.getElementById('ipdPatientSearchInput');
                if (pInput) {
                    pInput.focus();
                    pInput.select();
                    onPatientSearchFocus();
                }
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

    // Start with focus directly on Patient search input for seamless keyboard workflow
    setTimeout(function() {
        const pInput = document.getElementById('ipdPatientSearchInput');
        if (pInput) {
            pInput.focus();
            pInput.select();
        }
    }, 150);
});
</script>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
