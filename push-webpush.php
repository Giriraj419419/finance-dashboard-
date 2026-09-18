<?php
/**
 * push-webpush.php
 *
 * Minimal Web Push sender (RFC 8030 / 8291 / 8292) using only PHP core:
 *   - openssl for ECDH + ECDSA
 *   - hash_hmac for HKDF
 *   - curl (or stream_socket) for HTTPS
 *
 * VAPID (RFC 8292): ES256 JWT signed with the server's P-256 private key,
 * sent as "Authorization: vapid t=<jwt>, k=<base64url(server public key)>".
 *
 * Content encoding: "aes128gcm" (RFC 8188) as required by modern browsers.
 *
 * Payload format (raw bytes):
 *   [salt (16 B)] [rs (4 B BE)] [idlen (1 B)] [keyid=server_pub (idlen B)] [aead ciphertext]
 *
 * Where the AEAD key + nonce are derived per RFC 8291:
 *   ecdh_secret = ECDH(server_priv, client_p256dh_pub)
 *   PRK_key   = HMAC-SHA256(auth_secret, ecdh_secret)
 *   key_info  = "WebPush: info\x00" || client_pub || server_pub
 *   IKM       = HKDF-Expand(PRK_key, key_info, 32)
 *   PRK       = HMAC-SHA256(salt, IKM)
 *   CEK       = HKDF-Expand(PRK, "Content-Encoding: aes128gcm\x00", 16)
 *   NONCE     = HKDF-Expand(PRK, "Content-Encoding: nonce\x00",     12)
 *
 * Only ONE function is public: web_push_send($sub, $payload, $ttl = 86400).
 *
 * Requires:
 *   - config.php's push.vapid_public_key       (base64url)
 *   - config.php's push.vapid_private_key_path (PEM on disk)
 *
 * Returns [status_code, response_body, error_message]. status codes:
 *   201/202 -> success (message accepted)
 *   404/410 -> subscription is gone; caller must mark inactive
 *   others  -> transient / permanent failure
 */

require_once __DIR__ . '/functions.php';

