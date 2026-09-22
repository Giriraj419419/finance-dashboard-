<?php
/**
 * Unit tests for the recurrence math the reminder worker relies on.
 * Exercises the real functions from cron/recurrence-lib.php so a future
 * regression in date math is caught before the closed-tab reminder path
 * fires early, late, or never.
 */
declare(strict_types=1);

require __DIR__ . '/test-harness.php';
require_once dirname(__DIR__) . '/cron/recurrence-lib.php';

function _t_dt(string $s): DateTimeImmutable { return new DateTimeImmutable($s); }

it('daily recurrence advances by one calendar day', function () {
    $out = next_occurrence(_t_dt('2026-01-31 08:00:00'), 'daily');
    assert_eq($out->format('Y-m-d H:i:s'), '2026-02-01 08:00:00');
});

it('weekly recurrence advances by seven days', function () {
    $out = next_occurrence(_t_dt('2026-06-05 10:15:00'), 'weekly');
    assert_eq($out->format('Y-m-d H:i:s'), '2026-06-12 10:15:00');
});

it('monthly recurrence clamps to the last day when the target month is shorter', function () {
    // Jan 31 + monthly should land on Feb 28 (not roll forward to March).
    // 2026 is NOT a leap year.
    $out = next_occurrence(_t_dt('2026-01-31 09:00:00'), 'monthly');
    assert_eq($out->format('Y-m-d H:i:s'), '2026-02-28 09:00:00');
});

it('yearly recurrence advances by 12 months', function () {
    $out = next_occurrence(_t_dt('2026-03-01 12:00:00'), 'yearly');
    assert_eq($out->format('Y-m-d H:i:s'), '2027-03-01 12:00:00');
});

it('unrecognised recurrence returns null', function () {
    assert_eq(next_occurrence(_t_dt('2026-06-01 00:00:00'), 'bogus'), null);
});

it('non-recurring reminder yields base date only when in the window', function () {
    $in = occurrences_in_window(
        _t_dt('2026-06-10 12:00:00'), 'none', null,
        _t_dt('2026-06-10 00:00:00'), _t_dt('2026-06-11 00:00:00'));
    assert_eq(count($in), 1);

    $out = occurrences_in_window(
        _t_dt('2026-06-10 12:00:00'), 'none', null,
        _t_dt('2026-06-11 00:00:00'), _t_dt('2026-06-12 00:00:00'));
    assert_eq(count($out), 0);
});

it('daily reminder enumerates every day inside the window', function () {
    $occ = occurrences_in_window(
        _t_dt('2026-06-01 09:00:00'), 'daily', null,
        _t_dt('2026-06-03 00:00:00'), _t_dt('2026-06-06 23:59:59'));
    $days = array_map(static fn ($d) => $d->format('Y-m-d'), $occ);
    assert_eq($days, ['2026-06-03', '2026-06-04', '2026-06-05', '2026-06-06']);
});

it('respects recurrence_end_date and stops early', function () {
    $end = _t_dt('2026-06-04 23:59:59');
    $occ = occurrences_in_window(
        _t_dt('2026-06-01 09:00:00'), 'daily', $end,
        _t_dt('2026-06-03 00:00:00'), _t_dt('2026-06-10 23:59:59'));
    $days = array_map(static fn ($d) => $d->format('Y-m-d'), $occ);
    assert_eq($days, ['2026-06-03', '2026-06-04']);
});
