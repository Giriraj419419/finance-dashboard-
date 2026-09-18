<?php
/**
 * POST-only, CSRF-protected. Sends a synthetic push to every active
 * subscription for the current user. Never creates a reminder row.
 */

require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/push-webpush.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}
csrf_check_or_die();

$uid = (int) currentUser()['id'];
$subs = fetchAll(
    'SELECT id, endpoint, p256dh_key, auth_key FROM push_subscriptions WHERE user_id = :u AND is_active = 1',
    [':u' => $uid]
);
if (!$subs) {
    echo json_encode(['ok' => false, 'error' => 'no active subscriptions']);
    exit;
}

$payload = json_encode([
    'title' => 'Finance Dashboard test',
    'body'  => 'Notifications are working correctly.',
    'url'   => '/dashboard.php',
    'tag'   => 'test-' . time(),
], JSON_UNESCAPED_SLASHES);

$sent = 0; $failed = 0; $gone = 0;
foreach ($subs as $s) {
    [$code, $resp, $err] = web_push_send($s, $payload);
    if ($code === 201 || $code === 202) {
        $sent++;
    } elseif ($code === 404 || $code === 410) {
        $gone++;
        executeQuery("UPDATE push_subscriptions SET is_active = 0, last_error = 'gone' WHERE id = :id", [':id' => (int) $s['id']]);
    } else {
        $failed++;
        $safe_err = mb_substr((string) $err ?: ('http ' . $code), 0, 255);
        executeQuery('UPDATE push_subscriptions SET last_error = :e WHERE id = :id', [':e' => $safe_err, ':id' => (int) $s['id']]);
    }
}
log_audit('push_test', 'user', $uid, ['sent' => $sent, 'failed' => $failed, 'gone' => $gone]);
echo json_encode(['ok' => $sent > 0, 'sent' => $sent, 'failed' => $failed, 'gone' => $gone]);
