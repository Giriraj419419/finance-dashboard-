<?php
/**
 * XSS regression tests. Runs a matrix of nasty payloads through the
 * project's `e()` helper (used by every `<?= ... ?>` output in the app)
 * and asserts none of them can produce an executable tag/attribute in
 * an HTML body. This is what would catch a future refactor that
 * accidentally drops the `e()` wrapper.
 */
declare(strict_types=1);

require __DIR__ . '/test-harness.php';

$ROOT = dirname(__DIR__);
// e() is defined in functions.php. Bootstrapping it does not require a DB.
require_once $ROOT . '/functions.php';
app_config('app'); // warm config

$payloads = [
    '<script>alert(1)</script>',
    "<img src=x onerror=alert(1)>",
    '"><svg/onload=alert(1)>',
    "javascript:alert(1)",
    "\">'><body onload=alert(1)>",
    "<iframe src=\"javascript:alert(1)\"></iframe>",
    "<a href=\"javas\tcript:alert(1)\">x</a>",
    "<b onmouseover=alert(1)>hover</b>",
    "'; DROP TABLE users;--",           // SQL-injection is bound-param-guarded; here we just verify e() still escapes
    "&#x3C;script&#x3E;alert(1)&#x3C;/script&#x3E;", // pre-escaped attack
];

it('e() encodes every unsafe byte so no HTML/attribute injection survives', function () use ($payloads) {
    // In HTML body / attribute context, encoding <, >, ", and ' is
    // sufficient to prevent every payload class listed above from
    // becoming an executable tag or attribute. Once those bytes are
    // encoded, `onerror=`, `alert(1)`, and `javascript:` remain as
    // inert text — they have no tag to attach to. So the correctness
    // assertion is on the encoding, not on the absence of the words.
    foreach ($payloads as $p) {
        $out = e($p);
        assert_true(!str_contains($out, '<'), "unescaped < survived for payload: {$p}  =>  {$out}");
        assert_true(!str_contains($out, '>'), "unescaped > survived for payload: {$p}  =>  {$out}");
        assert_true(!str_contains($out, '"'), "unescaped \" survived for payload: {$p}  =>  {$out}");
        assert_true(!str_contains($out, "'"), "unescaped ' survived for payload: {$p}  =>  {$out}");
    }
});

it('e() escapes ATTRIBUTE-context single and double quotes (ENT_QUOTES semantics)', function () {
    $out = e("' onerror='alert(1)");
    // Both single and double quotes must be encoded, or attribute-context
    // injection would still be possible.
    assert_true(!str_contains($out, "'"), "unescaped ' in: {$out}");
    assert_true(!str_contains($out, '"'), "unescaped \" in: {$out}");
});

it('e() renders null and empty inputs as empty string', function () {
    assert_eq(e(null), '');
    assert_eq(e(''), '');
});
