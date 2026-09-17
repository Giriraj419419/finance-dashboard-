<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/audit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . base_url('/goals.php')); exit; }
csrf_check_or_die();

$me   = currentUser();
$uid  = (int) $me['id'];
$role = (string) ($me['role'] ?? 'employee');
$id   = (int) ($_POST['id'] ?? 0);
if ($id <= 0) { flash('danger', 'Invalid goal.'); header('Location: ' . base_url('/goals.php')); exit; }

try {
    $g = fetchOne('SELECT id, user_id, name, target_amount, current_amount FROM goals WHERE id = :id LIMIT 1', [':id' => $id]);
    if (!$g) { flash('danger', 'Goal not found.'); header('Location: ' . base_url('/goals.php')); exit; }
    if ((int) $g['user_id'] !== $uid && $role !== 'admin') {
        log_audit('access_denied', 'goal', $id, ['reason' => 'not_owner', 'op' => 'delete']);
        http_response_code(403); require __DIR__ . '/403.php'; exit;
    }
    // Contributions cascade-delete via FK ON DELETE CASCADE.
    executeQuery('DELETE FROM goals WHERE id = :id', [':id' => $id]);
    log_audit('goal_deleted', 'goal', $id, ['was' => ['name' => $g['name'], 'target' => (float) $g['target_amount'], 'current' => (float) $g['current_amount']]]);
    flash('success', 'Goal deleted.');
} catch (Throwable $e) {
    error_log('[goal-delete] ' . $e->getMessage());
    flash('danger', 'Could not delete the goal.');
}
header('Location: ' . base_url('/goals.php')); exit;
