<?php
/**
 * Tests for `_assertIdentifier()` — the whitelist regex that guards
 * insertRecord/updateRecord's runtime interpolation of table + column
 * names. If this ever regresses, dynamic identifiers can carry SQL, so we
 * pin it in CI.
 */
declare(strict_types=1);

require __DIR__ . '/test-harness.php';

$ROOT = dirname(__DIR__);
require_once $ROOT . '/functions.php';
app_config('app'); // warm config cache with dev defaults
require_once $ROOT . '/database.php';

it('accepts alphanumeric+underscore identifiers', function () {
    _assertIdentifier('users');
    _assertIdentifier('reminder_notifications');
    _assertIdentifier('a_1_b_2');
    _assertIdentifier('X');
    // If we reach here, none of the calls threw.
});

it('rejects empty identifiers', function () {
    assert_throws(static function () { _assertIdentifier(''); }, InvalidArgumentException::class);
});

it('rejects identifiers containing punctuation, quotes, or spaces', function () {
    foreach (['users;', "u'ser", 'user`s', 'user name', 'users--', 'a.b', 'a-b'] as $bad) {
        assert_throws(static function () use ($bad) { _assertIdentifier($bad); }, InvalidArgumentException::class);
    }
});

it('rejects backslash / null byte / control chars', function () {
    foreach (['a\\b', "a\x00b", "a\nb", "a\tb"] as $bad) {
        assert_throws(static function () use ($bad) { _assertIdentifier($bad); }, InvalidArgumentException::class);
    }
});
