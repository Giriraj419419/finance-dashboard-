<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/audit.php';
requireRole('admin', 'manager', 'employee');

$page_title = 'New purchase order';
$uid = (int) currentUser()['id'];

$allowed_status = ['draft','submitted','open','approved','ordered','received','closed','cancelled'];
$errors = [];
$old = [
    'order_number' => 'PO-' . date('Y') . '-' . str_pad((string) mt_rand(1, 9999), 4, '0', STR_PAD_LEFT),
    'supplier_name' => '', 'supplier_email' => '', 'supplier_phone' => '',
    'order_date' => date('Y-m-d'), 'expected_date' => '',
    'status' => 'draft', 'notes' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_or_die();
    foreach (array_keys($old) as $k) { $old[$k] = trim((string) ($_POST[$k] ?? '')); }

    if ($old['order_number'] === '' || mb_strlen($old['order_number']) > 60) { $errors['order_number'] = 'PO number is required (max 60 characters).'; }
    if ($old['supplier_name'] === '' || mb_strlen($old['supplier_name']) > 200) { $errors['supplier_name'] = 'Supplier name is required.'; }
    if ($old['supplier_email'] !== '' && !filter_var($old['supplier_email'], FILTER_VALIDATE_EMAIL)) { $errors['supplier_email'] = 'Enter a valid email.'; }
    if (mb_strlen($old['supplier_phone']) > 40) { $errors['supplier_phone'] = 'Phone is too long.'; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['order_date'])) { $errors['order_date'] = 'Enter a valid order date.'; }
    if ($old['expected_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['expected_date'])) { $errors['expected_date'] = 'Enter a valid expected date.'; }
    if (empty($errors['order_date']) && empty($errors['expected_date']) && $old['expected_date'] !== '' && $old['expected_date'] < $old['order_date']) {
        $errors['expected_date'] = 'Expected date cannot be earlier than order date.';
    }
    if (!in_array($old['status'], $allowed_status, true)) { $errors['status'] = 'Pick a valid status.'; }

    if ($errors === []) {
        try {
            // Check PO number uniqueness before insert (fail-fast rather than relying on UNIQUE error).
            $dup = fetchOne('SELECT id FROM purchase_orders WHERE order_number = :n LIMIT 1', [':n' => $old['order_number']]);
            if ($dup) {
                $errors['order_number'] = 'That PO number is already in use.';
            } else {
                $id = insertRecord('purchase_orders', [
                    'user_id'        => $uid,
                    'supplier_name'  => $old['supplier_name'],
                    'supplier_email' => $old['supplier_email'] === '' ? null : $old['supplier_email'],
                    'supplier_phone' => $old['supplier_phone'] === '' ? null : $old['supplier_phone'],
                    'order_number'   => $old['order_number'],
                    'order_date'     => $old['order_date'],
                    'expected_date'  => $old['expected_date'] !== '' ? $old['expected_date'] : null,
                    'subtotal'       => '0.00',
                    'tax_amount'     => '0.00',
                    'total_amount'   => '0.00',
                    'status'         => $old['status'],
                    'notes'          => $old['notes'] === '' ? null : $old['notes'],
                ]);
                log_audit('po_created', 'purchase_order', $id, ['order_number' => $old['order_number']]);
                flash('success', 'Purchase order created — add items next.');
                header('Location: ' . base_url('/po-edit.php?id=' . $id)); exit;
            }
        } catch (Throwable $ex) {
            error_log('[po-new] ' . $ex->getMessage());
            $errors['_general'] = 'Could not save the purchase order. Please try again.';
        }
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span><a href="purchase-orders.php">Purchase Orders</a></span><span>New</span></div>
    <div class="page-header"><div><h1 class="page-header__title">New purchase order</h1><p class="page-header__desc">Create the header, then add line items on the next screen.</p></div></div>
    <?php if (!empty($errors['_general'])): ?>
        <div class="flash flash--danger" role="alert"><span><?= e($errors['_general']) ?></span></div>
    <?php endif; ?>
    <section class="card"><div class="card__body">
        <form method="POST" action="po-new.php" class="form" data-validate novalidate>
            <?= csrf_field() ?>
            <div class="form__row">
                <div class="field<?= isset($errors['order_number']) ? ' field--error' : '' ?>">
                    <label for="order_number">PO number</label>
                    <input id="order_number" name="order_number" type="text" required value="<?= e($old['order_number']) ?>">
                    <div class="error" role="alert"><?= e($errors['order_number'] ?? '') ?></div>
                </div>
                <div class="field<?= isset($errors['status']) ? ' field--error' : '' ?>">
                    <label for="status">Status</label>
                    <select id="status" name="status" required>
                        <?php foreach ($allowed_status as $s): ?><option value="<?= e($s) ?>" <?= $old['status'] === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?>
                    </select>
                    <div class="error" role="alert"><?= e($errors['status'] ?? '') ?></div>
                </div>
            </div>
            <div class="field<?= isset($errors['supplier_name']) ? ' field--error' : '' ?>">
                <label for="supplier_name">Supplier name</label>
                <input id="supplier_name" name="supplier_name" type="text" required value="<?= e($old['supplier_name']) ?>">
                <div class="error" role="alert"><?= e($errors['supplier_name'] ?? '') ?></div>
            </div>
            <div class="form__row">
                <div class="field<?= isset($errors['supplier_email']) ? ' field--error' : '' ?>">
                    <label for="supplier_email">Supplier email <span class="text-soft">(optional)</span></label>
                    <input id="supplier_email" name="supplier_email" type="email" value="<?= e($old['supplier_email']) ?>">
                    <div class="error" role="alert"><?= e($errors['supplier_email'] ?? '') ?></div>
                </div>
                <div class="field<?= isset($errors['supplier_phone']) ? ' field--error' : '' ?>">
                    <label for="supplier_phone">Supplier phone <span class="text-soft">(optional)</span></label>
                    <input id="supplier_phone" name="supplier_phone" type="text" value="<?= e($old['supplier_phone']) ?>">
                    <div class="error" role="alert"><?= e($errors['supplier_phone'] ?? '') ?></div>
                </div>
            </div>
            <div class="form__row">
                <div class="field<?= isset($errors['order_date']) ? ' field--error' : '' ?>">
                    <label for="order_date">Order date</label>
                    <input id="order_date" name="order_date" type="date" required value="<?= e($old['order_date']) ?>">
                    <div class="error" role="alert"><?= e($errors['order_date'] ?? '') ?></div>
                </div>
                <div class="field<?= isset($errors['expected_date']) ? ' field--error' : '' ?>">
                    <label for="expected_date">Expected date</label>
                    <input id="expected_date" name="expected_date" type="date" value="<?= e($old['expected_date']) ?>">
                    <div class="error" role="alert"><?= e($errors['expected_date'] ?? '') ?></div>
                </div>
            </div>
            <div class="field">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" rows="3"><?= e($old['notes']) ?></textarea>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn btn--primary">Create PO</button>
                <a class="btn btn--ghost" href="purchase-orders.php">Cancel</a>
            </div>
        </form>
    </div></section>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
