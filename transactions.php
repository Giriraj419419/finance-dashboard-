<?php
require_once __DIR__ . '/includes/auth-check.php';
$page_title = 'Transactions';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span>Transactions</span></div>
    <div class="page-header">
        <div>
            <h1 class="page-header__title">Transactions</h1>
            <p class="page-header__desc">All income and expense activity across your accounts.</p>
        </div>
        <div class="page-header__actions">
            <button class="btn btn--ghost" type="button">Import CSV</button>
            <button class="btn btn--primary" type="button">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                Add transaction
            </button>
        </div>
    </div>

    <section class="card">
        <div class="card__header">
            <h2 class="card__title">All transactions</h2>
            <div class="flex gap-2">
                <select class="btn btn--ghost btn--sm" aria-label="Filter by category">
                    <option>All categories</option>
                    <option>Income</option>
                    <option>Rent</option>
                    <option>Software</option>
                    <option>Meals</option>
                </select>
                <select class="btn btn--ghost btn--sm" aria-label="Filter by range">
                    <option>Last 30 days</option>
                    <option>This month</option>
                    <option>This quarter</option>
                    <option>This year</option>
                </select>
            </div>
        </div>
        <div class="card__body card__body--flush">
            <div class="empty-state">
                <div class="empty-state__icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M7 7h13M7 7l4-4M7 7l4 4M17 17H4M17 17l-4 4M17 17l-4-4"/></svg>
                </div>
                <div class="empty-state__title">No transactions to show</div>
                <div class="empty-state__desc">Once you record a transaction or import a CSV, it will appear here.</div>
                <button class="btn btn--primary" type="button">Add your first transaction</button>
            </div>
        </div>
    </section>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
