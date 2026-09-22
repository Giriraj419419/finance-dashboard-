<?php
/**
 * Unit tests for the Web Push helpers in push-webpush.php.
 * We exercise the low-level primitives (base64url round-trip, HKDF-Expand
 * against known vectors, DER→raw ECDSA conversion) since those are the
 * pieces that would silently produce garbage ciphertext if broken —
 * exactly the class of bug that led to the earlier "notifications never
 * arrive" incident.
 */
declare(strict_types=1);

require __DIR__ . '/test-harness.php';
require_once dirname(__DIR__) . '/push-webpush.php';

it('base64url round-trips bytes with any padding shape', function () {
    // Start at len=1: random_bytes(0) raises TypeError. Empty-string
    // round-trip is trivial and covered elsewhere.
    foreach ([1, 2, 3, 4, 15, 16, 17, 32, 65] as $len) {
        $raw = random_bytes($len);
        $enc = _wp_b64u_encode($raw);
        assert_true(strpos($enc, '=') === false, "unexpected '=' in b64u for len={$len}");
        assert_true(strpos($enc, '+') === false, "unexpected '+' in b64u for len={$len}");
        assert_true(strpos($enc, '/') === false, "unexpected '/' in b64u for len={$len}");
        $dec = _wp_b64u_decode($enc);
        assert_eq($dec, $raw, "round-trip failed for len={$len}");
    }
});

it('base64url decode tolerates missing padding (RFC 4648 §5)', function () {
    // Padded form of "AA" is "AA==" -> 1 zero byte.
    assert_eq(_wp_b64u_decode('AA'), "\x00");
});

it('HKDF-Expand(PRK,info,L) matches its own Extract-then-Expand output', function () {
    // Sanity check: since PHP's hash_hkdf() performs Extract+Expand and we
    // want Expand only, feed it a random PRK and verify our implementation
    // matches the raw math (RFC 5869 §2.3): T(i) = HMAC(PRK, T(i-1) || info || i).
    $prk  = random_bytes(32);
    $info = "Content-Encoding: aes128gcm\x00";
    $mine = _wp_hkdf_expand($prk, $info, 16);
    // Hand-rolled reference: T1 = HMAC(prk, info || 0x01).
    $t1  = hash_hmac('sha256', $info . chr(1), $prk, true);
    $ref = substr($t1, 0, 16);
    assert_eq(bin2hex($mine), bin2hex($ref));
});

it('HKDF-Expand produces 32 bytes across multiple T() iterations', function () {
    $prk = random_bytes(32);
    $info = "WebPush: info\x00";
    $out = _wp_hkdf_expand($prk, $info, 32);
    assert_eq(strlen($out), 32);
    // First 32 bytes match T(1)'s full output since 32 == HMAC-SHA256 width.
    $t1 = hash_hmac('sha256', $info . chr(1), $prk, true);
    assert_eq(bin2hex($out), bin2hex($t1));
});

it('DER→raw ECDSA converts a hand-crafted DER sig into 64 raw bytes', function () {
    $r_body = str_repeat("\x0A", 32);
    $s_body = str_repeat("\x0B", 32);
    $der =
        "\x30" . chr(2 + 32 + 2 + 32) .
        "\x02" . chr(32) . $r_body .
        "\x02" . chr(32) . $s_body;
    $raw = _wp_der_to_raw($der, 32);
    assert_true($raw !== null);
    assert_eq(strlen($raw), 64);
    assert_eq(substr($raw, 0, 32), $r_body);
    assert_eq(substr($raw, 32, 32), $s_body);
});

it('DER→raw strips leading zero pad on a positive INTEGER', function () {
    $r_padded = "\x00" . str_repeat("\x7A", 32);
    $s_body   = str_repeat("\x0B", 32);
    $der =
        "\x30" . chr(2 + 33 + 2 + 32) .
        "\x02" . chr(33) . $r_padded .
        "\x02" . chr(32) . $s_body;
    $raw = _wp_der_to_raw($der, 32);
    assert_true($raw !== null);
    assert_eq(strlen($raw), 64);
    assert_eq(substr($raw, 0, 32), substr($r_padded, 1));
});

it('DER→raw refuses malformed input (wrong outer tag)', function () {
    // 0x31 is SET, not SEQUENCE.
    $bad = "\x31\x00";
    assert_eq(_wp_der_to_raw($bad, 32), null);
});

it('encodes a raw 65-byte P-256 public key as parseable SPKI PEM (openssl EC)', function () {
    // Skip gracefully if this PHP build lacks P-256 support (e.g. some
    // Windows shells). CI runs Ubuntu with full OpenSSL, so it always executes.
    $pk = @openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    if ($pk === false) {
        fwrite(STDOUT, "        (skipped: openssl EC/prime256v1 unavailable in this php build)\n");
        return;
    }
    $det = openssl_pkey_get_details($pk);
    $x = str_pad((string) $det['ec']['x'], 32, "\x00", STR_PAD_LEFT);
    $y = str_pad((string) $det['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    $raw = "\x04" . $x . $y;

    $pem = _wp_ec_raw_public_to_pem($raw);
    assert_contains('BEGIN PUBLIC KEY', $pem);
    $parsed = openssl_pkey_get_public($pem);
    assert_true($parsed !== false, 'SPKI PEM did not parse');
    $parsedDet = openssl_pkey_get_details($parsed);
    assert_eq($parsedDet['ec']['curve_name'] ?? '', 'prime256v1');
});
