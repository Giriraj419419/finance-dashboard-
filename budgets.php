<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/csrf.php';

$page_title = 'Budgets';
$me = currentUser();
$uid = (int) $me['id'];

$page     = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 20;
$offset   = ($page - 1) * $per_page;

try {
    $total = (int) (fetchOne(
        'SELECT COUNT(*) AS c FROM budgets WHERE user_id = :uid',
        [':uid' => $uid]
    )['c'] ?? 0);
    $total_pages = max(1, (int) ceil($total / $per_page));

    // Compute spent from live transactions for each budget, per category + range.
    // Kept in one round trip via a correlated subquery.
    $rows = executeQuery(
        "SELECT b.id, b.name, b.category, b.budget_amount, b.start_date, b.end_date, b.status,
                COALESCE((
                    SELECT SUM(t.amount) FROM transactions t
                    WHERE t.user_id = b.user_id
                      AND t.type = 'expense'
                      AND t.status = 'completed'
                      AND t.category = b.category
                      AND t.transaction_date >= b.start_date
                      AND (b.end_date IS NULL OR t.transaction_date <= b.end_date)
                ), 0) AS live_spent
         FROM budgets b
         WHERE b.user_id = :uid
         ORDER BY b.start_date DESC, b.id DESC
         LIMIT $per_page OFFSET $offset",
        [':uid' => $uid]
    )->fetchAll();
} catch (Throwable $e) {
    error_log('[budgets:list] ' . $e->getMessage());
    $rows = [];
    $total = 0;
    $total_pages = 1;
    flash('danger', 'Could not load budgets. Please refresh.');
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span>Budgets</span></div>
    <div class="page-header">
        <div>
            <h1 class="page-header__title">Budgets</h1>
            <p class="page-header__desc">Set monthly caps per category and monitor spend against your completed expenses.</p>
        </div>
        <div class="page-header__actions">
            <a class="btn btn--primary" href="budget-new.php">Create budget</a>
        </div>
    </div>

    <section class="card">
        <div class="card__header"><h2 class="card__title">Your budgets <span class="text-soft">(<?= (int) $total ?>)</span></h2></div>
        <div class="card__body card__body--flush">
            <?php if (empty($rows)): ?>
                <div class="empty-state">
                    <div class="empty-state__icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h15a3 3 0 0 1 3 3v7a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3z"/><path d="M16 13h.01"/></svg>
                    </div>
                    <div class="empty-state__title">No budgets yet</div>
                    <div class="empty-state__desc">Create your first budget to start tracking spend against a cap.</div>
                    <a class="btn btn--primary" href="budget-new.php">Create budget</a>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr><th>Name</th><th>Category</th><th>Range</th><th>Planned</th><th>Spent</th><th>Remaining</th><th>Progress</th><th>Status</th><th></th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $b):
                                $planned = (float) $b['budget_amount'];
                                $spent   = (float) $b['live_spent'];
                                $remaining = max(0.0, $planned - $spent);
                                $pct = $planned > 0 ? min(100, (int) round($spent / $planned * 100)) : 0;
                                $variant = $pct >= 90 ? 'danger' : ($pct >= 70 ? 'warning' : 'success');
                                $badge   = ['active' => 'success', 'paused' => 'warning', 'completed' => 'info', 'archived' => 'neutral'][$b['status']] ?? 'neutral';
                            ?>
                                <tr>
                                    <td><?= e($b['name']) ?></td>
                                    <td><span class="badge badge--neutral"><?= e($b['category']) ?></span></td>
                                    <td><?= e(date('M j', strtotime((string) $b['start_date']))) ?><?= $b['end_date'] ? ' – ' . e(date('M j, Y', strtotime((string) $b['end_date']))) : '' ?></td>
                                    <td class="text-right"><?= e(money($planned)) ?></td>
                                    <td class="text-right amount--neg"><?= e(money($spent)) ?></td>
                                    <td class="text-right"><?= e(money($remaining)) ?></td>
                                    <td class="col-progress">
                                        <div class="progress"><div class="progress__bar progress__bar--<?= e($variant) ?>" data-percent="<?= (int) $pct ?>"></div></div>
                                        <div class="item-row__meta"><?= (int) $pct ?>%</div>
                                    </td>
                                    <td><span class="badge badge--<?= e($badge) ?>"><?= e(ucfirst((string) $b['status'])) ?></span></td>
                                    <td class="text-right">
                                        <a class="btn btn--ghost btn--sm" href="budget-edit.php?id=<?= (int) $b['id'] ?>">Edit</a>
                                        <form method="POST" action="budget-delete.php" class="inline-form" onsubmit="return confirm('Delete this budget?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                                            <button type="submit" class="btn btn--sm btn--danger">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a class="btn btn--ghost btn--sm" href="?page=<?= (int) ($page - 1) ?>">← Previous</a>
                        <?php endif; ?>
                        <span class="text-muted">Page <?= (int) $page ?> of <?= (int) $total_pages ?></span>
                        <?php if ($page < $total_pages): ?>
                            <a class="btn btn--ghost btn--sm" href="?page=<?= (int) ($page + 1) ?>">Next →</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
