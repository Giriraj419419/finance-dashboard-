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
    $p = fetchOne('SELECT id, user_id, title, amount, status FROM payments WHERE id = :id LIMIT 1', [':id' => $id]);
    if (!$p) { flash('danger', 'Payment not found.'); header('Location: ' . base_url('/payments.php')); exit; }
    if ((int) $p['user_id'] !== $uid && $role !== 'admin') {
        log_audit('access_denied', 'payment', $id, ['reason' => 'not_owner', 'op' => 'mark_paid']);
        http_response_code(403); require __DIR__ . '/403.php'; exit;
    }
    if ($p['status'] === 'paid') {
        flash('info', 'Payment is already marked paid.');
        header('Location: ' . base_url('/payments.php')); exit;
    }

    executeQuery(
        "UPDATE payments
         SET status = 'paid', payment_date = COALESCE(payment_date, CURDATE())
         WHERE id = :id",
        [':id' => $id]
    );
    log_audit('payment_marked_paid', 'payment', $id, ['title' => $p['title'], 'amount' => (float) $p['amount']]);
    flash('success', 'Payment marked paid.');
} catch (Throwable $e) {
    error_log('[payment-mark-paid] ' . $e->getMessage());
    flash('danger', 'Could not mark the payment paid.');
}
header('Location: ' . base_url('/payments.php')); exit;
