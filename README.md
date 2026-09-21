# Finance Dashboard

Plain-PHP finance management app for a single production workspace at
[finance.kktechsolutions.in](https://finance.kktechsolutions.in/). Tracks
transactions, budgets, goals, payments, purchase orders, and reminders,
sends reminder notifications by Web Push and email, and runs on shared
cPanel hosting with a GitHub Actions FTPS deploy.

## Stack

- **Backend**: PHP 8.4 (no framework, no Composer)
- **Frontend**: HTML, one CSS file (`ui/css/style.css`), vanilla JavaScript
  (`ui/js/app.js`, `ui/js/push.js`) — no bundler
- **Database**: MySQL 8 + PDO with prepared statements
- **Web server**: Apache on cPanel, hardened with `.htaccess`
- **Email**: cPanel SMTP over `fsockopen` (implicit SSL on 465, or STARTTLS
  on 587)
- **Notifications**: cPanel Cron → PHP CLI worker → Web Push (VAPID +
  AES-128-GCM) with SMTP email as a parallel delivery channel
- **Deploy**: GitHub Actions FTPS to cPanel

Forbidden by policy on this project: React, Vue, Angular, Vite, Webpack,
npm, Node, Tailwind, Bootstrap, jQuery, Composer packages, AWS, Firebase.

## Features

- Sign in / sign out with bcrypt, session regeneration, DB-backed login
  throttle, and a constant-time verify path to close the user-enumeration
  timing side channel
- Password reset by email with hashed, single-use tokens (1 h TTL)
- Full CRUD for transactions, budgets, goals (+ contribution ledger),
  payments, purchase orders (+ line items), reminders
- Reports: transactions, budgets, goals, payments, income-vs-expense
- CSV export for every report (formula-injection safe, UTF-8 BOM)
- Reminders with priorities, recurrence, and per-reminder notification
  offset; delivered by Web Push and email even when the browser is closed
- Admin: user list, status toggle, admin-only password reset. Last-admin
  protection prevents locking yourself out
- Full audit log of significant actions

## Production mode

- Public sign-up is **disabled** — `signup.php` redirects to `login.php`
  with a flash message
- The production account is provisioned once from cPanel Terminal with
  `database/create-production-user.php` (interactive, never accepts a
  password in an argv or a chat message)
- Second production users are refused; the same script also rotates the
  existing password

## Local setup

1. Copy `config.example.php` to `config.php` and fill in real values.
2. Create a MySQL database + user, then import `database/schema.sql`.
3. (Optional dev-only) import `database/seed.sql` — rotate the seed
   passwords immediately.
4. Point Apache at the project root. The root `.htaccess` handles routing,
   deny rules, HTTPS redirect, and basic security headers.
5. `uploads/` is writable but cannot execute PHP (enforced by its own
   `.htaccess`).

## Deployment

See [docs/CPANEL-DEPLOYMENT.md](docs/CPANEL-DEPLOYMENT.md) for the
complete cPanel + GitHub Actions playbook, including:

- cPanel first-time setup and migration order
- FTPS deploy workflow secrets (`FTP_SERVER`, `FTP_USERNAME`,
  `FTP_PASSWORD`, `FTP_PORT`, `FTP_REMOTE_DIR`)
- Reminder cron job command
- Web Push VAPID key generation (`cron/generate-vapid-keys.php`)
- SMTP smoke test (`cron/test-reminder.php`)
- Recovery playbook for 500 / 403 on first deploy
- Database backup and audit-log retention recipes

## Folder layout

```
finance-dashboard/
├── *.php                  # page entry files
├── config.example.php     # committed template
├── config.php             # local / server-only, gitignored
├── database.php           # PDO factory + query helpers
├── auth.php               # session + role helpers
├── csrf.php               # CSRF token + verifier
├── functions.php          # shared utilities
├── mailer.php             # SMTP over fsockopen
├── login-throttle.php     # DB-backed failure counter
├── audit.php              # audit_logs helper
├── push-webpush.php       # RFC 8291 push sender (VAPID + aes128gcm)
├── push-subscribe.php     # subscribe endpoint (JSON or form)
├── push-unsubscribe.php   # unsubscribe endpoint
├── push-test.php          # POST-only test push
├── sw.js                  # service worker (push + notificationclick)
├── .htaccess              # Apache rules
├── ui/
│   ├── css/style.css      # single stylesheet
│   └── js/{app.js,push.js}
├── includes/              # layout partials (sidebar, topbar, header,
│                            footer, flash-messages, auth-check)
├── uploads/               # user files, PHP execution blocked
├── database/              # schema, migrations, CLI tools
├── cron/                  # reminder-worker, generate-vapid-keys,
│                            test-reminder (all CLI-only)
├── docs/                  # deployment, verification, testing checklist
└── .github/workflows/     # GitHub Actions FTPS deploy
```

## Security posture

- Every state-changing request is CSRF-protected (`csrf_check_or_die()`
  in every POST handler)
- Every SQL query goes through PDO prepared statements; identifiers in
  the helpers are guarded by an allowlist (`_assertIdentifier()`)
- All HTML output of user-controlled data uses `e()` (htmlspecialchars)
- Login throttled at 5 failures / 15 min → 15 min lockout (configurable)
- HTTPS redirect + HSTS + basic security headers in `.htaccess`
- `config.php`, `.env*`, `*.pem`, `*.key`, and `uploads/` never enter Git
  or the deploy tarball
- Session cookies default to `HttpOnly` + `SameSite=Lax`; set
  `session.secure = true` in production `config.php`
- Bugs and unfinished work: see [docs/PRODUCTION-VERIFICATION.md](docs/PRODUCTION-VERIFICATION.md)

## Ongoing maintenance

- Trim `login_attempts` daily (see `docs/CPANEL-DEPLOYMENT.md`
  §"Optional maintenance cron")
- Rotate `audit_logs` per your retention policy
- Keep the reminder worker healthy — the dashboard shows a `system_health`
  card for admins that flags a stalled cron
