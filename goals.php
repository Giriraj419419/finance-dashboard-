<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/csrf.php';

$page_title = 'Goals';
$uid = (int) currentUser()['id'];

$page     = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 20;
$offset   = ($page - 1) * $per_page;

try {
    $total = (int) (fetchOne('SELECT COUNT(*) AS c FROM goals WHERE user_id = :uid', [':uid' => $uid])['c'] ?? 0);
    $total_pages = max(1, (int) ceil($total / $per_page));
    $rows = executeQuery(
        "SELECT id, name, target_amount, current_amount, target_date, status, updated_at
         FROM goals WHERE user_id = :uid
         ORDER BY status <> 'active', target_date IS NULL, target_date ASC, id DESC
         LIMIT $per_page OFFSET $offset",
        [':uid' => $uid]
    )->fetchAll();
} catch (Throwable $e) {
    error_log('[goals:list] ' . $e->getMessage());
    $rows = []; $total = 0; $total_pages = 1;
    flash('danger', 'Could not load goals. Please refresh.');
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span>Goals</span></div>
    <div class="page-header">
        <div><h1 class="page-header__title">Financial goals</h1><p class="page-header__desc">Track progress toward savings targets and long-term milestones.</p></div>
        <div class="page-header__actions"><a class="btn btn--primary" href="goal-new.php">New goal</a></div>
    </div>

    <section class="card">
        <div class="card__header"><h2 class="card__title">Your goals <span class="text-soft">(<?= (int) $total ?>)</span></h2></div>
        <div class="card__body card__body--flush">
            <?php if (empty($rows)): ?>
                <div class="empty-state">
                    <div class="empty-state__icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.4"/></svg></div>
                    <div class="empty-state__title">No goals yet</div>
                    <div class="empty-state__desc">Set a target amount and deadline to keep saving on track.</div>
                    <a class="btn btn--primary" href="goal-new.php">Add a goal</a>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr><th>Name</th><th class="text-right">Target</th><th class="text-right">Saved</th><th class="text-right">Remaining</th><th>Target date</th><th>Progress</th><th>Status</th><th></th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $g):
                                $target = (float) $g['target_amount'];
                                $current = (float) $g['current_amount'];
                                $remaining = max(0.0, $target - $current);
                                $pct = $target > 0 ? min(100, (int) round($current / $target * 100)) : 0;
                                $badge = ['active' => 'success', 'paused' => 'warning', 'achieved' => 'info', 'archived' => 'neutral'][$g['status']] ?? 'neutral';
                            ?>
                                <tr>
                                    <td><?= e($g['name']) ?></td>
                                    <td class="text-right"><?= e(money($target)) ?></td>
                                    <td class="text-right amount--pos"><?= e(money($current)) ?></td>
                                    <td class="text-right"><?= e(money($remaining)) ?></td>
                                    <td><?= $g['target_date'] ? e(date('M j, Y', strtotime((string) $g['target_date']))) : '<span class="text-soft">—</span>' ?></td>
                                    <td class="col-progress">
                                        <div class="progress"><div class="progress__bar" data-percent="<?= (int) $pct ?>"></div></div>
                                        <div class="item-row__meta"><?= (int) $pct ?>%</div>
                                    </td>
                                    <td><span class="badge badge--<?= e($badge) ?>"><?= e(ucfirst((string) $g['status'])) ?></span></td>
                                    <td class="text-right">
                                        <a class="btn btn--ghost btn--sm" href="goal-edit.php?id=<?= (int) $g['id'] ?>">Edit</a>
                                        <?php if ($g['status'] === 'active' && $current < $target): ?>
                                            <a class="btn btn--primary btn--sm" href="goal-contribute.php?id=<?= (int) $g['id'] ?>">Contribute</a>
                                        <?php endif; ?>
                                        <form method="POST" action="goal-delete.php" class="inline-form" onsubmit="return confirm('Delete this goal? Its contributions history will also be removed.');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
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
