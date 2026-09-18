<?php
/**
 * TEMPORARY DIAGNOSTIC — delete this file after use.
 *
 * Requires ?token=finance-diag-2026 to run. Reports what config.php says
 * (WITHOUT the password) and what MySQL says back. Never prints the
 * password or the full DSN in a way that would leak credentials.
 */

$TOKEN = 'finance-diag-2026';
if (($_GET['token'] ?? '') !== $TOKEN) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "forbidden\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
echo "=== db-diag ===\n";

require_once __DIR__ . '/functions.php';

try {
    $cfg = app_config('database');
    echo "host:     " . ($cfg['host']     ?? '(missing)') . "\n";
    echo "port:     " . ($cfg['port']     ?? '(missing)') . "\n";
    echo "name:     " . ($cfg['name']     ?? '(missing)') . "\n";
    echo "username: " . ($cfg['username'] ?? '(missing)') . "\n";
    echo "password: " . (!empty($cfg['password']) ? '(set, length=' . strlen($cfg['password']) . ')' : '(EMPTY)') . "\n";
    echo "charset:  " . ($cfg['charset']  ?? '(missing)') . "\n";
    echo "\n";
} catch (Throwable $e) {
    echo "config load FAILED: " . $e->getMessage() . "\n";
    exit;
}

echo "attempting PDO connection...\n";
try {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['host'] ?? 'localhost',
        (int) ($cfg['port'] ?? 3306),
        $cfg['name'] ?? '',
        $cfg['charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $cfg['username'] ?? '', (string) ($cfg['password'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "  CONNECT: OK\n";
    $ver = $pdo->query('SELECT VERSION() AS v')->fetch(PDO::FETCH_ASSOC);
    echo "  server:  " . ($ver['v'] ?? '?') . "\n";
    $db = $pdo->query('SELECT DATABASE() AS d')->fetch(PDO::FETCH_ASSOC);
    echo "  current db: " . ($db['d'] ?? '?') . "\n";
    $tables = $pdo->query("SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE()")->fetch(PDO::FETCH_ASSOC);
    echo "  table count: " . ($tables['c'] ?? '?') . "\n";
    $users = $pdo->query("SELECT COUNT(*) AS c FROM users")->fetch(PDO::FETCH_ASSOC);
    echo "  users row count: " . ($users['c'] ?? '?') . "\n";
} catch (Throwable $e) {
    echo "  FAILED: " . $e->getMessage() . "\n";
}

echo "\n(delete this file after use)\n";
