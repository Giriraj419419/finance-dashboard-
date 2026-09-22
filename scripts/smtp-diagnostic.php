<?php
/**
 * scripts/smtp-diagnostic.php
 *
 * CLI wrapper over diag_smtp(). Same rows appear in the admin-only UI.
 *
 * Sending a real test message is destructive-adjacent (it hits an SMTP
 * server, may bill on egress) so the send is behind an explicit
 * `--send=<recipient>` argument and refuses example.com targets. Never
 * prints SMTP credentials, and the operator must supply the recipient.
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
require_once $ROOT . '/mailer.php';

$send_to = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--send=')) $send_to = substr($a, 7);
}

$rows = diag_smtp();
$fail = 0;
foreach ($rows as $r) {
    $mark = $r['ok'] ? 'PASS' : 'FAIL';
    if (!$r['ok']) $fail++;
    printf("%-4s  %s%s\n", $mark, $r['name'], $r['detail'] === '' ? '' : "  ({$r['detail']})");
}
echo "---\n";
echo $fail === 0 ? "smtp.config = PASS\n" : "smtp.config = FAIL ($fail check(s))\n";

if ($send_to === null) {
    echo "no send requested — pass --send=<address> to attempt actual delivery.\n";
    exit($fail === 0 ? 0 : 1);
}

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
echo "smtp.send = " . ($ok ? 'PASS' : 'FAIL') . "\n";
exit($ok ? 0 : 1);
