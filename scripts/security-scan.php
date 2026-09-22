<?php
/**
 * scripts/security-scan.php
 *
 * Lightweight static pattern scan run in CI. It reports lines that look
 * like unsafe SQL string composition or unsafe echo of user input, then
 * exits non-zero if any finding survives the allowlist.
 *
 * This is a defence-in-depth guard — not a substitute for a real SAST
 * pipeline. The rules are deliberately narrow to keep false-positive
 * noise low:
 *
 *   R1: SQL string that directly concatenates $_GET/$_POST/$_REQUEST/$_COOKIE.
 *   R2: PDO `query(...)`/`prepare(...)` with a $_GET/$_POST literal in the
 *       argument.
 *   R3: `echo`/`print` of $_GET/$_POST/$_SERVER/$_COOKIE variables without
 *       an `e(...)` or `htmlspecialchars(...)` call on the same line.
 *   R4: `header('Location: ' . $_GET|$_POST|$_REQUEST)` open redirect.
 *   R5: `include`/`require` with a variable filename derived from user input.
 *
 * If a legitimate case must exist, add an inline suppression comment on
 * the offending line: `// security-scan: allow <rule>`.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "CLI-only tool.\n";
    exit(1);
}

$ROOT = dirname(__DIR__);
$exclude_dirs = [
    $ROOT . DIRECTORY_SEPARATOR . '.git',
    $ROOT . DIRECTORY_SEPARATOR . 'videos',
    $ROOT . DIRECTORY_SEPARATOR . 'uploads',
    $ROOT . DIRECTORY_SEPARATOR . 'docs',
    $ROOT . DIRECTORY_SEPARATOR . 'scripts', // do not scan the scanner
];

$rules = [
    'R1_sql_concat_super' => '/(?:executeQuery|fetchOne|fetchAll|->query|->prepare)\s*\(\s*[\'"][^\'"]*[\'"]\s*\.\s*\$_(GET|POST|REQUEST|COOKIE)/',
    'R2_pdo_super_in_arg' => '/(?:executeQuery|->query|->prepare)\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)\b/',
    'R3_echo_super'       => '/(?:echo|print)\s+\$_(GET|POST|SERVER|COOKIE)(?![\w])(?!.*(?:e\s*\(|htmlspecialchars\s*\())/',
    'R4_open_redirect'    => '/header\s*\(\s*[\'"][Ll]ocation:\s*[\'"]\s*\.\s*\$_(GET|POST|REQUEST|COOKIE)/',
    'R5_dyn_include'      => '/(?:include|require)(?:_once)?\s+\$_(GET|POST|REQUEST|COOKIE)/',
];

$files = [];
_collect_php($ROOT, $exclude_dirs, $files);

$findings = [];
foreach ($files as $f) {
    $lines = file($f, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $i => $line) {
        // Skip pure comment lines to keep noise low.
        $trim = ltrim($line);
        if ($trim === '' || str_starts_with($trim, '//') || str_starts_with($trim, '#') || str_starts_with($trim, '*')) {
            continue;
        }
        foreach ($rules as $ruleId => $pat) {
            if (preg_match($pat, $line)) {
                if (str_contains($line, 'security-scan: allow ' . $ruleId) ||
                    str_contains($line, 'security-scan: allow all')) {
                    continue;
                }
                $findings[] = [
                    'file' => str_replace($ROOT . DIRECTORY_SEPARATOR, '', $f),
                    'line' => $i + 1,
                    'rule' => $ruleId,
                    'src'  => trim($line),
                ];
            }
        }
    }
}

if ($findings === []) {
    echo "security-scan: PASS (rules R1..R5, " . count($files) . " files)\n";
    exit(0);
}

echo "security-scan: FAIL\n";
foreach ($findings as $f) {
    printf("  %s:%d  [%s]  %s\n", $f['file'], $f['line'], $f['rule'], $f['src']);
}
exit(1);

function _collect_php(string $dir, array $excl, array &$out): void
{
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            static function ($current) use ($excl): bool {
                foreach ($excl as $e) {
                    if (str_starts_with((string) $current, $e)) return false;
                }
                return true;
            }
        )
    );
    foreach ($it as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $out[] = $file->getPathname();
        }
    }
}
