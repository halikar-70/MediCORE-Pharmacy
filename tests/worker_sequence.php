<?php
// tests/worker_sequence.php - Worker process generating sequential numbers concurrently

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Services/DocumentSequenceService.php';

use Pharmacy\Services\DocumentSequenceService;

$count = isset($argv[1]) ? (int)$argv[1] : 10;
$key = isset($argv[2]) ? $argv[2] : 'CONCURRENCY_TEST';

$service = new DocumentSequenceService($pdo);
$generated = [];

for ($i = 0; $i < $count; $i++) {
    try {
        $num = $service->getNextNumber($key);
        $generated[] = $num;
        // Jitter to increase concurrent contention
        usleep(rand(500, 3000));
    } catch (Exception $e) {
        fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    }
}

echo implode("\n", $generated);
