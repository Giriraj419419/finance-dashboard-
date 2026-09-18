<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
if (!headers_sent()) {
    http_response_code(403);
}
$page_title = 'Access denied';
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
            <h2>Access denied</h2>
            <p>Your account does not have permission to view that page. If you think this is a mistake, ask an administrator to review your role.</p>
        </div>
        <div class="auth__foot">&copy; <?= e(date('Y')) ?> <?= e($app_name) ?></div>
    </aside>
    <main class="auth__panel">
        <div class="auth-card">
            <h1>403 — Forbidden</h1>
            <p class="text-muted">You don't have access to this resource.</p>
            <div class="mt-4 flex gap-2">
                <a class="btn btn--primary" href="<?= e(base_url('/dashboard.php')) ?>">Back to dashboard</a>
                <a class="btn btn--ghost"   href="<?= e(base_url('/logout.php')) ?>">Sign out</a>
            </div>
        </div>
    </main>
</div>
</body>
</html>
