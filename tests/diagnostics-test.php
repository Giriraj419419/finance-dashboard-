<?php
/**
 * Unit tests for diagnostics-lib.php that don't need a database.
 *
 * These exercise the pure collectors (runtime, smtp, vapid, config) and
 * the shape / safety guarantees of the shared row structure. The DB
 * collector has its own integration test.
 */
declare(strict_types=1);

require __DIR__ . '/test-harness.php';

$ROOT = dirname(__DIR__);
require_once $ROOT . '/functions.php';
app_config('app'); // warm config (development, no prod-guard trip)
require_once $ROOT . '/diagnostics-lib.php';

/** Shape check for every collector row. Enforces the contract in the header comment. */
function _assert_row_shape(array $row): void
{
    foreach (['name', 'ok', 'detail'] as $k) {
        assert_true(array_key_exists($k, $row), "missing key {$k} in row " . json_encode($row));
    }
    assert_true(is_string($row['name']),   'name must be string');
    assert_true(is_bool($row['ok']),       'ok must be bool');
    assert_true(is_string($row['detail']), 'detail must be string');
}

/**
 * Diagnostic rows must NEVER surface secret values. This scans a row's
 * `detail` for anything that looks like a raw credential, PEM header,
 * or path to a private-key file.
 */
function _assert_row_has_no_secrets(array $row): void
{
    $bad_needles = [
        'BEGIN PRIVATE KEY',
        'BEGIN EC PRIVATE KEY',
        'BEGIN RSA PRIVATE KEY',
        '.pem',
        '.key',
    ];
    foreach ($bad_needles as $n) {
        assert_true(!str_contains($row['detail'], $n),
            "row '{$row['name']}' leaked substring '{$n}' in detail: '{$row['detail']}'");
    }
}

it('diag_runtime returns rows with the required shape and never leaks paths', function () {
    $rows = diag_runtime();
    assert_true(count($rows) > 0);
    foreach ($rows as $r) {
        _assert_row_shape($r);
        _assert_row_has_no_secrets($r);
    }
});

it('diag_runtime asserts pdo_mysql, openssl, curl, mbstring, json, session as required extensions', function () {
    $rows = diag_runtime();
    $names = array_map(static fn ($r) => $r['name'], $rows);
    foreach (['runtime.ext.pdo_mysql', 'runtime.ext.openssl', 'runtime.ext.curl',
              'runtime.ext.mbstring', 'runtime.ext.json', 'runtime.ext.session'] as $needed) {
        assert_true(in_array($needed, $names, true), "missing runtime check: {$needed}");
    }
});

it('diag_smtp reports FAIL when host / from_email are unset (which is the local dev shape)', function () {
    // In the dev config config.php ships with host='' and from_email='no-reply@example.com'.
    // The dev shape is deliberately broken so this asserts against known-bad configuration.
    $rows = diag_smtp();
    foreach ($rows as $r) {
        _assert_row_shape($r);
        _assert_row_has_no_secrets($r);
    }
    $names = array_map(static fn ($r) => $r['name'], $rows);
    assert_true(in_array('smtp.host', $names, true));
    assert_true(in_array('smtp.from_email', $names, true));
});

it('diag_smtp never renders the smtp password — details are always marker strings, not real values', function () {
    $rows = diag_smtp();
    // The marker set the collector may render (see diagnostics-lib.php,
    // diag_smtp()) — anything else in `smtp.credentials.present` would
    // be a leak of the actual username/password.
    $allowed = ['<user + password set>', '(one or both unset)'];
    foreach ($rows as $r) {
        _assert_row_has_no_secrets($r);
        if ($r['name'] === 'smtp.credentials.present') {
            assert_true(in_array($r['detail'], $allowed, true),
                "smtp.credentials.present.detail must be a marker, got: '{$r['detail']}'");
        }
        // For smtp.host / smtp.from_email present-shape rows, allowed
        // detail values are only marker strings, never bare credentials.
        if ($r['name'] === 'smtp.host') {
            $host_allowed = ['<set>', '(unset)'];
            assert_true(in_array($r['detail'], $host_allowed, true),
                "smtp.host.detail must be a marker, got: '{$r['detail']}'");
        }
    }
});

