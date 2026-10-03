<?php
// database/import_hospital_full_data.php - Comprehensive Hospital Data Import & Sync Utility

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../app/Database/Database.php';

use Pharmacy\Database\Database;

echo "====================================================================\n";
echo "   COMPREHENSIVE HOSPITAL DATA IMPORT & SYNCHRONIZATION UTILITY     \n";
echo "====================================================================\n\n";

try {
    $pharmacyPdo = Database::getPharmacyConnection();
    $hospitalPdo = Database::getHospitalConnection();

    if (!$hospitalPdo) {
        throw new Exception("Hospital DB is not reachable. Please ensure hospital_db exists in MySQL.");
    }

    // ---------------------------------------------------------
    // 1. SYNC PATIENTS (All 3,305+ Patients)
    // ---------------------------------------------------------
    echo "[1/5] Synchronizing Patient Directory from hospital_db...\n";
    $hPatients = $hospitalPdo->query("
        SELECT 
            patient_id as hospital_patient_id,
            COALESCE(patient_code, CONCAT('VH', patient_id)) as hospital_uhid,
            CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) as raw_name,
            COALESCE(phone, '') as mobile,
            COALESCE(gender, 'Other') as gender,
            COALESCE(address, '') as address,
            COALESCE(city, '') as city,
            dob,
            created_at
        FROM patients
        ORDER BY patient_id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo "  -> Found " . count($hPatients) . " patients in hospital_db.\n";

    $pharmPatients = $pharmacyPdo->query("SELECT id, hospital_patient_id, hospital_uhid FROM pharmacy_patients")->fetchAll(PDO::FETCH_ASSOC);
    $uhidToPharmId = [];
    $hIdToPharmId = [];
    foreach ($pharmPatients as $pp) {
        if (!empty($pp['hospital_uhid'])) {
            $uhidToPharmId[strtoupper(trim($pp['hospital_uhid']))] = (int)$pp['id'];
        }
        if (!empty($pp['hospital_patient_id'])) {
            $hIdToPharmId[(int)$pp['hospital_patient_id']] = (int)$pp['id'];
        }
    }

    $patInsertStmt = $pharmacyPdo->prepare("
        INSERT INTO pharmacy_patients 
        (pharmacy_patient_no, hospital_patient_id, hospital_uhid, name, mobile, gender, date_of_birth, address, city, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, NOW())
    ");

    $patUpdateStmt = $pharmacyPdo->prepare("
        UPDATE pharmacy_patients
        SET name = ?, mobile = ?, gender = ?, date_of_birth = ?, address = ?, city = ?, updated_at = NOW()
        WHERE id = ?
    ");

    $patientsInserted = 0;
    $patientsUpdated = 0;

    $pharmacyPdo->beginTransaction();

    foreach ($hPatients as $hp) {
        $uhid = strtoupper(trim($hp['hospital_uhid']));
        $hId = (int)$hp['hospital_patient_id'];
        $name = trim($hp['raw_name']);
        if ($name === '') $name = "Patient " . $uhid;
        $gender = in_array(ucfirst(strtolower($hp['gender'])), ['Male', 'Female']) ? ucfirst(strtolower($hp['gender'])) : 'Other';
        $dob = !empty($hp['dob']) && $hp['dob'] !== '0000-00-00' ? $hp['dob'] : null;
        $createdAt = !empty($hp['created_at']) ? $hp['created_at'] : date('Y-m-d H:i:s');

        $existingId = $uhidToPharmId[$uhid] ?? ($hIdToPharmId[$hId] ?? null);

        if ($existingId) {
            $patUpdateStmt->execute([
                $name,
                $hp['mobile'],
                $gender,
                $dob,
                $hp['address'],
                $hp['city'],
                $existingId
            ]);
            $patientsUpdated++;
        } else {
            $patientNo = 'PP-' . str_pad($hId, 5, '0', STR_PAD_LEFT);
            $patInsertStmt->execute([
                $patientNo,
                $hId,
                $uhid,
                $name,
                $hp['mobile'],
                $gender,
                $dob,
                $hp['address'],
                $hp['city'],
                $createdAt
            ]);
            $newId = (int)$pharmacyPdo->lastInsertId();
            $uhidToPharmId[$uhid] = $newId;
            $hIdToPharmId[$hId] = $newId;
            $patientsInserted++;
        }
    }

    $pharmacyPdo->commit();
    echo "  -> Patients Synced: {$patientsInserted} newly added, {$patientsUpdated} updated. Total: " . count($uhidToPharmId) . "\n\n";

    // ---------------------------------------------------------
    // 2. SYNC MEDICINES & BATCHES
    // ---------------------------------------------------------
    echo "[2/5] Synchronizing Medicines & Inventory Batches from hospital_db...\n";
    $hMeds = $hospitalPdo->query("SELECT * FROM medicines ORDER BY medicine_id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $hBatches = $hospitalPdo->query("SELECT * FROM medicine_batches ORDER BY batch_id ASC")->fetchAll(PDO::FETCH_ASSOC);

    echo "  -> Found " . count($hMeds) . " medicines and " . count($hBatches) . " batches in hospital_db.\n";

    $pharmMeds = $pharmacyPdo->query("SELECT medicine_id, medicine_name FROM medicines")->fetchAll(PDO::FETCH_ASSOC);
    $medNameToId = [];
    foreach ($pharmMeds as $pm) {
        $medNameToId[strtoupper(trim($pm['medicine_name']))] = (int)$pm['medicine_id'];
    }

    $medInsStmt = $pharmacyPdo->prepare("
        INSERT INTO medicines 
        (medicine_name, generic_name, brand_name, category, unit, pack_size, rack_location, schedule_type, manufacturer, hsn_code, gst_percent, price, purchase_price, stock_quantity, reorder_level, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");

    $medUpdStmt = $pharmacyPdo->prepare("
        UPDATE medicines
        SET generic_name = ?, brand_name = ?, category = ?, unit = ?, pack_size = ?, rack_location = ?, schedule_type = ?, manufacturer = ?, hsn_code = ?, gst_percent = ?, price = ?, purchase_price = ?, stock_quantity = ?, reorder_level = ?, status = ?, updated_at = NOW()
        WHERE medicine_id = ?
    ");

    $pharmacyPdo->beginTransaction();

    $medIdMap = []; // hospital medicine_id -> pharmacy medicine_id
    foreach ($hMeds as $hm) {
        $medName = trim($hm['medicine_name']);
        $medKey = strtoupper($medName);
        $status = in_array($hm['status'], ['Active', 'Discontinued']) ? $hm['status'] : 'Active';

        if (isset($medNameToId[$medKey])) {
            $pharmMedId = $medNameToId[$medKey];
            $medUpdStmt->execute([
                $hm['generic_name'] ?? '',
                $hm['brand_name'] ?? '',
                $hm['category'] ?? 'General',
                $hm['unit'] ?? 'Unit',
                $hm['pack_size'] ?? '1',
                $hm['rack_location'] ?? '',
                $hm['schedule_type'] ?? 'General',
                $hm['manufacturer'] ?? '',
                $hm['hsn_code'] ?? '',
                $hm['gst_percent'] ?? 0,
                $hm['price'] ?? 0,
                $hm['purchase_price'] ?? 0,
                $hm['stock_quantity'] ?? 0,
                $hm['reorder_level'] ?? 10,
                $status,
                $pharmMedId
            ]);
            $medIdMap[(int)$hm['medicine_id']] = $pharmMedId;
        } else {
            $medInsStmt->execute([
                $medName,
                $hm['generic_name'] ?? '',
                $hm['brand_name'] ?? '',
                $hm['category'] ?? 'General',
                $hm['unit'] ?? 'Unit',
                $hm['pack_size'] ?? '1',
                $hm['rack_location'] ?? '',
                $hm['schedule_type'] ?? 'General',
                $hm['manufacturer'] ?? '',
                $hm['hsn_code'] ?? '',
                $hm['gst_percent'] ?? 0,
                $hm['price'] ?? 0,
                $hm['purchase_price'] ?? 0,
                $hm['stock_quantity'] ?? 0,
                $hm['reorder_level'] ?? 10,
                $status
            ]);
            $pharmMedId = (int)$pharmacyPdo->lastInsertId();
            $medNameToId[$medKey] = $pharmMedId;
            $medIdMap[(int)$hm['medicine_id']] = $pharmMedId;
        }
    }

    // Sync Batches
    $batchCheckStmt = $pharmacyPdo->prepare("SELECT batch_id FROM medicine_batches WHERE medicine_id = ? AND batch_number = ?");
    $batchInsStmt = $pharmacyPdo->prepare("
        INSERT INTO medicine_batches 
        (medicine_id, batch_number, manufacturing_date, expiry_date, purchase_price, mrp, sale_price, quantity_received, quantity_available, shelf_location, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $batchUpdStmt = $pharmacyPdo->prepare("
        UPDATE medicine_batches
        SET expiry_date = ?, purchase_price = ?, mrp = ?, sale_price = ?, quantity_received = ?, quantity_available = ?, shelf_location = ?, status = ?, updated_at = NOW()
        WHERE batch_id = ?
    ");

    $batchesSynced = 0;
    foreach ($hBatches as $hb) {
        $pharmMedId = $medIdMap[(int)$hb['medicine_id']] ?? null;
        if (!$pharmMedId) continue;

        $batchNo = trim($hb['batch_number']);
        if (empty($batchNo)) continue;

        $expiry = !empty($hb['expiry_date']) ? $hb['expiry_date'] : date('Y-12-31', strtotime('+1 year'));
        $mfg = !empty($hb['manufacturing_date']) ? $hb['manufacturing_date'] : null;
        $status = in_array($hb['status'], ['Active', 'Near Expiry', 'Expired', 'Blocked', 'Depleted', 'Quarantined', 'Disposed']) ? $hb['status'] : 'Active';

        $batchCheckStmt->execute([$pharmMedId, $batchNo]);
        $existingBatchId = $batchCheckStmt->fetchColumn();

        if ($existingBatchId) {
            $batchUpdStmt->execute([
                $expiry,
                $hb['purchase_price'] ?? 0,
                $hb['mrp'] ?? 0,
                $hb['sale_price'] ?? 0,
                $hb['quantity_received'] ?? 0,
                $hb['quantity_available'] ?? 0,
                $hb['shelf_location'] ?? '',
                $status,
                $existingBatchId
            ]);
        } else {
            $batchInsStmt->execute([
                $pharmMedId,
                $batchNo,
                $mfg,
                $expiry,
                $hb['purchase_price'] ?? 0,
                $hb['mrp'] ?? 0,
                $hb['sale_price'] ?? 0,
                $hb['quantity_received'] ?? 0,
                $hb['quantity_available'] ?? 0,
                $hb['shelf_location'] ?? '',
                $status
            ]);
        }
        $batchesSynced++;
    }

    $pharmacyPdo->commit();
    echo "  -> Medicines Synced: " . count($medIdMap) . ", Batches Synced: {$batchesSynced}.\n\n";

    // ---------------------------------------------------------
    // 3. SYNC SUPPLIERS
    // ---------------------------------------------------------
    echo "[3/5] Synchronizing Pharmacy Suppliers from hospital_db...\n";
    $hSuppliers = $hospitalPdo->query("SELECT * FROM pharmacy_suppliers ORDER BY supplier_id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $supInsStmt = $pharmacyPdo->prepare("
        INSERT IGNORE INTO pharmacy_suppliers 
        (supplier_id, supplier_code, supplier_name, contact_person, phone, email, gstin, pan, payment_terms, address, city, state, pincode, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', NOW(), NOW())
    ");
    foreach ($hSuppliers as $hs) {
        $code = 'SUP-' . str_pad($hs['supplier_id'], 4, '0', STR_PAD_LEFT);
        $supInsStmt->execute([
            $hs['supplier_id'],
            $code,
            $hs['supplier_name'] ?? 'Supplier',
            $hs['contact_person'] ?? '',
            $hs['phone'] ?? '',
            $hs['email'] ?? '',
            $hs['gstin'] ?? '',
            $hs['pan'] ?? '',
            $hs['payment_terms'] ?? '30 Days Credit',
            $hs['address'] ?? '',
            'Nashik',
            'Maharashtra',
            ''
        ]);
    }
    echo "  -> Suppliers Synced: " . count($hSuppliers) . " records.\n\n";

    // ---------------------------------------------------------
    // 4. SYNC PHARMACY BILLS & SALES (From hospital_db bills & bill_items)
    // ---------------------------------------------------------
    echo "[4/5] Synchronizing Pharmacy Sales Bills from hospital_db...\n";

    // Fetch all bills with pharmacy items or pharmacy bill_type
    $hBills = $hospitalPdo->query("
        SELECT 
            b.bill_id,
            b.receipt_no,
            b.patient_id,
            b.visit_id,
            b.bill_type,
            b.total_amount,
            b.discount_amount,
            b.discount_percent,
            b.tax_amount,
            b.gst_percent,
            b.paid_amount,
            b.balance_amount,
            b.payment_status,
            b.payment_mode,
            b.bill_date,
            b.created_at,
            p.patient_code,
            CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, '')) as patient_name,
            p.phone as patient_phone
        FROM bills b
        JOIN patients p ON b.patient_id = p.patient_id
        WHERE b.bill_id IN (
            SELECT DISTINCT bill_id FROM bill_items WHERE charge_category = 'Pharmacy'
        ) OR b.bill_type = 'Pharmacy'
        ORDER BY b.bill_id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo "  -> Found " . count($hBills) . " pharmacy-related bills in hospital_db.\n";

    $saleCheckStmt = $pharmacyPdo->prepare("SELECT sale_id FROM pharmacy_sales WHERE sale_number = ?");
    $saleInsStmt = $pharmacyPdo->prepare("
        INSERT INTO pharmacy_sales 
        (sale_number, sale_type, sale_date, patient_type, patient_id, customer_name, customer_mobile, subtotal_amount, discount_percent, discount_amount, taxable_amount, gst_amount, round_off, grand_total, paid_amount, balance_amount, payment_status, payment_mode, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, ?, ?, ?, ?, ?, 'COMPLETED', ?, ?)
    ");

    $saleItemInsStmt = $pharmacyPdo->prepare("
        INSERT INTO pharmacy_sale_items 
        (sale_id, medicine_id, dosage_form, pack_size, quantity, unit_price, mrp, discount_percent, discount_amount, taxable_amount, gst_percent, gst_amount, line_total, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $salePayInsStmt = $pharmacyPdo->prepare("
        INSERT INTO pharmacy_sale_payments 
        (payment_number, sale_id, payment_date, amount, payment_mode, notes, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $billsImported = 0;
    $pharmacyPdo->beginTransaction();

    foreach ($hBills as $hb) {
        $saleNumber = !empty($hb['receipt_no']) ? $hb['receipt_no'] : ('BILL-' . str_pad($hb['bill_id'], 6, '0', STR_PAD_LEFT));
        $saleCheckStmt->execute([$saleNumber]);
        if ($saleCheckStmt->fetchColumn()) {
            continue; // already imported
        }

        $hPatId = (int)$hb['patient_id'];
        $pharmPatId = $hIdToPharmId[$hPatId] ?? null;
        $custName = trim($hb['patient_name']);
        if ($custName === '') $custName = 'Hospital Patient #' . $hPatId;
        $custPhone = $hb['patient_phone'] ?? '';

        $saleType = ($hb['bill_type'] === 'IPD') ? 'IPD_SALE' : 'COUNTER_SALE';
        $patType = ($hb['bill_type'] === 'IPD') ? 'IPD' : ($pharmPatId ? 'REGISTERED' : 'WALK_IN');
        $saleDate = !empty($hb['bill_date']) ? date('Y-m-d', strtotime($hb['bill_date'])) : date('Y-m-d');
        $createdAt = !empty($hb['created_at']) ? $hb['created_at'] : date('Y-m-d H:i:s');

        // Payment status normalization
        $pStatus = strtoupper($hb['payment_status'] ?? 'PAID');
        if ($pStatus === 'PAID') $normPStatus = 'PAID';
        elseif ($pStatus === 'PARTIAL' || $pStatus === 'PARTIALLY_PAID') $normPStatus = 'PARTIALLY_PAID';
        else $normPStatus = 'UNPAID';

        $pMode = strtoupper($hb['payment_mode'] ?? 'CASH');
        if (!in_array($pMode, ['CASH', 'UPI', 'CARD', 'BANK_TRANSFER', 'CHEQUE', 'CREDIT'])) {
            $pMode = 'CASH';
        }

        $grandTotal = (float)($hb['total_amount'] ?? 0);
        $paidAmount = (float)($hb['paid_amount'] ?? ($normPStatus === 'PAID' ? $grandTotal : 0));
        $balanceAmount = (float)($hb['balance_amount'] ?? ($grandTotal - $paidAmount));
        $discountAmount = (float)($hb['discount_amount'] ?? 0);
        $taxAmount = (float)($hb['tax_amount'] ?? 0);
        $subtotal = $grandTotal + $discountAmount - $taxAmount;

        $saleInsStmt->execute([
            $saleNumber,
            $saleType,
            $saleDate,
            $patType,
            $pharmPatId,
            $custName,
            $custPhone,
            $subtotal,
            (float)($hb['discount_percent'] ?? 0),
            $discountAmount,
            $subtotal,
            $taxAmount,
            $grandTotal,
            $paidAmount,
            $balanceAmount,
            $normPStatus,
            $pMode,
            $createdAt,
            $createdAt
        ]);
        $newSaleId = (int)$pharmacyPdo->lastInsertId();

        // Get bill items
        $bItems = $hospitalPdo->prepare("SELECT * FROM bill_items WHERE bill_id = ?");
        $bItems->execute([$hb['bill_id']]);
        $items = $bItems->fetchAll(PDO::FETCH_ASSOC);

        $defaultMedId = !empty($medNameToId) ? reset($medNameToId) : 1;

        foreach ($items as $it) {
            $itName = trim($it['item_name'] ?? 'Pharmacy Dispensed Item');
            $itMedId = $medNameToId[strtoupper($itName)] ?? $defaultMedId;
            $qty = (int)max(1, (float)($it['quantity'] ?? 1));
            $rate = (float)($it['rate'] ?? ($it['total_amount'] ?? 0));
            $lineTot = (float)($it['total_amount'] ?? ($qty * $rate));

            $saleItemInsStmt->execute([
                $newSaleId,
                $itMedId,
                'Tablet',
                '1 Strip',
                $qty,
                $rate,
                $rate,
                (float)($it['discount'] ?? 0),
                0.00,
                $lineTot,
                (float)($it['tax_percent'] ?? 0),
                (float)($it['tax_amount'] ?? 0),
                $lineTot,
                $createdAt
            ]);
        }

        // Add payment entry if paid
        if ($paidAmount > 0) {
            $payNum = 'PAY-' . str_pad($newSaleId, 6, '0', STR_PAD_LEFT);
            $salePayInsStmt->execute([
                $payNum,
                $newSaleId,
                $saleDate,
                $paidAmount,
                $pMode,
                'Hospital Bill Sync Payment Ref: ' . $saleNumber,
                $createdAt
            ]);
        }

        $billsImported++;
    }

    $pharmacyPdo->commit();
    echo "  -> Pharmacy Sales Bills Imported: {$billsImported} invoices.\n\n";

    // ---------------------------------------------------------
    // 5. SYNC PRESCRIPTIONS
    // ---------------------------------------------------------
    echo "[5/5] Synchronizing Prescriptions from hospital_db...\n";
    $hPrescriptions = $hospitalPdo->query("
        SELECT 
            p.*,
            CONCAT(COALESCE(pt.first_name, ''), ' ', COALESCE(pt.last_name, '')) as patient_name,
            pt.phone as patient_mobile,
            d.name as prescriber_doctor_name,
            d.registration_no as doctor_reg_no,
            d.specialization as doc_department
        FROM prescriptions p
        LEFT JOIN patients pt ON p.patient_id = pt.patient_id
        LEFT JOIN doctors d ON p.doctor_id = d.doctor_id
        ORDER BY p.prescription_id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo "  -> Found " . count($hPrescriptions) . " prescriptions in hospital_db.\n";

    $rxCheckStmt = $pharmacyPdo->prepare("SELECT prescription_id FROM pharmacy_prescriptions WHERE prescription_number = ?");
    $rxInsStmt = $pharmacyPdo->prepare("
        INSERT INTO pharmacy_prescriptions 
        (prescription_number, prescription_date, patient_type, patient_id, patient_name, patient_mobile, doctor_name, doctor_registration_no, department, status, notes, created_at, updated_at)
        VALUES (?, ?, 'OPD', ?, ?, ?, ?, ?, ?, 'VERIFIED', ?, ?, ?)
    ");

    $rxItemInsStmt = $pharmacyPdo->prepare("
        INSERT INTO pharmacy_prescription_items
        (prescription_id, medicine_id, dose, dose_unit, route, schedule, prescribed_qty, dispensed_qty, dosage_instructions, status, created_at, updated_at)
        VALUES (?, ?, ?, 'mg', 'ORAL', ?, ?, 0, ?, 'ACTIVE', ?, ?)
    ");

    $rxImported = 0;
    $pharmacyPdo->beginTransaction();

    foreach ($hPrescriptions as $hrx) {
        $rxNum = 'RX-' . str_pad($hrx['prescription_id'], 6, '0', STR_PAD_LEFT);
        $rxCheckStmt->execute([$rxNum]);
        if ($rxCheckStmt->fetchColumn()) {
            continue;
        }

        $hPatId = (int)$hrx['patient_id'];
        $pharmPatId = $hIdToPharmId[$hPatId] ?? null;
        $patName = trim($hrx['patient_name'] ?? '');
        if ($patName === '') $patName = 'Patient #' . $hPatId;
        $docName = !empty($hrx['prescriber_doctor_name']) ? $hrx['prescriber_doctor_name'] : 'Hospital Medical Consultant';

        $rxDate = date('Y-m-d', strtotime($hrx['created_at'] ?? 'now'));
        $createdAt = !empty($hrx['created_at']) ? $hrx['created_at'] : date('Y-m-d H:i:s');

        $rxInsStmt->execute([
            $rxNum,
            $rxDate,
            $pharmPatId,
            $patName,
            $hrx['patient_mobile'] ?? '',
            $docName,
            $hrx['doctor_reg_no'] ?? '',
            $hrx['doc_department'] ?? 'General Medicine',
            $hrx['notes'] ?? 'Synced from Hospital Prescription Records',
            $createdAt,
            $createdAt
        ]);
        $newRxId = (int)$pharmacyPdo->lastInsertId();

        $rxItems = $hospitalPdo->prepare("SELECT * FROM prescription_items WHERE prescription_id = ?");
        $rxItems->execute([$hrx['prescription_id']]);
        $items = $rxItems->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as $ri) {
            $medId = $medIdMap[(int)($ri['medicine_id'] ?? 0)] ?? reset($medNameToId);
            $dosageInst = $ri['instructions'] ?? ($ri['dosage'] ?? '1-0-1 After Meals');
            $prescQty = (int)($ri['quantity'] ?? 10);

            $rxItemInsStmt->execute([
                $newRxId,
                $medId,
                500.00,
                '1-0-1',
                $prescQty,
                $dosageInst,
                $createdAt,
                $createdAt
            ]);
        }
        $rxImported++;
    }

    $pharmacyPdo->commit();
    echo "  -> Prescriptions Synced: {$rxImported} records.\n\n";

    echo "====================================================================\n";
    echo "   SYNCHRONIZATION COMPLETED SUCCESSFULLY!                         \n";
    echo "====================================================================\n";
} catch (Exception $e) {
    if (isset($pharmacyPdo) && $pharmacyPdo->inTransaction()) {
        $pharmacyPdo->rollBack();
    }
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
