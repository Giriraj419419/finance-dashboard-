<?php
require_once __DIR__ . '/includes/auth-check.php';
$page_title = 'Budgets';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span>Budgets</span></div>
    <div class="page-header">
        <div>
            <h1 class="page-header__title">Budgets</h1>
            <p class="page-header__desc">Set monthly caps per category and monitor spend in real time.</p>
        </div>
        <div class="page-header__actions">
            <button class="btn btn--primary" type="button">Create budget</button>
        </div>
    </div>

    <section class="card">
        <div class="card__header"><h2 class="card__title">Your budgets</h2></div>
        <div class="card__body">
            <div class="empty-state">
                <div class="empty-state__icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h15a3 3 0 0 1 3 3v7a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3z"/><path d="M16 13h.01"/></svg>
                </div>
                <div class="empty-state__title">No budgets yet</div>
                <div class="empty-state__desc">Create your first budget to start tracking spending against a monthly cap.</div>
                <button class="btn btn--primary" type="button">Create budget</button>
            </div>
        </div>
    </section>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
