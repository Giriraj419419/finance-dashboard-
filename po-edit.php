<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/audit.php';
requireRole('admin', 'manager', 'employee');

$page_title = 'Edit purchase order';
$me   = currentUser();
$uid  = (int) $me['id'];
$role = (string) ($me['role'] ?? 'employee');
$id   = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) { header('Location: ' . base_url('/purchase-orders.php')); exit; }

$allowed_status = ['draft','submitted','open','approved','ordered','received','closed','cancelled'];

/** Load PO + items and enforce ownership. */
function load_po_with_items(int $id): ?array
{
    $po = fetchOne(
        'SELECT id, user_id, order_number, supplier_name, supplier_email, supplier_phone,
                order_date, expected_date, subtotal, tax_amount, total_amount, status, notes
         FROM purchase_orders WHERE id = :id LIMIT 1',
        [':id' => $id]
    );
    if (!$po) return null;
    $items = fetchAll(
        'SELECT id, item_name, description, quantity, unit_price, tax_rate, total_price
         FROM purchase_order_items WHERE purchase_order_id = :id ORDER BY id ASC',
        [':id' => $id]
    );
    $po['items'] = $items;
    return $po;
}

/** Recalculate subtotal / tax_amount / total_amount from items. */
function po_recompute_totals(int $po_id): array
{
    $items = fetchAll('SELECT quantity, unit_price, tax_rate FROM purchase_order_items WHERE purchase_order_id = :id', [':id' => $po_id]);
    $subtotal = 0.0; $tax = 0.0;
    foreach ($items as $it) {
        $line = (float) $it['quantity'] * (float) $it['unit_price'];
        $subtotal += $line;
        $tax      += $line * ((float) $it['tax_rate'] / 100.0);
    }
    $total = $subtotal + $tax;
    executeQuery(
        'UPDATE purchase_orders SET subtotal = :s, tax_amount = :tx, total_amount = :t WHERE id = :id',
        [':s' => number_format($subtotal, 2, '.', ''), ':tx' => number_format($tax, 2, '.', ''), ':t' => number_format($total, 2, '.', ''), ':id' => $po_id]
    );
    return ['subtotal' => $subtotal, 'tax_amount' => $tax, 'total_amount' => $total];
}

try {
    $po = load_po_with_items($id);
} catch (Throwable $e) {
    error_log('[po-edit:load] ' . $e->getMessage());
    flash('danger', 'Could not load that purchase order.');
    header('Location: ' . base_url('/purchase-orders.php')); exit;
}
if (!$po) { flash('danger', 'Purchase order not found.'); header('Location: ' . base_url('/purchase-orders.php')); exit; }
$can_manage = (int) $po['user_id'] === $uid || in_array($role, ['admin','manager'], true);
if (!$can_manage) {
    log_audit('access_denied', 'purchase_order', $id, ['reason' => 'not_owner']);
    http_response_code(403); require __DIR__ . '/403.php'; exit;
}

