<?php
/**
 * Generates a fresh VAPID key pair (P-256 ECDH).
 *
 *   php cron/generate-vapid-keys.php [/path/to/vapid-private.pem]
 *
 * Prints the base64url-encoded public key (safe for browser + config.php).
 * Writes the private key PEM to disk (chmod 600) — supply the destination
 * outside your public docroot, e.g. /home/kktechsolutions/vapid-private.pem
 *
 * NEVER commit the private key. NEVER paste it in chat.
 * If the file already exists, this script refuses to overwrite it.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "CLI-only.\n";
    exit(1);
}

$target = $argv[1] ?? (getenv('HOME') ?: dirname(__DIR__)) . '/vapid-private.pem';

if (file_exists($target)) {
    fwrite(STDERR, "[abort] file already exists: $target\n");
    fwrite(STDERR, "        refusing to overwrite — delete it first if you really want a new key.\n");
    exit(2);
}

$pkey = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name'       => 'prime256v1',
]);
if ($pkey === false) {
    fwrite(STDERR, "[abort] openssl_pkey_new failed: " . openssl_error_string() . "\n");
    exit(3);
}

// Export private key as PEM.
$pem = '';
if (!openssl_pkey_export($pkey, $pem)) {
    fwrite(STDERR, "[abort] openssl_pkey_export failed\n");
    exit(4);
}

// Extract raw public-key point (65 bytes: 0x04 || X(32) || Y(32)).
$details = openssl_pkey_get_details($pkey);
if ($details === false || empty($details['ec']['x']) || empty($details['ec']['y'])) {
    fwrite(STDERR, "[abort] could not read EC key details\n");
    exit(5);
}
$x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
$y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
$publicRaw = "\x04" . $x . $y;
$publicB64u = rtrim(strtr(base64_encode($publicRaw), '+/', '-_'), '=');

// Write PEM with strict permissions.
if (file_put_contents($target, $pem, LOCK_EX) === false) {
    fwrite(STDERR, "[abort] failed to write $target\n");
    exit(6);
}
@chmod($target, 0600);

echo "==========================================================\n";
echo "  VAPID key pair generated\n";
echo "==========================================================\n";
echo "Private key written to: $target  (chmod 600, DO NOT COMMIT)\n";
echo "\n";
echo "Add these to config.php push[] block:\n\n";
echo "'push' => [\n";
echo "    'vapid_subject'          => 'mailto:accounts@kktechsolutions.in',\n";
echo "    'vapid_public_key'       => '" . $publicB64u . "',\n";
echo "    'vapid_private_key_path' => '" . $target . "',\n";
echo "],\n\n";
echo "The public key value above is what the browser will fetch.\n";
echo "Keep the private key file OUT of the public docroot and out of git.\n";
exit(0);
