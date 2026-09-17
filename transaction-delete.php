<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/audit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . base_url('/transactions.php'));
    exit;
}

csrf_check_or_die();

$me   = currentUser();
$uid  = (int) $me['id'];
$role = (string) ($me['role'] ?? 'employee');

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    flash('danger', 'Invalid transaction reference.');
    header('Location: ' . base_url('/transactions.php'));
    exit;
}

try {
    $txn = fetchOne(
        'SELECT id, user_id, transaction_date, type, category, amount FROM transactions WHERE id = :id LIMIT 1',
        [':id' => $id]
    );
    if (!$txn) {
        flash('danger', 'Transaction not found.');
        header('Location: ' . base_url('/transactions.php'));
        exit;
    }

    $can_delete = ((int) $txn['user_id']) === $uid || $role === 'admin';
    if (!$can_delete) {
        log_audit('access_denied', 'transaction', $id, ['reason' => 'not_owner', 'op' => 'delete']);
        http_response_code(403);
        require __DIR__ . '/403.php';
        exit;
    }

    executeQuery('DELETE FROM transactions WHERE id = :id', [':id' => $id]);
    // Snapshot deleted values into the audit trail so the record is recoverable in intent.
    log_audit('transaction_deleted', 'transaction', $id, [
        'was' => [
            'transaction_date' => $txn['transaction_date'],
            'type'             => $txn['type'],
            'category'         => $txn['category'],
            'amount'           => (float) $txn['amount'],
        ],
    ]);
    flash('success', 'Transaction deleted.');
} catch (Throwable $e) {
    error_log('[transaction-delete] ' . $e->getMessage());
    flash('danger', 'Could not delete the transaction.');
}

header('Location: ' . base_url('/transactions.php'));
exit;
