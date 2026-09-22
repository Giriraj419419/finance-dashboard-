<?php
/**
 * scripts/production-smoke-test.php
 *
 * Non-destructive black-box HTTP smoke test against a deployed Finance
 * Dashboard. It performs safe read-only GETs and asserts:
 *   * HTTPS is served
 *   * HSTS + security headers are present on the login page
 *   * HTTP request is 301-redirected to HTTPS
 *   * /login.php returns 200 and contains the sign-in form
 *   * /sw.js returns 200 with a JS content-type and the expected handler names
 *   * /dashboard.php redirects unauthenticated clients to /login.php
 *   * /healthcheck.php returns 200 or 503 with the expected JSON keys
 *   * /config.php returns 403 (blocked by .htaccess)
 *   * /database/schema.sql returns 403 (blocked by .htaccess)
 *
 * Usage:
 *   php scripts/production-smoke-test.php --url=https://finance.kktechsolutions.in
 *
 * Exit code 0 if every check passes, 1 otherwise.
 * This script performs NO writes and NO logins. Safe to run against prod.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "CLI-only tool.\n";
    exit(1);
}

$base = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--url=')) $base = rtrim(substr($a, 6), '/');
}
if ($base === null) {
    fwrite(STDERR, "usage: php scripts/production-smoke-test.php --url=https://<host>\n");
    exit(2);
}

$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $fail;
    $mark = $ok ? 'PASS' : 'FAIL';
    if (!$ok) $fail++;
    printf("%-4s  %s%s\n", $mark, $name, $detail === '' ? '' : "  ({$detail})");
}

/**
 * Perform a GET and return [status, headers, body]. Does not follow
 * redirects — the caller inspects the Location header.
 */
function http_get(string $url, int $timeout = 15): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'FinanceDashboard-Smoke/1.0',
    ]);
    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err    = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return [0, [], 'curl:' . $err];
    $rawStr  = (string) $raw;
    $headers = _parse_headers(substr($rawStr, 0, $hsize));
    $body    = substr($rawStr, $hsize);
    return [$status, $headers, $body];
}

function _parse_headers(string $block): array
{
    $out = [];
    foreach (explode("\r\n", $block) as $line) {
        if (($p = strpos($line, ':')) !== false) {
            $out[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
        }
    }
    return $out;
}

/* -------- 1. HTTPS + login page -------- */
[$st, $h, $body] = http_get($base . '/login.php');
check('login.https_200', $st === 200, "status=$st");
check('login.contains_sign_in_form',
    is_string($body) && str_contains($body, 'name="email"') && str_contains($body, 'name="password"'));

/* -------- 2. security headers on login page -------- */
$hsts = $h['strict-transport-security'] ?? '';
check('headers.hsts', str_contains($hsts, 'max-age=') &&
    (int) (preg_match('/max-age=(\d+)/', $hsts, $m) ? $m[1] : 0) >= 15552000,
    $hsts ?: '(missing)');
check('headers.x_content_type_options',
    ($h['x-content-type-options'] ?? '') === 'nosniff');
check('headers.x_frame_options',
    in_array(strtolower($h['x-frame-options'] ?? ''), ['sameorigin', 'deny'], true));
check('headers.referrer_policy',
    ($h['referrer-policy'] ?? '') !== '');

/* -------- 3. http→https redirect -------- */
if (str_starts_with($base, 'https://')) {
    $httpBase = 'http://' . substr($base, 8);
    [$st, $h] = http_get($httpBase . '/login.php');
    $loc = strtolower($h['location'] ?? '');
    check('redirect.http_to_https',
        in_array($st, [301, 302, 307, 308], true) && str_starts_with($loc, 'https://'),
        "status=$st loc=" . ($h['location'] ?? '(missing)'));
}

/* -------- 4. sw.js served + shape -------- */
[$st, $h, $body] = http_get($base . '/sw.js');
check('sw.js.status_200', $st === 200);
$ct = strtolower($h['content-type'] ?? '');
check('sw.js.content_type_js',
    str_contains($ct, 'javascript'), $ct ?: '(missing)');
check('sw.js.has_push_listener',
    is_string($body) && str_contains($body, "addEventListener('push'"));
check('sw.js.has_notificationclick_listener',
    is_string($body) && str_contains($body, "addEventListener('notificationclick'"));

/* -------- 5. dashboard redirects when logged out -------- */
[$st, $h] = http_get($base . '/dashboard.php');
$loc = strtolower($h['location'] ?? '');
check('dashboard.protects_route',
    in_array($st, [301, 302, 303, 307, 308], true) && str_contains($loc, '/login.php'),
    "status=$st loc=" . ($h['location'] ?? '(missing)'));

/* -------- 6. health endpoint -------- */
[$st, $h, $body] = http_get($base . '/healthcheck.php');
check('healthcheck.status_200_or_503', in_array($st, [200, 503], true), "status=$st");
$json = json_decode($body ?? '', true);
check('healthcheck.json_shape',
    is_array($json) && isset($json['status'], $json['database'], $json['schema']));

/* -------- 7. protected files -------- */
$protected = ['/config.php', '/database/schema.sql', '/includes/header.php', '/cron/reminder-worker.php'];
foreach ($protected as $p) {
    [$st] = http_get($base . $p);
    check("blocked.$p", $st === 403 || $st === 404, "status=$st");
}

echo "---\n";
echo $fail === 0 ? "smoke = PASS\n" : "smoke = FAIL ($fail check(s))\n";
exit($fail === 0 ? 0 : 1);
