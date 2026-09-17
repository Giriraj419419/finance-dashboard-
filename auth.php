<?php
/**
 * Authentication + session + role helpers.
 *
 * Phase 3: real login/logout/signup are wired to these functions.
 * Sessions carry only { id, name, email, role, auth = true }.
 */

require_once __DIR__ . '/functions.php';

// ---------------------------------------------------------------------------
// Session lifecycle
// ---------------------------------------------------------------------------
function start_session_once(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $s = app_config('session');
    session_name($s['name'] ?? 'finance_dashboard_session');
    session_set_cookie_params([
        'lifetime' => (int) ($s['lifetime'] ?? 0),
        'path'     => '/',
        'secure'   => (bool) ($s['secure'] ?? false),
        'httponly' => (bool) ($s['httponly'] ?? true),
        'samesite' => $s['samesite'] ?? 'Lax',
    ]);
    session_start();
}

/**
 * Regenerate the session id, dropping the previous one — call after login
 * to prevent session fixation.
 */
function regenerate_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        start_session_once();
    }
    session_regenerate_id(true);
}

// ---------------------------------------------------------------------------
// User accessors
// ---------------------------------------------------------------------------
function currentUser(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }
    return $_SESSION['user'] ?? null;
}

// Back-compat with Phase 1 code.
function current_user(): ?array
{
    return currentUser();
}

function isAuthenticated(): bool
{
    $u = currentUser();
    return is_array($u) && !empty($u['auth']) && !empty($u['id']);
}

function is_logged_in(): bool
{
    return isAuthenticated();
}

function userRole(): ?string
{
    return currentUser()['role'] ?? null;
}

function user_role(): ?string
{
    return userRole();
}

function hasRole(string ...$roles): bool
{
    $r = userRole();
    return $r !== null && in_array($r, $roles, true);
}

function has_role(string ...$roles): bool
{
    return hasRole(...$roles);
}

// ---------------------------------------------------------------------------
// Guards
// ---------------------------------------------------------------------------
function requireLogin(): void
{
    start_session_once();
    if (!isAuthenticated()) {
        $target = $_SERVER['REQUEST_URI'] ?? '/';
        $_SESSION['_intended'] = $target;
        header('Location: ' . base_url('/login.php'));
        exit;
    }
}

function requireRole(string ...$roles): void
{
    requireLogin();
    if (!hasRole(...$roles)) {
        // Log the denial for the audit trail (best-effort).
        if (function_exists('log_audit')) {
            log_audit('access_denied', 'route', null, [
                'path' => $_SERVER['REQUEST_URI'] ?? '',
                'required_roles' => $roles,
            ]);
        }
        http_response_code(403);
        require __DIR__ . '/403.php';
        exit;
    }
}

// ---------------------------------------------------------------------------
// Password hashing
// ---------------------------------------------------------------------------
function hash_password(string $plain): string
{
    $cost = (int) (app_config('security')['bcrypt_cost'] ?? 12);
    return password_hash($plain, PASSWORD_BCRYPT, ['cost' => $cost]);
}

function verify_password(string $plain, string $hash): bool
{
    return password_verify($plain, $hash);
}

// ---------------------------------------------------------------------------
// Login / logout writes
// ---------------------------------------------------------------------------
/**
 * Populate the session with a freshly authenticated user record. Regenerates
 * the session id and rotates the CSRF token.
 */
function loginUser(array $user): void
{
    start_session_once();
    regenerate_session();
    if (function_exists('csrf_rotate')) {
        csrf_rotate();
    }
    $_SESSION['user'] = [
        'id'    => (int) $user['id'],
        'name'  => (string) ($user['name'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
        'role'  => (string) ($user['role'] ?? 'employee'),
        'auth'  => true,
    ];
}

function logoutUser(): void
{
    start_session_once();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'] ?? '',
            (bool) ($params['secure'] ?? false),
            (bool) ($params['httponly'] ?? true)
        );
    }
    session_destroy();
}
