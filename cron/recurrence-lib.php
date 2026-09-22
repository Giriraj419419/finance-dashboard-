<?php
/**
 * Recurrence math for the reminder worker. Extracted from
 * cron/reminder-worker.php so the two pure functions are unit-testable in
 * CI. Both are still called from the worker via `require_once`.
 */
declare(strict_types=1);

if (!defined('MAX_RECURRENCE_ITERATIONS')) {
    // Fallback for callers (like the test harness) that do not define the
    // worker's own constant. The worker itself defines this earlier.
    define('MAX_RECURRENCE_ITERATIONS', 500);
}

/**
 * Advance a datetime by the reminder's recurrence unit.
 * Returns null when the recurrence type is not recognised.
 */
function next_occurrence(DateTimeImmutable $dt, string $recurrence): ?DateTimeImmutable
{
    switch ($recurrence) {
        case 'daily':     return $dt->modify('+1 day');
        case 'weekly':    return $dt->modify('+1 week');
        case 'monthly':
            // Preserve original day-of-month where possible; when the target
            // month has fewer days, PHP's +1 month rolls forward which is
            // usually not desired. Clamp to the last day of the target month.
            $target = $dt->modify('first day of next month');
            $desiredDay = (int) $dt->format('j');
            $daysInTarget = (int) $target->format('t');
            $day = min($desiredDay, $daysInTarget);
            return $target->setDate((int) $target->format('Y'), (int) $target->format('n'), $day)
                          ->setTime((int) $dt->format('G'), (int) $dt->format('i'), (int) $dt->format('s'));
        case 'yearly':    return $dt->modify('+1 year');
        default:          return null;
    }
}

/**
 * Compute every occurrence that falls in [start, end], starting from
 * `base_dt` and advancing by `recurrence`. Non-recurring reminders
 * return at most one date (base_dt) if it fits the window.
 */
function occurrences_in_window(DateTimeImmutable $base_dt, string $recurrence, ?DateTimeImmutable $rec_end,
                                DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $out = [];
    if ($recurrence === 'none' || $recurrence === '') {
        if ($base_dt >= $start && $base_dt <= $end) $out[] = $base_dt;
        return $out;
    }

    $cur = $base_dt;
    for ($i = 0; $i < MAX_RECURRENCE_ITERATIONS && $cur < $start; $i++) {
        $next = next_occurrence($cur, $recurrence);
        if ($next === null || $next <= $cur) break;
        $cur = $next;
    }
    for ($i = 0; $i < MAX_RECURRENCE_ITERATIONS && $cur <= $end; $i++) {
        if ($rec_end !== null && $cur->format('Y-m-d') > $rec_end->format('Y-m-d')) break;
        if ($cur >= $start) $out[] = $cur;
        $next = next_occurrence($cur, $recurrence);
        if ($next === null || $next <= $cur) break;
        $cur = $next;
    }
    return $out;
}
