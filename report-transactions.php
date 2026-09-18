<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';

$page_title = 'Transactions report';
$uid = (int) currentUser()['id'];

$type   = in_array($_GET['type']   ?? '', ['income', 'expense'], true) ? $_GET['type'] : '';
$status = in_array($_GET['status'] ?? '', ['pending','completed','failed','cancelled'], true) ? $_GET['status'] : '';
$category = trim((string) ($_GET['category'] ?? ''));
$q      = trim((string) ($_GET['q'] ?? ''));
$from   = trim((string) ($_GET['from'] ?? date('Y-m-01', strtotime('-2 months'))));
$to     = trim((string) ($_GET['to']   ?? date('Y-m-t')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = date('Y-m-01'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $to   = date('Y-m-t'); }
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 50;
$offset = ($page - 1) * $per_page;

$where = ['user_id = :uid', 'transaction_date BETWEEN :from AND :to'];
$params = [':uid' => $uid, ':from' => $from, ':to' => $to];
if ($type   !== '') { $where[] = 'type = :type';       $params[':type'] = $type; }
if ($status !== '') { $where[] = 'status = :status';   $params[':status'] = $status; }
if ($category !== '' && mb_strlen($category) <= 120) { $where[] = 'category = :category'; $params[':category'] = $category; }
if ($q !== '')      { $where[] = '(description LIKE :q OR category LIKE :q)'; $params[':q'] = '%' . $q . '%'; }
$where_sql = implode(' AND ', $where);

// Whitelisted sort columns.
$sort_col = ['date' => 'transaction_date', 'amount' => 'amount', 'category' => 'category', 'type' => 'type'][$_GET['sort'] ?? 'date'] ?? 'transaction_date';
$sort_dir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

try {
    $total_row = fetchOne("SELECT COUNT(*) AS c FROM transactions WHERE $where_sql", $params);
    $total = (int) ($total_row['c'] ?? 0);
    $total_pages = max(1, (int) ceil($total / $per_page));

    $sums = fetchOne(
        "SELECT
            COALESCE(SUM(CASE WHEN type='income'  THEN amount ELSE 0 END), 0) AS income,
            COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END), 0) AS expense
         FROM transactions WHERE $where_sql",
        $params
    ) ?? ['income' => 0, 'expense' => 0];

    $rows = executeQuery(
        "SELECT id, transaction_date, description, category, payment_method, amount, type, status
         FROM transactions WHERE $where_sql
         ORDER BY $sort_col $sort_dir, id DESC
         LIMIT $per_page OFFSET $offset",
        $params
    )->fetchAll();

    $categories = fetchAll('SELECT DISTINCT category FROM transactions WHERE user_id = :uid ORDER BY category ASC', [':uid' => $uid]);
} catch (Throwable $e) {
    error_log('[report:txn] ' . $e->getMessage());
    $rows = []; $categories = []; $total = 0; $total_pages = 1;
    $sums = ['income' => 0, 'expense' => 0];
    flash('danger', 'Could not load the report. Please refresh.');
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span><a href="reports.php">Reports</a></span><span>Transactions</span></div>
    <div class="page-header">
        <div><h1 class="page-header__title">Transactions report</h1><p class="page-header__desc">Filter, search, and total across your ledger.</p></div>
        <div class="page-header__actions">
            <?php
                $csvq = array_filter(['type'=>$type,'status'=>$status,'category'=>$category,'from'=>$from,'to'=>$to], static fn($v) => $v !== '');
                $csvq['report'] = 'transactions';
            ?>
            <a class="btn btn--ghost" href="export-csv.php?<?= e(http_build_query($csvq)) ?>">Export CSV</a>
        </div>
    </div>

    <section class="card"><div class="card__body">
        <form method="GET" action="report-transactions.php" class="filters">
            <div class="field"><label for="f_q">Search</label><input id="f_q" name="q" type="search" value="<?= e($q) ?>"></div>
            <div class="field"><label for="f_type">Type</label>
                <select id="f_type" name="type">
                    <option value="">All</option>
                    <option value="income"  <?= $type === 'income'  ? 'selected' : '' ?>>Income</option>
                    <option value="expense" <?= $type === 'expense' ? 'selected' : '' ?>>Expense</option>
                </select>
            </div>
            <div class="field"><label for="f_status">Status</label>
                <select id="f_status" name="status">
                    <option value="">All</option>
                    <?php foreach (['pending','completed','failed','cancelled'] as $s): ?>
                        <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label for="f_category">Category</label>
                <select id="f_category" name="category">
                    <option value="">All</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= e($c['category']) ?>" <?= $category === $c['category'] ? 'selected' : '' ?>><?= e($c['category']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label for="f_from">From</label><input id="f_from" name="from" type="date" required value="<?= e($from) ?>"></div>
            <div class="filters__actions">
                <button type="submit" class="btn btn--primary">Apply</button>
                <a class="btn btn--ghost" href="report-transactions.php">Reset</a>
            </div>
            <div class="field"><label for="f_to">To</label><input id="f_to" name="to" type="date" required value="<?= e($to) ?>"></div>
        </form>
    </div></section>

    <div class="summary-grid mt-4">
        <div class="summary summary--income"><div class="summary__head"><span>Filtered income</span></div><div class="summary__value"><?= e(money((float) $sums['income'])) ?></div></div>
        <div class="summary summary--expense"><div class="summary__head"><span>Filtered expense</span></div><div class="summary__value"><?= e(money((float) $sums['expense'])) ?></div></div>
        <div class="summary"><div class="summary__head"><span>Rows</span></div><div class="summary__value"><?= (int) $total ?></div></div>
        <div class="summary summary--balance"><div class="summary__head"><span>Net</span></div><div class="summary__value"><?= e(money((float) $sums['income'] - (float) $sums['expense'])) ?></div></div>
    </div>

    <section class="card mt-4">
        <div class="card__body card__body--flush">
            <?php if (empty($rows)): ?>
                <div class="empty-state"><div class="empty-state__title">No transactions match</div><div class="empty-state__desc">Try widening the date range.</div></div>
            <?php else: ?>
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>Date</th><th>Description</th><th>Category</th><th>Type</th><th>Method</th><th class="text-right">Amount</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($rows as $t):
                            $signed = ($t['type'] === 'income' ? '+' : '−') . money((float) $t['amount']);
                            $amount_class = $t['type'] === 'income' ? 'amount--pos' : 'amount--neg';
                            $status_variant = ['completed' => 'success', 'pending' => 'warning', 'failed' => 'danger', 'cancelled' => 'neutral'][$t['status']] ?? 'neutral';
                        ?>
                            <tr>
                                <td><?= e(date('M j, Y', strtotime((string) $t['transaction_date']))) ?></td>
                                <td><?= e($t['description'] ?? '') ?></td>
                                <td><span class="badge badge--neutral"><?= e($t['category']) ?></span></td>
                                <td><?= e(ucfirst((string) $t['type'])) ?></td>
                                <td><?= e(str_replace('_', ' ', (string) $t['payment_method'])) ?></td>
                                <td class="text-right <?= e($amount_class) ?>"><?= e($signed) ?></td>
                                <td><span class="badge badge--<?= e($status_variant) ?>"><?= e(ucfirst((string) $t['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php if ($total_pages > 1):
                    $qs = static function (array $overrides) use ($type, $status, $category, $q, $from, $to): string {
                        return http_build_query(array_filter([
                            'type'=>$type,'status'=>$status,'category'=>$category,'q'=>$q,'from'=>$from,'to'=>$to
                        ], static fn($v) => $v !== '') + $overrides);
                    };
                ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?><a class="btn btn--ghost btn--sm" href="?<?= e($qs(['page'=>$page-1])) ?>">← Previous</a><?php endif; ?>
                        <span class="text-muted">Page <?= (int) $page ?> of <?= (int) $total_pages ?></span>
                        <?php if ($page < $total_pages): ?><a class="btn btn--ghost btn--sm" href="?<?= e($qs(['page'=>$page+1])) ?>">Next →</a><?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
