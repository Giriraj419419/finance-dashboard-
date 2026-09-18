<?php
/**
 * cron/test-reminder.php
 *
 * CLI-only SMTP smoke test. Sends ONE test email to the production account
 * without creating or modifying any reminder rows.
 *
 * Usage from cPanel Terminal:
 *     php /home/kktechsolutions/public_html/finance.kktechsolutions.in/cron/test-reminder.php
 */

$is_web_request = PHP_SAPI !== 'cli'
    && (isset($_SERVER['HTTP_HOST']) || isset($_SERVER['REMOTE_ADDR']) || isset($_SERVER['REQUEST_METHOD']));
if ($is_web_request) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "CLI-only.\n";
    exit(1);
}

$ROOT = dirname(__DIR__);
require_once $ROOT . '/functions.php';
require_once $ROOT . '/database.php';
require_once $ROOT . '/mailer.php';

$app  = app_config('app');
$mail = app_config('mail');
$app_name = $app['name'] ?? 'Finance Dashboard';

// Print config sanity WITHOUT credentials.
echo "==========================================================\n";
echo "  Reminder SMTP self-test\n";
echo "==========================================================\n";
echo "app.name:        " . ($app_name) . "\n";
echo "app.base_url:    " . ($app['base_url'] ?? '(empty)') . "\n";
echo "mail.host:       " . ($mail['host'] ?? '(empty)') . "\n";
echo "mail.port:       " . ($mail['port'] ?? '(empty)') . "\n";
echo "mail.from_email: " . ($mail['from_email'] ?? '(empty)') . "\n";
echo "mail.username:   " . (!empty($mail['username']) ? '(set)' : '(empty)') . "\n";
echo "mail.password:   " . (!empty($mail['password']) ? '(set)' : '(empty)') . "\n";
echo "\n";

if (empty($mail['host'])) {
    fwrite(STDERR, "[fail] mail.host is empty. Fill in config.php first.\n");
    exit(2);
}

// Find the production user, or fall back to the first admin.
try {
    $u = fetchOne("SELECT email, name FROM users WHERE email = 'accounts@kktechsolutions.in' LIMIT 1");
    if (!$u) $u = fetchOne("SELECT email, name FROM users WHERE role='admin' AND status='active' ORDER BY id ASC LIMIT 1");
} catch (Throwable $e) {
    fwrite(STDERR, "[fail] db lookup failed: {$e->getMessage()}\n");
    exit(3);
}
if (!$u || empty($u['email'])) {
    fwrite(STDERR, "[fail] no target user found. Run database/create-production-user.php first.\n");
    exit(4);
}
$to = (string) $u['email'];

echo "Sending test email to: $to\n";
$html =
    '<div style="font-family:system-ui;color:#0f172a;line-height:1.5">' .
        '<h2>SMTP test</h2>' .
        '<p>If you see this message, the reminder-worker mailer is configured correctly.</p>' .
        '<p>Sent at ' . date('r') . '.</p>' .
    '</div>';
$ok = send_mail($to, 'Reminder system SMTP test', $html, "SMTP test — if you see this, mailer works. " . date('r') . "\n");
if ($ok) {
    echo "[ok] send_mail returned true. Check the inbox at $to (may take a minute).\n";
    exit(0);
}
fwrite(STDERR, "[fail] send_mail returned false. Check server mail logs.\n");
exit(5);
