<?php
/**
 * Minimal test harness — no Composer, no vendor tree, so cPanel hosts and
 * a bare `php` in CI can run the whole suite. Each test file is a plain
 * PHP script that requires this harness and calls `it(...)` for each case.
 *
 * `it($label, $fn)` runs the closure inside a try/catch. Exceptions and
 * failed assertions are recorded as failures; the harness tallies pass/fail
 * counts and each test file exits with 0 on success, 1 on failure.
 *
 * `assert_eq($actual, $expected, $msg = '')` and friends throw on mismatch.
 */
declare(strict_types=1);

if (!defined('TEST_HARNESS_LOADED')) {
    define('TEST_HARNESS_LOADED', true);

    $GLOBALS['__test_counts'] = ['pass' => 0, 'fail' => 0];
    $GLOBALS['__test_current'] = '';

    function it(string $label, callable $fn): void {
        $GLOBALS['__test_current'] = $label;
        try {
            $fn();
            $GLOBALS['__test_counts']['pass']++;
            fwrite(STDOUT, "  PASS  {$label}\n");
        } catch (Throwable $e) {
            $GLOBALS['__test_counts']['fail']++;
            fwrite(STDOUT, "  FAIL  {$label}\n");
            fwrite(STDOUT, "        " . $e->getMessage() . "\n");
            $trace = $e->getFile() . ':' . $e->getLine();
            fwrite(STDOUT, "        {$trace}\n");
        }
    }

    function assert_eq($actual, $expected, string $msg = ''): void {
        if ($actual !== $expected) {
            $a = var_export($actual, true);
            $e = var_export($expected, true);
            throw new RuntimeException(($msg !== '' ? $msg . '  ' : '') . "expected {$e}, got {$a}");
        }
    }

    function assert_true(bool $cond, string $msg = ''): void {
        if (!$cond) throw new RuntimeException($msg !== '' ? $msg : 'expected true');
    }

    function assert_false(bool $cond, string $msg = ''): void {
        if ($cond) throw new RuntimeException($msg !== '' ? $msg : 'expected false');
    }

    function assert_contains(string $needle, string $haystack, string $msg = ''): void {
        if (!str_contains($haystack, $needle)) {
            throw new RuntimeException(($msg !== '' ? $msg . '  ' : '') . "expected string to contain " . var_export($needle, true));
        }
    }

    function assert_throws(callable $fn, ?string $expected_class = null): Throwable {
        try {
            $fn();
        } catch (Throwable $e) {
            if ($expected_class !== null && !($e instanceof $expected_class)) {
                throw new RuntimeException("expected {$expected_class}, got " . get_class($e) . ': ' . $e->getMessage());
            }
            return $e;
        }
        throw new RuntimeException('expected callable to throw' . ($expected_class ? " {$expected_class}" : ''));
    }

    register_shutdown_function(static function () {
        $counts = $GLOBALS['__test_counts'];
        fwrite(STDOUT, "  ---\n");
        fwrite(STDOUT, sprintf("  PASS=%d  FAIL=%d\n", $counts['pass'], $counts['fail']));
        if ($counts['fail'] > 0 && !getenv('TEST_NO_EXIT')) {
            // Signal failure to the runner (unless a runner sets TEST_NO_EXIT
            // to aggregate counts across files itself).
            exit(1);
        }
    });
}
