<?php
/**
 * database/production-health.php
 *
 * CLI-only production-database diagnostic. Verifies that the deployed
 * schema matches the shape the application expects. Exit code:
 *   0  everything OK
 *   1  one or more checks failed
 *
 * NEVER prints DB passwords, credentials, or row-level content. It only
 * prints table names, index names, and pass/fail markers.
 *
 * Usage (on the server):
 *   php database/production-health.php
 *   php database/production-health.php --json     (machine-readable output)
 *   php database/production-health.php --user=x   (also verify a specific user email exists)
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

// Parse args (no getopt dependency on quirky short forms).
$json  = in_array('--json', $argv, true);
$check_user = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--user=')) {
        $check_user = substr($a, 7);
    }
}

$results = []; // list<['name'=>string,'ok'=>bool,'detail'=>string]>
function record(array &$results, string $name, bool $ok, string $detail = ''): void
{
    $results[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
}

/* ------- 1. connectivity ------- */
try {
    $pdo = getDatabaseConnection();
    record($results, 'db.connect', true);
} catch (Throwable $e) {
    // Do NOT print the DSN or password — just the class of failure.
    record($results, 'db.connect', false, get_class($e));
    _emit($results, $json);
    exit(1);
}

/* ------- 2. required tables ------- */
$required_tables = [
    'users', 'transactions', 'budgets', 'goals', 'goal_contributions',
    'payments', 'purchase_orders', 'purchase_order_items',
    'reminders', 'reminder_notifications', 'push_subscriptions',
    'login_attempts', 'reports', 'password_reset_tokens', 'audit_logs',
    'system_health', 'user_notification_preferences',
];
try {
    $rows = fetchAll("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()");
    $present = array_map(static fn ($r) => (string) $r['t'], $rows);
    foreach ($required_tables as $t) {
        record($results, "table.$t", in_array($t, $present, true));
    }
} catch (Throwable $e) {
    record($results, 'table.enumeration', false, $e->getMessage());
}

/* ------- 3. critical unique keys + indexes ------- */
$required_keys = [
    ['reminder_notifications', 'uq_rn_reminder_occurrence'],
    ['push_subscriptions',     'uq_ps_endpoint'],
    ['users',                  'uq_users_email'],
    ['purchase_orders',        'uq_po_order_number'],
    ['password_reset_tokens',  'uq_prt_token_hash'],
];
foreach ($required_keys as [$tbl, $key]) {
    try {
        $row = fetchOne(
            "SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :k LIMIT 1",
            [':t' => $tbl, ':k' => $key]
        );
        record($results, "unique.$tbl.$key", $row !== null);
    } catch (Throwable $e) {
        record($results, "unique.$tbl.$key", false, $e->getMessage());
    }
}

/* ------- 4. required FK constraints ------- */
$required_fks = [
    ['reminder_notifications', 'fk_rn_reminder'],
    ['reminder_notifications', 'fk_rn_user'],
    ['push_subscriptions',     'fk_ps_user'],
    ['transactions',           'fk_txn_user'],
    ['goal_contributions',     'fk_gc_goal'],
    ['purchase_order_items',   'fk_poi_po'],
];
foreach ($required_fks as [$tbl, $fk]) {
    try {
        $row = fetchOne(
            "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
               AND CONSTRAINT_NAME = :c AND CONSTRAINT_TYPE = 'FOREIGN KEY' LIMIT 1",
            [':t' => $tbl, ':c' => $fk]
        );
        record($results, "fk.$tbl.$fk", $row !== null);
    } catch (Throwable $e) {
        record($results, "fk.$tbl.$fk", false, $e->getMessage());
    }
}

/* ------- 5. money columns are DECIMAL, not FLOAT/DOUBLE ------- */
$money_columns = [
    ['transactions', 'amount'],
    ['budgets',      'budget_amount'],
    ['budgets',      'spent_amount'],
    ['goals',        'target_amount'],
    ['goals',        'current_amount'],
    ['goal_contributions', 'amount'],
    ['payments',     'amount'],
    ['purchase_orders', 'subtotal'],
    ['purchase_orders', 'tax_amount'],
    ['purchase_orders', 'total_amount'],
    ['purchase_order_items', 'quantity'],
    ['purchase_order_items', 'unit_price'],
    ['purchase_order_items', 'total_price'],
];
foreach ($money_columns as [$tbl, $col]) {
    try {
        $row = fetchOne(
            "SELECT DATA_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1",
            [':t' => $tbl, ':c' => $col]
        );
        $type = strtolower((string) ($row['DATA_TYPE'] ?? ''));
        record($results, "money.$tbl.$col", $type === 'decimal', $type ?: 'missing');
    } catch (Throwable $e) {
        record($results, "money.$tbl.$col", false, $e->getMessage());
    }
}

/* ------- 6. optional: production user exists ------- */
if ($check_user !== null && $check_user !== '') {
    try {
        $row = fetchOne(
            "SELECT id, status FROM users WHERE email = :e LIMIT 1",
            [':e' => strtolower($check_user)]
        );
        $ok = $row !== null && (string) $row['status'] === 'active';
        record($results, 'user.' . $check_user, $ok, $row === null ? 'missing' : ('status=' . $row['status']));
    } catch (Throwable $e) {
        record($results, 'user.' . $check_user, false, $e->getMessage());
    }
}

_emit($results, $json);

$fail = 0;
foreach ($results as $r) {
    if (!$r['ok']) $fail++;
}
exit($fail === 0 ? 0 : 1);

function _emit(array $results, bool $json): void
{
    if ($json) {
        echo json_encode($results, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
        return;
    }
    $pass = 0; $fail = 0;
    foreach ($results as $r) {
        $mark = $r['ok'] ? 'PASS' : 'FAIL';
        $line = sprintf("%-4s  %s", $mark, $r['name']);
        if ($r['detail'] !== '') $line .= '  (' . $r['detail'] . ')';
        echo $line, "\n";
        $r['ok'] ? $pass++ : $fail++;
    }
    echo "---\n";
    echo "PASS=$pass FAIL=$fail\n";
}
