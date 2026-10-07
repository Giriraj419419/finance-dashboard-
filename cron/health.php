<?php
/**
 * cron/health.php
 *
 * CLI-only diagnostic. Prints OK when the reminder worker's most-recent
 * heartbeat is within threshold (default: 15 minutes), otherwise WARN/CRIT.
 *
 * Usage:
 *   php cron/health.php                 # human output, exit 0/1/2
 *   php cron/health.php --json          # machine-readable
 *   php cron/health.php --threshold=900 # override threshold seconds
 *
 * Never prints notification payloads, tokens, DB creds, or full recipient
 * addresses. Only prints heartbeat metrics from the `system_health` table.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "CLI-only diagnostic.\n";
    exit(1);
}

$ROOT = dirname(__DIR__);
require_once $ROOT . '/functions.php';
require_once $ROOT . '/database.php';

$json = in_array('--json', $argv, true);
$threshold = 15 * 60;
foreach ($argv as $a) {
    if (str_starts_with($a, '--threshold=')) {
        $threshold = max(60, (int) substr($a, 12));
    }
}

$now = time();
$out = [
    'status'          => 'unknown',
    'last_run'        => null,
    'age_seconds'     => null,
    'threshold'       => $threshold,
    'last_result'     => null,
    'last_success'    => null,
    'last_error'      => null,
];

try {
    $rows = fetchAll(
        "SELECT metric_key AS k, metric_value AS v FROM system_health
         WHERE metric_key IN ('reminder_worker_last_run',
                              'reminder_worker_last_result',
                              'reminder_worker_last_success',
                              'reminder_worker_last_error')"
    );
    $map = [];
    foreach ($rows as $r) $map[(string) $r['k']] = (string) $r['v'];

    $out['last_run']     = $map['reminder_worker_last_run']     ?? null;
    $out['last_result']  = $map['reminder_worker_last_result']  ?? null;
    $out['last_success'] = $map['reminder_worker_last_success'] ?? null;
    // last_error is coarse — a category string, never a SQL/PDO message.
    $out['last_error']   = $map['reminder_worker_last_error']   ?? null;

    if ($out['last_run'] === null) {
        $out['status'] = 'never-run';
        _emit($out, $json, 2);
    }
    $ts = strtotime((string) $out['last_run']);
    if ($ts === false) {
        $out['status'] = 'invalid-timestamp';
        _emit($out, $json, 2);
    }
    $out['age_seconds'] = $now - $ts;
    if ($out['age_seconds'] > $threshold) {
        $out['status'] = 'stale';
        _emit($out, $json, 2);
    }
    $out['status'] = 'ok';
    _emit($out, $json, 0);
} catch (Throwable $e) {
    $out['status'] = 'error';
    $out['last_error'] = get_class($e);
    _emit($out, $json, 1);
}

function _emit(array $out, bool $json, int $exit): void
{
    if ($json) {
        echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    } else {
        printf("cron.status         = %s\n", (string) $out['status']);
        printf("cron.last_run       = %s\n", (string) ($out['last_run'] ?? '(none)'));
        printf("cron.age_seconds    = %s\n", (string) ($out['age_seconds'] ?? '(n/a)'));
        printf("cron.threshold      = %s\n", (string) $out['threshold']);
        printf("cron.last_result    = %s\n", (string) ($out['last_result'] ?? '(none)'));
        printf("cron.last_success   = %s\n", (string) ($out['last_success'] ?? '(none)'));
        printf("cron.last_error     = %s\n", (string) ($out['last_error'] ?? '(none)'));
    }
    exit($exit);
}
