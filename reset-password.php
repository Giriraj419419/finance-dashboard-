<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';

start_session_once();
if (isAuthenticated()) {
    header('Location: ' . base_url('/dashboard.php'));
    exit;
}

$token = isset($_GET['token']) ? (string) $_GET['token'] : (string) ($_POST['token'] ?? '');
$errors = [];
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_or_die();

    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['confirm']  ?? '');

    if ($token === '' || strlen($token) < 40) {
        $errors['_general'] = 'The reset link is invalid or has expired.';
    } elseif (strlen($password) < 8) {
        $errors['password'] = 'Use at least 8 characters.';
    } elseif (!preg_match('/[0-9]/', $password)) {
        $errors['password'] = 'Include at least one number.';
    } elseif ($password !== $confirm) {
        $errors['confirm'] = 'Passwords do not match.';
    }

    if ($errors === []) {
        try {
            $token_hash = hash('sha256', $token);
            $row = fetchOne(
                'SELECT prt.id AS token_id, prt.user_id, prt.expires_at, prt.used_at
                 FROM password_reset_tokens prt
                 WHERE prt.token_hash = :h LIMIT 1',
                [':h' => $token_hash]
            );

            $now = new DateTimeImmutable('now');
            $valid = $row
                && $row['used_at'] === null
                && (new DateTimeImmutable((string) $row['expires_at'])) > $now;

            if (!$valid) {
                $errors['_general'] = 'The reset link is invalid or has expired.';
            } else {
                $uid = (int) $row['user_id'];
                executeQuery(
                    'UPDATE users SET password_hash = :ph WHERE id = :id',
                    [':ph' => hash_password($password), ':id' => $uid]
                );
                // Mark this token used and invalidate any other active tokens for the user.
                executeQuery('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = :tid', [':tid' => (int) $row['token_id']]);
                executeQuery(
                    'UPDATE password_reset_tokens SET used_at = NOW()
                     WHERE user_id = :uid AND used_at IS NULL',
                    [':uid' => $uid]
                );
                log_audit('password_reset_completed', 'user', $uid);
                flash('success', 'Your password has been updated. You can now sign in.');
                header('Location: ' . base_url('/login.php'));
                exit;
            }
        } catch (Throwable $e) {
            error_log('[reset-password] ' . $e->getMessage());
            $errors['_general'] = 'Something went wrong. Please try again.';
        }
    }
}

$page_title = 'Set new password';
$app_name = app_config('app')['name'] ?? 'Finance Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page_title . ' — ' . $app_name) ?></title>
    <link rel="stylesheet" href="<?= e(base_url('/assets/css/style.css')) ?>">
</head>
<body>
<div class="auth">
    <aside class="auth__aside">
        <div class="auth__brand">
            <span class="sidebar__logo" aria-hidden="true">F</span>
            <span><?= e($app_name) ?></span>
        </div>
        <div class="auth__pitch">
            <h2>One step from getting back in.</h2>
            <p>Choose a strong new password. We recommend a passphrase you'll remember.</p>
        </div>
        <div class="auth__foot">&copy; <?= e(date('Y')) ?> <?= e($app_name) ?></div>
    </aside>
    <main class="auth__panel">
        <div class="auth-card">
            <h1>Set a new password</h1>
            <p class="text-muted">Your password must be at least 8 characters and include a number.</p>

            <?php if (!empty($errors['_general'])): ?>
                <div class="flash flash--danger mt-4" role="alert"><span><?= e($errors['_general']) ?></span></div>
            <?php endif; ?>

            <form class="form mt-4" method="POST" action="reset-password.php" data-validate novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <div class="field<?= isset($errors['password']) ? ' field--error' : '' ?>">
                    <label for="password">New password</label>
                    <input id="password" name="password" type="password" autocomplete="new-password" required minlength="8">
                    <div class="error" role="alert"><?= e($errors['password'] ?? '') ?></div>
                </div>
                <div class="field<?= isset($errors['confirm']) ? ' field--error' : '' ?>">
                    <label for="confirm">Confirm new password</label>
                    <input id="confirm" name="confirm" type="password" autocomplete="new-password" required minlength="8">
                    <div class="error" role="alert"><?= e($errors['confirm'] ?? '') ?></div>
                </div>
                <button type="submit" class="btn btn--primary btn--block">Update password</button>
            </form>

            <div class="form-foot">
                <a href="login.php">Back to sign in</a>
            </div>
        </div>
    </main>
</div>
<script src="<?= e(base_url('/assets/js/app.js')) ?>"></script>
</body>
</html>
