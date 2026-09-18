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
csrf_check_or_die();

$uid = (int) currentUser()['id'];
$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?: [];
$endpoint = (string) ($body['endpoint'] ?? '');
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
