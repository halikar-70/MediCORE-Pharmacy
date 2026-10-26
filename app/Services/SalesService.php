<?php
// app/Services/SalesService.php - Standalone Pharmacy POS, Counter Sale & Billing Engine

namespace Pharmacy\Services;

require_once __DIR__ . '/FefoService.php';
require_once __DIR__ . '/StockLedgerService.php';
require_once __DIR__ . '/DocumentSequenceService.php';
require_once __DIR__ . '/AuditService.php';

use PDO;
use Exception;
use InvalidArgumentException;
use Pharmacy\Database\Database;

class SalesService
{
    private PDO $pdo;
    private ?PDO $hospitalPdo;
    private FefoService $fefoService;
    private StockLedgerService $ledgerService;
    private DocumentSequenceService $seqService;
    private AuditService $auditService;

    // Standard discount threshold above which 'pharmacy.discount.override' permission is required
    public const MAX_STANDARD_DISCOUNT_PERCENT = 10.0;
    public const MAX_ABSOLUTE_DISCOUNT_PERCENT = 50.0;

    public function __construct(PDO $pdo, ?PDO $hospitalPdo = null)
    {
        $this->pdo = $pdo;
        $this->hospitalPdo = $hospitalPdo ?: Database::getHospitalConnection();
        $this->fefoService = new FefoService($pdo);
        $this->ledgerService = new StockLedgerService($pdo);
        $this->seqService = new DocumentSequenceService($pdo);
        $this->auditService = new AuditService($pdo);
    }

