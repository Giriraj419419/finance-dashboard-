<?php
/**
 * diagnostics-lib.php
 *
 * Single source of truth for every safe production diagnostic. Each
 * `diag_*()` function returns a normalised list of check rows:
 *
 *   [
 *       ['name' => 'db.connect',   'ok' => true,  'detail' => ''],
 *       ['name' => 'table.users',  'ok' => true,  'detail' => ''],
 *       ...
 *   ]
 *
 * Consumed by BOTH the admin-only UI page (admin-diagnostics.php) and
 * the CLI scripts under scripts/. The CLIs and the UI must not diverge —
 * a check that lives in only one place is a check the operator will
 * one day forget to run.
 *
 * SAFETY CONTRACT
 *   * A `detail` string may include table names, index names, column
 *     names, PHP versions, extension names, DECIMAL/INT type strings,
 *     and coarse status words ('ok', 'missing', 'error').
 *   * A `detail` string MUST NEVER include: DB passwords, SMTP passwords,
 *     VAPID private key bytes, reset tokens, session IDs, notification
 *     payloads, or full user emails.
 *   * Config VALUES are never rendered — only setting NAMES + "<set>" /
 *     "(unset)" markers are considered safe.
 */
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/production-guard.php';

/* ---------- shared helpers ------------------------------------------- */

function _diag(string $name, bool $ok, string $detail = ''): array
{
    return ['name' => $name, 'ok' => $ok, 'detail' => $detail];
}

function diag_group_verdict(array $checks): bool
{
    foreach ($checks as $c) {
        if (!$c['ok']) return false;
    }
    return true;
}

function diag_counts(array $checks): array
{
    $pass = 0; $fail = 0;
    foreach ($checks as $c) $c['ok'] ? $pass++ : $fail++;
    return ['pass' => $pass, 'fail' => $fail, 'total' => count($checks)];
}

/* ---------- 1. runtime information ----------------------------------- */

function diag_runtime(): array
{
    $out = [];
    $out[] = _diag('runtime.php_version',
        version_compare(PHP_VERSION, '8.0', '>='),
        PHP_VERSION);

    // Every required extension the app uses at runtime.
    $needed = ['pdo_mysql', 'openssl', 'curl', 'mbstring', 'json', 'session'];
    foreach ($needed as $ext) {
        $out[] = _diag('runtime.ext.' . $ext, extension_loaded($ext), extension_loaded($ext) ? 'loaded' : 'missing');
    }

    // Non-blocking but useful.
    $tz = date_default_timezone_get();
    $out[] = _diag('runtime.timezone', $tz !== '', $tz ?: '(unset)');
    $out[] = _diag('runtime.memory_limit',
        (bool) ini_get('memory_limit'),
        (string) ini_get('memory_limit'));
    $out[] = _diag('runtime.max_execution_time',
        (int) ini_get('max_execution_time') > 0 || (int) ini_get('max_execution_time') === 0,
        (string) ini_get('max_execution_time'));

    // Session-cookie flags — informational; the production-config diag
    // asserts that these are correct on prod, this just surfaces the
    // effective values.
    $out[] = _diag('runtime.session.cookie_httponly',
        (bool) ini_get('session.cookie_httponly'),
        (string) ini_get('session.cookie_httponly'));
    $out[] = _diag('runtime.session.cookie_secure_setting_present',
        ini_get('session.cookie_secure') !== false,
        (string) ini_get('session.cookie_secure'));

    return $out;
}

/* ---------- 2. production config validation -------------------------- */

/**
 * Runs the same production_config_problems() the boot-time guard runs,
 * but does NOT abort — it returns a rowset for display.
 */
function diag_production_config(): array
{
    $cfg = app_config(); // /** @var array<string,mixed> */
    $env = (string) ($cfg['app']['environment'] ?? '');
    $rows = [];
    $rows[] = _diag('config.environment.set', $env !== '', $env ?: '(unset)');

    // In non-production environments, we still run the same rule set
    // against a temporarily-flipped copy so the operator can see what
    // WOULD fail if this config were promoted. The rows are informational
    // in dev — they only affect the overall PASS/FAIL in production.
    $probe = $cfg;
    $probe['app']['environment'] = 'production';
    $problems = production_config_problems($probe);

    if ($problems === []) {
        $rows[] = _diag('config.production_ready', true,
            $env === 'production' ? 'live production checks all pass' : 'would-pass in production');
    } else {
        foreach ($problems as $p) {
            // For non-production environments, the row is informational,
            // still marked FAIL so the operator can see what would break.
            $rows[] = _diag('config.' . _slug($p), false, $p);
        }
    }
    return $rows;
}

function _slug(string $s): string
{
    $s = strtolower(preg_replace('/[^A-Za-z0-9._-]+/', '_', $s) ?? '');
    return trim($s, '_');
}

