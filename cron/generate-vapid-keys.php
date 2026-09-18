<?php
/**
 * Generates a VAPID key pair (P-256) on the server, or, if the private
 * key PEM already exists at the target path, reads it and prints the
 * matching public key. Runs safely under cron on cPanel's CGI PHP.
 *
 * Usage:
 *   php cron/generate-vapid-keys.php                # $HOME/vapid-private.pem
 *   php cron/generate-vapid-keys.php /abs/path.pem  # explicit destination
 *
 * Refuses to run over a real web request.
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

// Block real web visits. Allow CLI + cron-CGI.
$is_web_request = PHP_SAPI !== 'cli'
    && (isset($_SERVER['HTTP_HOST']) || isset($_SERVER['REMOTE_ADDR']) || isset($_SERVER['REQUEST_METHOD']));
if ($is_web_request) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "CLI-only.\n";
    exit(1);
}
if (!headers_sent()) {
    header('Content-Type: text/plain; charset=utf-8');
}

// STDERR/STDOUT constants only exist under the CLI SAPI. Use echo everywhere
// so cron output captures the same stream regardless of PHP handler.
function say(string $msg): void { echo $msg . "\n"; }
function die_ok(string $msg): void { say($msg); exit(0); }
function die_err(string $msg, int $code): void { say('[error] ' . $msg); exit($code); }

// Pick target path.
$target = null;
if (isset($argv[1]) && $argv[1] !== '') {
    $target = $argv[1];
} elseif (getenv('VAPID_PEM')) {
    $target = getenv('VAPID_PEM');
} elseif (getenv('HOME')) {
    $target = rtrim(getenv('HOME'), '/') . '/vapid-private.pem';
} else {
    $target = dirname(__DIR__, 2) . '/vapid-private.pem';
}

say('target file: ' . $target);

if (!function_exists('openssl_pkey_new')) {
    die_err('PHP openssl extension is missing on this SAPI.', 3);
}

// ------------------------------------------------------------------------
// If the PEM already exists, load it and print the matching public key
// instead of failing. This is the recovery path when a previous run
// wrote the key but couldn't print output.
// ------------------------------------------------------------------------
if (file_exists($target)) {
    say('note: private key already exists at ' . $target . ' — reading it to derive the public key');
    $pem = file_get_contents($target);
    if ($pem === false || $pem === '') die_err('cannot read existing PEM', 4);
    $existing = openssl_pkey_get_private($pem);
    if ($existing === false) die_err('existing file is not a valid EC private key', 5);
    $details = openssl_pkey_get_details($existing);
    if ($details === false || empty($details['ec']['x']) || empty($details['ec']['y'])) {
        die_err('existing key is not a P-256 EC key', 6);
    }
    $x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
    $y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    $publicRaw = "\x04" . $x . $y;
    $publicB64u = rtrim(strtr(base64_encode($publicRaw), '+/', '-_'), '=');
    _print_config($target, $publicB64u);
    exit(0);
}

// ------------------------------------------------------------------------
// Fresh key generation
// ------------------------------------------------------------------------
$pkey = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name'       => 'prime256v1',
]);
if ($pkey === false) die_err('openssl_pkey_new failed: ' . openssl_error_string(), 7);

$pem = '';
if (!openssl_pkey_export($pkey, $pem)) {
    die_err('openssl_pkey_export failed: ' . openssl_error_string(), 8);
}

$details = openssl_pkey_get_details($pkey);
if ($details === false || empty($details['ec']['x']) || empty($details['ec']['y'])) {
    die_err('could not read EC key details', 9);
}

$x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
$y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
$publicRaw = "\x04" . $x . $y;
$publicB64u = rtrim(strtr(base64_encode($publicRaw), '+/', '-_'), '=');

$dir = dirname($target);
if (!is_dir($dir) || !is_writable($dir)) {
    die_err('cannot write to directory: ' . $dir, 10);
}

if (file_put_contents($target, $pem, LOCK_EX) === false) {
    die_err('failed to write ' . $target, 11);
}
@chmod($target, 0600);

_print_config($target, $publicB64u);
exit(0);

// ------------------------------------------------------------------------
function _print_config(string $target, string $publicB64u): void
{
    say('==========================================================');
    say('  VAPID configuration');
    say('==========================================================');
    say('Private key file: ' . $target . '  (chmod 600, DO NOT COMMIT)');
    say('');
    say('Add these lines to config.php inside the push[] block:');
    say('');
    say("    'vapid_subject'          => 'mailto:accounts@kktechsolutions.in',");
    say("    'vapid_public_key'       => '" . $publicB64u . "',");
    say("    'vapid_private_key_path' => '" . $target . "',");
    say('');
    say('The public key value above is safe to send to browsers.');
    say('The private key file must NEVER be committed or emailed.');
}
