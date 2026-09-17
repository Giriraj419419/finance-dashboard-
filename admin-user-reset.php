<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/mailer.php';
requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . base_url('/admin-users.php')); exit; }
csrf_check_or_die();

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) { flash('danger', 'Invalid user.'); header('Location: ' . base_url('/admin-users.php')); exit; }

try {
    $u = fetchOne('SELECT id, name, email, status FROM users WHERE id = :id LIMIT 1', [':id' => $id]);
    if (!$u) { flash('danger', 'User not found.'); header('Location: ' . base_url('/admin-users.php')); exit; }

    // Invalidate any prior active tokens.
    executeQuery(
        'UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL',
        [':uid' => $id]
    );

    $token_plain = bin2hex(random_bytes(32));
    $token_hash  = hash('sha256', $token_plain);
    $expires_at  = date('Y-m-d H:i:s', time() + 3600);
    insertRecord('password_reset_tokens', [
        'user_id'    => $id,
        'token_hash' => $token_hash,
        'expires_at' => $expires_at,
    ]);
    $reset_url = base_url('/reset-password.php?token=' . urlencode($token_plain));
    $html =
        '<p>Hi ' . e($u['name']) . ',</p>' .
        '<p>An administrator on Finance Dashboard requested a password reset for your account. This link is valid for one hour:</p>' .
        '<p><a href="' . e($reset_url) . '">Reset your password</a></p>' .
        '<p>If you didn\'t expect this, contact your administrator.</p>';
    $sent = send_mail((string) $u['email'], 'Reset your Finance Dashboard password', $html);

    log_audit('password_reset_requested', 'user', $id, ['triggered_by' => 'admin', 'delivered' => (bool) $sent]);
    flash('success', $sent ? 'Reset link sent.' : 'Reset link created — but the email could not be delivered. Check the mailer config.');
} catch (Throwable $e) {
    error_log('[admin-user-reset] ' . $e->getMessage());
    flash('danger', 'Could not initiate the reset.');
}
header('Location: ' . base_url('/admin-users.php')); exit;
