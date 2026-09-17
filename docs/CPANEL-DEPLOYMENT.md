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

## GitHub Actions FTPS deployment (planned)

Not part of Phase 5. When you're ready, add `.github/workflows/deploy.yml` with an FTPS deployer action, gated on `main` pushes, secrets pulled from repo settings — details to be filled in Phase 6.

## Rollback

- **App code**: `git revert` the Phase 5 commit and redeploy. All migrations are additive so existing rows still work with Phase 4 code.
- **Schema**: no automatic rollback — the migrations do not include DROP statements. If you must, manually drop the columns added by migration-005 after confirming nothing depends on them.

## Support notes

- If a user hits "Too many failed attempts", clear their recent failures manually:
  ```sql
  DELETE FROM login_attempts WHERE email = 'user@example.com' AND was_successful = 0 AND attempted_at >= NOW() - INTERVAL 15 MINUTE;
  ```
- If the sole active admin is locked out, connect via phpMyAdmin and set `users.status = 'active'` + reset the password hash directly.
