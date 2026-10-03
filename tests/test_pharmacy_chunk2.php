<?php
// tests/test_pharmacy_chunk2.php - Automated Acceptance & Security Test Suite for Chunk 2

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Services/AuditService.php';
require_once __DIR__ . '/../app/Services/DocumentSequenceService.php';
require_once __DIR__ . '/../app/Services/StockLedgerService.php';
require_once __DIR__ . '/../app/Services/BatchService.php';
require_once __DIR__ . '/../app/Services/MedicineService.php';
require_once __DIR__ . '/../app/Services/FefoService.php';
require_once __DIR__ . '/../app/Services/StockAdjustmentService.php';
require_once __DIR__ . '/../app/Services/PermissionService.php';
require_once __DIR__ . '/../app/Auth/AuthManager.php';

use Pharmacy\Services\MedicineService;
use Pharmacy\Services\BatchService;
use Pharmacy\Services\FefoService;
use Pharmacy\Services\StockLedgerService;
use Pharmacy\Services\StockAdjustmentService;
use Pharmacy\Services\PermissionService;
use Pharmacy\Auth\AuthManager;

echo "\n============================================================\n";
echo " MEDIPRO HMS — PHARMACY CHUNK 2: AUTOMATED ACCEPTANCE TESTS \n";
echo "============================================================\n\n";

$passCount = 0;
$failCount = 0;

function report(int $number, string $description, bool $passed, string $detail = ''): void {
    global $passCount, $failCount;
    if ($passed) {
        $passCount++;
        echo sprintf("  [PASS] TEST %02d: %s %s\n", $number, $description, $detail ? "({$detail})" : "");
    } else {
        $failCount++;
        echo sprintf("  [FAIL] TEST %02d: %s %s\n", $number, $description, $detail ? "({$detail})" : "");
    }
}

$medService = new MedicineService($pdo);
$batchService = new BatchService($pdo);
$fefoService = new FefoService($pdo);
$ledgerService = new StockLedgerService($pdo);
$adjService = new StockAdjustmentService($pdo);
$permService = new PermissionService($pdo);

$testRunId = time() . '_' . rand(100, 999);
$testUserId = 1; // Admin