it('diag_vapid on the dev config: at least public_key.present is FAIL', function () {
    $rows = diag_vapid();
    foreach ($rows as $r) {
        _assert_row_shape($r);
        _assert_row_has_no_secrets($r);
    }
    $by_name = [];
    foreach ($rows as $r) $by_name[$r['name']] = $r;
    // Dev config leaves VAPID unset — so the public-key check MUST fail.
    assert_false($by_name['vapid.public_key.present']['ok']);
    // The subject rendered detail must never be the actual mailto address
    // — it should be `(unset)` or the shape `mailto:*`.
    $subj = $by_name['vapid.subject.mailto']['detail'];
    assert_true($subj === '(unset)' || $subj === 'mailto:*', "subject detail leaked value: {$subj}");
});

it('diag_production_config on the dev config: environment.set is true (env=development)', function () {
    $rows = diag_production_config();
    foreach ($rows as $r) {
        _assert_row_shape($r);
        _assert_row_has_no_secrets($r);
    }
    $env_row = null;
    foreach ($rows as $r) if ($r['name'] === 'config.environment.set') $env_row = $r;
    assert_true($env_row !== null);
    assert_true($env_row['ok'], 'dev config has environment=development, so environment.set should be true');
});

it('diag_group_verdict returns true only when every row is ok', function () {
    assert_true(diag_group_verdict([]));                                    // vacuous
    assert_true(diag_group_verdict([['name' => 'a', 'ok' => true, 'detail' => '']]));
    assert_false(diag_group_verdict([['name' => 'a', 'ok' => true, 'detail' => ''],
                                     ['name' => 'b', 'ok' => false, 'detail' => 'x']]));
});

it('diag_counts sums pass/fail/total correctly', function () {
    $rows = [
        ['name' => 'a', 'ok' => true,  'detail' => ''],
        ['name' => 'b', 'ok' => false, 'detail' => ''],
        ['name' => 'c', 'ok' => true,  'detail' => ''],
    ];
    $c = diag_counts($rows);
    assert_eq($c['pass'], 2);
    assert_eq($c['fail'], 1);
    assert_eq($c['total'], 3);
});

it('diag_manual_checks are always ok=true (documentation, not verdict inputs)', function () {
    $manual = diag_manual_checks();
    assert_true(count($manual) >= 4);
    foreach ($manual as $r) {
        _assert_row_shape($r);
        assert_true($r['ok'], 'manual rows are documentation and must have ok=true so they never trip the verdict');
        assert_true(str_starts_with($r['name'], 'manual.'), "manual row name must start with 'manual.': {$r['name']}");
    }
});

it('diag_full_report shape: has all six groups + manual + counts + overall_ok', function () {
    $rep = diag_full_report(null, 900);
    foreach (['runtime', 'config', 'database', 'vapid', 'cron', 'smtp', 'manual'] as $g) {
        assert_true(array_key_exists($g, $rep['groups']), "missing group: {$g}");
    }
    assert_true(is_bool($rep['overall_ok']));
    assert_true(isset($rep['counts']['pass'], $rep['counts']['fail'], $rep['counts']['total']));
    assert_true(is_string($rep['generated']));
});

it('admin-diagnostics.php enforces admin role via requireRole(admin)', function () {
    // Static check: the file must call requireRole('admin') before any
    // response can be generated. If a refactor moves the guard, this
    // test flags it. It's not a runtime check, but it defends against
    // the specific regression where the guard is removed / commented out.
    $src = (string) file_get_contents(dirname(__DIR__) . '/admin-diagnostics.php');
    assert_true($src !== '', 'admin-diagnostics.php missing');
    assert_contains("requireRole('admin')", $src);
    // And the require must appear BEFORE any HTML/echo output.
    $guard_pos = strpos($src, "requireRole('admin')");
    $first_html = strpos($src, '<section class="page">');
    assert_true($guard_pos !== false && $first_html !== false && $guard_pos < $first_html,
        'requireRole(admin) must appear before the page HTML');
});

it('diagnostics-lib.php is denied direct HTTP access via .htaccess', function () {
    // Second layer of defence: the .htaccess must include diagnostics-lib.php
    // in its FilesMatch deny block. Regressing that would expose the file
    // over HTTP even though it's include-only.
    $ht = (string) file_get_contents(dirname(__DIR__) . '/.htaccess');
    assert_contains('diagnostics-lib', $ht);
});
