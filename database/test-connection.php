<?php
/**
 * Database connection self-test.
 *
 * SAFE BY DEFAULT: this file lives under /database/, which is denied by the
 * project's Apache config (see database/.htaccess). It refuses to run over
 * a web request even if that guard is bypassed. Intended usage:
 *
 *     php database/test-connection.php       # local
 *     php ~/public_html/database/test-connection.php   # cPanel shell
 *
 * After go-live, either delete this file or leave it — the .htaccess deny
 * rule keeps it inaccessible from the browser.
 *
 * Output NEVER prints credentials, SQL queries, or stack traces.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "This script is CLI-only. Run it from the shell, not the browser.\n";
    exit(1);
}

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../database.php';

$required_tables = [
    'users', 'transactions', 'budgets', 'goals', 'goal_contributions',
    'payments', 'purchase_orders', 'purchase_order_items', 'reminders',
    'reports', 'password_reset_tokens', 'login_attempts', 'audit_logs',
];

$results = [];
$pass = static function (string $name, string $detail = '') use (&$results): void {
    $results[] = ['ok' => true, 'name' => $name, 'detail' => $detail];
};
$fail = static function (string $name, string $detail): void {
    fwrite(STDERR, "FAIL: {$name} — {$detail}\n");
    global $results;
    $results[] = ['ok' => false, 'name' => $name, 'detail' => $detail];
};

// ---- 1. Config loads ---------------------------------------------------
try {
    $app = app_config('app');
    $db  = app_config('database');
    if (empty($db['name']) || empty($db['username'])) {
        throw new RuntimeException('database.name or database.username is empty in config.php');
    }
    $pass('config loads', 'env=' . ($app['environment'] ?? '?') . ', db=' . $db['name']);
} catch (Throwable $e) {
    $fail('config loads', 'config not loadable');
    print_summary($results);
    exit(1);
}

// ---- 2. PDO can connect ------------------------------------------------
try {
    $pdo = getDatabaseConnection();
    $pass('PDO connects');
} catch (Throwable $e) {
    // Never surface the raw exception message — it can contain credentials.
    $fail('PDO connects', 'connection refused (check host / credentials / firewall)');
    print_summary($results);
    exit(1);
}

// ---- 3. MySQL version --------------------------------------------------
try {
    $row = $pdo->query('SELECT VERSION() AS v')->fetch();
    $pass('server version', $row['v'] ?? '(unknown)');
} catch (Throwable $e) {
    $fail('server version', 'query failed');
}

// ---- 4. Charset --------------------------------------------------------
try {
    $row = $pdo->query("SHOW VARIABLES LIKE 'character_set_client'")->fetch();
    $charset = $row['Value'] ?? '';
    $ok = str_starts_with($charset, 'utf8mb4');
    $ok ? $pass('charset', $charset) : $fail('charset', "expected utf8mb4, got {$charset}");
} catch (Throwable $e) {
    $fail('charset', 'query failed');
}

// ---- 5. Required tables ------------------------------------------------
try {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS c FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $missing = [];
    foreach ($required_tables as $t) {
        $stmt->execute([$t]);
        $c = (int) ($stmt->fetch()['c'] ?? 0);
        if ($c === 0) {
            $missing[] = $t;
        }
    }
    if ($missing === []) {
        $pass('all required tables present', count($required_tables) . ' tables');
    } else {
        $fail('required tables', 'missing: ' . implode(', ', $missing));
    }
} catch (Throwable $e) {
    $fail('required tables', 'query failed');
}

// ---- 6. Safe error surface ---------------------------------------------
try {
    $pdo->query('SELECT 1 FROM __table_that_does_not_exist__');
    $fail('safe error surface', 'expected exception was NOT thrown');
} catch (PDOException $e) {
    // Good: exceptions ARE thrown on error.
    $pass('safe error surface', 'PDO throws on error');
}

print_summary($results);
exit(all_ok($results) ? 0 : 1);

// ---- helpers -----------------------------------------------------------
function all_ok(array $rs): bool
{
    foreach ($rs as $r) {
        if (!$r['ok']) return false;
    }
    return true;
}

function print_summary(array $rs): void
{
    echo str_repeat('=', 60), "\n";
    echo "Finance Dashboard — database self-test\n";
    echo str_repeat('=', 60), "\n";
    foreach ($rs as $r) {
        $mark = $r['ok'] ? '[ OK ]' : '[FAIL]';
        $line = "  {$mark}  " . $r['name'];
        if ($r['detail'] !== '') {
            $line .= '  — ' . $r['detail'];
        }
        echo $line, "\n";
    }
    $ok = all_ok($rs);
    echo str_repeat('-', 60), "\n";
    echo $ok ? "Result: PASS\n" : "Result: FAIL\n";
}
