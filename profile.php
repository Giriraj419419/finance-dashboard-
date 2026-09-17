<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/csrf.php';
$page_title = 'Profile';

$user = current_user();
$name = $user['name'] ?? '';
$email = $user['email'] ?? '';
$role = $user['role'] ?? 'employee';

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
                <form class="form" method="POST" action="profile.php" data-validate novalidate>
                    <?= csrf_field() ?>
                    <div class="form__row">
                        <div class="field">
                            <label for="p_name">Full name</label>
                            <input id="p_name" name="name" type="text" value="<?= e($name) ?>" required>
                            <div class="error" role="alert"></div>
                        </div>
                        <div class="field">
                            <label for="p_email">Email</label>
                            <input id="p_email" name="email" type="email" value="<?= e($email) ?>" required>
                            <div class="error" role="alert"></div>
                        </div>
                    </div>
                    <div class="field">
                        <label for="p_role">Role</label>
                        <input id="p_role" type="text" value="<?= e(ucfirst($role)) ?>" disabled>
                        <div class="hint">Role changes are made by an administrator.</div>
                    </div>
                    <div>
                        <button type="submit" class="btn btn--primary">Save changes</button>
                    </div>
                </form>
            </div>
        </section>

        <section class="card">
            <div class="card__header"><h2 class="card__title">Change password</h2></div>
            <div class="card__body">
                <form class="form" method="POST" action="profile.php" data-validate novalidate>
                    <?= csrf_field() ?>
                    <div class="field">
                        <label for="cur">Current password</label>
                        <input id="cur" name="current_password" type="password" autocomplete="current-password" required>
                        <div class="error" role="alert"></div>
                    </div>
                    <div class="field">
                        <label for="np">New password</label>
                        <input id="np" name="new_password" type="password" autocomplete="new-password" required minlength="8">
                        <div class="hint">At least 8 characters.</div>
                        <div class="error" role="alert"></div>
                    </div>
                    <div class="field">
                        <label for="np2">Confirm new password</label>
                        <input id="np2" name="new_password_confirm" type="password" autocomplete="new-password" required minlength="8">
                        <div class="error" role="alert"></div>
                    </div>
                    <div>
                        <button type="submit" class="btn btn--primary">Update password</button>
                    </div>
                </form>
            </div>
        </section>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
