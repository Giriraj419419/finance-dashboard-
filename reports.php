<?php
require_once __DIR__ . '/includes/auth-check.php';
$page_title = 'Reports';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span>Reports</span></div>
    <div class="page-header">
        <div><h1 class="page-header__title">Reports</h1><p class="page-header__desc">Live summaries built from your ledger — scoped to your account.</p></div>
    </div>

    <div class="dash-grid--three">
        <a class="card report-card" href="report-income-expense.php">
            <div class="card__body">
                <h2 class="card__title">Income &amp; Expense</h2>
                <p class="text-muted">Totals, net balance, and category breakdown for a date range.</p>
                <div class="btn btn--ghost mt-2">Open →</div>
            </div>
        </a>
        <a class="card report-card" href="report-transactions.php">
            <div class="card__body">
                <h2 class="card__title">Transactions</h2>
                <p class="text-muted">Filter, search, and total by type / category / status.</p>
                <div class="btn btn--ghost mt-2">Open →</div>
            </div>
        </a>
        <a class="card report-card" href="report-budgets.php">
            <div class="card__body">
                <h2 class="card__title">Budget Performance</h2>
                <p class="text-muted">Planned vs actual, utilisation, over-budget flags.</p>
                <div class="btn btn--ghost mt-2">Open →</div>
            </div>
        </a>
        <a class="card report-card" href="report-goals.php">
            <div class="card__body">
                <h2 class="card__title">Goal Progress</h2>
                <p class="text-muted">Every goal's target, saved, remaining, and progress.</p>
                <div class="btn btn--ghost mt-2">Open →</div>
            </div>
        </a>
        <a class="card report-card" href="report-payments.php">
            <div class="card__body">
                <h2 class="card__title">Payment Summary</h2>
                <p class="text-muted">Paid vs pending vs overdue, with a status breakdown.</p>
                <div class="btn btn--ghost mt-2">Open →</div>
            </div>
        </a>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
