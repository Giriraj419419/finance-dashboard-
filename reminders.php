<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/csrf.php';

$page_title = 'Reminders';
$me = currentUser();
$uid = (int) $me['id'];
$role = (string) ($me['role'] ?? 'employee');

$view = in_array($_GET['view'] ?? '', ['pending','completed','overdue','upcoming','all'], true) ? $_GET['view'] : 'pending';
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

$where = ['user_id = :uid'];
$params = [':uid' => $uid];
switch ($view) {
    case 'pending':   $where[] = "status = 'pending'"; break;
    case 'completed': $where[] = "status = 'completed'"; break;
    case 'overdue':   $where[] = "status = 'pending' AND reminder_date < NOW()"; break;
    case 'upcoming':  $where[] = "status = 'pending' AND reminder_date >= NOW()"; break;
    case 'all': default: break;
}
$where_sql = implode(' AND ', $where);

try {
    $total = (int) (fetchOne("SELECT COUNT(*) AS c FROM reminders WHERE $where_sql", $params)['c'] ?? 0);
    $total_pages = max(1, (int) ceil($total / $per_page));
    $rows = executeQuery(
        "SELECT id, title, description, priority, reminder_date, recurrence_type, status
         FROM reminders WHERE $where_sql
         ORDER BY reminder_date ASC, id DESC
         LIMIT $per_page OFFSET $offset",
        $params
    )->fetchAll();

    // Small badge counters (single round trip).
    $counts = fetchOne(
        "SELECT
            SUM(status = 'pending') AS c_pending,
            SUM(status = 'completed') AS c_completed,
            SUM(status = 'pending' AND reminder_date < NOW()) AS c_overdue,
            SUM(status = 'pending' AND reminder_date >= NOW()) AS c_upcoming
         FROM reminders WHERE user_id = :uid",
        [':uid' => $uid]
    ) ?? [];
} catch (Throwable $e) {
    error_log('[reminders:list] ' . $e->getMessage());
    $rows = []; $total = 0; $total_pages = 1; $counts = [];
    flash('danger', 'Could not load reminders. Please refresh.');
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span>Reminders</span></div>
    <div class="page-header">
        <div><h1 class="page-header__title">Reminders</h1><p class="page-header__desc">Stay ahead of tax filings, renewals, and reviews.</p></div>
        <div class="page-header__actions"><a class="btn btn--primary" href="reminder-new.php">New reminder</a></div>
    </div>

    <div class="tab-bar">
        <?php
        $tabs = [
            'pending'   => 'Pending ' . (int) ($counts['c_pending']   ?? 0),
            'overdue'   => 'Overdue ' . (int) ($counts['c_overdue']   ?? 0),
            'upcoming'  => 'Upcoming ' . (int) ($counts['c_upcoming'] ?? 0),
            'completed' => 'Completed ' . (int) ($counts['c_completed'] ?? 0),
            'all'       => 'All',
        ];
        foreach ($tabs as $key => $label): ?>
            <a class="tab<?= $view === $key ? ' is-active' : '' ?>" href="?view=<?= e($key) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>

    <section class="card mt-2">
        <div class="card__body card__body--flush">
            <?php if (empty($rows)): ?>
                <div class="empty-state">
                    <div class="empty-state__icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 1 1 12 0c0 5 2 6 2 8H4c0-2 2-3 2-8z"/></svg></div>
                    <div class="empty-state__title">No reminders here</div>
                    <div class="empty-state__desc">Add one to stay ahead of recurring finance tasks.</div>
                    <a class="btn btn--primary" href="reminder-new.php">Add reminder</a>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Title</th><th>Due</th><th>Priority</th><th>Recurrence</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($rows as $r):
                                $due = strtotime((string) $r['reminder_date']);
                                $overdue = $r['status'] === 'pending' && $due < time();
                                $badge = ['pending' => 'info', 'completed' => 'success', 'snoozed' => 'warning', 'cancelled' => 'neutral'][$r['status']] ?? 'neutral';
                                if ($overdue) { $badge = 'danger'; }
                                $prio_badge = ['low' => 'neutral', 'medium' => 'info', 'high' => 'danger'][$r['priority']] ?? 'neutral';
                            ?>
                                <tr>
                                    <td>
                                        <?= e($r['title']) ?>
                                        <?php if (!empty($r['description'])): ?><div class="item-row__meta"><?= e(mb_substr((string) $r['description'], 0, 80)) ?></div><?php endif; ?>
                                    </td>
                                    <td>
                                        <?= e(date('M j, Y g:i A', $due)) ?>
                                        <?php if ($overdue): ?><div class="item-row__meta text-danger"><strong>Overdue</strong></div><?php endif; ?>
                                    </td>
                                    <td><span class="badge badge--<?= e($prio_badge) ?>"><?= e(ucfirst((string) $r['priority'])) ?></span></td>
                                    <td><?= e(ucfirst((string) $r['recurrence_type'])) ?></td>
                                    <td><span class="badge badge--<?= e($badge) ?>"><?= $overdue ? 'Overdue' : e(ucfirst((string) $r['status'])) ?></span></td>
                                    <td class="text-right">
                                        <?php if ($r['status'] === 'pending'): ?>
                                            <form method="POST" action="reminder-complete.php" class="inline-form">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                                <button type="submit" class="btn btn--primary btn--sm">Complete</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" action="reminder-reopen.php" class="inline-form">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                                <button type="submit" class="btn btn--ghost btn--sm">Reopen</button>
                                            </form>
                                        <?php endif; ?>
                                        <a class="btn btn--ghost btn--sm" href="reminder-edit.php?id=<?= (int) $r['id'] ?>">Edit</a>
                                        <form method="POST" action="reminder-delete.php" class="inline-form" onsubmit="return confirm('Delete this reminder?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
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
                        <?php if ($page > 1): ?><a class="btn btn--ghost btn--sm" href="?view=<?= e($view) ?>&page=<?= (int) ($page-1) ?>">← Previous</a><?php endif; ?>
                        <span class="text-muted">Page <?= (int) $page ?> of <?= (int) $total_pages ?></span>
                        <?php if ($page < $total_pages): ?><a class="btn btn--ghost btn--sm" href="?view=<?= e($view) ?>&page=<?= (int) ($page+1) ?>">Next →</a><?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
