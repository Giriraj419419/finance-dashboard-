<?php
/**
 * Production configuration guard.
 *
 * The single job of this file is to REFUSE to run the application when
 * `app.environment === 'production'` but the configuration would be unsafe
 * in production. Dev/test environments are left completely alone.
 *
 * Called automatically from `functions.php::app_config()` the very first
 * time the config is loaded. Fail-loud is intentional — better a hard 500
 * with a clear operator message than a live site that quietly runs with
 * debug=true, session.secure=false, or a missing SMTP/VAPID config.
 *
 * NEVER logs a secret value. The function only names the setting that is
 * missing or wrong; it does not print DB passwords, SMTP passwords, VAPID
 * private keys, or session secrets.
 */
declare(strict_types=1);

/**
 * Evaluate the config array and return the list of production-safety
 * problems, or [] when everything checks out. Pure function — safe to
 * unit-test.
 *
 * @param array<string,mixed> $config Full app config (return value of config.php).
 * @return array<int,string> Human-readable problem strings.
 */
function production_config_problems(array $config): array
{
    $problems = [];

    $app = $config['app'] ?? [];
    $env = (string) ($app['environment'] ?? '');
    if ($env !== 'production') {
        // Not production — no assertions, empty list.
        return [];
    }

    if (!empty($app['debug'])) {
        $problems[] = 'app.debug must be false in production';
    }
    if (empty($app['base_url']) || strncasecmp((string) $app['base_url'], 'https://', 8) !== 0) {
        $problems[] = 'app.base_url must be an https:// URL in production';
    }

    $session = $config['session'] ?? [];
    if (empty($session['secure'])) {
        $problems[] = 'session.secure must be true in production (requires HTTPS)';
    }
    if (!isset($session['httponly']) || !$session['httponly']) {
        $problems[] = 'session.httponly must be true in production';
    }
    $samesite = (string) ($session['samesite'] ?? '');
    if (!in_array($samesite, ['Lax', 'Strict'], true)) {
        $problems[] = 'session.samesite must be "Lax" or "Strict" in production';
    }

    $db = $config['database'] ?? [];
    foreach (['host', 'name', 'username', 'password'] as $k) {
        if (empty($db[$k])) {
            $problems[] = "database.$k must be set in production";
        }
    }
    // Guard against forgotten placeholders.
    if (($db['username'] ?? '') === 'CHANGE_ME' || ($db['password'] ?? '') === 'CHANGE_ME') {
        $problems[] = 'database.username/password still hold CHANGE_ME placeholder';
    }

    $mail = $config['mail'] ?? [];
    foreach (['host', 'port', 'from_email'] as $k) {
        if (empty($mail[$k])) {
            $problems[] = "mail.$k must be set in production";
        }
    }
    $from = (string) ($mail['from_email'] ?? '');
    if ($from !== '' && (str_contains($from, 'example.com') || str_contains($from, 'no-reply@example'))) {
        $problems[] = 'mail.from_email still holds an example.com placeholder';
    }

    $push = $config['push'] ?? [];
    if (empty($push['vapid_public_key'])) {
        $problems[] = 'push.vapid_public_key must be set in production';
    }
    $priv = (string) ($push['vapid_private_key_path'] ?? '');
    if ($priv === '') {
        $problems[] = 'push.vapid_private_key_path must be set in production';
    } elseif (!is_file($priv)) {
        // Path present but file missing — dangerous silent no-send on push.
        $problems[] = 'push.vapid_private_key_path points at a missing file';
    } else {
        // Refuse to accept the private key living inside the web root.
        $webroot = realpath(__DIR__);
        $privReal = realpath($priv);
        if ($webroot !== false && $privReal !== false && str_starts_with($privReal, $webroot)) {
            $problems[] = 'push.vapid_private_key_path must live OUTSIDE the web-root directory';
        }
    }
    if (empty($push['vapid_subject']) || strncasecmp((string) $push['vapid_subject'], 'mailto:', 7) !== 0) {
        $problems[] = 'push.vapid_subject must be a mailto: URL in production';
    }

    return $problems;
}

/**
 * Fail-loud production assertion. Called from functions.php on config load.
 * Exits with HTTP 500 (or process exit 1 for CLI) when problems are found.
 *
 * Never prints secrets; only prints the SETTING names that are wrong.
 */
function assert_production_config(array $config): void
{
    $problems = production_config_problems($config);
    if ($problems === []) {
        return;
    }

    $isCli = PHP_SAPI === 'cli';

    $header = 'Refusing to serve — production configuration is unsafe. ' .
        'Fix the following in config.php on the server (values redacted):';
    error_log('[production-guard] ' . $header . ' ' . implode(' | ', $problems));

    if ($isCli) {
        fwrite(STDERR, $header . "\n");
        foreach ($problems as $p) {
            fwrite(STDERR, ' - ' . $p . "\n");
        }
        exit(1);
    }

    // Never leak internals to end-users — render an operator-neutral 500.
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo "Server misconfigured. See server logs.\n";
    exit;
}
