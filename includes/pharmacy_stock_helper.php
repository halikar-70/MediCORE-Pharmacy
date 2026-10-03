<?php
// includes/pharmacy_stock_helper.php
// MediPro HMS - Centralized Pharmacy Stock, Batch Management & FEFO Engine

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

/**
 * First Expiry, First Out (FEFO) Stock Allocator.
 * Locks candidate batches with SELECT ... FOR UPDATE, skips expired batches,
 * and allocates requested quantity across one or more batches in strict expiry order.
 *
 * @param PDO $pdo
 * @param int $medicine_id
 * @param int $required_qty
 * @return array Array of allocated batches: [['batch_id' => X, 'batch_number' => Y, 'quantity' => N, 'sale_price' => P, ...]]
 * @throws Exception If insufficient non-expired stock exists
 */
function fefo_allocate_stock(PDO $pdo, int $medicine_id, int $required_qty): array
{
    if ($required_qty <= 0) {
        throw new InvalidArgumentException("Allocation quantity must be greater than zero.");
    }

    // 1. Lock the master medicine record
    $med_stmt = $pdo->prepare("
        SELECT medicine_id, medicine_name, stock_quantity, status 
        FROM medicines 
        WHERE medicine_id = ? AND deleted_at IS NULL 
        FOR UPDATE
    ");
    $med_stmt->execute([$medicine_id]);
    $med = $med_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$med) {
        throw new Exception("Medicine #{$medicine_id} not found or has been archived.");
    }

    if ($med['status'] !== 'Active') {
        throw new Exception("Medicine '{$med['medicine_name']}' is discontinued and cannot be dispensed.");
    }

    // 2. Lock active, non-expired batches ordered by earliest expiry first
    $batch_stmt = $pdo->prepare("
        SELECT batch_id, batch_number, expiry_date, sale_price, purchase_price, quantity_available 
        FROM medicine_batches 
        WHERE medicine_id = ? 
          AND status = 'Active' 
          AND quantity_available > 0 
          AND expiry_date >= CURDATE() 
        ORDER BY expiry_date ASC, batch_id ASC 
        FOR UPDATE
    ");
    $batch_stmt->execute([$medicine_id]);
    $batches = $batch_stmt->fetchAll(PDO::FETCH_ASSOC);

    $remaining_needed = $required_qty;
    $allocations = [];
    $total_available_non_expired = 0;

    foreach ($batches as $b) {
        $avail = (int)$b['quantity_available'];
        $total_available_non_expired += $avail;

        if ($remaining_needed > 0) {
            $take = min($avail, $remaining_needed);
            $allocations[] = [
                'batch_id'           => (int)$b['batch_id'],
                'batch_number'       => $b['batch_number'],
                'expiry_date'        => $b['expiry_date'],
                'quantity'           => $take,
                'allocated_qty'      => $take,
                'sale_price'         => (float)$b['sale_price'],
                'purchase_price'     => (float)$b['purchase_price'],
                'quantity_available' => $avail
            ];
            $remaining_needed -= $take;
        }
    }

    if ($remaining_needed > 0) {
        throw new Exception("Insufficient valid non-expired stock for '{$med['medicine_name']}'. Requested: {$required_qty}, Available (Non-expired): {$total_available_non_expired}.");
    }

    return $allocations;
}

/**
 * Deduct stock atomically from a batch and medicine master, recording an immutable ledger entry.
 *
 * @param PDO $pdo
 * @param int $medicine_id
 * @param int $batch_id
 * @param int $quantity
 * @param string $transaction_type 'SALE' | 'IPD_DISPENSE' | 'ADJUSTMENT' | 'REVERSAL'
 * @param int|null $ref_id
 * @param string|null $ref_no
 * @param int|null $user_id
 * @param string|null $reason
 */
function record_stock_deduction(
    PDO $pdo,
    int $medicine_id,
    int $batch_id,
    int $quantity,
    string $transaction_type,
    ?int $ref_id = null,
    ?string $ref_no = null,
    ?int $user_id = null,
    ?string $reason = null
): void {
    if ($quantity <= 0) {
        throw new InvalidArgumentException("Deduction quantity must be positive.");
    }

    // Lock batch row
    $b_stmt = $pdo->prepare("SELECT quantity_available, purchase_price, sale_price FROM medicine_batches WHERE batch_id = ? FOR UPDATE");
    $b_stmt->execute([$batch_id]);
    $batch = $b_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$batch) {
        throw new Exception("Batch #{$batch_id} not found.");
    }

    if ((int)$batch['quantity_available'] < $quantity) {
        throw new Exception("Batch quantity insufficient. Available: {$batch['quantity_available']}, Required: {$quantity}.");
    }

    // Lock medicine row
    $m_stmt = $pdo->prepare("SELECT stock_quantity FROM medicines WHERE medicine_id = ? FOR UPDATE");
    $m_stmt->execute([$medicine_id]);
    $med = $m_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$med) {
        throw new Exception("Medicine #{$medicine_id} not found.");
    }

    $bal_before = (int)$med['stock_quantity'];
    $bal_after = $bal_before - $quantity;

    if ($bal_after < 0) {
        throw new Exception("Master inventory balance cannot become negative.");
    }

    // 1. Update Batch
    $new_batch_avail = (int)$batch['quantity_available'] - $quantity;
    $new_status = ($new_batch_avail <= 0) ? 'Depleted' : 'Active';
    $upd_b = $pdo->prepare("UPDATE medicine_batches SET quantity_available = ?, status = ? WHERE batch_id = ?");
    $upd_b->execute([$new_batch_avail, $new_status, $batch_id]);

    // 2. Update Medicine
    $upd_m = $pdo->prepare("UPDATE medicines SET stock_quantity = ? WHERE medicine_id = ?");
    $upd_m->execute([$bal_after, $medicine_id]);

    // 3. Write immutable Stock Ledger row
    $led_stmt = $pdo->prepare("
        INSERT INTO pharmacy_stock_ledger 
        (medicine_id, batch_id, transaction_type, reference_id, reference_no, quantity_change, balance_before, balance_after, unit_cost, unit_price, reason, created_by, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $led_stmt->execute([
        $medicine_id,
        $batch_id,
        $transaction_type,
        $ref_id,
        $ref_no,
        -$quantity,
        $bal_before,
        $bal_after,
        (float)$batch['purchase_price'],
        (float)$batch['sale_price'],
        $reason ?: "{$transaction_type} deduction ref #{$ref_no}",
        $user_id ?: (function_exists('current_user_id') ? current_user_id() : 1)
    ]);
}

/**
 * Add incoming stock (from purchase or inward adjustment) atomically to batch and ledger.
 * Supports either scalar arguments or an associative array for $batch_input.
 *
 * @return int The batch_id created or updated
 */
function record_stock_addition(
    PDO $pdo,
    int $medicine_id,
    $batch_input,
    ...$rest
): int {
    if (is_array($batch_input)) {
        $batch_number     = (string)($batch_input['batch_number'] ?? '');
        $expiry_date      = !empty($batch_input['expiry_date']) ? (string)$batch_input['expiry_date'] : date('Y-m-d', strtotime('+365 days'));
        $quantity         = (int)($rest[0] ?? 0);
        $transaction_type = (string)($rest[1] ?? 'PURCHASE');
        $ref_id           = isset($rest[2]) ? (int)$rest[2] : null;
        $ref_no           = isset($rest[3]) ? (string)$rest[3] : null;
        $user_id          = isset($rest[4]) ? (int)$rest[4] : null;
        $mfg_date         = isset($batch_input['mfg_date']) ? (string)$batch_input['mfg_date'] : null;
        $reason           = isset($rest[5]) ? (string)$rest[5] : (isset($batch_input['reason']) ? (string)$batch_input['reason'] : null);
        $purchase_price   = isset($batch_input['purchase_price']) ? (float)$batch_input['purchase_price'] : 0.0;
        $sale_price       = isset($batch_input['sale_price']) ? (float)$batch_input['sale_price'] : 0.0;
    } else {
        $batch_number     = (string)$batch_input;
        $expiry_date      = (string)($rest[0] ?? date('Y-m-d', strtotime('+365 days')));
        $quantity         = (int)($rest[1] ?? 0);
        $purchase_price   = (float)($rest[2] ?? 0.0);
        $sale_price       = (float)($rest[3] ?? 0.0);
        $transaction_type = (string)($rest[4] ?? 'PURCHASE');
        $ref_id           = isset($rest[5]) ? (int)$rest[5] : null;
        $ref_no           = isset($rest[6]) ? (string)$rest[6] : null;
        $user_id          = isset($rest[7]) ? (int)$rest[7] : null;
        $mfg_date         = isset($rest[8]) ? (string)$rest[8] : null;
        $reason           = isset($rest[9]) ? (string)$rest[9] : null;
    }

    if ($quantity <= 0) {
        throw new InvalidArgumentException("Addition quantity must be positive.");
    }

    // Lock medicine row
    $m_stmt = $pdo->prepare("SELECT stock_quantity, price, purchase_price FROM medicines WHERE medicine_id = ? FOR UPDATE");
    $m_stmt->execute([$medicine_id]);
    $med = $m_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$med) {
        throw new Exception("Medicine #{$medicine_id} not found.");
    }

    if ($sale_price <= 0) {
        $sale_price = (float)$med['price'];
    }
    if ($purchase_price <= 0) {
        $purchase_price = (float)$med['purchase_price'];
    }

    $bal_before = (int)$med['stock_quantity'];
    $bal_after = $bal_before + $quantity;

    // Check if matching batch already exists
    $b_stmt = $pdo->prepare("
        SELECT batch_id, quantity_available 
        FROM medicine_batches 
        WHERE medicine_id = ? AND batch_number = ? 
        FOR UPDATE
    ");
    $b_stmt->execute([$medicine_id, $batch_number]);
    $existing = $b_stmt->fetch(PDO::FETCH_ASSOC);

    $purchase_id_for_batch = ($transaction_type === 'PURCHASE' && $ref_id) ? $ref_id : null;

    if ($existing) {
        $batch_id = (int)$existing['batch_id'];
        $upd_b = $pdo->prepare("
            UPDATE medicine_batches 
            SET quantity_available = quantity_available + ?, 
                quantity_received = quantity_received + ?, 
                purchase_price = ?, 
                sale_price = ?, 
                purchase_id = COALESCE(purchase_id, ?),
                status = 'Active' 
            WHERE batch_id = ?
        ");
        $upd_b->execute([$quantity, $quantity, $purchase_price, $sale_price, $purchase_id_for_batch, $batch_id]);
    } else {
        $ins_b = $pdo->prepare("
            INSERT INTO medicine_batches 
            (medicine_id, batch_number, manufacturing_date, expiry_date, purchase_price, sale_price, quantity_received, quantity_available, purchase_id, status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', NOW())
        ");
        $ins_b->execute([
            $medicine_id,
            $batch_number,
            $mfg_date ?: null,
            $expiry_date,
            $purchase_price,
            $sale_price,
            $quantity,
            $quantity,
            $purchase_id_for_batch
        ]);
        $batch_id = (int)$pdo->lastInsertId();
    }

    // Update Medicine master stock and active batch pointers
    $upd_m = $pdo->prepare("
        UPDATE medicines 
        SET stock_quantity = ?, 
            batch_number = ?, 
            expiry_date = ?, 
            purchase_price = IF(? > 0, ?, purchase_price) 
        WHERE medicine_id = ?
    ");
    $upd_m->execute([$bal_after, $batch_number, $expiry_date, $purchase_price, $purchase_price, $medicine_id]);

    // Write immutable ledger row
    $led_stmt = $pdo->prepare("
        INSERT INTO pharmacy_stock_ledger 
        (medicine_id, batch_id, transaction_type, reference_id, reference_no, quantity_change, balance_before, balance_after, unit_cost, unit_price, reason, created_by, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $led_stmt->execute([
        $medicine_id,
        $batch_id,
        $transaction_type,
        $ref_id,
        $ref_no,
        $quantity,
        $bal_before,
        $bal_after,
        $purchase_price,
        $sale_price,
        $reason ?: "{$transaction_type} inward ref #{$ref_no}",
        $user_id ?: (function_exists('current_user_id') ? current_user_id() : 1)
    ]);

    return $batch_id;
}

/**
 * Atomically reverse purchase stock when cancelling or reversing a purchase.
 * Enforces that available batch stock >= quantity to reverse, preventing negative stock.
 *
 * @param PDO $pdo
 * @param int $medicine_id
 * @param int $batch_id
 * @param int $quantity
 * @param int $purchase_id
 * @param string $purchase_order_no
 * @param int|null $user_id
 * @param string|null $reason
 * @throws Exception
 */
function reverse_purchase_stock(
    PDO $pdo,
    int $medicine_id,
    int $batch_id,
    int $quantity,
    int $purchase_id,
    string $purchase_order_no,
    ?int $user_id = null,
    ?string $reason = null
): void {
    if ($quantity <= 0) {
        throw new InvalidArgumentException("Reversal quantity must be greater than zero.");
    }

    // 1. Lock batch row
    $b_stmt = $pdo->prepare("SELECT batch_id, batch_number, quantity_available, purchase_price, sale_price FROM medicine_batches WHERE batch_id = ? AND medicine_id = ? FOR UPDATE");
    $b_stmt->execute([$batch_id, $medicine_id]);
    $batch = $b_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$batch) {
        throw new Exception("Batch #{$batch_id} not found for medicine #{$medicine_id}.");
    }

    $avail = (int)$batch['quantity_available'];
    if ($avail < $quantity) {
        throw new Exception("Cannot reverse purchase: {$quantity} units required for reversal, but only {$avail} units remain available in batch '{$batch['batch_number']}'. Stock has already been dispensed.");
    }

    // 2. Lock medicine row
    $m_stmt = $pdo->prepare("SELECT stock_quantity FROM medicines WHERE medicine_id = ? FOR UPDATE");
    $m_stmt->execute([$medicine_id]);
    $med = $m_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$med) {
        throw new Exception("Medicine #{$medicine_id} not found.");
    }

    $bal_before = (int)$med['stock_quantity'];
    $bal_after = $bal_before - $quantity;
    if ($bal_after < 0) {
        throw new Exception("Purchase reversal would result in negative overall stock balance.");
    }

    // 3. Update Batch
    $new_batch_avail = $avail - $quantity;
    $new_status = ($new_batch_avail <= 0) ? 'Depleted' : 'Active';
    $pdo->prepare("UPDATE medicine_batches SET quantity_available = ?, status = ? WHERE batch_id = ?")
        ->execute([$new_batch_avail, $new_status, $batch_id]);

    // 4. Update Medicine Master
    $pdo->prepare("UPDATE medicines SET stock_quantity = ? WHERE medicine_id = ?")
        ->execute([$bal_after, $medicine_id]);

    // 5. Append immutable Stock Ledger row
    $led_stmt = $pdo->prepare("
        INSERT INTO pharmacy_stock_ledger 
        (medicine_id, batch_id, transaction_type, reference_id, reference_no, quantity_change, balance_before, balance_after, unit_cost, unit_price, reason, created_by, created_at) 
        VALUES (?, ?, 'REVERSAL', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $led_stmt->execute([
        $medicine_id,
        $batch_id,
        $purchase_id,
        $purchase_order_no,
        -$quantity,
        $bal_before,
        $bal_after,
        (float)$batch['purchase_price'],
        (float)$batch['sale_price'],
        $reason ?: "Purchase cancellation/reversal for {$purchase_order_no}",
        $user_id ?: (function_exists('current_user_id') ? current_user_id() : 1)
    ]);
}

/**
 * Atomically process a Supplier Return.
 * Validates available stock, deducts inventory from batch and medicine master,
 * and records immutable SUPPLIER_RETURN ledger movement.
 *
 * @param PDO $pdo
 * @param int $medicine_id
 * @param int $batch_id
 * @param int $quantity
 * @param int $return_id
 * @param string $return_no
 * @param int|null $user_id
 * @param string|null $reason
 * @throws Exception
 */
function record_supplier_return_stock(
    PDO $pdo,
    int $medicine_id,
    int $batch_id,
    int $quantity,
    int $return_id,
    string $return_no,
    ?int $user_id = null,
    ?string $reason = null
): void {
    if ($quantity <= 0) {
        throw new InvalidArgumentException("Supplier return quantity must be greater than zero.");
    }

    // 1. Lock batch row
    $b_stmt = $pdo->prepare("SELECT batch_id, batch_number, quantity_available, purchase_price, sale_price FROM medicine_batches WHERE batch_id = ? AND medicine_id = ? FOR UPDATE");
    $b_stmt->execute([$batch_id, $medicine_id]);
    $batch = $b_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$batch) {
        throw new Exception("Batch #{$batch_id} not found for medicine #{$medicine_id}.");
    }

    $avail = (int)$batch['quantity_available'];
    if ($avail < $quantity) {
        throw new Exception("Cannot process supplier return: {$quantity} units requested, but only {$avail} units available in batch '{$batch['batch_number']}'.");
    }

    // 2. Lock medicine row
    $m_stmt = $pdo->prepare("SELECT stock_quantity FROM medicines WHERE medicine_id = ? FOR UPDATE");
    $m_stmt->execute([$medicine_id]);
    $med = $m_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$med) {
        throw new Exception("Medicine #{$medicine_id} not found.");
    }

    $bal_before = (int)$med['stock_quantity'];
    $bal_after = $bal_before - $quantity;
    if ($bal_after < 0) {
        throw new Exception("Supplier return would result in negative overall stock balance.");
    }

    // 3. Update Batch
    $new_batch_avail = $avail - $quantity;
    $new_status = ($new_batch_avail <= 0) ? 'Depleted' : 'Active';
    $pdo->prepare("UPDATE medicine_batches SET quantity_available = ?, status = ? WHERE batch_id = ?")
        ->execute([$new_batch_avail, $new_status, $batch_id]);

    // 4. Update Medicine Master
    $pdo->prepare("UPDATE medicines SET stock_quantity = ? WHERE medicine_id = ?")
        ->execute([$bal_after, $medicine_id]);

    // 5. Append immutable Stock Ledger row
    $led_stmt = $pdo->prepare("
        INSERT INTO pharmacy_stock_ledger 
        (medicine_id, batch_id, transaction_type, reference_id, reference_no, quantity_change, balance_before, balance_after, unit_cost, unit_price, reason, created_by, created_at) 
        VALUES (?, ?, 'SUPPLIER_RETURN', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $led_stmt->execute([
        $medicine_id,
        $batch_id,
        $return_id,
        $return_no,
        -$quantity,
        $bal_before,
        $bal_after,
        (float)$batch['purchase_price'],
        (float)$batch['sale_price'],
        $reason ?: "Supplier return ref #{$return_no}",
        $user_id ?: (function_exists('current_user_id') ? current_user_id() : 1)
    ]);
}

/**
 * Atomically reverse sold stock when cancelling / voiding a pharmacy sale.
 * Restores quantity to the specific batch and updates medicine master stock and ledger.
 *
 * @param PDO $pdo
 * @param int $medicine_id
 * @param int $batch_id
 * @param int $quantity
 * @param int $sale_id
 * @param string $receipt_no
 * @param int|null $user_id
 * @param string|null $reason
 * @throws Exception
 */
function reverse_sale_stock(
    PDO $pdo,
    int $medicine_id,
    int $batch_id,
    int $quantity,
    int $sale_id,
    string $receipt_no,
    ?int $user_id = null,
    ?string $reason = null
): void {
    if ($quantity <= 0) {
        throw new InvalidArgumentException("Reversal quantity must be greater than zero.");
    }

    // 1. Lock batch row
    $b_stmt = $pdo->prepare("SELECT batch_id, batch_number, quantity_available, purchase_price, sale_price FROM medicine_batches WHERE batch_id = ? AND medicine_id = ? FOR UPDATE");
    $b_stmt->execute([$batch_id, $medicine_id]);
    $batch = $b_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$batch) {
        throw new Exception("Batch #{$batch_id} not found for medicine #{$medicine_id}.");
    }

    $batch_before = (int)$batch['quantity_available'];
    $batch_after = $batch_before + $quantity;

    // 2. Lock medicine row
    $m_stmt = $pdo->prepare("SELECT stock_quantity, price, purchase_price FROM medicines WHERE medicine_id = ? FOR UPDATE");
    $m_stmt->execute([$medicine_id]);
    $med = $m_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$med) {
        throw new Exception("Medicine #{$medicine_id} not found.");
    }

    $bal_before = (int)$med['stock_quantity'];
    $bal_after = $bal_before + $quantity;

    // 3. Update Batch
    $pdo->prepare("UPDATE medicine_batches SET quantity_available = ?, status = 'Active' WHERE batch_id = ?")
        ->execute([$batch_after, $batch_id]);

    // 4. Update Medicine Master
    $pdo->prepare("UPDATE medicines SET stock_quantity = ? WHERE medicine_id = ?")
        ->execute([$bal_after, $medicine_id]);

    // 5. Append immutable Stock Ledger row
    $led_stmt = $pdo->prepare("
        INSERT INTO pharmacy_stock_ledger 
        (medicine_id, batch_id, transaction_type, reference_id, reference_no, quantity_change, balance_before, balance_after, unit_cost, unit_price, reason, created_by, created_at) 
        VALUES (?, ?, 'REVERSAL', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $led_stmt->execute([
        $medicine_id,
        $batch_id,
        $sale_id,
        $receipt_no,
        $quantity,
        $bal_before,
        $bal_after,
        (float)$batch['purchase_price'],
        (float)$batch['sale_price'],
        $reason ?: "Sale void/cancellation for {$receipt_no}",
        $user_id ?: (function_exists('current_user_id') ? current_user_id() : 1)
    ]);
}

/**
 * Reverses all line items and restores stock to batches for an entire voided sale.
 *
 * @param PDO $pdo
 * @param int $sale_id
 * @param int|null $user_id
 * @param string|null $reason
 * @throws Exception
 */
function reverse_entire_sale_stock(PDO $pdo, int $sale_id, ?int $user_id = null, ?string $reason = null): void
{
    // Fetch sale
    $stmt = $pdo->prepare("SELECT receipt_no, status FROM pharmacy_sales WHERE sale_id = ? FOR UPDATE");
    $stmt->execute([$sale_id]);
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sale) {
        throw new Exception("Sale #{$sale_id} not found.");
    }

    // Fetch sale items
    $item_stmt = $pdo->prepare("SELECT medicine_id, batch_id, quantity FROM sale_items WHERE sale_id = ?");
    $item_stmt->execute([$sale_id]);
    $items = $item_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        return; // Nothing to reverse
    }

    foreach ($items as $it) {
        if (!empty($it['batch_id']) && (int)$it['quantity'] > 0) {
            reverse_sale_stock(
                $pdo,
                (int)$it['medicine_id'],
                (int)$it['batch_id'],
                (int)$it['quantity'],
                $sale_id,
                $sale['receipt_no'],
                $user_id,
                $reason
            );
        }
    }
}

/**
 * Controlled Stock Adjustment Workflow.
 * Applies an auditable positive or negative adjustment to a specific batch and writes immutable ledger.
 */
function record_stock_adjustment(
    PDO $pdo,
    int $medicine_id,
    ?int $batch_id,
    string $adjustment_type,
    int $quantity,
    string $reason,
    ?int $user_id = null
): array {
    if ($quantity <= 0) {
        throw new InvalidArgumentException("Adjustment quantity must be greater than zero.");
    }
    if (trim($reason) === '') {
        throw new InvalidArgumentException("A detailed business justification reason is required for stock adjustments.");
    }

    $is_increase = in_array(strtoupper($adjustment_type), ['INCREASE', 'FOUND STOCK', 'ADD']);
    $delta = $is_increase ? $quantity : -$quantity;
    $db_adj_type = in_array($adjustment_type, ['Increase', 'Decrease', 'Damage', 'Expired', 'Count Reconciliation', 'Found Stock']) 
        ? $adjustment_type 
        : ($is_increase ? 'Increase' : 'Decrease');

    // Generate adjustment number
    $adj_no = generate_document_number($pdo, 'ADJ');
    $user_id = $user_id ?: (function_exists('current_user_id') ? current_user_id() : 1);

    // 1. If batch_id provided, lock and verify batch
    $batch_price = 0.00;
    $batch_cost = 0.00;
    $batch_before = 0;
    $batch_after = 0;
    if ($batch_id) {
        $b_stmt = $pdo->prepare("SELECT batch_id, quantity_available, purchase_price, sale_price FROM medicine_batches WHERE batch_id = ? AND medicine_id = ? FOR UPDATE");
        $b_stmt->execute([$batch_id, $medicine_id]);
        $batch = $b_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$batch) {
            throw new Exception("Batch #{$batch_id} not found for medicine #{$medicine_id}.");
        }

        if (!$is_increase && (int)$batch['quantity_available'] < $quantity) {
            throw new Exception("Cannot decrease batch below zero. Available: {$batch['quantity_available']}, Decrease: {$quantity}.");
        }

        $batch_cost = (float)$batch['purchase_price'];
        $batch_price = (float)$batch['sale_price'];
        $batch_before = (int)$batch['quantity_available'];
        $batch_after = $batch_before + $delta;
        $new_b_status = ($batch_after <= 0) ? 'Depleted' : 'Active';

        $pdo->prepare("UPDATE medicine_batches SET quantity_available = ?, status = ? WHERE batch_id = ?")
            ->execute([$batch_after, $new_b_status, $batch_id]);
    }

    // 2. Lock medicine master
    $m_stmt = $pdo->prepare("SELECT stock_quantity, price, purchase_price FROM medicines WHERE medicine_id = ? FOR UPDATE");
    $m_stmt->execute([$medicine_id]);
    $med = $m_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$med) {
        throw new Exception("Medicine #{$medicine_id} not found.");
    }

    $bal_before = (int)$med['stock_quantity'];
    $bal_after = $bal_before + $delta;

    if ($bal_after < 0) {
        throw new Exception("Stock adjustment would result in negative overall stock balance.");
    }

    $pdo->prepare("UPDATE medicines SET stock_quantity = ? WHERE medicine_id = ?")->execute([$bal_after, $medicine_id]);

    // 3. Record in pharmacy_stock_adjustments table
    $adj_stmt = $pdo->prepare("
        INSERT INTO pharmacy_stock_adjustments 
        (adjustment_no, medicine_id, batch_id, adjustment_type, quantity, reason, created_by, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $adj_stmt->execute([$adj_no, $medicine_id, $batch_id, $db_adj_type, $quantity, $reason, $user_id]);
    $adj_id = (int)$pdo->lastInsertId();

    // 4. Write to pharmacy_stock_ledger
    $led_stmt = $pdo->prepare("
        INSERT INTO pharmacy_stock_ledger 
        (medicine_id, batch_id, transaction_type, reference_id, reference_no, quantity_change, balance_before, balance_after, unit_cost, unit_price, reason, created_by, created_at) 
        VALUES (?, ?, 'ADJUSTMENT', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $led_stmt->execute([
        $medicine_id,
        $batch_id,
        $adj_id,
        $adj_no,
        $delta,
        $bal_before,
        $bal_after,
        $batch_cost ?: (float)$med['purchase_price'],
        $batch_price ?: (float)$med['price'],
        "[{$db_adj_type}] " . $reason,
        $user_id
    ]);

    log_activity($pdo, $user_id, "Stock Adjustment {$adj_no}: {$db_adj_type} {$quantity} units for medicine #{$medicine_id}", 'pharmacy');

    return [
        'adjustment_id'     => $adj_id,
        'adjustment_no'     => $adj_no,
        'adjustment_number' => $adj_no,
        'balance_before'    => $bal_before,
        'balance_after'     => $bal_after,
        'batch_before'      => $batch_before,
        'batch_after'       => $batch_after
    ];
}

/**
 * Standardized Stock & Expiry Status Evaluator.
 * Returns status code, label, badge class for a batch or medicine.
 */
function get_stock_status(int $available, int $reorder_level, ?string $expiry_date, string $status = 'Active'): array
{
    // Quarantined batches
    if ($status === 'Quarantined') {
        return ['code' => 'QUARANTINED', 'label' => 'QUARANTINED', 'badge' => 'bg-dark text-white', 'class' => 'bg-dark text-white'];
    }

    // Disposed batches
    if ($status === 'Disposed') {
        return ['code' => 'DISPOSED', 'label' => 'DISPOSED', 'badge' => 'bg-secondary text-white', 'class' => 'bg-secondary text-white'];
    }

    // All other non-Active statuses (Depleted, etc.)
    if (!in_array($status, ['Active', 'Expired'], true)) {
        return ['code' => 'INACTIVE', 'label' => 'INACTIVE', 'badge' => 'bg-secondary text-white', 'class' => 'bg-secondary text-white'];
    }

    if ($available <= 0) {
        return ['code' => 'OUT_OF_STOCK', 'label' => 'OUT OF STOCK', 'badge' => 'bg-danger text-white', 'class' => 'bg-danger text-white'];
    }

    // Unknown / NULL / invalid expiry — flag explicitly
    if (empty($expiry_date) || $expiry_date === '0000-00-00' || strtotime($expiry_date) === false) {
        return ['code' => 'UNKNOWN_EXPIRY', 'label' => 'UNKNOWN EXPIRY', 'badge' => 'bg-info text-dark', 'class' => 'bg-info text-dark'];
    }

    $exp_ts = strtotime($expiry_date);
    $today_ts = strtotime(date('Y-m-d'));
    $days_left = ($exp_ts - $today_ts) / 86400;

    if ($days_left < 0 || $status === 'Expired') {
        return ['code' => 'EXPIRED', 'label' => 'EXPIRED', 'badge' => 'bg-danger text-white', 'class' => 'bg-danger text-white'];
    } elseif ($days_left <= 30) {
        return ['code' => 'EXPIRING_30', 'label' => 'EXPIRING SOON', 'badge' => 'bg-danger bg-opacity-75 text-white', 'class' => 'bg-danger bg-opacity-75 text-white'];
    } elseif ($days_left <= 60) {
        return ['code' => 'EXPIRING_60', 'label' => 'EXPIRING SOON', 'badge' => 'bg-warning text-dark', 'class' => 'bg-warning text-dark'];
    } elseif ($days_left <= 90) {
        return ['code' => 'EXPIRING_90', 'label' => 'EXPIRING SOON', 'badge' => 'bg-warning bg-opacity-75 text-dark', 'class' => 'bg-warning bg-opacity-75 text-dark'];
    }

    if ($available <= $reorder_level) {
        return ['code' => 'LOW_STOCK', 'label' => 'LOW STOCK', 'badge' => 'bg-warning text-dark', 'class' => 'bg-warning text-dark'];
    }

    return ['code' => 'IN_STOCK', 'label' => 'IN STOCK', 'badge' => 'bg-success text-white', 'class' => 'bg-success text-white'];
}

/**
 * Executes an atomic IPD prescription dispensing transaction with FEFO batch allocation.
 *
 * @param PDO $pdo
 * @param int $prescription_id
 * @param array $dispense_items Array of ['item_id' => int, 'quantity' => int] or key-value [item_id => quantity]
 * @param int $user_id
 * @param string|null $notes
 * @return array Dispensing transaction summary
 * @throws Exception
 */
function dispense_ipd_prescription(
    PDO $pdo,
    int $prescription_id,
    array $dispense_items,
    int $user_id,
    ?string $notes = null
): array {
    $started_tx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $started_tx = true;
    }

    try {
        // 1. Lock prescription row
        $rx_stmt = $pdo->prepare("SELECT * FROM prescriptions WHERE prescription_id = ? FOR UPDATE");
        $rx_stmt->execute([$prescription_id]);
        $rx = $rx_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rx) {
            throw new Exception("Prescription #{$prescription_id} not found.");
        }

        if (in_array($rx['status'], ['Cancelled', 'Completed', 'Fully Dispensed'], true)) {
            throw new Exception("Prescription #{$prescription_id} cannot be dispensed because its current status is '{$rx['status']}'.");
        }

        if (empty($rx['admission_id'])) {
            throw new Exception("Prescription #{$prescription_id} is not linked to an active IPD admission.");
        }

        // 2. Lock admission row and verify active inpatient status
        $adm_stmt = $pdo->prepare("SELECT admission_id, ipd_number, patient_id, status FROM admissions WHERE admission_id = ? FOR UPDATE");
        $adm_stmt->execute([$rx['admission_id']]);
        $admission = $adm_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admission || $admission['status'] !== 'Admitted') {
            throw new Exception("Cannot dispense: Inpatient Admission #{$rx['admission_id']} is not active (Status: " . ($admission['status'] ?? 'Not Found') . ").");
        }

        if ((int)$admission['patient_id'] !== (int)$rx['patient_id']) {
            throw new Exception("Patient mismatch between prescription and active admission.");
        }

        // 3. Process and validate requested item quantities
        $total_dispensed_units = 0;
        $items_to_process = [];

        foreach ($dispense_items as $k => $v) {
            $item_id = is_array($v) ? (int)($v['item_id'] ?? $k) : (int)$k;
            $req_qty = is_array($v) ? (int)($v['quantity'] ?? 0) : (int)$v;

            if ($req_qty <= 0) {
                continue;
            }

            // Lock prescription item
            $pi_stmt = $pdo->prepare("
                SELECT pi.*, m.medicine_name, m.price, m.gst_percent, m.status AS med_status
                FROM prescription_items pi
                JOIN medicines m ON pi.medicine_id = m.medicine_id
                WHERE pi.item_id = ? AND pi.prescription_id = ?
                FOR UPDATE
            ");
            $pi_stmt->execute([$item_id, $prescription_id]);
            $item = $pi_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                throw new Exception("Prescription item #{$item_id} does not belong to Prescription #{$prescription_id}.");
            }

            if ($item['status'] === 'Cancelled') {
                throw new Exception("Prescription item #{$item_id} ('{$item['medicine_name']}') has been cancelled.");
            }

            $prescribed_qty    = (int)$item['quantity'];
            $already_dispensed = (int)$item['dispensed_quantity'];
            $remaining_qty     = max(0, $prescribed_qty - $already_dispensed);

            if ($req_qty > $remaining_qty) {
                throw new Exception("Requested quantity ({$req_qty}) exceeds remaining prescribed quantity ({$remaining_qty}) for '{$item['medicine_name']}'.");
            }

            // Allocate batches via FEFO (locks batches with FOR UPDATE)
            $allocations = fefo_allocate_stock($pdo, (int)$item['medicine_id'], $req_qty);

            $unit_price  = (float)$item['price'];
            $gst_percent = (float)$item['gst_percent'];
            $line_gross  = round($req_qty * $unit_price, 2);
            $line_tax    = round($line_gross * ($gst_percent / 100), 2);
            $line_total  = round($line_gross + $line_tax, 2);

            $items_to_process[] = [
                'item_id'           => $item_id,
                'medicine_id'       => (int)$item['medicine_id'],
                'medicine_name'     => $item['medicine_name'],
                'prescribed_qty'    => $prescribed_qty,
                'already_dispensed' => $already_dispensed,
                'dispense_qty'      => $req_qty,
                'unit_price'        => $unit_price,
                'gst_percent'       => $gst_percent,
                'line_gross'        => $line_gross,
                'line_tax'          => $line_tax,
                'line_total'        => $line_total,
                'allocations'       => $allocations
            ];

            $total_dispensed_units += $req_qty;
        }

        if ($total_dispensed_units <= 0) {
            throw new Exception("No valid medication quantities selected for dispensing.");
        }

        // 4. Generate sequential receipt number
        $receipt_no = generate_document_number($pdo, 'PHARM');

        // Calculate totals
        $total_gross = 0.00;
        $total_tax   = 0.00;
        $total_net   = 0.00;
        foreach ($items_to_process as $it) {
            $total_gross += $it['line_gross'];
            $total_tax   += $it['line_tax'];
            $total_net   += $it['line_total'];
        }
        $total_gross = round($total_gross, 2);
        $total_tax   = round($total_tax, 2);
        $total_net   = round($total_net, 2);

        // Fetch patient demographics
        $pat_stmt = $pdo->prepare("SELECT CONCAT(first_name, ' ', last_name) AS name, phone FROM patients WHERE patient_id = ?");
        $pat_stmt->execute([$rx['patient_id']]);
        $pat = $pat_stmt->fetch(PDO::FETCH_ASSOC);

        // 5. Insert Regular Pharmacy Sale (Pending payment for IPD discharge settlement)
        $s_stmt = $pdo->prepare("
            INSERT INTO pharmacy_sales (
                receipt_no, sale_type, patient_id, customer_name, customer_phone,
                admission_id, prescription_id, sold_by, doctor_id, total_amount,
                gst_amount, net_amount, round_off, discount_amount, discount_percent,
                payment_mode, payment_status, status, total_items, sale_date, created_by
            ) VALUES (
                ?, 'Regular', ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, 0.00, 0.00, 0.00,
                'Cash', 'Pending', 'Completed', ?, NOW(), ?
            )
        ");
        $s_stmt->execute([
            $receipt_no,
            $rx['patient_id'],
            $pat['name'] ?? 'Inpatient',
            $pat['phone'] ?? null,
            $rx['admission_id'],
            $prescription_id,
            $user_id,
            $rx['doctor_id'],
            $total_gross,
            $total_tax,
            $total_net,
            count($items_to_process),
            $user_id
        ]);
        $sale_id = (int)$pdo->lastInsertId();

        // 6. Deduct stock, insert sale items, and update prescription items
        $ins_si = $pdo->prepare("
            INSERT INTO sale_items (
                sale_id, prescription_item_id, medicine_id, batch_id,
                quantity, price, gst_percent, total_amount
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $upd_pi = $pdo->prepare("
            UPDATE prescription_items 
            SET dispensed_quantity = dispensed_quantity + ?,
                status = CASE WHEN dispensed_quantity >= quantity THEN 'Fully Dispensed' ELSE 'Partially Dispensed' END
            WHERE item_id = ?
        ");

        foreach ($items_to_process as $it) {
            foreach ($it['allocations'] as $alloc) {
                $alloc_qty   = (int)$alloc['allocated_qty'];
                $alloc_gross = round($alloc_qty * $it['unit_price'], 2);
                $alloc_tax   = round($alloc_gross * ($it['gst_percent'] / 100), 2);
                $alloc_tot   = round($alloc_gross + $alloc_tax, 2);

                // Line item linked to batch and prescription item
                $ins_si->execute([
                    $sale_id,
                    $it['item_id'],
                    $it['medicine_id'],
                    $alloc['batch_id'],
                    $alloc_qty,
                    $it['unit_price'],
                    $it['gst_percent'],
                    $alloc_tot
                ]);

                // Atomically decrement stock from batch and master + log immutable ledger movement
                record_stock_deduction(
                    $pdo,
                    $it['medicine_id'],
                    $alloc['batch_id'],
                    $alloc_qty,
                    'SALE',
                    $sale_id,
                    $receipt_no,
                    $user_id,
                    "IPD Dispensing for Rx #{$prescription_id} (Adm #{$rx['admission_id']})"
                );
            }

            // Update prescription item progress
            $upd_pi->execute([$it['dispense_qty'], $it['item_id']]);
        }

        // 7. Update overall prescription status
        $chk_rem = $pdo->prepare("
            SELECT COUNT(*) 
            FROM prescription_items 
            WHERE prescription_id = ? AND (quantity - dispensed_quantity) > 0 AND status != 'Cancelled'
        ");
        $chk_rem->execute([$prescription_id]);
        $remaining_items_count = (int)$chk_rem->fetchColumn();

        $new_rx_status = ($remaining_items_count === 0) ? 'Fully Dispensed' : 'Partially Dispensed';
        $pdo->prepare("UPDATE prescriptions SET status = ? WHERE prescription_id = ?")->execute([$new_rx_status, $prescription_id]);

        // 8. Audit activity log
        log_activity(
            $pdo,
            $user_id,
            "IPD Dispensing: Sale #{$sale_id} ({$receipt_no}) for Rx #{$prescription_id}, Adm #{$rx['admission_id']}, Units: {$total_dispensed_units}, Net: ₹{$total_net}",
            'pharmacy'
        );

        if ($started_tx) {
            $pdo->commit();
        }

        return [
            'sale_id'          => $sale_id,
            'receipt_no'       => $receipt_no,
            'prescription_id'  => $prescription_id,
            'admission_id'     => $rx['admission_id'],
            'patient_id'       => $rx['patient_id'],
            'total_items'      => count($items_to_process),
            'total_units'      => $total_dispensed_units,
            'total_gross'      => $total_gross,
            'total_tax'        => $total_tax,
            'net_amount'       => $total_net,
            'rx_status'        => $new_rx_status,
            'processed_items'  => $items_to_process
        ];

    } catch (Exception $e) {
        if ($started_tx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Controlled cancellation of an IPD prescription.
 *
 * @param PDO $pdo
 * @param int $prescription_id
 * @param int $user_id
 * @param string $reason
 * @throws Exception
 */
function cancel_ipd_prescription(PDO $pdo, int $prescription_id, int $user_id, string $reason): void
{
    $started_tx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $started_tx = true;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM prescriptions WHERE prescription_id = ? FOR UPDATE");
        $stmt->execute([$prescription_id]);
        $rx = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rx) {
            throw new Exception("Prescription #{$prescription_id} not found.");
        }

        if ($rx['status'] === 'Cancelled') {
            throw new Exception("Prescription #{$prescription_id} has already been cancelled.");
        }

        if ($rx['status'] === 'Fully Dispensed') {
            throw new Exception("Prescription #{$prescription_id} has already been fully dispensed and cannot be cancelled.");
        }

        // Mark prescription as Cancelled
        $pdo->prepare("UPDATE prescriptions SET status = 'Cancelled' WHERE prescription_id = ?")->execute([$prescription_id]);

        // Cancel remaining items
        $pdo->prepare("
            UPDATE prescription_items 
            SET status = 'Cancelled' 
            WHERE prescription_id = ? AND status != 'Fully Dispensed'
        ")->execute([$prescription_id]);

        log_activity(
            $pdo,
            $user_id,
            "IPD Prescription #{$prescription_id} cancelled. Reason: {$reason}",
            'prescriptions'
        );

        if ($started_tx) {
            $pdo->commit();
        }
    } catch (Exception $e) {
        if ($started_tx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// =========================================================================
// PHARMACY CHUNK 6: MAR (MEDICATION ADMINISTRATION RECORD) & BILLING
// =========================================================================

/**
 * Record a clinical medication administration event in the MAR.
 * 
 * CORE ARCHITECTURAL INVARIANT:
 * PRESCRIBED != DISPENSED != ADMINISTERED != BILLED != PAID
 * 
 * MAR records clinical administration events.
 * It NEVER deducts stock, NEVER modifies pharmacy inventory, and NEVER creates bills or payments.
 * 
 * QUANTITY RULE:
 * ADMINISTERED <= DISPENSED <= PRESCRIBED
 *
 * @param PDO   $pdo
 * @param array $data
 * @param int   $user_id
 * @return array
 * @throws Exception
 */
function record_medication_administration(PDO $pdo, array $data, int $user_id): array
{
    $admission_id         = (int)($data['admission_id'] ?? 0);
    $prescription_id      = !empty($data['prescription_id']) ? (int)$data['prescription_id'] : null;
    $prescription_item_id = !empty($data['prescription_item_id']) ? (int)$data['prescription_item_id'] : null;
    $medicine_id          = (int)($data['medicine_id'] ?? 0);
    $dose                 = trim($data['dose'] ?? '');
    $route                = trim($data['route'] ?? 'Oral');
    $frequency            = trim($data['frequency'] ?? '');
    $scheduled_time       = !empty($data['scheduled_time']) ? date('Y-m-d H:i:s', strtotime($data['scheduled_time'])) : date('Y-m-d H:i:s');
    $administered_time    = !empty($data['administered_time']) ? date('Y-m-d H:i:s', strtotime($data['administered_time'])) : date('Y-m-d H:i:s');
    $status               = trim($data['status'] ?? 'Administered');
    $raw_qty = isset($data['administered_quantity']) ? (int)$data['administered_quantity'] : 1;
    if ($raw_qty < 0) {
        throw new InvalidArgumentException("Administered quantity cannot be negative.");
    }
    $admin_qty            = $raw_qty;
    $reason               = trim($data['reason'] ?? '');
    $notes                = trim($data['notes'] ?? '');

    $allowed_statuses = ['Given', 'Administered', 'Missed', 'Held', 'Refused', 'Omitted', 'Not Given', 'Scheduled'];
    if (!in_array($status, $allowed_statuses, true)) {
        throw new InvalidArgumentException("Invalid MAR status '{$status}'. Allowed: " . implode(', ', $allowed_statuses));
    }

    $is_non_admin = in_array($status, ['Missed', 'Held', 'Refused', 'Omitted', 'Not Given'], true);
    if ($is_non_admin) {
        if (empty($reason)) {
            throw new InvalidArgumentException("A clinical reason is mandatory when recording medication as '{$status}'.");
        }
        $admin_qty = 0; // No medication physically consumed
    }

    $started_tx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $started_tx = true;
    }

    try {
        // 1. Lock and verify active admission
        $adm_stmt = $pdo->prepare("SELECT admission_id, patient_id, status FROM admissions WHERE admission_id = ? FOR UPDATE");
        $adm_stmt->execute([$admission_id]);
        $admission = $adm_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admission || $admission['status'] !== 'Admitted') {
            throw new Exception("Cannot record administration: Inpatient Admission #{$admission_id} is not active (Status: " . ($admission['status'] ?? 'Not Found') . ").");
        }

        // 2. Lock and verify medicine
        $med_stmt = $pdo->prepare("SELECT medicine_id, medicine_name FROM medicines WHERE medicine_id = ? FOR UPDATE");
        $med_stmt->execute([$medicine_id]);
        $med = $med_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$med) {
            throw new Exception("Medicine #{$medicine_id} not found.");
        }

        // 3. Clinical Quantity Validation against Prescription & Dispensing
        $dispensed_qty = 0;
        $prescribed_qty = 0;

        if ($prescription_item_id) {
            $pi_stmt = $pdo->prepare("
                SELECT pi.*, rx.admission_id AS rx_admission_id, rx.patient_id AS rx_patient_id, rx.status AS rx_status
                FROM prescription_items pi
                JOIN prescriptions rx ON pi.prescription_id = rx.prescription_id
                WHERE pi.item_id = ?
                FOR UPDATE
            ");
            $pi_stmt->execute([$prescription_item_id]);
            $pi = $pi_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$pi) {
                throw new Exception("Prescription item #{$prescription_item_id} not found.");
            }

            if ((int)$pi['rx_admission_id'] !== $admission_id) {
                throw new Exception("Prescription item #{$prescription_item_id} does not belong to Inpatient Admission #{$admission_id}.");
            }

            if ((int)$pi['rx_patient_id'] !== (int)$admission['patient_id']) {
                throw new Exception("Patient mismatch between prescription item and inpatient admission.");
            }

            if ($pi['status'] === 'Cancelled' || $pi['rx_status'] === 'Cancelled') {
                throw new Exception("Cannot administer: Prescription item has been cancelled.");
            }

            if ((int)$pi['medicine_id'] !== $medicine_id) {
                throw new Exception("Medicine mismatch: Prescription item #{$prescription_item_id} is for medicine #{$pi['medicine_id']}, not #{$medicine_id}.");
            }

            $prescribed_qty = (int)$pi['quantity'];
            $dispensed_qty  = (int)$pi['dispensed_quantity'];
            $prescription_id = (int)$pi['prescription_id'];

            // Cumulative check: Administered cannot exceed Dispensed
            if (!$is_non_admin && $status !== 'Scheduled') {
                if ($dispensed_qty <= 0) {
                    throw new Exception("Cannot administer '{$med['medicine_name']}': no units have been dispensed yet by the pharmacy (Dispensed: 0, Prescribed: {$prescribed_qty}).");
                }

                $cum_stmt = $pdo->prepare("
                    SELECT COALESCE(SUM(administered_quantity), 0)
                    FROM ipd_medicine_administration
                    WHERE prescription_item_id = ? AND status IN ('Given', 'Administered')
                ");
                $cum_stmt->execute([$prescription_item_id]);
                $already_admin = (int)$cum_stmt->fetchColumn();

                if (($already_admin + $admin_qty) > $dispensed_qty) {
                    $avail_to_admin = max(0, $dispensed_qty - $already_admin);
                    throw new Exception("Cannot administer {$admin_qty} units of '{$med['medicine_name']}'. Total administered (" . ($already_admin + $admin_qty) . ") would exceed total dispensed quantity ({$dispensed_qty}). Max currently available to administer: {$avail_to_admin} unit(s).");
                }
            }
        }

        // 4. Insert MAR entry
        $ins = $pdo->prepare("
            INSERT INTO ipd_medicine_administration (
                admission_id, prescription_id, prescription_item_id, medicine_id,
                administered_quantity, dose, route, frequency, scheduled_time,
                administered_time, given_by, status, reason, notes, created_at
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, NOW()
            )
        ");
        $ins->execute([
            $admission_id,
            $prescription_id,
            $prescription_item_id,
            $medicine_id,
            $admin_qty,
            $dose ?: ($med['dose'] ?? 'Standard'),
            $route,
            $frequency,
            $scheduled_time,
            $administered_time,
            $user_id,
            $status,
            $reason ?: null,
            $notes ?: null
        ]);
        $admin_id = (int)$pdo->lastInsertId();

        // 5. Activity log
        log_activity(
            $pdo,
            $user_id,
            "MAR Recorded: {$status} {$admin_qty} unit(s) of '{$med['medicine_name']}' for Admission #{$admission_id}" . ($prescription_item_id ? " (Rx Item #{$prescription_item_id})" : ""),
            'ipd'
        );

        if ($started_tx) {
            $pdo->commit();
        }

        return [
            'admin_id'              => $admin_id,
            'admission_id'          => $admission_id,
            'prescription_id'       => $prescription_id,
            'prescription_item_id'  => $prescription_item_id,
            'medicine_id'           => $medicine_id,
            'medicine_name'         => $med['medicine_name'],
            'status'                => $status,
            'administered_quantity' => $admin_qty,
            'dispensed_quantity'    => $dispensed_qty,
            'prescribed_quantity'   => $prescribed_qty
        ];

    } catch (Exception $e) {
        if ($started_tx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Controlled voiding/reversal of an erroneous MAR administration entry.
 *
 * @param PDO    $pdo
 * @param int    $admin_id
 * @param int    $user_id
 * @param string $reason
 * @throws Exception
 */
function void_medication_administration(PDO $pdo, int $admin_id, int $user_id, string $reason): void
{
    if (empty(trim($reason))) {
        throw new InvalidArgumentException("A valid clinical reason is mandatory to void a medication administration record.");
    }

    $started_tx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $started_tx = true;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM ipd_medicine_administration WHERE admin_id = ? FOR UPDATE");
        $stmt->execute([$admin_id]);
        $mar = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$mar) {
            throw new Exception("Medication administration record #{$admin_id} not found.");
        }

        if ($mar['status'] === 'Voided') {
            throw new Exception("Medication administration record #{$admin_id} has already been voided.");
        }

        $upd = $pdo->prepare("
            UPDATE ipd_medicine_administration
            SET status = 'Voided',
                voided_by = ?,
                voided_at = NOW(),
                void_reason = ?
            WHERE admin_id = ?
        ");
        $upd->execute([$user_id, $reason, $admin_id]);

        log_activity(
            $pdo,
            $user_id,
            "MAR Voided: Record #{$admin_id} for Admission #{$mar['admission_id']}. Reason: {$reason}",
            'ipd'
        );

        if ($started_tx) {
            $pdo->commit();
        }
    } catch (Exception $e) {
        if ($started_tx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Fetch administration progress for a given prescription item.
 *
 * @param PDO $pdo
 * @param int $prescription_item_id
 * @return array
 */
function get_prescription_item_mar_summary(PDO $pdo, int $prescription_item_id): array
{
    $stmt = $pdo->prepare("
        SELECT pi.item_id, pi.prescription_id, pi.medicine_id, m.medicine_name,
               pi.quantity AS prescribed_qty,
               pi.dispensed_quantity AS dispensed_qty,
               COALESCE((
                   SELECT SUM(administered_quantity) 
                   FROM ipd_medicine_administration 
                   WHERE prescription_item_id = pi.item_id AND status IN ('Given', 'Administered')
               ), 0) AS administered_qty,
               COALESCE((
                   SELECT COUNT(*) 
                   FROM ipd_medicine_administration 
                   WHERE prescription_item_id = pi.item_id AND status IN ('Missed', 'Held', 'Refused', 'Omitted', 'Not Given')
               ), 0) AS non_admin_count
        FROM prescription_items pi
        JOIN medicines m ON pi.medicine_id = m.medicine_id
        WHERE pi.item_id = ?
    ");
    $stmt->execute([$prescription_item_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return [];
    }

    $prescribed   = (int)$row['prescribed_qty'];
    $dispensed    = (int)$row['dispensed_qty'];
    $administered = (int)$row['administered_qty'];

    $row['remaining_to_dispense']   = max(0, $prescribed - $dispensed);
    $row['remaining_to_administer'] = max(0, $dispensed - $administered);

    return $row;
}

/**
 * Reconcile all pharmacy dispensing, billing, and payment records for an inpatient admission.
 *
 * @param PDO $pdo
 * @param int $admission_id
 * @return array
 */
function reconcile_admission_pharmacy_billing(PDO $pdo, int $admission_id): array
{
    // 1. Admission details
    $adm_stmt = $pdo->prepare("
        SELECT a.admission_id, a.ipd_number, a.patient_id, a.status AS admission_status,
               CONCAT(p.first_name, ' ', p.last_name) AS patient_name, p.patient_code
        FROM admissions a
        JOIN patients p ON a.patient_id = p.patient_id
        WHERE a.admission_id = ?
    ");
    $adm_stmt->execute([$admission_id]);
    $adm = $adm_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$adm) {
        throw new Exception("Admission #{$admission_id} not found.");
    }

    // 2. All pharmacy sales tied to this admission
    $sales_stmt = $pdo->prepare("
        SELECT s.sale_id, s.receipt_no, s.sale_date, s.prescription_id, s.bill_id,
               s.total_amount, s.gst_amount, s.net_amount, s.payment_status, s.status AS sale_status,
               b.receipt_no AS bill_receipt_no, b.status AS bill_status, b.payment_status AS bill_payment_status
        FROM pharmacy_sales s
        LEFT JOIN bills b ON s.bill_id = b.bill_id
        WHERE s.admission_id = ?
        ORDER BY s.sale_id ASC
    ");
    $sales_stmt->execute([$admission_id]);
    $sales = $sales_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Totals calculation
    $total_dispensed_amount = 0.00;
    $total_billed_amount    = 0.00;
    $total_paid_amount      = 0.00;
    $total_pending_amount   = 0.00;

    foreach ($sales as $s) {
        if ($s['sale_status'] === 'Void' || $s['sale_status'] === 'Refunded') {
            continue;
        }

        $net = (float)$s['net_amount'];
        $total_dispensed_amount += $net;

        if (!empty($s['bill_id']) || $s['payment_status'] === 'Billed' || $s['payment_status'] === 'Paid') {
            $total_billed_amount += $net;
            if ($s['payment_status'] === 'Paid' || ($s['bill_payment_status'] ?? '') === 'Paid') {
                $total_paid_amount += $net;
            }
        } else {
            $total_pending_amount += $net;
        }
    }

    // 4. Inpatient Prescription Items vs Dispensed vs MAR
    $rx_stmt = $pdo->prepare("
        SELECT pi.item_id, pi.prescription_id, pi.medicine_id, m.medicine_name,
               pi.quantity AS prescribed_quantity,
               pi.dispensed_quantity,
               COALESCE((
                   SELECT SUM(administered_quantity)
                   FROM ipd_medicine_administration
                   WHERE prescription_item_id = pi.item_id AND status IN ('Given', 'Administered')
               ), 0) AS administered_quantity
        FROM prescription_items pi
        JOIN prescriptions rx ON pi.prescription_id = rx.prescription_id
        JOIN medicines m ON pi.medicine_id = m.medicine_id
        WHERE rx.admission_id = ?
        ORDER BY pi.item_id ASC
    ");
    $rx_stmt->execute([$admission_id]);
    $items = $rx_stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_prescribed_units   = 0;
    $total_dispensed_units    = 0;
    $total_administered_units = 0;
    $clinical_invariants_hold = true;

    foreach ($items as $it) {
        $p = (int)$it['prescribed_quantity'];
        $d = (int)$it['dispensed_quantity'];
        $a = (int)$it['administered_quantity'];

        $total_prescribed_units   += $p;
        $total_dispensed_units    += $d;
        $total_administered_units += $a;

        if ($a > $d || $d > $p) {
            $clinical_invariants_hold = false;
        }
    }

    return [
        'admission'                => $adm,
        'sales_count'              => count($sales),
        'sales'                    => $sales,
        'items'                    => $items,
        'total_prescribed_units'   => $total_prescribed_units,
        'total_dispensed_units'    => $total_dispensed_units,
        'total_administered_units' => $total_administered_units,
        'total_dispensed_amount'   => round($total_dispensed_amount, 2),
        'total_billed_amount'      => round($total_billed_amount, 2),
        'total_paid_amount'        => round($total_paid_amount, 2),
        'total_pending_amount'     => round($total_pending_amount, 2),
        'clinical_invariants_hold' => $clinical_invariants_hold,
        'financial_reconciled'     => (abs($total_dispensed_amount - ($total_billed_amount + $total_pending_amount)) < 0.01)
    ];
}

/**
 * Links pharmacy sales to an IPD bill idempotently.
 *
 * @param PDO    $pdo
 * @param int    $bill_id
 * @param array  $sale_ids
 * @param int    $user_id
 * @param string $bill_status 'Paid' | 'Pending' | 'Draft'
 */
function sync_pharmacy_sales_to_bill(PDO $pdo, int $bill_id, array $sale_ids, int $user_id, string $bill_status = 'Pending'): void
{
    if (empty($sale_ids)) {
        return;
    }

    $target_status = ($bill_status === 'Paid') ? 'Paid' : 'Billed';

    $upd = $pdo->prepare("
        UPDATE pharmacy_sales
        SET bill_id = ?, payment_status = ?
        WHERE sale_id = ? AND status = 'Completed'
    ");

    foreach ($sale_ids as $sid) {
        $sid = (int)$sid;
        if ($sid > 0) {
            $upd->execute([$bill_id, $target_status, $sid]);
        }
    }
}

/**
 * Releases linked pharmacy sales back to Pending if a bill is deleted or voided.
 *
 * @param PDO         $pdo
 * @param int         $bill_id
 * @param int         $user_id
 * @param string|null $reason
 */
function release_pharmacy_sales_from_bill(PDO $pdo, int $bill_id, int $user_id, ?string $reason = null): void
{
    $pdo->prepare("
        UPDATE pharmacy_sales
        SET bill_id = NULL, payment_status = 'Pending'
        WHERE bill_id = ? AND status = 'Completed'
    ")->execute([$bill_id]);

    log_activity(
        $pdo,
        $user_id,
        "Released pharmacy sales from voided/deleted Bill #{$bill_id}. Reason: {$reason}",
        'billing'
    );
}

/**
 * Computes exact return eligibility for all items in a pharmacy sale.
 * Enforces:
 * - Counter sales: Eligible = Sold - Previously Returned
 * - IPD sales: Eligible = Dispensed - Administered (MAR) - Previously Returned
 *
 * @param PDO $pdo
 * @param int $sale_id
 * @return array Sale metadata and line items with return eligibility details
 * @throws Exception
 */
function get_sale_return_eligibility(PDO $pdo, int $sale_id): array
{
    $sale_stmt = $pdo->prepare("
        SELECT s.*,
               COALESCE(NULLIF(CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, '')), ' '), s.customer_name, 'Walk-in Customer') AS patient_display_name,
               b.receipt_no AS bill_receipt_no, b.paid_amount AS bill_paid_amount, b.total_amount AS bill_total_amount
        FROM pharmacy_sales s
        LEFT JOIN patients p ON s.patient_id = p.patient_id
        LEFT JOIN bills b ON s.bill_id = b.bill_id
        WHERE s.sale_id = ?
    ");
    $sale_stmt->execute([$sale_id]);
    $sale = $sale_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sale) {
        throw new Exception("Pharmacy Sale #{$sale_id} not found.");
    }

    $is_void = ($sale['status'] === 'Void');

    // Fetch line items
    $item_stmt = $pdo->prepare("
        SELECT si.*, m.medicine_name, mb.batch_number, mb.expiry_date, mb.quantity_available, mb.status AS batch_status,
               COALESCE((
                   SELECT SUM(sri.quantity)
                   FROM pharmacy_sales_return_items sri
                   JOIN pharmacy_sales_returns sr ON sri.return_id = sr.return_id
                   WHERE sr.sale_id = si.sale_id
                     AND (sri.sale_item_id = si.item_id OR (sri.sale_item_id IS NULL AND sri.medicine_id = si.medicine_id AND (sri.batch_id = si.batch_id OR (sri.batch_id IS NULL AND si.batch_id IS NULL))))
                     AND sr.status != 'Void'
               ), 0) AS previously_returned_qty
        FROM sale_items si
        JOIN medicines m ON si.medicine_id = m.medicine_id
        LEFT JOIN medicine_batches mb ON si.batch_id = mb.batch_id
        WHERE si.sale_id = ?
        ORDER BY si.item_id ASC
    ");
    $item_stmt->execute([$sale_id]);
    $raw_items = $item_stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    $total_eligible_qty = 0;

    foreach ($raw_items as $it) {
        $sold_qty = (int)$it['quantity'];
        $prev_ret = (int)$it['previously_returned_qty'];
        $item_rem = max(0, $sold_qty - $prev_ret);

        $admin_qty = 0;
        $rx_prescribed_qty = 0;
        $rx_dispensed_qty = 0;
        $eligible_qty = $item_rem;

        if (!empty($it['prescription_item_id'])) {
            // IPD Dispensing Item: Query MAR
            $pi_stmt = $pdo->prepare("
                SELECT pi.quantity, pi.dispensed_quantity,
                       COALESCE((
                           SELECT SUM(administered_quantity)
                           FROM ipd_medicine_administration
                           WHERE prescription_item_id = pi.item_id AND status IN ('Administered', 'Given')
                       ), 0) AS total_administered,
                       COALESCE((
                           SELECT SUM(sri.quantity)
                           FROM pharmacy_sales_return_items sri
                           JOIN pharmacy_sales_returns sr ON sri.return_id = sr.return_id
                           WHERE sri.prescription_item_id = pi.item_id AND sr.status != 'Void'
                       ), 0) AS total_pi_returned
                FROM prescription_items pi
                WHERE pi.item_id = ?
            ");
            $pi_stmt->execute([(int)$it['prescription_item_id']]);
            $pi = $pi_stmt->fetch(PDO::FETCH_ASSOC);

            if ($pi) {
                $rx_prescribed_qty = (int)$pi['quantity'];
                $rx_dispensed_qty = (int)$pi['dispensed_quantity'];
                $admin_qty = (int)$pi['total_administered'];
                $pi_returned = (int)$pi['total_pi_returned'];

                // Overall unadministered in ward
                $unadministered_in_ward = max(0, $rx_dispensed_qty - $admin_qty - $pi_returned);
                // Cannot return more than this line's remaining, and cannot return more than ward unadministered
                $eligible_qty = min($item_rem, $unadministered_in_ward);
            }
        }

        if ($is_void) {
            $eligible_qty = 0;
        }

        $unit_price = ($sold_qty > 0 && !empty($it['total_amount'])) ? round((float)$it['total_amount'] / $sold_qty, 2) : (float)$it['price'];
        $total_eligible_qty += $eligible_qty;

        $items[] = [
            'item_id'                 => (int)$it['item_id'],
            'sale_id'                 => (int)$it['sale_id'],
            'prescription_item_id'    => !empty($it['prescription_item_id']) ? (int)$it['prescription_item_id'] : null,
            'medicine_id'             => (int)$it['medicine_id'],
            'medicine_name'           => $it['medicine_name'],
            'batch_id'                => !empty($it['batch_id']) ? (int)$it['batch_id'] : null,
            'batch_number'            => $it['batch_number'],
            'expiry_date'             => $it['expiry_date'],
            'sold_quantity'           => $sold_qty,
            'previously_returned_qty' => $prev_ret,
            'administered_qty'        => $admin_qty,
            'prescribed_qty'          => $rx_prescribed_qty,
            'dispensed_qty'           => $rx_dispensed_qty,
            'eligible_return_qty'     => $eligible_qty,
            'unit_effective_price'    => $unit_price,
            'is_expired'              => (!empty($it['expiry_date']) && strtotime($it['expiry_date']) < strtotime(date('Y-m-d')))
        ];
    }

    return [
        'sale'               => $sale,
        'items'              => $items,
        'total_eligible_qty' => $total_eligible_qty,
        'can_return'         => (!$is_void && $total_eligible_qty > 0),
        'void_reason'        => $is_void ? 'This sale has been voided/cancelled and cannot be returned.' : null
    ];
}

/**
 * Processes an atomic, production-safe pharmacy sale return with stock restoration and refund integration.
 *
 * Invariants Enforced:
 * 1. Administered <= Dispensed <= Prescribed (MAR safety boundary: administered quantity cannot be returned)
 * 2. Returned <= eligible dispensed quantity
 * 3. Exact original batch allocation restored (no arbitrary batch swapping)
 * 4. Condition classification: Saleable (if unexpired) restores stock; Expired/Quarantined/Damaged does NOT inflate saleable stock
 * 5. Refund calculation server-side: Refunded <= eligible paid amount
 * 6. Central billing & payments integration: atomic refund record created if funds were paid
 * 7. Concurrency protection via SELECT ... FOR UPDATE
 * 8. Immutable stock ledger records created
 *
 * @param PDO   $pdo
 * @param int   $sale_id
 * @param array $return_items Array of items to return:
 *     [
 *         [
 *             'sale_item_id'     => int,
 *             'medicine_id'      => int,
 *             'batch_id'         => int|null,
 *             'quantity'         => int,
 *             'condition_status' => 'Saleable'|'Quarantine'|'Expired'|'Damaged',
 *             'return_reason'    => string
 *         ], ...
 *     ]
 * @param array $options [
 *     'user_id'       => int,
 *     'return_reason' => string,
 *     'payment_mode'  => string,
 *     'return_type'   => string
 * ]
 * @return array
 * @throws Exception
 */
function process_pharmacy_sale_return(PDO $pdo, int $sale_id, array $return_items, array $options = []): array
{
    if (empty($return_items)) {
        throw new InvalidArgumentException("Return payload cannot be empty. Select at least one item to return.");
    }

    $should_commit = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $should_commit = true;
    }

    try {
        $user_id = (int)($options['user_id'] ?? (function_exists('current_user_id') ? current_user_id() : 1));
        $global_reason = trim((string)($options['return_reason'] ?? 'Customer return'));
        $payment_mode = trim((string)($options['payment_mode'] ?? 'Cash'));
        if ($payment_mode === '') $payment_mode = 'Cash';

        // 1. Lock the sale record
        $s_stmt = $pdo->prepare("SELECT * FROM pharmacy_sales WHERE sale_id = ? FOR UPDATE");
        $s_stmt->execute([$sale_id]);
        $sale = $s_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sale) {
            throw new Exception("Pharmacy sale #{$sale_id} not found.");
        }

        if ($sale['status'] === 'Void') {
            throw new Exception("Sale #{$sale['receipt_no']} is voided and cannot be returned.");
        }

        $return_type = !empty($options['return_type']) ? $options['return_type'] : (!empty($sale['admission_id']) ? 'IPD' : 'Counter');

        // 2. Lock and index all sale items for this sale
        $si_stmt = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id = ? FOR UPDATE");
        $si_stmt->execute([$sale_id]);
        $raw_sale_items = $si_stmt->fetchAll(PDO::FETCH_ASSOC);

        $sale_items_by_id = [];
        foreach ($raw_sale_items as $si) {
            $sale_items_by_id[(int)$si['item_id']] = $si;
        }

        $processed_items = [];
        $total_refund_amount = 0.00;
        $total_returned_units = 0;
        $distinct_items_count = 0;

        foreach ($return_items as $ret_req) {
            $req_sale_item_id = isset($ret_req['sale_item_id']) ? (int)$ret_req['sale_item_id'] : 0;
            $req_med_id       = (int)($ret_req['medicine_id'] ?? 0);
            $req_batch_id     = !empty($ret_req['batch_id']) ? (int)$ret_req['batch_id'] : null;
            $return_qty       = (int)($ret_req['quantity'] ?? 0);
            $condition_status = trim((string)($ret_req['condition_status'] ?? 'Saleable'));
            $item_reason      = trim((string)($ret_req['return_reason'] ?? $global_reason));

            if (!in_array($condition_status, ['Saleable', 'Quarantine', 'Expired', 'Damaged'], true)) {
                $condition_status = 'Saleable';
            }

            if ($return_qty <= 0) {
                continue; // Skip zero/negative quantity lines
            }

            // Find matching sale item
            $matched_item = null;
            if ($req_sale_item_id > 0 && isset($sale_items_by_id[$req_sale_item_id])) {
                $matched_item = $sale_items_by_id[$req_sale_item_id];
            } else {
                // Find by medicine and batch
                foreach ($sale_items_by_id as $si) {
                    if ((int)$si['medicine_id'] === $req_med_id && ($req_batch_id === null || (int)$si['batch_id'] === $req_batch_id)) {
                        $matched_item = $si;
                        break;
                    }
                }
            }

            if (!$matched_item) {
                throw new Exception("Sale item not found for return request (Medicine #{$req_med_id}, Batch #{$req_batch_id}).");
            }

            $sale_item_id = (int)$matched_item['item_id'];
            $medicine_id  = (int)$matched_item['medicine_id'];
            $orig_batch_id = !empty($matched_item['batch_id']) ? (int)$matched_item['batch_id'] : null;

            // Strict exact batch validation
            if ($req_batch_id !== null && $orig_batch_id !== null && $req_batch_id !== $orig_batch_id) {
                throw new Exception("Batch mismatch: Item #{$sale_item_id} was sold from Batch #{$orig_batch_id}, cannot return into Batch #{$req_batch_id}.");
            }
            if ($req_med_id > 0 && $req_med_id !== $medicine_id) {
                throw new Exception("Medicine mismatch: Item #{$sale_item_id} belongs to Medicine #{$medicine_id}, cannot return as Medicine #{$req_med_id}.");
            }

            $batch_id = $orig_batch_id;
            $sold_qty = (int)$matched_item['quantity'];

            // 3. Check previously returned quantity for this sale item with FOR UPDATE lock
            $chk_ret = $pdo->prepare("
                SELECT COALESCE(SUM(sri.quantity), 0)
                FROM pharmacy_sales_return_items sri
                JOIN pharmacy_sales_returns sr ON sri.return_id = sr.return_id
                WHERE sr.sale_id = ?
                  AND (sri.sale_item_id = ? OR (sri.sale_item_id IS NULL AND sri.medicine_id = ? AND (sri.batch_id = ? OR (sri.batch_id IS NULL AND ? IS NULL))))
                  AND sr.status != 'Void'
                FOR UPDATE
            ");
            $chk_ret->execute([$sale_id, $sale_item_id, $medicine_id, $batch_id, $batch_id]);
            $prev_returned_qty = (int)$chk_ret->fetchColumn();

            $max_item_returnable = max(0, $sold_qty - $prev_returned_qty);
            if ($return_qty > $max_item_returnable) {
                throw new Exception("Return quantity ({$return_qty}) exceeds eligible remaining quantity ({$max_item_returnable}) for item #{$sale_item_id}.");
            }

            // 4. IPD MAR Validation: Administered quantity cannot be returned
            $prescription_item_id = !empty($matched_item['prescription_item_id']) ? (int)$matched_item['prescription_item_id'] : null;
            if ($prescription_item_id > 0) {
                // Lock prescription item
                $pi_stmt = $pdo->prepare("SELECT quantity, dispensed_quantity FROM prescription_items WHERE item_id = ? FOR UPDATE");
                $pi_stmt->execute([$prescription_item_id]);
                $pi = $pi_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$pi) {
                    throw new Exception("Prescription item #{$prescription_item_id} not found.");
                }

                $pi_dispensed = (int)$pi['dispensed_quantity'];

                // Query MAR administered quantity
                $mar_stmt = $pdo->prepare("
                    SELECT COALESCE(SUM(administered_quantity), 0)
                    FROM ipd_medicine_administration
                    WHERE prescription_item_id = ? AND status IN ('Administered', 'Given')
                    FOR UPDATE
                ");
                $mar_stmt->execute([$prescription_item_id]);
                $pi_administered = (int)$mar_stmt->fetchColumn();

                // Query total returns across all sales for this prescription item
                $pi_ret_stmt = $pdo->prepare("
                    SELECT COALESCE(SUM(sri.quantity), 0)
                    FROM pharmacy_sales_return_items sri
                    JOIN pharmacy_sales_returns sr ON sri.return_id = sr.return_id
                    WHERE sri.prescription_item_id = ? AND sr.status != 'Void'
                    FOR UPDATE
                ");
                $pi_ret_stmt->execute([$prescription_item_id]);
                $total_pi_returned = (int)$pi_ret_stmt->fetchColumn();

                $unadministered_in_ward = max(0, $pi_dispensed - $pi_administered - $total_pi_returned);

                if ($return_qty > $unadministered_in_ward) {
                    throw new Exception("Cannot return {$return_qty} units: only {$unadministered_in_ward} unadministered units remain in ward for prescription item #{$prescription_item_id} (Dispensed: {$pi_dispensed}, Administered: {$pi_administered}, Previously Returned: {$total_pi_returned}).");
                }
            }

            // 5. Calculate line refund server-side
            $unit_effective_price = ($sold_qty > 0 && !empty($matched_item['total_amount']))
                ? ((float)$matched_item['total_amount'] / $sold_qty)
                : (float)$matched_item['price'];
            $line_refund = round($return_qty * $unit_effective_price, 2);

            // 6. Lock Batch & Medicine Master
            $batch_row = null;
            if ($batch_id > 0) {
                $b_stmt = $pdo->prepare("SELECT * FROM medicine_batches WHERE batch_id = ? FOR UPDATE");
                $b_stmt->execute([$batch_id]);
                $batch_row = $b_stmt->fetch(PDO::FETCH_ASSOC);
                if (!$batch_row) {
                    throw new Exception("Original batch #{$batch_id} not found in catalog.");
                }
            }

            $med_stmt = $pdo->prepare("SELECT * FROM medicines WHERE medicine_id = ? FOR UPDATE");
            $med_stmt->execute([$medicine_id]);
            $med_row = $med_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$med_row) {
                throw new Exception("Medicine #{$medicine_id} not found.");
            }

            // 7. Expiry & Quarantine Safety Check
            $restored_to_stock = 0;
            $final_condition = $condition_status;

            if ($final_condition === 'Saleable') {
                if ($batch_row && !empty($batch_row['expiry_date']) && strtotime($batch_row['expiry_date']) < strtotime(date('Y-m-d'))) {
                    // Batch has expired! Never restore to saleable stock
                    $final_condition = 'Expired';
                    $restored_to_stock = 0;
                } else {
                    $restored_to_stock = 1;
                }
            } else {
                $restored_to_stock = 0;
            }

            // 8. Restore Stock if Saleable
            $bal_before = (int)$med_row['stock_quantity'];
            $bal_after = $bal_before;

            if ($restored_to_stock === 1) {
                // Restore batch quantity
                if ($batch_row) {
                    $new_b_qty = (int)$batch_row['quantity_available'] + $return_qty;
                    $new_b_st = ($batch_row['status'] === 'Depleted') ? 'Active' : $batch_row['status'];
                    $pdo->prepare("UPDATE medicine_batches SET quantity_available = ?, status = ? WHERE batch_id = ?")
                        ->execute([$new_b_qty, $new_b_st, $batch_id]);
                }

                // Restore master stock
                $bal_after = $bal_before + $return_qty;
                $pdo->prepare("UPDATE medicines SET stock_quantity = ? WHERE medicine_id = ?")
                    ->execute([$bal_after, $medicine_id]);
            } elseif ($final_condition === 'Expired' && $batch_row && $batch_row['status'] !== 'Expired') {
                $pdo->prepare("UPDATE medicine_batches SET status = 'Expired' WHERE batch_id = ?")
                    ->execute([$batch_id]);
            }

            $processed_items[] = [
                'sale_item_id'         => $sale_item_id,
                'prescription_item_id' => $prescription_item_id,
                'medicine_id'          => $medicine_id,
                'batch_id'             => $batch_id,
                'quantity'             => $return_qty,
                'price'                => (float)$matched_item['price'],
                'unit_effective_price' => $unit_effective_price,
                'refund_amount'        => $line_refund,
                'condition_status'     => $final_condition,
                'restored_to_stock'    => $restored_to_stock,
                'return_reason'        => $item_reason,
                'bal_before'           => $bal_before,
                'bal_after'            => $bal_after,
                'batch_row'            => $batch_row
            ];

            $total_refund_amount += $line_refund;
            $total_returned_units += $return_qty;
            $distinct_items_count++;
        }

        if (empty($processed_items)) {
            throw new Exception("No valid items or quantities specified for return.");
        }

        // 9. Central Billing & Financial Refund Validation
        $bill_id = !empty($sale['bill_id']) ? (int)$sale['bill_id'] : null;
        $refund_id = null;

        if ($bill_id > 0) {
            $b_stmt = $pdo->prepare("SELECT * FROM bills WHERE bill_id = ? FOR UPDATE");
            $b_stmt->execute([$bill_id]);
            $bill = $b_stmt->fetch(PDO::FETCH_ASSOC);

            if ($bill) {
                if ($bill['status'] === 'Deleted') {
                    throw new Exception("Central Bill #{$bill['receipt_no']} has been deleted / cancelled.");
                }

                $pay_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE bill_id = ? AND status = 'Completed' FOR UPDATE");
                $pay_stmt->execute([$bill_id]);
                $total_bill_paid = (float)$pay_stmt->fetchColumn();

                $ref_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM refunds WHERE bill_id = ? AND status = 'Active' FOR UPDATE");
                $ref_stmt->execute([$bill_id]);
                $total_bill_refunded = (float)$ref_stmt->fetchColumn();

                $remaining_refundable = max(0.00, $total_bill_paid - $total_bill_refunded);

                if ($total_bill_paid > 0 && $total_refund_amount > $remaining_refundable + 0.01) {
                    throw new Exception("Refund amount (₹" . number_format($total_refund_amount, 2) . ") exceeds eligible remaining paid amount (₹" . number_format($remaining_refundable, 2) . ") on Bill #{$bill['receipt_no']}.");
                }

                // If money was paid and refund amount > 0, create central refund record
                if ($total_bill_paid > 0 && $total_refund_amount > 0) {
                    $refund_number = generate_refund_number($pdo);
                    $ins_ref = $pdo->prepare("
                        INSERT INTO refunds 
                          (bill_id, patient_id, admission_id, amount, refund_date, payment_mode, reason, created_by, refund_number, status)
                        VALUES 
                          (?, ?, ?, ?, NOW(), ?, ?, ?, ?, 'Active')
                    ");
                    $ins_ref->execute([
                        $bill_id,
                        $sale['patient_id'] ?: $bill['patient_id'],
                        $sale['admission_id'] ?: $bill['admission_id'] ?? null,
                        $total_refund_amount,
                        $payment_mode,
                        $global_reason,
                        $user_id,
                        $refund_number
                    ]);
                    $refund_id = (int)$pdo->lastInsertId();

                    // Update bill balances atomically
                    $new_paid = max(0.00, (float)$bill['paid_amount'] - $total_refund_amount);
                    $new_total = max(0.00, (float)$bill['total_amount'] - $total_refund_amount);
                    $new_bal = max(0.00, $new_total - $new_paid);
                    $new_pay_st = ($new_bal <= 0.01) ? 'Paid' : ($new_paid > 0 ? 'Partial' : 'Pending');

                    $pdo->prepare("
                        UPDATE bills 
                        SET total_amount = ?, paid_amount = ?, balance_amount = ?, payment_status = ? 
                        WHERE bill_id = ?
                    ")->execute([$new_total, $new_paid, $new_bal, $new_pay_st, $bill_id]);
                }
            }
        }

        // 10. Generate sequential document number for return
        $return_number = generate_pharmacy_return_number($pdo);

        // 11. Insert pharmacy_sales_returns header
        $ins_sr = $pdo->prepare("
            INSERT INTO pharmacy_sales_returns 
              (return_number, return_type, sale_id, patient_id, admission_id, bill_id, refund_id, return_date, refund_amount, total_items, total_quantity, reason, status, created_by, created_at)
            VALUES 
              (?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, 'Completed', ?, NOW())
        ");
        $ins_sr->execute([
            $return_number,
            $return_type,
            $sale_id,
            $sale['patient_id'],
            $sale['admission_id'],
            $bill_id,
            $refund_id,
            $total_refund_amount,
            $distinct_items_count,
            $total_returned_units,
            $global_reason,
            $user_id
        ]);
        $return_id = (int)$pdo->lastInsertId();

        // 12. Insert return line items and write immutable stock ledger rows
        $ins_sri = $pdo->prepare("
            INSERT INTO pharmacy_sales_return_items
              (return_id, sale_item_id, prescription_item_id, medicine_id, batch_id, quantity, price, condition_status, refund_amount, restored_to_stock, return_reason)
            VALUES
              (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $led_stmt = $pdo->prepare("
            INSERT INTO pharmacy_stock_ledger 
            (medicine_id, batch_id, transaction_type, reference_id, reference_no, quantity_change, balance_before, balance_after, unit_cost, unit_price, reason, created_by, created_at) 
            VALUES (?, ?, 'SALE_RETURN', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        foreach ($processed_items as $pi) {
            $ins_sri->execute([
                $return_id,
                $pi['sale_item_id'],
                $pi['prescription_item_id'],
                $pi['medicine_id'],
                $pi['batch_id'],
                $pi['quantity'],
                $pi['price'],
                $pi['condition_status'],
                $pi['refund_amount'],
                $pi['restored_to_stock'],
                $pi['return_reason']
            ]);

            // If stock was restored or quarantined/expired, write immutable ledger entry
            $purchase_price = $pi['batch_row'] ? (float)$pi['batch_row']['purchase_price'] : 0.00;
            $sale_price     = $pi['batch_row'] ? (float)$pi['batch_row']['sale_price'] : (float)$pi['price'];
            $qty_delta      = ($pi['restored_to_stock'] === 1) ? $pi['quantity'] : 0;
            $ledger_notes   = "Return {$return_number} against sale #{$sale['receipt_no']} (Condition: {$pi['condition_status']})";

            $led_stmt->execute([
                $pi['medicine_id'],
                $pi['batch_id'],
                $return_id,
                $return_number,
                $qty_delta,
                $pi['bal_before'],
                $pi['bal_after'],
                $purchase_price,
                $sale_price,
                $ledger_notes,
                $user_id
            ]);
        }

        // 13. Check if all items on the sale have been fully returned
        $tot_sold_units = (int)$pdo->query("SELECT COALESCE(SUM(quantity), 0) FROM sale_items WHERE sale_id = {$sale_id}")->fetchColumn();
        $tot_ret_units  = (int)$pdo->query("
            SELECT COALESCE(SUM(sri.quantity), 0) 
            FROM pharmacy_sales_return_items sri
            JOIN pharmacy_sales_returns sr ON sri.return_id = sr.return_id
            WHERE sr.sale_id = {$sale_id} AND sr.status != 'Void'
        ")->fetchColumn();

        if ($tot_ret_units >= $tot_sold_units && $tot_sold_units > 0) {
            $pdo->prepare("UPDATE pharmacy_sales SET status = 'Refunded' WHERE sale_id = ?")->execute([$sale_id]);
        }

        // 14. Audit logging
        if (function_exists('log_receipt_audit')) {
            log_receipt_audit(
                $pdo,
                $bill_id ?: 0,
                'PHARM_RETURN',
                "Processed pharmacy return #{$return_number} for sale {$sale['receipt_no']}, Refund: ₹{$total_refund_amount}",
                ['new_receipt_number' => $return_number]
            );
        }

        log_activity(
            $pdo,
            $user_id,
            "Processed pharmacy return #{$return_number} for sale #{$sale['receipt_no']} (Refund: ₹" . number_format($total_refund_amount, 2) . ")",
            'pharmacy'
        );

        if ($should_commit) {
            $pdo->commit();
        }

        return [
            'success'        => true,
            'return_id'      => $return_id,
            'return_number'  => $return_number,
            'sale_id'        => $sale_id,
            'receipt_no'     => $sale['receipt_no'],
            'refund_id'      => $refund_id,
            'refund_amount'  => $total_refund_amount,
            'total_items'    => $distinct_items_count,
            'total_quantity' => $total_returned_units,
            'items'          => $processed_items
        ];

    } catch (Exception $e) {
        if ($should_commit && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Multi-way reconciliation engine for pharmacy returns, IPD dispensing, MAR, billing, and financials.
 *
 * @param PDO      $pdo
 * @param int|null $sale_id
 * @param int|null $admission_id
 * @return array
 */
function reconcile_pharmacy_returns_and_refunds(PDO $pdo, ?int $sale_id = null, ?int $admission_id = null): array
{
    $reconciliation = [
        'sales_reconciliation'     => [],
        'ipd_reconciliation'       => [],
        'financial_reconciliation' => [],
        'stock_reconciliation'     => [
            'is_equilibrated'    => true,
            'discrepancy_count'  => 0,
            'discrepancies'      => []
        ]
    ];

    // 1. Sales Reconciliation
    $sale_filter = ($sale_id > 0) ? "WHERE s.sale_id = " . (int)$sale_id : "";
    $sales_data = $pdo->query("
        SELECT s.sale_id, s.receipt_no, s.sale_type, s.net_amount, s.status,
               COALESCE((SELECT SUM(quantity) FROM sale_items WHERE sale_id = s.sale_id), 0) AS sold_qty,
               COALESCE((
                   SELECT SUM(sri.quantity)
                   FROM pharmacy_sales_return_items sri
                   JOIN pharmacy_sales_returns sr ON sri.return_id = sr.return_id
                   WHERE sr.sale_id = s.sale_id AND sr.status != 'Void'
               ), 0) AS returned_qty
        FROM pharmacy_sales s
        {$sale_filter}
        ORDER BY s.sale_id DESC LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($sales_data as $sd) {
        $sold = (int)$sd['sold_qty'];
        $ret  = (int)$sd['returned_qty'];
        $net  = $sold - $ret;
        $reconciliation['sales_reconciliation'][] = [
            'sale_id'       => (int)$sd['sale_id'],
            'receipt_no'    => $sd['receipt_no'],
            'sold_quantity' => $sold,
            'returned_qty'  => $ret,
            'net_sold_qty'  => $net,
            'reconciled'    => ($net >= 0 && ($sold - $ret === $net))
        ];
    }

    // 2. IPD Clinical MAR Reconciliation
    if ($admission_id > 0) {
        $rx_items = $pdo->query("
            SELECT pi.item_id, pi.prescription_id, pi.medicine_id, m.medicine_name,
                   pi.quantity AS prescribed_qty,
                   pi.dispensed_quantity AS dispensed_qty,
                   COALESCE((
                       SELECT SUM(administered_quantity)
                       FROM ipd_medicine_administration
                       WHERE prescription_item_id = pi.item_id AND status IN ('Administered', 'Given')
                   ), 0) AS administered_qty,
                   COALESCE((
                       SELECT SUM(sri.quantity)
                       FROM pharmacy_sales_return_items sri
                       JOIN pharmacy_sales_returns sr ON sri.return_id = sr.return_id
                       WHERE sri.prescription_item_id = pi.item_id AND sr.status != 'Void'
                   ), 0) AS returned_qty
            FROM prescription_items pi
            JOIN prescriptions rx ON pi.prescription_id = rx.prescription_id
            JOIN medicines m ON pi.medicine_id = m.medicine_id
            WHERE rx.admission_id = {$admission_id}
            ORDER BY pi.item_id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rx_items as $rxi) {
            $p = (int)$rxi['prescribed_qty'];
            $d = (int)$rxi['dispensed_qty'];
            $a = (int)$rxi['administered_qty'];
            $r = (int)$rxi['returned_qty'];
            $rem = $d - $a - $r;

            $inv_holds = ($a <= $d && $r <= ($d - $a) && $d <= $p && $rem >= 0);

            $reconciliation['ipd_reconciliation'][] = [
                'item_id'                => (int)$rxi['item_id'],
                'medicine_name'          => $rxi['medicine_name'],
                'prescribed_quantity'    => $p,
                'dispensed_quantity'     => $d,
                'administered_quantity'  => $a,
                'returned_quantity'      => $r,
                'remaining_eligible_qty' => $rem,
                'invariant_holds'        => $inv_holds
            ];
        }
    }

    // 3. Financial Reconciliation
    $fin_sql = ($sale_id > 0)
        ? "SELECT s.sale_id, s.receipt_no, s.net_amount, s.bill_id,
                  COALESCE((
                      SELECT SUM(p.amount)
                      FROM payments p
                      WHERE p.bill_id = s.bill_id AND p.status = 'Completed'
                  ), 0) AS paid_amount,
                  COALESCE((
                      SELECT SUM(r.amount)
                      FROM refunds r
                      WHERE r.bill_id = s.bill_id AND r.status = 'Active'
                  ), 0) AS refunded_amount
           FROM pharmacy_sales s
           LEFT JOIN bills b ON s.bill_id = b.bill_id
           WHERE s.sale_id = " . (int)$sale_id
        : "SELECT b.bill_id, b.receipt_no,
                  COALESCE((
                      SELECT SUM(p.amount)
                      FROM payments p
                      WHERE p.bill_id = b.bill_id AND p.status = 'Completed'
                  ), 0) AS paid_amount,
                  COALESCE((SELECT SUM(amount) FROM refunds WHERE bill_id = b.bill_id AND status = 'Active'), 0) AS refunded_amount
           FROM bills b
           WHERE b.bill_type = 'Pharmacy' AND b.status != 'Deleted'
           ORDER BY b.bill_id DESC LIMIT 50";

    $fin_data = $pdo->query($fin_sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($fin_data as $fd) {
        $paid = (float)$fd['paid_amount'];
        $ref  = (float)$fd['refunded_amount'];
        $net  = max(0.00, $paid - $ref);

        $reconciliation['financial_reconciliation'][] = [
            'identifier'      => $fd['receipt_no'],
            'paid_amount'     => $paid,
            'refunded_amount' => $ref,
            'net_paid'        => $net,
            'reconciled'      => ($ref <= $paid + 0.01)
        ];
    }

    // 4. Stock Equilibrium Reconciliation
    $meds = $pdo->query("
        SELECT m.medicine_id, m.medicine_name, m.stock_quantity,
               COALESCE((
                   SELECT SUM(quantity_available)
                   FROM medicine_batches
                   WHERE medicine_id = m.medicine_id
               ), 0) AS batch_total
        FROM medicines m
        WHERE m.deleted_at IS NULL
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($meds as $m) {
        $master = (int)$m['stock_quantity'];
        $batches = (int)$m['batch_total'];
        if ($master !== $batches) {
            $reconciliation['stock_reconciliation']['is_equilibrated'] = false;
            $reconciliation['stock_reconciliation']['discrepancy_count']++;
            $reconciliation['stock_reconciliation']['discrepancies'][] = [
                'medicine_id'   => (int)$m['medicine_id'],
                'medicine_name' => $m['medicine_name'],
                'master_stock'  => $master,
                'batch_stock'   => $batches,
                'delta'         => $master - $batches
            ];
        }
    }

    return $reconciliation;
}


/**
 * Atomically quarantine a pharmacy batch.
 * Locks the batch, sets status to 'Quarantined', records a pharmacy_quarantines row,
 * and writes an immutable QUARANTINE_TRANSFER movement to the stock ledger.
 * Note: Quarantine does NOT change quantity_available or master stock — it simply
 * makes the batch ineligible for FEFO allocation by changing its status.
 */
function quarantine_pharmacy_batch(
    PDO $pdo,
    int $medicine_id,
    int $batch_id,
    string $reason,
    int $user_id
): array {
    if (trim($reason) === '') {
        throw new InvalidArgumentException("A reason is required for quarantine actions.");
    }

    // Lock the batch
    $batch_stmt = $pdo->prepare("
        SELECT batch_id, batch_number, medicine_id, quantity_available, status, expiry_date
        FROM medicine_batches WHERE batch_id = ? AND medicine_id = ? FOR UPDATE
    ");
    $batch_stmt->execute([$batch_id, $medicine_id]);
    $batch = $batch_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$batch) {
        throw new Exception("Batch #{$batch_id} not found for medicine #{$medicine_id}.");
    }
    if ($batch['status'] === 'Quarantined') {
        throw new Exception("Batch '{$batch['batch_number']}' is already quarantined.");
    }
    if ($batch['status'] === 'Disposed') {
        throw new Exception("Batch '{$batch['batch_number']}' has been disposed and cannot be quarantined.");
    }

    $qty = (int)$batch['quantity_available'];

    // Update batch status
    $pdo->prepare("UPDATE medicine_batches SET status = 'Quarantined' WHERE batch_id = ?")->execute([$batch_id]);

    // Record quarantine
    $q_stmt = $pdo->prepare("
        INSERT INTO pharmacy_quarantines (medicine_id, batch_id, quantity, reason, quarantined_by, quarantine_date, status)
        VALUES (?, ?, ?, ?, ?, NOW(), 'Quarantined')
    ");
    $q_stmt->execute([$medicine_id, $batch_id, $qty, $reason, $user_id]);
    $quarantine_id = (int)$pdo->lastInsertId();

    // Stock ledger (informational; quantity_change = 0 since stock stays but becomes unsaleable)
    $med = $pdo->prepare("SELECT stock_quantity FROM medicines WHERE medicine_id = ?");
    $med->execute([$medicine_id]);
    $master_stock = (int)$med->fetchColumn();

    $pdo->prepare("
        INSERT INTO pharmacy_stock_ledger 
        (medicine_id, batch_id, transaction_type, reference_id, reference_no, quantity_change, balance_before, balance_after, reason, created_by, created_at)
        VALUES (?, ?, 'QUARANTINE_TRANSFER', ?, ?, 0, ?, ?, ?, ?, NOW())
    ")->execute([
        $medicine_id, $batch_id, $quarantine_id, "QUARANTINE-{$quarantine_id}",
        $master_stock, $master_stock, "[QUARANTINE] {$reason}", $user_id
    ]);

    log_activity($pdo, $user_id, "Quarantined batch '{$batch['batch_number']}' ({$qty} units) for medicine #{$medicine_id}: {$reason}", 'pharmacy');

    return [
        'quarantine_id' => $quarantine_id,
        'batch_id' => $batch_id,
        'batch_number' => $batch['batch_number'],
        'quantity' => $qty
    ];
}

/**
 * Release a quarantined batch back to Active (if not expired) or Expired status.
 */
function release_quarantine_batch(
    PDO $pdo,
    int $batch_id,
    string $release_reason,
    int $user_id
): array {
    if (trim($release_reason) === '') {
        throw new InvalidArgumentException("A reason is required for quarantine release.");
    }

    $batch_stmt = $pdo->prepare("
        SELECT batch_id, batch_number, medicine_id, quantity_available, status, expiry_date
        FROM medicine_batches WHERE batch_id = ? FOR UPDATE
    ");
    $batch_stmt->execute([$batch_id]);
    $batch = $batch_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$batch) {
        throw new Exception("Batch #{$batch_id} not found.");
    }
    if ($batch['status'] !== 'Quarantined') {
        throw new Exception("Batch '{$batch['batch_number']}' is not quarantined (current status: {$batch['status']}).");
    }

    // Determine target status based on expiry
    $new_status = 'Active';
    if (!empty($batch['expiry_date']) && $batch['expiry_date'] !== '0000-00-00') {
        if (strtotime($batch['expiry_date']) < strtotime(date('Y-m-d'))) {
            $new_status = 'Expired';
        }
    }

    $pdo->prepare("UPDATE medicine_batches SET status = ? WHERE batch_id = ?")->execute([$new_status, $batch_id]);

    // Update quarantine record
    $pdo->prepare("
        UPDATE pharmacy_quarantines SET released_at = NOW(), released_by = ?, release_reason = ?, status = 'Released'
        WHERE batch_id = ? AND status = 'Quarantined' ORDER BY quarantine_id DESC LIMIT 1
    ")->execute([$user_id, $release_reason, $batch_id]);

    // Ledger
    $medicine_id = (int)$batch['medicine_id'];
    $med = $pdo->prepare("SELECT stock_quantity FROM medicines WHERE medicine_id = ?");
    $med->execute([$medicine_id]);
    $master_stock = (int)$med->fetchColumn();

    $pdo->prepare("
        INSERT INTO pharmacy_stock_ledger 
        (medicine_id, batch_id, transaction_type, reference_no, quantity_change, balance_before, balance_after, reason, created_by, created_at)
        VALUES (?, ?, 'QUARANTINE_RELEASE', ?, 0, ?, ?, ?, ?, NOW())
    ")->execute([
        $medicine_id, $batch_id, "QREL-{$batch_id}",
        $master_stock, $master_stock, "[QUARANTINE RELEASE -> {$new_status}] {$release_reason}", $user_id
    ]);

    log_activity($pdo, $user_id, "Released batch '{$batch['batch_number']}' from quarantine -> {$new_status}: {$release_reason}", 'pharmacy');

    return [
        'batch_id' => $batch_id,
        'batch_number' => $batch['batch_number'],
        'new_status' => $new_status
    ];
}

/**
 * Atomically dispose of pharmacy stock from a specific batch.
 * Decrements batch and master stock, generates a disposal document number,
 * records a pharmacy_disposals row, and writes an immutable DISPOSAL ledger entry.
 * Disposed stock can NEVER re-enter saleable inventory.
 */
function dispose_pharmacy_batch(
    PDO $pdo,
    int $medicine_id,
    int $batch_id,
    int $quantity,
    string $reason,
    string $disposal_method,
    int $user_id,
    ?int $authorized_by = null
): array {
    if ($quantity <= 0) {
        throw new InvalidArgumentException("Disposal quantity must be greater than zero.");
    }
    if (trim($reason) === '') {
        throw new InvalidArgumentException("A reason is required for disposal.");
    }

    $valid_methods = ['Incineration', 'Chemical Inactivation', 'Return to Supplier', 'Biohazard Disposal', 'Landfill / Other'];
    if (!in_array($disposal_method, $valid_methods, true)) {
        throw new InvalidArgumentException("Invalid disposal method: '{$disposal_method}'.");
    }

    // Lock batch
    $batch_stmt = $pdo->prepare("
        SELECT batch_id, batch_number, medicine_id, quantity_available, status, purchase_price, sale_price
        FROM medicine_batches WHERE batch_id = ? AND medicine_id = ? FOR UPDATE
    ");
    $batch_stmt->execute([$batch_id, $medicine_id]);
    $batch = $batch_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$batch) {
        throw new Exception("Batch #{$batch_id} not found for medicine #{$medicine_id}.");
    }
    if ($batch['status'] === 'Disposed') {
        throw new Exception("Batch '{$batch['batch_number']}' has already been fully disposed.");
    }
    if ($quantity > (int)$batch['quantity_available']) {
        throw new Exception("Cannot dispose {$quantity} units. Batch '{$batch['batch_number']}' only has {$batch['quantity_available']} available.");
    }

    // Lock master medicine
    $med_stmt = $pdo->prepare("SELECT stock_quantity FROM medicines WHERE medicine_id = ? FOR UPDATE");
    $med_stmt->execute([$medicine_id]);
    $master_stock = (int)$med_stmt->fetchColumn();

    $new_batch_qty = (int)$batch['quantity_available'] - $quantity;
    $new_master_stock = $master_stock - $quantity;

    if ($new_master_stock < 0) {
        throw new Exception("Disposal would produce negative master stock ({$new_master_stock}). Rejected.");
    }

    // Update batch
    $new_status = $new_batch_qty <= 0 ? 'Disposed' : $batch['status'];
    $pdo->prepare("UPDATE medicine_batches SET quantity_available = ?, status = ? WHERE batch_id = ?")->execute([$new_batch_qty, $new_status, $batch_id]);

    // Update master stock
    $pdo->prepare("UPDATE medicines SET stock_quantity = ? WHERE medicine_id = ?")->execute([$new_master_stock, $medicine_id]);

    // Generate disposal number
    $disposal_no = generate_pharmacy_disposal_number($pdo);

    // Insert disposal record
    $d_stmt = $pdo->prepare("
        INSERT INTO pharmacy_disposals 
        (disposal_no, medicine_id, batch_id, quantity, reason, disposal_method, disposed_by, authorized_by, disposal_date, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), NOW())
    ");
    $d_stmt->execute([$disposal_no, $medicine_id, $batch_id, $quantity, $reason, $disposal_method, $user_id, $authorized_by]);
    $disposal_id = (int)$pdo->lastInsertId();

    // Immutable ledger entry
    $pdo->prepare("
        INSERT INTO pharmacy_stock_ledger 
        (medicine_id, batch_id, transaction_type, reference_id, reference_no, quantity_change, balance_before, balance_after, unit_cost, unit_price, reason, created_by, created_at)
        VALUES (?, ?, 'DISPOSAL', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ")->execute([
        $medicine_id, $batch_id, $disposal_id, $disposal_no,
        -$quantity, $master_stock, $new_master_stock,
        (float)$batch['purchase_price'], (float)$batch['sale_price'],
        "[DISPOSAL] {$reason}", $user_id
    ]);

    log_activity($pdo, $user_id, "Disposed {$quantity} units of batch '{$batch['batch_number']}' (medicine #{$medicine_id}). Document: {$disposal_no}. Method: {$disposal_method}.", 'pharmacy');

    return [
        'disposal_id' => $disposal_id,
        'disposal_no' => $disposal_no,
        'batch_id' => $batch_id,
        'batch_number' => $batch['batch_number'],
        'quantity_disposed' => $quantity,
        'batch_remaining' => $new_batch_qty,
        'master_stock_after' => $new_master_stock
    ];
}

/**
 * Calculate saleable stock for a specific medicine, excluding expired, quarantined, and disposed batches.
 * Returns classification: OUT_OF_STOCK, LOW_STOCK, ADEQUATE, UNKNOWN_THRESHOLD.
 */
function calculate_medicine_saleable_stock(PDO $pdo, int $medicine_id): array
{
    $med = $pdo->prepare("SELECT medicine_id, medicine_name, stock_quantity, reorder_level FROM medicines WHERE medicine_id = ? AND deleted_at IS NULL");
    $med->execute([$medicine_id]);
    $medicine = $med->fetch(PDO::FETCH_ASSOC);
    if (!$medicine) {
        throw new Exception("Medicine #{$medicine_id} not found.");
    }

    $batch_stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN status = 'Active' AND expiry_date >= CURDATE() THEN quantity_available ELSE 0 END), 0) AS saleable,
            COALESCE(SUM(CASE WHEN status = 'Active' AND expiry_date < CURDATE() THEN quantity_available ELSE 0 END), 0) AS expired,
            COALESCE(SUM(CASE WHEN status = 'Quarantined' THEN quantity_available ELSE 0 END), 0) AS quarantined,
            COALESCE(SUM(CASE WHEN status = 'Disposed' THEN quantity_available ELSE 0 END), 0) AS disposed,
            COALESCE(SUM(CASE WHEN status = 'Active' AND (expiry_date IS NULL OR expiry_date = '0000-00-00') THEN quantity_available ELSE 0 END), 0) AS unknown_expiry,
            COALESCE(SUM(quantity_available), 0) AS total_batch_stock
        FROM medicine_batches WHERE medicine_id = ?
    ");
    $batch_stmt->execute([$medicine_id]);
    $stocks = $batch_stmt->fetch(PDO::FETCH_ASSOC);

    $saleable = (int)$stocks['saleable'];
    $reorder = (int)$medicine['reorder_level'];

    if ($reorder <= 0) {
        $classification = $saleable <= 0 ? 'OUT_OF_STOCK' : 'UNKNOWN_THRESHOLD';
    } elseif ($saleable <= 0) {
        $classification = 'OUT_OF_STOCK';
    } elseif ($saleable <= $reorder) {
        $classification = 'LOW_STOCK';
    } else {
        $classification = 'ADEQUATE';
    }

    return [
        'medicine_id' => (int)$medicine['medicine_id'],
        'medicine_name' => $medicine['medicine_name'],
        'master_stock' => (int)$medicine['stock_quantity'],
        'reorder_level' => $reorder,
        'saleable_stock' => $saleable,
        'expired_stock' => (int)$stocks['expired'],
        'quarantined_stock' => (int)$stocks['quarantined'],
        'disposed_stock' => (int)$stocks['disposed'],
        'unknown_expiry_stock' => (int)$stocks['unknown_expiry'],
        'total_batch_stock' => (int)$stocks['total_batch_stock'],
        'classification' => $classification
    ];
}


