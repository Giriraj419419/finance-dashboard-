# cPanel deployment

## Prerequisites

- PHP 8.4 on the account (cPanel → MultiPHP Manager).
- MySQL database and DB user created (see `database/README.md`).
- Working outbound SMTP account for the email address used in `mail.from_email` (cPanel → Email Accounts).

## Files to upload

Every tracked file in the repo, except:
- `config.php` — replace with your production version (never commit).
- `uploads/` — ships with `.gitkeep` and `.htaccess`; keep local user files out of git.
- `videos/` — motion-graphics scratch, gitignored.

Do NOT upload `node_modules`, `package.json`, `composer.json`, or `vendor/` — none exist and none should ever be added to this stack.

## First-time database setup

1. cPanel → **MySQL® Databases** → create DB + user + grant privileges.
2. Import `database/schema.sql` via phpMyAdmin (**Import** tab).
3. Optionally import `database/seed.sql` for demo rows. Rotate the seed passwords immediately.
4. Fill `config.php`:
   ```php
   'database' => [
       'host'     => 'localhost',
       'name'     => 'youracct_finance_dashboard',
       'username' => 'youracct_dbuser',
       'password' => '...',
       'charset'  => 'utf8mb4',
   ],
   ```
5. Fill `mail.*` with your cPanel SMTP credentials.
6. From cPanel → Terminal / SSH:
   ```bash
   php database/test-connection.php
   ```
   Expect `Result: PASS` with all 13 required tables.

## Applying migrations to an existing install

Import the following in order, via phpMyAdmin or shell:

1. `database/migration-004-phase4.sql` — Phase 4 tables (skip if already applied).
2. `database/migration-005-phase5.sql` — Phase 5 columns.
3. `database/migration-006-reminder-notifications.sql` — Phase 6: reminder notification tracking + `notification_offset_minutes` + `system_health`.
4. `database/migration-007-push-notifications.sql` — Phase 7: `push_subscriptions` + `user_notification_preferences` + `reminder_notifications.channel` column.

All files are idempotent — they can be re-run without side effects.

## Single-user production mode

This deployment runs as a SINGLE-USER application. Public signup is disabled:

- `signup.php` redirects unconditionally to `login.php` with an "sign-up disabled" flash.
- The login page has no "Create one" link.
- The admin Users page has no "Add user" button; `admin-user-form.php` refuses to create new accounts.

### Provisioning the production account

The production account is:

- Email: `accounts@kktechsolutions.in`
- Role:  `admin`
- Status: `active`

The password is NEVER stored in git, code, SQL seeds, or docs. Provision it with the interactive CLI tool:

```bash
cd /home/kktechsolutions/public_html/finance.kktechsolutions.in
php database/create-production-user.php
```

The script:

- Refuses to run under any web SAPI (also blocked by `database/.htaccess`).
- Reads the password without echoing (uses `stty -echo`).
- Requires ≥ 8 chars with at least one digit.
- Bcrypts with the configured cost.
- Refuses to create a second production user; existing accounts can only have their password rotated.
- Invalidates any active password-reset tokens.
- Never prints the password or hash.

To rotate the password later, run the same command again.

## Reminder notification setup

The reminder system uses a **cPanel Cron Job** as the primary off-site notification mechanism. Reminders send email **even when the browser or dashboard is closed**, because the cron worker runs on the server independent of any user session.

### 1. Apply migration 006

Import `database/migration-006-reminder-notifications.sql` via phpMyAdmin.

### 2. Verify SMTP config

Your `config.php` must have valid `mail.*` values (host, port, secure, username, password, from_email). Test with:

```bash
php cron/test-reminder.php
```

It prints your mail config without credentials and sends one test email to `accounts@kktechsolutions.in`. Look for `[ok] send_mail returned true` and confirm the message arrives.

### 3. Schedule the cron job

cPanel → **Cron Jobs** → **Add New Cron Job**:

