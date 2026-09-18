<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';

$page_title = 'Budget performance';
$uid = (int) currentUser()['id'];

try {
    $rows = fetchAll(
        "SELECT b.id, b.name, b.category, b.budget_amount, b.start_date, b.end_date, b.status,
                COALESCE((
                    SELECT SUM(t.amount) FROM transactions t
                    WHERE t.user_id = b.user_id
                      AND t.type = 'expense' AND t.status = 'completed'
                      AND t.category = b.category
                      AND t.transaction_date >= b.start_date
                      AND (b.end_date IS NULL OR t.transaction_date <= b.end_date)
                ), 0) AS spent
         FROM budgets b
         WHERE b.user_id = :uid
         ORDER BY b.start_date DESC, b.id DESC",
        [':uid' => $uid]
    );
} catch (Throwable $e) {
    error_log('[report:budget] ' . $e->getMessage());
    $rows = [];
    flash('danger', 'Could not load the report. Please refresh.');
}

$total_planned = 0.0; $total_spent = 0.0;
foreach ($rows as $r) { $total_planned += (float) $r['budget_amount']; $total_spent += (float) $r['spent']; }

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span><a href="reports.php">Reports</a></span><span>Budget performance</span></div>
    <div class="page-header">
        <div><h1 class="page-header__title">Budget performance</h1><p class="page-header__desc">Planned vs actual, computed live from completed expenses.</p></div>
        <div class="page-header__actions"><a class="btn btn--ghost" href="export-csv.php?report=budgets">Export CSV</a></div>
    </div>

    <div class="summary-grid mt-2">
        <div class="summary"><div class="summary__head"><span>Planned</span></div><div class="summary__value"><?= e(money($total_planned)) ?></div></div>
        <div class="summary summary--expense"><div class="summary__head"><span>Spent</span></div><div class="summary__value"><?= e(money($total_spent)) ?></div></div>
        <div class="summary summary--balance"><div class="summary__head"><span>Remaining</span></div><div class="summary__value"><?= e(money(max(0.0, $total_planned - $total_spent))) ?></div></div>
        <div class="summary"><div class="summary__head"><span>Budgets</span></div><div class="summary__value"><?= (int) count($rows) ?></div></div>
    </div>

    <section class="card mt-4">
        <div class="card__body card__body--flush">
            <?php if (empty($rows)): ?>
                <div class="empty-state"><div class="empty-state__title">No budgets defined yet</div><a class="btn btn--primary mt-2" href="budget-new.php">Create budget</a></div>
            <?php else: ?>
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>Budget</th><th>Category</th><th>Range</th><th class="text-right">Planned</th><th class="text-right">Spent</th><th class="text-right">Remaining</th><th>Utilisation</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($rows as $b):
                            $planned = (float) $b['budget_amount'];
                            $spent = (float) $b['spent'];
                            $remaining = max(0.0, $planned - $spent);
                            $pct = $planned > 0 ? (int) round($spent / $planned * 100) : 0;
                            $capped_pct = min(100, $pct);
                            $over = $spent > $planned;
                            $variant = $over ? 'danger' : ($pct >= 90 ? 'danger' : ($pct >= 70 ? 'warning' : 'success'));
                        ?>
                            <tr>
                                <td><?= e($b['name']) ?></td>
                                <td><span class="badge badge--neutral"><?= e($b['category']) ?></span></td>
                                <td><?= e(date('M j', strtotime((string) $b['start_date']))) ?><?= $b['end_date'] ? ' – ' . e(date('M j, Y', strtotime((string) $b['end_date']))) : '' ?></td>
                                <td class="text-right"><?= e(money($planned)) ?></td>
                                <td class="text-right amount--neg"><?= e(money($spent)) ?></td>
                                <td class="text-right"><?= e(money($remaining)) ?></td>
                                <td class="col-progress">
                                    <div class="progress"><div class="progress__bar progress__bar--<?= e($variant) ?>" data-percent="<?= (int) $capped_pct ?>"></div></div>
                                    <div class="item-row__meta"><?= (int) $pct ?>%</div>
                                </td>
                                <td>
                                    <?php if ($over): ?><span class="badge badge--danger">Over budget</span><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </div>
    </section>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
