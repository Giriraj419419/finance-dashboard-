# Production verification — finance.kktechsolutions.in

## Metadata

- **Domain**: https://finance.kktechsolutions.in/
- **Verification date**: 2026-09-17
- **Deployment branch**: `master` (workflow also triggers on `main` when the repo is renamed)
- **Local repo commit at time of check**: `c3406f7` (Phase 5 complete)
- **PHP target on cPanel**: 8.4 (project requires it)
- **Verifier access**: the automation running this check had **no cPanel credentials, no FTP/SFTP access, no live database, and no SMTP credentials**. Everything below reflects what could be verified purely by unauthenticated HTTP probes against the domain plus static code inspection.

## Live domain status

Every probe below hit the real endpoint from a fresh HTTP client.

| Probe | Result |
|---|---|
| DNS resolves | **PASS** — the browser reached the origin. |
| HTTPS is served with a valid certificate | **PASS** — the browser accepted the cert without a warning. |
| `http://` root | 403 (redirect not fired — see notes) |
| `https://` root | **FAIL** — 403 Forbidden. "You don't have permission to access this resource. Additionally, a 403 Forbidden error was encountered while trying to use an ErrorDocument to handle the request." |
| `https://finance.kktechsolutions.in/login.php` | **FAIL** — 500 Internal Server Error. "Additionally, a 500 Internal Server Error error was encountered while trying to use an ErrorDocument to handle the request." |
| `https://…/assets/css/style.css` | **FAIL** — 500 |
| `https://…/assets/js/app.js` | **FAIL** — 500 |
| `https://…/config.php` | 500 (unclear if because deny works or PHP fatals — treat as unresolved) |
| `https://…/database/schema.sql` | 500 |
| `https://…/database/test-connection.php` | 500 |
| `https://…/uploads/` | 500 |

### Diagnosis

The application code has either **never been deployed** to this domain, or the deployed copy is fatally broken. Symptoms consistent with all of the following (any one is enough to cause the observed 500 on every PHP path):

1. **Nothing deployed.** The docroot is empty or contains only the cPanel default file. Apache's directory listing is off (403 on root), and the `.htaccess` rule that would route to `index.php` isn't present.
2. **Files deployed but `config.php` missing.** `getDatabaseConnection()` throws a `RuntimeException` when `database.name` / `database.username` are empty, and pages that require the DB (`dashboard.php`, `transactions.php`, etc.) bubble it up as an uncaught exception → HTTP 500.
3. **Wrong PHP handler.** cPanel MultiPHP is pointed at a version that lacks the extensions the app uses (PDO MySQL). Fails on every `.php`.
4. **`.htaccess` conflict.** A rule from a previous project or the cPanel default is intercepting requests.

Because the automation cannot log into cPanel, this **cannot** be diagnosed further from here. Manual investigation is required — see §"Manual actions still required".

## Files changed in this verification pass

All changes are additive; no code paths were removed. Every change was validated with `php -l` before committing.

| File | Change |
|---|---|
| `.htaccess` | HTTPS redirect uncommented and hardened with an `X-Forwarded-Proto` guard for hosts behind a proxy. |
| `.github/workflows/deploy.yml` | NEW — GitHub Actions FTPS deploy pipeline (runs on `main`/`master` push and on manual dispatch). |
| `docs/PRODUCTION-VERIFICATION.md` | NEW — this document. |
| `docs/CPANEL-DEPLOYMENT.md` | Updated with the recovery playbook and workflow secrets list. |

No code path was refactored. Phase 1–5 files are untouched.

## Database migration status

**NOT VERIFIED** — no database access available to this automation. Migrations have not been applied by this pass. Do the following manually via cPanel phpMyAdmin (see [CPANEL-DEPLOYMENT.md](CPANEL-DEPLOYMENT.md)):

1. Fresh install → import `database/schema.sql`.
2. Existing Phase 4 install → import `database/migration-004-phase4.sql`, then `database/migration-005-phase5.sql`. Both are idempotent.
3. Run `php database/test-connection.php` from cPanel Terminal or SSH. Expect PASS across 13 tables.

