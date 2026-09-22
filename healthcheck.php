<?php
/**
 * healthcheck.php — safe public JSON health endpoint.
 *
 * Design constraints:
 *   * Returns ONLY status booleans + coarse descriptors.
 *   * Never prints DB creds, SMTP creds, VAPID private key, session id,
 *     reset tokens, or the actual configuration values.
 *   * Idempotent and non-destructive — issues one SELECT per check.
 *   * HTTP 200 when every check is 'ok', HTTP 503 otherwise (matches load
 *     balancer / uptime-monitor conventions).
 *
 * The endpoint is intentionally CLI/HTTP-safe: it runs from the browser AND
 * from `php healthcheck.php` for cron-style black-box checks. It never
 * requires a login — this is what monitors hit — but nothing it returns is
 * sensitive.
 */
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$out = [
    'status'        => 'ok',
    'database'      => 'unknown',
    'schema'        => 'unknown',
    'configuration' => 'unknown',
    'cron'          => 'unknown',
    'push'          => 'unknown',
    'mail'          => 'unknown',
    'time'          => gmdate('c'),
];

/* ------------------------------------------------------------------------
 * Configuration presence (no values printed).
 * ---------------------------------------------------------------------- */
try {
    $app  = app_config('app');
    $db   = app_config('database');
    $mail = app_config('mail');
    $push = app_config('push');

    $cfg_ok =
        !empty($app['environment']) &&
        !empty($db['host']) && !empty($db['name']) && !empty($db['username']) &&
        !empty($mail['host']) && !empty($mail['from_email']) &&
        !empty($push['vapid_public_key']);
    $out['configuration'] = $cfg_ok ? 'ok' : 'incomplete';

    $out['mail'] = !empty($mail['host']) && !empty($mail['from_email']) ? 'configured' : 'incomplete';
    $out['push'] = !empty($push['vapid_public_key']) &&
        !empty($push['vapid_private_key_path']) &&
        is_file((string) $push['vapid_private_key_path'])
            ? 'configured'
            : 'incomplete';
} catch (Throwable $e) {
    error_log('[healthcheck:config] ' . $e->getMessage());
    $out['configuration'] = 'error';
}

/* ------------------------------------------------------------------------
 * Database connectivity + schema presence.
 * ---------------------------------------------------------------------- */
$required_tables = [
    'users', 'transactions', 'budgets', 'goals', 'goal_contributions',
    'payments', 'purchase_orders', 'purchase_order_items',
    'reminders', 'reminder_notifications', 'push_subscriptions',
    'login_attempts', 'reports', 'password_reset_tokens', 'audit_logs',
    'system_health', 'user_notification_preferences',
];
try {
    // No dbname interpolation — bound value.
    $pdo = getDatabaseConnection();
    $out['database'] = 'ok';

    $rows = fetchAll(
        "SELECT TABLE_NAME AS t FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()"
    );
    $present = array_map(static fn ($r) => (string) $r['t'], $rows);
    $missing = array_values(array_diff($required_tables, $present));
    $out['schema'] = $missing === [] ? 'ok' : 'missing-tables';
} catch (Throwable $e) {
    error_log('[healthcheck:db] ' . $e->getMessage());
    $out['database'] = 'error';
    $out['schema']   = 'unknown';
}

/* ------------------------------------------------------------------------
 * Cron heartbeat — the reminder worker writes `reminder_worker_last_run`
 * on every run. "Healthy" means we've seen a run in the last 15 minutes.
 * Threshold is deliberately generous (default cron is every 5m; 3x is a
 * comfortable band without flapping on transient host slowness).
 * ---------------------------------------------------------------------- */
if ($out['database'] === 'ok') {
    try {
        $row = fetchOne(
            "SELECT metric_value FROM system_health WHERE metric_key = :k LIMIT 1",
            [':k' => 'reminder_worker_last_run']
        );
        $last = $row['metric_value'] ?? null;
        if ($last === null) {
            $out['cron'] = 'never-run';
        } else {
            $ts = strtotime((string) $last);
            $age = $ts === false ? PHP_INT_MAX : (time() - $ts);
            $out['cron'] = $age <= 15 * 60 ? 'ok' : 'stale';
        }
    } catch (Throwable $e) {
        error_log('[healthcheck:cron] ' . $e->getMessage());
        $out['cron'] = 'error';
    }
}

/* ------------------------------------------------------------------------
 * Aggregate status.
 * ---------------------------------------------------------------------- */
$critical = [$out['database'], $out['schema'], $out['configuration']];
$degraded = [$out['cron'], $out['push'], $out['mail']];
if (in_array('error', $critical, true) || in_array('incomplete', $critical, true) || in_array('missing-tables', $critical, true)) {
    $out['status'] = 'unhealthy';
    http_response_code(503);
} elseif (in_array('error', $degraded, true) || in_array('stale', $degraded, true) || in_array('never-run', $degraded, true)) {
    $out['status'] = 'degraded';
    http_response_code(200); // degraded is still up, so 200 for load balancers
} else {
    $out['status'] = 'ok';
    http_response_code(200);
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
