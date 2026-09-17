<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/audit.php';

$page_title = 'New goal';
$uid = (int) currentUser()['id'];

$allowed_status = ['active', 'paused', 'achieved', 'archived'];
$errors = [];
$old = [
    'name' => '', 'description' => '', 'target_amount' => '',
    'current_amount' => '0.00', 'target_date' => '', 'status' => 'active',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_or_die();
    foreach (array_keys($old) as $k) { $old[$k] = trim((string) ($_POST[$k] ?? '')); }

    if ($old['name'] === '' || mb_strlen($old['name']) > 160) { $errors['name'] = 'Name is required (max 160 characters).'; }
    if (mb_strlen($old['description']) > 65535)              { $errors['description'] = 'Description is too long.'; }
    $target_raw  = str_replace([',', ' '], '', $old['target_amount']);
    $current_raw = str_replace([',', ' '], '', $old['current_amount']);
    if (!is_numeric($target_raw)  || (float) $target_raw  <= 0) { $errors['target_amount']  = 'Enter a positive target amount.'; }
    if (!is_numeric($current_raw) || (float) $current_raw < 0)  { $errors['current_amount'] = 'Current amount cannot be negative.'; }
    if (empty($errors['target_amount']) && empty($errors['current_amount']) && (float) $current_raw > (float) $target_raw) {
        // Schema does not model overfunding — cap at target.
        $errors['current_amount'] = 'Current amount cannot exceed the target.';
    }
    if ($old['target_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['target_date'])) {
        $errors['target_date'] = 'Enter a valid target date.';
    }
    if (!in_array($old['status'], $allowed_status, true)) { $errors['status'] = 'Pick a valid status.'; }

    if ($errors === []) {
        try {
            $id = insertRecord('goals', [
                'user_id'        => $uid,
                'name'           => $old['name'],
                'target_amount'  => number_format((float) $target_raw,  2, '.', ''),
                'current_amount' => number_format((float) $current_raw, 2, '.', ''),
                'target_date'    => $old['target_date'] !== '' ? $old['target_date'] : null,
                'status'         => $old['status'],
            ]);
            // Schema has no dedicated description column on goals; the field is a UI hint kept
            // for future use. If you need persistent descriptions, add a column via migration
            // and re-enable this write.
            log_audit('goal_created', 'goal', $id, ['name' => $old['name'], 'target' => (float) $target_raw]);
            flash('success', 'Goal created.');
            header('Location: ' . base_url('/goals.php')); exit;
        } catch (Throwable $ex) {
            error_log('[goal-new] ' . $ex->getMessage());
            $errors['_general'] = 'Could not save the goal. Please try again.';
        }
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span><a href="goals.php">Goals</a></span><span>New</span></div>
    <div class="page-header"><div><h1 class="page-header__title">New goal</h1><p class="page-header__desc">Set a target and track how close you are.</p></div></div>
    <?php if (!empty($errors['_general'])): ?>
        <div class="flash flash--danger" role="alert"><span><?= e($errors['_general']) ?></span></div>
    <?php endif; ?>
    <section class="card"><div class="card__body">
        <form method="POST" action="goal-new.php" class="form" data-validate novalidate>
            <?= csrf_field() ?>
            <div class="field<?= isset($errors['name']) ? ' field--error' : '' ?>">
                <label for="name">Name</label>
                <input id="name" name="name" type="text" required value="<?= e($old['name']) ?>" placeholder="e.g. Emergency fund">
                <div class="error" role="alert"><?= e($errors['name'] ?? '') ?></div>
            </div>
            <div class="field<?= isset($errors['description']) ? ' field--error' : '' ?>">
                <label for="description">Description <span class="text-soft">(optional)</span></label>
                <textarea id="description" name="description" rows="3"><?= e($old['description']) ?></textarea>
                <div class="error" role="alert"><?= e($errors['description'] ?? '') ?></div>
            </div>
            <div class="form__row">
                <div class="field<?= isset($errors['target_amount']) ? ' field--error' : '' ?>">
                    <label for="target_amount">Target amount</label>
                    <input id="target_amount" name="target_amount" type="number" step="0.01" min="0.01" required value="<?= e($old['target_amount']) ?>">
                    <div class="error" role="alert"><?= e($errors['target_amount'] ?? '') ?></div>
                </div>
                <div class="field<?= isset($errors['current_amount']) ? ' field--error' : '' ?>">
                    <label for="current_amount">Current amount</label>
                    <input id="current_amount" name="current_amount" type="number" step="0.01" min="0" required value="<?= e($old['current_amount']) ?>">
                    <div class="error" role="alert"><?= e($errors['current_amount'] ?? '') ?></div>
                </div>
            </div>
            <div class="form__row">
                <div class="field<?= isset($errors['target_date']) ? ' field--error' : '' ?>">
                    <label for="target_date">Target date <span class="text-soft">(optional)</span></label>
                    <input id="target_date" name="target_date" type="date" value="<?= e($old['target_date']) ?>">
                    <div class="error" role="alert"><?= e($errors['target_date'] ?? '') ?></div>
                </div>
                <div class="field<?= isset($errors['status']) ? ' field--error' : '' ?>">
                    <label for="status">Status</label>
                    <select id="status" name="status" required>
                        <?php foreach ($allowed_status as $s): ?>
                            <option value="<?= e($s) ?>" <?= $old['status'] === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="error" role="alert"><?= e($errors['status'] ?? '') ?></div>
                </div>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn btn--primary">Save goal</button>
                <a class="btn btn--ghost" href="goals.php">Cancel</a>
            </div>
        </form>
    </div></section>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
