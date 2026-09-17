<?php
require_once __DIR__ . '/includes/auth-check.php';
$page_title = 'Overview';

/**
 * DEMO DATA — placeholder only, not sourced from MySQL.
 * All real reads/writes land in the CRUD phase.
 */
$summary = [
    ['label' => 'Total Balance', 'value' => 42580.75, 'delta' => '+3.2% vs last month', 'dir' => 'up',   'variant' => 'balance'],
    ['label' => 'Income',        'value' => 12840.00, 'delta' => '+8.4% vs last month', 'dir' => 'up',   'variant' => 'income'],
    ['label' => 'Expenses',      'value' => 7420.20,  'delta' => '-1.6% vs last month', 'dir' => 'up',   'variant' => 'expense'],
    ['label' => 'Savings',       'value' => 5419.80,  'delta' => '+12% vs last month',  'dir' => 'up',   'variant' => 'savings'],
];

$transactions = [
    ['date' => '2026-09-15', 'name' => 'Salary — September',       'category' => 'Income',   'method' => 'Bank transfer', 'amount' =>  4500.00, 'status' => 'success'],
    ['date' => '2026-09-14', 'name' => 'Office rent',              'category' => 'Rent',     'method' => 'ACH',           'amount' => -1800.00, 'status' => 'success'],
    ['date' => '2026-09-13', 'name' => 'Cloud hosting',            'category' => 'Software', 'method' => 'Card',          'amount' =>  -140.50, 'status' => 'pending'],
    ['date' => '2026-09-12', 'name' => 'Consulting invoice #4021', 'category' => 'Revenue',  'method' => 'Wire',          'amount' =>  1250.00, 'status' => 'success'],
    ['date' => '2026-09-10', 'name' => 'Team lunch',               'category' => 'Meals',    'method' => 'Card',          'amount' =>  -186.20, 'status' => 'failed'],
];

$budgets = [
    ['name' => 'Marketing',  'spent' => 2400, 'limit' => 4000, 'variant' => 'success'],
    ['name' => 'Software',   'spent' => 1150, 'limit' => 1500, 'variant' => 'warning'],
    ['name' => 'Travel',     'spent' =>  780, 'limit' =>  800, 'variant' => 'danger'],
    ['name' => 'Office',     'spent' =>  320, 'limit' => 1000, 'variant' => 'success'],
];

$goals = [
    ['name' => 'Emergency fund',   'saved' => 8200,  'target' => 15000],
    ['name' => 'New workstation',  'saved' => 1450,  'target' =>  3000],
    ['name' => 'Retirement 2050',  'saved' => 24500, 'target' => 40000],
];

$payments = [
    ['name' => 'Adobe Creative Cloud', 'due' => 'Sep 22, 2026', 'amount' => 59.99],
    ['name' => 'AWS invoice',          'due' => 'Sep 25, 2026', 'amount' => 312.40],
    ['name' => 'Insurance premium',    'due' => 'Sep 30, 2026', 'amount' => 210.00],
];

$reminders = [
    ['title' => 'File quarterly VAT return', 'when' => 'Tomorrow',  'variant' => 'warning'],
    ['title' => 'Review payroll for October','when' => 'In 3 days', 'variant' => 'info'],
    ['title' => 'Renew accounting license',  'when' => 'Sep 28',    'variant' => 'danger'],
];

$chart_series = '3800,4200,3900,4600,5100,4800,5400,5900,5300,6100,6400,6800';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>

