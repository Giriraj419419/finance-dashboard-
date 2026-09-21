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

// Accept both form-encoded (default from ui/js/push.js) and JSON bodies.
// JSON is transparently promoted into $_POST so csrf_check_or_die and the
// $_POST read below both see the same values regardless of encoding.
$ct = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
if (strpos($ct, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?: [];
    if (isset($body['_csrf']))    $_POST['_csrf']    = $body['_csrf'];
    if (isset($body['endpoint'])) $_POST['endpoint'] = $body['endpoint'];
}
csrf_check_or_die();

$uid = (int) currentUser()['id'];
$endpoint = (string) ($_POST['endpoint'] ?? '');
if ($endpoint === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'endpoint']);
    exit;
}

try {
    executeQuery(
        "UPDATE push_subscriptions SET is_active = 0 WHERE endpoint = :e AND user_id = :u",
        [':e' => $endpoint, ':u' => $uid]
    );
    log_audit('push_subscription_removed', 'push_subscription', null, ['endpoint_prefix' => substr($endpoint, 0, 60)]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('[push-unsubscribe] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
