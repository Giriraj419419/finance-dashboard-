# Production Diagnostics & Verification Runbook

Diagnostics and tests introduced during the production-hardening pass.
Every command in this document is non-destructive unless explicitly
flagged. All CLI scripts refuse to run under a web SAPI.

## Recommended path for hosts without SSH

Modern cPanel installs frequently do not expose Terminal / SSH. In that
case, use the **admin-only Production Diagnostics page** described
below. It runs the same check logic as the CLI scripts, gated by
`requireRole('admin')`, and never renders any secret value.

## Table of contents

1. [Automated CI gates](#automated-ci-gates)
2. [Admin-only Diagnostics UI page](#admin-only-diagnostics-ui-page)
3. [Production config validator](#production-config-validator)
4. [Live health endpoint](#live-health-endpoint)
5. [Database production-health CLI](#database-production-health-cli)
6. [Cron heartbeat health CLI](#cron-heartbeat-health-cli)
7. [SMTP diagnostic](#smtp-diagnostic)
8. [VAPID diagnostic](#vapid-diagnostic)
9. [Push send diagnostic](#push-send-diagnostic)
10. [Production HTTP smoke test](#production-http-smoke-test)
11. [What still requires human observation](#what-still-requires-human-observation)

---

## Automated CI gates

The GitHub Actions workflow now has two jobs. Every push to `master`
runs them in this order:

- **tests** — PHP lint on every file, static security scan
  (`scripts/security-scan.php`, rules R1..R5), unit + integration tests
  against an ephemeral MySQL 8 service (`tests/run.php`). All must pass.
- **deploy** — only fires when `tests` is green; FTPS-uploads with the
  usual excludes.

A single failing test blocks the deploy. There is no
`continue-on-error: true` and no `|| true` anywhere in the workflow.

## Admin-only Diagnostics UI page

Sign in as an `admin`-role user, open **Diagnostics** in the sidebar
(or go directly to `/admin-diagnostics.php`). The page renders the full
grouped output of `diagnostics-lib.php` — runtime, production
configuration, database + schema, VAPID pair, cron heartbeat, SMTP
configuration — plus the static list of external checks the operator
still has to do by hand.

- The page is gated by `requireRole('admin')` at the top; any other role
  gets the standard 403.
- The page is READ-ONLY. It performs no writes, sends no email, and
  never triggers a real push.
- No secret is rendered. The `detail` column shows setting NAMES,
  `<set>` / `(unset)` markers, table/index/foreign-key names, PHP
  version + extension names, and coarse status words only.
- Overall verdict at the top-right: **PASS** when every automatic
  check passes; **FAIL** otherwise. The "Manual verification still
  required" section never contributes to the verdict.

Optional query parameters:

- `?user=<email>` — additionally verify a specific account exists and
  is `active`.
- `?cron_threshold=<seconds>` — override the acceptable heartbeat age.

## Production config validator

`production-guard.php` is included the first time `functions.php::app_config()`
loads the config. When `app.environment === 'production'` it refuses to
serve the app if any of the following is wrong:

- `app.debug` is truthy
- `app.base_url` is not `https://…`
- `session.secure` is false
- `session.httponly` is false
- `session.samesite` is not `Lax` or `Strict`
- Any `database.*` field is empty, or username/password equal `CHANGE_ME`
- Any `mail.host`, `mail.port`, `mail.from_email` is empty
- `mail.from_email` still points at `example.com`
- `push.vapid_public_key` is empty
- `push.vapid_private_key_path` is empty, missing, or **inside the web-root**
- `push.vapid_subject` is not a `mailto:` URL

Failures log the offending SETTING names (never values) and return HTTP
500. Development environments are left alone — this only fires when
`environment='production'`.

## Live health endpoint

```
GET https://finance.kktechsolutions.in/healthcheck.php
```

Returns JSON of the shape:

```json
{
  "status": "ok",
  "database": "ok",
  "schema": "ok",
  "configuration": "ok",
  "cron": "ok",
  "push": "configured",
  "mail": "configured",
  "time": "2026-09-22T05:12:07+00:00"
}
```

HTTP 200 when everything is `ok`; HTTP 503 when any critical (database,
schema, configuration) check is bad. Contains **no** credentials, tokens,
or sensitive values.

## Database production-health CLI

```
php database/production-health.php                       # human-readable
php database/production-health.php --json                # machine-readable
php database/production-health.php --user=accounts@kktechsolutions.in
```

Verifies:

- Connectivity
- Every required table exists (17 tables)
- Every critical UNIQUE key (5)
- Every critical FOREIGN KEY (6)
- All money columns are `DECIMAL` (13 columns)
- (Optional) a specific user exists and is `active`

Exit `0` when everything passes, `1` otherwise. Never prints DB creds.

## Cron heartbeat health CLI

```
php cron/health.php               # exit 0 = OK, 1 = error, 2 = stale
php cron/health.php --json
php cron/health.php --threshold=600
```

Reads `system_health.reminder_worker_last_run` and reports OK only when
that heartbeat is within 15 minutes (or whatever `--threshold` overrides
to). Use this as the smoke test for "is Cron actually running?" — the
mere existence of the worker file does not prove it runs.

## SMTP diagnostic

```
php scripts/smtp-diagnostic.php                          # config-only
php scripts/smtp-diagnostic.php --send=you@your-inbox    # sends one test
```

Validates the configuration (host/port/secure combo, sender not
`example.com`, credentials present) without sending. Only sends when the
operator explicitly passes `--send=<address>`, and refuses to send to any
`example.com` recipient. Never prints the SMTP password.

## VAPID diagnostic

```
php scripts/vapid-diagnostic.php
```

Verifies:

- Public key is set + shape-checks it (65 bytes, uncompressed 0x04 prefix)
- Private key path is set, file exists, and lives **outside** the web-root
- Private key parses as ECDSA P-256
- The public key derived from the private matches the configured public
  (i.e. they are a real pair, not two random values)
- `vapid.subject` is `mailto:`
- On Unix hosts, the private key is not world-readable

Never prints the private key material.

## Push send diagnostic

```
php scripts/push-send.php --user=accounts@kktechsolutions.in
```

Sends ONE Web Push notification to each active subscription owned by the
supplied user, using the same `web_push_send()` the cron worker uses.
Reports the endpoint's HTTP status for each subscription. 404/410 rows
are automatically deactivated (matching the worker's own policy). Use
this to confirm the VAPID pair actually works against the browser after
subscribing.

## Production HTTP smoke test

```
php scripts/production-smoke-test.php --url=https://finance.kktechsolutions.in
```

Black-box GETs only (no writes, no logins). Asserts:

- HTTPS + expected security headers (HSTS, X-Content-Type-Options,
  X-Frame-Options, Referrer-Policy)
- HTTP → HTTPS 301 redirect
- `/login.php` returns 200 and contains the sign-in form
- `/sw.js` returns 200 with a JS content-type and both `push` +
  `notificationclick` handlers
- `/dashboard.php` 302's an unauthenticated client to `/login.php`
- `/healthcheck.php` returns 200 or 503 with the documented JSON keys
- `/config.php`, `/database/schema.sql`, `/includes/header.php`,
  `/cron/reminder-worker.php` return 403 or 404

## What still requires human observation

The following items cannot be honestly claimed by any automated test in
this repo. They are the manual verification checklist:

1. **Password-reset email arrives in the operator's inbox.** SMTP diag
   verifies configuration and can send a real message; only a human can
   confirm delivery to the actual mailbox.
2. **Closed-tab Web Push notification actually appears on the OS.** Push
   diag verifies the VAPID pair works against the endpoint; only the
   operator's real browser (with the tab closed, connected to the
   internet) can confirm the OS notification.
3. **cPanel Cron is scheduled with the right command and interval.** The
   cron-health CLI tells you whether it's *running*, but the schedule
   itself lives in cPanel's UI and must be verified there.
4. **Production `config.php` on the server has the correct real values.**
   The production-config guard aborts boot if it's wrong; the guard
   itself proves nothing about what was configured, only that if it's
   ever wrong on this specific server the app will refuse to run.
5. **A second tenant / user isolation review.** The IDOR integration
   tests prove the guard SQL rejects cross-user writes at the query
   level. Any additional cross-tenant scenarios must be exercised in a
   two-account UI walkthrough.
