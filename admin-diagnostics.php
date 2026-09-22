<?php
/**
 * admin-diagnostics.php — Production Diagnostics
 *
 * Renders the full grouped output of diagnostics-lib.php (DB / config /
 * VAPID / cron / SMTP / runtime) plus a static "manual checks" section
 * that spells out what the operator still has to verify by hand.
 *
 * SECURITY
 *   * Admin-only: `requireRole('admin')` at the very top.
 *   * Read-only: renders diagnostic rows; performs no writes.
 *   * No secrets rendered — diagnostics-lib is contract-bound to emit
 *     setting NAMES and `<set>` / `(unset)` markers only.
 *   * All variable output goes through e() — the row `name` and `detail`
 *     values come from the DB / config / runtime, not from user request
 *     input, but escaping is applied regardless.
 *
 * USAGE
 *   Optional query params (safe to omit):
 *     ?user=<email>      also verify a specific user account exists
 *     ?cron_threshold=N  override the acceptable heartbeat age (seconds)
 *
 * The page does NOT send email, generate keys, or hit external services.
 * Actual delivery tests (SMTP send, push send) live behind their own CLI
 * scripts to prevent accidental clicks from producing production traffic.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/diagnostics-lib.php';
requireRole('admin');

$page_title = 'Production Diagnostics';

$check_user_raw = (string) ($_GET['user'] ?? '');
$check_user     = null;
if ($check_user_raw !== '' && filter_var($check_user_raw, FILTER_VALIDATE_EMAIL)) {
    $check_user = strtolower($check_user_raw);
}
$cron_threshold_raw = (int) ($_GET['cron_threshold'] ?? 900);
$cron_threshold = max(60, min(3600 * 6, $cron_threshold_raw));

$report = diag_full_report($check_user, $cron_threshold);

/** Pretty-print a row set with a per-group PASS/FAIL header. */
$render_group = static function (string $title, string $description, array $rows): void {
    $ok = diag_group_verdict($rows);
    $c  = diag_counts($rows);
    ?>
    <section class="card mt-4">
        <div class="card__header">
            <h2 class="card__title"><?= e($title) ?></h2>
            <span class="badge badge--<?= $ok ? 'success' : 'warning' ?>">
                <?= $ok ? 'PASS' : 'FAIL' ?>
                — <?= (int) $c['pass'] ?>/<?= (int) $c['total'] ?>
            </span>
        </div>
        <div class="card__body">
            <p class="text-muted" style="margin-top:0"><?= e($description) ?></p>
            <table class="table table--compact" role="table">
                <thead><tr><th>Check</th><th style="width:5rem">Status</th><th>Detail</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><code><?= e($r['name']) ?></code></td>
                        <td>
                            <span class="badge badge--<?= $r['ok'] ? 'success' : 'warning' ?>">
                                <?= $r['ok'] ? 'PASS' : 'FAIL' ?>
                            </span>
                        </td>
                        <td class="text-muted"><?= e($r['detail']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php
};

$render_manual = static function (array $rows): void {
    ?>
    <section class="card mt-4">
        <div class="card__header">
            <h2 class="card__title">Manual verification still required</h2>
            <span class="badge badge--info">External</span>
        </div>
        <div class="card__body">
            <p class="text-muted" style="margin-top:0">
                These checks cannot be performed by any code in this
                repository. They require external observation (an inbox,
                a browser, cPanel access) and are listed here so nothing
                is silently skipped.
            </p>
            <ol>
                <?php foreach ($rows as $r): ?>
                    <li>
                        <strong><code><?= e($r['name']) ?></code></strong>
                        — <span class="text-muted"><?= e($r['detail']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
    </section>
    <?php
};

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/topbar.php';
?>
<section class="page">
    <div class="breadcrumbs"><span><a href="dashboard.php">Home</a></span><span>Diagnostics</span></div>
    <div class="page-header">
        <div>
            <h1 class="page-header__title">Production Diagnostics</h1>
            <p class="page-header__desc">
                Read-only automated checks for hosts without SSH.
                <span class="badge badge--info">Admin</span>
            </p>
        </div>
        <div class="page-header__actions">
            <span class="badge badge--<?= $report['overall_ok'] ? 'success' : 'warning' ?>">
                Overall: <?= $report['overall_ok'] ? 'PASS' : 'FAIL' ?>
                (<?= (int) $report['counts']['pass'] ?>/<?= (int) $report['counts']['total'] ?>)
            </span>
        </div>
    </div>

    <section class="card">
        <div class="card__header"><h2 class="card__title">Optional parameters</h2></div>
        <div class="card__body">
            <form method="GET" action="admin-diagnostics.php" class="form-inline">
                <div class="field field--inline">
                    <label for="user">Verify this user email exists</label>
                    <input id="user" name="user" type="email"
                           value="<?= e($check_user_raw) ?>"
                           placeholder="you@yourdomain.com" autocomplete="off">
                </div>
                <div class="field field--inline">
                    <label for="cron_threshold">Cron heartbeat threshold (sec)</label>
                    <input id="cron_threshold" name="cron_threshold" type="number"
                           value="<?= (int) $cron_threshold ?>" min="60" max="21600" step="60">
                </div>
                <button type="submit" class="btn btn--primary">Re-run checks</button>
            </form>
            <p class="text-muted mt-2">
                <strong>Generated:</strong> <code><?= e($report['generated']) ?></code> (UTC).
                Reload the page to re-run every check.
            </p>
        </div>
    </section>

    <?php
    $render_group(
        'PHP runtime',
        'The PHP version and extensions the app relies on at runtime.',
        $report['groups']['runtime']
    );
    $render_group(
        'Production configuration',
        'Would this configuration be safe to serve in production? Runs the same rules that block boot when environment=production but the config is unsafe.',
        $report['groups']['config']
    );
    $render_group(
        'Database + schema',
        'Connectivity, required tables, unique keys, foreign-key constraints, and money-column DECIMAL types.',
        $report['groups']['database']
    );
    $render_group(
        'VAPID keys (Web Push)',
        'Public key shape, private-key PEM exists + is outside the web root, keypair actually matches, mailto: subject, and Unix mode.',
        $report['groups']['vapid']
    );
    $render_group(
        'Cron heartbeat',
        'The reminder worker writes system_health.reminder_worker_last_run on every run. Fresh heartbeat = cron is actually executing.',
        $report['groups']['cron']
    );
    $render_group(
        'SMTP configuration',
        'Host / port / sender / credentials present, and port ↔ secure pairing matches the mailer expectations (465 = SSL, 587 = STARTTLS).',
        $report['groups']['smtp']
    );
    $render_manual($report['groups']['manual']);
    ?>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
