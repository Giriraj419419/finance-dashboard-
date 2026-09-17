<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';

$page_title = 'Overview';
$me = currentUser();
$uid = (int) $me['id'];

// -----------------------------------------------------------------------
// Summary — scope to the current user. Admins/Managers see their own
// records here too; global aggregate views are reserved for the reports
// module in a later phase.
// -----------------------------------------------------------------------
try {
    $totals = fetchOne(
        "SELECT
            COALESCE(SUM(CASE WHEN type = 'income'  AND status = 'completed' THEN amount ELSE 0 END), 0) AS total_income,
            COALESCE(SUM(CASE WHEN type = 'expense' AND status = 'completed' THEN amount ELSE 0 END), 0) AS total_expense
         FROM transactions
         WHERE user_id = :uid",
        [':uid' => $uid]
    ) ?? ['total_income' => 0, 'total_expense' => 0];

    $recent = fetchAll(
        'SELECT id, transaction_date, description, category, payment_method, amount, type, status
         FROM transactions
         WHERE user_id = :uid
         ORDER BY transaction_date DESC, id DESC
         LIMIT 5',
        [':uid' => $uid]
    );

    $budgets = fetchAll(
        "SELECT id, name, budget_amount, spent_amount
         FROM budgets
         WHERE user_id = :uid AND status = 'active'
         ORDER BY updated_at DESC
         LIMIT 4",
        [':uid' => $uid]
    );

    $goals = fetchAll(
        "SELECT id, name, target_amount, current_amount
         FROM goals
         WHERE user_id = :uid AND status = 'active'
         ORDER BY updated_at DESC
         LIMIT 3",
        [':uid' => $uid]
    );

    $upcoming_payments = fetchAll(
        "SELECT id, title, amount, due_date
         FROM payments
         WHERE user_id = :uid AND status = 'scheduled' AND due_date >= CURDATE()
         ORDER BY due_date ASC
         LIMIT 5",
        [':uid' => $uid]
    );

    $reminders = fetchAll(
        "SELECT id, title, reminder_date
         FROM reminders
         WHERE user_id = :uid AND status = 'pending' AND reminder_date >= NOW()
         ORDER BY reminder_date ASC
         LIMIT 5",
        [':uid' => $uid]
    );
} catch (Throwable $e) {
    error_log('[dashboard] ' . $e->getMessage());
    $totals = ['total_income' => 0, 'total_expense' => 0];
    $recent = $budgets = $goals = $upcoming_payments = $reminders = [];
    flash('danger', 'Could not load your dashboard right now. Please refresh.');
}

$income  = (float) $totals['total_income'];
$expense = (float) $totals['total_expense'];
$balance = $income - $expense;
// Savings is a naive placeholder computed from income minus expense floor.
$savings = max(0.0, $income - $expense);

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>

