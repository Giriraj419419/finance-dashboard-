<?php
require_once __DIR__ . '/includes/auth-check.php';
$page_title = 'Payments';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span>Payments</span></div>
    <div class="page-header">
        <div>
            <h1 class="page-header__title">Payments</h1>
            <p class="page-header__desc">Schedule outgoing payments and review upcoming due dates.</p>
        </div>
        <div class="page-header__actions">
            <button class="btn btn--primary" type="button">Schedule payment</button>
        </div>
    </div>

    <section class="card">
        <div class="card__header"><h2 class="card__title">Upcoming payments</h2></div>
        <div class="card__body card__body--flush">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>Recipient</th><th>Category</th><th>Due date</th><th class="text-right">Amount</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <div class="empty-state__icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="5.5" width="19" height="13" rx="2"/><path d="M2.5 10h19"/></svg></div>
                                    <div class="empty-state__title">No scheduled payments</div>
                                    <div class="empty-state__desc">Schedule a payment to see it here with its due date and status.</div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
