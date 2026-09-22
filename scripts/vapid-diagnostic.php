<?php
/**
 * scripts/vapid-diagnostic.php
 *
 * CLI wrapper over diag_vapid(). The same rows are surfaced in the admin
 * UI at admin-diagnostics.php for hosts without SSH/Terminal access.
 * Read-only; never prints private-key bytes.
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

$rows = diag_vapid();
$fail = 0;
foreach ($rows as $r) {
    $mark = $r['ok'] ? 'PASS' : 'FAIL';
    if (!$r['ok']) $fail++;
    printf("%-4s  %s%s\n", $mark, $r['name'], $r['detail'] === '' ? '' : "  ({$r['detail']})");
}
echo "---\n";
echo $fail === 0 ? "vapid = PASS\n" : "vapid = FAIL ($fail check(s))\n";
exit($fail === 0 ? 0 : 1);