/* ---------- 3. database + schema ------------------------------------- */

/**
 * @param string|null $check_user optional email to verify exists + active
 */
function diag_database(?string $check_user = null): array
{
    require_once __DIR__ . '/database.php';
    $rows = [];

    /* connectivity */
    try {
        $pdo = getDatabaseConnection();
        $rows[] = _diag('db.connect', true);
    } catch (Throwable $e) {
        $rows[] = _diag('db.connect', false, get_class($e));
        return $rows; // pointless to continue
    }

    /* tables */
    $required_tables = [
        'users', 'transactions', 'budgets', 'goals', 'goal_contributions',
        'payments', 'purchase_orders', 'purchase_order_items',
        'reminders', 'reminder_notifications', 'push_subscriptions',
        'login_attempts', 'reports', 'password_reset_tokens', 'audit_logs',
        'system_health', 'user_notification_preferences',
    ];
    try {
        $present = array_map(
            static fn ($r) => (string) $r['t'],
            fetchAll("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")
        );
        foreach ($required_tables as $t) {
            $rows[] = _diag('table.' . $t, in_array($t, $present, true));
        }
    } catch (Throwable $e) {
        $rows[] = _diag('table.enumeration', false, get_class($e));
    }

    /* unique keys */
    foreach (
        [
            ['reminder_notifications', 'uq_rn_reminder_occurrence'],
            ['push_subscriptions',     'uq_ps_endpoint'],
            ['users',                  'uq_users_email'],
            ['purchase_orders',        'uq_po_order_number'],
            ['password_reset_tokens',  'uq_prt_token_hash'],
        ] as [$tbl, $key]
    ) {
        try {
            $r = fetchOne(
                "SELECT 1 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :k LIMIT 1",
                [':t' => $tbl, ':k' => $key]
            );
            $rows[] = _diag("unique.$tbl.$key", $r !== null);
        } catch (Throwable $e) {
            $rows[] = _diag("unique.$tbl.$key", false, get_class($e));
        }
    }

    /* foreign keys */
    foreach (
        [
            ['reminder_notifications', 'fk_rn_reminder'],
            ['reminder_notifications', 'fk_rn_user'],
            ['push_subscriptions',     'fk_ps_user'],
            ['transactions',           'fk_txn_user'],
            ['goal_contributions',     'fk_gc_goal'],
            ['purchase_order_items',   'fk_poi_po'],
        ] as [$tbl, $fk]
    ) {
        try {
            $r = fetchOne(
                "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
                   AND CONSTRAINT_NAME = :c AND CONSTRAINT_TYPE = 'FOREIGN KEY' LIMIT 1",
                [':t' => $tbl, ':c' => $fk]
            );
            $rows[] = _diag("fk.$tbl.$fk", $r !== null);
        } catch (Throwable $e) {
            $rows[] = _diag("fk.$tbl.$fk", false, get_class($e));
        }
    }

    /* money columns are DECIMAL */
    foreach (
        [
            ['transactions', 'amount'],
            ['budgets', 'budget_amount'],
            ['budgets', 'spent_amount'],
            ['goals', 'target_amount'],
            ['goals', 'current_amount'],
            ['goal_contributions', 'amount'],
            ['payments', 'amount'],
            ['purchase_orders', 'subtotal'],
            ['purchase_orders', 'tax_amount'],
            ['purchase_orders', 'total_amount'],
            ['purchase_order_items', 'quantity'],
            ['purchase_order_items', 'unit_price'],
            ['purchase_order_items', 'total_price'],
        ] as [$tbl, $col]
    ) {
        try {
            $r = fetchOne(
                "SELECT DATA_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1",
                [':t' => $tbl, ':c' => $col]
            );
            $type = strtolower((string) ($r['DATA_TYPE'] ?? ''));
            $rows[] = _diag("money.$tbl.$col", $type === 'decimal', $type ?: 'missing');
        } catch (Throwable $e) {
            $rows[] = _diag("money.$tbl.$col", false, get_class($e));
        }
    }

    /* optional user existence check */
    if ($check_user !== null && $check_user !== '') {
        try {
            $r = fetchOne("SELECT status FROM users WHERE email = :e LIMIT 1", [':e' => strtolower($check_user)]);
            $ok = $r !== null && $r['status'] === 'active';
            // Print only status token, never the email in row detail (name already has it).
            $rows[] = _diag('user.exists.' . _slug($check_user),
                $ok, $r === null ? 'missing' : ('status=' . $r['status']));
        } catch (Throwable $e) {
            $rows[] = _diag('user.exists.' . _slug($check_user), false, get_class($e));
        }
    }
    return $rows;
}

/* ---------- 4. VAPID pair -------------------------------------------- */

