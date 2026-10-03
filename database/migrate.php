<?php
// database/migrate.php - CLI Migration Runner

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Database/MigrationManager.php';

use Pharmacy\Database\MigrationManager;

echo "=== Pharmacy Database Migration Runner ===\n";

try {
    $manager = new MigrationManager($pdo);
    $newlyRan = $manager->runPending();

    if (empty($newlyRan)) {
        echo "Nothing to migrate. Database is already up to date.\n";
    } else {
        echo "Successfully executed " . count($newlyRan) . " migration(s):\n";
        foreach ($newlyRan as $m) {
            echo "  [OK] " . $m . "\n";
        }
    }
} catch (Exception $e) {
    echo "ERROR: Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