<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span>Overview</span></div>
    <div class="page-header">
        <div>
            <h1 class="page-header__title">Welcome back<?= is_logged_in() ? ', ' . e(explode(' ', current_user()['name'])[0]) : '' ?></h1>
            <p class="page-header__desc">Here's a snapshot of your finances for September 2026. <span class="badge badge--neutral">Placeholder data</span></p>
        </div>
        <div class="page-header__actions">
            <a class="btn btn--ghost" href="reports.php">Export report</a>
            <a class="btn btn--primary" href="transactions.php">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                New transaction
            </a>
        </div>
    </div>

    <!-- Summary cards -->
    <div class="summary-grid">
        <?php foreach ($summary as $s): ?>
            <div class="summary summary--<?= e($s['variant']) ?>">
                <div class="summary__head">
                    <span><?= e($s['label']) ?></span>
                    <span class="summary__icon" aria-hidden="true">
                        <?php if ($s['variant'] === 'income'): ?>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17l10-10M7 7h10v10"/></svg>
                        <?php elseif ($s['variant'] === 'expense'): ?>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 7L7 17M7 7v10h10"/></svg>
                        <?php elseif ($s['variant'] === 'savings'): ?>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9"/><path d="M12 7v5l3 2"/></svg>
                        <?php else: ?>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h15a3 3 0 0 1 3 3v7a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3z"/><path d="M16 13h.01"/></svg>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="summary__value"><?= e(money($s['value'])) ?></div>
                <div class="summary__delta summary__delta--<?= $s['dir'] === 'up' ? 'up' : 'down' ?>"><?= e($s['delta']) ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Chart + Quick actions -->
    <div class="dash-grid">
        <section class="card chart-card">
            <div class="card__header">
                <h2 class="card__title">Cash flow (last 12 months)</h2>
                <span class="badge badge--neutral">Demo</span>
            </div>
            <div class="card__body">
                <div class="chart" data-chart data-values="<?= e($chart_series) ?>" aria-label="Cash flow line chart"></div>
            </div>
        </section>
        <section class="card">
            <div class="card__header"><h2 class="card__title">Quick actions</h2></div>
            <div class="card__body">
                <div class="quick-actions">
                    <a class="quick-action" href="transactions.php">
                        <span class="quick-action__icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg></span>
                        Add transaction
                    </a>
                    <a class="quick-action" href="budgets.php">
                        <span class="quick-action__icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h15a3 3 0 0 1 3 3v7a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3z"/></svg></span>
                        Create budget
                    </a>
                    <a class="quick-action" href="goals.php">
                        <span class="quick-action__icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/></svg></span>
                        New goal
                    </a>
                    <a class="quick-action" href="reports.php">
                        <span class="quick-action__icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg></span>
                        View reports
                    </a>
                </div>
            </div>
        </section>
    </div>

    <!-- Recent transactions -->
    <section class="card mt-2">
        <div class="card__header">
            <h2 class="card__title">Recent transactions</h2>
            <a class="btn btn--ghost btn--sm" href="transactions.php">View all</a>
        </div>
        <div class="card__body card__body--flush">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Category</th>
                            <th>Method</th>
                            <th class="text-right">Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $t):
                            $status_variant = ['success' => 'success', 'pending' => 'warning', 'failed' => 'danger'][$t['status']] ?? 'neutral';
                            $amount_class = $t['amount'] >= 0 ? 'amount--pos' : 'amount--neg';
                        ?>
                            <tr>
                                <td><?= e(date('M j, Y', strtotime($t['date']))) ?></td>
                                <td><?= e($t['name']) ?></td>
                                <td><span class="badge badge--neutral"><?= e($t['category']) ?></span></td>
                                <td><?= e($t['method']) ?></td>
                                <td class="text-right <?= e($amount_class) ?>"><?= e(($t['amount'] >= 0 ? '+' : '−') . money(abs($t['amount']))) ?></td>
                                <td><span class="badge badge--<?= e($status_variant) ?>"><?= e(ucfirst($t['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- Budgets · Goals · Upcoming payments -->
    <div class="dash-grid--three mt-6">
        <section class="card">
            <div class="card__header">
                <h2 class="card__title">Budget progress</h2>
                <a href="budgets.php" class="btn btn--ghost btn--sm">Manage</a>
            </div>
            <div class="card__body">
                <?php foreach ($budgets as $b):
                    $pct = min(100, (int) round(($b['spent'] / max(1, $b['limit'])) * 100));
                ?>
                    <div class="item-row">
                        <div class="item-row__grow">
                            <div class="flex-between">
                                <div class="item-row__title"><?= e($b['name']) ?></div>
                                <div class="item-row__meta"><?= e(money($b['spent'])) ?> / <?= e(money($b['limit'])) ?></div>
                            </div>
                            <div class="progress mt-2" aria-label="Budget usage">
                                <div class="progress__bar progress__bar--<?= e($b['variant']) ?>" data-percent="<?= (int) $pct ?>"></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="card">
            <div class="card__header">
                <h2 class="card__title">Goals</h2>
                <a href="goals.php" class="btn btn--ghost btn--sm">All goals</a>
            </div>
            <div class="card__body">
                <?php foreach ($goals as $g):
                    $pct = min(100, (int) round(($g['saved'] / max(1, $g['target'])) * 100));
                ?>
                    <div class="item-row">
                        <div class="item-row__grow">
                            <div class="flex-between">
                                <div class="item-row__title"><?= e($g['name']) ?></div>
                                <div class="item-row__meta"><?= (int) $pct ?>%</div>
                            </div>
                            <div class="item-row__meta"><?= e(money($g['saved'])) ?> saved of <?= e(money($g['target'])) ?></div>
                            <div class="progress mt-2">
                                <div class="progress__bar" data-percent="<?= (int) $pct ?>"></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="card">
            <div class="card__header">
                <h2 class="card__title">Upcoming payments</h2>
                <a href="payments.php" class="btn btn--ghost btn--sm">Schedule</a>
            </div>
            <div class="card__body">
                <?php foreach ($payments as $p): ?>
                    <div class="item-row">
                        <div class="item-row__grow">
                            <div class="item-row__title"><?= e($p['name']) ?></div>
                            <div class="item-row__meta">Due <?= e($p['due']) ?></div>
                        </div>
                        <div class="amount--neg"><?= e('−' . money($p['amount'])) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>

    <!-- Reminders -->
    <section class="card mt-6">
        <div class="card__header">
            <h2 class="card__title">Reminders</h2>
            <a href="reminders.php" class="btn btn--ghost btn--sm">All reminders</a>
        </div>
        <div class="card__body">
            <?php if (empty($reminders)): ?>
                <div class="empty-state">
                    <div class="empty-state__icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 1 1 12 0c0 5 2 6 2 8H4c0-2 2-3 2-8z"/></svg>
                    </div>
                    <div class="empty-state__title">Nothing on the radar</div>
                    <div class="empty-state__desc">You have no reminders scheduled. Add one to stay ahead of deadlines.</div>
                    <a class="btn btn--primary" href="reminders.php">Add reminder</a>
                </div>
            <?php else: ?>
                <?php foreach ($reminders as $r): ?>
                    <div class="item-row">
                        <div class="item-row__grow">
                            <div class="item-row__title"><?= e($r['title']) ?></div>
                            <div class="item-row__meta"><?= e($r['when']) ?></div>
                        </div>
                        <span class="badge badge--<?= e($r['variant']) ?>">Due soon</span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
