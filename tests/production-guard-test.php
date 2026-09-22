<?php
/**
 * Unit tests for production-guard.php.
 * Exercises production_config_problems() with a variety of shaped configs
 * so a future regression in the guard's rules is caught in CI.
 */
declare(strict_types=1);

require __DIR__ . '/test-harness.php';
require_once dirname(__DIR__) . '/production-guard.php';

/** A minimal "good" production config skeleton. */
function _good_prod_config(): array {
    // Create a fake private key OUTSIDE the web root so the path-check passes.
    $tmp = sys_get_temp_dir() . '/vapid-test-' . bin2hex(random_bytes(4)) . '.pem';
    file_put_contents($tmp, "not a real key\n");
    return [
        'app' => [
            'environment' => 'production',
            'debug'       => false,
            'base_url'    => 'https://finance.example.org',
        ],
        'session' => [
            'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
        ],
        'database' => [
            'host' => 'localhost', 'name' => 'finance', 'username' => 'app', 'password' => 'realpass',
        ],
        'mail' => [
            'host' => 'smtp.example.org', 'port' => 465, 'from_email' => 'no-reply@finance.example.org',
        ],
        'push' => [
            'vapid_public_key'       => 'BASE64URL',
            'vapid_private_key_path' => $tmp,
            'vapid_subject'          => 'mailto:ops@finance.example.org',
        ],
    ];
}

it('empty problems on a fully-formed production config', function () {
    $cfg = _good_prod_config();
    $probs = production_config_problems($cfg);
    assert_eq($probs, [], 'clean config should yield no problems, got: ' . json_encode($probs));
});

it('non-production config is skipped entirely (dev is a free-fire zone)', function () {
    $cfg = _good_prod_config();
    $cfg['app']['environment'] = 'development';
    $cfg['app']['debug']       = true;      // would fail if we checked
    $cfg['session']['secure']  = false;     // would fail if we checked
    assert_eq(production_config_problems($cfg), []);
});

it('flags debug=true in production', function () {
    $cfg = _good_prod_config();
    $cfg['app']['debug'] = true;
    $probs = production_config_problems($cfg);
    assert_true(in_array('app.debug must be false in production', $probs, true),
        'expected debug problem in: ' . json_encode($probs));
});

it('flags http:// base_url in production', function () {
    $cfg = _good_prod_config();
    $cfg['app']['base_url'] = 'http://finance.example.org';
    $probs = production_config_problems($cfg);
    assert_contains('https://', implode(' | ', $probs));
});

it('flags session.secure=false', function () {
    $cfg = _good_prod_config();
    $cfg['session']['secure'] = false;
    $probs = production_config_problems($cfg);
    assert_true(in_array('session.secure must be true in production (requires HTTPS)', $probs, true));
});

it('flags CHANGE_ME database credentials', function () {
    $cfg = _good_prod_config();
    $cfg['database']['username'] = 'CHANGE_ME';
    $cfg['database']['password'] = 'CHANGE_ME';
    $probs = production_config_problems($cfg);
    assert_true(in_array('database.username/password still hold CHANGE_ME placeholder', $probs, true));
});

it('flags example.com sender addresses', function () {
    $cfg = _good_prod_config();
    $cfg['mail']['from_email'] = 'no-reply@example.com';
    $probs = production_config_problems($cfg);
    assert_true(in_array('mail.from_email still holds an example.com placeholder', $probs, true));
});

it('flags missing VAPID public key', function () {
    $cfg = _good_prod_config();
    $cfg['push']['vapid_public_key'] = '';
    $probs = production_config_problems($cfg);
    assert_true(in_array('push.vapid_public_key must be set in production', $probs, true));
});

it('flags VAPID private key path when file does not exist', function () {
    $cfg = _good_prod_config();
    $cfg['push']['vapid_private_key_path'] = '/tmp/does-not-exist-' . bin2hex(random_bytes(4)) . '.pem';
    $probs = production_config_problems($cfg);
    assert_true(in_array('push.vapid_private_key_path points at a missing file', $probs, true));
});

it('rejects VAPID private key inside web-root', function () {
    // Write a private key path INSIDE the app directory — must be rejected.
    $ROOT = dirname(__DIR__);
    $inside = $ROOT . DIRECTORY_SEPARATOR . 'vapid-test.pem';
    file_put_contents($inside, "not a real key\n");
    try {
        $cfg = _good_prod_config();
        $cfg['push']['vapid_private_key_path'] = $inside;
        $probs = production_config_problems($cfg);
        assert_true(
            in_array('push.vapid_private_key_path must live OUTSIDE the web-root directory', $probs, true),
            'expected outside-webroot problem in: ' . json_encode($probs)
        );
    } finally {
        @unlink($inside);
    }
});

it('flags vapid_subject that is not mailto:', function () {
    $cfg = _good_prod_config();
    $cfg['push']['vapid_subject'] = 'https://finance.example.org';
    $probs = production_config_problems($cfg);
    assert_true(in_array('push.vapid_subject must be a mailto: URL in production', $probs, true));
});
