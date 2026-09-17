<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/audit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . base_url('/payments.php')); exit; }
csrf_check_or_die();

$me   = currentUser();
$uid  = (int) $me['id'];
$role = (string) ($me['role'] ?? 'employee');
$id   = (int) ($_POST['id'] ?? 0);
if ($id <= 0) { flash('danger', 'Invalid payment.'); header('Location: ' . base_url('/payments.php')); exit; }

try {
    $p = fetchOne('SELECT id, user_id, title, amount, due_date, status FROM payments WHERE id = :id LIMIT 1', [':id' => $id]);
    if (!$p) { flash('danger', 'Payment not found.'); header('Location: ' . base_url('/payments.php')); exit; }
    if ((int) $p['user_id'] !== $uid && $role !== 'admin') {
        log_audit('access_denied', 'payment', $id, ['reason' => 'not_owner', 'op' => 'delete']);
        http_response_code(403); require __DIR__ . '/403.php'; exit;
    }
    executeQuery('DELETE FROM payments WHERE id = :id', [':id' => $id]);
    log_audit('payment_deleted', 'payment', $id, ['was' => [
        'title' => $p['title'], 'amount' => (float) $p['amount'], 'due_date' => $p['due_date'], 'status' => $p['status'],
    ]]);
    flash('success', 'Payment deleted.');
} catch (Throwable $e) {
    error_log('[payment-delete] ' . $e->getMessage());
    flash('danger', 'Could not delete the payment.');
}
header('Location: ' . base_url('/payments.php')); exit;