- **Common Settings**: Once Every 5 Minutes (`*/5 * * * *`).
- **Command**:
  ```
  php /home/kktechsolutions/public_html/finance.kktechsolutions.in/cron/reminder-worker.php >> /home/kktechsolutions/logs/reminder-worker.log 2>&1
  ```
  Adjust the log path to a writable directory (or omit the `>> log 2>&1` if you don't need file logs — the worker also writes to `error_log`).

### 4. Verify it's running

Wait for the cron to fire (~5 min). Then:

- Sign in as admin → the dashboard shows a **Reminder worker** card with:
  - Badge: **Healthy** when the last run was within 15 minutes.
  - **Last run**, **Last successful send**, **Last result**, **Last error** fields.
- Or check phpMyAdmin → `system_health` table.

### 5. Test a real reminder

- Create a reminder due in ~7 minutes with **Email notification: At due time**.
- Wait for the next cron run after the due time.
- Email arrives at `accounts@kktechsolutions.in`.
- Verify a `reminder_notifications` row with `status = 'sent'` and populated `sent_at`.
- Delete the test reminder afterwards.

### How it handles edge cases

- **Duplicate cron runs** — UNIQUE key on `(reminder_id, scheduled_for, notification_type)` in `reminder_notifications` is the atomic claim. Second concurrent worker gets a duplicate-key error, silently skips.
- **Recurring reminders** — for daily/weekly/monthly/yearly, the worker computes the next occurrence and inserts a fresh pending row for it. Each occurrence is a distinct row.
- **Missed reminders** — the catch-up window is `REMINDER_CATCHUP_WINDOW_HOURS = 24`. Overdue notifications within 24 hours are still sent. Older ones are ignored (change the constant in `cron/reminder-worker.php` to widen).
- **Retry** — up to `MAX_ATTEMPTS = 3`. After that the row is marked `failed`; no further attempts.
- **Completed reminders** — worker skips rows where `reminders.status != 'pending'`.
- **Cron unavailable** — dashboard health card flags "Not configured" or "Needs attention". Reminders are still visible in the UI but no emails are sent until the cron fires.

### Log locations

- Worker writes structured JSON via `error_log()` — visible in cPanel → Metrics → Errors, prefixed `[reminder-worker]`.
- Optional file log at whatever path you point the cron command's `>>` to. Rotate manually (`logrotate` on cPanel is per-account).

## Web Push (background notifications)

Reminders can also deliver via Web Push in addition to email, so the user
gets a browser/OS notification even when the dashboard tab is closed.

### 1. Apply migration 007

Import `database/migration-007-push-notifications.sql`.

### 2. Generate a VAPID key pair

From cPanel Terminal (or SSH):

```bash
cd /home/kktechsolutions/public_html/finance.kktechsolutions.in
php cron/generate-vapid-keys.php
```

The script:

- Writes the private key PEM to `$HOME/vapid-private.pem` with `chmod 600`.
- Prints the matching public key (base64url).
- Refuses to run over the web.
- Recovers gracefully — if the PEM already exists, it re-derives and
  prints the public key instead of failing.

Copy the three printed lines into the `push` block of `config.php`:

```php
'push' => [
    'vapid_subject'          => 'mailto:accounts@kktechsolutions.in',
    'vapid_public_key'       => '<the printed base64url public key>',
    'vapid_private_key_path' => '/home/kktechsolutions/vapid-private.pem',
],
```

The private key file and the private key content must **never** enter
Git, HTML, JavaScript, the service worker, SQL, docs, or any chat message.

### 3. Enable push in the browser

Sign in as `accounts@kktechsolutions.in` → **Profile** → "Enable push
notifications". Grant permission when the browser prompts. The button
flips to "Push notifications are enabled on this browser."

### 4. Verify with the built-in test push

Still on the profile page: click **"Send test"**. A notification should
appear within a few seconds. If it does not, check:

- `Notification.permission` in devtools console — must be `"granted"`
- Windows Focus Assist / macOS Do Not Disturb — turn off during testing
- cPanel error log for `[push-webpush]` entries

### 5. Real end-to-end test

Create a reminder due in ~7 minutes. Wait for the next cron run after
the due time. Push and email arrive together at `accounts@kktechsolutions.in`
and any subscribed browser. Row in `reminder_notifications` shows
`status = 'sent'` with `sent_at` populated. Delete the test reminder.

## Post-deploy checklist

- [ ] `config.php` has real credentials — and only on the server, never in git.
- [ ] `session.secure = true` in production (HTTPS-only cookies).
- [ ] `app.environment = 'production'`, `app.debug = false`.
- [ ] `app.base_url` set to your public URL.
- [ ] HTTPS redirect uncommented in root `.htaccess` (see the block near the bottom).
- [ ] Uploaded a fresh copy of `.htaccess` files (root, `uploads/`, `database/`, `includes/`, `cron/`).
- [ ] `db-diag.php` is NOT present on the server (it was a temporary diagnostic; the deploy workflow now excludes it, and the root `.htaccess` denies it defensively).
- [ ] Confirmed that `curl https://your-domain/config.php` returns a 403.
- [ ] Confirmed that `curl https://your-domain/database/schema.sql` returns a 403.
- [ ] Confirmed that `curl https://your-domain/push-webpush.php` returns a 403 (include-only helper).
- [ ] Confirmed that a login round-trip completes and `last_login_at` populates.
- [ ] Confirmed that a password-reset email arrives at a real inbox.
- [ ] Confirmed the reminder cron is scheduled and fired at least once (`system_health` row + reminder-worker.log).
- [ ] VAPID keys generated and Web Push test succeeds from the profile page.
- [ ] Rotated the three seed passwords from `database/README.md`.

## Optional maintenance cron

Old `login_attempts` rows accumulate. Trim them daily with a one-line SQL cron:

```
0 4 * * * /usr/local/bin/mysql -u <user> -p<password> <db> -e "DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 30 DAY"
```

Or run it manually every so often via phpMyAdmin:

```sql
DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 30 DAY;
```

**Never delete rows inside the current lockout window** — the throttle counts recent failures. The 30-day cut-off is well past any configured `lockout_seconds` (default 900s / 15 min).

Optional: prune resolved audit rows older than a policy window:

```sql
DELETE FROM audit_logs WHERE created_at < NOW() - INTERVAL 365 DAY;
```

Keep this longer than your compliance window requires.

## GitHub Actions FTPS deployment

`.github/workflows/deploy.yml` deploys the repo to the cPanel account via **FTPS (explicit TLS, strict cert check)** on every push to `main` or `master`, and on manual dispatch.

**Required GitHub Secrets** (Settings → Secrets and variables → Actions):

| Secret | Purpose |
|---|---|
| `FTP_SERVER` | cPanel FTP host, e.g. `ftp.kktechsolutions.in` |
| `FTP_USERNAME` | Dedicated FTP user for this repo. Best practice: create a per-repo FTP user in cPanel → FTP Accounts, with home directory pinned to the site's document root. |
| `FTP_PASSWORD` | The FTP account's password. Never commit it. |
| `FTP_PORT` | Optional; defaults to 21. |
| `FTP_REMOTE_DIR` | Optional; defaults to `./`. Set to the site's docroot when deploying from a mono-repo (e.g. `/public_html/finance/`). |

**Excluded from every deploy** — the workflow never uploads these:
- `config.php` (server-side only — never overwritten)
- `uploads/**` (user files) — the `.htaccess` guard and `.gitkeep` sentinels ARE uploaded
- `videos/` (motion-graphics scratch)
- `docs/`, `README.md`, `database/*.sql`, `.github/`, `.git*`, `node_modules/`, backup / log / tmp files

**Before the first deploy**:
1. Push this repo to GitHub.
2. Add the five secrets above.
3. Rename the branch to `main` (optional — workflow also triggers on `master`).
4. Trigger a manual run via **Actions → Deploy to cPanel (FTPS) → Run workflow** — this smoke-tests the credentials before you rely on push-triggered deploys.

**Post-deploy**: check the Actions run for green; browse the domain; if you see 500, follow the recovery playbook below.

## Recovering from a "500 Internal Server Error" on every PHP page

Symptom: `curl https://finance.kktechsolutions.in/login.php` returns 500.

Diagnose in this order:

1. **cPanel → Metrics → Errors** — read the top few lines of the error log for this domain. Almost always names the fatal.
2. **Is `config.php` on the server?** Files can deploy without it (the workflow excludes it). If missing, `getDatabaseConnection()` throws on the first DB-touching page. Fix: create `config.php` on the server via cPanel File Manager.
3. **Are the DB credentials correct?** cPanel prefixes both the DB name and DB user with your account name. Cross-check with cPanel → MySQL Databases.
4. **Is PHP 8.4 selected?** cPanel → MultiPHP Manager → pick the domain → set to `PHP 8.4`. The app uses PHP 8.4 features (named arguments, readonly, etc.).
5. **Is the `.htaccess` uploaded?** Root `.htaccess` sets `DirectoryIndex index.php` and denies dotfiles. Without it, the root returns 403.
6. **Is the deploy user's home directory the docroot?** If the FTP user drops files into `/public_html/` but the site is served from `/public_html/finance/`, nothing lands where Apache looks. Fix in cPanel → FTP Accounts.

Symptom: `curl https://finance.kktechsolutions.in/` returns 403.

- Confirm `index.php` is at the docroot.
- Confirm `.htaccess` is present at the docroot.
- Confirm no `deny from all` was added by a prior tenant or a Cloudflare/security plugin.

## Rollback

- **App code**: `git revert` the Phase 5 commit and redeploy. All migrations are additive so existing rows still work with Phase 4 code.
- **Schema**: no automatic rollback — the migrations do not include DROP statements. If you must, manually drop the columns added by migration-005 after confirming nothing depends on them.

## Support notes

- If a user hits "Too many failed attempts", clear their recent failures manually:
  ```sql
  DELETE FROM login_attempts WHERE email = 'user@example.com' AND was_successful = 0 AND attempted_at >= NOW() - INTERVAL 15 MINUTE;
  ```
- If the sole active admin is locked out, connect via phpMyAdmin and set `users.status = 'active'` + reset the password hash directly.
