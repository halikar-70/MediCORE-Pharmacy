<?php
// database/seed_demo_data.php - Comprehensive Hospital Pharmacy Test Data Generator

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

echo "============================================================\n";
echo " VATSALYA HOSPITAL PHARMACY — SEED DEMO DATASET\n";
echo "============================================================\n\n";

try {
    $pdo->beginTransaction();

    $today = date('Y-m-d');
    $adminUserId = 1;

    // 1. SUPPLIERS
    echo "[1/7] Seeding Suppliers...\n";
    $suppliers = [
        [
            'code'     => 'SUP-001',
            'name'     => 'Sun Pharma Distributors',
            'gstin'    => '27AAACS1234A1Z5',
            'phone'    => '9820012345',
            'email'    => 'orders@sunpharma-dist.com',
            'city'     => 'Mumbai',
            'state'    => 'Maharashtra',
            'terms'    => '30 Days Net',
            'dl_no'    => 'MH-TZ-123456'
        ],
        [
            'code'     => 'SUP-002',
            'name'     => 'Cipla Healthcare Logistics',
            'gstin'    => '27AABCC5678B1Z2',
            'phone'    => '9820054321',
            'email'    => 'supply@ciplalogistics.com',
            'city'     => 'Pune',
            'state'    => 'Maharashtra',
            'terms'    => '15 Days Net',
            'dl_no'    => 'MH-PUN-789012'
        ],
        [
            'code'     => 'SUP-003',
            'name'     => 'Mankind MediSupply Agency',
            'gstin'    => '27AABCM9012C1Z9',
            'phone'    => '9820098765',
            'email'    => 'contact@mankindagency.in',
            'city'     => 'Nagpur',
            'state'    => 'Maharashtra',
            'terms'    => 'Immediate / COD',
            'dl_no'    => 'MH-NGP-345678'
        ]
    ];

    $supIds = [];
    $supStmt = $pdo->prepare("
        INSERT INTO pharmacy_suppliers (supplier_code, supplier_name, gstin, phone, email, city, state, payment_terms, drug_licence_no, status, created_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE supplier_id = LAST_INSERT_ID(supplier_id), supplier_name = VALUES(supplier_name)
    ");
    foreach ($suppliers as $s) {
        $supStmt->execute([$s['code'], $s['name'], $s['gstin'], $s['phone'], $s['email'], $s['city'], $s['state'], $s['terms'], $s['dl_no'], $adminUserId]);
        $supIds[$s['code']] = (int)$pdo->lastInsertId();
    }
    echo "  ✓ Seeded " . count($suppliers) . " suppliers.\n";

    // 2. MEDICINES & BATCHES
    echo "[2/7] Seeding Medicines and Available Stock Batches...\n";
    $catalog = [
        [
            'name'          => 'Dolo 650mg Tablet',
            'generic'       => 'Paracetamol',
            'composition'   => 'Paracetamol 650mg',
            'dosage_form'   => 'Tablet',
            'pack_size'     => '15 Tablets',
            'category'      => 'Antipyretic / Analgesic',
            'rack'          => 'A1-01',
            'barcode'       => '890100100001',
            'gst'           => 12.00,
            'mrp'           => 33.00,
            'purchase'      => 20.00,
            'stock'         => 450,
            'batch_no'      => 'DLO-2401',
            'expiry'        => '2027-12-31',
            'sup_code'      => 'SUP-001'
        ],
        [
            'name'          => 'Augmentin 625 Duo Tablet',
            'generic'       => 'Amoxicillin and Potassium Clavulanate',
            'composition'   => 'Amoxicillin 500mg + Clavulanic Acid 125mg',
            'dosage_form'   => 'Tablet',
            'pack_size'     => '10 Tablets',
            'category'      => 'Antibiotic',
            'rack'          => 'A2-05',
            'barcode'       => '890100100002',
            'gst'           => 12.00,
            'mrp'           => 220.00,
            'purchase'      => 155.00,
            'stock'         => 280,
            'batch_no'      => 'AUG-9912',
            'expiry'        => '2027-08-30',
            'sup_code'      => 'SUP-001'
        ],
        [
            'name'          => 'Pan 40 Tablet',
            'generic'       => 'Pantoprazole',
            'composition'   => 'Pantoprazole Gastro-resistant 40mg',
            'dosage_form'   => 'Tablet',
            'pack_size'     => '15 Tablets',
            'category'      => 'Antacid / PPI',
            'rack'          => 'B1-03',
            'barcode'       => '890100100003',
            'gst'           => 12.00,
            'mrp'           => 155.00,
            'purchase'      => 95.00,
            'stock'         => 350,
            'batch_no'      => 'PAN-8821',
            'expiry'        => '2027-10-15',
            'sup_code'      => 'SUP-002'
        ],
        [
            'name'          => 'Azee 500 Tablet',
            'generic'       => 'Azithromycin',
            'composition'   => 'Azithromycin 500mg',
            'dosage_form'   => 'Tablet',
            'pack_size'     => '5 Tablets',
            'category'      => 'Antibiotic',
            'rack'          => 'B2-02',
            'barcode'       => '890100100004',
            'gst'           => 12.00,
            'mrp'           => 130.00,
            'purchase'      => 85.00,
            'stock'         => 220,
            'batch_no'      => 'AZ-5542',
            'expiry'        => '2027-05-20',
            'sup_code'      => 'SUP-002'
        ],
        [
            'name'          => 'Ascoril-D Plus Syrup 100ml',
            'generic'       => 'Dextromethorphan + Phenylephrine',
            'composition'   => 'Dextromethorphan HBr 10mg + Phenylephrine 5mg',
            'dosage_form'   => 'Syrup',
            'pack_size'     => '100 ml Bottle',
            'category'      => 'Cough Syrup',
            'rack'          => 'C1-04',
            'barcode'       => '890100100005',
            'gst'           => 12.00,
            'mrp'           => 125.00,
            'purchase'      => 80.00,
            'stock'         => 140,
            'batch_no'      => 'ASC-1102',
            'expiry'        => '2027-04-10',
            'sup_code'      => 'SUP-003'
        ],
        [
            'name'          => 'Monocef 1g Injection',
            'generic'       => 'Ceftriaxone Sodium',
            'composition'   => 'Ceftriaxone 1000mg with sterile water',
            'dosage_form'   => 'Injection',
            'pack_size'     => '1 Vial',
            'category'      => 'Antibiotic Injection',
            'rack'          => 'INJ-01',
            'barcode'       => '890100100006',
            'gst'           => 12.00,
            'mrp'           => 65.00,
            'purchase'      => 42.00,
            'stock'         => 180,
            'batch_no'      => 'MON-3321',
            'expiry'        => '2027-09-15',
            'sup_code'      => 'SUP-001'
        ],
        [
            'name'          => 'Normal Saline 0.9% IV 500ml',
            'generic'       => 'Sodium Chloride IV Infusion',
            'composition'   => 'Sodium Chloride 0.9% w/v',
            'dosage_form'   => 'IV Infusion',
            'pack_size'     => '500 ml Bottle',
            'category'      => 'IV Fluid',
            'rack'          => 'IV-02',
            'barcode'       => '890100100007',
            'gst'           => 12.00,
            'mrp'           => 48.00,
            'purchase'      => 28.00,
            'stock'         => 230,
            'batch_no'      => 'NS-7730',
            'expiry'        => '2027-11-30',
            'sup_code'      => 'SUP-002'
        ],
        [
            'name'          => 'Lantus Solostar 100IU/ml',
            'generic'       => 'Insulin Glargine',
            'composition'   => 'Insulin Glargine rDNA origin 100IU/ml',
            'dosage_form'   => 'Pre-filled Pen',
            'pack_size'     => '3 ml Pen',
            'category'      => 'Antidiabetic / Insulin',
            'rack'          => 'FRIDGE-1',
            'barcode'       => '890100100008',
            'gst'           => 5.00,
            'mrp'           => 680.00,
            'purchase'      => 510.00,
            'stock'         => 55,
            'batch_no'      => 'LAN-4419',
            'expiry'        => '2027-03-31',
            'sup_code'      => 'SUP-001'
        ]
    ];

    $medIds = [];
    $batchIds = [];

    $medStmt = $pdo->prepare("
        INSERT INTO medicines (medicine_name, generic_name, composition, dosage_form, pack_size, category, rack_location, barcode, gst_percent, price, purchase_price, stock_quantity, reorder_level, status, created_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 20, 'Active', ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE medicine_id = LAST_INSERT_ID(medicine_id), stock_quantity = VALUES(stock_quantity), price = VALUES(price)
    ");

    $batchStmt = $pdo->prepare("
        INSERT INTO medicine_batches (medicine_id, batch_number, manufacturing_date, expiry_date, purchase_price, mrp, sale_price, quantity_received, quantity_available, supplier_id, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', NOW(), NOW())
        ON DUPLICATE KEY UPDATE batch_id = LAST_INSERT_ID(batch_id), quantity_available = VALUES(quantity_available)
    ");

    $ledgerStmt = $pdo->prepare("
        INSERT INTO pharmacy_stock_ledger (medicine_id, batch_id, transaction_type, reference_type, reference_no, quantity_change, balance_before, balance_after, unit_cost, unit_price, reason, created_by, created_at)
        VALUES (?, ?, 'OPENING_STOCK', 'INITIAL_MIGRATION', 'INIT-2026', ?, 0, ?, ?, ?, 'Initial inventory stock load', ?, NOW())
    ");

    foreach ($catalog as $item) {
        $medStmt->execute([
            $item['name'], $item['generic'], $item['composition'], $item['dosage_form'], $item['pack_size'],
            $item['category'], $item['rack'], $item['barcode'], $item['gst'], $item['mrp'], $item['purchase'],
            $item['stock'], $adminUserId
        ]);
        $medId = (int)$pdo->lastInsertId();
        $medIds[$item['barcode']] = $medId;

        $supId = $supIds[$item['sup_code']] ?? null;
        $batchStmt->execute([
            $medId, $item['batch_no'], date('Y-m-01', strtotime('-3 months')), $item['expiry'],
            $item['purchase'], $item['mrp'], $item['mrp'], $item['stock'] + 50, $item['stock'],
            $supId
        ]);
        $batchId = (int)$pdo->lastInsertId();
        $batchIds[$medId] = [
            'batch_id'     => $batchId,
            'batch_number' => $item['batch_no'],
            'expiry_date'  => $item['expiry'],
            'mrp'          => $item['mrp'],
            'purchase'     => $item['purchase'],
            'gst'          => $item['gst']
        ];

        // Opening Stock Ledger Record
        $ledgerStmt->execute([
            $medId, $batchId, $item['stock'], $item['stock'], $item['purchase'], $item['mrp'], $adminUserId
        ]);
    }
    echo "  ✓ Seeded " . count($catalog) . " medicines with batches and stock ledger balances.\n";

    // 3. PATIENTS
    echo "[3/7] Seeding Patients (OPD & IPD)...\n";
    $patients = [
        [
            'no'     => 'PP-000001',
            'uhid'   => 'VH-2026-0001',
            'name'   => 'Ramesh Kumar Sharma',
            'mobile' => '9876501234',
            'gender' => 'Male',
            'dob'    => '1981-04-12',
            'city'   => 'Mumbai'
        ],
        [
            'no'     => 'PP-000002',
            'uhid'   => 'VH-2026-0002',
            'name'   => 'Priya Patel',
            'mobile' => '9876505678',
            'gender' => 'Female',
            'dob'    => '1994-09-25',
            'city'   => 'Thane'
        ],
        [
            'no'     => 'PP-000003',
            'uhid'   => 'VH-2026-0003',
            'name'   => 'Rajesh Verma',
            'mobile' => '9876509988',
            'gender' => 'Male',
            'dob'    => '1968-11-04',
            'city'   => 'Navi Mumbai'
        ],
        [
            'no'     => 'PP-000004',
            'uhid'   => 'VH-2026-0004',
            'name'   => 'Sunita Devi',
            'mobile' => '9876507744',
            'gender' => 'Female',
            'dob'    => '1964-02-18',
            'city'   => 'Kalyan'
        ]
    ];

    $patientIds = [];
    $patStmt = $pdo->prepare("
        INSERT INTO pharmacy_patients (pharmacy_patient_no, hospital_uhid, name, mobile, gender, date_of_birth, city, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'Active', NOW(), NOW())
        ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), name = VALUES(name)
    ");
    foreach ($patients as $p) {
        $patStmt->execute([$p['no'], $p['uhid'], $p['name'], $p['mobile'], $p['gender'], $p['dob'], $p['city']]);
        $patientIds[$p['uhid']] = (int)$pdo->lastInsertId();
    }
    echo "  ✓ Seeded " . count($patients) . " patient records.\n";

    // 4. PRESCRIPTIONS (OPD & IPD)
    echo "[4/7] Seeding Doctor Prescriptions...\n";
    $prescStmt = $pdo->prepare("
        INSERT INTO pharmacy_prescriptions (prescription_number, prescription_date, patient_type, patient_id, patient_name, patient_mobile, doctor_name, department, ipd_admission_no, ward, bed_number, status, notes, created_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE prescription_id = LAST_INSERT_ID(prescription_id)
    ");
    $pItemStmt = $pdo->prepare("
        INSERT INTO pharmacy_prescription_items (prescription_id, medicine_id, dose, dose_unit, route, frequency, prescribed_qty, dispensed_qty, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 0, 'ACTIVE', NOW(), NOW())
    ");

    // OPD Prescription: Ramesh Kumar Sharma
    $prescStmt->execute([
        'RX-2026-001', $today, 'OPD', $patientIds['VH-2026-0001'], 'Ramesh Kumar Sharma', '9876501234',
        'Dr. A. K. Mishra', 'Internal Medicine', null, null, null, 'PENDING', 'Fever and dyspepsia management', $adminUserId
    ]);
    $rxOpdId = (int)$pdo->lastInsertId();
    $pItemStmt->execute([$rxOpdId, $medIds['890100100001'], 650.00, 'mg', 'ORAL', 'TDS (1-1-1)', 15]);
    $pItemStmt->execute([$rxOpdId, $medIds['890100100003'], 40.00, 'mg', 'ORAL', 'OD (1-0-0) AC', 15]);

    // IPD Prescription: Rajesh Verma
    $prescStmt->execute([
        'RX-2026-002', $today, 'IPD', $patientIds['VH-2026-0003'], 'Rajesh Verma', '9876509988',
        'Dr. Sneha Roy', 'Pulmonology / Critical Care', 'IPD-2026-101', 'Male Medical Ward', 'MMW-04', 'ACTIVE', 'Severe chest infection', $adminUserId
    ]);
    $rxIpdId = (int)$pdo->lastInsertId();
    $pItemStmt->execute([$rxIpdId, $medIds['890100100006'], 1000.00, 'mg', 'IV', 'BD (1-0-1)', 4]);
    $pItemStmt->execute([$rxIpdId, $medIds['890100100007'], 500.00, 'ml', 'IV', 'OD Slow Infusion', 2]);

    echo "  ✓ Seeded active OPD & IPD doctor prescriptions.\n";

    // 5. WARD INDENTS
    echo "[5/7] Seeding Inpatient Ward Indents...\n";
    $indentStmt = $pdo->prepare("
        INSERT INTO pharmacy_indents (indent_number, indent_date, ward, bed_number, patient_id, patient_name, ipd_admission_no, requested_by, priority, status, notes, created_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE indent_id = LAST_INSERT_ID(indent_id)
    ");
    $indentItemStmt = $pdo->prepare("
        INSERT INTO pharmacy_indent_items (indent_id, medicine_id, requested_qty, approved_qty, dispensed_qty, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, 0, 'PENDING', NOW(), NOW())
    ");

    $indentStmt->execute([
        'IND-2026-001', $today, 'ICU', 'ICU-02', $patientIds['VH-2026-0004'], 'Sunita Devi', 'IPD-2026-102',
        'Staff Nurse Neha', 'URGENT', 'SUBMITTED', 'Urgent replacement for ICU bed 02', $adminUserId
    ]);
    $indentId = (int)$pdo->lastInsertId();
    $indentItemStmt->execute([$indentId, $medIds['890100100007'], 4, 4]);
    $indentItemStmt->execute([$indentId, $medIds['890100100006'], 2, 2]);

    echo "  ✓ Seeded pending ICU Ward Indent IND-2026-001.\n";

    // 6. SALES TRANSACTIONS (OPD & IPD)
    echo "[6/7] Seeding Real-Time Sales Invoices (OPD & IPD)...\n";
    
    $saleStmt = $pdo->prepare("
        INSERT INTO pharmacy_sales (sale_number, sale_type, sale_date, patient_type, patient_id, customer_name, customer_mobile, doctor_name, prescription_id, ipd_admission_id, ipd_ward, ipd_bed, subtotal_amount, gst_amount, grand_total, paid_amount, balance_amount, payment_status, payment_mode, status, created_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'COMPLETED', ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE sale_id = LAST_INSERT_ID(sale_id)
    ");

    $sItemStmt = $pdo->prepare("
        INSERT INTO pharmacy_sale_items (sale_id, medicine_id, dosage_form, pack_size, quantity, unit_price, mrp, taxable_amount, gst_percent, gst_amount, line_total, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $sBatchStmt = $pdo->prepare("
        INSERT INTO pharmacy_sale_item_batches (sale_item_id, sale_id, medicine_id, batch_id, batch_number, expiry_date, allocated_quantity, unit_cost, unit_price, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $sPayStmt = $pdo->prepare("
        INSERT INTO pharmacy_sale_payments (payment_number, sale_id, payment_date, amount, payment_mode, reference_number, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    // --- OPD SALE 1: Ramesh Kumar Sharma ---
    // 1 Dolo 650 (₹33) + 1 Pan 40 (₹155) = ₹188.00 (CASH)
    $saleStmt->execute([
        'CS-2026-000001', 'COUNTER_SALE', $today, 'REGISTERED', $patientIds['VH-2026-0001'],
        'Ramesh Kumar Sharma', '9876501234', 'Dr. A. K. Mishra', $rxOpdId, null, null, null,
        167.86, 20.14, 188.00, 188.00, 0.00, 'PAID', 'CASH', $adminUserId
    ]);
    $sId1 = (int)$pdo->lastInsertId();

    $mId = $medIds['890100100001'];
    $b = $batchIds[$mId];
    $sItemStmt->execute([$sId1, $mId, 'Tablet', '15 Tablets', 1, 33.00, 33.00, 29.46, 12.00, 3.54, 33.00]);
    $siId = (int)$pdo->lastInsertId();
    $sBatchStmt->execute([$siId, $sId1, $mId, $b['batch_id'], $b['batch_number'], $b['expiry_date'], 1, $b['purchase'], 33.00]);

    $mId = $medIds['890100100003'];
    $b = $batchIds[$mId];
    $sItemStmt->execute([$sId1, $mId, 'Tablet', '15 Tablets', 1, 155.00, 155.00, 138.39, 12.00, 16.61, 155.00]);
    $siId = (int)$pdo->lastInsertId();
    $sBatchStmt->execute([$siId, $sId1, $mId, $b['batch_id'], $b['batch_number'], $b['expiry_date'], 1, $b['purchase'], 155.00]);

    $sPayStmt->execute(['PAY-CS-001', $sId1, $today, 188.00, 'CASH', null, $adminUserId]);

    // --- OPD SALE 2: Priya Patel ---
    // 1 Augmentin 625 (₹220) + 1 Ascoril-D (₹125) = ₹345.00 (UPI)
    $saleStmt->execute([
        'CS-2026-000002', 'COUNTER_SALE', $today, 'REGISTERED', $patientIds['VH-2026-0002'],
        'Priya Patel', '9876505678', 'Dr. Sneha Roy', null, null, null, null,
        308.04, 36.96, 345.00, 345.00, 0.00, 'PAID', 'UPI', $adminUserId
    ]);
    $sId2 = (int)$pdo->lastInsertId();

    $mId = $medIds['890100100002'];
    $b = $batchIds[$mId];
    $sItemStmt->execute([$sId2, $mId, 'Tablet', '10 Tablets', 1, 220.00, 220.00, 196.43, 12.00, 23.57, 220.00]);
    $siId = (int)$pdo->lastInsertId();
    $sBatchStmt->execute([$siId, $sId2, $mId, $b['batch_id'], $b['batch_number'], $b['expiry_date'], 1, $b['purchase'], 220.00]);

    $mId = $medIds['890100100005'];
    $b = $batchIds[$mId];
    $sItemStmt->execute([$sId2, $mId, 'Syrup', '100 ml Bottle', 1, 125.00, 125.00, 111.61, 12.00, 13.39, 125.00]);
    $siId = (int)$pdo->lastInsertId();
    $sBatchStmt->execute([$siId, $sId2, $mId, $b['batch_id'], $b['batch_number'], $b['expiry_date'], 1, $b['purchase'], 125.00]);

    $sPayStmt->execute(['PAY-CS-002', $sId2, $today, 345.00, 'UPI', 'UPI-REF-889021', $adminUserId]);

    // --- OPD SALE 3: Walk-in Customer ---
    // 1 Dolo 650 (₹33) = ₹33.00 (CASH)
    $saleStmt->execute([
        'CS-2026-000003', 'COUNTER_SALE', $today, 'WALK_IN', null,
        'Walk-in Customer (Suresh)', '9811223344', null, null, null, null, null,
        29.46, 3.54, 33.00, 33.00, 0.00, 'PAID', 'CASH', $adminUserId
    ]);
    $sId3 = (int)$pdo->lastInsertId();

    $mId = $medIds['890100100001'];
    $b = $batchIds[$mId];
    $sItemStmt->execute([$sId3, $mId, 'Tablet', '15 Tablets', 1, 33.00, 33.00, 29.46, 12.00, 3.54, 33.00]);
    $siId = (int)$pdo->lastInsertId();
    $sBatchStmt->execute([$siId, $sId3, $mId, $b['batch_id'], $b['batch_number'], $b['expiry_date'], 1, $b['purchase'], 33.00]);

    $sPayStmt->execute(['PAY-CS-003', $sId3, $today, 33.00, 'CASH', null, $adminUserId]);

    // --- IPD SALE 1: Rajesh Verma (Male Medical Ward, Bed MMW-04) ---
    // 2 Monocef 1g (₹130) + 2 NS 500ml (₹96) + 1 Pan 40 (₹155) = ₹381.00 (CREDIT / UNPAID)
    $saleStmt->execute([
        'RS-2026-000001', 'IPD_SALE', $today, 'IPD', $patientIds['VH-2026-0003'],
        'Rajesh Verma', '9876509988', 'Dr. Sneha Roy', $rxIpdId, 'IPD-2026-101', 'Male Medical Ward', 'MMW-04',
        340.18, 40.82, 381.00, 0.00, 381.00, 'CREDIT', 'CREDIT', $adminUserId
    ]);
    $sId4 = (int)$pdo->lastInsertId();

    $mId = $medIds['890100100006'];
    $b = $batchIds[$mId];
    $sItemStmt->execute([$sId4, $mId, 'Injection', '1 Vial', 2, 65.00, 65.00, 116.07, 12.00, 13.93, 130.00]);
    $siId = (int)$pdo->lastInsertId();
    $sBatchStmt->execute([$siId, $sId4, $mId, $b['batch_id'], $b['batch_number'], $b['expiry_date'], 2, $b['purchase'], 65.00]);

    $mId = $medIds['890100100007'];
    $b = $batchIds[$mId];
    $sItemStmt->execute([$sId4, $mId, 'IV Infusion', '500 ml Bottle', 2, 48.00, 48.00, 85.71, 12.00, 10.29, 96.00]);
    $siId = (int)$pdo->lastInsertId();
    $sBatchStmt->execute([$siId, $sId4, $mId, $b['batch_id'], $b['batch_number'], $b['expiry_date'], 2, $b['purchase'], 48.00]);

    $mId = $medIds['890100100003'];
    $b = $batchIds[$mId];
    $sItemStmt->execute([$sId4, $mId, 'Tablet', '15 Tablets', 1, 155.00, 155.00, 138.39, 12.00, 16.61, 155.00]);
    $siId = (int)$pdo->lastInsertId();
    $sBatchStmt->execute([$siId, $sId4, $mId, $b['batch_id'], $b['batch_number'], $b['expiry_date'], 1, $b['purchase'], 155.00]);

    // --- IPD SALE 2: Sunita Devi (ICU, Bed ICU-02) ---
    // 1 Lantus Solostar (₹680) + 2 NS 500ml (₹96) = ₹776.00 (CREDIT / UNPAID)
    $saleStmt->execute([
        'RS-2026-000002', 'IPD_SALE', $today, 'IPD', $patientIds['VH-2026-0004'],
        'Sunita Devi', '9876507744', 'Dr. Sneha Roy', null, 'IPD-2026-102', 'ICU', 'ICU-02',
        733.38, 42.62, 776.00, 0.00, 776.00, 'CREDIT', 'CREDIT', $adminUserId
    ]);
    $sId5 = (int)$pdo->lastInsertId();

    $mId = $medIds['890100100008'];
    $b = $batchIds[$mId];
    $sItemStmt->execute([$sId5, $mId, 'Pre-filled Pen', '3 ml Pen', 1, 680.00, 680.00, 647.62, 5.00, 32.38, 680.00]);
    $siId = (int)$pdo->lastInsertId();
    $sBatchStmt->execute([$siId, $sId5, $mId, $b['batch_id'], $b['batch_number'], $b['expiry_date'], 1, $b['purchase'], 680.00]);

    $mId = $medIds['890100100007'];
    $b = $batchIds[$mId];
    $sItemStmt->execute([$sId5, $mId, 'IV Infusion', '500 ml Bottle', 2, 48.00, 48.00, 85.71, 12.00, 10.29, 96.00]);
    $siId = (int)$pdo->lastInsertId();
    $sBatchStmt->execute([$siId, $sId5, $mId, $b['batch_id'], $b['batch_number'], $b['expiry_date'], 2, $b['purchase'], 48.00]);

    echo "  ✓ Seeded 3 OPD Sales (Total: ₹566.00) & 2 IPD Sales (Total: ₹1,157.00).\n";

    // 7. PROCUREMENT INVOICE
    echo "[7/7] Seeding Procurement Invoice...\n";
    $poStmt = $pdo->prepare("
        INSERT INTO pharmacy_purchase_orders (po_number, po_date, supplier_id, status, subtotal_amount, tax_amount, total_amount, created_by, created_at, updated_at)
        VALUES (?, ?, ?, 'FULLY_RECEIVED', 12946.43, 1553.57, 14500.00, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE po_id = LAST_INSERT_ID(po_id)
    ");
    $poStmt->execute(['PO-2026-0001', $today, $supIds['SUP-001'], $adminUserId]);
    $poId = (int)$pdo->lastInsertId();

    $invStmt = $pdo->prepare("
        INSERT INTO pharmacy_purchase_invoices (invoice_number, supplier_invoice_no, supplier_id, po_id, invoice_date, taxable_amount, gst_amount, grand_total, amount_paid, outstanding_amount, payment_status, match_status, created_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, 12946.43, 1553.57, 14500.00, 14500.00, 0.00, 'PAID', 'MATCHED', ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE invoice_id = LAST_INSERT_ID(invoice_id)
    ");
    $invStmt->execute(['PINV-2026-0001', 'INV-SUN-8812', $supIds['SUP-001'], $poId, $today, $adminUserId]);

    $pdo->commit();

    echo "\n============================================================\n";
    echo " [SUCCESS] All Hospital Pharmacy Demo Data Seeded Successfully!\n";
    echo "============================================================\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "\n[ERROR] Failed to seed demo data: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
