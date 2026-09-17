<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/audit.php';
requireRole('admin');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$is_edit = $id > 0;
$page_title = $is_edit ? 'Edit user' : 'Add user';

$allowed_roles = ['admin','manager','employee'];
$allowed_status = ['active','inactive','suspended'];
$errors = [];
$old = ['name' => '', 'email' => '', 'role' => 'employee', 'status' => 'active', 'password' => ''];

if ($is_edit) {
    try {
        $user = fetchOne('SELECT id, name, email, role, status FROM users WHERE id = :id LIMIT 1', [':id' => $id]);
    } catch (Throwable $e) {
        error_log('[admin-user-form:load] ' . $e->getMessage());
        flash('danger', 'Could not load that user.');
        header('Location: ' . base_url('/admin-users.php')); exit;
    }
    if (!$user) { flash('danger', 'User not found.'); header('Location: ' . base_url('/admin-users.php')); exit; }
    $old = ['name' => $user['name'], 'email' => $user['email'], 'role' => $user['role'], 'status' => $user['status'], 'password' => ''];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_or_die();
    foreach (['name','email','role','status','password'] as $k) { $old[$k] = trim((string) ($_POST[$k] ?? '')); }
    $old['email'] = strtolower($old['email']);

    if ($old['name'] === '' || mb_strlen($old['name']) > 160)                          { $errors['name']   = 'Name is required (max 160 characters).'; }
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($old['email']) > 190) { $errors['email'] = 'Enter a valid email.'; }
    if (!in_array($old['role'], $allowed_roles, true))                                 { $errors['role']   = 'Pick a valid role.'; }
    if (!in_array($old['status'], $allowed_status, true))                              { $errors['status'] = 'Pick a valid status.'; }
    if (!$is_edit) {
        if (strlen($old['password']) < 8)          { $errors['password'] = 'Use at least 8 characters.'; }
        elseif (!preg_match('/[0-9]/', $old['password'])) { $errors['password'] = 'Include at least one number.'; }
    }

    if ($errors === []) {
        try {
            // Uniqueness
            $dup = fetchOne('SELECT id FROM users WHERE email = :e AND id <> :id LIMIT 1', [':e' => $old['email'], ':id' => $id ?: 0]);
            if ($dup) {
                $errors['email'] = 'That email is already in use.';
            } else {
                // Last-active-admin protection.
                if ($is_edit) {
                    $before = fetchOne('SELECT role, status FROM users WHERE id = :id', [':id' => $id]);
                    $would_lose_last_admin = false;
                    if ($before && $before['role'] === 'admin' && $before['status'] === 'active'
                        && ($old['role'] !== 'admin' || $old['status'] !== 'active')) {
                        $active_admins = (int) (fetchOne("SELECT COUNT(*) AS c FROM users WHERE role='admin' AND status='active'")['c'] ?? 0);
                        if ($active_admins <= 1) $would_lose_last_admin = true;
                    }
                    if ($would_lose_last_admin) {
                        $errors['_general'] = 'Cannot demote or deactivate the last active admin.';
                    } else {
                        updateRecord('users', [
                            'name' => $old['name'], 'email' => $old['email'],
                            'role' => $old['role'], 'status' => $old['status'],
                        ], ['id' => $id]);
                        if ($before) {
                            if ($before['role'] !== $old['role']) {
                                log_audit('user_role_changed', 'user', $id, ['from' => $before['role'], 'to' => $old['role']]);
                            }
                            if ($before['status'] !== $old['status']) {
                                log_audit('user_status_changed', 'user', $id, ['from' => $before['status'], 'to' => $old['status']]);
                            }
                        }
                        log_audit('user_updated', 'user', $id);
                        flash('success', 'User updated.');
                        header('Location: ' . base_url('/admin-users.php')); exit;
                    }
                } else {
                    $new_id = insertRecord('users', [
                        'name' => $old['name'], 'email' => $old['email'],
                        'password_hash' => hash_password($old['password']),
                        'role' => $old['role'], 'status' => $old['status'],
                    ]);
                    log_audit('user_created', 'user', $new_id, ['email' => $old['email'], 'role' => $old['role']]);
                    flash('success', 'User created.');
                    header('Location: ' . base_url('/admin-users.php')); exit;
                }
            }
        } catch (Throwable $ex) {
            error_log('[admin-user-form] ' . $ex->getMessage());
            $errors['_general'] = 'Could not save the user. Please try again.';
        }
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span><a href="admin-users.php">Users</a></span><span><?= $is_edit ? 'Edit' : 'New' ?></span></div>
    <div class="page-header"><div><h1 class="page-header__title"><?= $is_edit ? 'Edit user' : 'Add user' ?></h1></div></div>
    <?php if (!empty($errors['_general'])): ?>
        <div class="flash flash--danger" role="alert"><span><?= e($errors['_general']) ?></span></div>
    <?php endif; ?>
    <section class="card"><div class="card__body">
        <form method="POST" action="admin-user-form.php<?= $is_edit ? '?id=' . (int) $id : '' ?>" class="form" data-validate novalidate>
            <?= csrf_field() ?>
            <?php if ($is_edit): ?><input type="hidden" name="id" value="<?= (int) $id ?>"><?php endif; ?>
            <div class="form__row">
                <div class="field<?= isset($errors['name']) ? ' field--error' : '' ?>">
                    <label for="name">Name</label>
                    <input id="name" name="name" type="text" required value="<?= e($old['name']) ?>">
                    <div class="error" role="alert"><?= e($errors['name'] ?? '') ?></div>
                </div>
                <div class="field<?= isset($errors['email']) ? ' field--error' : '' ?>">
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" required value="<?= e($old['email']) ?>">
                    <div class="error" role="alert"><?= e($errors['email'] ?? '') ?></div>
                </div>
            </div>
            <div class="form__row">
                <div class="field<?= isset($errors['role']) ? ' field--error' : '' ?>">
                    <label for="role">Role</label>
                    <select id="role" name="role" required>
                        <?php foreach ($allowed_roles as $r): ?><option value="<?= e($r) ?>" <?= $old['role'] === $r ? 'selected' : '' ?>><?= e(ucfirst($r)) ?></option><?php endforeach; ?>
                    </select>
                    <div class="error" role="alert"><?= e($errors['role'] ?? '') ?></div>
                </div>
                <div class="field<?= isset($errors['status']) ? ' field--error' : '' ?>">
                    <label for="status">Status</label>
                    <select id="status" name="status" required>
                        <?php foreach ($allowed_status as $s): ?><option value="<?= e($s) ?>" <?= $old['status'] === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?>
                    </select>
                    <div class="error" role="alert"><?= e($errors['status'] ?? '') ?></div>
                </div>
            </div>
            <?php if (!$is_edit): ?>
                <div class="field<?= isset($errors['password']) ? ' field--error' : '' ?>">
                    <label for="password">Initial password</label>
                    <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password">
                    <div class="hint">At least 8 characters, including one number. Ask the user to change it on first login (or click "Reset password" from the list to send a reset email).</div>
                    <div class="error" role="alert"><?= e($errors['password'] ?? '') ?></div>
                </div>
            <?php endif; ?>
            <div class="flex gap-2">
                <button type="submit" class="btn btn--primary"><?= $is_edit ? 'Save changes' : 'Create user' ?></button>
                <a class="btn btn--ghost" href="admin-users.php">Cancel</a>
            </div>
        </form>
    </div></section>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