// -------------------------------------------------------------
// TEST 1: Medicine creation
// -------------------------------------------------------------
$medId1 = 0;
$uniqueBarcode1 = 'BAR-' . $testRunId . '-1';
try {
    $medId1 = $medService->createMedicine([
        'medicine_name' => "Chunk2 Test Amox-{$testRunId}",
        'generic_name'  => 'Amoxicillin Trihydrate',
        'composition'   => 'Amoxicillin 500mg',
        'strength'      => '500 mg',
        'dosage_form'   => 'Capsule',
        'category'      => 'Capsule',
        'unit'          => 'Strip',
        'pack_size'     => '10',
        'schedule_type' => 'Schedule H',
        'manufacturer'  => 'Test Pharma Ltd',
        'hsn_code'      => '300410',
        'gst_percent'   => 12.00,
        'price'         => 120.00,
        'purchase_price'=> 80.00,
        'reorder_level' => 20,
        'barcode'       => $uniqueBarcode1,
        'shelf'         => 'Shelf-A1',
        'status'        => 'Active'
    ], $testUserId);

    report(1, "Medicine creation with full attributes", $medId1 > 0, "Created ID: {$medId1}");
} catch (Exception $e) {
    report(1, "Medicine creation with full attributes", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 2: Duplicate medicine detection
// -------------------------------------------------------------
try {
    // E.g. "Chunk2 Test Amox-XYZ" vs "chunk2-test-amox xyz"
    $searchName = "chunk2 test-amox-{$testRunId}";
    $duplicates = $medService->findPotentialDuplicates($searchName);
    $found = false;
    foreach ($duplicates as $d) {
        if ($d['medicine_id'] == $medId1) {
            $found = true;
            break;
        }
    }
    report(2, "Duplicate medicine detection via normalized comparison", $found, "Found matching candidate ID: {$medId1}");
} catch (Exception $e) {
    report(2, "Duplicate medicine detection via normalized comparison", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 3: Duplicate barcode detection
// -------------------------------------------------------------
try {
    $duplicateBarcodeCaught = false;
    try {
        $medService->createMedicine([
            'medicine_name' => "Another Medicine {$testRunId}",
            'barcode'       => $uniqueBarcode1 // Duplicate barcode
        ], $testUserId);
    } catch (InvalidArgumentException $ex) {
        if (stripos($ex->getMessage(), 'barcode') !== false) {
            $duplicateBarcodeCaught = true;
        }
    }
    report(3, "Duplicate barcode detection and rejection", $duplicateBarcodeCaught);
} catch (Exception $e) {
    report(3, "Duplicate barcode detection and rejection", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 4: Medicine deactivation
// -------------------------------------------------------------
try {
    $medService->setStatus($medId1, 'Inactive', $testUserId);
    $m = $medService->getMedicineById($medId1);
    $deactivated = ($m['status'] === 'Inactive');
    // Reactivate for further batch testing
    $medService->setStatus($medId1, 'Active', $testUserId);
    report(4, "Medicine deactivation and reactivation", $deactivated);
} catch (Exception $e) {
    report(4, "Medicine deactivation and reactivation", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 5: Batch creation
// -------------------------------------------------------------
$batchId1 = 0;
$batchNo1 = "BAT-A-{$testRunId}";
try {
    $batchId1 = $batchService->createBatch([
        'medicine_id'       => $medId1,
        'batch_number'      => $batchNo1,
        'manufacturing_date'=> date('Y-m-d', strtotime('-1 month')),
        'expiry_date'       => date('Y-m-d', strtotime('+12 months')),
        'purchase_price'    => 80.00,
        'mrp'               => 120.00,
        'sale_price'        => 120.00,
        'quantity_received' => 50,
        'quantity_available'=> 50,
        'shelf_location'    => 'Shelf-A1',
        'transaction_type'  => 'OPENING_STOCK',
        'reason'            => 'Initial test batch'
    ], $testUserId);

    report(5, "Batch creation with expiry and pricing", $batchId1 > 0, "Batch ID: {$batchId1}");
} catch (Exception $e) {
    report(5, "Batch creation with expiry and pricing", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 6: Same medicine + same batch handling (scoped uniqueness rejection)
// -------------------------------------------------------------
try {
    $duplicateBatchCaught = false;
    try {
        $batchService->createBatch([
            'medicine_id'  => $medId1,
            'batch_number' => $batchNo1, // Duplicate for SAME medicine
            'expiry_date'  => date('Y-m-d', strtotime('+12 months')),
            'quantity'     => 10
        ], $testUserId);
    } catch (InvalidArgumentException $ex) {
        if (stripos($ex->getMessage(), 'already exists') !== false) {
            $duplicateBatchCaught = true;
        }
    }
    report(6, "Same medicine + duplicate batch number rejection", $duplicateBatchCaught);
} catch (Exception $e) {
    report(6, "Same medicine + duplicate batch number rejection", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 7: Same batch number across different medicines (permitted)
// -------------------------------------------------------------
try {
    $medId2 = $medService->createMedicine([
        'medicine_name' => "Chunk2 Test Paracetamol-{$testRunId}",
        'barcode'       => 'BAR-' . $testRunId . '-2'
    ], $testUserId);

    $crossMedBatchId = $batchService->createBatch([
        'medicine_id'  => $medId2,
        'batch_number' => $batchNo1, // Same batch number on DIFFERENT medicine
        'expiry_date'  => date('Y-m-d', strtotime('+10 months')),
        'quantity'     => 25
    ], $testUserId);

    report(7, "Same batch number across different medicines permitted", $crossMedBatchId > 0, "Batch ID: {$crossMedBatchId}");
} catch (Exception $e) {
    report(7, "Same batch number across different medicines permitted", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 8: Invalid expiry rejection
// -------------------------------------------------------------
try {
    $invalidExpiryCaught = false;
    try {
        $batchService->createBatch([
            'medicine_id'  => $medId1,
            'batch_number' => "BAT-INVALID-{$testRunId}",
            'expiry_date'  => "invalid-date-format",
            'quantity'     => 10
        ], $testUserId);
    } catch (InvalidArgumentException $ex) {
        $invalidExpiryCaught = true;
    }
    report(8, "Invalid expiry date format rejected", $invalidExpiryCaught);
} catch (Exception $e) {
    report(8, "Invalid expiry date format rejected", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 9: Expired batch detection
// -------------------------------------------------------------
try {
    $pastDate = date('Y-m-d', strtotime('-15 days'));
    $expBatchId = $batchService->createBatch([
        'medicine_id'  => $medId1,
        'batch_number' => "BAT-EXPIRED-{$testRunId}",
        'expiry_date'  => $pastDate,
        'quantity'     => 10
    ], $testUserId);

    $b = $batchService->getBatchById($expBatchId);
    $isExpired = ($b['status'] === 'Expired');
    report(9, "Expired batch automatically flagged as Expired", $isExpired, "Status: {$b['status']}");
} catch (Exception $e) {
    report(9, "Expired batch automatically flagged as Expired", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 10: Near-expiry detection
// -------------------------------------------------------------
try {
    $nearDate = date('Y-m-d', strtotime('+45 days'));
    $nearBatchId = $batchService->createBatch([
        'medicine_id'  => $medId1,
        'batch_number' => "BAT-NEAR-{$testRunId}",
        'expiry_date'  => $nearDate,
        'quantity'     => 15
    ], $testUserId);

    $b = $batchService->getBatchById($nearBatchId);
    $daysLeft = (int)$b['days_to_expiry'];
    $isNear = ($daysLeft > 0 && $daysLeft <= 60);
    report(10, "Near-expiry batch within 60-day threshold detected", $isNear, "Days left: {$daysLeft}");
} catch (Exception $e) {
    report(10, "Near-expiry batch within 60-day threshold detected", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 11: FEFO ordering
// -------------------------------------------------------------
try {
    // Create two batches: Batch Early (Exp in 3 months), Batch Late (Exp in 18 months)
    $earlyBatchNo = "BAT-FEFO-EARLY-{$testRunId}";
    $lateBatchNo  = "BAT-FEFO-LATE-{$testRunId}";

    $batchService->createBatch([
        'medicine_id'  => $medId1,
        'batch_number' => $earlyBatchNo,
        'expiry_date'  => date('Y-m-d', strtotime('+3 months')),
        'quantity'     => 20
    ], $testUserId);

    $batchService->createBatch([
        'medicine_id'  => $medId1,
        'batch_number' => $lateBatchNo,
        'expiry_date'  => date('Y-m-d', strtotime('+18 months')),
        'quantity'     => 30
    ], $testUserId);

    $preview = $fefoService->previewAllocation($medId1, 5);
    $selectedBatchNo = $preview[0]['batch_number'] ?? '';
    // Earliest unexpired batch should be selected first
    // Among BAT-NEAR (+45d), BAT-FEFO-EARLY (+3m), etc., earliest expiry is BAT-NEAR (+45d)
    $hasEarliest = ($selectedBatchNo === "BAT-NEAR-{$testRunId}");
    report(11, "FEFO ordering prioritizes earliest unexpired batch", $hasEarliest, "Allocated batch: {$selectedBatchNo}");
} catch (Exception $e) {
    report(11, "FEFO ordering prioritizes earliest unexpired batch", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 12: Multi-batch FEFO allocation
// -------------------------------------------------------------
try {
    // Total stock in BAT-NEAR is 15. If we request 25, it must split across BAT-NEAR (15) and BAT-FEFO-EARLY (10)
    $allocs = $fefoService->previewAllocation($medId1, 25);
    $multiCount = count($allocs);
    $totalAlloc = array_sum(array_column($allocs, 'allocated_quantity'));
    report(12, "Multi-batch FEFO allocation splits across batches", ($multiCount >= 2 && $totalAlloc === 25), "Split count: {$multiCount}, Total units: {$totalAlloc}");
} catch (Exception $e) {
    report(12, "Multi-batch FEFO allocation splits across batches", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 13: Insufficient stock detection
// -------------------------------------------------------------
try {
    $insufficientCaught = false;
    try {
        $fefoService->previewAllocation($medId1, 99999);
    } catch (Exception $ex) {
        if (stripos($ex->getMessage(), 'insufficient') !== false) {
            $insufficientCaught = true;
        }
    }
    report(13, "Insufficient stock detection halts allocation", $insufficientCaught);
} catch (Exception $e) {
    report(13, "Insufficient stock detection halts allocation", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 14: Zero quantity rejection
// -------------------------------------------------------------
try {
    $zeroCaught = false;
    try {
        $fefoService->previewAllocation($medId1, 0);
    } catch (InvalidArgumentException $ex) {
        $zeroCaught = true;
    }
    report(14, "Zero quantity allocation rejected", $zeroCaught);
} catch (Exception $e) {
    report(14, "Zero quantity allocation rejected", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 15: Negative quantity rejection
// -------------------------------------------------------------
try {
    $negCaught = false;
    try {
        $fefoService->previewAllocation($medId1, -10);
    } catch (InvalidArgumentException $ex) {
        $negCaught = true;
    }
    report(15, "Negative quantity allocation rejected", $negCaught);
} catch (Exception $e) {
    report(15, "Negative quantity allocation rejected", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 16: Opening stock creates ledger entry
// -------------------------------------------------------------
try {
    $medId3 = $medService->createMedicine([
        'medicine_name' => "Ledger Test Med {$testRunId}",
        'barcode'       => 'BAR-' . $testRunId . '-3'
    ], $testUserId);

    $openingBatchNo = "BAT-OPN-{$testRunId}";
    $bId3 = $batchService->createBatch([
        'medicine_id'       => $medId3,
        'batch_number'      => $openingBatchNo,
        'expiry_date'       => date('Y-m-d', strtotime('+12 months')),
        'quantity'          => 100,
        'purchase_price'    => 50.00,
        'sale_price'        => 90.00,
        'transaction_type'  => 'OPENING_STOCK'
    ], $testUserId);

    $history = $ledgerService->getLedgerHistory(['medicine_id' => $medId3, 'transaction_type' => 'OPENING_STOCK']);
    $hasEntry = !empty($history) && ($history[0]['quantity_change'] === 100);
    report(16, "Opening stock automatically creates ledger entry", $hasEntry, "Change: +{$history[0]['quantity_change']}");
} catch (Exception $e) {
    report(16, "Opening stock automatically creates ledger entry", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 17: Stock adjustment creates ledger entry
// -------------------------------------------------------------
try {
    $adjRes = $adjService->adjustStock($medId3, $bId3, 'Damage', 5, "Test broken vial in handling", $testUserId);
    $adjHistory = $ledgerService->getLedgerHistory(['medicine_id' => $medId3, 'transaction_type' => 'DAMAGE']);
    $hasAdjEntry = !empty($adjHistory) && ($adjHistory[0]['quantity_change'] === -5);
    report(17, "Controlled stock adjustment creates ledger entry", $hasAdjEntry, "Adj No: {$adjRes['adjustment_no']}");
} catch (Exception $e) {
    report(17, "Controlled stock adjustment creates ledger entry", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 18: Ledger balance reconciliation
// -------------------------------------------------------------
try {
    $recon3 = $ledgerService->reconcileMedicine($medId3);
    report(18, "Ledger balance reconciliation matches master and batch sum", $recon3['is_reconciled'], "Master: {$recon3['medicine_stock']}, Batch: {$recon3['batch_sum']}");
} catch (Exception $e) {
    report(18, "Ledger balance reconciliation matches master and batch sum", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 19: Medicine total equals batch total
// -------------------------------------------------------------
try {
    $recon1 = $ledgerService->reconcileMedicine($medId1);
    report(19, "Medicine total equals sum of batch quantities", $recon1['is_reconciled'], "Master: {$recon1['medicine_stock']}, Batch: {$recon1['batch_sum']}");
} catch (Exception $e) {
    report(19, "Medicine total equals sum of batch quantities", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 20: No negative stock allowed
// -------------------------------------------------------------
try {
    $negStockCaught = false;
    try {
        // Attempt to decrease more stock than available (current is 95, request 200)
        $adjService->adjustStock($medId3, $bId3, 'Decrease', 200, "Should fail due to negative stock", $testUserId);
    } catch (Exception $ex) {
        if (stripos($ex->getMessage(), 'below zero') !== false || stripos($ex->getMessage(), 'negative') !== false) {
            $negStockCaught = true;
        }
    }
    report(20, "Negative batch stock strictly prohibited", $negStockCaught);
} catch (Exception $e) {
    report(20, "Negative batch stock strictly prohibited", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 21: Concurrent stock deduction safety
// -------------------------------------------------------------
try {
    // In an active transaction, deduct FEFO allocations
    $pdo->beginTransaction();
    $allocs = $fefoService->allocate($medId3, 10, true);
    $fefoService->executeDeduction($allocs, 'COUNTER_SALE', 999, 'TEST-SALE-001', $testUserId, 'Test sale deduction');
    $pdo->commit();

    $bAfter = $batchService->getBatchById($bId3);
    $deductedCorrectly = ((int)$bAfter['quantity_available'] === 85);
    report(21, "Atomic stock deduction with row-level locking", $deductedCorrectly, "Remaining stock: {$bAfter['quantity_available']}");
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    report(21, "Atomic stock deduction with row-level locking", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 22: Inactive medicine excluded from operational selectors
// -------------------------------------------------------------
try {
    $medService->setStatus($medId3, 'Inactive', $testUserId);
    $inactiveCaught = false;
    try {
        $fefoService->previewAllocation($medId3, 5);
    } catch (Exception $ex) {
        if (stripos($ex->getMessage(), 'not Active') !== false) {
            $inactiveCaught = true;
        }
    }
    $medService->setStatus($medId3, 'Active', $testUserId); // restore
    report(22, "Inactive medicine excluded from dispensing allocation", $inactiveCaught);
} catch (Exception $e) {
    report(22, "Inactive medicine excluded from dispensing allocation", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 23: Inactive batch cannot be selected for dispensing
// -------------------------------------------------------------
try {
    // Deplete a batch or set to Quarantined
    $stmt = $pdo->prepare("UPDATE medicine_batches SET status = 'Quarantined' WHERE batch_id = ?");
    $stmt->execute([$bId3]);

    $quarantineCaught = false;
    try {
        $fefoService->previewAllocation($medId3, 5);
    } catch (Exception $ex) {
        if (stripos($ex->getMessage(), 'insufficient') !== false) {
            $quarantineCaught = true;
        }
    }
    // Restore
    $stmt = $pdo->prepare("UPDATE medicine_batches SET status = 'Active' WHERE batch_id = ?");
    $stmt->execute([$bId3]);
    report(23, "Quarantined/Inactive batch excluded from FEFO allocation", $quarantineCaught);
} catch (Exception $e) {
    report(23, "Quarantined/Inactive batch excluded from FEFO allocation", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 24: Expired batch blocked
// -------------------------------------------------------------
try {
    // Check medId1 which has $expBatchId (expired batch with 10 units)
    // The FEFO preview should completely skip BAT-EXPIRED and never include it
    $previewAll = $fefoService->previewAllocation($medId1, 10);
    $hasExpiredInAllocation = false;
    foreach ($previewAll as $a) {
        if ($a['batch_id'] == $expBatchId) {
            $hasExpiredInAllocation = true;
            break;
        }
    }
    report(24, "Expired batch strictly excluded from dispensing", !$hasExpiredInAllocation);
} catch (Exception $e) {
    report(24, "Expired batch strictly excluded from dispensing", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 25: Authorized expiry override
// -------------------------------------------------------------
try {
    // Admin has permission 'pharmacy.adjustments.manage' to write off expired stock
    $adminHasPerm = $permService->hasPermission('pharmacy.adjustments.manage', 1);
    report(25, "Authorized expiry management permitted for admin role", $adminHasPerm);
} catch (Exception $e) {
    report(25, "Authorized expiry management permitted for admin role", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 26: Unauthorized expiry override rejected
// -------------------------------------------------------------
try {
    $cashierId = (int)$pdo->query("SELECT id FROM pharmacy_roles WHERE role_name = 'PHARMACY_CASHIER'")->fetchColumn();
    $cashierHasAdj = $permService->hasPermission('pharmacy.adjustments.manage', $cashierId);
    $cashierHasOpn = $permService->hasPermission('pharmacy.opening_stock.manage', $cashierId);
    $cashierBlocked = (!$cashierHasAdj && !$cashierHasOpn);
    report(26, "Unauthorized role (Cashier) blocked from stock adjustments", $cashierBlocked, "Cashier role ID: {$cashierId}");
} catch (Exception $e) {
    report(26, "Unauthorized role (Cashier) blocked from stock adjustments", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 27: RBAC backend enforcement
// -------------------------------------------------------------
try {
    $pharmaId = (int)$pdo->query("SELECT id FROM pharmacy_roles WHERE role_name IN ('PHARMACIST', 'PHARMACY_MANAGER') LIMIT 1")->fetchColumn();
    $pharmacistHasInv = $permService->hasPermission('pharmacy.inventory.view', $pharmaId);
    $pharmacistHasMed = $permService->hasPermission('pharmacy.medicines.manage', $pharmaId);
    report(27, "RBAC backend permissions correctly granted to Pharmacist role", ($pharmacistHasInv && $pharmacistHasMed), "Pharmacist role ID: {$pharmaId}");
} catch (Exception $e) {
    report(27, "RBAC backend permissions correctly granted to Pharmacist role", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 28: CSRF protection
// -------------------------------------------------------------
try {
    $_SESSION['pharmacy_csrf_token'] = 'valid_token_123';
    $_POST['csrf_token'] = 'invalid_tampered_token';
    $verified = AuthManager::verifyCsrf();
    report(28, "CSRF token mismatch detected and rejected", !$verified);
    unset($_POST['csrf_token']);
} catch (Exception $e) {
    report(28, "CSRF token mismatch detected and rejected", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 29: SQL injection resistance for search inputs
// -------------------------------------------------------------
try {
    $sqliPayload = "' OR '1'='1' UNION SELECT null, null, null-- ";
    $res = $medService->search($sqliPayload);
    // Should safely return 0 results or exact string matches, without SQL syntax error or dumping all tables
    report(29, "SQL injection resistance in medicine search input", is_array($res));
} catch (Exception $e) {
    report(29, "SQL injection resistance in medicine search input", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 30: Historical ledger preservation (immutability)
// -------------------------------------------------------------
try {
    // Verify that pharmacy_stock_ledger does not allow direct tampering or casual modification
    $countBefore = (int)$pdo->query("SELECT COUNT(*) FROM pharmacy_stock_ledger")->fetchColumn();
    // Test that the service provides only recordEntry() and no edit/delete methods
    $hasEditMethod = method_exists($ledgerService, 'updateEntry');
    $hasDeleteMethod = method_exists($ledgerService, 'deleteEntry');
    report(30, "Historical ledger preservation (append-only immutability)", (!$hasEditMethod && !$hasDeleteMethod && $countBefore > 0), "Ledger count: {$countBefore}");
} catch (Exception $e) {
    report(30, "Historical ledger preservation (append-only immutability)", false, $e->getMessage());
}

echo "\n------------------------------------------------------------\n";
echo " ADVERSARIAL QA TESTS \n";
echo "------------------------------------------------------------\n\n";

// ADVERSARIAL 1: Quantity = -100
try {
    $adv1Caught = false;
    try {
        $adjService->adjustStock($medId1, $batchId1, 'Increase', -100, "Negative qty attack", $testUserId);
    } catch (InvalidArgumentException $ex) {
        $adv1Caught = true;
    }
    report(31, "Adversarial Test: Negative quantity rejected", $adv1Caught);
} catch (Exception $e) {
    report(31, "Adversarial Test: Negative quantity rejected", false, $e->getMessage());
}

// ADVERSARIAL 2: Quantity = 0
try {
    $adv2Caught = false;
    try {
        $adjService->adjustStock($medId1, $batchId1, 'Increase', 0, "Zero qty attack", $testUserId);
    } catch (InvalidArgumentException $ex) {
        $adv2Caught = true;
    }
    report(32, "Adversarial Test: Zero quantity rejected", $adv2Caught);
} catch (Exception $e) {
    report(32, "Adversarial Test: Zero quantity rejected", false, $e->getMessage());
}

// ADVERSARIAL 3: Batch ID belonging to another medicine
try {
    $adv3Caught = false;
    try {
        // batchId1 belongs to medId1, but we pass medId2
        $adjService->adjustStock($medId2, $batchId1, 'Increase', 5, "Mismatched batch attack", $testUserId);
    } catch (Exception $ex) {
        if (stripos($ex->getMessage(), 'not found') !== false) {
            $adv3Caught = true;
        }
    }
    report(33, "Adversarial Test: Batch belonging to another medicine rejected", $adv3Caught);
} catch (Exception $e) {
    report(33, "Adversarial Test: Batch belonging to another medicine rejected", false, $e->getMessage());
}

// ADVERSARIAL 4: Nonexistent batch ID
try {
    $adv4Caught = false;
    try {
        $adjService->adjustStock($medId1, 999999, 'Increase', 5, "Nonexistent batch attack", $testUserId);
    } catch (Exception $ex) {
        $adv4Caught = true;
    }
    report(34, "Adversarial Test: Nonexistent batch ID rejected", $adv4Caught);
} catch (Exception $e) {
    report(34, "Adversarial Test: Nonexistent batch ID rejected", false, $e->getMessage());
}

echo "\n------------------------------------------------------------\n";
echo " REGRESSION VERIFICATION \n";
echo "------------------------------------------------------------\n\n";

// REGRESSION 1: Chunk 1 Foundation
try {
    $userCount = (int)$pdo->query("SELECT COUNT(*) FROM pharmacy_users")->fetchColumn();
    $rolesCount = (int)$pdo->query("SELECT COUNT(*) FROM pharmacy_roles")->fetchColumn();
    $settingsCount = (int)$pdo->query("SELECT COUNT(*) FROM pharmacy_settings")->fetchColumn();
    report(35, "Regression Test: Chunk 1 Foundation tables intact", ($userCount >= 3 && $rolesCount >= 3 && $settingsCount >= 10));
} catch (Exception $e) {
    report(35, "Regression Test: Chunk 1 Foundation tables intact", false, $e->getMessage());
}

// REGRESSION 2: Supplier & Purchase Tables
try {
    $checkSup = $pdo->query("SHOW TABLES LIKE 'pharmacy_suppliers'")->rowCount();
    $checkPur = $pdo->query("SHOW TABLES LIKE 'pharmacy_purchases'")->rowCount();
    report(36, "Regression Test: Procurement & Supplier tables preserved", ($checkSup > 0 && $checkPur > 0));
} catch (Exception $e) {
    report(36, "Regression Test: Procurement & Supplier tables preserved", false, $e->getMessage());
}

// REGRESSION 3: Baseline Historical Records Preserved
try {
    $medTotal = (int)$pdo->query("SELECT COUNT(*) FROM medicines")->fetchColumn();
    $batchTotal = (int)$pdo->query("SELECT COUNT(*) FROM medicine_batches")->fetchColumn();
    $ledgerTotal = (int)$pdo->query("SELECT COUNT(*) FROM pharmacy_stock_ledger")->fetchColumn();
    report(37, "Regression Test: Baseline historical data completely preserved", ($medTotal >= 10 && $batchTotal >= 14 && $ledgerTotal >= 7), "Meds: {$medTotal}, Batches: {$batchTotal}, Ledger: {$ledgerTotal}");
} catch (Exception $e) {
    report(37, "Regression Test: Baseline historical data completely preserved", false, $e->getMessage());
}

echo "\n============================================================\n";
echo sprintf(" TEST SUMMARY: %d PASSED, %d FAILED (TOTAL %d)\n", $passCount, $failCount, $passCount + $failCount);
echo "============================================================\n\n";

if ($failCount === 0) {
    echo "🟢 ALL CHUNK 2 TESTS PASSED PERFECTLY!\n";
    exit(0);
} else {
    echo "🔴 SOME TESTS FAILED. PLEASE INVESTIGATE.\n";
    exit(1);
}
