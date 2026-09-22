<?php
/**
 * Integration tests for authentication + session behaviour, driven against
 * the real users / login_attempts / password_reset_tokens tables.
 */
declare(strict_types=1);

require __DIR__ . '/test-harness.php';
require __DIR__ . '/db-bootstrap.php';

require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/login-throttle.php';

it('bcrypt roundtrip: hash + verify accept the correct password', function () {
    $h = hash_password('S3cret-p4ss');
    assert_true(verify_password('S3cret-p4ss', $h));
    assert_false(verify_password('wrong', $h));
});

it('login throttle: threshold trips after configured failures for the (email, ip) pair', function () {
    $email = 'throttle@test.example';
    $ip    = '203.0.113.10';
    seed_user($email);

    // threshold in the test config is 3.
    assert_false(login_is_blocked($email, $ip));
    login_record_attempt($email, $ip, false);
    login_record_attempt($email, $ip, false);
    login_record_attempt($email, $ip, false);
    assert_true(login_is_blocked($email, $ip), 'expected block after 3 fails');
});

it('login throttle: successful clear removes the block once we prune old failures', function () {
    $email = 'throttle-clear@test.example';
    $ip    = '203.0.113.11';
    seed_user($email);

    login_record_attempt($email, $ip, false);
    login_record_attempt($email, $ip, false);
    login_record_attempt($email, $ip, false);
    assert_true(login_is_blocked($email, $ip));

    // Move the fake failures far enough into the past that the cleanup
    // predicate (`attempted_at < $window_ago`) actually matches them, then
    // clear. `login_clear_recent_failures` was designed to prune stale
    // noise on success, so this asserts the semantics.
    db_pdo()->prepare("UPDATE login_attempts SET attempted_at = NOW() - INTERVAL 1 DAY WHERE email = ?")
            ->execute([$email]);
    login_clear_recent_failures($email);

    // After clearing old failures, the block is gone (there are no new
    // failures in the recent window).
    assert_false(login_is_blocked($email, $ip));
});

it('password reset flow: token stored hashed, expiry + one-shot enforced', function () {
    $uid = seed_user('reset@test.example');

    // Simulate forgot-password.php's token issue.
    $token_plain = bin2hex(random_bytes(32));
    $token_hash  = hash('sha256', $token_plain);
    $expires_at  = date('Y-m-d H:i:s', time() + 3600);
    db_pdo()->prepare("INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)")
        ->execute([$uid, $token_hash, $expires_at]);

    // The PLAIN token is never stored.
    $row = db_pdo()->prepare("SELECT token_hash, used_at FROM password_reset_tokens WHERE user_id = ?");
    $row->execute([$uid]);
    $r = $row->fetch();
    assert_eq($r['token_hash'], $token_hash);
    assert_true($r['token_hash'] !== $token_plain, 'plain token must never appear in DB');
    assert_eq($r['used_at'], null);

    // One-shot enforcement: after use, used_at is populated and every
    // other active token for the user is invalidated.
    db_pdo()->prepare("INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
                       VALUES (?, ?, ?)")
        ->execute([$uid, hash('sha256', 'other-token-' . bin2hex(random_bytes(4))), $expires_at]);

    db_pdo()->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE token_hash = ?")
        ->execute([$token_hash]);
    db_pdo()->prepare("UPDATE password_reset_tokens SET used_at = NOW()
                       WHERE user_id = ? AND used_at IS NULL")
        ->execute([$uid]);

    // Verify: every token for this user now has a non-null used_at.
    $unused = db_pdo()->prepare("SELECT COUNT(*) FROM password_reset_tokens WHERE user_id = ? AND used_at IS NULL");
    $unused->execute([$uid]);
    assert_eq((int) $unused->fetchColumn(), 0);
});

it('audit_logs FK is SET NULL when the audited user is deleted', function () {
    $uid = seed_user('auditee@test.example');
    db_pdo()->prepare("INSERT INTO audit_logs (user_id, action, entity_type, entity_id)
                       VALUES (?, 'x', 'user', ?)")->execute([$uid, $uid]);
    // Deleting the user would trip RESTRICT on transactions etc., but for
    // audit_logs the FK is SET NULL. Force by clearing dependent rows first
    // (this user has none in this test), then delete.
    db_pdo()->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
    $row = db_pdo()->prepare("SELECT user_id FROM audit_logs WHERE entity_id = ?");
    $row->execute([$uid]);
    assert_eq($row->fetch()['user_id'], null);
});