function web_push_send(array $sub, string $payload, int $ttl = 86400): array
{
    $cfg = app_config('push');
    $pubB64  = (string) ($cfg['vapid_public_key'] ?? '');
    $privPem = (string) ($cfg['vapid_private_key_path'] ?? '');
    $sub_url = (string) ($cfg['vapid_subject'] ?? 'mailto:admin@example.com');

    if ($pubB64 === '' || $privPem === '' || !is_file($privPem)) {
        return [0, '', 'vapid keys not configured'];
    }

    $endpoint = (string) ($sub['endpoint']    ?? '');
    $p256dh   = (string) ($sub['p256dh_key']  ?? $sub['p256dh'] ?? '');
    $auth     = (string) ($sub['auth_key']    ?? $sub['auth']   ?? '');
    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        return [0, '', 'incomplete subscription'];
    }

    // ---- Load VAPID keys -------------------------------------------------
    $serverPub = _wp_b64u_decode($pubB64);
    if (strlen($serverPub) !== 65 || $serverPub[0] !== "\x04") {
        return [0, '', 'invalid vapid public key'];
    }
    $vapidPriv = openssl_pkey_get_private('file://' . $privPem);
    if ($vapidPriv === false) {
        return [0, '', 'cannot load vapid private key'];
    }

    // ---- Build the VAPID JWT (ES256) ------------------------------------
    $parts = parse_url($endpoint);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return [0, '', 'invalid endpoint url'];
    }
    $aud = $parts['scheme'] . '://' . $parts['host'];
    $jwtHeader  = _wp_b64u_encode('{"typ":"JWT","alg":"ES256"}');
    $jwtPayload = _wp_b64u_encode(json_encode([
        'aud' => $aud,
        'exp' => time() + 12 * 3600,
        'sub' => $sub_url,
    ], JSON_UNESCAPED_SLASHES));
    $signInput = $jwtHeader . '.' . $jwtPayload;
    if (!openssl_sign($signInput, $derSig, $vapidPriv, OPENSSL_ALGO_SHA256)) {
        return [0, '', 'vapid sign failed'];
    }
    // Convert DER ECDSA signature to raw R||S (64 bytes) as JWT requires.
    $rawSig = _wp_der_to_raw($derSig, 32);
    if ($rawSig === null) return [0, '', 'vapid signature encoding failed'];
    $jwt = $signInput . '.' . _wp_b64u_encode_raw($rawSig);

    // ---- Generate ephemeral ECDH keypair for message encryption ---------
    $ephPk = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name'       => 'prime256v1',
    ]);
    if ($ephPk === false) return [0, '', 'ephemeral keygen failed'];
    $ephDet = openssl_pkey_get_details($ephPk);
    if (!$ephDet) return [0, '', 'ephemeral details failed'];
    $ephX = str_pad($ephDet['ec']['x'], 32, "\x00", STR_PAD_LEFT);
    $ephY = str_pad($ephDet['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    $ephPubRaw = "\x04" . $ephX . $ephY;

    // Client's P-256 public key (raw 65B) and auth secret (16B).
    $clientPub  = _wp_b64u_decode($p256dh);
    $authSecret = _wp_b64u_decode($auth);
    if (strlen($clientPub) !== 65 || $clientPub[0] !== "\x04" || strlen($authSecret) !== 16) {
        return [0, '', 'invalid subscription keys'];
    }

    // ECDH shared secret = X coordinate of client_pub multiplied by eph_priv.
    // openssl_dh_compute_key needs a PKey resource for the client public key.
    $clientPubPem = _wp_ec_raw_public_to_pem($clientPub);
    $clientPubRes = openssl_pkey_get_public($clientPubPem);
    if ($clientPubRes === false) return [0, '', 'client pub key parse failed'];
    $ecdh = openssl_pkey_derive($clientPubRes, $ephPk, 32);
    if ($ecdh === false || strlen($ecdh) !== 32) return [0, '', 'ecdh derive failed'];

    // ---- RFC 8291 key/nonce derivation ----------------------------------
    // PRK_key = HKDF-Extract(auth_secret, ecdh)  = HMAC-SHA256(auth_secret, ecdh)
    $prkKey = hash_hmac('sha256', $ecdh, $authSecret, true);

    // key_info = "WebPush: info\0" || ua_public(client) || as_public(server_eph)
    $keyInfo = "WebPush: info\x00" . $clientPub . $ephPubRaw;
    // IKM = HKDF-Expand(PRK_key, key_info, 32)
    $ikm = _wp_hkdf_expand($prkKey, $keyInfo, 32);

    // salt (16B random) → PRK = HMAC(salt, IKM) → CEK, NONCE
    $salt = random_bytes(16);
    $prk  = hash_hmac('sha256', $ikm, $salt, true);
    $cek  = _wp_hkdf_expand($prk, "Content-Encoding: aes128gcm\x00", 16);
    $nonce = _wp_hkdf_expand($prk, "Content-Encoding: nonce\x00", 12);

    // ---- Pad + AES-128-GCM encrypt --------------------------------------
    // Per RFC 8188: plaintext = payload || 0x02 || padding (0x00)*
    $plaintext = $payload . "\x02";
    $rs = 4096; // record size — we always fit in one record
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false) return [0, '', 'aead encrypt failed'];
    $aead = $ciphertext . $tag;

    // ---- Assemble aes128gcm content-coding header + body ----------------
    // header = salt(16) || rs(4 BE) || idlen(1) || keyid(idlen)
    $keyId = $ephPubRaw; // 65 bytes
    $body = $salt . pack('N', $rs) . chr(strlen($keyId)) . $keyId . $aead;

    // ---- POST to the endpoint -------------------------------------------
    $headers = [
        'Content-Type: application/octet-stream',
        'Content-Encoding: aes128gcm',
        'TTL: ' . (int) $ttl,
        'Content-Length: ' . strlen($body),
        'Authorization: vapid t=' . $jwt . ', k=' . $pubB64,
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false && $code === 0) {
        return [0, '', 'curl error: ' . $err];
    }
    return [$code, (string) $resp, ''];
}

