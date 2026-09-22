<?php
/**
 * Runs scripts/security-scan.php against the working tree and asserts it
 * exits with 0. This is what stops someone from committing a piece of
 * code that would have tripped the R1..R5 rules.
 */
declare(strict_types=1);

require __DIR__ . '/test-harness.php';

it('security-scan reports no findings on the current tree', function () {
    $script = dirname(__DIR__) . '/scripts/security-scan.php';
    $cmd = [PHP_BINARY, $script];

    $descriptors = [
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w'],  // stderr
    ];
    $proc = proc_open($cmd, $descriptors, $pipes);
    assert_true(is_resource($proc), 'failed to launch security-scan.php');

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);

    if ($exit !== 0) {
        throw new RuntimeException(
            "security-scan exited with code {$exit}.\n" .
            "STDOUT:\n{$stdout}\n" .
            "STDERR:\n{$stderr}\n"
        );
    }
    // Sanity check on the output shape.
    assert_contains('security-scan: PASS', $stdout);
});
