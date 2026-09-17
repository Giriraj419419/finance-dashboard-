<?php
require_once __DIR__ . '/includes/auth-check.php';
$page_title = 'Reminders';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span>Reminders</span></div>
    <div class="page-header">
        <div>
            <h1 class="page-header__title">Recurring reminders</h1>
            <p class="page-header__desc">Never miss a due date. Schedule reminders for taxes, renewals, and reviews.</p>
        </div>
        <div class="page-header__actions">
            <button class="btn btn--primary" type="button">New reminder</button>
        </div>
    </div>

    <section class="card">
        <div class="card__header"><h2 class="card__title">Your reminders</h2></div>
        <div class="card__body">
            <div class="empty-state">
                <div class="empty-state__icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 1 1 12 0c0 5 2 6 2 8H4c0-2 2-3 2-8z"/><path d="M10 20a2 2 0 0 0 4 0"/></svg></div>
                <div class="empty-state__title">Nothing scheduled</div>
                <div class="empty-state__desc">Create a reminder to stay ahead of recurring finance tasks.</div>
                <button class="btn btn--primary" type="button">Add reminder</button>
            </div>
        </div>
    </section>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
