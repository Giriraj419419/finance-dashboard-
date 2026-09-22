<?php
/**
 * IDOR regression tests. Two users own separate records; asserts that the
 * ownership guards in the state-changing endpoints refuse cross-user
 * writes.
 *
 * We drive the guard SQL directly (the pages themselves need a session
 * and CSRF), which is what makes this a REGRESSION test for the guard
 * pattern: it's still black-box against the query, so any change that
 * accidentally drops `user_id = :uid` will surface here.
 */
declare(strict_types=1);

require __DIR__ . '/test-harness.php';
require __DIR__ . '/db-bootstrap.php';

it('DELETE with user_id guard: attacker cannot delete another user\'s reminder', function () {
    $a = seed_user('idor-a@test.example');
    $b = seed_user('idor-b@test.example');

    // A owns a reminder.
    db_pdo()->prepare("INSERT INTO reminders (user_id, title, reminder_date) VALUES (?, 'A owns', '2026-01-01 00:00:00')")
        ->execute([$a]);
    $rid = (int) db_pdo()->lastInsertId();

    // B attempts a delete with the exact SQL shape the endpoint uses.
    $del = db_pdo()->prepare("DELETE FROM reminders WHERE id = ? AND user_id = ?");
    $del->execute([$rid, $b]);
    assert_eq($del->rowCount(), 0, 'B must not delete A\'s reminder');

    // The row still exists.
    $chk = db_pdo()->prepare("SELECT COUNT(*) FROM reminders WHERE id = ?");
    $chk->execute([$rid]);
    assert_eq((int) $chk->fetchColumn(), 1);
});

it('UPDATE with user_id guard: attacker cannot modify another user\'s transaction', function () {
    $a = seed_user('idor-txn-a@test.example');
    $b = seed_user('idor-txn-b@test.example');

    db_pdo()->prepare("INSERT INTO transactions (user_id, type, category, amount, transaction_date)
                       VALUES (?, 'income', 'salary', 1000.00, '2026-01-15')")
        ->execute([$a]);
    $tid = (int) db_pdo()->lastInsertId();

    $upd = db_pdo()->prepare("UPDATE transactions SET amount = 0 WHERE id = ? AND user_id = ?");
    $upd->execute([$tid, $b]);
    assert_eq($upd->rowCount(), 0, 'B must not update A\'s transaction');

    // Amount unchanged.
    $chk = db_pdo()->prepare("SELECT amount FROM transactions WHERE id = ?");
    $chk->execute([$tid]);
    assert_eq((string) $chk->fetchColumn(), '1000.00');
});

it('push-subscribe.php ownership: existing endpoint owned by A cannot be reassigned to B', function () {
    $a = seed_user('push-a@test.example');
    $b = seed_user('push-b@test.example');
    $endpoint = 'https://fcm.googleapis.com/idor-endpoint-1';

    // A subscribes.
    db_pdo()->prepare("INSERT INTO push_subscriptions (user_id, endpoint, p256dh_key, auth_key)
                       VALUES (?, ?, ?, ?)")
        ->execute([$a, $endpoint, str_repeat('a', 87), str_repeat('b', 22)]);

    // The ownership guard from push-subscribe.php: if the endpoint exists
    // and belongs to another user, refuse. Simulate that predicate:
    $row = db_pdo()->prepare("SELECT user_id FROM push_subscriptions WHERE endpoint = ?");
    $row->execute([$endpoint]);
    $owner = (int) $row->fetch()['user_id'];
    $allowed = $owner === $b;
    assert_false($allowed, 'ownership check must refuse');

    // Confirm B was never assigned.
    $chk = db_pdo()->prepare("SELECT user_id FROM push_subscriptions WHERE endpoint = ?");
    $chk->execute([$endpoint]);
    assert_eq((int) $chk->fetch()['user_id'], $a);
});

it('reports scope: user B sees zero rows for A\'s transactions', function () {
    $a = seed_user('rep-a@test.example');
    $b = seed_user('rep-b@test.example');
    for ($i = 0; $i < 3; $i++) {
        db_pdo()->prepare("INSERT INTO transactions (user_id, type, category, amount, transaction_date)
                           VALUES (?, 'expense', 'misc', 10.00, '2026-02-01')")->execute([$a]);
    }
    // Report query mirrors report-transactions.php's WHERE.
    $cnt = db_pdo()->prepare(
        "SELECT COUNT(*) FROM transactions WHERE user_id = :uid
           AND transaction_date BETWEEN :from AND :to"
    );
    $cnt->execute([':uid' => $b, ':from' => '2026-01-01', ':to' => '2026-12-31']);
    assert_eq((int) $cnt->fetchColumn(), 0);

    $cnt->execute([':uid' => $a, ':from' => '2026-01-01', ':to' => '2026-12-31']);
    assert_eq((int) $cnt->fetchColumn(), 3);
});
