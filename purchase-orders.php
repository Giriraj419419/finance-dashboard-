<?php
require_once __DIR__ . '/includes/auth-check.php';
$page_title = 'Purchase Orders';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span>Purchase Orders</span></div>
    <div class="page-header">
        <div>
            <h1 class="page-header__title">Purchase orders</h1>
            <p class="page-header__desc">Raise, approve, and track vendor purchase orders. <span class="badge badge--info">Admin &amp; Manager</span></p>
        </div>
        <div class="page-header__actions">
            <button class="btn btn--ghost" type="button">Export</button>
            <button class="btn btn--primary" type="button">New PO</button>
        </div>
    </div>

    <section class="card">
        <div class="card__header"><h2 class="card__title">Open purchase orders</h2></div>
        <div class="card__body card__body--flush">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>PO #</th><th>Vendor</th><th>Issued</th><th>Expected</th><th class="text-right">Total</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <div class="empty-state__icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h9l5 5v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/><path d="M14 3v6h6"/></svg></div>
                                    <div class="empty-state__title">No purchase orders</div>
                                    <div class="empty-state__desc">Raise a PO to start tracking approvals, deliveries, and invoicing.</div>
                                    <button class="btn btn--primary" type="button">Create purchase order</button>
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
