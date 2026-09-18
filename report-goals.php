<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';

$page_title = 'Goal progress';
$uid = (int) currentUser()['id'];

try {
    $rows = fetchAll(
        'SELECT id, name, target_amount, current_amount, target_date, status
         FROM goals WHERE user_id = :uid ORDER BY status <> \'active\', target_date IS NULL, target_date ASC, id DESC',
        [':uid' => $uid]
    );
} catch (Throwable $e) {
    error_log('[report:goals] ' . $e->getMessage());
    $rows = [];
    flash('danger', 'Could not load the report. Please refresh.');
}

$total_target = 0.0; $total_saved = 0.0; $achieved = 0;
foreach ($rows as $r) {
    $total_target += (float) $r['target_amount'];
    $total_saved  += (float) $r['current_amount'];
    if ($r['status'] === 'achieved') $achieved++;
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span><a href="reports.php">Reports</a></span><span>Goal progress</span></div>
    <div class="page-header">
        <div><h1 class="page-header__title">Goal progress</h1><p class="page-header__desc">Where every goal stands.</p></div>
        <div class="page-header__actions"><a class="btn btn--ghost" href="export-csv.php?report=goals">Export CSV</a></div>
    </div>

    <div class="summary-grid mt-2">
        <div class="summary"><div class="summary__head"><span>Total target</span></div><div class="summary__value"><?= e(money($total_target)) ?></div></div>
        <div class="summary summary--savings"><div class="summary__head"><span>Total saved</span></div><div class="summary__value"><?= e(money($total_saved)) ?></div></div>
        <div class="summary summary--balance"><div class="summary__head"><span>Remaining</span></div><div class="summary__value"><?= e(money(max(0.0, $total_target - $total_saved))) ?></div></div>
        <div class="summary"><div class="summary__head"><span>Achieved</span></div><div class="summary__value"><?= (int) $achieved ?></div></div>
    </div>

    <section class="card mt-4">
        <div class="card__body card__body--flush">
            <?php if (empty($rows)): ?>
                <div class="empty-state"><div class="empty-state__title">No goals defined yet</div><a class="btn btn--primary mt-2" href="goal-new.php">Add goal</a></div>
            <?php else: ?>
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>Goal</th><th class="text-right">Target</th><th class="text-right">Saved</th><th class="text-right">Remaining</th><th>Target date</th><th>Progress</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($rows as $g):
                            $target = (float) $g['target_amount'];
                            $current = (float) $g['current_amount'];
                            $remaining = max(0.0, $target - $current);
                            $pct = $target > 0 ? (int) round($current / $target * 100) : 0;
                            $capped_pct = min(100, $pct);
                            $badge = ['active' => 'success', 'paused' => 'warning', 'achieved' => 'info', 'archived' => 'neutral'][$g['status']] ?? 'neutral';
                        ?>
                            <tr>
                                <td><?= e($g['name']) ?></td>
                                <td class="text-right"><?= e(money($target)) ?></td>
                                <td class="text-right amount--pos"><?= e(money($current)) ?></td>
                                <td class="text-right"><?= e(money($remaining)) ?></td>
                                <td><?= $g['target_date'] ? e(date('M j, Y', strtotime((string) $g['target_date']))) : '<span class="text-soft">—</span>' ?></td>
                                <td class="col-progress">
                                    <div class="progress"><div class="progress__bar" data-percent="<?= (int) $capped_pct ?>"></div></div>
                                    <div class="item-row__meta"><?= (int) $pct ?>%</div>
                                </td>
                                <td><span class="badge badge--<?= e($badge) ?>"><?= e(ucfirst((string) $g['status'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </div>
    </section>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
