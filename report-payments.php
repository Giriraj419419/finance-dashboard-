<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';

$page_title = 'Payment summary';
$uid = (int) currentUser()['id'];

try {
    $sums = fetchOne(
        "SELECT
            COALESCE(SUM(amount), 0) AS total,
            COALESCE(SUM(CASE WHEN status = 'paid'      THEN amount ELSE 0 END), 0) AS paid,
            COALESCE(SUM(CASE WHEN status = 'scheduled' THEN amount ELSE 0 END), 0) AS scheduled,
            COALESCE(SUM(CASE WHEN status = 'overdue'   OR (status = 'scheduled' AND due_date < CURDATE()) THEN amount ELSE 0 END), 0) AS overdue,
            COALESCE(SUM(CASE WHEN status = 'cancelled' THEN amount ELSE 0 END), 0) AS cancelled,
            COUNT(*) AS n
         FROM payments WHERE user_id = :uid",
        [':uid' => $uid]
    ) ?? [];
    $by_status = fetchAll(
        'SELECT status, COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total FROM payments WHERE user_id = :uid GROUP BY status ORDER BY total DESC',
        [':uid' => $uid]
    );
    $upcoming = fetchAll(
        "SELECT id, title, amount, due_date, status
         FROM payments
         WHERE user_id = :uid AND status IN ('scheduled', 'overdue')
         ORDER BY due_date ASC LIMIT 20",
        [':uid' => $uid]
    );
} catch (Throwable $e) {
    error_log('[report:payments] ' . $e->getMessage());
    $sums = ['total' => 0, 'paid' => 0, 'scheduled' => 0, 'overdue' => 0, 'cancelled' => 0, 'n' => 0];
    $by_status = []; $upcoming = [];
    flash('danger', 'Could not load the report. Please refresh.');
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span><a href="reports.php">Reports</a></span><span>Payment summary</span></div>
    <div class="page-header"><div><h1 class="page-header__title">Payment summary</h1><p class="page-header__desc">Where your outgoing payments stand.</p></div></div>

    <div class="summary-grid mt-2">
        <div class="summary"><div class="summary__head"><span>Total payments</span></div><div class="summary__value"><?= e(money((float) $sums['total'])) ?></div><div class="summary__delta text-muted"><?= (int) $sums['n'] ?> total</div></div>
        <div class="summary summary--balance"><div class="summary__head"><span>Paid</span></div><div class="summary__value"><?= e(money((float) $sums['paid'])) ?></div></div>
        <div class="summary summary--income"><div class="summary__head"><span>Scheduled</span></div><div class="summary__value"><?= e(money((float) $sums['scheduled'])) ?></div></div>
        <div class="summary summary--expense"><div class="summary__head"><span>Overdue</span></div><div class="summary__value"><?= e(money((float) $sums['overdue'])) ?></div></div>
    </div>

    <div class="dash-grid mt-4">
        <section class="card">
            <div class="card__header"><h2 class="card__title">Status breakdown</h2></div>
            <div class="card__body">
                <?php if (empty($by_status)): ?>
                    <div class="text-muted">No payments yet.</div>
                <?php else: foreach ($by_status as $s):
                    $badge = ['scheduled' => 'info', 'paid' => 'success', 'overdue' => 'danger', 'cancelled' => 'neutral'][$s['status']] ?? 'neutral';
                ?>
                    <div class="item-row">
                        <div class="item-row__grow"><span class="badge badge--<?= e($badge) ?>"><?= e(ucfirst((string) $s['status'])) ?></span>&nbsp; <?= (int) $s['n'] ?> payment(s)</div>
                        <div><?= e(money((float) $s['total'])) ?></div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </section>

        <section class="card">
            <div class="card__header"><h2 class="card__title">Upcoming &amp; overdue</h2></div>
            <div class="card__body">
                <?php if (empty($upcoming)): ?>
                    <div class="text-muted">Nothing scheduled.</div>
                <?php else: foreach ($upcoming as $p):
                    $overdue = $p['status'] === 'overdue' || ($p['status'] === 'scheduled' && strtotime((string) $p['due_date']) < strtotime(date('Y-m-d')));
                ?>
                    <div class="item-row">
                        <div class="item-row__grow">
                            <div class="item-row__title"><?= e($p['title']) ?></div>
                            <div class="item-row__meta">Due <?= e(date('M j, Y', strtotime((string) $p['due_date']))) ?><?= $overdue ? ' · <strong class="text-danger">Overdue</strong>' : '' ?></div>
                        </div>
                        <div class="amount--neg"><?= e('−' . money((float) $p['amount'])) ?></div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </section>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
