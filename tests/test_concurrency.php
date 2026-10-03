<?php
// tests/test_concurrency.php - Concurrency Verification Test (STEP 20)

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

echo "\n============================================================\n";
echo " PHARMACY DOCUMENT SEQUENCE CONCURRENCY VERIFICATION TEST \n";
echo "============================================================\n\n";

$workersCount = 5;
$itemsPerWorker = 15;
$expectedTotal = $workersCount * $itemsPerWorker;
$testKey = 'CONCURRENT_BATCH_' . time();

echo "Spawning {$workersCount} concurrent worker processes...\n";
echo "Each worker requesting {$itemsPerWorker} sequential document numbers for key: '{$testKey}'\n";
echo "Total expected numbers: {$expectedTotal}\n\n";

$phpBinary = 'C:\\xampp\\php\\php.exe';
$workerScript = __DIR__ . '/worker_sequence.php';

$processes = [];
$pipes = [];

// Launch workers concurrently
for ($i = 0; $i < $workersCount; $i++) {
    $cmd = "\"{$phpBinary}\" \"{$workerScript}\" {$itemsPerWorker} \"{$testKey}\"";
    $descriptors = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];
    $proc = proc_open($cmd, $descriptors, $pipes[$i]);
    if (is_resource($proc)) {
        $processes[$i] = $proc;
    }
}

$allOutputs = [];

// Gather output from each worker
for ($i = 0; $i < $workersCount; $i++) {
    if (isset($processes[$i])) {
        $stdout = stream_get_contents($pipes[$i][1]);
        $stderr = stream_get_contents($pipes[$i][2]);
        fclose($pipes[$i][0]);
        fclose($pipes[$i][1]);
        fclose($pipes[$i][2]);
        proc_close($processes[$i]);

        if ($stderr) {
            echo "Worker {$i} STDERR: {$stderr}\n";
        }

        $lines = array_filter(array_map('trim', explode("\n", $stdout)));
        foreach ($lines as $l) {
            $allOutputs[] = $l;
        }
    }
}

$totalCollected = count($allOutputs);
$uniqueCollected = count(array_unique($allOutputs));
$duplicates = $totalCollected - $uniqueCollected;

echo "Collected: {$totalCollected} numbers\n";
echo "Unique:    {$uniqueCollected} numbers\n";
echo "Duplicates/Collisions: {$duplicates}\n\n";

if ($totalCollected === $expectedTotal && $duplicates === 0) {
    echo "  [PASS] CONCURRENCY TEST: ZERO COLLISIONS DETECTED!\n";
    echo "  Every concurrent transaction received a strictly unique document number.\n\n";
    exit(0);
} else {
    echo "  [FAIL] CONCURRENCY TEST: Detected {$duplicates} duplicate number(s)!\n\n";
    exit(1);
}
