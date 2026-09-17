<?php
require_once __DIR__ . '/includes/auth-check.php';
$page_title = 'Goals';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span>Goals</span></div>
    <div class="page-header">
        <div>
            <h1 class="page-header__title">Financial goals</h1>
            <p class="page-header__desc">Track progress toward savings targets and long-term milestones.</p>
        </div>
        <div class="page-header__actions">
            <button class="btn btn--primary" type="button">New goal</button>
        </div>
    </div>

    <section class="card">
        <div class="card__header"><h2 class="card__title">Active goals</h2></div>
        <div class="card__body">
            <div class="empty-state">
                <div class="empty-state__icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.4"/></svg>
                </div>
                <div class="empty-state__title">No goals yet</div>
                <div class="empty-state__desc">Set a target amount and deadline to keep saving on track.</div>
                <button class="btn btn--primary" type="button">Add a goal</button>
            </div>
        </div>
    </section>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
