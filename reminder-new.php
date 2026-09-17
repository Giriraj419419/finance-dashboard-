<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/audit.php';

$page_title = 'New reminder';
$uid = (int) currentUser()['id'];

$allowed_priority = ['low','medium','high'];
$allowed_recurrence = ['none','daily','weekly','monthly','yearly'];
$errors = [];
$old = [
    'title' => '', 'description' => '', 'priority' => 'medium',
    'reminder_date' => date('Y-m-d\TH:i', strtotime('+1 day 09:00')),
    'recurrence_type' => 'none', 'recurrence_end_date' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_or_die();
    foreach (array_keys($old) as $k) { $old[$k] = trim((string) ($_POST[$k] ?? '')); }

    if ($old['title'] === '' || mb_strlen($old['title']) > 200)          { $errors['title']       = 'Title is required (max 200 characters).'; }
    if (mb_strlen($old['description']) > 65535)                          { $errors['description'] = 'Description is too long.'; }
    if (!in_array($old['priority'], $allowed_priority, true))            { $errors['priority']    = 'Pick a valid priority.'; }
    // datetime-local: YYYY-MM-DDTHH:MM
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $old['reminder_date'])) {
        $errors['reminder_date'] = 'Enter a valid due date and time.';
    }
    if (!in_array($old['recurrence_type'], $allowed_recurrence, true))   { $errors['recurrence_type'] = 'Pick a valid recurrence.'; }
    if ($old['recurrence_end_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['recurrence_end_date'])) {
        $errors['recurrence_end_date'] = 'Enter a valid end date.';
    }

    if ($errors === []) {
        try {
            $due = date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', $old['reminder_date'])));
            $id = insertRecord('reminders', [
                'user_id'             => $uid,
                'title'               => $old['title'],
                'description'         => $old['description'] === '' ? null : $old['description'],
                'priority'            => $old['priority'],
                'reminder_date'       => $due,
                'recurrence_type'     => $old['recurrence_type'],
                'recurrence_end_date' => $old['recurrence_end_date'] !== '' ? $old['recurrence_end_date'] : null,
                'status'              => 'pending',
            ]);
            log_audit('reminder_created', 'reminder', $id, ['title' => $old['title']]);
            flash('success', 'Reminder created.');
            header('Location: ' . base_url('/reminders.php')); exit;
        } catch (Throwable $ex) {
            error_log('[reminder-new] ' . $ex->getMessage());
            $errors['_general'] = 'Could not save the reminder. Please try again.';
        }
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span><a href="reminders.php">Reminders</a></span><span>New</span></div>
    <div class="page-header"><div><h1 class="page-header__title">New reminder</h1></div></div>
    <?php if (!empty($errors['_general'])): ?>
        <div class="flash flash--danger" role="alert"><span><?= e($errors['_general']) ?></span></div>
    <?php endif; ?>
    <section class="card"><div class="card__body">
        <form method="POST" action="reminder-new.php" class="form" data-validate novalidate>
            <?= csrf_field() ?>
            <div class="field<?= isset($errors['title']) ? ' field--error' : '' ?>">
                <label for="title">Title</label>
                <input id="title" name="title" type="text" required value="<?= e($old['title']) ?>">
                <div class="error" role="alert"><?= e($errors['title'] ?? '') ?></div>
            </div>
            <div class="field<?= isset($errors['description']) ? ' field--error' : '' ?>">
                <label for="description">Description <span class="text-soft">(optional)</span></label>
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
                <div class="field<?= isset($errors['recurrence_end_date']) ? ' field--error' : '' ?>">
                    <label for="recurrence_end_date">Recurrence ends <span class="text-soft">(optional)</span></label>
                    <input id="recurrence_end_date" name="recurrence_end_date" type="date" value="<?= e($old['recurrence_end_date']) ?>">
                    <div class="error" role="alert"><?= e($errors['recurrence_end_date'] ?? '') ?></div>
                </div>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn btn--primary">Save reminder</button>
                <a class="btn btn--ghost" href="reminders.php">Cancel</a>
            </div>
        </form>
    </div></section>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
