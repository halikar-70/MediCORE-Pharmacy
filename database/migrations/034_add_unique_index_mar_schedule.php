<?php
// database/migrations/034_add_unique_index_mar_schedule.php

return function (PDO $pdo) {
    // Check if unique index already exists on pharmacy_mar_records
    $indexExists = false;
    $indexes = $pdo->query("SHOW INDEX FROM pharmacy_mar_records WHERE Key_name = 'uq_mar_slot'")->fetchAll();
    if (!empty($indexes)) {
        $indexExists = true;
    }

    if (!$indexExists) {
        $pdo->exec("
            ALTER TABLE pharmacy_mar_records
            ADD UNIQUE KEY uq_mar_slot (prescription_item_id, scheduled_date, scheduled_time)
        ");
    }
};
