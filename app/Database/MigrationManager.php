<?php
// app/Database/MigrationManager.php - Database Migration Engine

namespace Pharmacy\Database;

use PDO;
use Exception;

class MigrationManager
{
    private PDO $pdo;
    private string $migrationsPath;

    public function __construct(PDO $pdo, ?string $migrationsPath = null)
    {
        $this->pdo = $pdo;
        $this->migrationsPath = $migrationsPath ?: dirname(__DIR__, 2) . '/database/migrations';
        $this->ensureMigrationsTable();
    }

    /**
     * Ensure the migrations tracking table exists.
     */
    private function ensureMigrationsTable(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS pharmacy_migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(191) NOT NULL UNIQUE,
                batch INT NOT NULL,
                executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        $this->pdo->exec($sql);
    }

    /**
     * Get list of already executed migrations.
     *
     * @return array
     */
    public function getExecutedMigrations(): array
    {
        $stmt = $this->pdo->query("SELECT migration FROM pharmacy_migrations ORDER BY id ASC");
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Execute all pending migrations.
     *
     * @return array List of newly executed migrations
     * @throws Exception
     */
    public function runPending(): array
    {
        $executed = $this->getExecutedMigrations();
        $files = glob($this->migrationsPath . '/*.php');
        sort($files);

        // Determine next batch number
        $stmt = $this->pdo->query("SELECT MAX(batch) FROM pharmacy_migrations");
        $nextBatch = ((int)$stmt->fetchColumn()) + 1;

        $ran = [];

        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (in_array($name, $executed, true)) {
                continue;
            }

            // Include migration definition
            $migration = require $file;
            if (is_callable($migration)) {
                try {
                    $migration($this->pdo);

                    $insertStmt = $this->pdo->prepare("INSERT INTO pharmacy_migrations (migration, batch, executed_at) VALUES (?, ?, NOW())");
                    $insertStmt->execute([$name, $nextBatch]);

                    if ($this->pdo->inTransaction()) {
                        $this->pdo->commit();
                    }
                    $ran[] = $name;
                } catch (Exception $e) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    throw new Exception("Migration [{$name}] failed: " . $e->getMessage(), 0, $e);
                }
            }
        }

        return $ran;
    }
}
