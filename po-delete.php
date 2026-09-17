<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/audit.php';
requireRole('admin', 'manager', 'employee');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . base_url('/purchase-orders.php')); exit; }
csrf_check_or_die();

$me   = currentUser();
$uid  = (int) $me['id'];
$role = (string) ($me['role'] ?? 'employee');
$id   = (int) ($_POST['id'] ?? 0);
if ($id <= 0) { flash('danger', 'Invalid purchase order.'); header('Location: ' . base_url('/purchase-orders.php')); exit; }

try {
    $po = fetchOne('SELECT id, user_id, order_number, supplier_name, total_amount, status FROM purchase_orders WHERE id = :id LIMIT 1', [':id' => $id]);
    if (!$po) { flash('danger', 'Purchase order not found.'); header('Location: ' . base_url('/purchase-orders.php')); exit; }
    $can_delete = (int) $po['user_id'] === $uid || in_array($role, ['admin','manager'], true);
    if (!$can_delete) {
        log_audit('access_denied', 'purchase_order', $id, ['reason' => 'not_owner', 'op' => 'delete']);
        http_response_code(403); require __DIR__ . '/403.php'; exit;
    }
    // Items cascade-delete via FK.
    executeQuery('DELETE FROM purchase_orders WHERE id = :id', [':id' => $id]);
    log_audit('po_deleted', 'purchase_order', $id, ['was' => [
        'order_number' => $po['order_number'], 'supplier' => $po['supplier_name'],
        'total' => (float) $po['total_amount'], 'status' => $po['status'],
    ]]);
    flash('success', 'Purchase order deleted.');
} catch (Throwable $e) {
    error_log('[po-delete] ' . $e->getMessage());
    flash('danger', 'Could not delete the purchase order.');
}
header('Location: ' . base_url('/purchase-orders.php')); exit;
