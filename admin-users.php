<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/csrf.php';
requireRole('admin');

$page_title = 'Users';

$q      = trim((string) ($_GET['q'] ?? ''));
$role   = in_array($_GET['role']   ?? '', ['admin','manager','employee'], true) ? $_GET['role'] : '';
$status = in_array($_GET['status'] ?? '', ['active','inactive','suspended'], true) ? $_GET['status'] : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 25;
$offset = ($page - 1) * $per_page;

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(name LIKE :q OR email LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
if ($role   !== '') { $where[] = 'role = :role';     $params[':role'] = $role; }
if ($status !== '') { $where[] = 'status = :status'; $params[':status'] = $status; }
$where_sql = $where === [] ? '1=1' : implode(' AND ', $where);

try {
    $total = (int) (fetchOne("SELECT COUNT(*) AS c FROM users WHERE $where_sql", $params)['c'] ?? 0);
    $total_pages = max(1, (int) ceil($total / $per_page));
    $rows = executeQuery(
        "SELECT id, name, email, role, status, last_login_at, created_at
         FROM users WHERE $where_sql
         ORDER BY created_at DESC
         LIMIT $per_page OFFSET $offset",
        $params
    )->fetchAll();
    $active_admins = (int) (fetchOne("SELECT COUNT(*) AS c FROM users WHERE role = 'admin' AND status = 'active'")['c'] ?? 0);
} catch (Throwable $e) {
    error_log('[admin-users:list] ' . $e->getMessage());
    $rows = []; $total = 0; $total_pages = 1; $active_admins = 0;
    flash('danger', 'Could not load users.');
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span>Users</span></div>
    <div class="page-header">
        <div><h1 class="page-header__title">User management</h1><p class="page-header__desc">Admin-only. Manage team members, roles, and account status.</p></div>
        <div class="page-header__actions"><a class="btn btn--primary" href="admin-user-form.php">Add user</a></div>
    </div>

    <section class="card">
        <div class="card__header"><h2 class="card__title">All users <span class="text-soft">(<?= (int) $total ?>)</span></h2></div>
        <div class="card__body">
            <form method="GET" action="admin-users.php" class="filters">
                <div class="field"><label for="f_q">Search</label><input id="f_q" name="q" type="search" value="<?= e($q) ?>" placeholder="Name or email"></div>
                <div class="field"><label for="f_role">Role</label>
                    <select id="f_role" name="role">
                        <option value="">All</option>
                        <?php foreach (['admin','manager','employee'] as $r): ?>
                            <option value="<?= e($r) ?>" <?= $role === $r ? 'selected' : '' ?>><?= e(ucfirst($r)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label for="f_status">Status</label>
                    <select id="f_status" name="status">
                        <option value="">All</option>
                        <?php foreach (['active','inactive','suspended'] as $s): ?>
                            <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><span>&nbsp;</span></div>
                <div class="field"><span>&nbsp;</span></div>
                <div class="filters__actions"><button type="submit" class="btn btn--primary">Apply</button><a class="btn btn--ghost" href="admin-users.php">Reset</a></div>
            </form>
        </div>
        <div class="card__body card__body--flush">
            <?php if (empty($rows)): ?>
                <div class="empty-state"><div class="empty-state__title">No users match</div></div>
            <?php else: ?>
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last login</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($rows as $u):
                            $role_badge = ['admin' => 'danger', 'manager' => 'warning', 'employee' => 'info'][$u['role']] ?? 'neutral';
                            $status_badge = ['active' => 'success', 'inactive' => 'neutral', 'suspended' => 'danger'][$u['status']] ?? 'neutral';
                            $is_last_admin = ($u['role'] === 'admin' && $u['status'] === 'active' && $active_admins <= 1);
                        ?>
                            <tr>
                                <td><?= e($u['name']) ?></td>
                                <td><?= e($u['email']) ?></td>
                                <td><span class="badge badge--<?= e($role_badge) ?>"><?= e(ucfirst((string) $u['role'])) ?></span></td>
                                <td><span class="badge badge--<?= e($status_badge) ?>"><?= e(ucfirst((string) $u['status'])) ?></span></td>
                                <td><?= $u['last_login_at'] ? e(date('M j, Y', strtotime((string) $u['last_login_at']))) : '<span class="text-soft">Never</span>' ?></td>
                                <td class="text-right">
                                    <a class="btn btn--ghost btn--sm" href="admin-user-form.php?id=<?= (int) $u['id'] ?>">Edit</a>

                                    <?php if ($u['status'] === 'active'): ?>
                                        <?php if ($is_last_admin): ?>
                                            <button class="btn btn--sm btn--ghost" disabled title="Cannot deactivate the last active admin">Deactivate</button>
                                        <?php else: ?>
                                            <form method="POST" action="admin-user-status.php" class="inline-form" onsubmit="return confirm('Deactivate this user?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                                <input type="hidden" name="status" value="inactive">
                                                <button type="submit" class="btn btn--sm btn--danger">Deactivate</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <form method="POST" action="admin-user-status.php" class="inline-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                            <input type="hidden" name="status" value="active">
                                            <button type="submit" class="btn btn--sm btn--primary">Activate</button>
                                        </form>
                                    <?php endif; ?>

                                    <form method="POST" action="admin-user-reset.php" class="inline-form" onsubmit="return confirm('Send a password-reset link to this user?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                        <button type="submit" class="btn btn--sm btn--ghost">Reset password</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php if ($total_pages > 1):
                    $qs = static function (array $overrides) use ($q, $role, $status): string {
                        return http_build_query(array_filter(['q'=>$q,'role'=>$role,'status'=>$status], static fn($v) => $v !== '') + $overrides);
                    };
                ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?><a class="btn btn--ghost btn--sm" href="?<?= e($qs(['page'=>$page-1])) ?>">← Previous</a><?php endif; ?>
                        <span class="text-muted">Page <?= (int) $page ?> of <?= (int) $total_pages ?></span>
                        <?php if ($page < $total_pages): ?><a class="btn btn--ghost btn--sm" href="?<?= e($qs(['page'=>$page+1])) ?>">Next →</a><?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
