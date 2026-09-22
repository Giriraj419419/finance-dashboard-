<?php
/**
 * database/production-health.php
 *
 * CLI-only wrapper around diag_database() + diag_production_config().
 * Kept for operators who DO have SSH — the admin-only UI page provides
 * the same information for hosts where SSH is unavailable.
 *
 * Usage:
 *   php database/production-health.php
 *   php database/production-health.php --json
 *   php database/production-health.php --user=<email>
 *
 * Exit code 0 on all-pass, 1 otherwise.
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

$json  = in_array('--json', $argv, true);
$user  = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--user=')) $user = substr($a, 7);
}

$rows = array_merge(diag_production_config(), diag_database($user));
_emit($rows, $json);

exit(diag_group_verdict($rows) ? 0 : 1);

function _emit(array $rows, bool $json): void
{
    if ($json) {
        echo json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
        return;
    }
    $pass = 0; $fail = 0;
    foreach ($rows as $r) {
        $mark = $r['ok'] ? 'PASS' : 'FAIL';
        $line = sprintf("%-4s  %s", $mark, $r['name']);
        if ($r['detail'] !== '') $line .= '  (' . $r['detail'] . ')';
        echo $line, "\n";
        $r['ok'] ? $pass++ : $fail++;
    }
    echo "---\nPASS=$pass FAIL=$fail\n";
}