    /**
     * Search medicine catalog for POS by barcode, name, generic, composition, or brand.
     * Only returns active medicines and includes non-expired available stock.
     */
    public function searchMedicines(string $query, int $limit = 20): array
    {
        $query = trim($query);
        if ($query === '') {
            $sql = "
                SELECT m.medicine_id, m.medicine_name, m.generic_name, m.composition, m.brand_name,
                       m.dosage_form, m.strength, m.pack_size, m.barcode, m.hsn_code, m.gst_percent,
                       m.price as mrp, m.price as sale_price, m.shelf, m.rack_location,
                       COALESCE((
                           SELECT SUM(mb.quantity_available)
                           FROM medicine_batches mb
                           WHERE mb.medicine_id = m.medicine_id
                             AND mb.status = 'Active'
                             AND mb.quantity_available > 0
                             AND mb.expiry_date >= CURDATE()
                       ), 0) as available_stock
                FROM medicines m
                WHERE m.status = 'Active'
                  AND m.deleted_at IS NULL
                ORDER BY available_stock DESC, m.medicine_name ASC
                LIMIT ?
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $sql = "
            SELECT m.medicine_id, m.medicine_name, m.generic_name, m.composition, m.brand_name,
                   m.dosage_form, m.strength, m.pack_size, m.barcode, m.hsn_code, m.gst_percent,
                   m.price as mrp, m.price as sale_price, m.shelf, m.rack_location,
                   COALESCE((
                       SELECT SUM(mb.quantity_available)
                       FROM medicine_batches mb
                       WHERE mb.medicine_id = m.medicine_id
                         AND mb.status = 'Active'
                         AND mb.quantity_available > 0
                         AND mb.expiry_date >= CURDATE()
                   ), 0) as available_stock
            FROM medicines m
            WHERE m.status = 'Active'
              AND m.deleted_at IS NULL
              AND (
                  m.barcode = ?
                  OR m.medicine_name LIKE ?
                  OR m.generic_name LIKE ?
                  OR m.composition LIKE ?
                  OR m.brand_name LIKE ?
              )
            ORDER BY available_stock DESC, m.medicine_name ASC
            LIMIT ?
        ";

        $stmt = $this->pdo->prepare($sql);
        $like = '%' . $query . '%';
        $stmt->bindValue(1, $query, PDO::PARAM_STR);
        $stmt->bindValue(2, $like, PDO::PARAM_STR);
        $stmt->bindValue(3, $like, PDO::PARAM_STR);
        $stmt->bindValue(4, $like, PDO::PARAM_STR);
        $stmt->bindValue(5, $like, PDO::PARAM_STR);
        $stmt->bindValue(6, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Process and record an atomic pharmacy sale (Counter, Prescription, or IPD).
     *
     * @param array $data Header fields (customer_name, customer_mobile, sale_type, patient_id, etc.)
     * @param array $items Array of items: [['medicine_id' => int, 'quantity' => int, 'discount_percent' => float]]
     * @param array|null $payment Initial payment details: ['amount' => float, 'mode' => string, 'reference' => string]
     * @param int|null $userId Staff ID processing sale
     * @param bool $hasDiscountOverridePermission Whether user is authorized for discounts > standard threshold
     * @return array Created sale record
     * @throws Exception On validation, concurrency, or stock failure
     */
    public function createSale(
        array $data,
        array $items,
        ?array $payment = null,
        ?int $userId = null,
        bool $hasDiscountOverridePermission = false
    ): array {
        if (empty($items)) {
            throw new InvalidArgumentException("Sale must contain at least one medicine item.");
        }

        $saleType = $data['sale_type'] ?? 'COUNTER_SALE';
        if (!in_array($saleType, ['COUNTER_SALE', 'PRESCRIPTION_SALE', 'IPD_SALE'], true)) {
            throw new InvalidArgumentException("Invalid sale type: {$saleType}");
        }

        // Idempotency token check
        $idempotencyKey = !empty($data['idempotency_key']) ? trim($data['idempotency_key']) : null;
        if ($idempotencyKey !== null) {
            $idemStmt = $this->pdo->prepare("SELECT sale_id, sale_number FROM pharmacy_sales WHERE idempotency_key = ?");
            $idemStmt->execute([$idempotencyKey]);
            $existing = $idemStmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                return $this->getSale((int)$existing['sale_id']);
            }
        }

        $customerName = trim($data['customer_name'] ?? 'Walk-in Customer');
        if ($customerName === '') {
            $customerName = 'Walk-in Customer';
        }
        $customerMobile = !empty($data['customer_mobile']) ? trim($data['customer_mobile']) : null;
        $doctorName = !empty($data['doctor_name']) ? trim($data['doctor_name']) : null;
        $patientId = !empty($data['patient_id']) ? (int)$data['patient_id'] : null;
        $prescriptionId = !empty($data['prescription_id']) ? (int)$data['prescription_id'] : null;
        $ipdAdmissionId = !empty($data['ipd_admission_id']) ? trim($data['ipd_admission_id']) : null;
        $ipdWard = !empty($data['ipd_ward']) ? trim($data['ipd_ward']) : null;
        $ipdBed = !empty($data['ipd_bed']) ? trim($data['ipd_bed']) : null;
        $notes = !empty($data['notes']) ? trim($data['notes']) : null;

        // Overall sale discount percentage
        $headerDiscountPercent = max(0.0, (float)($data['discount_percent'] ?? 0.0));
        if ($headerDiscountPercent > self::MAX_ABSOLUTE_DISCOUNT_PERCENT) {
            throw new InvalidArgumentException("Discount cannot exceed " . self::MAX_ABSOLUTE_DISCOUNT_PERCENT . "%.");
        }
        if ($headerDiscountPercent > self::MAX_STANDARD_DISCOUNT_PERCENT && !$hasDiscountOverridePermission) {
            throw new Exception("Discount of {$headerDiscountPercent}% exceeds standard cashier threshold (" . self::MAX_STANDARD_DISCOUNT_PERCENT . "%). Manager authorization required.");
        }

        $this->pdo->beginTransaction();
        try {
            // Determine sequence key based on sale type
            $seqKey = match ($saleType) {
                'PRESCRIPTION_SALE' => 'PRESCRIPTION_SALE',
                'IPD_SALE'          => 'REGULAR_SALE',
                default             => 'COUNTER_SALE'
            };

            $saleNumber = $this->seqService->generate($seqKey);

            // Phase 1: Allocate stock and validate prices server-side
            $processedItems = [];
            $allFefoAllocations = [];
            $subtotalAmount = 0.0;
            $totalTaxable = 0.0;
            $totalGst = 0.0;

            foreach ($items as $idx => $rawItem) {
                $medicineId = (int)($rawItem['medicine_id'] ?? 0);
                $qty = (int)($rawItem['quantity'] ?? 0);

                if ($medicineId <= 0) {
                    throw new InvalidArgumentException("Item #" . ($idx + 1) . ": Invalid medicine ID.");
                }
                if ($qty <= 0) {
                    throw new InvalidArgumentException("Item #" . ($idx + 1) . ": Quantity must be greater than zero.");
                }

                // Authoritative re-read from master medicine with row lock
                $mStmt = $this->pdo->prepare("
                    SELECT medicine_id, medicine_name, dosage_form, pack_size, gst_percent,
                           price as mrp, status
                    FROM medicines
                    WHERE medicine_id = ? AND deleted_at IS NULL
                    FOR UPDATE
                ");
                $mStmt->execute([$medicineId]);
                $med = $mStmt->fetch(PDO::FETCH_ASSOC);

                if (!$med) {
                    throw new Exception("Medicine #{$medicineId} not found or archived.");
                }
                if ($med['status'] !== 'Active') {
                    throw new Exception("Medicine '{$med['medicine_name']}' is not Active.");
                }

                // Call existing FEFO engine with row-locking (FOR UPDATE), prioritizing preferred batch if selected
                $preferredBatchId = !empty($rawItem['batch_id']) ? (int)$rawItem['batch_id'] : null;
                $allocations = $this->fefoService->allocate($medicineId, $qty, true, $preferredBatchId);
                $allFefoAllocations[$medicineId] = $allocations;

                // Price is authoritative from master MRP/sale price
                $unitPrice = (float)$med['mrp'];
                $lineSubtotal = round($unitPrice * $qty, 2);

                // Line discount
                $lineDiscPercent = max(0.0, (float)($rawItem['discount_percent'] ?? 0.0));
                if ($lineDiscPercent > self::MAX_ABSOLUTE_DISCOUNT_PERCENT) {
                    throw new InvalidArgumentException("Item discount cannot exceed " . self::MAX_ABSOLUTE_DISCOUNT_PERCENT . "%.");
                }
                if ($lineDiscPercent > self::MAX_STANDARD_DISCOUNT_PERCENT && !$hasDiscountOverridePermission) {
                    throw new Exception("Item discount of {$lineDiscPercent}% requires manager authorization.");
                }

                // If header discount provided and line discount not provided, apply header discount
                $appliedDiscountPercent = ($lineDiscPercent > 0.0) ? $lineDiscPercent : $headerDiscountPercent;
                $lineDiscountAmount = round($lineSubtotal * ($appliedDiscountPercent / 100.0), 2);
                $lineTaxable = round($lineSubtotal - $lineDiscountAmount, 2);

                // Authoritative GST calculation
                $gstPercent = (float)$med['gst_percent'];
                $lineGstAmount = round($lineTaxable * ($gstPercent / 100.0), 2);
                $lineTotal = round($lineTaxable + $lineGstAmount, 2);

                $subtotalAmount += $lineSubtotal;
                $totalTaxable += $lineTaxable;
                $totalGst += $lineGstAmount;

                $processedItems[] = [
                    'medicine_id'      => $medicineId,
                    'dosage_form'      => $med['dosage_form'],
                    'pack_size'        => $med['pack_size'],
                    'quantity'         => $qty,
                    'unit_price'       => $unitPrice,
                    'mrp'              => $unitPrice,
                    'discount_percent' => $appliedDiscountPercent,
                    'discount_amount'  => $lineDiscountAmount,
                    'taxable_amount'   => $lineTaxable,
                    'gst_percent'      => $gstPercent,
                    'gst_amount'       => $lineGstAmount,
                    'line_total'       => $lineTotal,
                    'allocations'      => $allocations
                ];
            }

            $rawGrandTotal = $totalTaxable + $totalGst;
            $grandTotal = round($rawGrandTotal, 2);
            $roundOff = round($grandTotal - $rawGrandTotal, 2);
            $totalDiscountAmount = round($subtotalAmount - $totalTaxable, 2);

            // Resolve integer admission_id for Hospital IPD Billing integration
            $hospConn = $this->hospitalPdo ?: $this->pdo;
            $admissionId = !empty($data['admission_id']) ? (int)$data['admission_id'] : null;
            if (!$admissionId && !empty($ipdAdmissionId)) {
                if (is_numeric($ipdAdmissionId)) {
                    $admissionId = (int)$ipdAdmissionId;
                } else {
                    try {
                        $admStmt = $hospConn->prepare("SELECT admission_id FROM admissions WHERE ipd_number = ? LIMIT 1");
                        $admStmt->execute([$ipdAdmissionId]);
                        $admissionId = (int)$admStmt->fetchColumn() ?: null;
                    } catch (Exception $e) {
                        $admissionId = null;
                    }
                }
            } elseif (!$admissionId && $saleType === 'IPD_SALE' && !empty($patientId)) {
                try {
                    $hPatId = $patientId;
                    $pStmt = $this->pdo->prepare("SELECT hospital_patient_id FROM pharmacy_patients WHERE id = ?");
                    $pStmt->execute([$patientId]);
                    $mappedHospId = (int)$pStmt->fetchColumn();
                    if ($mappedHospId > 0) {
                        $hPatId = $mappedHospId;
                    }

                    $admStmt = $hospConn->prepare("
                        SELECT a.admission_id 
                        FROM admissions a 
                        WHERE (a.patient_id = ? OR a.patient_id = ?)
                          AND a.status = 'Admitted'
                        ORDER BY a.admission_id DESC LIMIT 1
                    ");
                    $admStmt->execute([$patientId, $hPatId]);
                    $admissionId = (int)$admStmt->fetchColumn() ?: null;
                } catch (Exception $e) {
                    $admissionId = null;
                }
            }

            // Identify if patient is an Existing Hospital Patient (MediPro)
            $isExistingPatient = false;
            $hospitalPatientId = null;

            if ($admissionId || !empty($ipdAdmissionId)) {
                $isExistingPatient = true;
                if ($admissionId) {
                    try {
                        $admPatStmt = $hospConn->prepare("SELECT patient_id FROM admissions WHERE admission_id = ?");
                        $admPatStmt->execute([$admissionId]);
                        $hospitalPatientId = (int)$admPatStmt->fetchColumn() ?: null;
                    } catch (Exception $e) {
                        $hospitalPatientId = null;
                    }
                }
            }

            if ($patientId > 0) {
                // Check directly in MediPro patients table
                try {
                    $hStmt = $hospConn->prepare("SELECT patient_id FROM patients WHERE patient_id = ?");
                    $hStmt->execute([$patientId]);
                    $foundHId = $hStmt->fetchColumn();
                    if ($foundHId) {
                        $isExistingPatient = true;
                        $hospitalPatientId = (int)$foundHId;
                    }
                } catch (Exception $e) {}

                if (!$isExistingPatient) {
                    // Check pharmacy_patients mapping to MediPro
                    $ppStmt = $this->pdo->prepare("SELECT hospital_patient_id, hospital_uhid FROM pharmacy_patients WHERE id = ?");
                    $ppStmt->execute([$patientId]);
                    $pp = $ppStmt->fetch(PDO::FETCH_ASSOC);
                    if ($pp) {
                        if (!empty($pp['hospital_patient_id'])) {
                            $isExistingPatient = true;
                            $hospitalPatientId = (int)$pp['hospital_patient_id'];
                        } elseif (!empty($pp['hospital_uhid'])) {
                            try {
                                $uStmt = $hospConn->prepare("SELECT patient_id FROM patients WHERE patient_code = ?");
                                $uStmt->execute([$pp['hospital_uhid']]);
                                $uFound = $uStmt->fetchColumn();
                                if ($uFound) {
                                    $isExistingPatient = true;
                                    $hospitalPatientId = (int)$uFound;
                                }
                            } catch (Exception $e) {}
                        }
                    }
                }
            }

            // Also check customer_name against hospital patients if not Walk-in
            if (!$isExistingPatient && $customerName !== 'Walk-in Customer' && !empty($customerName)) {
                try {
                    $cStmt = $hospConn->prepare("
                        SELECT patient_id FROM patients 
                        WHERE CONCAT(first_name, ' ', last_name) = ? OR first_name = ?
                        LIMIT 1
                    ");
                    $cStmt->execute([$customerName, $customerName]);
                    $cFound = $cStmt->fetchColumn();
                    if ($cFound) {
                        $isExistingPatient = true;
                        $hospitalPatientId = (int)$cFound;
                    }
                } catch (Exception $e) {}
            }

            // Phase 2: Insert Sale Header
            $insSale = $this->pdo->prepare("
                INSERT INTO pharmacy_sales (
                    sale_number, sale_type, sale_date, patient_type, patient_id,
                    ipd_admission_id, ipd_ward, ipd_bed,
                    customer_name, customer_mobile, doctor_name, prescription_id,
                    subtotal_amount, discount_percent, discount_amount, discount_reason, discount_authorized_by,
                    taxable_amount, gst_amount, round_off, grand_total,
                    paid_amount, balance_amount, payment_status, payment_mode,
                    idempotency_key, status, notes, created_by, created_at
                ) VALUES (
                    ?, ?, CURDATE(), ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    0.00, ?, 'UNPAID', 'CASH',
                    ?, 'COMPLETED', ?, ?, NOW()
                )
            ");

            $patientType = match ($saleType) {
                'IPD_SALE' => 'IPD',
                'PRESCRIPTION_SALE' => ($patientId ? 'REGISTERED' : 'WALK_IN'),
                default => ($patientId ? 'REGISTERED' : 'WALK_IN')
            };

            $discountReason = $data['discount_reason'] ?? null;
            $discountAuthBy = ($headerDiscountPercent > self::MAX_STANDARD_DISCOUNT_PERCENT) ? $userId : null;

            $insSale->execute([
                $saleNumber,
                $saleType,
                $patientType,
                $patientId,
                $ipdAdmissionId,
                $ipdWard,
                $ipdBed,
                $customerName,
                $customerMobile,
                $doctorName,
                $prescriptionId,
                $subtotalAmount,
                $headerDiscountPercent,
                $totalDiscountAmount,
                $discountReason,
                $discountAuthBy,
                $totalTaxable,
                $totalGst,
                $roundOff,
                $grandTotal,
                $grandTotal, // initial balance_amount = grand_total
                $idempotencyKey,
                $notes,
                $userId
            ]);

            $saleId = (int)$this->pdo->lastInsertId();

            // Phase 3: Insert Line Items & Batch Mappings
            $insItem = $this->pdo->prepare("
                INSERT INTO pharmacy_sale_items (
                    sale_id, medicine_id, dosage_form, pack_size, quantity,
                    unit_price, mrp, discount_percent, discount_amount,
                    taxable_amount, gst_percent, gst_amount, line_total, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, NOW()
                )
            ");

            $insBatchMap = $this->pdo->prepare("
                INSERT INTO pharmacy_sale_item_batches (
                    sale_item_id, sale_id, medicine_id, batch_id, batch_number,
                    expiry_date, allocated_quantity, unit_cost, unit_price, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, NOW()
                )
            ");

            foreach ($processedItems as $pItem) {
                $insItem->execute([
                    $saleId,
                    $pItem['medicine_id'],
                    $pItem['dosage_form'],
                    $pItem['pack_size'],
                    $pItem['quantity'],
                    $pItem['unit_price'],
                    $pItem['mrp'],
                    $pItem['discount_percent'],
                    $pItem['discount_amount'],
                    $pItem['taxable_amount'],
                    $pItem['gst_percent'],
                    $pItem['gst_amount'],
                    $pItem['line_total']
                ]);
                $saleItemId = (int)$this->pdo->lastInsertId();

                // Insert batch traceability mapping
                foreach ($pItem['allocations'] as $alloc) {
                    $insBatchMap->execute([
                        $saleItemId,
                        $saleId,
                        $pItem['medicine_id'],
                        $alloc['batch_id'],
                        $alloc['batch_number'],
                        $alloc['expiry_date'],
                        $alloc['allocated_quantity'],
                        $alloc['purchase_price'],
                        $alloc['sale_price']
                    ]);
                }
            }

            // Phase 4: Atomic Stock Deduction via existing FefoService
            // All allocations execute row-level locking, medicine_batches decrements, and StockLedger movements
            foreach ($processedItems as $pItem) {
                $this->fefoService->executeDeduction(
                    $pItem['allocations'],
                    $saleType,
                    $saleId,
                    $saleNumber,
                    $userId,
                    "Sale #{$saleNumber} Dispensed"
                );
            }

            // Phase 5: Billing & Payment Processing (Supports BOTH walk-in & previously registered HMS patients)
            $rawMode = strtoupper(trim($payment['mode'] ?? 'CASH'));
            $isCreditOrHospitalBilling = in_array($rawMode, ['HOSPITAL_BILLING', 'CREDIT'], true);

            $paidAmount = 0.0;
            $balanceAmount = $grandTotal;
            $paymentMode = 'Cash';
            $paymentStatus = 'Unpaid';
            $refNumber = !empty($payment['reference']) ? trim($payment['reference']) : null;
            $payNotes = !empty($payment['notes']) ? trim($payment['notes']) : 'Pharmacy Settlement';

            if ($isCreditOrHospitalBilling) {
                // Hospital Credit / Forward to central billing
                $paidAmount = 0.00;
                $balanceAmount = $grandTotal;
                $paymentStatus = 'CREDIT';
                $paymentMode = ($rawMode === 'CREDIT') ? 'Credit' : 'Hospital Billing';
            } else {
                // Cash, Card, UPI, Bank Transfer, Cheque
                $payAmt = isset($payment['amount']) ? (float)$payment['amount'] : $grandTotal;
                if ($payAmt <= 0.0 && empty($payment['amount_specified'])) {
                    $payAmt = $grandTotal;
                }
                $paidAmount = max(0.0, $payAmt);
                $balanceAmount = round(max(0.0, $grandTotal - $paidAmount), 2);
                $paymentStatus = ($balanceAmount <= 0.0) ? 'PAID' : ($paidAmount > 0 ? 'PARTIALLY_PAID' : 'UNPAID');
                $paymentMode = match ($rawMode) {
                    'UPI'           => 'UPI',
                    'CARD'          => 'Card',
                    'BANK_TRANSFER' => 'Bank Transfer',
                    'CHEQUE'        => 'Cheque',
                    default         => 'Cash'
                };

                // Record payment in pharmacy_sale_payments if paidAmount > 0
                if ($paidAmount > 0) {
                    $paySeq = $this->seqService->generate('SUPPLIER_PAYMENT');
                    $paySeqNum = 'RCP-' . substr($paySeq, 3);

                    $insPay = $this->pdo->prepare("
                        INSERT INTO pharmacy_sale_payments (
                            payment_number, sale_id, payment_date, amount, payment_mode,
                            reference_number, notes, created_by, created_at
                        ) VALUES (
                            ?, ?, CURDATE(), ?, ?,
                            ?, ?, ?, NOW()
                        )
                    ");
                    $insPay->execute([
                        $paySeqNum,
                        $saleId,
                        $paidAmount,
                        $rawMode,
                        $refNumber,
                        $payNotes,
                        $userId
                    ]);
                }
            }

            // Sync with MediPro Central Billing if Existing Hospital Patient
            $mediproBillId = null;
            if ($isExistingPatient) {
                $visitId = null;
                if ($hospitalPatientId && $this->hospitalPdo) {
                    try {
                        $vStmt = $this->hospitalPdo->prepare("SELECT visit_id FROM opd_visits WHERE patient_id = ? ORDER BY visit_id DESC LIMIT 1");
                        $vStmt->execute([$hospitalPatientId]);
                        $visitId = (int)$vStmt->fetchColumn() ?: null;
                    } catch (Exception $e) {
                        $visitId = null;
                    }
                }

                $mediproBillId = $this->passSaleToHospitalBilling(
                    $saleId,
                    $hospitalPatientId ?: $patientId,
                    $admissionId,
                    $visitId,
                    $saleNumber,
                    $subtotalAmount,
                    $totalDiscountAmount,
                    $headerDiscountPercent,
                    $totalGst,
                    $grandTotal,
                    $processedItems,
                    $userId,
                    $paidAmount,
                    $balanceAmount,
                    $paymentStatus,
                    $paymentMode
                );

                $notesPrefix = $isCreditOrHospitalBilling 
                    ? " [Passed to MediPro Bill #{$mediproBillId}]" 
                    : " [Paid in Pharmacy & Synced to MediPro Bill #{$mediproBillId}]";
                $notes = trim(($notes ?? '') . $notesPrefix);
            }

            // Update Sale Header with final payment numbers
            $updSale = $this->pdo->prepare("
                UPDATE pharmacy_sales
                SET paid_amount = ?, balance_amount = ?, payment_status = ?,
                    payment_mode = ?, notes = ?
                WHERE sale_id = ?
            ");
            $updSale->execute([
                $paidAmount,
                $balanceAmount,
                $paymentStatus,
                $paymentMode,
                $notes,
                $saleId
            ]);

            // Phase 6: Update prescription status if dispensing against a prescription
            if ($prescriptionId) {
                $this->updatePrescriptionDispensingStatus($prescriptionId, $processedItems);
            }

            // Phase 7: Audit log entry
            $this->auditService->logAction(
                $userId,
                'SALE_CREATED',
                'pharmacy_sales',
                (string)$saleId,
                null,
                [
                    'sale_type'      => $saleType,
                    'customer_name'  => $customerName,
                    'grand_total'    => $grandTotal,
                    'paid_amount'    => $paidAmount,
                    'payment_status' => $paymentStatus,
                    'items_count'    => count($processedItems)
                ]
            );

            $this->pdo->commit();
            $completedSale = $this->getSale($saleId);
            $completedSale['is_existing_patient'] = $isExistingPatient;
            $completedSale['passed_to_medipro'] = $isExistingPatient;
            if ($isExistingPatient) {
                $completedSale['medipro_bill_id'] = $mediproBillId;
            }
            return $completedSale;

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Add subsequent payment against an unpaid or partially paid sale invoice.
     */
    public function addPayment(int $saleId, float $amount, string $mode = 'CASH', ?string $refNumber = null, ?int $userId = null): array
    {
        if ($amount <= 0.0) {
            throw new InvalidArgumentException("Payment amount must be greater than zero.");
        }

        $this->pdo->beginTransaction();
        try {
            $saleStmt = $this->pdo->prepare("
                SELECT sale_id, sale_number, grand_total, paid_amount, balance_amount, status
                FROM pharmacy_sales
                WHERE sale_id = ?
                FOR UPDATE
            ");
            $saleStmt->execute([$saleId]);
            $sale = $saleStmt->fetch(PDO::FETCH_ASSOC);

            if (!$sale) {
                throw new Exception("Sale #{$saleId} not found.");
            }
            if ($sale['status'] === 'CANCELLED') {
                throw new Exception("Cannot add payment to a cancelled sale invoice.");
            }

            $currentBalance = (float)$sale['balance_amount'];
            if ($amount > $currentBalance) {
                throw new InvalidArgumentException("Payment amount (₹{$amount}) exceeds remaining balance (₹{$currentBalance}).");
            }

            $paySeq = $this->seqService->generate('SUPPLIER_PAYMENT');
            $paySeqNum = 'RCP-' . substr($paySeq, 3);

            $insPay = $this->pdo->prepare("
                INSERT INTO pharmacy_sale_payments (
                    payment_number, sale_id, payment_date, amount, payment_mode,
                    reference_number, created_by, created_at
                ) VALUES (
                    ?, ?, CURDATE(), ?, ?,
                    ?, ?, NOW()
                )
            ");
            $insPay->execute([$paySeqNum, $saleId, $amount, strtoupper($mode), $refNumber, $userId]);

            $newPaid = round((float)$sale['paid_amount'] + $amount, 2);
            $newBalance = round((float)$sale['grand_total'] - $newPaid, 2);
            $newStatus = ($newBalance <= 0.0) ? 'PAID' : 'PARTIALLY_PAID';

            $updSale = $this->pdo->prepare("
                UPDATE pharmacy_sales
                SET paid_amount = ?, balance_amount = ?, payment_status = ?
                WHERE sale_id = ?
            ");
            $updSale->execute([$newPaid, $newBalance, $newStatus, $saleId]);

            $this->auditService->logAction(
                $userId,
                'SALE_PAYMENT_ADDED',
                'pharmacy_sale_payments',
                (string)$saleId,
                ['previous_paid' => $sale['paid_amount']],
                ['new_paid' => $newPaid, 'payment_number' => $paySeqNum]
            );

            $this->pdo->commit();
            return $this->getSale($saleId);

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Record and complete an overpayment refund against a sales invoice.
     *
     * Used when an invoice total is reduced below the amount already paid by the customer
     * (e.g. ₹200 bill -> paid ₹150 -> edited bill ₹100 -> excess ₹50).
     *
     * Rules:
     * - Locks the sale row FOR UPDATE to prevent race conditions and duplicate refunds.
     * - Validates status !== 'CANCELLED'.
     * - Calculates excess = paid_amount - grand_total.
     * - Validates excess > 0.00 and refund_amount <= excess.
     * - Inserts a compensating refund voucher (-refund_amount) into pharmacy_sale_payments.
     *   This preserves the original historical receipt voucher (+₹150) while balancing the ledger.
     * - Updates pharmacy_sales: paid_amount = grand_total, notes updated to [OVERPAYMENT_REFUND_COMPLETED: ...]
     * - Logs audit trail with previous and new values, voucher #, mode, and reference.
     * - Entire operation is atomic; rolls back completely on any error.
     * - Re-attempting will strictly fail as excess becomes 0.00 and notes show completed.
     */
    public function settleOverpaymentRefund(
        int $saleId,
        float $refundAmount,
        string $refundMode = 'CASH',
        ?string $refNumber = null,
        string $notes = '',
        ?int $userId = null
    ): array {
        $refundAmount = round((float)$refundAmount, 2);
        if ($refundAmount <= 0.0) {
            throw new \InvalidArgumentException("Refund amount must be greater than zero.");
        }

        $allowedRefundModes = ['CASH', 'UPI', 'CARD', 'BANK_TRANSFER', 'CHEQUE'];
        $cleanMode = strtoupper(trim($refundMode));
        if ($cleanMode === 'CREDIT') {
            throw new \InvalidArgumentException("Customer credit cannot be issued as a cash refund. Accepted refund disbursement modes are: " . implode(', ', $allowedRefundModes) . ".");
        }
        if (!in_array($cleanMode, $allowedRefundModes, true)) {
            throw new \InvalidArgumentException("Invalid refund payment mode '{$refundMode}'. Accepted modes: " . implode(', ', $allowedRefundModes) . ".");
        }

        $this->pdo->beginTransaction();
        try {
            $saleStmt = $this->pdo->prepare("
                SELECT sale_id, sale_number, grand_total, paid_amount, balance_amount, status, notes
                FROM pharmacy_sales
                WHERE sale_id = ?
                FOR UPDATE
            ");
            $saleStmt->execute([$saleId]);
            $sale = $saleStmt->fetch(\PDO::FETCH_ASSOC);

            if (!$sale) {
                throw new \Exception("Sale #{$saleId} not found.");
            }
            if ($sale['status'] === 'CANCELLED') {
                throw new \Exception("Cannot process an overpayment refund on a cancelled sale invoice.");
            }

            $currentExcess = max(0.00, round((float)$sale['paid_amount'] - (float)$sale['grand_total'], 2));
            if ($currentExcess <= 0.00) {
                throw new \Exception("No outstanding overpayment exists for Sale #{$sale['sale_number']} (Paid ₹" . number_format((float)$sale['paid_amount'], 2) . ", Total ₹" . number_format((float)$sale['grand_total'], 2) . ").");
            }

            if ($refundAmount > $currentExcess) {
                throw new \InvalidArgumentException("Refund amount (₹" . number_format($refundAmount, 2) . ") exceeds outstanding excess (₹" . number_format($currentExcess, 2) . ").");
            }

            // Generate refund voucher sequence number (e.g. RFD-000001)
            $refundVoucher = $this->seqService->getNextNumber('SALE_REFUND');

            // Insert negative compensating refund entry into pharmacy_sale_payments
            // Preserves historical receipt vouchers (+₹150.00) while balancing net receipts (+₹100.00)
            $refundVoucherAmount = -1.0 * $refundAmount;
            $payNotes = !empty($notes) ? $notes : "Overpayment refund settlement for invoice edit: Excess ₹" . number_format($refundAmount, 2);

            $insPay = $this->pdo->prepare("
                INSERT INTO pharmacy_sale_payments (
                    payment_number, sale_id, payment_date, amount, payment_mode,
                    reference_number, notes, created_by, created_at
                ) VALUES (
                    ?, ?, CURDATE(), ?, ?,
                    ?, ?, ?, NOW()
                )
            ");
            $insPay->execute([
                $refundVoucher,
                $saleId,
                $refundVoucherAmount,
                $cleanMode,
                $refNumber !== '' ? $refNumber : null,
                $payNotes,
                $userId
            ]);

            $newPaid = max(0.00, round((float)$sale['paid_amount'] - $refundAmount, 2));
            $newBalance = max(0.00, round((float)$sale['grand_total'] - $newPaid, 2));
            $newStatus = ($newBalance <= 0.01) ? 'PAID' : (($newPaid > 0) ? 'PARTIALLY_PAID' : 'UNPAID');

            $remainingExcess = max(0.00, round($newPaid - (float)$sale['grand_total'], 2));

            $origNotes = $sale['notes'] ?? '';
            if ($remainingExcess > 0.00) {
                // Partial refund: excess still remains due
                $partialRefundTag = "[OVERPAYMENT_REFUND_DUE: ₹" . number_format($remainingExcess, 2) . " (Partial refund ₹" . number_format($refundAmount, 2) . " paid via {$cleanMode} on " . date('Y-m-d') . ", Voucher: {$refundVoucher}" . ($refNumber ? ", Ref: {$refNumber}" : "") . ")]";
                if (strpos($origNotes, 'OVERPAYMENT_REFUND_DUE') !== false) {
                    $newNotes = preg_replace('/\[OVERPAYMENT_REFUND_DUE:[^\]]*\]/', $partialRefundTag, $origNotes);
                } else {
                    $newNotes = trim($origNotes . ' ' . $partialRefundTag);
                }
            } else {
                // Complete refund: all excess settled
                $refundTag = "[OVERPAYMENT_REFUND_COMPLETED: ₹" . number_format($refundAmount, 2) . " via {$cleanMode} on " . date('Y-m-d') . " (Voucher: {$refundVoucher}" . ($refNumber ? ", Ref: {$refNumber}" : "") . ")]";
                if (strpos($origNotes, 'OVERPAYMENT_REFUND_DUE') !== false) {
                    $newNotes = preg_replace('/\[OVERPAYMENT_REFUND_DUE:[^\]]*\]/', $refundTag, $origNotes);
                } else {
                    $newNotes = trim($origNotes . ' ' . $refundTag);
                }
            }

            $updSale = $this->pdo->prepare("
                UPDATE pharmacy_sales
                SET paid_amount = ?, balance_amount = ?, payment_status = ?, notes = ?, updated_at = NOW()
                WHERE sale_id = ?
            ");
            $updSale->execute([$newPaid, $newBalance, $newStatus, $newNotes, $saleId]);

            $this->auditService->logAction(
                $userId,
                'SETTLE_OVERPAYMENT_REFUND',
                'pharmacy_sale_payments',
                (string)$saleId,
                [
                    'paid_amount'      => (float)$sale['paid_amount'],
                    'grand_total'      => (float)$sale['grand_total'],
                    'excess_amount'    => $currentExcess,
                ],
                [
                    'refund_amount'    => $refundAmount,
                    'remaining_excess' => $remainingExcess,
                    'voucher_number'   => $refundVoucher,
                    'payment_mode'     => $cleanMode,
                    'reference'        => $refNumber,
                    'new_paid'         => $newPaid,
                    'balance'          => $newBalance,
                    'payment_status'   => $newStatus,
                ]
            );

            $this->pdo->commit();
            return [
                'success'          => true,
                'sale_id'          => $saleId,
                'refund_voucher'   => $refundVoucher,
                'refund_amount'    => $refundAmount,
                'remaining_excess' => $remainingExcess,
                'new_paid'         => $newPaid,
                'sale'             => $this->getSale($saleId)
            ];

        } catch (\Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Fetch all sales with outstanding overpayments awaiting refund.
     */
    public function getOutstandingOverpayments(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare("
            SELECT s.*, 
                   GREATEST(0.00, ROUND(s.paid_amount - s.grand_total, 2)) AS excess_amount,
                   u.full_name as cashier_name
            FROM pharmacy_sales s
            LEFT JOIN pharmacy_users u ON s.created_by = u.id
            WHERE s.status != 'CANCELLED' 
              AND s.paid_amount > s.grand_total
            ORDER BY s.sale_id DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Cancel a posted sale transaction.
     * Generates compensating stock inward movements into medicine_batches and StockLedger ('SALE_RETURN').
     * Historical transactions are NEVER deleted.
     */
    public function cancelSale(int $saleId, string $reason, ?int $userId = null): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException("Cancellation reason is required.");
        }

        $this->pdo->beginTransaction();
        try {
            $saleStmt = $this->pdo->prepare("SELECT * FROM pharmacy_sales WHERE sale_id = ? FOR UPDATE");
            $saleStmt->execute([$saleId]);
            $sale = $saleStmt->fetch(PDO::FETCH_ASSOC);

            if (!$sale) {
                throw new Exception("Sale #{$saleId} not found.");
            }
            if ($sale['status'] === 'CANCELLED') {
                throw new Exception("Sale #{$saleId} is already cancelled.");
            }

            // Fetch allocated batches to reverse stock
            $batchMapStmt = $this->pdo->prepare("
                SELECT medicine_id, batch_id, allocated_quantity, unit_cost, unit_price
                FROM pharmacy_sale_item_batches
                WHERE sale_id = ?
            ");
            $batchMapStmt->execute([$saleId]);
            $allocations = $batchMapStmt->fetchAll(PDO::FETCH_ASSOC);

            // Revert each batch quantity and write compensating SALE_RETURN ledger entry
            foreach ($allocations as $alloc) {
                $medId = (int)$alloc['medicine_id'];
                $batchId = (int)$alloc['batch_id'];
                $qty = (int)$alloc['allocated_quantity'];

                // 1. Compensating ledger movement (+qty)
                $this->ledgerService->recordEntry(
                    $medId,
                    $batchId,
                    'SALE_RETURN',
                    $qty,
                    (float)$alloc['unit_cost'],
                    (float)$alloc['unit_price'],
                    $saleId,
                    $sale['sale_number'],
                    $userId,
                    "Reversal of Sale #{$sale['sale_number']}: {$reason}"
                );

                // 2. Increment batch quantity
                $updBatch = $this->pdo->prepare("
                    UPDATE medicine_batches
                    SET quantity_available = quantity_available + ?, status = 'Active', updated_at = NOW()
                    WHERE batch_id = ?
                ");
                $updBatch->execute([$qty, $batchId]);

                // 3. Increment master medicine aggregate stock
                $updMed = $this->pdo->prepare("
                    UPDATE medicines
                    SET stock_quantity = stock_quantity + ?, updated_at = NOW()
                    WHERE medicine_id = ?
                ");
                $updMed->execute([$qty, $medId]);
            }

            // Update sale status to CANCELLED
            $updSale = $this->pdo->prepare("
                UPDATE pharmacy_sales
                SET status = 'CANCELLED', payment_status = 'CANCELLED',
                    cancelled_by = ?, cancelled_at = NOW(), cancellation_reason = ?
                WHERE sale_id = ?
            ");
            $updSale->execute([$userId, $reason, $saleId]);

            $this->auditService->logAction(
                $userId,
                'SALE_CANCELLED',
                'pharmacy_sales',
                (string)$saleId,
                ['status' => $sale['status']],
                ['status' => 'CANCELLED', 'reason' => $reason]
            );

            $this->pdo->commit();
            return $this->getSale($saleId);

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Update customer/doctor/notes metadata on an existing sale record.
     * Preserves financial amounts, batches, and ledger history.
     */
    public function updateSaleMetadata(int $saleId, array $data, ?int $userId = null): array
    {
        $existing = $this->getSale($saleId);
        if (!$existing) {
            throw new InvalidArgumentException("Sale #{$saleId} not found.");
        }

        $customerName = trim($data['customer_name'] ?? $existing['customer_name']);
        if ($customerName === '') {
            throw new InvalidArgumentException("Customer/Patient name cannot be blank.");
        }
        $customerMobile = !empty($data['customer_mobile']) ? trim($data['customer_mobile']) : null;
        $doctorName = !empty($data['doctor_name']) ? trim($data['doctor_name']) : null;
        $notes = !empty($data['notes']) ? trim($data['notes']) : null;

        $stmt = $this->pdo->prepare("
            UPDATE pharmacy_sales SET
                customer_name = ?,
                customer_mobile = ?,
                doctor_name = ?,
                notes = ?,
                updated_at = NOW()
            WHERE sale_id = ?
        ");
        $stmt->execute([$customerName, $customerMobile, $doctorName, $notes, $saleId]);

        $this->auditService->logAction(
            $userId ?? 1,
            'SALE_METADATA_UPDATE',
            'pharmacy_sales',
            $saleId,
            [
                'customer_name' => $existing['customer_name'],
                'customer_mobile' => $existing['customer_mobile'],
                'doctor_name' => $existing['doctor_name'],
                'notes' => $existing['notes']
            ],
            [
                'customer_name' => $customerName,
                'customer_mobile' => $customerMobile,
                'doctor_name' => $doctorName,
                'notes' => $notes
            ]
        );

        return $this->getSale($saleId);
    }

    /**
     * Get single sale record with items, batch allocations, and payment receipts.
     */
    public function getSale(int $saleId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT s.*, u.full_name as cashier_name
            FROM pharmacy_sales s
            LEFT JOIN pharmacy_users u ON s.created_by = u.id
            WHERE s.sale_id = ?
        ");
        $stmt->execute([$saleId]);
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sale) {
            return null;
        }

        // Fetch patient address and details if available
        $sale['patient_address'] = '';
        if (!empty($sale['patient_id'])) {
            try {
                $pStmt = $this->pdo->prepare("SELECT address, city, state, pincode, hospital_uhid, mobile FROM pharmacy_patients WHERE id = ?");
                $pStmt->execute([$sale['patient_id']]);
                $pData = $pStmt->fetch(PDO::FETCH_ASSOC);
                if ($pData) {
                    $addrParts = array_filter([$pData['address'] ?? '', $pData['city'] ?? '', $pData['state'] ?? '']);
                    $sale['patient_address'] = implode(', ', $addrParts);
                    if (empty($sale['hospital_uhid']) && !empty($pData['hospital_uhid'])) {
                        $sale['hospital_uhid'] = $pData['hospital_uhid'];
                    }
                    if (empty($sale['customer_mobile']) && !empty($pData['mobile'])) {
                        $sale['customer_mobile'] = $pData['mobile'];
                    }
                }
            } catch (Exception $pex) {
                // Ignore fallback
            }
        }

        // Fetch line items with full medicine details
        $itemStmt = $this->pdo->prepare("
            SELECT si.*, m.medicine_name, m.generic_name, m.hsn_code, m.manufacturer, m.dosage_form as med_dosage_form,
                   m.pack_size as med_pack_size, m.unit as med_unit, m.price as med_mrp
            FROM pharmacy_sale_items si
            JOIN medicines m ON si.medicine_id = m.medicine_id
            WHERE si.sale_id = ?
            ORDER BY si.sale_item_id ASC
        ");
        $itemStmt->execute([$saleId]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch batch allocations for items
        $batchStmt = $this->pdo->prepare("
            SELECT b.*, mb.mrp as batch_mrp, mb.sale_price as batch_sale_price
            FROM pharmacy_sale_item_batches b
            LEFT JOIN medicine_batches mb ON b.batch_id = mb.batch_id
            WHERE b.sale_id = ?
            ORDER BY b.id ASC
        ");
        $batchStmt->execute([$saleId]);
        $allBatches = $batchStmt->fetchAll(PDO::FETCH_ASSOC);

        $batchesByItem = [];
        foreach ($allBatches as $b) {
            $batchesByItem[$b['sale_item_id']][] = $b;
        }

        foreach ($items as &$it) {
            $it['batches'] = $batchesByItem[$it['sale_item_id']] ?? [];
        }
        $sale['items'] = $items;

        // Fetch payments
        $payStmt = $this->pdo->prepare("
            SELECT * FROM pharmacy_sale_payments
            WHERE sale_id = ?
            ORDER BY payment_id ASC
        ");
        $payStmt->execute([$saleId]);
        $sale['payments'] = $payStmt->fetchAll(PDO::FETCH_ASSOC);

        return $sale;
    }

    /**
     * Filterable list of sales for registries and monitoring dashboard.
     */
    public function listSales(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ["1=1"];
        $params = [];

        if (!empty($filters['sale_type'])) {
            $where[] = "s.sale_type = ?";
            $params[] = $filters['sale_type'];
        }

        if (!empty($filters['payment_status'])) {
            if ($filters['payment_status'] === 'OVERPAID' || $filters['payment_status'] === 'OVERPAYMENT_DUE') {
                $where[] = "(s.paid_amount > s.grand_total AND s.status != 'CANCELLED')";
            } else {
                $where[] = "s.payment_status = ?";
                $params[] = $filters['payment_status'];
            }
        }

        if (!empty($filters['overpayment_due'])) {
            $where[] = "(s.paid_amount > s.grand_total AND s.status != 'CANCELLED')";
        }

        if (!empty($filters['status'])) {
            $where[] = "s.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['start_date'])) {
            $where[] = "s.sale_date >= ?";
            $params[] = $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $where[] = "s.sale_date <= ?";
            $params[] = $filters['end_date'];
        }

        if (!empty($filters['search'])) {
            $search = '%' . trim($filters['search']) . '%';
            $where[] = "(s.sale_number LIKE ? OR s.customer_name LIKE ? OR s.customer_mobile LIKE ?)";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $whereSql = implode(' AND ', $where);
        $sql = "
            SELECT s.*, u.full_name as cashier_name,
                   (SELECT COUNT(*) FROM pharmacy_sale_items WHERE sale_id = s.sale_id) as items_count
            FROM pharmacy_sales s
            LEFT JOIN pharmacy_users u ON s.created_by = u.id
            WHERE {$whereSql}
            ORDER BY s.sale_id DESC
            LIMIT {$limit} OFFSET {$offset}
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update prescription items dispensed count and status.
     */
    private function updatePrescriptionDispensingStatus(int $prescriptionId, array $processedItems): void
    {
        foreach ($processedItems as $pItem) {
            $medId = (int)$pItem['medicine_id'];
            $qty = (int)$pItem['quantity'];

            $updItem = $this->pdo->prepare("
                UPDATE pharmacy_prescription_items
                SET dispensed_qty = dispensed_qty + ?, updated_at = NOW()
                WHERE prescription_id = ? AND medicine_id = ?
            ");
            $updItem->execute([$qty, $prescriptionId, $medId]);
        }

        // Check if all items in the prescription are fully dispensed
        $rxItemsStmt = $this->pdo->prepare("
            SELECT prescribed_qty, dispensed_qty
            FROM pharmacy_prescription_items
            WHERE prescription_id = ?
        ");
        $rxItemsStmt->execute([$prescriptionId]);
        $rxItems = $rxItemsStmt->fetchAll(PDO::FETCH_ASSOC);

        $allFulfilled = true;
        $anyDispensed = false;

        foreach ($rxItems as $rxi) {
            if ($rxi['dispensed_qty'] < $rxi['prescribed_qty']) {
                $allFulfilled = false;
            }
            if ($rxi['dispensed_qty'] > 0) {
                $anyDispensed = true;
            }
        }

        $newRxStatus = 'PENDING';
        if ($allFulfilled) {
            $newRxStatus = 'DISPENSED';
        } elseif ($anyDispensed) {
            $newRxStatus = 'PARTIALLY_DISPENSED';
        }

        $updRx = $this->pdo->prepare("
            UPDATE pharmacy_prescriptions
            SET status = ?, updated_at = NOW()
            WHERE prescription_id = ?
        ");
        $updRx->execute([$newRxStatus, $prescriptionId]);

        // Bidirectional Interdependence: Update Hospital Doctor's Prescriptions (prescriptions & prescription_items)
        $hospConn = $this->hospitalPdo;
        if ($hospConn) {
            try {
                $hRxChk = $hospConn->prepare("SELECT prescription_id, status FROM prescriptions WHERE prescription_id = ?");
                $hRxChk->execute([$prescriptionId]);
                if ($hRxChk->fetchColumn()) {
                    foreach ($processedItems as $pItem) {
                        $medId = (int)$pItem['medicine_id'];
                        $qty = (int)$pItem['quantity'];

                        $updHItem = $hospConn->prepare("
                            UPDATE prescription_items
                            SET dispensed_quantity = dispensed_quantity + ?,
                                status = CASE WHEN (dispensed_quantity + ?) >= quantity THEN 'Fully Dispensed' ELSE 'Partially Dispensed' END
                            WHERE prescription_id = ? AND medicine_id = ?
                        ");
                        $updHItem->execute([$qty, $qty, $prescriptionId, $medId]);
                    }

                    $hItemsStmt = $hospConn->prepare("SELECT quantity, dispensed_quantity FROM prescription_items WHERE prescription_id = ?");
                    $hItemsStmt->execute([$prescriptionId]);
                    $hItems = $hItemsStmt->fetchAll(PDO::FETCH_ASSOC);

                    $allDone = true;
                    $anyDone = false;
                    foreach ($hItems as $hi) {
                        if ((int)$hi['dispensed_quantity'] < (int)$hi['quantity']) {
                            $allDone = false;
                        }
                        if ((int)$hi['dispensed_quantity'] > 0) {
                            $anyDone = true;
                        }
                    }

                    $hStatus = 'Active';
                    if ($allDone && !empty($hItems)) {
                        $hStatus = 'Fully Dispensed';
                    } elseif ($anyDone) {
                        $hStatus = 'Partially Dispensed';
                    }

                    $updHrx = $hospConn->prepare("UPDATE prescriptions SET status = ? WHERE prescription_id = ?");
                    $updHrx->execute([$hStatus, $prescriptionId]);
                }
            } catch (Exception $e) {
                error_log("Hospital Prescription sync note: " . $e->getMessage());
            }
        }
    }

    /**
     * Pass pharmacy medication charges to Main MediPro central billing.
     * Creates an approved pending bill in `bills` and itemized entries in `bill_items`.
     */
    private function passSaleToHospitalBilling(
        int $saleId,
        int $hospitalPatientId,
        ?int $admissionId,
        ?int $visitId,
        string $saleNumber,
        float $subtotalAmount,
        float $totalDiscountAmount,
        float $headerDiscountPercent,
        float $totalGst,
        float $grandTotal,
        array $processedItems,
        ?int $userId,
        float $paidAmount = 0.00,
        float $balanceAmount = 0.00,
        string $paymentStatus = 'Pending',
        string $paymentMode = 'Hospital Billing'
    ): int {
        $hospConn = $this->hospitalPdo;
        if (!$hospConn) {
            return 0;
        }

        try {
            // 1. Create central bill in `bills` with accurate payment details
            // so central cashier in MediPro can view either paid receipt or collect outstanding amount upon patient discharge.
            $gstPercent = $subtotalAmount > 0 ? round(($totalGst / $subtotalAmount) * 100, 2) : 0.0;
            
            $insBill = $hospConn->prepare("
                INSERT INTO bills (
                    receipt_no, original_receipt_number, current_receipt_number,
                    patient_id, visit_id, bill_type,
                    total_amount, discount_amount, discount_percent, gst_percent, tax_amount,
                    patient_payable, paid_amount, balance_amount, payment_status, payment_mode,
                    generated_by, bill_date, status, receipt_type, created_at
                ) VALUES (
                    ?, ?, ?,
                    ?, ?, 'Pharmacy',
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, CURDATE(), 'Approved', 'Normal', NOW()
                )
            ");
            $insBill->execute([
                $saleNumber,
                $saleNumber,
                $saleNumber,
                $hospitalPatientId,
                $visitId,
                $grandTotal,
                $totalDiscountAmount,
                $headerDiscountPercent,
                $gstPercent,
                $totalGst,
                $grandTotal,
                $paidAmount,
                $balanceAmount,
                $paymentStatus,
                $paymentMode,
                $userId ?: 1
            ]);
            $billId = (int)$hospConn->lastInsertId();

            // 2. Insert itemized charges into `bill_items`
            $insItem = $hospConn->prepare("
                INSERT INTO bill_items (
                    bill_id, charge_category, item_name, quantity, unit, rate,
                    discount, tax_percent, tax_amount, total_amount, amount,
                    order_ref_id, order_type, charge_date
                ) VALUES (
                    ?, 'Pharmacy', ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, 'pharmacy', CURDATE()
                )
            ");

            foreach ($processedItems as $pItem) {
                $mStmt = $this->pdo->prepare("SELECT medicine_name, pack_size FROM medicines WHERE medicine_id = ?");
                $mStmt->execute([$pItem['medicine_id']]);
                $mRow = $mStmt->fetch(PDO::FETCH_ASSOC);
                $medName = $mRow['medicine_name'] ?? ('Medicine #' . $pItem['medicine_id']);
                $packSize = $mRow['pack_size'] ?? ($pItem['pack_size'] ?? 'Unit');

                // Include batch details if allocated
                $batchDetails = [];
                if (!empty($pItem['allocations'])) {
                    foreach ($pItem['allocations'] as $alloc) {
                        $bNum = $alloc['batch_number'] ?? '';
                        $exp = !empty($alloc['expiry_date']) ? date('m/y', strtotime($alloc['expiry_date'])) : '';
                        if ($bNum) {
                            $batchDetails[] = "Batch: {$bNum}" . ($exp ? " (Exp: {$exp})" : "");
                        }
                    }
                }
                $displayName = $medName . (!empty($batchDetails) ? ' [' . implode(', ', $batchDetails) . ']' : '');

                $insItem->execute([
                    $billId,
                    $displayName,
                    $pItem['quantity'],
                    $packSize,
                    $pItem['unit_price'],
                    $pItem['discount_amount'],
                    $pItem['gst_percent'],
                    $pItem['gst_amount'],
                    $pItem['line_total'],
                    $pItem['taxable_amount'],
                    $saleId
                ]);
            }

            return $billId;
        } catch (Exception $e) {
            error_log("Hospital Billing Sync Error (non-fatal): " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Archive an edited bill snapshot into pharmacy_bill_edit_history with 30-day retention.
     */
    public function recordBillEditHistory(int $oldSaleId, int $newSaleId, string $editReason = 'Medicines modified in POS', ?int $userId = null): int
    {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM pharmacy_sales WHERE sale_id = ?");
            $stmt->execute([$oldSaleId]);
            $oldSale = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$oldSale) return 0;

            $stmtNew = $this->pdo->prepare("SELECT sale_number FROM pharmacy_sales WHERE sale_id = ?");
            $stmtNew->execute([$newSaleId]);
            $newSaleNumber = $stmtNew->fetchColumn() ?: null;

            // Fetch old sale items snapshot
            $stmtItems = $this->pdo->prepare("
                SELECT si.sale_item_id, si.medicine_id, m.medicine_name, m.generic_name, m.composition,
                       si.dosage_form, si.pack_size, si.quantity, si.unit_price, si.mrp,
                       si.discount_percent, si.discount_amount, si.taxable_amount,
                       si.gst_percent, si.gst_amount, si.line_total,
                       COALESCE((SELECT batch_number FROM pharmacy_sale_item_batches WHERE sale_item_id = si.sale_item_id LIMIT 1), 'GEN-01') as batch_number,
                       COALESCE((SELECT expiry_date FROM pharmacy_sale_item_batches WHERE sale_item_id = si.sale_item_id LIMIT 1), '') as expiry_date
                FROM pharmacy_sale_items si
                JOIN medicines m ON si.medicine_id = m.medicine_id
                WHERE si.sale_id = ?
            ");
            $stmtItems->execute([$oldSaleId]);
            $itemsSnapshot = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            // Determine root original sale number
            $rootSaleNumber = $oldSale['sale_number'];
            if (preg_match('/\[EDITED:\s*Replaced Bill #([^\]]+)\]/i', $oldSale['notes'] ?? '', $m)) {
                $rootSaleNumber = trim($m[1]);
            }

            // Calculate version number
            $vStmt = $this->pdo->prepare("SELECT COUNT(*) FROM pharmacy_bill_edit_history WHERE original_sale_number = ?");
            $vStmt->execute([$rootSaleNumber]);
            $existingCount = (int)$vStmt->fetchColumn();
            $versionNumber = $existingCount + 1;

            // Fetch editor user name
            $userName = 'System / Cashier';
            if ($userId) {
                $uStmt = $this->pdo->prepare("SELECT full_name FROM pharmacy_users WHERE id = ?");
                $uStmt->execute([$userId]);
                $userName = $uStmt->fetchColumn() ?: 'Staff #' . $userId;
            }

            $ins = $this->pdo->prepare("
                INSERT INTO pharmacy_bill_edit_history (
                    original_sale_id, original_sale_number, new_sale_id, new_sale_number,
                    version_number, patient_id, customer_name, customer_mobile, doctor_name,
                    subtotal_amount, discount_amount, gst_amount, grand_total, paid_amount,
                    payment_mode, notes, items_snapshot, edited_by, edited_by_name,
                    edit_reason, created_at, retention_until
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY)
                )
            ");

            $ins->execute([
                $oldSaleId,
                $rootSaleNumber,
                $newSaleId,
                $newSaleNumber,
                $versionNumber,
                $oldSale['patient_id'],
                $oldSale['customer_name'],
                $oldSale['customer_mobile'],
                $oldSale['doctor_name'],
                $oldSale['subtotal_amount'],
                $oldSale['discount_amount'],
                $oldSale['gst_amount'],
                $oldSale['grand_total'],
                $oldSale['paid_amount'],
                $oldSale['payment_mode'],
                $oldSale['notes'],
                json_encode($itemsSnapshot, JSON_UNESCAPED_UNICODE),
                $userId,
                $userName,
                $editReason
            ]);

            return (int)$this->pdo->lastInsertId();
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Get complete 30-day bill edit history versions for a given sale.
     */
    public function getBillEditHistory(int $saleId): array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT sale_number, notes FROM pharmacy_sales WHERE sale_id = ?");
            $stmt->execute([$saleId]);
            $currSale = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$currSale) return [];

            $rootSaleNumber = $currSale['sale_number'];
            if (preg_match('/\[EDITED:\s*Replaced Bill #([^\]]+)\]/i', $currSale['notes'] ?? '', $m)) {
                $rootSaleNumber = trim($m[1]);
            }

            $hStmt = $this->pdo->prepare("
                SELECT *,
                       DATEDIFF(retention_until, NOW()) as days_remaining
                FROM pharmacy_bill_edit_history
                WHERE (original_sale_number = ? OR original_sale_id = ? OR new_sale_id = ?)
                  AND retention_until >= NOW()
                ORDER BY version_number ASC, id ASC
            ");
            $hStmt->execute([$rootSaleNumber, $saleId, $saleId]);
            $histories = $hStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($histories as &$h) {
                $h['items'] = json_decode($h['items_snapshot'] ?? '[]', true) ?: [];
            }
            return $histories;
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Purge historical bill edit snapshots older than the retention threshold.
     */
    public function purgeExpiredEditHistories(int $retentionDays = 30): int
    {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM pharmacy_bill_edit_history WHERE retention_until < NOW()");
            $stmt->execute();
            return $stmt->rowCount();
        } catch (Exception $e) {
            return 0;
        }
    }
}

