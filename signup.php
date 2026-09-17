<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';

$page_title = 'Create account';
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
            <h2>Start tracking in under two minutes.</h2>
            <p>Create your workspace, invite your team, and get a real-time view of cash flow, budgets, and goals.</p>
        </div>
        <div class="auth__foot">&copy; <?= e(date('Y')) ?> <?= e($app_name) ?></div>
    </aside>

    <main class="auth__panel">
        <div class="auth-card">
            <h1>Create your account</h1>
            <p class="text-muted">Free to start — no credit card required.</p>

            <form class="form mt-4" method="POST" action="signup.php" data-validate novalidate>
                <?= csrf_field() ?>
                <div class="form__row">
                    <div class="field">
                        <label for="first_name">First name</label>
                        <input id="first_name" name="first_name" type="text" autocomplete="given-name" required>
                        <div class="error" role="alert"></div>
                    </div>
                    <div class="field">
                        <label for="last_name">Last name</label>
                        <input id="last_name" name="last_name" type="text" autocomplete="family-name" required>
                        <div class="error" role="alert"></div>
                    </div>
                </div>
                <div class="field">
                    <label for="email">Work email</label>
                    <input id="email" name="email" type="email" autocomplete="email" required placeholder="you@company.com">
                    <div class="error" role="alert"></div>
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" autocomplete="new-password" required minlength="8">
                    <div class="hint">At least 8 characters, including one number.</div>
                    <div class="error" role="alert"></div>
                </div>
                <label class="form-check form-check--top">
                    <input type="checkbox" name="terms" required>
                    <span>I agree to the terms of service and privacy policy.</span>
                </label>
                <button type="submit" class="btn btn--primary btn--block">Create account</button>
            </form>

            <div class="form-foot">
                Already have an account? <a href="login.php">Sign in</a>
            </div>
        </div>
    </main>
</div>
<script src="<?= e(base_url('/assets/js/app.js')) ?>"></script>
</body>
</html>
