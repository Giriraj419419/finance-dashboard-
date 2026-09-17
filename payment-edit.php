<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/audit.php';

$page_title = 'Edit payment';
$me = currentUser();
$uid = (int) $me['id'];
$role = (string) ($me['role'] ?? 'employee');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) { header('Location: ' . base_url('/payments.php')); exit; }

try {
    $p = fetchOne(
        'SELECT id, user_id, title, amount, due_date, payment_date, payment_method, status, notes
         FROM payments WHERE id = :id LIMIT 1',
        [':id' => $id]
    );
} catch (Throwable $e) {
    error_log('[payment-edit:load] ' . $e->getMessage());
    flash('danger', 'Could not load that payment.');
    header('Location: ' . base_url('/payments.php')); exit;
}
if (!$p) { flash('danger', 'Payment not found.'); header('Location: ' . base_url('/payments.php')); exit; }
if ((int) $p['user_id'] !== $uid && $role !== 'admin') {
    log_audit('access_denied', 'payment', $id, ['reason' => 'not_owner']);
    http_response_code(403); require __DIR__ . '/403.php'; exit;
}

$allowed_methods = ['cash', 'card', 'bank_transfer', 'ach', 'wire', 'upi', 'other'];
$allowed_status  = ['scheduled', 'paid', 'overdue', 'cancelled'];

$errors = [];
$old = [
    'title' => $p['title'], 'amount' => $p['amount'], 'due_date' => $p['due_date'],
    'payment_date' => $p['payment_date'] ?? '', 'payment_method' => $p['payment_method'],
    'status' => $p['status'], 'notes' => $p['notes'] ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_or_die();
    foreach (array_keys($old) as $k) { $old[$k] = trim((string) ($_POST[$k] ?? '')); }

    if ($old['title'] === '' || mb_strlen($old['title']) > 200)      { $errors['title']   = 'Title is required (max 200 characters).'; }
    $amount_raw = str_replace([',', ' '], '', $old['amount']);
    if (!is_numeric($amount_raw) || (float) $amount_raw <= 0)        { $errors['amount']  = 'Enter a positive amount.'; }
    elseif ((float) $amount_raw > 9999999999.99)                     { $errors['amount']  = 'Amount is too large.'; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['due_date']))      { $errors['due_date'] = 'Enter a valid due date.'; }
    if ($old['payment_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['payment_date'])) {
        $errors['payment_date'] = 'Enter a valid payment date.';
    }
    if (!in_array($old['payment_method'], $allowed_methods, true))   { $errors['payment_method'] = 'Pick a valid method.'; }
    if (!in_array($old['status'], $allowed_status, true))            { $errors['status'] = 'Pick a valid status.'; }
    if (mb_strlen($old['notes']) > 65535)                            { $errors['notes'] = 'Notes are too long.'; }
    if ($old['status'] === 'paid' && $old['payment_date'] === '')    { $old['payment_date'] = $old['due_date']; }

    if ($errors === []) {
        try {
            updateRecord('payments', [
                'title'          => $old['title'],
                'amount'         => number_format((float) $amount_raw, 2, '.', ''),
                'due_date'       => $old['due_date'],
                'payment_date'   => $old['payment_date'] !== '' ? $old['payment_date'] : null,
                'payment_method' => $old['payment_method'],
                'status'         => $old['status'],
                'notes'          => $old['notes'] === '' ? null : $old['notes'],
            ], ['id' => $id]);
            log_audit('payment_updated', 'payment', $id, ['title' => $old['title'], 'status' => $old['status']]);
            flash('success', 'Payment updated.');
            header('Location: ' . base_url('/payments.php')); exit;
        } catch (Throwable $ex) {
            error_log('[payment-edit] ' . $ex->getMessage());
            $errors['_general'] = 'Could not update the payment. Please try again.';
        }
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span><a href="payments.php">Payments</a></span><span>Edit</span></div>
    <div class="page-header"><div><h1 class="page-header__title">Edit payment</h1></div></div>
    <?php if (!empty($errors['_general'])): ?>
        <div class="flash flash--danger" role="alert"><span><?= e($errors['_general']) ?></span></div>
    <?php endif; ?>
    <section class="card"><div class="card__body">
        <form method="POST" action="payment-edit.php" class="form" data-validate novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $id ?>">
            <div class="field<?= isset($errors['title']) ? ' field--error' : '' ?>">
                <label for="title">Title</label>
                <input id="title" name="title" type="text" required value="<?= e($old['title']) ?>">
                <div class="error" role="alert"><?= e($errors['title'] ?? '') ?></div>
            </div>
            <div class="form__row">
                <div class="field<?= isset($errors['amount']) ? ' field--error' : '' ?>">
                    <label for="amount">Amount</label>
                    <input id="amount" name="amount" type="number" step="0.01" min="0.01" required value="<?= e($old['amount']) ?>">
                    <div class="error" role="alert"><?= e($errors['amount'] ?? '') ?></div>
                </div>
                <div class="field<?= isset($errors['due_date']) ? ' field--error' : '' ?>">
                    <label for="due_date">Due date</label>
                    <input id="due_date" name="due_date" type="date" required value="<?= e($old['due_date']) ?>">
                    <div class="error" role="alert"><?= e($errors['due_date'] ?? '') ?></div>
                </div>
            </div>
            <div class="form__row">
                <div class="field<?= isset($errors['payment_date']) ? ' field--error' : '' ?>">
                    <label for="payment_date">Payment date <span class="text-soft">(when paid)</span></label>
                    <input id="payment_date" name="payment_date" type="date" value="<?= e($old['payment_date']) ?>">
                    <div class="error" role="alert"><?= e($errors['payment_date'] ?? '') ?></div>
                </div>
                <div class="field<?= isset($errors['payment_method']) ? ' field--error' : '' ?>">
                    <label for="payment_method">Payment method</label>
                    <select id="payment_method" name="payment_method" required>
                        <?php foreach ($allowed_methods as $m): ?>
                            <option value="<?= e($m) ?>" <?= $old['payment_method'] === $m ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $m))) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="error" role="alert"><?= e($errors['payment_method'] ?? '') ?></div>
                </div>
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
            <div class="field<?= isset($errors['notes']) ? ' field--error' : '' ?>">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" rows="2"><?= e($old['notes']) ?></textarea>
                <div class="error" role="alert"><?= e($errors['notes'] ?? '') ?></div>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn btn--primary">Save changes</button>
                <a class="btn btn--ghost" href="payments.php">Cancel</a>
            </div>
        </form>
    </div></section>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
