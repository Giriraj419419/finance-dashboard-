<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';

start_session_once();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_or_die();
    if (isAuthenticated()) {
        log_audit('logout', 'user', (int) (currentUser()['id'] ?? 0));
    }
    logoutUser();
    header('Location: ' . base_url('/login.php'));
    exit;
}

// GET → confirmation page with a POST form (no GET-triggered logout).
$page_title = 'Sign out';
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
            <h2>Sign out?</h2>
            <p>You can sign back in anytime with your email and password.</p>
        </div>
        <div class="auth__foot">&copy; <?= e(date('Y')) ?> <?= e($app_name) ?></div>
    </aside>
    <main class="auth__panel">
        <div class="auth-card">
            <h1>Confirm sign out</h1>
            <p class="text-muted">You'll be returned to the sign in page.</p>
            <form method="POST" action="logout.php" class="form mt-4">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn--primary btn--block">Sign out</button>
            </form>
            <div class="form-foot">
                <a href="<?= e(base_url('/dashboard.php')) ?>">Cancel</a>
            </div>
        </div>
    </main>
</div>
</body>
</html>