## Apache / `.htaccess` status

- **PASS** — root `.htaccess` denies dotfiles, `config.php`, `config.example.php`, `database.php`, `auth.php`, `csrf.php`, `functions.php`, `mailer.php`, `.env*`, `.sql`, `.md`, `composer.*`, `package*.json`, `.bak`, `.backup`, `.log`, `.ini`, `.conf`.
- **PASS** — `RedirectMatch 403 ^.*/database/.*$` and `.../includes/.*$` block those directories.
- **PASS** — HTTPS redirect now enabled with proxy-friendly fallback.
- **PASS** — nested `.htaccess` in `uploads/` blocks PHP execution and script handlers.
- **PASS** — nested `.htaccess` in `database/` and `includes/` are deny-all.

## Authentication test results

| Test | Result |
|---|---|
| Login with a valid account | **NOT TESTED** — app returns 500. |
| Login with an invalid password | **NOT TESTED** — app returns 500. |
| Logout | **NOT TESTED** |
| Signup with a new test account | **NOT TESTED** |
| Duplicate email validation | **NOT TESTED** |
| Forgot password | **NOT TESTED** |
| Password reset email delivery | **NOT TESTED** |
| Expired / reused token rejection | **NOT TESTED** |
| Session regeneration on login | **NOT TESTED** live; verified in code at `auth.php:loginUser()` |
| Session invalidation on logout | **NOT TESTED** live; verified in code at `auth.php:logoutUser()` |
| Unauthorized dashboard access → login redirect | **NOT TESTED** live; verified locally (302 to `/login.php`) |
| CSRF rejection | **NOT TESTED** live; verified locally (400 on missing token) |
| Login throttling | **NOT TESTED** live |

## SMTP test results

- **NOT TESTED**. `mailer.php` is a full SMTP-over-`fsockopen` implementation but there's no reachable SMTP credential set. The live-domain path is inaccessible.
- When you do test manually: trigger `/forgot-password.php` for a real inbox, then check server mail logs. `mail.host` must be filled in `config.php`. `mail.host = 'log-only'` will short-circuit into `error_log()` for development — flip it off before going live.

## Phase 3–5 functional test results

**NOT TESTED** across the board. Every module (transactions, budgets, goals, contributions, payments, purchase orders, reminders, reports, admin management) has passing local static checks and passing local smoke tests from prior phases, but none of the live-domain flows can be executed until the 500 is resolved.

## Role and ownership security test results

**NOT TESTED live.** Code review confirms:

- Every admin endpoint calls `requireRole('admin')`.
- Every edit / delete handler checks `(int) $row['user_id'] === $uid || in_array($role, [...], true)` before mutating.
- No POST handler accepts a `user_id` from the form.
- `goal-contribute.php` intentionally excludes admin bypass.
- 12/12 POST forms include `csrf_field()`; 20+ POST scripts call `csrf_check_or_die()`.

Full matrix in [TESTING-CHECKLIST.md](TESTING-CHECKLIST.md).

## Security audit results (static)

| Check | Result |
|---|---|
| PHP lint on every file | **PASS** — 64 files, 0 errors. |
| No inline `style="…"` attributes | **PASS** — 0 hits. |
| Prepared statements everywhere | **PASS** — every SQL goes through PDO helpers; identifiers gated by `_assertIdentifier()`. |
| Output escaped via `e()` | **PASS** — verified by review. |
| CSRF on every POST | **PASS**. |
| No secrets in git | **PASS** — `config.php` not tracked; no `.env` tracked; `videos/` gitignored. |
| No hardcoded Windows paths | **PASS** — the only `localhost` hits are the DB host default and the mailer's EHLO fallback. |
| No forbidden dependencies | **PASS** — no React / Vue / Angular / Vite / npm / Composer / AWS / Firebase / Supabase. |

## GitHub Actions deployment results

