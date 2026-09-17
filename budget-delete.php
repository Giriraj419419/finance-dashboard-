<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/audit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . base_url('/budgets.php')); exit;
}
csrf_check_or_die();

$me   = currentUser();
$uid  = (int) $me['id'];
$role = (string) ($me['role'] ?? 'employee');
$id   = (int) ($_POST['id'] ?? 0);
if ($id <= 0) { flash('danger', 'Invalid budget.'); header('Location: ' . base_url('/budgets.php')); exit; }

try {
    $b = fetchOne('SELECT id, user_id, name, category, budget_amount FROM budgets WHERE id = :id LIMIT 1', [':id' => $id]);
    if (!$b) { flash('danger', 'Budget not found.'); header('Location: ' . base_url('/budgets.php')); exit; }
    if ((int) $b['user_id'] !== $uid && $role !== 'admin') {
        log_audit('access_denied', 'budget', $id, ['reason' => 'not_owner', 'op' => 'delete']);
        http_response_code(403); require __DIR__ . '/403.php'; exit;
    }
    executeQuery('DELETE FROM budgets WHERE id = :id', [':id' => $id]);
    log_audit('budget_deleted', 'budget', $id, ['was' => ['name' => $b['name'], 'category' => $b['category'], 'planned' => (float) $b['budget_amount']]]);
    flash('success', 'Budget deleted.');
} catch (Throwable $e) {
    error_log('[budget-delete] ' . $e->getMessage());
    flash('danger', 'Could not delete the budget.');
}
header('Location: ' . base_url('/budgets.php')); exit;
