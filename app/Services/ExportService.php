<?php
// app/Services/ExportService.php - Safe CSV & Data Export Engine

namespace Pharmacy\Services;

use Exception;

class ExportService
{
    /**
     * Dangerous formula prefixes in spreadsheet software (Excel, LibreOffice, Google Sheets)
     */
    private const FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Sanitize a cell value to prevent CSV / Spreadsheet formula injection.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function sanitizeCell($value)
    {
        if ($value === null) {
            return '';
        }

        $str = (string)$value;
        if ($str === '') {
            return '';
        }

        // Check first character against dangerous formula triggers (=, +, -, @, \t, \r)
        $firstChar = $str[0];
        if (in_array($firstChar, self::FORMULA_TRIGGERS, true)) {
            // Prepend single quote to force spreadsheet programs to treat as text literal
            return "'" . $str;
        }

        if (is_numeric($value)) {
            return $value;
        }

        return $str;
    }

    /**
     * Sanitize an entire row.
     *
     * @param array $row
     * @return array
     */
    public static function sanitizeRow(array $row): array
    {
        $sanitized = [];
        foreach ($row as $k => $v) {
            $sanitized[$k] = self::sanitizeCell($v);
        }
        return $sanitized;
    }

    /**
     * Clean and format safe filename for HTTP download.
     *
     * @param string $filename
     * @param string $extension
     * @return string
     */
    public static function sanitizeFilename(string $filename, string $extension = 'csv'): string
    {
        // Strip path traversal characters
        $base = basename($filename);
        // Only allow alphanumeric, underscores, hyphens
        $clean = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $base);
        $clean = trim($clean, '_');
        if (empty($clean)) {
            $clean = 'export_' . date('Ymd_His');
        }
        return $clean . '.' . ltrim($extension, '.');
    }

    /**
     * Generate raw CSV string from headers and associative or indexed rows.
     *
     * @param array $headers List of column header names
     * @param array $rows Array of data rows (keyed or indexed)
     * @return string
     */
    public static function generateCsv(array $headers, array $rows): string
    {
        $fp = fopen('php://temp', 'r+');
        if (!$fp) {
            throw new Exception("Unable to open memory buffer for CSV generation.");
        }

        // Write UTF-8 BOM for Excel compatibility
        fwrite($fp, "\xEF\xBB\xBF");

        // Write sanitized headers
        $sanitizedHeaders = self::sanitizeRow($headers);
        fputcsv($fp, $sanitizedHeaders);

        // Write sanitized data rows
        foreach ($rows as $row) {
            $rowValues = [];
            if (is_array($row)) {
                // If keys match header keys or if indexed
                foreach ($row as $val) {
                    $rowValues[] = self::sanitizeCell($val);
                }
            } else {
                $rowValues[] = self::sanitizeCell($row);
            }
            fputcsv($fp, $rowValues);
        }

        rewind($fp);
        $csvContent = stream_get_contents($fp);
        fclose($fp);

        return $csvContent ?: '';
    }

    /**
     * Output CSV download directly to browser with security headers.
     *
     * @param string $filename
     * @param array $headers
     * @param array $rows
     * @return void
     */
    public static function streamCsvDownload(string $filename, array $headers, array $rows): void
    {
        $safeFilename = self::sanitizeFilename($filename, 'csv');

        // Prevent output buffering interference
        if (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
        header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');

        echo self::generateCsv($headers, $rows);
        exit;
    }
}
