<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';

$page_title = 'Income & Expense report';
$uid = (int) currentUser()['id'];

$from = trim((string) ($_GET['from'] ?? date('Y-m-01', strtotime('-2 months'))));
$to   = trim((string) ($_GET['to']   ?? date('Y-m-t')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = date('Y-m-01'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $to   = date('Y-m-t'); }

try {
    $totals = fetchOne(
        "SELECT
            COALESCE(SUM(CASE WHEN type='income'  AND status='completed' THEN amount ELSE 0 END), 0) AS income,
            COALESCE(SUM(CASE WHEN type='expense' AND status='completed' THEN amount ELSE 0 END), 0) AS expense
         FROM transactions WHERE user_id = :uid AND transaction_date BETWEEN :from AND :to",
        [':uid' => $uid, ':from' => $from, ':to' => $to]
    ) ?? ['income' => 0, 'expense' => 0];
    $breakdown = fetchAll(
        "SELECT type, category, SUM(amount) AS total, COUNT(*) AS n
         FROM transactions
         WHERE user_id = :uid AND status = 'completed' AND transaction_date BETWEEN :from AND :to
         GROUP BY type, category
         ORDER BY type ASC, total DESC",
        [':uid' => $uid, ':from' => $from, ':to' => $to]
    );
} catch (Throwable $e) {
    error_log('[report:ie] ' . $e->getMessage());
    $totals = ['income' => 0, 'expense' => 0];
    $breakdown = [];
    flash('danger', 'Could not load the report. Please refresh.');
}

$income = (float) $totals['income'];
$expense = (float) $totals['expense'];
$net = $income - $expense;

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span><a href="reports.php">Reports</a></span><span>Income &amp; Expense</span></div>
    <div class="page-header">
        <div><h1 class="page-header__title">Income &amp; Expense</h1><p class="page-header__desc">Totals and category split for a date range.</p></div>
    </div>

    <section class="card"><div class="card__body">
        <form method="GET" action="report-income-expense.php" class="filters">
            <div class="field"><label for="f_from">From</label><input id="f_from" name="from" type="date" required value="<?= e($from) ?>"></div>
            <div class="field"><label for="f_to">To</label><input id="f_to" name="to" type="date" required value="<?= e($to) ?>"></div>
            <div class="field"><span>&nbsp;</span></div>
            <div class="field"><span>&nbsp;</span></div>
            <div class="field"><span>&nbsp;</span></div>
            <div class="filters__actions"><button type="submit" class="btn btn--primary">Apply</button></div>
        </form>
    </div></section>

    <div class="summary-grid mt-4">
        <div class="summary summary--income"><div class="summary__head"><span>Income</span></div><div class="summary__value"><?= e(money($income)) ?></div></div>
        <div class="summary summary--expense"><div class="summary__head"><span>Expenses</span></div><div class="summary__value"><?= e(money($expense)) ?></div></div>
        <div class="summary summary--balance"><div class="summary__head"><span>Net balance</span></div><div class="summary__value"><?= e(money($net)) ?></div></div>
        <div class="summary"><div class="summary__head"><span>Range</span></div><div class="summary__value summary__value--lg"><?= e(date('M j', strtotime($from))) ?> – <?= e(date('M j, Y', strtotime($to))) ?></div></div>
    </div>

    <section class="card mt-4">
        <div class="card__header"><h2 class="card__title">Category breakdown</h2></div>
        <div class="card__body card__body--flush">
            <?php if (empty($breakdown)): ?>
                <div class="empty-state"><div class="empty-state__title">No completed transactions in this range</div></div>
            <?php else: ?>
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>Type</th><th>Category</th><th class="text-right">Count</th><th class="text-right">Total</th></tr></thead>
                    <tbody>
                        <?php foreach ($breakdown as $row):
                            $cls = $row['type'] === 'income' ? 'amount--pos' : 'amount--neg';
                        ?>
                            <tr>
                                <td><?= e(ucfirst((string) $row['type'])) ?></td>
                                <td><span class="badge badge--neutral"><?= e($row['category']) ?></span></td>
                                <td class="text-right"><?= (int) $row['n'] ?></td>
                                <td class="text-right <?= e($cls) ?>"><?= e(money((float) $row['total'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </div>
    </section>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
