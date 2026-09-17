<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/csrf.php';

$page_title = 'Payments';
$uid = (int) currentUser()['id'];

$status_filter = in_array($_GET['status'] ?? '', ['scheduled', 'paid', 'overdue', 'cancelled'], true) ? $_GET['status'] : '';
$page     = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 20;
$offset   = ($page - 1) * $per_page;

$where = ['user_id = :uid'];
$params = [':uid' => $uid];
if ($status_filter !== '') {
    $where[] = 'status = :status';
    $params[':status'] = $status_filter;
}
$where_sql = implode(' AND ', $where);

try {
    $total = (int) (fetchOne("SELECT COUNT(*) AS c FROM payments WHERE $where_sql", $params)['c'] ?? 0);
    $total_pages = max(1, (int) ceil($total / $per_page));
    $rows = executeQuery(
        "SELECT id, title, amount, due_date, payment_date, payment_method, status, notes
         FROM payments WHERE $where_sql
         ORDER BY status = 'paid', due_date ASC, id DESC
         LIMIT $per_page OFFSET $offset",
        $params
    )->fetchAll();
} catch (Throwable $e) {
    error_log('[payments:list] ' . $e->getMessage());
    $rows = []; $total = 0; $total_pages = 1;
    flash('danger', 'Could not load payments. Please refresh.');
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span>Payments</span></div>
    <div class="page-header">
        <div><h1 class="page-header__title">Payments</h1><p class="page-header__desc">Schedule outgoing payments and mark them paid when settled.</p></div>
        <div class="page-header__actions"><a class="btn btn--primary" href="payment-new.php">Schedule payment</a></div>
    </div>

    <section class="card">
        <div class="card__header">
            <h2 class="card__title">Your payments <span class="text-soft">(<?= (int) $total ?>)</span></h2>
        </div>
        <div class="card__body">
            <form method="GET" action="payments.php" class="filters">
                <div class="field">
                    <label for="f_status">Status</label>
                    <select id="f_status" name="status">
                        <option value="">All</option>
                        <?php foreach (['scheduled', 'paid', 'overdue', 'cancelled'] as $s): ?>
                            <option value="<?= e($s) ?>" <?= $status_filter === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filters__actions"><button type="submit" class="btn btn--primary">Apply</button><a class="btn btn--ghost" href="payments.php">Reset</a></div>
            </form>
        </div>
        <div class="card__body card__body--flush">
            <?php if (empty($rows)): ?>
                <div class="empty-state">
                    <div class="empty-state__icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="5.5" width="19" height="13" rx="2"/><path d="M2.5 10h19"/></svg></div>
                    <div class="empty-state__title">No payments to show</div>
                    <div class="empty-state__desc"><?= $total === 0 ? 'Schedule a payment to see it here.' : 'Try a different filter.' ?></div>
                    <a class="btn btn--primary" href="payment-new.php">Schedule payment</a>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr><th>Title</th><th class="text-right">Amount</th><th>Due</th><th>Method</th><th>Status</th><th></th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $p):
                                $badge = ['scheduled' => 'info', 'paid' => 'success', 'overdue' => 'danger', 'cancelled' => 'neutral'][$p['status']] ?? 'neutral';
                            ?>
                                <tr>
                                    <td>
                                        <?= e($p['title']) ?>
                                        <?php if (!empty($p['notes'])): ?>
                                            <div class="item-row__meta"><?= e($p['notes']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right amount--neg"><?= e(money((float) $p['amount'])) ?></td>
                                    <td>
                                        <?= e(date('M j, Y', strtotime((string) $p['due_date']))) ?>
                                        <?php if (!empty($p['payment_date'])): ?>
                                            <div class="item-row__meta">paid <?= e(date('M j', strtotime((string) $p['payment_date']))) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e(str_replace('_', ' ', (string) $p['payment_method'])) ?></td>
                                    <td><span class="badge badge--<?= e($badge) ?>"><?= e(ucfirst((string) $p['status'])) ?></span></td>
                                    <td class="text-right">
                                        <?php if ($p['status'] === 'scheduled' || $p['status'] === 'overdue'): ?>
                                            <form method="POST" action="payment-mark-paid.php" class="inline-form">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                                <button type="submit" class="btn btn--primary btn--sm">Mark paid</button>
                                            </form>
                                        <?php endif; ?>
                                        <a class="btn btn--ghost btn--sm" href="payment-edit.php?id=<?= (int) $p['id'] ?>">Edit</a>
                                        <form method="POST" action="payment-delete.php" class="inline-form" onsubmit="return confirm('Delete this payment?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                            <button type="submit" class="btn btn--sm btn--danger">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($total_pages > 1):
                    $qs = static function (array $overrides) use ($status_filter): string {
                        $base = array_filter(['status' => $status_filter], static fn ($v) => $v !== '');
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