// ------------------------------------------------------------------------
// helpers
// ------------------------------------------------------------------------
function _wp_b64u_encode(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}
function _wp_b64u_encode_raw(string $s): string
{
    return _wp_b64u_encode($s);
}
function _wp_b64u_decode(string $s): string
{
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    $out = base64_decode(strtr($s, '-_', '+/'), true);
    return $out === false ? '' : $out;
}

/**
 * HKDF-Expand(PRK, info, L) with SHA-256 (RFC 5869).
 *
 * DO NOT use PHP's built-in hash_hkdf() here — it always performs the full
 * Extract+Expand cycle (treats its "key" arg as IKM and does an Extract
 * with the given salt before Expand). We already have a PRK from an
 * earlier Extract, so we need Expand ONLY. Doing another Extract on top
 * produces a different key than the browser derives, and the ciphertext
 * decrypts to garbage on the client → notification silently dropped.
 *
 * L ≤ 32 for our use, so N=1 iteration is enough.
 */
function _wp_hkdf_expand(string $prk, string $info, int $length): string
{
    $t = '';
    $okm = '';
    $counter = 1;
    while (strlen($okm) < $length) {
        $t = hash_hmac('sha256', $t . $info . chr($counter), $prk, true);
        $okm .= $t;
        $counter++;
    }
    return substr($okm, 0, $length);
}

/**
 * Parse a DER-encoded ECDSA signature into raw R||S of length 2*$size.
 */
function _wp_der_to_raw(string $der, int $size): ?string
{
    // Sequence tag
    $offset = 0;
    if (strlen($der) < 2 || $der[$offset++] !== "\x30") return null;
    $len = ord($der[$offset++]);
    if ($len & 0x80) {
        $n = $len & 0x7f;
        if ($n > 2 || $offset + $n > strlen($der)) return null;
        $len = 0;
        for ($i = 0; $i < $n; $i++) $len = ($len << 8) | ord($der[$offset++]);
    }
    // Two INTEGER components
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        if ($offset >= strlen($der) || $der[$offset++] !== "\x02") return null;
        $l = ord($der[$offset++]);
        if ($offset + $l > strlen($der)) return null;
        $v = substr($der, $offset, $l);
        $offset += $l;
        // strip leading zero pad
        while (strlen($v) > 0 && $v[0] === "\x00") $v = substr($v, 1);
        if (strlen($v) > $size) return null;
        $out .= str_pad($v, $size, "\x00", STR_PAD_LEFT);
    }
    return $out;
}

/**
 * Wrap a raw uncompressed P-256 public key (65B) into a PEM SPKI so
 * openssl_pkey_get_public can parse it and openssl_pkey_derive can use it.
 */
function _wp_ec_raw_public_to_pem(string $raw): string
{
    // SPKI header for prime256v1 uncompressed public key.
    $spkiHeader = hex2bin(
        '3059' .   // SEQUENCE, 89 bytes
        '3013' .   //   SEQUENCE, 19 bytes
        '0607' .   //     OID, 7 bytes  (1.2.840.10045.2.1  ecPublicKey)
        '2a8648ce3d0201' .
        '0608' .   //     OID, 8 bytes  (1.2.840.10045.3.1.7  prime256v1)
        '2a8648ce3d030107' .
        '0342' .   //   BIT STRING, 66 bytes
        '00'       //     unused bits
    );
    $der = $spkiHeader . $raw;
    $b64 = chunk_split(base64_encode($der), 64, "\n");
    return "-----BEGIN PUBLIC KEY-----\n" . $b64 . "-----END PUBLIC KEY-----\n";
}
