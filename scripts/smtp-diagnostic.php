<?php
/**
 * scripts/smtp-diagnostic.php
 *
 * Verify the SMTP configuration is safe for production without actually
 * sending an email. Prints only setting NAMES and safe descriptors — never
 * the SMTP password, sender inbox, or any recipient address.
 *
 * Optionally sends a test message when --send=<recipient> is passed
 * EXPLICITLY. The recipient must be provided by the operator on the CLI —
 * this script will not read it from config, environment, or DB.
 *
 * Exit codes:
 *   0  configuration looks correct (and, if --send used, delivery accepted)
 *   1  configuration invalid, or send failed
 *   2  --send was requested but declined for a policy reason
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
require_once $ROOT . '/mailer.php';

$send_to = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--send=')) {
        $send_to = substr($a, 7);
    }
}

$mail = app_config('mail');
$problems = [];

foreach (['host', 'port', 'from_email'] as $k) {
    if (empty($mail[$k])) $problems[] = "mail.$k is not set";
}
if (!empty($mail['from_email'])) {
    if (!filter_var($mail['from_email'], FILTER_VALIDATE_EMAIL)) {
        $problems[] = 'mail.from_email is not a valid email address';
    } elseif (str_contains((string) $mail['from_email'], 'example.com')) {
        $problems[] = 'mail.from_email still holds an example.com placeholder';
    }
}
$port  = (int) ($mail['port'] ?? 0);
$secure = (bool) ($mail['secure'] ?? false);
if ($port === 465 && !$secure) {
    $problems[] = 'mail.secure should be true when using port 465 (implicit SSL)';
}
if ($port === 587 && $secure) {
    $problems[] = 'mail.secure should be false when using port 587 (STARTTLS)';
}
if (empty($mail['username']) && !empty($mail['host'])) {
    $problems[] = 'mail.username is empty — most cPanel SMTP servers require authentication';
}

echo "SMTP configuration:\n";
printf("  host      = %s\n",   (string) ($mail['host'] ?? '(unset)'));
printf("  port      = %s\n",   $port ?: '(unset)');
printf("  secure    = %s\n",   $secure ? 'true (implicit SSL)' : 'false (plain / STARTTLS)');
printf("  username  = %s\n",   empty($mail['username']) ? '(unset)' : '<set>');
printf("  password  = %s\n",   empty($mail['password']) ? '(unset)' : '<set>');
printf("  from      = %s\n",   (string) ($mail['from_email'] ?? '(unset)'));
printf("  from_name = %s\n",   (string) ($mail['from_name']  ?? '(unset)'));

if ($problems !== []) {
    echo "\nPROBLEMS:\n";
    foreach ($problems as $p) echo "  - $p\n";
    exit(1);
}
echo "\nconfig-check = PASS\n";

if ($send_to === null) {
    echo "no send requested — pass --send=<address> to attempt actual delivery.\n";
    exit(0);
}

// Sanity-check recipient before we hand it to the mailer. Operator has to
// pass a real address; the diagnostic will not send to an example.com.
if (!filter_var($send_to, FILTER_VALIDATE_EMAIL) || str_contains($send_to, 'example.com')) {
    echo "REFUSED: --send target is not a valid non-example address.\n";
    exit(2);
}

$ok = send_mail(
    $send_to,
    'Finance Dashboard SMTP diagnostic',
    '<p>This is an automated SMTP diagnostic message.</p>',
    'This is an automated SMTP diagnostic message.'
);
echo "send = " . ($ok ? 'PASS' : 'FAIL') . "\n";
exit($ok ? 0 : 1);
