<?php
/**
 * Integration tests for diag_database() + diag_cron() against a real
 * MySQL schema. Together with the unit tests in diagnostics-test.php,
 * this locks the observable behaviour of the admin-only Diagnostics UI.
 */
declare(strict_types=1);

require __DIR__ . '/test-harness.php';
require __DIR__ . '/db-bootstrap.php';
require_once dirname(__DIR__) . '/diagnostics-lib.php';

it('diag_database on a freshly-migrated schema: every row PASS', function () {
    $rows = diag_database();
    $failed = array_values(array_filter($rows, static fn ($r) => !$r['ok']));
    if ($failed !== []) {
        $msg = "unexpected FAIL rows on a fresh schema:\n";
        foreach ($failed as $r) $msg .= "  {$r['name']}  ({$r['detail']})\n";
        throw new RuntimeException($msg);
    }
    // Sanity: all 17 tables + 5 unique + 6 fk + 13 money columns + db.connect = at least 42 rows.
    assert_true(count($rows) >= 42, 'expected at least 42 rows, got ' . count($rows));
});

it('diag_database --user missing user: FAIL row for that user only', function () {
    $rows = diag_database('nobody-' . bin2hex(random_bytes(4)) . '@test.example');
    $user_row = null;
    foreach ($rows as $r) if (str_starts_with($r['name'], 'user.exists.')) $user_row = $r;
    assert_true($user_row !== null);
    assert_false($user_row['ok']);
    assert_contains('missing', $user_row['detail']);
});

it('diag_database --user existing active user: PASS row', function () {
    $email = 'diag-' . bin2hex(random_bytes(4)) . '@test.example';
    seed_user($email, 'admin', 'active');
    $rows = diag_database($email);
    $user_row = null;
    foreach ($rows as $r) if (str_starts_with($r['name'], 'user.exists.')) $user_row = $r;
    assert_true($user_row !== null);
    assert_true($user_row['ok'], "expected active user to PASS: " . json_encode($user_row));
    assert_contains('status=active', $user_row['detail']);
});

it('diag_cron with no heartbeat: never-run FAIL', function () {
    // Wipe any existing system_health rows so this is a fresh state.
    db_pdo()->exec("DELETE FROM system_health WHERE metric_key LIKE 'reminder_worker%'");
    $rows = diag_cron(900);
    $heart = null;
    foreach ($rows as $r) if ($r['name'] === 'cron.reminder_worker.heartbeat') $heart = $r;
    assert_true($heart !== null);
    assert_false($heart['ok']);
    assert_contains('never-run', $heart['detail']);
});

it('diag_cron with a recent heartbeat: OK', function () {
    // Insert a heartbeat "now".
    db_pdo()->prepare(
        "INSERT INTO system_health (metric_key, metric_value) VALUES ('reminder_worker_last_run', :v)
         ON DUPLICATE KEY UPDATE metric_value = VALUES(metric_value), updated_at = CURRENT_TIMESTAMP"
    )->execute([':v' => date('Y-m-d H:i:s')]);
    $rows = diag_cron(900);
    $heart = null;
    foreach ($rows as $r) if ($r['name'] === 'cron.reminder_worker.heartbeat') $heart = $r;
    assert_true($heart !== null);
    assert_true($heart['ok'], "expected fresh heartbeat to PASS: " . json_encode($heart));
    assert_contains('age=', $heart['detail']);
});

it('diag_cron with a stale heartbeat: FAIL with age reported', function () {
    // Insert a heartbeat one hour ago.
    db_pdo()->prepare(
        "INSERT INTO system_health (metric_key, metric_value) VALUES ('reminder_worker_last_run', :v)
         ON DUPLICATE KEY UPDATE metric_value = VALUES(metric_value), updated_at = CURRENT_TIMESTAMP"
    )->execute([':v' => date('Y-m-d H:i:s', time() - 3600)]);
    $rows = diag_cron(300); // 5-minute threshold => stale
    $heart = null;
    foreach ($rows as $r) if ($r['name'] === 'cron.reminder_worker.heartbeat') $heart = $r;
    assert_true($heart !== null);
    assert_false($heart['ok'], 'expected stale heartbeat to FAIL: ' . json_encode($heart));
    assert_contains('age=', $heart['detail']);
});

it('diag_full_report groups the six collectors + manual, and manual never trips the verdict', function () {
    // Ensure a recent heartbeat so the overall verdict can PASS in principle.
    db_pdo()->prepare(
        "INSERT INTO system_health (metric_key, metric_value) VALUES ('reminder_worker_last_run', :v)
         ON DUPLICATE KEY UPDATE metric_value = VALUES(metric_value), updated_at = CURRENT_TIMESTAMP"
    )->execute([':v' => date('Y-m-d H:i:s')]);

    $rep = diag_full_report(null, 900);
    foreach (['runtime', 'config', 'database', 'vapid', 'cron', 'smtp', 'manual'] as $g) {
        assert_true(isset($rep['groups'][$g]), "missing group: {$g}");
    }
    // Manual rows are ok=true but overall_ok should NOT depend on them —
    // dev SMTP + VAPID are still broken, so overall MUST be false here.
    assert_false($rep['overall_ok'], 'expected overall FAIL because dev SMTP + VAPID are unset');
    foreach ($rep['groups']['manual'] as $r) {
        assert_true($r['ok']);
    }
});