$errors = [];
$item_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_or_die();
    $action = (string) ($_POST['_action'] ?? 'header');

    if ($action === 'header') {
        $upd = [
            'supplier_name'  => trim((string) ($_POST['supplier_name']  ?? '')),
            'supplier_email' => trim((string) ($_POST['supplier_email'] ?? '')),
            'supplier_phone' => trim((string) ($_POST['supplier_phone'] ?? '')),
            'order_number'   => trim((string) ($_POST['order_number']   ?? '')),
            'order_date'     => trim((string) ($_POST['order_date']     ?? '')),
            'expected_date'  => trim((string) ($_POST['expected_date']  ?? '')),
            'status'         => trim((string) ($_POST['status']         ?? '')),
            'notes'          => trim((string) ($_POST['notes']          ?? '')),
        ];
        if ($upd['order_number'] === '' || mb_strlen($upd['order_number']) > 60) { $errors['order_number'] = 'PO number is required (max 60 characters).'; }
        if ($upd['supplier_name'] === '' || mb_strlen($upd['supplier_name']) > 200) { $errors['supplier_name'] = 'Supplier name is required.'; }
        if ($upd['supplier_email'] !== '' && !filter_var($upd['supplier_email'], FILTER_VALIDATE_EMAIL)) { $errors['supplier_email'] = 'Enter a valid email.'; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $upd['order_date'])) { $errors['order_date'] = 'Enter a valid order date.'; }
        if ($upd['expected_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $upd['expected_date'])) { $errors['expected_date'] = 'Enter a valid expected date.'; }
        if (empty($errors['order_date']) && empty($errors['expected_date']) && $upd['expected_date'] !== '' && $upd['expected_date'] < $upd['order_date']) {
            $errors['expected_date'] = 'Expected date cannot be earlier than order date.';
        }
        if (!in_array($upd['status'], $allowed_status, true)) { $errors['status'] = 'Pick a valid status.'; }
        if ($errors === []) {
            try {
                $dup = fetchOne('SELECT id FROM purchase_orders WHERE order_number = :n AND id <> :id LIMIT 1', [':n' => $upd['order_number'], ':id' => $id]);
                if ($dup) {
                    $errors['order_number'] = 'That PO number is already in use.';
                } else {
                    $prev_status = $po['status'];
                    updateRecord('purchase_orders', [
                        'supplier_name'  => $upd['supplier_name'],
                        'supplier_email' => $upd['supplier_email'] === '' ? null : $upd['supplier_email'],
                        'supplier_phone' => $upd['supplier_phone'] === '' ? null : $upd['supplier_phone'],
                        'order_number'   => $upd['order_number'],
                        'order_date'     => $upd['order_date'],
                        'expected_date'  => $upd['expected_date'] !== '' ? $upd['expected_date'] : null,
                        'status'         => $upd['status'],
                        'notes'          => $upd['notes'] === '' ? null : $upd['notes'],
                    ], ['id' => $id]);
                    if ($prev_status !== $upd['status']) {
                        log_audit('po_status_changed', 'purchase_order', $id, ['from' => $prev_status, 'to' => $upd['status']]);
                    } else {
                        log_audit('po_updated', 'purchase_order', $id, ['order_number' => $upd['order_number']]);
                    }
                    flash('success', 'Purchase order updated.');
                    header('Location: ' . base_url('/po-edit.php?id=' . $id)); exit;
                }
            } catch (Throwable $ex) {
                error_log('[po-edit:header] ' . $ex->getMessage());
                $errors['_general'] = 'Could not save. Please try again.';
            }
        }
    } elseif ($action === 'add_item') {
        $item = [
            'item_name'   => trim((string) ($_POST['item_name']   ?? '')),
            'description' => trim((string) ($_POST['description'] ?? '')),
            'quantity'    => trim((string) ($_POST['quantity']    ?? '')),
            'unit_price'  => trim((string) ($_POST['unit_price']  ?? '')),
            'tax_rate'    => trim((string) ($_POST['tax_rate']    ?? '0')),
        ];
        if ($item['item_name'] === '' || mb_strlen($item['item_name']) > 200) { $item_errors['item_name'] = 'Item name is required.'; }
        if (mb_strlen($item['description']) > 255) { $item_errors['description'] = 'Description is too long.'; }
        $q_raw  = str_replace([',', ' '], '', $item['quantity']);
        $p_raw  = str_replace([',', ' '], '', $item['unit_price']);
        $tx_raw = str_replace([',', ' '], '', $item['tax_rate']);
        if (!is_numeric($q_raw)  || (float) $q_raw  <= 0)   { $item_errors['quantity']   = 'Quantity must be positive.'; }
        if (!is_numeric($p_raw)  || (float) $p_raw  < 0)    { $item_errors['unit_price'] = 'Unit price cannot be negative.'; }
        if (!is_numeric($tx_raw) || (float) $tx_raw < 0 || (float) $tx_raw > 100) { $item_errors['tax_rate']   = 'Tax rate must be between 0 and 100.'; }

        if ($item_errors === []) {
            $pdo = getDatabaseConnection();
            try {
                $pdo->beginTransaction();
                $line_total = (float) $q_raw * (float) $p_raw;
                insertRecord('purchase_order_items', [
                    'purchase_order_id' => $id,
                    'item_name'         => $item['item_name'],
                    'description'       => $item['description'] === '' ? null : $item['description'],
                    'quantity'          => number_format((float) $q_raw, 2, '.', ''),
                    'unit_price'        => number_format((float) $p_raw, 2, '.', ''),
                    'tax_rate'          => number_format((float) $tx_raw, 2, '.', ''),
                    'total_price'       => number_format($line_total, 2, '.', ''),
                ]);
                po_recompute_totals($id);
                $pdo->commit();
                log_audit('po_item_added', 'purchase_order', $id, ['item' => $item['item_name']]);
                flash('success', 'Item added.');
                header('Location: ' . base_url('/po-edit.php?id=' . $id)); exit;
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('[po-edit:add_item] ' . $ex->getMessage());
                $item_errors['_general'] = 'Could not add the item.';
            }
        }
    } elseif ($action === 'remove_item') {
        $item_id = (int) ($_POST['item_id'] ?? 0);
        if ($item_id > 0) {
            $pdo = getDatabaseConnection();
            try {
                $pdo->beginTransaction();
                // Delete via PO scope guard.
                $del = $pdo->prepare('DELETE FROM purchase_order_items WHERE id = :iid AND purchase_order_id = :pid');
                $del->execute([':iid' => $item_id, ':pid' => $id]);
                po_recompute_totals($id);
                $pdo->commit();
                log_audit('po_item_removed', 'purchase_order', $id, ['item_id' => $item_id]);
                flash('success', 'Item removed.');
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('[po-edit:remove_item] ' . $ex->getMessage());
                flash('danger', 'Could not remove the item.');
            }
        }
        header('Location: ' . base_url('/po-edit.php?id=' . $id)); exit;
    }
    // Reload after any POST branch that fell through with errors.
    $po = load_po_with_items($id);
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span><a href="purchase-orders.php">Purchase Orders</a></span><span><?= e($po['order_number']) ?></span></div>
    <div class="page-header">
        <div><h1 class="page-header__title">PO <?= e($po['order_number']) ?></h1><p class="page-header__desc">Header, items, and totals.</p></div>
    </div>

    <?php if (!empty($errors['_general'])): ?>
        <div class="flash flash--danger" role="alert"><span><?= e($errors['_general']) ?></span></div>
    <?php endif; ?>

    <div class="dash-grid">
        <section class="card">
            <div class="card__header"><h2 class="card__title">Header</h2></div>
            <div class="card__body">
                <form method="POST" action="po-edit.php" class="form" data-validate novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="header">
                    <input type="hidden" name="id" value="<?= (int) $id ?>">
                    <div class="form__row">
                        <div class="field<?= isset($errors['order_number']) ? ' field--error' : '' ?>">
                            <label for="order_number">PO number</label>
                            <input id="order_number" name="order_number" type="text" required value="<?= e($po['order_number']) ?>">
                            <div class="error" role="alert"><?= e($errors['order_number'] ?? '') ?></div>
                        </div>
                        <div class="field<?= isset($errors['status']) ? ' field--error' : '' ?>">
                            <label for="status">Status</label>
                            <select id="status" name="status" required>
                                <?php foreach ($allowed_status as $s): ?>
                                    <option value="<?= e($s) ?>" <?= $po['status'] === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="error" role="alert"><?= e($errors['status'] ?? '') ?></div>
                        </div>
                    </div>
                    <div class="field<?= isset($errors['supplier_name']) ? ' field--error' : '' ?>">
                        <label for="supplier_name">Supplier</label>
                        <input id="supplier_name" name="supplier_name" type="text" required value="<?= e($po['supplier_name']) ?>">
                        <div class="error" role="alert"><?= e($errors['supplier_name'] ?? '') ?></div>
                    </div>
                    <div class="form__row">
                        <div class="field<?= isset($errors['supplier_email']) ? ' field--error' : '' ?>">
                            <label for="supplier_email">Supplier email</label>
                            <input id="supplier_email" name="supplier_email" type="email" value="<?= e($po['supplier_email'] ?? '') ?>">
                            <div class="error" role="alert"><?= e($errors['supplier_email'] ?? '') ?></div>
                        </div>
                        <div class="field">
                            <label for="supplier_phone">Supplier phone</label>
                            <input id="supplier_phone" name="supplier_phone" type="text" value="<?= e($po['supplier_phone'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form__row">
                        <div class="field<?= isset($errors['order_date']) ? ' field--error' : '' ?>">
                            <label for="order_date">Order date</label>
                            <input id="order_date" name="order_date" type="date" required value="<?= e($po['order_date']) ?>">
                            <div class="error" role="alert"><?= e($errors['order_date'] ?? '') ?></div>
                        </div>
                        <div class="field<?= isset($errors['expected_date']) ? ' field--error' : '' ?>">
                            <label for="expected_date">Expected date</label>
                            <input id="expected_date" name="expected_date" type="date" value="<?= e($po['expected_date'] ?? '') ?>">
                            <div class="error" role="alert"><?= e($errors['expected_date'] ?? '') ?></div>
                        </div>
                    </div>
                    <div class="field">
                        <label for="notes">Notes</label>
                        <textarea id="notes" name="notes" rows="3"><?= e($po['notes'] ?? '') ?></textarea>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="btn btn--primary">Save header</button>
                        <a class="btn btn--ghost" href="purchase-orders.php">Back</a>
                    </div>
                </form>
            </div>
        </section>

        <section class="card">
            <div class="card__header"><h2 class="card__title">Totals</h2></div>
            <div class="card__body">
                <div class="item-row"><div class="item-row__grow item-row__title">Subtotal</div><div><?= e(money((float) $po['subtotal'])) ?></div></div>
                <div class="item-row"><div class="item-row__grow item-row__title">Tax</div><div><?= e(money((float) $po['tax_amount'])) ?></div></div>
                <div class="item-row"><div class="item-row__grow item-row__title">Total</div><div class="amount--pos"><?= e(money((float) $po['total_amount'])) ?></div></div>
            </div>
        </section>
    </div>

    <section class="card mt-4">
        <div class="card__header"><h2 class="card__title">Line items</h2></div>
        <div class="card__body card__body--flush">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>Item</th><th>Description</th><th class="text-right">Qty</th><th class="text-right">Unit price</th><th class="text-right">Tax %</th><th class="text-right">Line total</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($po['items'])): ?>
                            <tr><td colspan="7"><div class="empty-state"><div class="empty-state__title">No items yet</div><div class="empty-state__desc">Add the first line item below.</div></div></td></tr>
                        <?php else: foreach ($po['items'] as $it): ?>
                            <tr>
                                <td><?= e($it['item_name']) ?></td>
                                <td class="text-muted"><?= e($it['description'] ?? '') ?></td>
                                <td class="text-right"><?= e(number_format((float) $it['quantity'], 2)) ?></td>
                                <td class="text-right"><?= e(money((float) $it['unit_price'])) ?></td>
                                <td class="text-right"><?= e(number_format((float) $it['tax_rate'], 2)) ?>%</td>
                                <td class="text-right"><?= e(money((float) $it['total_price'])) ?></td>
                                <td class="text-right">
                                    <form method="POST" action="po-edit.php" class="inline-form" onsubmit="return confirm('Remove this item?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="remove_item">
                                        <input type="hidden" name="id" value="<?= (int) $id ?>">
                                        <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                                        <button type="submit" class="btn btn--sm btn--danger">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="card mt-4">
        <div class="card__header"><h2 class="card__title">Add item</h2></div>
        <div class="card__body">
            <?php if (!empty($item_errors['_general'])): ?>
                <div class="flash flash--danger" role="alert"><span><?= e($item_errors['_general']) ?></span></div>
            <?php endif; ?>
            <form method="POST" action="po-edit.php" class="form" data-validate novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="add_item">
                <input type="hidden" name="id" value="<?= (int) $id ?>">
                <div class="form__row">
                    <div class="field<?= isset($item_errors['item_name']) ? ' field--error' : '' ?>">
                        <label for="item_name">Item name</label>
                        <input id="item_name" name="item_name" type="text" required>
                        <div class="error" role="alert"><?= e($item_errors['item_name'] ?? '') ?></div>
                    </div>
                    <div class="field<?= isset($item_errors['description']) ? ' field--error' : '' ?>">
                        <label for="description">Description</label>
                        <input id="description" name="description" type="text">
                        <div class="error" role="alert"><?= e($item_errors['description'] ?? '') ?></div>
                    </div>
                </div>
                <div class="form__row">
                    <div class="field<?= isset($item_errors['quantity']) ? ' field--error' : '' ?>">
                        <label for="quantity">Quantity</label>
                        <input id="quantity" name="quantity" type="number" step="0.01" min="0.01" required value="1">
                        <div class="error" role="alert"><?= e($item_errors['quantity'] ?? '') ?></div>
                    </div>
                    <div class="field<?= isset($item_errors['unit_price']) ? ' field--error' : '' ?>">
                        <label for="unit_price">Unit price</label>
                        <input id="unit_price" name="unit_price" type="number" step="0.01" min="0" required value="0.00">
                        <div class="error" role="alert"><?= e($item_errors['unit_price'] ?? '') ?></div>
                    </div>
                    <div class="field<?= isset($item_errors['tax_rate']) ? ' field--error' : '' ?>">
                        <label for="tax_rate">Tax %</label>
                        <input id="tax_rate" name="tax_rate" type="number" step="0.01" min="0" max="100" required value="0">
                        <div class="error" role="alert"><?= e($item_errors['tax_rate'] ?? '') ?></div>
                    </div>
                </div>
                <button type="submit" class="btn btn--primary">Add item</button>
            </form>
        </div>
    </section>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
