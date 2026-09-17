<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';

$page_title = 'Forgot password';
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
            <h2>Password trouble? We'll help.</h2>
            <p>Enter the email tied to your workspace and we'll send a secure reset link.</p>
        </div>
        <div class="auth__foot">&copy; <?= e(date('Y')) ?> <?= e($app_name) ?></div>
    </aside>

    <main class="auth__panel">
        <div class="auth-card">
            <h1>Reset your password</h1>
            <p class="text-muted">We'll email you a link to set a new one.</p>

            <form class="form mt-4" method="POST" action="forgot-password.php" data-validate novalidate>
                <?= csrf_field() ?>
                <div class="field">
                    <label for="email">Email address</label>
                    <input id="email" name="email" type="email" autocomplete="email" required>
                    <div class="error" role="alert"></div>
                </div>
                <button type="submit" class="btn btn--primary btn--block">Send reset link</button>
            </form>

            <div class="form-foot">
                Remembered it? <a href="login.php">Back to sign in</a>
            </div>
        </div>
    </main>
</div>
<script src="<?= e(base_url('/assets/js/app.js')) ?>"></script>
</body>
</html>
