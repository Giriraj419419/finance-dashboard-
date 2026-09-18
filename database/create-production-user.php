<?php
/**
 * Interactive production-user setup / password rotation.
 *
 * Single-user deployment: this script provisions the ONE production
 * account (accounts@kktechsolutions.in by default) or rotates its
 * password. The password is read from the tty without echoing and is
 * never printed, logged, or written to disk in plaintext.
 *
 * Usage (from cPanel Terminal or SSH):
 *
 *     cd /home/kktechsolutions/public_html/finance.kktechsolutions.in
 *     php database/create-production-user.php
 *
 * Safety guarantees:
 *   - CLI-only (refuses to run under any web SAPI, and lives under
 *     /database/ which is deny-all in Apache).
 *   - Password read via `stty -echo` — never visible in the terminal,
 *     never in shell history, never in logs.
 *   - Bcrypt hash generated with the configured cost.
 *   - Refuses to CREATE a second production user; existing accounts can
 *     only have their password / status / role updated.
 *   - Never prints the password or the hash.
 */

$is_web_request = PHP_SAPI !== 'cli'
    && (isset($_SERVER['HTTP_HOST']) || isset($_SERVER['REMOTE_ADDR']) || isset($_SERVER['REQUEST_METHOD']));
if ($is_web_request) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "CLI-only script. Run from the shell.\n";
    exit(1);
}

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../auth.php';

const PRODUCTION_EMAIL = 'accounts@kktechsolutions.in';
const PRODUCTION_NAME  = 'Accounts';

// ------------------------------------------------------------------------
// Interactive helpers
// ------------------------------------------------------------------------
function prompt_line(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $line = fgets(STDIN);
    return $line === false ? '' : rtrim($line, "\r\n");
}

/**
 * Read a password from the tty without echoing it. Falls back to plain
 * fgets() if stty is unavailable (unusual on cPanel/Linux) and warns.
 */
function prompt_password(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $stty_available = (bool) @shell_exec('command -v stty 2>/dev/null');
    if ($stty_available) {
        $old = trim((string) shell_exec('stty -g'));
        shell_exec('stty -echo');
    } else {
        fwrite(STDOUT, "\n[warn] stty not available — password may echo. Type carefully.\n> ");
    }
    $pwd = fgets(STDIN);
    if ($stty_available) {
        shell_exec('stty ' . escapeshellarg($old));
        fwrite(STDOUT, "\n");
    }
    return $pwd === false ? '' : rtrim($pwd, "\r\n");
}

function fail(string $msg, int $code = 1): never
{
    fwrite(STDERR, "[error] $msg\n");
    exit($code);
}

// ------------------------------------------------------------------------
// Confirm this is really what the operator wants
// ------------------------------------------------------------------------
$email = PRODUCTION_EMAIL;
echo "==========================================================\n";
echo "  Finance Dashboard — production user setup\n";
echo "==========================================================\n";
echo "This script creates or updates ONE production account:\n";
echo "  email: $email\n";
echo "  role:  admin\n";
echo "  status: active\n";
echo "\n";
echo "The password is read without echoing.\n";
echo "It is hashed with bcrypt and never printed.\n";
echo "\n";

// ------------------------------------------------------------------------
// Read + validate the password
// ------------------------------------------------------------------------
$password = prompt_password("Enter a new password (min 8 chars, must contain a digit): ");
if ($password === '') {
    fail('empty password — aborting');
}
if (strlen($password) < 8) {
    fail('password must be at least 8 characters');
}
if (!preg_match('/[0-9]/', $password)) {
    fail('password must contain at least one digit');
}

$confirm = prompt_password("Confirm password: ");
if (!hash_equals($password, $confirm)) {
    // Wipe both from memory before erroring.
    $password = str_repeat("\0", strlen($password));
    $confirm  = str_repeat("\0", strlen($confirm));
    fail('passwords do not match — aborting');
}
$confirm = str_repeat("\0", strlen($confirm));

// ------------------------------------------------------------------------
// Insert or update — never a second account
// ------------------------------------------------------------------------
try {
    $existing = fetchOne('SELECT id, role, status FROM users WHERE email = :e LIMIT 1', [':e' => $email]);
    $hash = hash_password($password);
    // Immediately wipe the plaintext.
    $password = str_repeat("\0", strlen($password));

    if ($existing) {
        // Update path — rotate password, ensure role + status.
        executeQuery(
            'UPDATE users SET password_hash = :h, role = :r, status = :s WHERE id = :id',
            [':h' => $hash, ':r' => 'admin', ':s' => 'active', ':id' => (int) $existing['id']]
        );
        // Invalidate any active reset tokens for the account.
        executeQuery(
            'UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL',
            [':uid' => (int) $existing['id']]
        );
        echo "[ok] Password rotated for existing user id={$existing['id']}.\n";
    } else {
        // Refuse a second production user if any account already exists.
        $count = (int) (fetchOne('SELECT COUNT(*) AS c FROM users')['c'] ?? 0);
        if ($count > 0) {
            fail(
                'refusing to create a second production user. ' .
                "The database already has $count user(s). " .
                'This deployment is single-user. If you must reassign the account, ' .
                'log in and change the email of the existing user, or use phpMyAdmin.'
            );
        }
        $id = insertRecord('users', [
            'name'          => PRODUCTION_NAME,
            'email'         => $email,
            'password_hash' => $hash,
            'role'          => 'admin',
            'status'        => 'active',
        ]);
        echo "[ok] Production user created: id={$id}.\n";
    }

    echo "\nDone. You can now sign in at your production URL with:\n";
    echo "  email:    $email\n";
    echo "  password: (the one you just typed)\n";
    exit(0);
} catch (Throwable $e) {
    fail('database write failed: ' . $e->getMessage());
}
