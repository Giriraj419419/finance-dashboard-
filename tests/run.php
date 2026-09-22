<?php
/**
 * tests/run.php — discovers every tests/*-test.php file and runs it in a
 * fresh subprocess. Aggregates results and exits non-zero on any failure.
 *
 * Runs the unit tests unconditionally. Integration tests that require a
 * MySQL server (files matching *integration-test.php) are only run when
 * TEST_DB_DSN is set — those are wired up in CI.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI-only.\n";
    exit(1);
}

$ROOT = dirname(__DIR__);
$here = __DIR__;

$files = glob($here . DIRECTORY_SEPARATOR . '*-test.php') ?: [];
sort($files);

$has_db = getenv('TEST_DB_DSN') !== false && getenv('TEST_DB_DSN') !== '';
$skip_integration = !$has_db;

$total_pass = 0;
$total_fail = 0;
$total_skip = 0;
$failed_files = [];

$php = defined('PHP_BINARY') ? PHP_BINARY : 'php';

foreach ($files as $f) {
    $base = basename($f);
    $is_integration = str_contains($base, 'integration');
    if ($is_integration && $skip_integration) {
        echo "SKIP  {$base}  (no TEST_DB_DSN)\n";
        $total_skip++;
        continue;
    }
    echo "\n=== {$base} ===\n";
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($f);
    // Stream stdout live so CI can show progress.
    passthru($cmd, $exit);
    if ($exit !== 0) {
        $failed_files[] = $base;
        $total_fail++;
    } else {
        $total_pass++;
    }
}

echo "\n=============================\n";
echo sprintf("FILES: pass=%d fail=%d skipped=%d\n", $total_pass, $total_fail, $total_skip);
if ($failed_files !== []) {
    echo "FAILED FILES:\n";
    foreach ($failed_files as $ff) echo "  - {$ff}\n";
}
echo $total_fail === 0 ? "OVERALL: PASS\n" : "OVERALL: FAIL\n";
exit($total_fail === 0 ? 0 : 1);
