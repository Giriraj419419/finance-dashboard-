<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/login-throttle.php';

start_session_once();
if (isAuthenticated()) {
    header('Location: ' . base_url('/dashboard.php'));
    exit;
}

$errors = [];
$old_email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_or_die();

    $old_email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password  = (string) ($_POST['password'] ?? '');

    // Always run one bcrypt verify — constant CPU even when the email is unknown.
    // Combined with usleep() below, this closes the user-enumeration timing side channel.
    $DUMMY_HASH = '$2y$12$CzSUZNbRV1LKpipK1zi0v./88T7umivl9LNXlKng8V4km431mS0bS';

    $ip = client_ip();

    if ($old_email === '' || $password === '') {
        $errors['_general'] = 'Invalid email or password.';
    } elseif (login_is_blocked($old_email, $ip)) {
        // Do NOT reveal whether the email exists.
        log_audit('login_locked_out', 'user', null, ['email' => $old_email]);
        $errors['_general'] = 'Too many failed attempts. Try again later.';
    } else {
        try {
            $user = fetchOne(
                'SELECT id, name, email, password_hash, role, status FROM users WHERE email = :email LIMIT 1',
                [':email' => $old_email]
            );
            $hash = $user['password_hash'] ?? $DUMMY_HASH;
            $verified = $user && verify_password($password, $hash);

            if (!$verified) {
                // Small timing brake + persistent failure record for throttle.
                usleep(400_000);
                login_record_attempt($old_email, $ip, false);
                log_audit('login_failed', 'user', $user['id'] ?? null, ['email' => $old_email]);
                $errors['_general'] = 'Invalid email or password.';
            } elseif (($user['status'] ?? 'active') !== 'active') {
                login_record_attempt($old_email, $ip, false);
                log_audit('login_blocked', 'user', $user['id'], ['status' => $user['status']]);
                $errors['_general'] = 'Your account is not active. Contact an administrator.';
            } else {
                login_record_attempt($old_email, $ip, true);
                login_clear_recent_failures($old_email);
                loginUser($user);
                try {
                    executeQuery(
                        'UPDATE users SET last_login_at = NOW() WHERE id = :id',
                        [':id' => $user['id']]
                    );
                } catch (Throwable $e) {
                    // Non-fatal.
                    error_log('[login] last_login_at update failed: ' . $e->getMessage());
                }
                log_audit('login_success', 'user', (int) $user['id']);
                $target = $_SESSION['_intended'] ?? base_url('/dashboard.php');
                unset($_SESSION['_intended']);
                header('Location: ' . $target);
                exit;
            }
        } catch (Throwable $e) {
            error_log('[login] ' . $e->getMessage());
            $errors['_general'] = 'Something went wrong. Please try again.';
        }
    }
}

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
            <p>Track income and expenses, plan budgets, and hit financial goals with clarity.</p>
        </div>
        <div class="auth__foot">&copy; <?= e(date('Y')) ?> <?= e($app_name) ?></div>
    </aside>

    <main class="auth__panel">
        <div class="auth-card">
            <h1>Welcome back</h1>
            <p class="text-muted">Sign in to continue to your dashboard.</p>

            <?php if (!empty($errors['_general'])): ?>
                <div class="flash flash--danger mt-4" role="alert"><span><?= e($errors['_general']) ?></span></div>
            <?php endif; ?>

            <form class="form mt-4" method="POST" action="login.php" data-validate novalidate>
                <?= csrf_field() ?>
                <div class="field">
                    <label for="email">Email address</label>
                    <input id="email" name="email" type="email" autocomplete="email" required placeholder="you@company.com" value="<?= e($old_email) ?>">
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
                <a href="forgot-password.php">Forgot your password?</a>
            </div>
        </div>
    </main>
</div>
<script src="<?= e(base_url('/assets/js/app.js')) ?>"></script>
</body>
</html>
