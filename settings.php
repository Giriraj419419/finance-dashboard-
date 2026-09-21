<?php
/**
 * Settings — admin-only read-out of the workspace configuration.
 *
 * Single-user production mode: workspace name, timezone, and currency are
 * set in config.php (server-side) rather than through a UI form. This page
 * surfaces those values plus per-user notification preferences so an admin
 * can confirm what the server is running.
 */
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';
requireRole('admin');
$page_title = 'Settings';

$app = app_config('app');
$mail = app_config('mail');
$push = app_config('push');
$sess = app_config('session');
$sec  = app_config('security');

$me  = currentUser();
$uid = (int) $me['id'];

// Per-user notification preferences (may not exist yet — treat missing row
// as "both enabled" to match the default at insert time).
try {
    $prefs = fetchOne(
        'SELECT email_enabled, push_enabled FROM user_notification_preferences WHERE user_id = :u',
        [':u' => $uid]
    );
} catch (Throwable $e) {
    error_log('[settings] ' . $e->getMessage());
    $prefs = null;
}
$email_enabled = $prefs === null ? true : ((int) $prefs['email_enabled'] === 1);
$push_enabled  = $prefs === null ? true : ((int) $prefs['push_enabled']  === 1);

$mail_ready = !empty($mail['host']) && !empty($mail['from_email']);
$push_ready = !empty($push['vapid_public_key']) && !empty($push['vapid_private_key_path'])
              && is_file((string) $push['vapid_private_key_path']);

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span>Settings</span></div>
    <div class="page-header">
        <div>
            <h1 class="page-header__title">Settings</h1>
            <p class="page-header__desc">Server configuration and per-user notification state. <span class="badge badge--info">Admin</span></p>
        </div>
    </div>

    <div class="dash-grid">
        <section class="card">
            <div class="card__header"><h2 class="card__title">Workspace</h2></div>
            <div class="card__body">
                <div class="item-row">
                    <div class="item-row__grow"><div class="item-row__title">Application</div>
                        <div class="item-row__meta"><?= e($app['name'] ?? 'Finance Dashboard') ?></div>
                    </div>
                </div>
                <div class="item-row">
                    <div class="item-row__grow"><div class="item-row__title">Environment</div>
                        <div class="item-row__meta"><?= e($app['environment'] ?? 'development') ?></div>
                    </div>
                    <span class="badge badge--<?= (($app['environment'] ?? '') === 'production') ? 'success' : 'warning' ?>">
                        <?= (($app['environment'] ?? '') === 'production') ? 'Production' : 'Non-production' ?>
                    </span>
                </div>
                <div class="item-row">
                    <div class="item-row__grow"><div class="item-row__title">Debug mode</div>
                        <div class="item-row__meta"><?= empty($app['debug']) ? 'Off (safe for production)' : 'On (dev only)' ?></div>
                    </div>
                    <span class="badge badge--<?= empty($app['debug']) ? 'success' : 'warning' ?>">
                        <?= empty($app['debug']) ? 'Off' : 'On' ?>
                    </span>
                </div>
                <div class="item-row">
                    <div class="item-row__grow"><div class="item-row__title">Timezone</div>
                        <div class="item-row__meta"><?= e($app['timezone'] ?? date_default_timezone_get()) ?></div>
                    </div>
                </div>
                <div class="item-row">
                    <div class="item-row__grow"><div class="item-row__title">Base URL</div>
                        <div class="item-row__meta"><?= e($app['base_url'] ?: '(auto-detected)') ?></div>
                    </div>
                </div>
                <div class="item-row">
                    <div class="item-row__grow"><div class="item-row__title">Session cookie</div>
                        <div class="item-row__meta">Secure: <?= !empty($sess['secure']) ? 'yes' : 'no' ?>
                            &nbsp;·&nbsp; HttpOnly: <?= !empty($sess['httponly']) ? 'yes' : 'no' ?>
                            &nbsp;·&nbsp; SameSite: <?= e((string) ($sess['samesite'] ?? 'Lax')) ?></div>
                    </div>
                    <span class="badge badge--<?= !empty($sess['secure']) ? 'success' : 'warning' ?>">
                        <?= !empty($sess['secure']) ? 'Hardened' : 'HTTP-safe' ?>
                    </span>
                </div>
                <div class="text-muted mt-2">To change any of these, edit <code>config.php</code> on the server and redeploy.</div>
            </div>
        </section>

        <section class="card">
            <div class="card__header"><h2 class="card__title">Notifications</h2></div>
            <div class="card__body">
                <div class="item-row">
                    <div class="item-row__grow"><div class="item-row__title">SMTP mailer</div>
                        <div class="item-row__meta">
                            <?= $mail_ready
                                ? e($mail['from_email']) . ' via ' . e((string) $mail['host'])
                                : 'Not configured (mail.host / mail.from_email empty in config.php)' ?>
                        </div>
                    </div>
                    <span class="badge badge--<?= $mail_ready ? 'success' : 'danger' ?>">
                        <?= $mail_ready ? 'Configured' : 'Missing' ?>
                    </span>
                </div>
                <div class="item-row">
                    <div class="item-row__grow"><div class="item-row__title">Web Push (VAPID)</div>
                        <div class="item-row__meta">
                            <?= $push_ready
                                ? 'Public key set · private key present on server'
                                : 'Not configured (generate with cron/generate-vapid-keys.php)' ?>
                        </div>
                    </div>
                    <span class="badge badge--<?= $push_ready ? 'success' : 'danger' ?>">
                        <?= $push_ready ? 'Configured' : 'Missing' ?>
                    </span>
                </div>
                <div class="item-row">
                    <div class="item-row__grow"><div class="item-row__title">Your email delivery</div>
                        <div class="item-row__meta">Reminder emails go to <?= e((string) ($me['email'] ?? '')) ?></div>
                    </div>
                    <span class="badge badge--<?= $email_enabled ? 'success' : 'neutral' ?>">
                        <?= $email_enabled ? 'Enabled' : 'Disabled' ?>
                    </span>
                </div>
                <div class="item-row">
                    <div class="item-row__grow"><div class="item-row__title">Your push subscription</div>
                        <div class="item-row__meta">Manage per-browser subscription on your profile.</div>
                    </div>
                    <span class="badge badge--<?= $push_enabled ? 'success' : 'neutral' ?>">
                        <?= $push_enabled ? 'Enabled' : 'Disabled' ?>
                    </span>
                </div>
                <div class="mt-2"><a class="btn btn--ghost" href="<?= e(base_url('/profile.php')) ?>">Open profile</a></div>
            </div>
        </section>

        <section class="card">
            <div class="card__header"><h2 class="card__title">Security</h2></div>
            <div class="card__body">
                <div class="item-row">
                    <div class="item-row__grow"><div class="item-row__title">Password hashing</div>
                        <div class="item-row__meta">bcrypt cost <?= (int) ($sec['bcrypt_cost'] ?? 12) ?></div>
                    </div>
                </div>
                <div class="item-row">
                    <div class="item-row__grow"><div class="item-row__title">Login throttle</div>
                        <div class="item-row__meta">
                            <?= (int) ($sec['login_throttle']['threshold'] ?? 5) ?> attempts in
                            <?= (int) round(((int) ($sec['login_throttle']['window_seconds']  ?? 900)) / 60) ?> min
                            → <?= (int) round(((int) ($sec['login_throttle']['lockout_seconds'] ?? 900)) / 60) ?> min lockout
                        </div>
                    </div>
                </div>
                <div class="item-row">
                    <div class="item-row__grow"><div class="item-row__title">Public sign-up</div>
                        <div class="item-row__meta">Disabled (single-user production mode)</div>
                    </div>
                    <span class="badge badge--success">Disabled</span>
                </div>
            </div>
        </section>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
