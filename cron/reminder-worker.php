<?php
/**
 * cron/reminder-worker.php
 *
 * PRIMARY reminder delivery mechanism. Runs via cPanel Cron Job
 * (recommended: every 5 minutes). Emails due reminders through the
 * existing SMTP mailer. Idempotent across concurrent runs.
 *
 * Cron command (adjust the path for your account):
 *   php /home/kktechsolutions/public_html/finance.kktechsolutions.in/cron/reminder-worker.php
 *
 * Design:
 *   1. For every active reminder, compute the "current occurrence"
 *      timestamp (accounting for notification_offset_minutes AND recurrence).
 *   2. INSERT a claim row into reminder_notifications (UNIQUE key prevents
 *      duplicate claims across concurrent cron runs). If insert fails on
 *      the UNIQUE, another process already owns this occurrence.
 *   3. Send the email via send_mail().
 *   4. Update the claim row: sent / failed / retry.
 *   5. Missed occurrences within REMINDER_CATCHUP_WINDOW_HOURS are still
 *      processed on next run; anything older is skipped.
 *   6. Retries up to MAX_ATTEMPTS. After the cap, status = 'failed'.
 *
 * NOTHING sensitive is logged: no passwords, tokens, SMTP creds, or full
 * user records.
 */

// -------- CLI-only guard --------
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "CLI-only worker.\n";
    exit(1);
}

// -------- Config knobs --------
const MAX_ATTEMPTS                    = 3;
const REMINDER_CATCHUP_WINDOW_HOURS   = 24;
const OCCURRENCE_LOOKAHEAD_MINUTES    = 10;   // schedule near-future occurrences
const MAX_RECURRENCE_ITERATIONS       = 500;  // safety valve for date math

// -------- Bootstrapping (absolute paths only — CLI can run from anywhere) --------
$ROOT = dirname(__DIR__);
require_once $ROOT . '/functions.php';
require_once $ROOT . '/database.php';
require_once $ROOT . '/mailer.php';

$app = app_config('app');
if (!empty($app['timezone'])) {
    @date_default_timezone_set($app['timezone']);
}
$app_name = $app['name'] ?? 'Finance Dashboard';
$base_url = $app['base_url'] ?? '';

/**
 * Compact worker-local logger. Writes to error_log only (no file bloat).
 */
function wlog(string $level, string $msg, array $ctx = []): void
{
    $line = '[reminder-worker][' . $level . '] ' . $msg;
    if ($ctx !== []) {
        $safe = [];
        foreach ($ctx as $k => $v) {
            $safe[$k] = is_scalar($v) || $v === null ? $v : '<obj>';
        }
        $line .= ' ' . json_encode($safe, JSON_UNESCAPED_SLASHES);
    }
    error_log($line);
}

/**
 * Update a metric_key in system_health. Best-effort.
 */
function health_set(string $key, string $value): void
{
    try {
        executeQuery(
            'INSERT INTO system_health (metric_key, metric_value) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE metric_value = VALUES(metric_value), updated_at = CURRENT_TIMESTAMP',
            [':k' => $key, ':v' => $value]
        );
    } catch (Throwable $e) {
        wlog('warn', 'health_set failed', ['key' => $key, 'error' => $e->getMessage()]);
    }
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
    // Advance up to the window start efficiently.
    for ($i = 0; $i < MAX_RECURRENCE_ITERATIONS && $cur < $start; $i++) {
        $next = next_occurrence($cur, $recurrence);
        if ($next === null || $next <= $cur) break;
        $cur = $next;
    }
    // Emit occurrences until we pass the window end (or recurrence_end_date).
    for ($i = 0; $i < MAX_RECURRENCE_ITERATIONS && $cur <= $end; $i++) {
        if ($rec_end !== null && $cur->format('Y-m-d') > $rec_end->format('Y-m-d')) break;
        if ($cur >= $start) $out[] = $cur;
        $next = next_occurrence($cur, $recurrence);
        if ($next === null || $next <= $cur) break;
        $cur = $next;
    }
    return $out;
}

/**
 * Format a datetime for MySQL DATETIME columns.
 */
function fmt_mysql(DateTimeImmutable $dt): string
{
    return $dt->format('Y-m-d H:i:s');
}

/**
 * Attempt to claim (INSERT) the notification row. Returns claim id on
 * success, null when another process already owns it.
 */
function claim_occurrence(int $reminder_id, int $user_id, string $scheduled_for): ?int
{
    try {
        return insertRecord('reminder_notifications', [
            'reminder_id'       => $reminder_id,
            'user_id'           => $user_id,
            'scheduled_for'     => $scheduled_for,
            'notification_type' => 'email',
            'status'            => 'processing',
            'attempts'          => 1,
        ]);
    } catch (PDOException $e) {
        // Duplicate key => already claimed. Any other PDO error re-throws.
        if ($e->getCode() === '23000') {
            return null;
        }
        throw $e;
    }
}

// ========================================================================
// Main
// ========================================================================
$started = new DateTimeImmutable('now');
wlog('info', 'worker start', ['now' => fmt_mysql($started)]);
health_set('reminder_worker_last_run', fmt_mysql($started));

