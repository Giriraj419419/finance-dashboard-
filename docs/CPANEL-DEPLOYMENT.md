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

Both files are idempotent — they can be re-run without side effects.

## Post-deploy checklist

- [ ] `config.php` has real credentials — and only on the server, never in git.
- [ ] `session.secure = true` in production (HTTPS-only cookies).
- [ ] `app.environment = 'production'`, `app.debug = false`.
- [ ] `app.base_url` set to your public URL.
- [ ] HTTPS redirect uncommented in root `.htaccess` (see the block near the bottom).
- [ ] Uploaded a fresh copy of `.htaccess` files (root, `uploads/`, `database/`, `includes/`).
- [ ] Confirmed that `curl https://your-domain/config.php` returns a 403.
- [ ] Confirmed that `curl https://your-domain/database/schema.sql` returns a 403.
- [ ] Confirmed that a login round-trip completes and `last_login_at` populates.
- [ ] Confirmed that a password-reset email arrives at a real inbox.
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
| `FTP_HOST` | cPanel FTP host, e.g. `ftp.kktechsolutions.in` |
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
