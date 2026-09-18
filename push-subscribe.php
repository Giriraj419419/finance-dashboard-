<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/audit.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

// Accept either form-encoded (preferred, so $_POST + csrf_check_or_die work)
// or JSON body (transparently promoted into $_POST for the CSRF check).
$ct = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
if (strpos($ct, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?: [];
    if (isset($body['_csrf'])) $_POST['_csrf'] = $body['_csrf'];
    if (isset($body['endpoint'])) $_POST['endpoint'] = $body['endpoint'];
    if (isset($body['keys']['p256dh'])) $_POST['p256dh'] = $body['keys']['p256dh'];
    if (isset($body['keys']['auth']))   $_POST['auth']   = $body['keys']['auth'];
}
csrf_check_or_die();

$uid = (int) currentUser()['id'];
$endpoint = (string) ($_POST['endpoint'] ?? '');
$p256dh   = (string) ($_POST['p256dh'] ?? '');
$auth_key = (string) ($_POST['auth'] ?? '');
$ua       = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

if (strlen($endpoint) > 500 || !preg_match('~^https://~', $endpoint)) {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'invalid endpoint']); exit;
}
if ($p256dh === '' || strlen($p256dh) > 255 || $auth_key === '' || strlen($auth_key) > 64) {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'invalid keys']); exit;
}

try {
    $existing = fetchOne('SELECT id, user_id FROM push_subscriptions WHERE endpoint = :e LIMIT 1', [':e' => $endpoint]);
    if ($existing) {
        if ((int) $existing['user_id'] !== $uid) {
            http_response_code(403); echo json_encode(['ok' => false, 'error' => 'ownership']); exit;
        }
        updateRecord('push_subscriptions', [
            'p256dh_key' => $p256dh,
            'auth_key'   => $auth_key,
            'user_agent' => $ua,
            'is_active'  => 1,
            'last_error' => null,
        ], ['id' => (int) $existing['id']]);
        log_audit('push_subscription_updated', 'push_subscription', (int) $existing['id']);
    } else {
        $id = insertRecord('push_subscriptions', [
            'user_id'    => $uid,
            'endpoint'   => $endpoint,
            'p256dh_key' => $p256dh,
            'auth_key'   => $auth_key,
            'user_agent' => $ua,
            'is_active'  => 1,
        ]);
        log_audit('push_subscription_created', 'push_subscription', $id);
    }
    executeQuery(
        'INSERT INTO user_notification_preferences (user_id, email_enabled, push_enabled) VALUES (:u, 1, 1)
         ON DUPLICATE KEY UPDATE push_enabled = 1',
        [':u' => $uid]
    );
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('[push-subscribe] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
