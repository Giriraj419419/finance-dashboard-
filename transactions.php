<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/csrf.php';

$page_title = 'Transactions';
$me = currentUser();
$uid = (int) $me['id'];

// ---- Filters ------------------------------------------------------------
$type     = in_array($_GET['type']     ?? '', ['income', 'expense'], true) ? $_GET['type'] : '';
$category = trim((string) ($_GET['category'] ?? ''));
$from     = trim((string) ($_GET['from'] ?? ''));
$to       = trim((string) ($_GET['to']   ?? ''));
$q        = trim((string) ($_GET['q']    ?? ''));
$page     = max(1, (int) ($_GET['page']  ?? 1));
$per_page = 20;

$where = ['user_id = :uid'];
$params = [':uid' => $uid];

if ($type !== '') {
    $where[] = 'type = :type';
    $params[':type'] = $type;
}
if ($category !== '' && mb_strlen($category) <= 120) {
    $where[] = 'category = :category';
    $params[':category'] = $category;
}
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[] = 'transaction_date >= :from';
    $params[':from'] = $from;
}
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[] = 'transaction_date <= :to';
    $params[':to'] = $to;
}
if ($q !== '') {
    $where[] = '(description LIKE :q OR category LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}

$where_sql = implode(' AND ', $where);
$offset = ($page - 1) * $per_page;

try {
    $total_row = fetchOne(
        "SELECT COUNT(*) AS c FROM transactions WHERE $where_sql",
        $params
    );
    $total = (int) ($total_row['c'] ?? 0);
    $total_pages = max(1, (int) ceil($total / $per_page));

    // Bind paging with integers via a prepared statement (PDO can't bind LIMIT with named args
    // when emulate is off unless we bindValue — we splice in validated ints).
    $rows = executeQuery(
        "SELECT id, transaction_date, description, category, payment_method, amount, type, status
         FROM transactions
         WHERE $where_sql
         ORDER BY transaction_date DESC, id DESC
         LIMIT $per_page OFFSET $offset",
        $params
    )->fetchAll();

    $categories = fetchAll(
        'SELECT DISTINCT category FROM transactions WHERE user_id = :uid ORDER BY category ASC',
        [':uid' => $uid]
    );
} catch (Throwable $e) {
    error_log('[transactions:list] ' . $e->getMessage());
    $rows = [];
    $categories = [];
    $total = 0;
    $total_pages = 1;
    flash('danger', 'Could not load transactions. Please refresh.');
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span>Transactions</span></div>
    <div class="page-header">
        <div>
            <h1 class="page-header__title">Transactions</h1>
            <p class="page-header__desc">All income and expense activity across your account.</p>
        </div>
        <div class="page-header__actions">
            <a class="btn btn--primary" href="transaction-new.php">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                Add transaction
            </a>
        </div>
    </div>

    <section class="card">
        <div class="card__header">
            <h2 class="card__title">All transactions <span class="text-soft">(<?= (int) $total ?>)</span></h2>
        </div>
        <div class="card__body">
            <form method="GET" action="transactions.php" class="filters">
                <div class="field">
                    <label for="f_q">Search</label>
                    <input id="f_q" name="q" type="search" value="<?= e($q) ?>" placeholder="Description or category">
                </div>
                <div class="field">
                    <label for="f_type">Type</label>
                    <select id="f_type" name="type">
                        <option value="">All</option>
                        <option value="income"  <?= $type === 'income'  ? 'selected' : '' ?>>Income</option>
                        <option value="expense" <?= $type === 'expense' ? 'selected' : '' ?>>Expense</option>
                    </select>
                </div>
                <div class="field">
                    <label for="f_category">Category</label>
                    <select id="f_category" name="category">
                        <option value="">All</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= e($c['category']) ?>" <?= $category === $c['category'] ? 'selected' : '' ?>><?= e($c['category']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="f_from">From</label>
                    <input id="f_from" name="from" type="date" value="<?= e($from) ?>">
                </div>
                <div class="field">
                    <label for="f_to">To</label>
                    <input id="f_to" name="to" type="date" value="<?= e($to) ?>">
                </div>
                <div class="filters__actions">
                    <button type="submit" class="btn btn--primary">Apply</button>
                    <a class="btn btn--ghost" href="transactions.php">Reset</a>
                </div>
            </form>
        </div>
        <div class="card__body card__body--flush">
            <?php if (empty($rows)): ?>
                <div class="empty-state">
                    <div class="empty-state__icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M7 7h13M7 7l4-4M7 7l4 4M17 17H4M17 17l-4 4M17 17l-4-4"/></svg>
                    </div>
                    <div class="empty-state__title">No transactions match</div>
                    <div class="empty-state__desc"><?= $total === 0 ? "You haven't recorded any transactions yet." : "Try adjusting your filters." ?></div>
                    <a class="btn btn--primary" href="transaction-new.php">Add transaction</a>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th><th>Description</th><th>Category</th><th>Type</th><th>Method</th><th class="text-right">Amount</th><th>Status</th><th></th>
                            </tr>
                        </thead>
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
                                    <td class="text-right">
                                        <a class="btn btn--ghost btn--sm" href="transaction-edit.php?id=<?= (int) $t['id'] ?>">Edit</a>
                                        <form method="POST" action="transaction-delete.php" class="inline-form" onsubmit="return confirm('Delete this transaction? This cannot be undone.');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                            <button type="submit" class="btn btn--sm btn--danger">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($total_pages > 1):
                    $qs = static function (array $overrides) use ($type, $category, $from, $to, $q): string {
                        $base = array_filter(['type' => $type, 'category' => $category, 'from' => $from, 'to' => $to, 'q' => $q], static fn ($v) => $v !== '');
                        return http_build_query($base + $overrides);
                    };
                ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a class="btn btn--ghost btn--sm" href="?<?= e($qs(['page' => $page - 1])) ?>">← Previous</a>
                        <?php endif; ?>
                        <span class="text-muted">Page <?= (int) $page ?> of <?= (int) $total_pages ?></span>
                        <?php if ($page < $total_pages): ?>
                            <a class="btn btn--ghost btn--sm" href="?<?= e($qs(['page' => $page + 1])) ?>">Next →</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
