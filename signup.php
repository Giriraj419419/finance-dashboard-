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

$errors = [];
$old = ['first_name' => '', 'last_name' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_or_die();

    $old['first_name'] = trim((string) ($_POST['first_name'] ?? ''));
    $old['last_name']  = trim((string) ($_POST['last_name']  ?? ''));
    $old['email']      = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password          = (string) ($_POST['password'] ?? '');
    $terms             = !empty($_POST['terms']);

    if ($old['first_name'] === '') {
        $errors['first_name'] = 'First name is required.';
    } elseif (mb_strlen($old['first_name']) > 80) {
        $errors['first_name'] = 'Keep the first name under 80 characters.';
    }
    if ($old['last_name'] === '') {
        $errors['last_name'] = 'Last name is required.';
    } elseif (mb_strlen($old['last_name']) > 80) {
        $errors['last_name'] = 'Keep the last name under 80 characters.';
    }
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    } elseif (mb_strlen($old['email']) > 190) {
        $errors['email'] = 'Email is too long.';
    }
    if (strlen($password) < 8) {
        $errors['password'] = 'Use at least 8 characters.';
    } elseif (!preg_match('/[0-9]/', $password)) {
        $errors['password'] = 'Include at least one number.';
    }
    if (!$terms) {
        $errors['terms'] = 'You must accept the terms.';
    }

    if ($errors === []) {
        try {
            $existing = fetchOne('SELECT id FROM users WHERE email = :email', [':email' => $old['email']]);
            if ($existing !== null) {
                // Do NOT reveal existence — but this is signup where a duplicate email is a legitimate friction.
                $errors['email'] = 'That email is already in use.';
            } else {
                $full_name = trim($old['first_name'] . ' ' . $old['last_name']);
                $id = insertRecord('users', [
                    'name'          => $full_name,
                    'email'         => $old['email'],
                    'password_hash' => hash_password($password),
                    'role'          => 'employee', // public signup can NEVER create admin/manager
                    'status'        => 'active',
                ]);
                loginUser([
                    'id'    => $id,
                    'name'  => $full_name,
                    'email' => $old['email'],
                    'role'  => 'employee',
                ]);
                log_audit('signup', 'user', $id, ['email' => $old['email']]);
                flash('success', 'Welcome to Finance Dashboard.');
                header('Location: ' . base_url('/dashboard.php'));
                exit;
            }
        } catch (Throwable $e) {
            error_log('[signup] ' . $e->getMessage());
            $errors['_general'] = 'Something went wrong. Please try again.';
        }
    }
}

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

            <?php if (!empty($errors['_general'])): ?>
                <div class="flash flash--danger mt-4" role="alert"><span><?= e($errors['_general']) ?></span></div>
            <?php endif; ?>

            <form class="form mt-4" method="POST" action="signup.php" data-validate novalidate>
                <?= csrf_field() ?>
                <div class="form__row">
                    <div class="field<?= isset($errors['first_name']) ? ' field--error' : '' ?>">
                        <label for="first_name">First name</label>
                        <input id="first_name" name="first_name" type="text" autocomplete="given-name" required value="<?= e($old['first_name']) ?>">
                        <div class="error" role="alert"><?= e($errors['first_name'] ?? '') ?></div>
                    </div>
                    <div class="field<?= isset($errors['last_name']) ? ' field--error' : '' ?>">
                        <label for="last_name">Last name</label>
                        <input id="last_name" name="last_name" type="text" autocomplete="family-name" required value="<?= e($old['last_name']) ?>">
                        <div class="error" role="alert"><?= e($errors['last_name'] ?? '') ?></div>
                    </div>
                </div>
                <div class="field<?= isset($errors['email']) ? ' field--error' : '' ?>">
                    <label for="email">Work email</label>
                    <input id="email" name="email" type="email" autocomplete="email" required placeholder="you@company.com" value="<?= e($old['email']) ?>">
                    <div class="error" role="alert"><?= e($errors['email'] ?? '') ?></div>
                </div>
                <div class="field<?= isset($errors['password']) ? ' field--error' : '' ?>">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" autocomplete="new-password" required minlength="8">
                    <div class="hint">At least 8 characters, including one number.</div>
                    <div class="error" role="alert"><?= e($errors['password'] ?? '') ?></div>
                </div>
                <label class="form-check form-check--top">
                    <input type="checkbox" name="terms" required <?= isset($errors['terms']) ? '' : '' ?>>
                    <span>I agree to the terms of service and privacy policy.</span>
                </label>
                <?php if (isset($errors['terms'])): ?>
                    <div class="error" role="alert"><?= e($errors['terms']) ?></div>
                <?php endif; ?>
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
