<?php
/**
 * Password hashing tests. Exercises the same helpers login.php uses.
 */
declare(strict_types=1);

require __DIR__ . '/test-harness.php';

$ROOT = dirname(__DIR__);
require_once $ROOT . '/functions.php';
app_config('app');
require_once $ROOT . '/auth.php';

it('hash_password produces a bcrypt-shaped hash', function () {
    $h = hash_password('secret1234');
    assert_true(str_starts_with($h, '$2y$'), "expected bcrypt \$2y\$ prefix, got: {$h}");
    assert_true(strlen($h) === 60, 'bcrypt hashes are 60 chars');
});

it('verify_password accepts the correct password', function () {
    $h = hash_password('correct-horse-battery-staple');
    assert_true(verify_password('correct-horse-battery-staple', $h));
});

it('verify_password rejects the wrong password', function () {
    $h = hash_password('correct-horse-battery-staple');
    assert_false(verify_password('CORRECT-HORSE-BATTERY-STAPLE', $h));
    assert_false(verify_password('', $h));
    assert_false(verify_password('wrong', $h));
});

it('verify_password is safe against a completely non-bcrypt string', function () {
    // Should return false, not warn.
    assert_false(verify_password('anything', 'not-a-hash'));
});
