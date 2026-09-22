<?php
/**
 * CSV helpers shared by export-csv.php and the automated test suite.
 * Extracted from the original export-csv.php so the formula-injection
 * guard and the write path are unit-testable in CI.
 *
 * Pure functions — no globals, no session, no DB. Safe to include from any
 * context (CLI worker, HTTP export, test harness).
 */
declare(strict_types=1);

/**
 * Neutralise spreadsheet formula injection. Excel / LibreOffice / Google
 * Sheets treat a leading =, +, -, @, tab, CR, LF, or pipe as the start of
 * a formula. Prefixing the value with a single quote forces text mode.
 */
function csv_cell($v): string
{
    if ($v === null) return '';
    $s = (string) $v;
    if ($s !== '' && strpbrk($s[0], "=+-@\t\r\n|") !== false) {
        $s = "'" . $s;
    }
    return $s;
}

/**
 * Emit UTF-8 BOM + downloadable-CSV headers. HTTP-only; not called from
 * unit tests.
 */
function csv_start(string $filename): void
{
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $safe . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo "\xEF\xBB\xBF";
}

/**
 * Write a header row and every row from an iterable to php://output using
 * fputcsv. Every field is routed through csv_cell() first. The unit tests
 * verify both the formula-injection guard and the RFC 4180 quoting that
 * fputcsv applies.
 */
function write_rows(array $header, iterable $rows): void
{
    $fp = fopen('php://output', 'w');
    // PHP 8.4 warns unless $escape is passed explicitly. RFC 4180 does not
    // use a separate escape char (doubled quotes escape themselves), so we
    // pass '' to preserve backward-compatible behaviour without the notice.
    fputcsv($fp, $header, ',', '"', '');
    foreach ($rows as $row) {
        $safe = [];
        foreach ($row as $v) { $safe[] = csv_cell($v); }
        fputcsv($fp, $safe, ',', '"', '');
    }
    fclose($fp);
}