try {
    $pdo = getDatabaseConnection();
} catch (Throwable $e) {
    wlog('error', 'db unreachable', ['error' => $e->getMessage()]);
    health_set('reminder_worker_last_error', 'db unreachable');
    exit(2);
}

$now = new DateTimeImmutable('now');
$window_start = $now->modify('-' . REMINDER_CATCHUP_WINDOW_HOURS . ' hours');
$window_end   = $now->modify('+' . OCCURRENCE_LOOKAHEAD_MINUTES . ' minutes');

$processed = 0;
$sent = 0;
$failed = 0;
$skipped = 0;

// -------- Step 1: enumerate pending reminders and materialise due occurrences --------
try {
    $reminders = fetchAll(
        "SELECT r.id, r.user_id, r.title, r.description, r.priority,
                r.reminder_date, r.recurrence_type, r.recurrence_end_date,
                r.notification_offset_minutes,
                u.email  AS user_email,
                u.name   AS user_name,
                u.status AS user_status
         FROM reminders r
         INNER JOIN users u ON u.id = r.user_id
         WHERE r.status = 'pending'
           AND u.status = 'active'
         ORDER BY r.reminder_date ASC"
    );
} catch (Throwable $e) {
    wlog('error', 'reminder query failed', ['error' => $e->getMessage()]);
    health_set('reminder_worker_last_error', 'reminder query failed');
    exit(3);
}

foreach ($reminders as $r) {
    try {
        $base_dt = new DateTimeImmutable((string) $r['reminder_date']);
        $offset  = (int) $r['notification_offset_minutes'];
        if ($offset > 0) {
            $base_dt = $base_dt->modify('-' . $offset . ' minutes');
        }
        $rec_end = !empty($r['recurrence_end_date']) ? new DateTimeImmutable((string) $r['recurrence_end_date']) : null;

        $occurrences = occurrences_in_window(
            $base_dt,
            (string) $r['recurrence_type'],
            $rec_end,
            $window_start,
            $window_end
        );

        foreach ($occurrences as $occ) {
            if ($occ > $now) {
                // A near-future occurrence — schedule it but don't send yet.
                // Only INSERT the claim row so future runs can pick it up.
                try {
                    insertRecord('reminder_notifications', [
                        'reminder_id'       => (int) $r['id'],
                        'user_id'           => (int) $r['user_id'],
                        'scheduled_for'     => fmt_mysql($occ),
                        'notification_type' => 'email',
                        'status'            => 'pending',
                        'attempts'          => 0,
                    ]);
                } catch (PDOException $e) {
                    // Duplicate — already scheduled. Fine.
                    if ($e->getCode() !== '23000') throw $e;
                }
                continue;
            }
            // Occurrence is due (or overdue within catch-up window).
            deliver($r, $occ, $app_name, $base_url, $sent, $failed, $skipped, $processed);
        }
    } catch (Throwable $ex) {
        wlog('error', 'reminder scan failed', ['reminder_id' => $r['id'] ?? null, 'error' => $ex->getMessage()]);
    }
}

// -------- Step 2: retry any pending/processing rows the previous run failed on --------
try {
    $retryable = fetchAll(
        "SELECT rn.id, rn.reminder_id, rn.scheduled_for, rn.attempts,
                r.id AS rid, r.user_id, r.title, r.description, r.priority,
                r.reminder_date, r.status AS r_status,
                u.email AS user_email, u.name AS user_name, u.status AS user_status
         FROM reminder_notifications rn
         INNER JOIN reminders r ON r.id = rn.reminder_id
         INNER JOIN users u ON u.id = rn.user_id
         WHERE rn.status IN ('pending','processing')
           AND rn.scheduled_for <= :now
           AND rn.attempts < :cap
           AND r.status = 'pending'
           AND u.status = 'active'
         ORDER BY rn.scheduled_for ASC
         LIMIT 200",
        [':now' => fmt_mysql($now), ':cap' => MAX_ATTEMPTS]
    );
} catch (Throwable $e) {
    $retryable = [];
    wlog('warn', 'retry query failed', ['error' => $e->getMessage()]);
}

foreach ($retryable as $rn) {
    try {
        deliver_retry($rn, $app_name, $base_url, $sent, $failed, $processed);
    } catch (Throwable $ex) {
        wlog('error', 'retry failed', ['rn_id' => $rn['id'], 'error' => $ex->getMessage()]);
    }
}

$duration_ms = (int) ((microtime(true) - $started->format('U.u')) * 1000);
wlog('info', 'worker done', [
    'processed' => $processed,
    'sent'      => $sent,
    'failed'    => $failed,
    'skipped'   => $skipped,
    'duration_ms' => $duration_ms,
]);
health_set('reminder_worker_last_result', "processed=$processed sent=$sent failed=$failed");
if ($sent > 0) {
    health_set('reminder_worker_last_success', fmt_mysql(new DateTimeImmutable('now')));
}
exit(0);

