<?php
/**
 * Session + role helpers.
 * Phase 1 defines the surface only. Real login/verify lands in a later phase.
 */

require_once __DIR__ . '/functions.php';

function start_session_once(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $s = app_config('session');
    session_name($s['name'] ?? 'FIN_SESSION');
    session_set_cookie_params([
        'lifetime' => (int) ($s['lifetime'] ?? 0),
        'path'     => '/',
        'secure'   => (bool) ($s['secure'] ?? false),
        'httponly' => (bool) ($s['httponly'] ?? true),
        'samesite' => $s['samesite'] ?? 'Lax',
    ]);
    session_start();
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function user_role(): ?string
{
    return current_user()['role'] ?? null;
}

function has_role(string ...$roles): bool
{
    $r = user_role();
    return $r !== null && in_array($r, $roles, true);
}

/**
 * bcrypt hash + verify wrappers. Not exercised in Phase 1, but ready.
 */
function hash_password(string $plain): string
{
    $cost = (int) (app_config('security')['bcrypt_cost'] ?? 12);
    return password_hash($plain, PASSWORD_BCRYPT, ['cost' => $cost]);
}

function verify_password(string $plain, string $hash): bool
{
    return password_verify($plain, $hash);
}
