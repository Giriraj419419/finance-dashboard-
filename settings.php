<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/csrf.php';
$page_title = 'Settings';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span>Settings</span></div>
    <div class="page-header">
        <div>
            <h1 class="page-header__title">Settings</h1>
            <p class="page-header__desc">Workspace preferences and administrative controls. <span class="badge badge--info">Admin</span></p>
        </div>
    </div>

    <div class="dash-grid">
        <section class="card">
            <div class="card__header"><h2 class="card__title">Workspace</h2></div>
            <div class="card__body">
                <form class="form" method="POST" action="settings.php" data-validate novalidate>
                    <?= csrf_field() ?>
                    <div class="field">
                        <label for="ws_name">Workspace name</label>
                        <input id="ws_name" name="workspace_name" type="text" value="Acme Finance" required>
                        <div class="error" role="alert"></div>
                    </div>
                    <div class="form__row">
                        <div class="field">
                            <label for="ws_currency">Default currency</label>
                            <select id="ws_currency" name="currency">
                                <option>USD — US Dollar</option>
                                <option>INR — Indian Rupee</option>
                                <option>EUR — Euro</option>
                                <option>GBP — British Pound</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="ws_tz">Timezone</label>
                            <select id="ws_tz" name="timezone">
                                <option>UTC</option>
                                <option>Asia/Kolkata</option>
                                <option>America/New_York</option>
                                <option>Europe/London</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <button class="btn btn--primary" type="submit">Save workspace</button>
                    </div>
                </form>
            </div>
        </section>

        <section class="card">
            <div class="card__header"><h2 class="card__title">Team members</h2></div>
            <div class="card__body">
                <div class="empty-state">
                    <div class="empty-state__icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="4"/><path d="M2 21c0-4 3-7 7-7s7 3 7 7"/><circle cx="17" cy="9" r="3"/><path d="M22 20c0-3-2-5-5-5"/></svg></div>
                    <div class="empty-state__title">Invite your team</div>
                    <div class="empty-state__desc">Invite Admins, Managers, and Employees to collaborate.</div>
                    <button class="btn btn--primary" type="button">Invite members</button>
                </div>
            </div>
        </section>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
