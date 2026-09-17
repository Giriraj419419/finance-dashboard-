<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/audit.php';

$page_title = 'New budget';
$uid = (int) currentUser()['id'];

$allowed_status = ['active', 'paused', 'completed', 'archived'];
$errors = [];
$old = [
    'name'          => '',
    'category'      => '',
    'budget_amount' => '',
    'start_date'    => date('Y-m-01'),
    'end_date'      => date('Y-m-t'),
    'status'        => 'active',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_or_die();
    foreach (array_keys($old) as $k) {
        $old[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    if ($old['name'] === '' || mb_strlen($old['name']) > 160) {
        $errors['name'] = 'Name is required (max 160 characters).';
    }
    if ($old['category'] === '' || mb_strlen($old['category']) > 120) {
        $errors['category'] = 'Category is required (max 120 characters).';
    }
    $amount_raw = str_replace([',', ' '], '', $old['budget_amount']);
    if (!is_numeric($amount_raw) || (float) $amount_raw <= 0) {
        $errors['budget_amount'] = 'Enter a positive planned amount.';
    } elseif ((float) $amount_raw > 9999999999.99) {
        $errors['budget_amount'] = 'Amount is too large.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['start_date'])) {
        $errors['start_date'] = 'Enter a valid start date.';
    }
    if ($old['end_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['end_date'])) {
        $errors['end_date'] = 'Enter a valid end date.';
    }
    if (empty($errors['start_date']) && empty($errors['end_date']) && $old['end_date'] !== '' && $old['end_date'] < $old['start_date']) {
        $errors['end_date'] = 'End date cannot be earlier than start date.';
    }
    if (!in_array($old['status'], $allowed_status, true)) {
        $errors['status'] = 'Pick a valid status.';
    }

    if ($errors === []) {
        try {
            $id = insertRecord('budgets', [
                'user_id'       => $uid,
                'name'          => $old['name'],
                'category'      => $old['category'],
                'budget_amount' => number_format((float) $amount_raw, 2, '.', ''),
                'spent_amount'  => '0.00',
                'start_date'    => $old['start_date'],
                'end_date'      => $old['end_date'] !== '' ? $old['end_date'] : null,
                'status'        => $old['status'],
            ]);
            log_audit('budget_created', 'budget', $id, ['name' => $old['name'], 'planned' => (float) $amount_raw]);
            flash('success', 'Budget created.');
            header('Location: ' . base_url('/budgets.php'));
            exit;
        } catch (Throwable $ex) {
            error_log('[budget-new] ' . $ex->getMessage());
            $errors['_general'] = 'Could not save the budget. Please try again.';
        }
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span><a href="budgets.php">Budgets</a></span><span>New</span></div>
    <div class="page-header"><div><h1 class="page-header__title">New budget</h1><p class="page-header__desc">Set a cap on a category for a date range.</p></div></div>

    <?php if (!empty($errors['_general'])): ?>
        <div class="flash flash--danger" role="alert"><span><?= e($errors['_general']) ?></span></div>
    <?php endif; ?>

    <section class="card"><div class="card__body">
        <form method="POST" action="budget-new.php" class="form" data-validate novalidate>
            <?= csrf_field() ?>
            <div class="form__row">
                <div class="field<?= isset($errors['name']) ? ' field--error' : '' ?>">
                    <label for="name">Name</label>
                    <input id="name" name="name" type="text" required value="<?= e($old['name']) ?>" placeholder="e.g. Marketing Q4">
                    <div class="error" role="alert"><?= e($errors['name'] ?? '') ?></div>
                </div>
                <div class="field<?= isset($errors['category']) ? ' field--error' : '' ?>">
                    <label for="category">Category</label>
                    <input id="category" name="category" type="text" required value="<?= e($old['category']) ?>" placeholder="e.g. Software, Meals">
                    <div class="error" role="alert"><?= e($errors['category'] ?? '') ?></div>
                </div>
            </div>
            <div class="form__row">
                <div class="field<?= isset($errors['budget_amount']) ? ' field--error' : '' ?>">
                    <label for="budget_amount">Planned amount</label>
                    <input id="budget_amount" name="budget_amount" type="number" step="0.01" min="0.01" required value="<?= e($old['budget_amount']) ?>">
                    <div class="error" role="alert"><?= e($errors['budget_amount'] ?? '') ?></div>
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
            <div class="form__row">
                <div class="field<?= isset($errors['start_date']) ? ' field--error' : '' ?>">
                    <label for="start_date">Start date</label>
                    <input id="start_date" name="start_date" type="date" required value="<?= e($old['start_date']) ?>">
                    <div class="error" role="alert"><?= e($errors['start_date'] ?? '') ?></div>
                </div>
                <div class="field<?= isset($errors['end_date']) ? ' field--error' : '' ?>">
                    <label for="end_date">End date <span class="text-soft">(optional)</span></label>
                    <input id="end_date" name="end_date" type="date" value="<?= e($old['end_date']) ?>">
                    <div class="error" role="alert"><?= e($errors['end_date'] ?? '') ?></div>
                </div>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn btn--primary">Save budget</button>
                <a class="btn btn--ghost" href="budgets.php">Cancel</a>
            </div>
        </form>
    </div></section>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
