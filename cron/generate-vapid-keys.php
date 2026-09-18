<?php
/**
 * Generates a fresh VAPID key pair (P-256 ECDH) on the server.
 *
 * Usage:
 *   php cron/generate-vapid-keys.php                # default: $HOME/vapid-private.pem
 *   php cron/generate-vapid-keys.php /abs/path.pem  # explicit destination
 *
 * Prints the VAPID public key and a ready-to-paste config.php block.
 * Writes the private key PEM (chmod 600) — supply a path OUTSIDE the
 * public docroot, e.g. /home/kktechsolutions/vapid-private.pem
 *
 * Refuses to run over a real web request. Runs fine under cron even when
 * cPanel routes commands through a CGI handler.
 */

// Force errors to be visible in the script output so cron/File Manager
// can show the actual failure instead of a blank 500.
ini_set('display_errors', '1');
error_reporting(E_ALL);

// Block genuine HTTP visits, allow CLI and CGI-from-cron.
$is_web_request = PHP_SAPI !== 'cli'
    && (isset($_SERVER['HTTP_HOST']) || isset($_SERVER['REMOTE_ADDR']) || isset($_SERVER['REQUEST_METHOD']));
if ($is_web_request) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "CLI-only.\n";
    exit(1);
}
header('Content-Type: text/plain; charset=utf-8');

// Pick the destination path. Order:
//   1. explicit CLI arg
//   2. env $VAPID_PEM
//   3. $HOME/vapid-private.pem
//   4. one directory above the project as a last resort
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

echo "target file: $target\n";

if (file_exists($target)) {
    fwrite(STDERR, "[abort] file already exists: $target\n");
    fwrite(STDERR, "        refusing to overwrite. Delete it first if you really want a new key.\n");
    exit(2);
}

// -- Check OpenSSL is present --
if (!function_exists('openssl_pkey_new')) {
    fwrite(STDERR, "[abort] PHP openssl extension is missing on this SAPI.\n");
    exit(3);
}

$pkey = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name'       => 'prime256v1',
]);
if ($pkey === false) {
    fwrite(STDERR, "[abort] openssl_pkey_new failed: " . openssl_error_string() . "\n");
    exit(4);
}

$pem = '';
if (!openssl_pkey_export($pkey, $pem)) {
    fwrite(STDERR, "[abort] openssl_pkey_export failed: " . openssl_error_string() . "\n");
    exit(5);
}

$details = openssl_pkey_get_details($pkey);
if ($details === false || empty($details['ec']['x']) || empty($details['ec']['y'])) {
    fwrite(STDERR, "[abort] could not read EC key details\n");
    exit(6);
}

$x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
$y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
$publicRaw = "\x04" . $x . $y;
$publicB64u = rtrim(strtr(base64_encode($publicRaw), '+/', '-_'), '=');

// Ensure the parent directory is writable.
$dir = dirname($target);
if (!is_dir($dir) || !is_writable($dir)) {
    fwrite(STDERR, "[abort] cannot write to directory: $dir\n");
    exit(7);
}

if (file_put_contents($target, $pem, LOCK_EX) === false) {
    fwrite(STDERR, "[abort] failed to write $target\n");
    exit(8);
}
@chmod($target, 0600);

echo "==========================================================\n";
echo "  VAPID key pair generated\n";
echo "==========================================================\n";
echo "Private key file: $target  (chmod 600, DO NOT COMMIT)\n\n";
echo "Add these lines to config.php inside the push[] block:\n\n";
echo "    'vapid_subject'          => 'mailto:accounts@kktechsolutions.in',\n";
echo "    'vapid_public_key'       => '" . $publicB64u . "',\n";
echo "    'vapid_private_key_path' => '" . $target . "',\n\n";
echo "The public key value above is safe to send to browsers.\n";
echo "The private key file must NEVER be committed or emailed.\n";
exit(0);
