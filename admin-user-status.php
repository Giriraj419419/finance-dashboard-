<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/audit.php';
requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . base_url('/admin-users.php')); exit; }
csrf_check_or_die();

$id = (int) ($_POST['id'] ?? 0);
$new_status = (string) ($_POST['status'] ?? '');
if ($id <= 0 || !in_array($new_status, ['active','inactive','suspended'], true)) {
    flash('danger', 'Invalid request.');
    header('Location: ' . base_url('/admin-users.php')); exit;
}

try {
    $u = fetchOne('SELECT id, role, status FROM users WHERE id = :id LIMIT 1', [':id' => $id]);
    if (!$u) { flash('danger', 'User not found.'); header('Location: ' . base_url('/admin-users.php')); exit; }
    if ($u['status'] === $new_status) {
        flash('info', 'No change needed.');
        header('Location: ' . base_url('/admin-users.php')); exit;
    }
    // Final admin guard.
    if ($u['role'] === 'admin' && $u['status'] === 'active' && $new_status !== 'active') {
        $active_admins = (int) (fetchOne("SELECT COUNT(*) AS c FROM users WHERE role='admin' AND status='active'")['c'] ?? 0);
        if ($active_admins <= 1) {
            flash('danger', 'Cannot deactivate the last active admin.');
            header('Location: ' . base_url('/admin-users.php')); exit;
        }
    }
    executeQuery('UPDATE users SET status = :s WHERE id = :id', [':s' => $new_status, ':id' => $id]);
    log_audit('user_status_changed', 'user', $id, ['from' => $u['status'], 'to' => $new_status]);
    flash('success', 'User status updated.');
} catch (Throwable $e) {
    error_log('[admin-user-status] ' . $e->getMessage());
    flash('danger', 'Could not update the user.');
}
header('Location: ' . base_url('/admin-users.php')); exit;