function diag_vapid(): array
{
    require_once __DIR__ . '/push-webpush.php';
    $rows = [];
    $push = app_config('push');

    $pubB64 = (string) ($push['vapid_public_key'] ?? '');
    $pub = $pubB64 === '' ? '' : _wp_b64u_decode($pubB64);
    $rows[] = _diag('vapid.public_key.present',        $pubB64 !== '');
    $rows[] = _diag('vapid.public_key.65_bytes',       strlen($pub) === 65, strlen($pub) . ' bytes');
    $rows[] = _diag('vapid.public_key.prefix_0x04',    $pub !== '' && $pub[0] === "\x04");

    $priv = (string) ($push['vapid_private_key_path'] ?? '');
    $rows[] = _diag('vapid.private_key.path_set',      $priv !== '');
    $exists = $priv !== '' && is_file($priv);
    $rows[] = _diag('vapid.private_key.file_exists',   $exists);

    if ($priv !== '') {
        $realWeb  = realpath(__DIR__);
        $realPriv = realpath($priv);
        $outside  = $realPriv !== false && $realWeb !== false && !str_starts_with($realPriv, $realWeb);
        // Only rendered as "outside" / "inside", never the actual path.
        $rows[] = _diag('vapid.private_key.outside_webroot', $outside);
    }

    if ($exists) {
        $pkey = @openssl_pkey_get_private('file://' . $priv);
        $rows[] = _diag('vapid.private_key.parses', $pkey !== false);
        if ($pkey !== false) {
            $det = @openssl_pkey_get_details($pkey);
            $isP256 = is_array($det) && (($det['type'] ?? -1) === OPENSSL_KEYTYPE_EC)
                && (($det['ec']['curve_name'] ?? '') === 'prime256v1');
            $rows[] = _diag('vapid.private_key.curve_prime256v1', $isP256,
                $isP256 ? 'prime256v1' : ($det['ec']['curve_name'] ?? '(unknown)'));

            if ($isP256 && $pub !== '') {
                $x = str_pad((string) $det['ec']['x'], 32, "\x00", STR_PAD_LEFT);
                $y = str_pad((string) $det['ec']['y'], 32, "\x00", STR_PAD_LEFT);
                $derivedPub = "\x04" . $x . $y;
                $rows[] = _diag('vapid.keypair.matches', $derivedPub === $pub,
                    $derivedPub === $pub ? '' : 'derived != configured');
            }
        }
    }

    $subj = (string) ($push['vapid_subject'] ?? '');
    $rows[] = _diag('vapid.subject.mailto',
        $subj !== '' && strncasecmp($subj, 'mailto:', 7) === 0,
        $subj === '' ? '(unset)' : 'mailto:*'); // never render the actual subject

    if ($exists && DIRECTORY_SEPARATOR === '/') {
        $perms = fileperms($priv) & 0o777;
        $rows[] = _diag('vapid.private_key.no_world_read', ($perms & 0o004) === 0,
            sprintf('mode=%04o', $perms));
    }
    return $rows;
}

/* ---------- 5. cron heartbeat ---------------------------------------- */

/**
 * @param int $threshold_seconds tolerable staleness of the reminder-worker heartbeat
 */
