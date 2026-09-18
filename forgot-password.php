<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/mailer.php';

start_session_once();
if (isAuthenticated()) {
    header('Location: ' . base_url('/dashboard.php'));
    exit;
}

$submitted = false;
$errors = [];
$old_email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_or_die();

    $old_email = strtolower(trim((string) ($_POST['email'] ?? '')));
    if (!filter_var($old_email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    } else {
        try {
            $user = fetchOne('SELECT id, name, email, status FROM users WHERE email = :email LIMIT 1', [':email' => $old_email]);
            if ($user && ($user['status'] ?? '') === 'active') {
                // Invalidate previous unused tokens.
                executeQuery(
                    'UPDATE password_reset_tokens SET used_at = NOW()
                     WHERE user_id = :uid AND used_at IS NULL',
                    [':uid' => (int) $user['id']]
                );
                // Fresh token.
                $token_plain = bin2hex(random_bytes(32));
                $token_hash  = hash('sha256', $token_plain);
                $expires_at  = date('Y-m-d H:i:s', time() + 3600); // 1 hour
                insertRecord('password_reset_tokens', [
                    'user_id'    => (int) $user['id'],
                    'token_hash' => $token_hash,
                    'expires_at' => $expires_at,
                ]);
                $reset_url = base_url('/reset-password.php?token=' . urlencode($token_plain));
                $html =
                    '<p>Hi ' . e($user['name']) . ',</p>' .
                    '<p>We received a request to reset your Finance Dashboard password. This link is valid for one hour:</p>' .
                    '<p><a href="' . e($reset_url) . '">Reset your password</a></p>' .
                    '<p>If you didn\'t request this, you can safely ignore this email.</p>';
                $sent = send_mail($old_email, 'Reset your Finance Dashboard password', $html);
                log_audit('password_reset_requested', 'user', (int) $user['id'], ['delivered' => (bool) $sent]);
            }
            // Whether or not the email existed, tell the user the same thing.
            $submitted = true;
        } catch (Throwable $e) {
            error_log('[forgot-password] ' . $e->getMessage());
            $errors['_general'] = 'Something went wrong. Please try again.';
        }
    }
}

$page_title = 'Forgot password';
$app_name = app_config('app')['name'] ?? 'Finance Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page_title . ' — ' . $app_name) ?></title>
    <link rel="stylesheet" href="<?= e(base_url('/ui/css/style.css')) ?>">
</head>
<body>
<div class="auth">
    <aside class="auth__aside">
        <div class="auth__brand">
            <span class="sidebar__logo" aria-hidden="true">F</span>
            <span><?= e($app_name) ?></span>
        </div>
        <div class="auth__pitch">
            <h2>Password trouble? We'll help.</h2>
            <p>Enter the email tied to your workspace and we'll send a secure reset link.</p>
        </div>
        <div class="auth__foot">&copy; <?= e(date('Y')) ?> <?= e($app_name) ?></div>
    </aside>

    <main class="auth__panel">
        <div class="auth-card">
            <h1>Reset your password</h1>

            <?php if ($submitted && empty($errors)): ?>
                <div class="flash flash--success mt-4" role="status">
                    <span>If the email is registered, we've sent a reset link. Check your inbox.</span>
                </div>
                <div class="form-foot">
                    <a href="login.php">Back to sign in</a>
                </div>
            <?php else: ?>
                <p class="text-muted">We'll email you a link to set a new one.</p>
                <?php if (!empty($errors['_general'])): ?>
                    <div class="flash flash--danger mt-4" role="alert"><span><?= e($errors['_general']) ?></span></div>
                <?php endif; ?>
                <form class="form mt-4" method="POST" action="forgot-password.php" data-validate novalidate>
                    <?= csrf_field() ?>
                    <div class="field<?= isset($errors['email']) ? ' field--error' : '' ?>">
                        <label for="email">Email address</label>
                        <input id="email" name="email" type="email" autocomplete="email" required value="<?= e($old_email) ?>">
                        <div class="error" role="alert"><?= e($errors['email'] ?? '') ?></div>
                    </div>
                    <button type="submit" class="btn btn--primary btn--block">Send reset link</button>
                </form>
                <div class="form-foot">
                    Remembered it? <a href="login.php">Back to sign in</a>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<script src="<?= e(base_url('/ui/js/app.js')) ?>"></script>
</body>
</html>
