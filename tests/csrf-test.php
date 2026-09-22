<?php
/**
 * Unit tests for csrf.php. We drive a real PHP session in this process
 * (CLI SAPI keeps $_SESSION alive across function calls within the same
 * process, so we can assert token issue/verify/rotate behaviour end-to-end).
 */
declare(strict_types=1);

require __DIR__ . '/test-harness.php';

// Suppress the session cookie / headers warnings that CLI cannot honour.
@ini_set('session.use_cookies', '0');
@ini_set('session.cache_limiter', '');

// csrf.php requires auth.php which requires functions.php; we do NOT want
// to trigger app_config() (it would try to require config.php and could
// invoke the production guard). Preload a benign config into the static.
$ROOT = dirname(__DIR__);
require_once $ROOT . '/functions.php';
// Warm the memoised config via a private path — call app_config() once so
// its static cache holds the config.example.php values (dev, non-prod).
app_config('app');

require_once $ROOT . '/auth.php';
require_once $ROOT . '/csrf.php';

it('csrf_token returns a stable 64-hex string within a session', function () {
    $t1 = csrf_token();
    $t2 = csrf_token();
    assert_eq($t1, $t2, 'token should be stable within a session');
    assert_eq(strlen($t1), 64);
    assert_true(ctype_xdigit($t1), 'token should be hex');
});

it('csrf_verify accepts the current token', function () {
    $t = csrf_token();
    assert_true(csrf_verify($t));
});

it('csrf_verify rejects an empty, wrong, or absent token', function () {
    assert_false(csrf_verify(''));
    assert_false(csrf_verify(null));
    assert_false(csrf_verify(str_repeat('a', 64)));
});

it('csrf_rotate mints a new token and invalidates the old one', function () {
    $old = csrf_token();
    csrf_rotate();
    $new = csrf_token();
    assert_true($old !== $new, 'expected new token after rotate');
    assert_false(csrf_verify($old), 'old token must NOT verify after rotate');
    assert_true(csrf_verify($new), 'new token must verify after rotate');
});

it('csrf_field emits an html hidden input with the current token', function () {
    $html = csrf_field();
    assert_contains('name="_csrf"', $html);
    assert_contains('value="' . csrf_token() . '"', $html);
});
