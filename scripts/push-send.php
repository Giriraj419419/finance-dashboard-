<?php
/**
 * scripts/push-send.php
 *
 * CLI-only tool that sends ONE Web Push notification to an active
 * subscription owned by a specific user. Uses the same web_push_send()
 * the production reminder worker uses, so a green result here is a
 * genuine "this VAPID pair works against this browser subscription"
 * signal — not a mocked one.
 *
 * The operator supplies the recipient explicitly by user email:
 *   php scripts/push-send.php --user=you@example.com
 *
 * When the endpoint returns 404/410 the subscription is deactivated
 * (same policy as the cron worker). Nothing is logged that reveals the
 * VAPID private key, endpoint URL beyond its first 40 chars, or user
 * agents.
 *
 * Exit codes:
 *   0  at least one endpoint accepted the push (HTTP 201/202)
 *   1  every endpoint failed
 *   2  no active subscription for that user, or VAPID misconfigured
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
require_once $ROOT . '/database.php';
require_once $ROOT . '/push-webpush.php';

$email = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--user=')) $email = strtolower(substr($a, 7));
}
if ($email === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "usage: php scripts/push-send.php --user=<email>\n");
    exit(2);
}

$push = app_config('push');
if (empty($push['vapid_public_key']) || empty($push['vapid_private_key_path']) || !is_file((string) $push['vapid_private_key_path'])) {
    fwrite(STDERR, "vapid configuration is incomplete — refusing to attempt push\n");
    exit(2);
}

try {
    $user = fetchOne("SELECT id FROM users WHERE email = :e LIMIT 1", [':e' => $email]);
    if ($user === null) {
        fwrite(STDERR, "no user with that email\n");
        exit(2);
    }
    $subs = fetchAll(
        "SELECT id, endpoint, p256dh_key, auth_key FROM push_subscriptions
         WHERE user_id = :u AND is_active = 1",
        [':u' => (int) $user['id']]
    );
    if ($subs === []) {
        fwrite(STDERR, "no active push subscriptions for that user\n");
        exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "db error: " . get_class($e) . "\n");
    exit(1);
}

$payload = json_encode([
    'title' => 'Finance Dashboard diagnostic',
    'body'  => 'This is a manual test push from scripts/push-send.php',
    'url'   => rtrim((string) (app_config('app')['base_url'] ?? ''), '/') . '/dashboard.php',
    'tag'   => 'diag-' . bin2hex(random_bytes(4)),
], JSON_UNESCAPED_SLASHES);

$any_ok = false;
foreach ($subs as $s) {
    [$code, $_resp, $err] = web_push_send($s, $payload);
    $prefix = substr((string) $s['endpoint'], 0, 40);
    if ($code === 201 || $code === 202) {
        printf("PASS  sub=%d code=%d  endpoint=%s...\n", (int) $s['id'], $code, $prefix);
        try {
            executeQuery(
                "UPDATE push_subscriptions SET last_used_at = NOW(), last_error = NULL WHERE id = :id",
                [':id' => (int) $s['id']]
            );
        } catch (Throwable $ignored) { /* not a diagnostic signal */ }
        $any_ok = true;
    } elseif ($code === 404 || $code === 410) {
        printf("GONE  sub=%d code=%d  endpoint=%s... (deactivating)\n", (int) $s['id'], $code, $prefix);
        try {
            executeQuery(
                "UPDATE push_subscriptions SET is_active = 0, last_error = 'gone' WHERE id = :id",
                [':id' => (int) $s['id']]
            );
        } catch (Throwable $ignored) { /* not a diagnostic signal */ }
    } else {
        printf("FAIL  sub=%d code=%d  endpoint=%s...  err=%s\n",
            (int) $s['id'], $code, $prefix, $err ?: '(none)');
    }
}
exit($any_ok ? 0 : 1);
