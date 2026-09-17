<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';

$page_title = 'Set new password';
$app_name = app_config('app')['name'] ?? 'Finance Dashboard';

$token = isset($_GET['token']) ? (string) $_GET['token'] : '';
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
            <p class="text-muted">Your password must be at least 8 characters.</p>

            <form class="form mt-4" method="POST" action="reset-password.php" data-validate novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <div class="field">
                    <label for="password">New password</label>
                    <input id="password" name="password" type="password" autocomplete="new-password" required minlength="8">
                    <div class="error" role="alert"></div>
                </div>
                <div class="field">
                    <label for="confirm">Confirm new password</label>
                    <input id="confirm" name="confirm" type="password" autocomplete="new-password" required minlength="8">
                    <div class="error" role="alert"></div>
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
