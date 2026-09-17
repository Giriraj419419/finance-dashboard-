<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/audit.php';

$page_title = 'Edit reminder';
$me = currentUser();
$uid = (int) $me['id'];
$role = (string) ($me['role'] ?? 'employee');
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) { header('Location: ' . base_url('/reminders.php')); exit; }

try {
    $r = fetchOne(
        'SELECT id, user_id, title, description, priority, reminder_date, recurrence_type, recurrence_end_date, status
         FROM reminders WHERE id = :id LIMIT 1',
        [':id' => $id]
    );
} catch (Throwable $e) {
    error_log('[reminder-edit:load] ' . $e->getMessage());
    flash('danger', 'Could not load that reminder.');
    header('Location: ' . base_url('/reminders.php')); exit;
}
if (!$r) { flash('danger', 'Reminder not found.'); header('Location: ' . base_url('/reminders.php')); exit; }
if ((int) $r['user_id'] !== $uid && $role !== 'admin') {
    log_audit('access_denied', 'reminder', $id, ['reason' => 'not_owner']);
    http_response_code(403); require __DIR__ . '/403.php'; exit;
}

$allowed_priority = ['low','medium','high'];
$allowed_recurrence = ['none','daily','weekly','monthly','yearly'];
$allowed_status = ['pending','completed','snoozed','cancelled'];

$errors = [];
$old = [
    'title' => $r['title'], 'description' => $r['description'] ?? '',
    'priority' => $r['priority'],
    'reminder_date' => date('Y-m-d\TH:i', strtotime((string) $r['reminder_date'])),
    'recurrence_type' => $r['recurrence_type'],
    'recurrence_end_date' => $r['recurrence_end_date'] ?? '',
    'status' => $r['status'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_or_die();
    foreach (array_keys($old) as $k) { $old[$k] = trim((string) ($_POST[$k] ?? '')); }

    if ($old['title'] === '' || mb_strlen($old['title']) > 200)          { $errors['title']       = 'Title is required.'; }
    if (mb_strlen($old['description']) > 65535)                          { $errors['description'] = 'Description is too long.'; }
    if (!in_array($old['priority'], $allowed_priority, true))            { $errors['priority']    = 'Pick a valid priority.'; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $old['reminder_date'])) { $errors['reminder_date'] = 'Enter a valid due date.'; }
    if (!in_array($old['recurrence_type'], $allowed_recurrence, true))   { $errors['recurrence_type'] = 'Pick a valid recurrence.'; }
    if ($old['recurrence_end_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['recurrence_end_date'])) { $errors['recurrence_end_date'] = 'Enter a valid end date.'; }
    if (!in_array($old['status'], $allowed_status, true))                { $errors['status'] = 'Pick a valid status.'; }

    if ($errors === []) {
        try {
            $due = date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', $old['reminder_date'])));
            updateRecord('reminders', [
                'title'               => $old['title'],
                'description'         => $old['description'] === '' ? null : $old['description'],
                'priority'            => $old['priority'],
                'reminder_date'       => $due,
                'recurrence_type'     => $old['recurrence_type'],
                'recurrence_end_date' => $old['recurrence_end_date'] !== '' ? $old['recurrence_end_date'] : null,
                'status'              => $old['status'],
            ], ['id' => $id]);
            log_audit('reminder_updated', 'reminder', $id, ['title' => $old['title']]);
            flash('success', 'Reminder updated.');
            header('Location: ' . base_url('/reminders.php')); exit;
        } catch (Throwable $ex) {
            error_log('[reminder-edit] ' . $ex->getMessage());
            $errors['_general'] = 'Could not update the reminder.';
        }
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span><a href="reminders.php">Reminders</a></span><span>Edit</span></div>
    <div class="page-header"><div><h1 class="page-header__title">Edit reminder</h1></div></div>
    <?php if (!empty($errors['_general'])): ?><div class="flash flash--danger" role="alert"><span><?= e($errors['_general']) ?></span></div><?php endif; ?>
    <section class="card"><div class="card__body">
        <form method="POST" action="reminder-edit.php" class="form" data-validate novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $id ?>">
            <div class="field<?= isset($errors['title']) ? ' field--error' : '' ?>">
                <label for="title">Title</label>
                <input id="title" name="title" type="text" required value="<?= e($old['title']) ?>">
                <div class="error" role="alert"><?= e($errors['title'] ?? '') ?></div>
            </div>
            <div class="field<?= isset($errors['description']) ? ' field--error' : '' ?>">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="3"><?= e($old['description']) ?></textarea>
                <div class="error" role="alert"><?= e($errors['description'] ?? '') ?></div>
            </div>
            <div class="form__row">
                <div class="field<?= isset($errors['reminder_date']) ? ' field--error' : '' ?>">
                    <label for="reminder_date">Due date &amp; time</label>
                    <input id="reminder_date" name="reminder_date" type="datetime-local" required value="<?= e($old['reminder_date']) ?>">
                    <div class="error" role="alert"><?= e($errors['reminder_date'] ?? '') ?></div>
                </div>
                <div class="field<?= isset($errors['priority']) ? ' field--error' : '' ?>">
                    <label for="priority">Priority</label>
                    <select id="priority" name="priority" required>
                        <?php foreach ($allowed_priority as $p): ?><option value="<?= e($p) ?>" <?= $old['priority'] === $p ? 'selected' : '' ?>><?= e(ucfirst($p)) ?></option><?php endforeach; ?>
                    </select>
                    <div class="error" role="alert"><?= e($errors['priority'] ?? '') ?></div>
                </div>
            </div>
            <div class="form__row">
                <div class="field<?= isset($errors['recurrence_type']) ? ' field--error' : '' ?>">
                    <label for="recurrence_type">Recurrence</label>
                    <select id="recurrence_type" name="recurrence_type" required>
                        <?php foreach ($allowed_recurrence as $rc): ?><option value="<?= e($rc) ?>" <?= $old['recurrence_type'] === $rc ? 'selected' : '' ?>><?= e(ucfirst($rc)) ?></option><?php endforeach; ?>
                    </select>
                    <div class="error" role="alert"><?= e($errors['recurrence_type'] ?? '') ?></div>
                </div>
                <div class="field<?= isset($errors['status']) ? ' field--error' : '' ?>">
                    <label for="status">Status</label>
                    <select id="status" name="status" required>
                        <?php foreach ($allowed_status as $s): ?><option value="<?= e($s) ?>" <?= $old['status'] === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?>
                    </select>
                    <div class="error" role="alert"><?= e($errors['status'] ?? '') ?></div>
                </div>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn btn--primary">Save changes</button>
                <a class="btn btn--ghost" href="reminders.php">Cancel</a>
            </div>
        </form>
    </div></section>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
