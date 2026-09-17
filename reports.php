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
        <div>
            <h1 class="page-header__title">Reports</h1>
            <p class="page-header__desc">Generate P&amp;L, cash flow, and category-level reports for any time range.</p>
        </div>
        <div class="page-header__actions">
            <select class="btn btn--ghost btn--sm" aria-label="Range">
                <option>This month</option>
                <option>Last month</option>
                <option>This quarter</option>
                <option>Custom…</option>
            </select>
            <button class="btn btn--primary" type="button">Export PDF</button>
        </div>
    </div>

    <div class="dash-grid--three">
        <section class="card">
            <div class="card__header"><h2 class="card__title">Profit &amp; Loss</h2></div>
            <div class="card__body">
                <p class="text-muted">Summarised income and expense for the selected range.</p>
                <button class="btn btn--ghost mt-4" type="button">Generate report</button>
            </div>
        </section>
        <section class="card">
            <div class="card__header"><h2 class="card__title">Cash flow</h2></div>
            <div class="card__body">
                <p class="text-muted">Inflow, outflow, and net movement across accounts.</p>
                <button class="btn btn--ghost mt-4" type="button">Generate report</button>
            </div>
        </section>
        <section class="card">
            <div class="card__header"><h2 class="card__title">Category breakdown</h2></div>
            <div class="card__body">
                <p class="text-muted">Where every dollar goes, ranked by category.</p>
                <button class="btn btn--ghost mt-4" type="button">Generate report</button>
            </div>
        </section>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
