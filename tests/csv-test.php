<?php
/**
 * CSV formula-injection regression tests. Exercises the real csv_cell()
 * and write_rows() from csv-lib.php.
 */
declare(strict_types=1);

require __DIR__ . '/test-harness.php';
require_once dirname(__DIR__) . '/csv-lib.php';

it('empty and scalar values pass through unchanged', function () {
    assert_eq(csv_cell(''), '');
    assert_eq(csv_cell(null), '');
    assert_eq(csv_cell(0), '0');
    assert_eq(csv_cell('hello'), 'hello');
});

it('prefixes leading = to neutralize formulas', function () {
    $v = csv_cell('=SUM(A1:A2)');
    assert_true(str_starts_with($v, "'"), "expected leading ' guard, got: {$v}");
});

it('prefixes leading + / - / @ (Excel treats these as formula starts)', function () {
    foreach (['+CMD', '-10+20', '@SUM'] as $s) {
        $v = csv_cell($s);
        assert_true(str_starts_with($v, "'"), "expected leading ' for {$s}, got: {$v}");
    }
});

it('prefixes leading TAB/CR/LF/pipe to prevent hidden formula tricks', function () {
    foreach (["\t", "\r", "\n", "|"] as $prefix) {
        $v = csv_cell($prefix . 'anything');
        assert_true(str_starts_with($v, "'"), 'expected leading guard for ' . bin2hex($prefix));
    }
});

it('leaves commas / quotes / newlines to fputcsv (write_rows quotes them)', function () {
    // csv_cell only neutralises formula starts; the RFC 4180 quoting is
    // done by fputcsv() at write time. So the cell itself is unchanged...
    assert_eq(csv_cell('hello, "world"'), 'hello, "world"');

    // ...and write_rows, which calls fputcsv, emits the correctly-quoted form.
    ob_start();
    write_rows(['a', 'b'], [['hello, "world"', '=DANGEROUS()']]);
    $out = ob_get_clean();
    assert_contains('""world""', $out);       // fputcsv doubles the internal "
    assert_contains("'=DANGEROUS()", $out);   // formula guard survived the write
});

it('preserves numeric-looking strings without any injection guard', function () {
    assert_eq(csv_cell('123.45'), '123.45');
    assert_eq(csv_cell(123.45), '123.45');
});