function diag_cron(int $threshold_seconds = 900): array
{
    require_once __DIR__ . '/database.php';
    $rows = [];

    try {
        getDatabaseConnection();
    } catch (Throwable $e) {
        $rows[] = _diag('cron.db_reachable', false, get_class($e));
        return $rows;
    }
    $rows[] = _diag('cron.db_reachable', true);

    try {
        $map = [];
        foreach (fetchAll(
            "SELECT metric_key AS k, metric_value AS v FROM system_health
             WHERE metric_key IN (
                'reminder_worker_last_run',
                'reminder_worker_last_result',
                'reminder_worker_last_success',
                'reminder_worker_last_error')") as $r
        ) {
            $map[(string) $r['k']] = (string) $r['v'];
        }
        $last = $map['reminder_worker_last_run'] ?? null;
        if ($last === null) {
            $rows[] = _diag('cron.reminder_worker.heartbeat', false, 'never-run');
        } else {
            $ts = strtotime($last);
            $age = $ts === false ? PHP_INT_MAX : (time() - $ts);
            $rows[] = _diag('cron.reminder_worker.heartbeat',
                $age <= $threshold_seconds,
                'age=' . $age . 's threshold=' . $threshold_seconds . 's last=' . $last);
        }
        $rows[] = _diag('cron.reminder_worker.last_result_seen',
            isset($map['reminder_worker_last_result']),
            $map['reminder_worker_last_result'] ?? '(none)');
        // last_success is optional — worker only writes it on a run that
        // actually delivered something. Not a FAIL when missing.
        $rows[] = _diag('cron.reminder_worker.any_success_seen',
            true,
            $map['reminder_worker_last_success'] ?? '(none — no successful send yet)');
        // last_error is coarse (category), never a full stack trace. Safe to render.
        if (isset($map['reminder_worker_last_error'])) {
            $rows[] = _diag('cron.reminder_worker.no_current_error',
                false,
                $map['reminder_worker_last_error']);
        }
    } catch (Throwable $e) {
        $rows[] = _diag('cron.heartbeat_query', false, get_class($e));
    }
    return $rows;
}

/* ---------- 6. SMTP configuration ------------------------------------ */

function diag_smtp(): array
{
    $rows = [];
    $mail = app_config('mail');

    $rows[] = _diag('smtp.host',       !empty($mail['host']),        !empty($mail['host']) ? '<set>' : '(unset)');
    $rows[] = _diag('smtp.port',       !empty($mail['port']),        (string) ($mail['port'] ?? '(unset)'));
    $rows[] = _diag('smtp.from_email', !empty($mail['from_email'])
        && filter_var($mail['from_email'], FILTER_VALIDATE_EMAIL)
        && !str_contains((string) $mail['from_email'], 'example.com'),
        !empty($mail['from_email'])
            ? (str_contains((string) $mail['from_email'], 'example.com')
                ? 'placeholder@example.com'
                : '<set>')
            : '(unset)'
    );
    $rows[] = _diag('smtp.credentials.present',
        !empty($mail['username']) && !empty($mail['password']),
        !empty($mail['username']) && !empty($mail['password']) ? '<user + password set>' : '(one or both unset)'
    );

    // Port + secure pairing sanity.
    $port = (int) ($mail['port'] ?? 0);
    $secure = (bool) ($mail['secure'] ?? false);
    if ($port === 465) {
        $rows[] = _diag('smtp.port_465_uses_ssl', $secure, $secure ? 'secure=true' : 'secure should be true on 465');
    } elseif ($port === 587) {
        $rows[] = _diag('smtp.port_587_uses_starttls', !$secure, !$secure ? 'secure=false (STARTTLS)' : 'secure should be false on 587');
    } elseif ($port !== 0) {
        // Any other port is technically OK — leave a warning-shape row that
        // is informational (marked FAIL so it draws the eye).
        $rows[] = _diag('smtp.port_recognised', false, 'unexpected port ' . $port);
    }

    return $rows;
}

/* ---------- 7. everything, in one place ------------------------------ */

/**
 * Returns the full grouped report:
 *   [
 *     'runtime'    => [...rows],
 *     'config'     => [...rows],
 *     'database'   => [...rows],
 *     'vapid'      => [...rows],
 *     'cron'       => [...rows],
 *     'smtp'       => [...rows],
 *     'manual'     => [...static rows listing external-only checks],
 *     'overall_ok' => bool,
 *     'counts'     => ['pass'=>int, 'fail'=>int, 'total'=>int],
 *   ]
 */
function diag_full_report(?string $check_user = null, int $cron_threshold = 900): array
{
    $groups = [
        'runtime'  => diag_runtime(),
        'config'   => diag_production_config(),
        'database' => diag_database($check_user),
        'vapid'    => diag_vapid(),
        'cron'     => diag_cron($cron_threshold),
        'smtp'     => diag_smtp(),
    ];
    $groups['manual'] = diag_manual_checks();

    $all_ok = true;
    $pass = 0; $fail = 0;
    foreach ($groups as $g => $rows) {
        if ($g === 'manual') continue; // manual rows are documentation, not verdict inputs
        foreach ($rows as $r) {
            $r['ok'] ? $pass++ : $fail++;
            if (!$r['ok']) $all_ok = false;
        }
    }

    return [
        'groups'     => $groups,
        'overall_ok' => $all_ok,
        'counts'     => ['pass' => $pass, 'fail' => $fail, 'total' => $pass + $fail],
        'generated'  => gmdate('c'),
    ];
}

/**
 * Explicitly-manual rows shown in the UI so the operator can see WHY
 * we don't claim end-to-end verified. These never contribute to the
 * overall verdict — they're documentation, not a check.
 */
function diag_manual_checks(): array
{
    return [
        _diag('manual.smtp.inbox_delivery',
            true,
            'Send a real password-reset email to your own inbox from Forgot Password and confirm it arrives.'),
        _diag('manual.push.closed_tab_notification',
            true,
            'On a browser where push is enabled, close the Finance Dashboard tab and confirm a scheduled reminder still fires an OS notification.'),
        _diag('manual.cpanel.cron_schedule',
            true,
            'In cPanel → Cron Jobs, confirm reminder-worker.php is scheduled at the expected interval (recommended every 5 minutes).'),
        _diag('manual.cpanel.vapid_private_key_placement',
            true,
            'Confirm the VAPID private-key PEM lives OUTSIDE the web-root directory (production-guard already refuses to boot otherwise).'),
    ];
}
