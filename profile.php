<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/audit.php';

$page_title = 'Profile';
$me = currentUser();
$uid = (int) $me['id'];

$profile_errors  = [];
$password_errors = [];

// Refresh user record from the database so a rename by another session shows up.
try {
    $db_user = fetchOne(
        'SELECT id, name, email, role, status FROM users WHERE id = :id LIMIT 1',
        [':id' => $uid]
    );
    if ($db_user) {
        // Keep session snapshot fresh.
        $_SESSION['user']['name']  = $db_user['name'];
        $_SESSION['user']['email'] = $db_user['email'];
        $_SESSION['user']['role']  = $db_user['role'];
        $me = currentUser();
    }
} catch (Throwable $e) {
    error_log('[profile:load] ' . $e->getMessage());
}

$old = [
    'name'  => (string) ($me['name']  ?? ''),
    'email' => (string) ($me['email'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_or_die();
    $form = $_POST['form'] ?? '';

    // ---------- Update name / email --------------------------------------
    if ($form === 'profile') {
        $old['name']  = trim((string) ($_POST['name']  ?? ''));
        $old['email'] = strtolower(trim((string) ($_POST['email'] ?? '')));

        if ($old['name'] === '' || mb_strlen($old['name']) > 160) {
            $profile_errors['name'] = 'Name is required (max 160 characters).';
        }
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($old['email']) > 190) {
            $profile_errors['email'] = 'Enter a valid email address.';
        }

        if ($profile_errors === []) {
            try {
                // Duplicate email check (exclude self).
                $dup = fetchOne(
                    'SELECT id FROM users WHERE email = :e AND id <> :id LIMIT 1',
                    [':e' => $old['email'], ':id' => $uid]
                );
                if ($dup) {
                    $profile_errors['email'] = 'That email is already in use.';
                } else {
                    updateRecord('users', [
                        'name'  => $old['name'],
                        'email' => $old['email'],
                    ], ['id' => $uid]);
                    $_SESSION['user']['name']  = $old['name'];
                    $_SESSION['user']['email'] = $old['email'];
                    log_audit('profile_updated', 'user', $uid);
                    flash('success', 'Profile updated.');
                    header('Location: ' . base_url('/profile.php'));
                    exit;
                }
            } catch (Throwable $e) {
                error_log('[profile:save] ' . $e->getMessage());
                $profile_errors['_general'] = 'Could not save changes. Please try again.';
            }
        }
    }

    // ---------- Change password ------------------------------------------
    if ($form === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');

        if ($current === '') {
            $password_errors['current_password'] = 'Enter your current password.';
        }
        if (strlen($new) < 8) {
            $password_errors['new_password'] = 'Use at least 8 characters.';
        } elseif (!preg_match('/[0-9]/', $new)) {
            $password_errors['new_password'] = 'Include at least one number.';
        }
        if ($new !== $confirm) {
            $password_errors['new_password_confirm'] = 'Passwords do not match.';
        }

        if ($password_errors === []) {
            try {
                $row = fetchOne('SELECT password_hash FROM users WHERE id = :id LIMIT 1', [':id' => $uid]);
                if (!$row || !verify_password($current, $row['password_hash'])) {
                    // Generic message — never confirm what was wrong.
                    $password_errors['current_password'] = 'Current password is incorrect.';
                    log_audit('password_change_failed', 'user', $uid);
                } else {
                    updateRecord('users', ['password_hash' => hash_password($new)], ['id' => $uid]);
                    // Invalidate any active password-reset tokens.
                    executeQuery(
                        'UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL',
                        [':uid' => $uid]
                    );
                    // Rotate session id so a stolen cookie can't outlive the password change.
                    regenerate_session();
                    if (function_exists('csrf_rotate')) csrf_rotate();
                    log_audit('password_changed', 'user', $uid);
                    flash('success', 'Password updated.');
                    header('Location: ' . base_url('/profile.php'));
                    exit;
                }
            } catch (Throwable $e) {
                error_log('[profile:password] ' . $e->getMessage());
                $password_errors['_general'] = 'Could not update the password. Please try again.';
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span>Profile</span></div>
    <div class="page-header">
        <div>
            <h1 class="page-header__title">Your profile</h1>
            <p class="page-header__desc">Manage your personal information and password.</p>
        </div>
    </div>

    <div class="dash-grid">
        <section class="card">
            <div class="card__header"><h2 class="card__title">Personal info</h2></div>
            <div class="card__body">
                <?php if (!empty($profile_errors['_general'])): ?>
                    <div class="flash flash--danger mt-2" role="alert"><span><?= e($profile_errors['_general']) ?></span></div>
                <?php endif; ?>
                <form class="form" method="POST" action="profile.php" data-validate novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="form" value="profile">
                    <div class="form__row">
                        <div class="field<?= isset($profile_errors['name']) ? ' field--error' : '' ?>">
                            <label for="p_name">Full name</label>
                            <input id="p_name" name="name" type="text" value="<?= e($old['name']) ?>" required>
                            <div class="error" role="alert"><?= e($profile_errors['name'] ?? '') ?></div>
                        </div>
                        <div class="field<?= isset($profile_errors['email']) ? ' field--error' : '' ?>">
                            <label for="p_email">Email</label>
                            <input id="p_email" name="email" type="email" value="<?= e($old['email']) ?>" required>
                            <div class="error" role="alert"><?= e($profile_errors['email'] ?? '') ?></div>
                        </div>
                    </div>
                    <div class="field">
                        <label for="p_role">Role</label>
                        <input id="p_role" type="text" value="<?= e(ucfirst((string) ($me['role'] ?? 'employee'))) ?>" disabled>
                        <div class="hint">Role changes are made by an administrator.</div>
                    </div>
                    <div>
                        <button type="submit" class="btn btn--primary">Save changes</button>
                    </div>
                </form>
            </div>
        </section>

        <section class="card">
            <div class="card__header"><h2 class="card__title">Notifications</h2></div>
            <div class="card__body">
                <p class="text-muted">Receive reminder notifications on this browser even when the dashboard tab is closed.</p>
                <div class="push-status" data-push-status>Checking browser support…</div>
                <div class="flex gap-2 mt-2">
                    <button type="button" class="btn btn--primary" data-push-enable>Enable notifications</button>
                    <button type="button" class="btn btn--ghost is-hidden" data-push-disable>Disable</button>
                    <button type="button" class="btn btn--ghost is-hidden" data-push-test>Send test</button>
                </div>
                <p class="text-soft mt-2 text-xs">Email reminders still send from the server-side cron regardless of this toggle.</p>
            </div>
        </section>

        <section class="card">
            <div class="card__header"><h2 class="card__title">Change password</h2></div>
            <div class="card__body">
                <?php if (!empty($password_errors['_general'])): ?>
                    <div class="flash flash--danger mt-2" role="alert"><span><?= e($password_errors['_general']) ?></span></div>
                <?php endif; ?>
                <form class="form" method="POST" action="profile.php" data-validate novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="form" value="password">
                    <div class="field<?= isset($password_errors['current_password']) ? ' field--error' : '' ?>">
                        <label for="cur">Current password</label>
                        <input id="cur" name="current_password" type="password" autocomplete="current-password" required>
                        <div class="error" role="alert"><?= e($password_errors['current_password'] ?? '') ?></div>
                    </div>
                    <div class="field<?= isset($password_errors['new_password']) ? ' field--error' : '' ?>">
                        <label for="np">New password</label>
                        <input id="np" name="new_password" type="password" autocomplete="new-password" required minlength="8">
                        <div class="hint">At least 8 characters, including one number.</div>
                        <div class="error" role="alert"><?= e($password_errors['new_password'] ?? '') ?></div>
                    </div>
                    <div class="field<?= isset($password_errors['new_password_confirm']) ? ' field--error' : '' ?>">
                        <label for="np2">Confirm new password</label>
                        <input id="np2" name="new_password_confirm" type="password" autocomplete="new-password" required minlength="8">
                        <div class="error" role="alert"><?= e($password_errors['new_password_confirm'] ?? '') ?></div>
                    </div>
                    <div>
                        <button type="submit" class="btn btn--primary">Update password</button>
                    </div>
                </form>
            </div>
        </section>
    </div>
</section>
<?php
$push_cfg = app_config('push');
$vapid_pub = (string) ($push_cfg['vapid_public_key'] ?? '');
?>
<script>
window.__push = {
    vapidPublicKey: <?= json_encode($vapid_pub) ?>,
    csrfToken:      <?= json_encode(csrf_token()) ?>
};
</script>
<script src="<?= e(base_url('/ui/js/push.js')) ?>"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
