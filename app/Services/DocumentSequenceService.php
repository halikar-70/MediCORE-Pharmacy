<?php
// app/Services/DocumentSequenceService.php - Concurrency-Safe Transaction Number Generator

namespace Pharmacy\Services;

use PDO;
use Exception;

class DocumentSequenceService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Generate the next document sequence number with strict ACID locking (SELECT ... FOR UPDATE).
     * Guaranteed zero duplicates and zero collisions under parallel/concurrent requests.
     *
     * @param string $sequenceKey e.g. 'PATIENT', 'COUNTER_SALE', 'REGULAR_SALE'
     * @param int $maxRetries Maximum retry attempts on transaction deadlock
     * @return string e.g. 'PP-000001', 'CS-000001'
     * @throws Exception
     */
    public function getNextNumber(string $sequenceKey, int $maxRetries = 3): string
    {
        $attempt = 0;

        while ($attempt < $maxRetries) {
            $attempt++;
            $manageTx = !$this->pdo->inTransaction();

            try {
                if ($manageTx) {
                    $this->pdo->beginTransaction();
                }

                // Row-level lock to serialize concurrent sequence requests
                $stmt = $this->pdo->prepare("
                    SELECT prefix, current_value, pad_length 
                    FROM pharmacy_sequences 
                    WHERE sequence_key = ? 
                    FOR UPDATE
                ");
                $stmt->execute([$sequenceKey]);
                $row = $stmt->fetch();

                if (!$row) {
                    // Fallback create baseline sequence if key doesn't exist yet
                    $prefix = match ($sequenceKey) {
                        'DIRECT_STOCK_IN' => 'DSI-',
                        'DIRECT_STOCK_OUT' => 'DSO-',
                        default => strtoupper(substr($sequenceKey, 0, 3)) . '-'
                    };
                    $padLength = 6;
                    $currentVal = 0;

                    $insertStmt = $this->pdo->prepare("
                        INSERT INTO pharmacy_sequences (sequence_key, prefix, current_value, pad_length, updated_at)
                        VALUES (?, ?, 0, ?, NOW())
                    ");
                    $insertStmt->execute([$sequenceKey, $prefix, $padLength]);

                    $nextVal = 1;
                } else {
                    $prefix = $row['prefix'];
                    $padLength = (int)$row['pad_length'];
                    $currentVal = (int)$row['current_value'];
                    $nextVal = $currentVal + 1;
                }

                // Update counter
                $updateStmt = $this->pdo->prepare("
                    UPDATE pharmacy_sequences 
                    SET current_value = ?, updated_at = NOW() 
                    WHERE sequence_key = ?
                ");
                $updateStmt->execute([$nextVal, $sequenceKey]);

                if ($manageTx) {
                    $this->pdo->commit();
                }

                // Format document number
                return $prefix . str_pad((string)$nextVal, $padLength, '0', STR_PAD_LEFT);

            } catch (Exception $e) {
                if ($manageTx && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                if ($attempt >= $maxRetries) {
                    throw new Exception("Document sequence generation for '{$sequenceKey}' failed after {$maxRetries} attempts: " . $e->getMessage(), 0, $e);
                }

                // Short exponential backoff (10ms - 50ms)
                usleep(rand(10000, 50000));
            }
        }

        throw new Exception("Document sequence generation for '{$sequenceKey}' timed out.");
    }

    /**
     * Alias method for getNextNumber()
     */
    public function generate(string $sequenceKey, ?string $prefix = null): string
    {
        return $this->getNextNumber($sequenceKey);
    }
}
