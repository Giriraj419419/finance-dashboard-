<?php
/**
 * cron/health.php
 *
 * CLI wrapper over diag_cron(). Same rows appear in the admin-only UI
 * for hosts where SSH is unavailable.
 *
 * Usage:
 *   php cron/health.php                  # human output, exit 0 = OK
 *   php cron/health.php --json
 *   php cron/health.php --threshold=600  # override threshold seconds
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "CLI-only diagnostic.\n";
    exit(1);
}

$ROOT = dirname(__DIR__);
require_once $ROOT . '/diagnostics-lib.php';

$json = in_array('--json', $argv, true);
$threshold = 15 * 60;
foreach ($argv as $a) {
    if (str_starts_with($a, '--threshold=')) $threshold = max(60, (int) substr($a, 12));
}

$rows = diag_cron($threshold);

if ($json) {
    echo json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
} else {
    foreach ($rows as $r) {
        printf("%-4s  %s%s\n", $r['ok'] ? 'PASS' : 'FAIL', $r['name'],
            $r['detail'] === '' ? '' : "  ({$r['detail']})");
    }
}
exit(diag_group_verdict($rows) ? 0 : 1);
