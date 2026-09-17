<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/audit.php';

$page_title = 'Contribute';
$me = currentUser();
$uid = (int) $me['id'];
$role = (string) ($me['role'] ?? 'employee');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) { header('Location: ' . base_url('/goals.php')); exit; }

try {
    $g = fetchOne(
        'SELECT id, user_id, name, target_amount, current_amount, status FROM goals WHERE id = :id LIMIT 1',
        [':id' => $id]
    );
} catch (Throwable $e) {
    error_log('[goal-contribute:load] ' . $e->getMessage());
    flash('danger', 'Could not load that goal.');
    header('Location: ' . base_url('/goals.php')); exit;
}
if (!$g) { flash('danger', 'Goal not found.'); header('Location: ' . base_url('/goals.php')); exit; }
// Only the owner can contribute — admin does NOT top up other users' goals.
if ((int) $g['user_id'] !== $uid) {
    log_audit('access_denied', 'goal', $id, ['reason' => 'not_owner', 'op' => 'contribute']);
    http_response_code(403); require __DIR__ . '/403.php'; exit;
}
$remaining = max(0.0, (float) $g['target_amount'] - (float) $g['current_amount']);

$errors = [];
$old = ['amount' => '', 'contribution_date' => date('Y-m-d'), 'note' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_or_die();
    foreach (array_keys($old) as $k) { $old[$k] = trim((string) ($_POST[$k] ?? '')); }

    $amount_raw = str_replace([',', ' '], '', $old['amount']);
    if (!is_numeric($amount_raw) || (float) $amount_raw <= 0) { $errors['amount'] = 'Enter a positive contribution amount.'; }
    elseif ((float) $amount_raw > 9999999999.99) { $errors['amount'] = 'Amount is too large.'; }
    if ($remaining <= 0) { $errors['amount'] = 'This goal is already fully funded.'; }
    elseif (empty($errors['amount']) && (float) $amount_raw > $remaining) {
        $errors['amount'] = 'This contribution exceeds the remaining amount (' . money($remaining) . ').';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['contribution_date'])) {
        $errors['contribution_date'] = 'Enter a valid date.';
    }
    if (mb_strlen($old['note']) > 255) { $errors['note'] = 'Keep the note under 255 characters.'; }

    if ($errors === []) {
        $pdo = getDatabaseConnection();
        try {
            $pdo->beginTransaction();

            // Re-read current_amount inside the transaction to avoid the classic race.
            $locked = $pdo->prepare(
                'SELECT current_amount, target_amount FROM goals WHERE id = :id FOR UPDATE'
            );
            $locked->execute([':id' => $id]);
            $cur = $locked->fetch();
            if (!$cur) {
                $pdo->rollBack();
                flash('danger', 'Goal not found.');
                header('Location: ' . base_url('/goals.php')); exit;
            }
            $new_current = (float) $cur['current_amount'] + (float) $amount_raw;
            if ($new_current > (float) $cur['target_amount']) {
                // Cap defensively — schema doesn't model overfunding.
                $new_current = (float) $cur['target_amount'];
            }

            insertRecord('goal_contributions', [
                'goal_id'           => $id,
                'user_id'           => $uid,
                'amount'            => number_format((float) $amount_raw, 2, '.', ''),
                'contribution_date' => $old['contribution_date'],
                'note'              => $old['note'] === '' ? null : $old['note'],
            ]);

            $upd = $pdo->prepare('UPDATE goals SET current_amount = :ca WHERE id = :id');
            $upd->execute([':ca' => number_format($new_current, 2, '.', ''), ':id' => $id]);

            // If the goal just hit target, auto-mark it achieved.
            if (abs($new_current - (float) $cur['target_amount']) < 0.005 && $g['status'] === 'active') {
                $mark = $pdo->prepare("UPDATE goals SET status = 'achieved' WHERE id = :id");
                $mark->execute([':id' => $id]);
            }

            $pdo->commit();
            log_audit('goal_contributed', 'goal', $id, [
                'amount'     => (float) $amount_raw,
                'new_total'  => $new_current,
            ]);
            flash('success', 'Contribution recorded.');
            header('Location: ' . base_url('/goals.php')); exit;
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('[goal-contribute] ' . $ex->getMessage());
            $errors['_general'] = 'Could not record the contribution. Please try again.';
        }
    }
}

// Recent contributions history (nice-to-have on the page).
try {
    $history = fetchAll(
        'SELECT amount, contribution_date, note, created_at FROM goal_contributions
         WHERE goal_id = :id ORDER BY contribution_date DESC, id DESC LIMIT 10',
        [':id' => $id]
    );
} catch (Throwable $e) {
    $history = [];
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span><a href="goals.php">Goals</a></span><span>Contribute</span></div>
    <div class="page-header"><div><h1 class="page-header__title">Contribute to “<?= e($g['name']) ?>”</h1>
        <p class="page-header__desc"><?= e(money((float) $g['current_amount'])) ?> of <?= e(money((float) $g['target_amount'])) ?> saved. <?= e(money($remaining)) ?> to go.</p>
    </div></div>

    <?php if (!empty($errors['_general'])): ?>
        <div class="flash flash--danger" role="alert"><span><?= e($errors['_general']) ?></span></div>
    <?php endif; ?>

    <div class="dash-grid">
        <section class="card"><div class="card__body">
            <form method="POST" action="goal-contribute.php" class="form" data-validate novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $id ?>">
                <div class="form__row">
                    <div class="field<?= isset($errors['amount']) ? ' field--error' : '' ?>">
                        <label for="amount">Amount</label>
                        <input id="amount" name="amount" type="number" step="0.01" min="0.01" required value="<?= e($old['amount']) ?>">
                        <div class="error" role="alert"><?= e($errors['amount'] ?? '') ?></div>
                    </div>
                    <div class="field<?= isset($errors['contribution_date']) ? ' field--error' : '' ?>">
                        <label for="contribution_date">Date</label>
                        <input id="contribution_date" name="contribution_date" type="date" required value="<?= e($old['contribution_date']) ?>">
                        <div class="error" role="alert"><?= e($errors['contribution_date'] ?? '') ?></div>
                    </div>
                </div>
                <div class="field<?= isset($errors['note']) ? ' field--error' : '' ?>">
                    <label for="note">Note <span class="text-soft">(optional)</span></label>
                    <input id="note" name="note" type="text" value="<?= e($old['note']) ?>" placeholder="e.g. Bonus deposit">
                    <div class="error" role="alert"><?= e($errors['note'] ?? '') ?></div>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="btn btn--primary">Add contribution</button>
                    <a class="btn btn--ghost" href="goals.php">Cancel</a>
                </div>
            </form>
        </div></section>

        <section class="card">
            <div class="card__header"><h2 class="card__title">Recent contributions</h2></div>
            <div class="card__body">
                <?php if (empty($history)): ?>
                    <div class="text-muted">No contributions yet.</div>
                <?php else: foreach ($history as $h): ?>
                    <div class="item-row">
                        <div class="item-row__grow">
                            <div class="item-row__title">+ <?= e(money((float) $h['amount'])) ?></div>
                            <div class="item-row__meta">
                                <?= e(date('M j, Y', strtotime((string) $h['contribution_date']))) ?>
                                <?php if (!empty($h['note'])): ?> · <?= e($h['note']) ?><?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </section>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
