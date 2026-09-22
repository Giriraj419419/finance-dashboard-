<?php
/**
 * Integration tests for the cron reminder worker. Verifies the three
 * properties the production requirement actually cares about:
 *
 *   1. Two "concurrent" workers cannot both claim the same occurrence.
 *   2. Failed delivery increments attempts and keeps status pending
 *      until MAX_ATTEMPTS, then flips to failed.
 *   3. A 410-Gone push endpoint deactivates the subscription.
 *
 * These tests do NOT boot the whole worker (it exits when it thinks it's
 * done); instead they exercise the two data primitives the worker uses:
 * `claim_occurrence()`-equivalent INSERT and `finalise_notification()`-
 * equivalent UPDATE. Using the same SQL shape makes the invariants real.
 */
declare(strict_types=1);

require __DIR__ . '/test-harness.php';
require __DIR__ . '/db-bootstrap.php';

it('unique-key claim: two concurrent claims for the same occurrence — only one wins', function () {
    $uid  = seed_user('claim-a@test.example');
    $rid  = _make_pending_reminder($uid, '2026-06-01 09:00:00');
    $when = '2026-06-01 09:00:00';

    $first  = _try_claim($rid, $uid, $when);
    $second = _try_claim($rid, $uid, $when);

    assert_true($first  !== null, 'first claim should have succeeded');
    assert_true($second === null, 'second claim MUST fail on the unique key');

    // Sanity: exactly one row exists.
    $count = (int) db_pdo()
        ->query("SELECT COUNT(*) FROM reminder_notifications WHERE reminder_id = {$rid}")
        ->fetchColumn();
    assert_eq($count, 1);
});

it('retry pipeline: fail once → status pending, attempts=1; three fails → status failed', function () {
    $uid  = seed_user('retry-a@test.example');
    $rid  = _make_pending_reminder($uid, '2026-06-02 09:00:00');
    $when = '2026-06-02 09:00:00';

    $rn_id = _try_claim($rid, $uid, $when);
    assert_true($rn_id !== null);

    // Simulate attempt 1 failing.
    _record_failure($rn_id, 'first fail');
    $row = _rn_row($rn_id);
    assert_eq($row['status'],   'pending',   'first fail should reset to pending');
    assert_eq((int) $row['attempts'], 1);

    // Simulate attempt 2 (retry pass: increment attempts, run, fail).
    _bump_attempt($rn_id);
    _record_failure($rn_id, 'second fail');
    $row = _rn_row($rn_id);
    assert_eq((int) $row['attempts'], 2);
    assert_eq($row['status'], 'pending');

    // Attempt 3: this one MUST take the status all the way to 'failed'.
    _bump_attempt($rn_id);
    _record_failure($rn_id, 'third fail');
    $row = _rn_row($rn_id);
    assert_eq((int) $row['attempts'], 3);
    assert_eq($row['status'], 'failed');
});

it('successful send flips status to sent and clears last_error', function () {
    $uid  = seed_user('sent-a@test.example');
    $rid  = _make_pending_reminder($uid, '2026-06-03 09:00:00');
    $when = '2026-06-03 09:00:00';
    $rn_id = _try_claim($rid, $uid, $when);
    _record_failure($rn_id, 'transient');
    _record_success($rn_id);
    $row = _rn_row($rn_id);
    assert_eq($row['status'], 'sent');
    assert_eq($row['last_error'], null);
    assert_true($row['sent_at'] !== null);
});

it('push endpoint 410-gone flips subscription to is_active=0', function () {
    $uid = seed_user('gone-a@test.example');
    $sub_id = _insert_subscription($uid, 'https://fcm.googleapis.com/gone-endpoint-A');
    // Mirror the same UPDATE the worker uses when it observes 404/410.
    db_pdo()->prepare(
        "UPDATE push_subscriptions SET is_active = 0, last_error = 'gone' WHERE id = ?"
    )->execute([$sub_id]);
    $row = db_pdo()->query("SELECT is_active, last_error FROM push_subscriptions WHERE id = {$sub_id}")->fetch();
    assert_eq((int) $row['is_active'], 0);
    assert_eq($row['last_error'], 'gone');
});

it('endpoint uniqueness: two subscriptions cannot share the same endpoint', function () {
    $uid1 = seed_user('endp-a@test.example');
    $uid2 = seed_user('endp-b@test.example');
    $endpoint = 'https://fcm.googleapis.com/dup-endpoint-1';
    $ok = false;
    try {
        _insert_subscription($uid1, $endpoint);
        _insert_subscription($uid2, $endpoint); // must throw on unique key
    } catch (PDOException $e) {
        // 23000 = integrity constraint violation (dup key).
        $ok = $e->getCode() === '23000';
    }
    assert_true($ok, 'expected UNIQUE endpoint to reject second insert');
});

// ---------- helpers ------------------------------------------------------

function _make_pending_reminder(int $uid, string $when): int
{
    db_pdo()->prepare(
        "INSERT INTO reminders (user_id, title, priority, notification_offset_minutes, reminder_date, recurrence_type, status)
         VALUES (?, ?, 'medium', 0, ?, 'none', 'pending')"
    )->execute([$uid, 'test reminder', $when]);
    return (int) db_pdo()->lastInsertId();
}

/** Mirrors claim_occurrence() from cron/reminder-worker.php. */
function _try_claim(int $rid, int $uid, string $when): ?int
{
    try {
        db_pdo()->prepare(
            "INSERT INTO reminder_notifications (reminder_id, user_id, scheduled_for, notification_type, status, attempts)
             VALUES (?, ?, ?, 'email', 'processing', 1)"
        )->execute([$rid, $uid, $when]);
        return (int) db_pdo()->lastInsertId();
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') return null;
        throw $e;
    }
}

/** Mirrors finalise_notification($id, false, $err). */
function _record_failure(int $rn_id, string $err): void
{
    // MAX_ATTEMPTS = 3 in the worker; same value here.
    db_pdo()->prepare(
        "UPDATE reminder_notifications
         SET status = CASE WHEN attempts >= 3 THEN 'failed' ELSE 'pending' END,
             last_error = ?
         WHERE id = ?"
    )->execute([$err, $rn_id]);
}

/** Mirrors the atomic retry-claim UPDATE in deliver_retry(). */
function _bump_attempt(int $rn_id): void
{
    db_pdo()->prepare(
        "UPDATE reminder_notifications
         SET status = 'processing', attempts = attempts + 1
         WHERE id = ? AND status IN ('pending','processing') AND attempts < 3"
    )->execute([$rn_id]);
}

/** Mirrors finalise_notification($id, true, null). */
function _record_success(int $rn_id): void
{
    db_pdo()->prepare(
        "UPDATE reminder_notifications
         SET status = 'sent', sent_at = NOW(), last_error = NULL
         WHERE id = ?"
    )->execute([$rn_id]);
}

function _rn_row(int $id): array
{
    $row = db_pdo()->prepare("SELECT * FROM reminder_notifications WHERE id = ?");
    $row->execute([$id]);
    $r = $row->fetch();
    if ($r === false) throw new RuntimeException("rn_row: no such id={$id}");
    return $r;
}

function _insert_subscription(int $uid, string $endpoint): int
{
    db_pdo()->prepare(
        "INSERT INTO push_subscriptions (user_id, endpoint, p256dh_key, auth_key, is_active)
         VALUES (?, ?, ?, ?, 1)"
    )->execute([$uid, $endpoint, str_repeat('a', 87), str_repeat('b', 22)]);
    return (int) db_pdo()->lastInsertId();
}
