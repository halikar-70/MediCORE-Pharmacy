<?php
// app/Database/Database.php - Dedicated Pharmacy Database Connector

namespace Pharmacy\Database;

use PDO;
use PDOException;
use Exception;

class Database
{
    private static ?PDO $pharmacyPdo = null;
    private static ?PDO $hospitalPdo = null;

    /**
     * Get the dedicated Pharmacy Database connection (pharmacy_db).
     *
     * @return PDO
     * @throws Exception
     */
    public static function getPharmacyConnection(): PDO
    {
        if (self::$pharmacyPdo === null) {
            $host = getenv('PHARMACY_DB_HOST') ?: '127.0.0.1';
            $port = getenv('PHARMACY_DB_PORT') ?: '3306';
            $dbname = getenv('PHARMACY_DB_NAME') ?: 'pharmacy_db';
            $user = getenv('PHARMACY_DB_USER') ?: 'root';
            $pass = getenv('PHARMACY_DB_PASS') !== false ? getenv('PHARMACY_DB_PASS') : '';

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$pharmacyPdo = new PDO($dsn, $user, $pass, $options);
                self::$pharmacyPdo->exec("SET time_zone = '+05:30'");
            } catch (PDOException $e) {
                // Never expose database credentials or internal dsn in production
                error_log("Pharmacy DB Connection Failure: " . $e->getMessage());
                throw new Exception("Pharmacy database connection failed. Please ensure the database server is running.");
            }
        }

        return self::$pharmacyPdo;
    }

    /**
     * Optional connection to Hospital/MediPro database for controlled integration queries.
     * Returns the shared connection if both point to the unified database, or opens a dedicated connection if configured differently.
     *
     * @return PDO|null
     */
    public static function getHospitalConnection(): ?PDO
    {
        $hHost = getenv('HOSPITAL_DB_HOST') ?: '127.0.0.1';
        $hPort = getenv('HOSPITAL_DB_PORT') ?: '3306';
        $hDbname = getenv('HOSPITAL_DB_NAME') ?: 'hospital_db';
        $hUser = getenv('HOSPITAL_DB_USER') ?: 'root';
        $hPass = getenv('HOSPITAL_DB_PASS') !== false ? getenv('HOSPITAL_DB_PASS') : '';

        $pHost = getenv('PHARMACY_DB_HOST') ?: '127.0.0.1';
        $pPort = getenv('PHARMACY_DB_PORT') ?: '3306';
        $pDbname = getenv('PHARMACY_DB_NAME') ?: 'pharmacy_db';
        $pUser = getenv('PHARMACY_DB_USER') ?: 'root';
        $pPass = getenv('PHARMACY_DB_PASS') !== false ? getenv('PHARMACY_DB_PASS') : '';

        // If both point to the same database configuration, reuse the single PDO instance
        if ($hHost === $pHost && $hPort === $pPort && $hDbname === $pDbname && $hUser === $pUser && $hPass === $pPass) {
            try {
                return self::getPharmacyConnection();
            } catch (Exception $e) {
                return null;
            }
        }

        if (self::$hospitalPdo === null) {
            $dsn = "mysql:host={$hHost};port={$hPort};dbname={$hDbname};charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$hospitalPdo = new PDO($dsn, $hUser, $hPass, $options);
                self::$hospitalPdo->exec("SET time_zone = '+05:30'");
            } catch (PDOException $e) {
                error_log("Hospital DB Connection Notice (non-fatal): " . $e->getMessage());
                return null;
            }
        }

        return self::$hospitalPdo;
    }

    /**
     * Reset connection instances (useful for testing).
     */
    public static function resetConnections(): void
    {
        self::$pharmacyPdo = null;
        self::$hospitalPdo = null;
    }
}
