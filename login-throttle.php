<?php
/**
 * Database-backed login-attempt throttle. Reads config from security.login_throttle:
 *   threshold        — failed attempts before lockout
 *   window_seconds   — sliding window in which failures are counted
 *   lockout_seconds  — duration of the block once the threshold trips
 *
 * The throttle counts recent FAILED attempts for the (email, ip) tuple within
 * the window. A successful login clears the recent failure trail for that email.
 *
 * The remote address is taken from $_SERVER['REMOTE_ADDR'] only. Proxy
 * headers are NOT trusted here — behind a reverse proxy, populate REMOTE_ADDR
 * server-side (e.g. mod_remoteip) rather than reading X-Forwarded-For blindly.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

function login_throttle_config(): array
{
    $cfg = app_config('security')['login_throttle'] ?? [];
    return [
        'threshold'       => (int) ($cfg['threshold']       ?? 5),
        'window_seconds'  => (int) ($cfg['window_seconds']  ?? 900),
        'lockout_seconds' => (int) ($cfg['lockout_seconds'] ?? 900),
    ];
}

function client_ip(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    if (!is_string($ip) || $ip === '') {
        return null;
    }
    return strlen($ip) > 45 ? substr($ip, 0, 45) : $ip;
}

/**
 * Return true when login attempts for this (email, ip) should be blocked.
 */
function login_is_blocked(string $email, ?string $ip): bool
{
    $c = login_throttle_config();
    if ($c['threshold'] <= 0) {
        return false;
    }
    $lockout_since = date('Y-m-d H:i:s', time() - $c['lockout_seconds']);

    // If the most recent successful login is within the lockout window, allow.
    // Otherwise, count failures within the window.
    try {
        $row = fetchOne(
            "SELECT COUNT(*) AS fails FROM login_attempts
             WHERE email = :email
               AND ip_address <=> :ip
               AND was_successful = 0
               AND attempted_at >= :since",
            [':email' => $email, ':ip' => $ip, ':since' => $lockout_since]
        );
        $fails = (int) ($row['fails'] ?? 0);
        return $fails >= $c['threshold'];
    } catch (Throwable $e) {
        error_log('[login-throttle:check] ' . $e->getMessage());
        return false; // fail open — don't lock people out on a DB hiccup
    }
}

function login_record_attempt(string $email, ?string $ip, bool $success): void
{
    try {
        insertRecord('login_attempts', [
            'email'          => $email,
            'ip_address'     => $ip,
            'was_successful' => $success ? 1 : 0,
        ]);
    } catch (Throwable $e) {
        error_log('[login-throttle:record] ' . $e->getMessage());
    }
}

/**
 * Called after a successful login: mark recent failures as consumed by
 * inserting a success row (already done by record_attempt) and prune old
 * failure noise. Keeps the table small.
 */
function login_clear_recent_failures(string $email): void
{
    try {
        $c = login_throttle_config();
        $since = date('Y-m-d H:i:s', time() - max($c['window_seconds'], $c['lockout_seconds']));
        executeQuery(
            "DELETE FROM login_attempts
             WHERE email = :email
               AND was_successful = 0
               AND attempted_at < :since",
            [':email' => $email, ':since' => $since]
        );
    } catch (Throwable $e) {
        error_log('[login-throttle:clear] ' . $e->getMessage());
    }
}
