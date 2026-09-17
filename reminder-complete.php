<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/audit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . base_url('/reminders.php')); exit; }
csrf_check_or_die();

$me   = currentUser();
$uid  = (int) $me['id'];
$role = (string) ($me['role'] ?? 'employee');
$id   = (int) ($_POST['id'] ?? 0);
if ($id <= 0) { flash('danger', 'Invalid reminder.'); header('Location: ' . base_url('/reminders.php')); exit; }

try {
    $r = fetchOne('SELECT id, user_id, title, status FROM reminders WHERE id = :id LIMIT 1', [':id' => $id]);
    if (!$r) { flash('danger', 'Reminder not found.'); header('Location: ' . base_url('/reminders.php')); exit; }
    if ((int) $r['user_id'] !== $uid && $role !== 'admin') {
        log_audit('access_denied', 'reminder', $id, ['reason' => 'not_owner', 'op' => 'complete']);
        http_response_code(403); require __DIR__ . '/403.php'; exit;
    }
    if ($r['status'] === 'completed') {
        flash('info', 'Already completed.');
    } else {
        executeQuery("UPDATE reminders SET status = 'completed' WHERE id = :id", [':id' => $id]);
        log_audit('reminder_completed', 'reminder', $id, ['title' => $r['title']]);
        flash('success', 'Reminder completed.');
    }
} catch (Throwable $e) {
    error_log('[reminder-complete] ' . $e->getMessage());
    flash('danger', 'Could not complete the reminder.');
}
header('Location: ' . base_url('/reminders.php' . (isset($_POST['view']) ? '?view=' . urlencode((string) $_POST['view']) : ''))); exit;
