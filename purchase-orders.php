<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/csrf.php';
requireRole('admin', 'manager', 'employee'); // all roles can see their own; managers/admins see broader

$page_title = 'Purchase Orders';
$me   = currentUser();
$uid  = (int) $me['id'];
$role = (string) ($me['role'] ?? 'employee');

$q      = trim((string) ($_GET['q'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$from   = trim((string) ($_GET['from'] ?? ''));
$to     = trim((string) ($_GET['to']   ?? ''));
$page   = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

$allowed_status = ['draft','submitted','open','approved','ordered','received','closed','cancelled'];

$where = [];
$params = [];
if (!in_array($role, ['admin', 'manager'], true)) {
    $where[] = 'user_id = :uid';
    $params[':uid'] = $uid;
}
if ($q !== '') {
    $where[] = '(order_number LIKE :q OR supplier_name LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
if (in_array($status, $allowed_status, true)) {
    $where[] = 'status = :status';
    $params[':status'] = $status;
}
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[] = 'order_date >= :from';
    $params[':from'] = $from;
}
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[] = 'order_date <= :to';
    $params[':to'] = $to;
}
$where_sql = $where === [] ? '1=1' : implode(' AND ', $where);

try {
    $total = (int) (fetchOne("SELECT COUNT(*) AS c FROM purchase_orders WHERE $where_sql", $params)['c'] ?? 0);
    $total_pages = max(1, (int) ceil($total / $per_page));
    $rows = executeQuery(
        "SELECT id, order_number, supplier_name, order_date, expected_date, total_amount, status, user_id
         FROM purchase_orders
         WHERE $where_sql
         ORDER BY order_date DESC, id DESC
         LIMIT $per_page OFFSET $offset",
        $params
    )->fetchAll();
} catch (Throwable $e) {
    error_log('[purchase-orders:list] ' . $e->getMessage());
    $rows = []; $total = 0; $total_pages = 1;
    flash('danger', 'Could not load purchase orders. Please refresh.');
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span>Purchase Orders</span></div>
    <div class="page-header">
        <div><h1 class="page-header__title">Purchase orders</h1><p class="page-header__desc">Raise, approve, and track vendor purchase orders.</p></div>
        <div class="page-header__actions">
            <a class="btn btn--ghost" href="export-csv.php?report=purchase_orders">Export CSV</a>
            <a class="btn btn--primary" href="po-new.php">New PO</a>
        </div>
    </div>

    <section class="card">
        <div class="card__header"><h2 class="card__title">All POs <span class="text-soft">(<?= (int) $total ?>)</span></h2></div>
        <div class="card__body">
            <form method="GET" action="purchase-orders.php" class="filters">
                <div class="field">
                    <label for="f_q">Search</label>
                    <input id="f_q" name="q" type="search" value="<?= e($q) ?>" placeholder="PO # or supplier">
                </div>
                <div class="field">
                    <label for="f_status">Status</label>
                    <select id="f_status" name="status">
                        <option value="">All</option>
                        <?php foreach ($allowed_status as $s): ?>
                            <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label for="f_from">From</label><input id="f_from" name="from" type="date" value="<?= e($from) ?>"></div>
                <div class="field"><label for="f_to">To</label><input id="f_to" name="to" type="date" value="<?= e($to) ?>"></div>
                <div class="field"><span>&nbsp;</span></div>
                <div class="filters__actions"><button type="submit" class="btn btn--primary">Apply</button><a class="btn btn--ghost" href="purchase-orders.php">Reset</a></div>
            </form>
        </div>
        <div class="card__body card__body--flush">
            <?php if (empty($rows)): ?>
                <div class="empty-state">
                    <div class="empty-state__icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h9l5 5v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/><path d="M14 3v6h6"/></svg></div>
                    <div class="empty-state__title">No purchase orders</div>
                    <div class="empty-state__desc"><?= $total === 0 ? 'Raise a PO to start tracking approvals, deliveries, and invoicing.' : 'Try a different filter.' ?></div>
                    <a class="btn btn--primary" href="po-new.php">Create purchase order</a>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr><th>PO #</th><th>Supplier</th><th>Order date</th><th>Expected</th><th class="text-right">Total</th><th>Status</th><th></th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $p):
                                $badge = [
                                    'draft' => 'neutral', 'submitted' => 'info', 'open' => 'info', 'approved' => 'success',
                                    'ordered' => 'warning', 'received' => 'success', 'closed' => 'neutral', 'cancelled' => 'danger'
                                ][$p['status']] ?? 'neutral';
                            ?>
                                <tr>
                                    <td><a href="po-edit.php?id=<?= (int) $p['id'] ?>"><?= e($p['order_number']) ?></a></td>
                                    <td><?= e($p['supplier_name']) ?></td>
                                    <td><?= e(date('M j, Y', strtotime((string) $p['order_date']))) ?></td>
                                    <td><?= $p['expected_date'] ? e(date('M j, Y', strtotime((string) $p['expected_date']))) : '<span class="text-soft">—</span>' ?></td>
                                    <td class="text-right"><?= e(money((float) $p['total_amount'])) ?></td>
                                    <td><span class="badge badge--<?= e($badge) ?>"><?= e(ucfirst((string) $p['status'])) ?></span></td>
                                    <td class="text-right">
                                        <a class="btn btn--ghost btn--sm" href="po-edit.php?id=<?= (int) $p['id'] ?>">Open</a>
                                        <form method="POST" action="po-delete.php" class="inline-form" onsubmit="return confirm('Delete this purchase order and all its items?');">
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
                    $qs = static function (array $overrides) use ($q, $status, $from, $to): string {
                        return http_build_query(array_filter(['q'=>$q,'status'=>$status,'from'=>$from,'to'=>$to], static fn($v) => $v !== '') + $overrides);
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