<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span>Overview</span></div>
    <div class="page-header">
        <div>
            <h1 class="page-header__title">Welcome back, <?= e(explode(' ', (string) $me['name'])[0] ?? '') ?></h1>
            <p class="page-header__desc">Here's a live snapshot of your finances.</p>
        </div>
        <div class="page-header__actions">
            <a class="btn btn--ghost" href="reports.php">Export report</a>
            <a class="btn btn--primary" href="transaction-new.php">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                New transaction
            </a>
        </div>
    </div>

    <!-- Summary cards -->
    <div class="summary-grid">
        <div class="summary summary--balance">
            <div class="summary__head"><span>Total Balance</span>
                <span class="summary__icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h15a3 3 0 0 1 3 3v7a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3z"/><path d="M16 13h.01"/></svg></span>
            </div>
            <div class="summary__value"><?= e(money($balance)) ?></div>
            <div class="summary__delta text-muted">Income minus completed expenses</div>
        </div>
        <div class="summary summary--income">
            <div class="summary__head"><span>Income</span>
                <span class="summary__icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17l10-10M7 7h10v10"/></svg></span>
            </div>
            <div class="summary__value"><?= e(money($income)) ?></div>
            <div class="summary__delta text-muted">All completed income</div>
        </div>
        <div class="summary summary--expense">
            <div class="summary__head"><span>Expenses</span>
                <span class="summary__icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 7L7 17M7 7v10h10"/></svg></span>
            </div>
            <div class="summary__value"><?= e(money($expense)) ?></div>
            <div class="summary__delta text-muted">All completed expenses</div>
        </div>
        <div class="summary summary--savings">
            <div class="summary__head"><span>Savings</span>
                <span class="summary__icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9"/><path d="M12 7v5l3 2"/></svg></span>
            </div>
            <div class="summary__value"><?= e(money($savings)) ?></div>
            <div class="summary__delta text-muted">Simple heuristic — Reports phase adds real modelling</div>
        </div>
    </div>

    <!-- Recent transactions -->
    <section class="card mt-2">
        <div class="card__header">
            <h2 class="card__title">Recent transactions</h2>
            <a class="btn btn--ghost btn--sm" href="transactions.php">View all</a>
        </div>
        <div class="card__body card__body--flush">
            <?php if (empty($recent)): ?>
                <div class="empty-state">
                    <div class="empty-state__icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M7 7h13M7 7l4-4M7 7l4 4M17 17H4M17 17l-4 4M17 17l-4-4"/></svg></div>
                    <div class="empty-state__title">No transactions yet</div>
                    <div class="empty-state__desc">Record your first transaction to see activity here.</div>
                    <a class="btn btn--primary" href="transaction-new.php">Add transaction</a>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr><th>Date</th><th>Description</th><th>Category</th><th>Method</th><th class="text-right">Amount</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent as $t):
                                $signed = ($t['type'] === 'income' ? '+' : '−') . money((float) $t['amount']);
                                $amount_class = $t['type'] === 'income' ? 'amount--pos' : 'amount--neg';
                                $status_variant = ['completed' => 'success', 'pending' => 'warning', 'failed' => 'danger', 'cancelled' => 'neutral'][$t['status']] ?? 'neutral';
                            ?>
                                <tr>
                                    <td><?= e(date('M j, Y', strtotime((string) $t['transaction_date']))) ?></td>
                                    <td><?= e($t['description'] ?? '') ?></td>
                                    <td><span class="badge badge--neutral"><?= e($t['category']) ?></span></td>
                                    <td><?= e(str_replace('_', ' ', (string) $t['payment_method'])) ?></td>
                                    <td class="text-right <?= e($amount_class) ?>"><?= e($signed) ?></td>
                                    <td><span class="badge badge--<?= e($status_variant) ?>"><?= e(ucfirst((string) $t['status'])) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Budgets · Goals · Upcoming payments -->
    <div class="dash-grid--three mt-6">
        <section class="card">
            <div class="card__header"><h2 class="card__title">Budget progress</h2><a href="budgets.php" class="btn btn--ghost btn--sm">Manage</a></div>
            <div class="card__body">
                <?php if (empty($budgets)): ?>
                    <div class="empty-state">
                        <div class="empty-state__title">No active budgets</div>
                        <div class="empty-state__desc">Create a budget to track spend against a cap.</div>
                        <a class="btn btn--primary" href="budgets.php">Create budget</a>
                    </div>
                <?php else: foreach ($budgets as $b):
                    $limit = max(1.0, (float) $b['budget_amount']);
                    $pct = min(100, (int) round(((float) $b['spent_amount'] / $limit) * 100));
                    $variant = $pct >= 90 ? 'danger' : ($pct >= 70 ? 'warning' : 'success');
                ?>
                    <div class="item-row">
                        <div class="item-row__grow">
                            <div class="flex-between">
                                <div class="item-row__title"><?= e($b['name']) ?></div>
                                <div class="item-row__meta"><?= e(money((float) $b['spent_amount'])) ?> / <?= e(money((float) $b['budget_amount'])) ?></div>
                            </div>
                            <div class="progress mt-2"><div class="progress__bar progress__bar--<?= e($variant) ?>" data-percent="<?= (int) $pct ?>"></div></div>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </section>

        <section class="card">
            <div class="card__header"><h2 class="card__title">Goals</h2><a href="goals.php" class="btn btn--ghost btn--sm">All goals</a></div>
            <div class="card__body">
                <?php if (empty($goals)): ?>
                    <div class="empty-state">
                        <div class="empty-state__title">No goals yet</div>
                        <div class="empty-state__desc">Set a savings target to see progress here.</div>
                        <a class="btn btn--primary" href="goals.php">Add a goal</a>
                    </div>
                <?php else: foreach ($goals as $g):
                    $target = max(1.0, (float) $g['target_amount']);
                    $pct = min(100, (int) round(((float) $g['current_amount'] / $target) * 100));
                ?>
                    <div class="item-row">
                        <div class="item-row__grow">
                            <div class="flex-between">
                                <div class="item-row__title"><?= e($g['name']) ?></div>
                                <div class="item-row__meta"><?= (int) $pct ?>%</div>
                            </div>
                            <div class="item-row__meta"><?= e(money((float) $g['current_amount'])) ?> saved of <?= e(money((float) $g['target_amount'])) ?></div>
                            <div class="progress mt-2"><div class="progress__bar" data-percent="<?= (int) $pct ?>"></div></div>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </section>

        <section class="card">
            <div class="card__header"><h2 class="card__title">Upcoming payments</h2><a href="payments.php" class="btn btn--ghost btn--sm">Schedule</a></div>
            <div class="card__body">
                <?php if (empty($upcoming_payments)): ?>
                    <div class="empty-state">
                        <div class="empty-state__title">Nothing due</div>
                        <div class="empty-state__desc">You have no scheduled payments coming up.</div>
                    </div>
                <?php else: foreach ($upcoming_payments as $p): ?>
                    <div class="item-row">
                        <div class="item-row__grow">
                            <div class="item-row__title"><?= e($p['title']) ?></div>
                            <div class="item-row__meta">Due <?= e(date('M j, Y', strtotime((string) $p['due_date']))) ?></div>
                        </div>
                        <div class="amount--neg"><?= e('−' . money((float) $p['amount'])) ?></div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </section>
    </div>

    <!-- Reminders -->
    <section class="card mt-6">
        <div class="card__header"><h2 class="card__title">Reminders</h2><a href="reminders.php" class="btn btn--ghost btn--sm">All reminders</a></div>
        <div class="card__body">
            <?php if (empty($reminders)): ?>
                <div class="empty-state">
                    <div class="empty-state__icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 1 1 12 0c0 5 2 6 2 8H4c0-2 2-3 2-8z"/></svg></div>
                    <div class="empty-state__title">Nothing on the radar</div>
                    <div class="empty-state__desc">You have no reminders scheduled. Add one to stay ahead of deadlines.</div>
                    <a class="btn btn--primary" href="reminders.php">Add reminder</a>
                </div>
            <?php else: foreach ($reminders as $r):
                $when = strtotime((string) $r['reminder_date']);
                $days = (int) floor(($when - time()) / 86400);
                $when_label = $days <= 0 ? 'Today' : ($days === 1 ? 'Tomorrow' : ('In ' . $days . ' days'));
                $variant = $days <= 1 ? 'warning' : ($days <= 3 ? 'info' : 'neutral');
            ?>
                <div class="item-row">
                    <div class="item-row__grow">
                        <div class="item-row__title"><?= e($r['title']) ?></div>
                        <div class="item-row__meta"><?= e($when_label) ?></div>
                    </div>
                    <span class="badge badge--<?= e($variant) ?>">Due soon</span>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </section>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