// ------------------------------------------------------------------------
// Delivery helpers
// ------------------------------------------------------------------------
function deliver(array $r, DateTimeImmutable $occ, string $app_name, string $base_url,
                  int &$sent, int &$failed, int &$skipped, int &$processed): void
{
    $scheduled = fmt_mysql($occ);
    $claim_id = claim_occurrence((int) $r['id'], (int) $r['user_id'], $scheduled);
    if ($claim_id === null) {
        // Already handled or in flight. Try to pick it up in the retry pass if pending.
        $skipped++;
        return;
    }
    $processed++;

    $ok = send_reminder_email($r, $occ, $app_name, $base_url, $err);
    finalise_notification($claim_id, $ok, $err ?? null);
    $ok ? $sent++ : $failed++;
}

function deliver_retry(array $rn, string $app_name, string $base_url, int &$sent, int &$failed, int &$processed): void
{
    $now = new DateTimeImmutable('now');
    // Atomic claim: only transition pending/processing rows we can update.
    $updated = executeQuery(
        "UPDATE reminder_notifications
         SET status = 'processing', attempts = attempts + 1
         WHERE id = :id AND status IN ('pending','processing') AND attempts < :cap",
        [':id' => (int) $rn['id'], ':cap' => MAX_ATTEMPTS]
    )->rowCount();
    if ($updated === 0) return; // another worker took it
    $processed++;

    $r = [
        'id'            => $rn['rid'],
        'user_id'       => $rn['user_id'],
        'title'         => $rn['title'],
        'description'   => $rn['description'] ?? null,
        'priority'      => $rn['priority'],
        'reminder_date' => $rn['reminder_date'],
        'user_email'    => $rn['user_email'],
        'user_name'     => $rn['user_name'],
    ];
    $occ = new DateTimeImmutable((string) $rn['scheduled_for']);
    $ok = send_reminder_email($r, $occ, $app_name, $base_url, $err);
    finalise_notification((int) $rn['id'], $ok, $err ?? null);
    $ok ? $sent++ : $failed++;
}

function finalise_notification(int $rn_id, bool $ok, ?string $error): void
{
    try {
        if ($ok) {
            executeQuery(
                "UPDATE reminder_notifications
                 SET status = 'sent', sent_at = NOW(), last_error = NULL
                 WHERE id = :id",
                [':id' => $rn_id]
            );
        } else {
            $short = $error === null ? 'unknown' : substr($error, 0, 500);
            executeQuery(
                "UPDATE reminder_notifications
                 SET status = CASE WHEN attempts >= :cap THEN 'failed' ELSE 'pending' END,
                     last_error = :err
                 WHERE id = :id",
                [':id' => $rn_id, ':err' => $short, ':cap' => MAX_ATTEMPTS]
            );
        }
    } catch (Throwable $e) {
        wlog('warn', 'finalise failed', ['id' => $rn_id, 'error' => $e->getMessage()]);
    }
}

function send_reminder_email(array $r, DateTimeImmutable $occ, string $app_name, string $base_url, ?string &$err = null): bool
{
    $to = (string) ($r['user_email'] ?? '');
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $err = 'user email missing or invalid';
        return false;
    }
    $title    = (string) ($r['title'] ?? 'Reminder');
    $desc     = (string) ($r['description'] ?? '');
    $priority = ucfirst((string) ($r['priority'] ?? 'medium'));
    $due      = (new DateTimeImmutable((string) $r['reminder_date']))->format('j M Y, g:i A');
    $url      = $base_url !== '' ? rtrim($base_url, '/') . '/dashboard.php' : 'dashboard.php';

    $subject = 'Reminder: ' . $title;
    $descHtml = $desc !== '' ? '<p><strong>Details:</strong> ' . htmlspecialchars($desc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>' : '';
    $html =
        '<div style="font-family:system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0f172a;line-height:1.5">' .
            '<h2 style="margin:0 0 12px">' . htmlspecialchars($app_name, ENT_QUOTES, 'UTF-8') . ' Reminder</h2>' .
            '<p><strong>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</strong></p>' .
            '<p><strong>Due:</strong> ' . htmlspecialchars($due, ENT_QUOTES, 'UTF-8') . '<br>' .
            '<strong>Priority:</strong> ' . htmlspecialchars($priority, ENT_QUOTES, 'UTF-8') . '</p>' .
            $descHtml .
            '<p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Open dashboard</a></p>' .
            '<hr style="border:0;border-top:1px solid #e5e9f0;margin:20px 0">' .
            '<p style="color:#5b6577;font-size:12px">This is an automated reminder from your finance dashboard.</p>' .
        '</div>';
    $text =
        $app_name . " reminder\n\n" .
        $title . "\n" .
        "Due: $due\n" .
        "Priority: $priority\n" .
        ($desc !== '' ? "Details: $desc\n" : '') .
        "\nOpen dashboard: $url\n";

    try {
        $ok = send_mail($to, $subject, $html, $text);
        if (!$ok) $err = 'send_mail returned false';
        return $ok;
    } catch (Throwable $e) {
        $err = $e->getMessage();
        return false;
    }
}
