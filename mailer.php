<?php
/**
 * cPanel-friendly SMTP mailer over fsockopen. No Composer packages.
 *
 * Supports two common cPanel configurations:
 *   - Implicit SSL on port 465          (mail.secure = true)
 *   - Explicit STARTTLS on port 587     (mail.secure = false, port = 587)
 *
 * Sends a multipart/alternative message (text + HTML). Returns true on the
 * "250 OK" after DATA, false on any transport error. Never leaks credentials
 * to the caller.
 */

require_once __DIR__ . '/functions.php';

function send_mail(string $to, string $subject, string $htmlBody, string $textBody = ''): bool
{
    $cfg = app_config('mail');
    if (empty($cfg['host']) || empty($cfg['from_email'])) {
        error_log('[mailer] not configured (mail.host / mail.from_email missing)');
        return false;
    }

    // Local test hook: writing to a dev inbox instead of an SMTP server.
    if (($cfg['host'] ?? '') === 'log-only') {
        error_log(sprintf('[mailer:log-only] to=%s subject=%s bytes=%d', $to, $subject, strlen($htmlBody)));
        return true;
    }

    if ($textBody === '') {
        $textBody = trim(strip_tags($htmlBody));
    }

    $host      = (string) $cfg['host'];
    $port      = (int) ($cfg['port'] ?? 587);
    $useSsl    = (bool) ($cfg['secure'] ?? false);
    $username  = (string) ($cfg['username'] ?? '');
    $password  = (string) ($cfg['password'] ?? '');
    $fromEmail = (string) $cfg['from_email'];
    $fromName  = (string) ($cfg['from_name'] ?? 'Finance Dashboard');

    $connectHost = $useSsl ? ('ssl://' . $host) : $host;

    $errno = 0;
    $errstr = '';
    $sock = @fsockopen($connectHost, $port, $errno, $errstr, 15);
    if (!$sock) {
        error_log(sprintf('[mailer] connect failed: %s (%d)', $errstr, $errno));
        return false;
    }
    stream_set_timeout($sock, 15);

    $write = static function (string $line) use ($sock): void {
        fwrite($sock, $line . "\r\n");
    };
    $read = static function () use ($sock): string {
        $out = '';
        while (!feof($sock)) {
            $line = fgets($sock, 1024);
            if ($line === false) break;
            $out .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $out;
    };
    $expect = static function (string $stage, int $code) use ($read, $sock): bool {
        $resp = $read();
        if ((int) substr($resp, 0, 3) !== $code) {
            error_log(sprintf('[mailer] stage=%s expected=%d got=%s', $stage, $code, trim($resp)));
            @fclose($sock);
            return false;
        }
        return true;
    };

    // Banner
    if (!$expect('banner', 220)) return false;

    $ehloHost = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $write('EHLO ' . $ehloHost);
    if (!$expect('ehlo', 250)) return false;

    // STARTTLS on 587
    if (!$useSsl && $port === 587) {
        $write('STARTTLS');
        if (!$expect('starttls', 220)) return false;
        if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            error_log('[mailer] TLS negotiation failed');
            @fclose($sock);
            return false;
        }
        $write('EHLO ' . $ehloHost);
        if (!$expect('ehlo-after-tls', 250)) return false;
    }

    // AUTH LOGIN
    if ($username !== '') {
        $write('AUTH LOGIN');
        if (!$expect('auth-login', 334)) return false;
        $write(base64_encode($username));
        if (!$expect('auth-user', 334)) return false;
        $write(base64_encode($password));
        if (!$expect('auth-pass', 235)) return false;
    }

    $write('MAIL FROM:<' . $fromEmail . '>');
    if (!$expect('mail-from', 250)) return false;
    $write('RCPT TO:<' . $to . '>');
    if (!$expect('rcpt-to', 250)) return false;

    $write('DATA');
    if (!$expect('data', 354)) return false;

    $boundary = 'fd-' . bin2hex(random_bytes(8));
    $headers = [
        'From: ' . _encode_header_addr($fromName, $fromEmail),
        'To: <' . $to . '>',
        'Subject: ' . _encode_header_text($subject),
        'Date: ' . date(DATE_RFC2822),
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $ehloHost . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];
    $body =
        "--{$boundary}\r\n" .
        "Content-Type: text/plain; charset=UTF-8\r\n" .
        "Content-Transfer-Encoding: 8bit\r\n\r\n" .
        _dot_stuff($textBody) . "\r\n\r\n" .
        "--{$boundary}\r\n" .
        "Content-Type: text/html; charset=UTF-8\r\n" .
        "Content-Transfer-Encoding: 8bit\r\n\r\n" .
        _dot_stuff($htmlBody) . "\r\n\r\n" .
        "--{$boundary}--\r\n";

    $write(implode("\r\n", $headers) . "\r\n");
    $write($body);
    $write('.');
    if (!$expect('data-end', 250)) return false;

    $write('QUIT');
    @fclose($sock);
    return true;
}

/** RFC 5321 dot-stuffing for lines beginning with "." */
function _dot_stuff(string $s): string
{
    return preg_replace('/^\./m', '..', $s) ?? $s;
}

function _encode_header_text(string $s): string
{
    // Very light MIME encoding; keeps ASCII plain, wraps anything else.
    return preg_match('/[\x80-\xff]/', $s) === 1
        ? '=?UTF-8?B?' . base64_encode($s) . '?='
        : $s;
}

function _encode_header_addr(string $name, string $email): string
{
    return _encode_header_text($name) . ' <' . $email . '>';
}