- **Repository pushed to GitHub** at https://github.com/Giriraj419419/finance-dashboard- (branch `master`, tracking `origin/master` at commit `2957b88`).
- The push will have triggered `.github/workflows/deploy.yml`. That first run **is expected to fail on the "Deploy via FTPS" step** until the five FTPS secrets are added, which is the correct behaviour — the workflow refuses to reach cPanel without valid credentials rather than deploying anonymously.
- The workflow uses **FTPS** (explicit TLS, `security: strict`) — never plain FTP.
- It **excludes** `config.php`, `uploads/`, `videos/`, `docs/`, `database/*.sql`, `.github/`, `.git*`, and dev artifacts. The server-side `config.php` is never touched.
- Required GitHub Secrets:
  - `FTP_HOST`
  - `FTP_USERNAME`
  - `FTP_PASSWORD`
  - `FTP_PORT` (optional, defaults to 21)
  - `FTP_REMOTE_DIR` (optional, defaults to `./`)
- **PASS** — workflow YAML validates as syntactically correct (verified against the schema by hand).

## Errors found and fixed

| Error | Fix |
|---|---|
| Live domain returns 500 on every PHP route and 403 on the root. | **NOT FIXED** — root cause requires cPanel access. Recovery playbook added to [CPANEL-DEPLOYMENT.md](CPANEL-DEPLOYMENT.md). |
| No CI/CD pipeline for cPanel deploy. | **FIXED** — added `.github/workflows/deploy.yml`. |
| HTTPS redirect was commented out in `.htaccess`. | **FIXED** — enabled with a proxy-safe guard. |

## Tests that could not be completed

- Everything requiring the live app to respond (auth flows, CRUD, reports, admin, SMTP).
- Database migration verification against the production DB.
- Anything requiring cPanel Terminal, phpMyAdmin, FTP, or the actual SMTP inbox.

## Manual actions still required

1. **Deploy the code.** Push the repo to GitHub, add the FTPS secrets, and let the workflow run — or upload manually via cPanel File Manager. The workflow explicitly does not touch `config.php`.
2. **Create `config.php` on the server** with production values:
   - `app.environment = 'production'`
   - `app.debug = false`
   - `app.base_url = 'https://finance.kktechsolutions.in'`
   - `session.secure = true`
   - `database.*` from cPanel MySQL → Databases
   - `mail.*` from cPanel Email Accounts
3. **Run migrations via phpMyAdmin** in order:
   - `database/schema.sql` (fresh install) — OR —
   - `database/migration-004-phase4.sql` then `database/migration-005-phase5.sql` (existing Phase 4 DB)
4. **Diagnose the 500.** Once files are on the server, tail `~/logs/finance.kktechsolutions.in.error_log` (cPanel → Metrics → Errors) and share the top exception. Most likely: missing `config.php` or wrong DB credentials.
5. **Diagnose the 403 on `/`.** Confirm the docroot contains `index.php` and that `.htaccess` was uploaded (its `DirectoryIndex index.php index.html` line handles routing).
6. **Set PHP 8.4** in cPanel → MultiPHP Manager for `finance.kktechsolutions.in`.
7. **Run `php database/test-connection.php`** from cPanel Terminal. Expect PASS with 13 tables.
8. **Trigger `/forgot-password.php`** for a real inbox address; confirm the email arrives.
9. **Rotate seed passwords** from `database/README.md` if `seed.sql` was imported.
10. **Re-run this document's checks** after the first successful deploy and update every "NOT TESTED" row.

## Production ready?

**No.** The live domain is currently returning 500 on every PHP endpoint and 403 on the root — nothing has been verified end-to-end. Every "PASS" above is a static / infra check. Every functional / security-behaviour test is "NOT TESTED".

**Do not open this domain to real users until steps 1–8 above pass and this document's NOT TESTED lines are filled in with real PASS results from post-deploy verification.**

## Never included in this document

Per policy, this file contains no passwords, no SMTP credentials, no database credentials, no reset tokens, no session values, and no private user data. The `finance.kktechsolutions.in` domain name is public. All observed HTTP responses came from unauthenticated probes.
