<?php
/**
 * scripts/vapid-diagnostic.php
 *
 * Verify the VAPID key configuration for Web Push. This is a READ-ONLY
 * check — it never writes to disk, never regenerates keys, and never
 * prints the private key material.
 *
 * Checks:
 *   1. push.vapid_public_key is set and decodes to 65 bytes starting 0x04.
 *   2. push.vapid_private_key_path is set, points at a readable PEM,
 *      and is OUTSIDE the web-root directory.
 *   3. The PEM parses as an ECDSA P-256 private key.
 *   4. The public key derived from the private PEM matches the configured
 *      public key (i.e. they are a real pair).
 *   5. push.vapid_subject is a mailto: URL.
 *   6. File permissions are not world-readable (0644 or narrower on Unix).
 *
 * Exit code 0 = OK; 1 = at least one check failed.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "CLI-only diagnostic.\n";
    exit(1);
}

$ROOT = dirname(__DIR__);
require_once $ROOT . '/functions.php';
// Import the tiny base64url helpers already used by push-webpush.php.
require_once $ROOT . '/push-webpush.php';

$push = app_config('push');
$fail = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $fail;
    $mark = $ok ? 'PASS' : 'FAIL';
    if (!$ok) $fail++;
    printf("%-4s  %s%s\n", $mark, $name, $detail === '' ? '' : "  ({$detail})");
}

/* ------- 1. public key present + shape ------- */
$pubB64 = (string) ($push['vapid_public_key'] ?? '');
$pub    = $pubB64 === '' ? '' : _wp_b64u_decode($pubB64);
check('vapid.public_key.present', $pubB64 !== '');
check('vapid.public_key.length_65_bytes', strlen($pub) === 65,
    strlen($pub) . ' bytes');
check('vapid.public_key.uncompressed_prefix_0x04', $pub !== '' && $pub[0] === "\x04");

/* ------- 2. private key path + location ------- */
$priv = (string) ($push['vapid_private_key_path'] ?? '');
check('vapid.private_key.path.set', $priv !== '');
$exists = $priv !== '' && is_file($priv);
check('vapid.private_key.path.exists', $exists);
if ($priv !== '') {
    $realWeb  = realpath($ROOT);
    $realPriv = realpath($priv);
    $outside  = $realPriv !== false && $realWeb !== false && !str_starts_with($realPriv, $realWeb);
    check('vapid.private_key.path.outside_webroot', $outside, $priv);
}

/* ------- 3. private key parses as ES256 (P-256) ------- */
$pkey = false;
if ($exists) {
    $pkey = @openssl_pkey_get_private('file://' . $priv);
    check('vapid.private_key.parse', $pkey !== false);

    if ($pkey !== false) {
        $det = openssl_pkey_get_details($pkey);
        $isEc = is_array($det) && ($det['type'] ?? -1) === OPENSSL_KEYTYPE_EC;
        $isP256 = $isEc && ($det['ec']['curve_name'] ?? '') === 'prime256v1';
        check('vapid.private_key.curve.prime256v1', $isP256, $det['ec']['curve_name'] ?? '(unknown)');

        // 4. Derived public must match configured public.
        if ($isP256 && $pub !== '') {
            $ex = str_pad((string) $det['ec']['x'], 32, "\x00", STR_PAD_LEFT);
            $ey = str_pad((string) $det['ec']['y'], 32, "\x00", STR_PAD_LEFT);
            $derivedPub = "\x04" . $ex . $ey;
            check('vapid.keypair.matches', $derivedPub === $pub,
                $derivedPub === $pub ? '' : 'derived != configured public');
        }
    }
}

/* ------- 5. mailto: subject ------- */
$subj = (string) ($push['vapid_subject'] ?? '');
check('vapid.subject.mailto', $subj !== '' && strncasecmp($subj, 'mailto:', 7) === 0, $subj);

/* ------- 6. file permissions (best-effort on Unix, harmless on Windows) ------- */
if ($exists && DIRECTORY_SEPARATOR === '/') {
    $perms = fileperms($priv) & 0o777;
    check('vapid.private_key.mode.no_world_read', ($perms & 0o004) === 0,
        sprintf('mode=%04o', $perms));
}

echo "---\n";
echo $fail === 0 ? "vapid = PASS\n" : "vapid = FAIL ($fail check(s))\n";
exit($fail === 0 ? 0 : 1);
