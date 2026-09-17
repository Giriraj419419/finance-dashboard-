<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';

$page_title = 'Sign in';
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
            <h2>Every rupee, dollar, and decision in one place.</h2>
            <p>Track income and expenses, plan budgets, and hit financial goals with clarity. Built for teams that need real numbers, fast.</p>
        </div>
        <div class="auth__foot">&copy; <?= e(date('Y')) ?> <?= e($app_name) ?></div>
    </aside>

    <main class="auth__panel">
        <div class="auth-card">
            <h1>Welcome back</h1>
            <p class="text-muted">Sign in to continue to your dashboard.</p>

            <form class="form mt-4" method="POST" action="login.php" data-validate novalidate>
                <?= csrf_field() ?>
                <div class="field">
                    <label for="email">Email address</label>
                    <input id="email" name="email" type="email" autocomplete="email" required placeholder="you@company.com">
                    <div class="error" role="alert"></div>
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required placeholder="••••••••">
                    <div class="error" role="alert"></div>
                </div>
                <div class="flex-between">
                    <label class="form-check">
                        <input type="checkbox" name="remember" value="1"> Remember me
                    </label>
                    <a class="link-sm" href="forgot-password.php">Forgot password?</a>
                </div>
                <button type="submit" class="btn btn--primary btn--block">Sign in</button>
            </form>

            <div class="form-foot">
                Don't have an account? <a href="signup.php">Create one</a>
            </div>
        </div>
    </main>
</div>
<script src="<?= e(base_url('/assets/js/app.js')) ?>"></script>
</body>
</html>
