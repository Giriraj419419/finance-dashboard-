<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/audit.php';

$page_title = 'New transaction';
$me = currentUser();
$uid = (int) $me['id'];

$defaults = [
    'type' => 'expense',
    'category' => '',
    'amount' => '',
    'description' => '',
    'transaction_date' => date('Y-m-d'),
    'payment_method' => 'bank_transfer',
    'status' => 'completed',
];
$old = $defaults;
$errors = [];

$allowed_methods = ['cash', 'card', 'bank_transfer', 'ach', 'wire', 'upi', 'other'];
$allowed_status  = ['pending', 'completed', 'failed', 'cancelled'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_or_die();

    foreach (array_keys($defaults) as $k) {
        $old[$k] = trim((string) ($_POST[$k] ?? ''));
    }

    if (!in_array($old['type'], ['income', 'expense'], true)) {
        $errors['type'] = 'Pick income or expense.';
    }
    if ($old['category'] === '') {
        $errors['category'] = 'Category is required.';
    } elseif (mb_strlen($old['category']) > 120) {
        $errors['category'] = 'Keep the category under 120 characters.';
    }
    $amount_raw = str_replace([',', ' '], '', $old['amount']);
    if (!is_numeric($amount_raw) || (float) $amount_raw <= 0) {
        $errors['amount'] = 'Enter a positive amount.';
    } elseif ((float) $amount_raw > 9999999999.99) {
        $errors['amount'] = 'Amount is too large.';
    }
    if ($old['description'] !== '' && mb_strlen($old['description']) > 255) {
        $errors['description'] = 'Keep the description under 255 characters.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['transaction_date'])) {
        $errors['transaction_date'] = 'Enter a valid date.';
    }
    if (!in_array($old['payment_method'], $allowed_methods, true)) {
        $errors['payment_method'] = 'Pick a valid method.';
    }
    if (!in_array($old['status'], $allowed_status, true)) {
        $errors['status'] = 'Pick a valid status.';
    }

    if ($errors === []) {
        try {
            $id = insertRecord('transactions', [
                'user_id'          => $uid,
                'type'             => $old['type'],
                'category'         => $old['category'],
                'amount'           => number_format((float) $amount_raw, 2, '.', ''),
                'description'      => $old['description'] === '' ? null : $old['description'],
                'transaction_date' => $old['transaction_date'],
                'payment_method'   => $old['payment_method'],
                'status'           => $old['status'],
            ]);
            log_audit('transaction_created', 'transaction', $id, [
                'type'   => $old['type'],
                'amount' => (float) $amount_raw,
            ]);
            flash('success', 'Transaction added.');
            header('Location: ' . base_url('/transactions.php'));
            exit;
        } catch (Throwable $ex) {
            error_log('[transaction-new] ' . $ex->getMessage());
            $errors['_general'] = 'Could not save the transaction. Please try again.';
        }
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span><a href="transactions.php">Transactions</a></span><span>New</span></div>
    <div class="page-header">
        <div>
            <h1 class="page-header__title">New transaction</h1>
            <p class="page-header__desc">Record income or an expense against your account.</p>
        </div>
    </div>

    <?php if (!empty($errors['_general'])): ?>
        <div class="flash flash--danger" role="alert"><span><?= e($errors['_general']) ?></span></div>
    <?php endif; ?>

    <section class="card">
        <div class="card__body">
            <form method="POST" action="transaction-new.php" class="form" data-validate novalidate>
                <?= csrf_field() ?>
                <div class="form__row">
                    <div class="field<?= isset($errors['type']) ? ' field--error' : '' ?>">
                        <label for="type">Type</label>
                        <select id="type" name="type" required>
                            <option value="expense" <?= $old['type'] === 'expense' ? 'selected' : '' ?>>Expense</option>
                            <option value="income"  <?= $old['type'] === 'income'  ? 'selected' : '' ?>>Income</option>
                        </select>
                        <div class="error" role="alert"><?= e($errors['type'] ?? '') ?></div>
                    </div>
                    <div class="field<?= isset($errors['category']) ? ' field--error' : '' ?>">
                        <label for="category">Category</label>
                        <input id="category" name="category" type="text" required value="<?= e($old['category']) ?>" placeholder="e.g. Rent, Salary, Software">
                        <div class="error" role="alert"><?= e($errors['category'] ?? '') ?></div>
                    </div>
                </div>
                <div class="form__row">
                    <div class="field<?= isset($errors['amount']) ? ' field--error' : '' ?>">
                        <label for="amount">Amount</label>
                        <input id="amount" name="amount" type="number" step="0.01" min="0.01" required value="<?= e($old['amount']) ?>">
                        <div class="error" role="alert"><?= e($errors['amount'] ?? '') ?></div>
                    </div>
                    <div class="field<?= isset($errors['transaction_date']) ? ' field--error' : '' ?>">
                        <label for="transaction_date">Date</label>
                        <input id="transaction_date" name="transaction_date" type="date" required value="<?= e($old['transaction_date']) ?>">
                        <div class="error" role="alert"><?= e($errors['transaction_date'] ?? '') ?></div>
                    </div>
                </div>
                <div class="field<?= isset($errors['description']) ? ' field--error' : '' ?>">
                    <label for="description">Description</label>
                    <input id="description" name="description" type="text" value="<?= e($old['description']) ?>" placeholder="Optional note about this transaction">
                    <div class="error" role="alert"><?= e($errors['description'] ?? '') ?></div>
                </div>
                <div class="form__row">
                    <div class="field<?= isset($errors['payment_method']) ? ' field--error' : '' ?>">
                        <label for="payment_method">Payment method</label>
                        <select id="payment_method" name="payment_method" required>
                            <?php foreach ($allowed_methods as $m): ?>
                                <option value="<?= e($m) ?>" <?= $old['payment_method'] === $m ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $m))) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="error" role="alert"><?= e($errors['payment_method'] ?? '') ?></div>
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
                    <button type="submit" class="btn btn--primary">Save transaction</button>
                    <a class="btn btn--ghost" href="transactions.php">Cancel</a>
                </div>
            </form>
        </div>
    </section>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
