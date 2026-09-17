# Finance Dashboard

A professional, plain-PHP finance management dashboard for tracking transactions, budgets, goals, payments, purchase orders, and reminders across Admin / Manager / Employee roles.

## Stack

- PHP 8.4 (no framework)
- MySQL + PDO
- Vanilla HTML, one CSS file, vanilla JavaScript
- Apache + `.htaccess`
- cPanel hosting, cPanel SMTP for password reset
- Local `uploads/` directory for files
- GitHub + GitHub Actions FTPS for deployment

No React, Vue, Angular, Vite, Webpack, npm, Node, Tailwind, Bootstrap, jQuery, Composer packages, or AWS.

## Current status: Phase 2

The MySQL schema and PHP PDO backend foundation are in place. Auth, CRUD, and email are still ahead.

**What's new since Phase 1:**
- `database/schema.sql` — 11 InnoDB tables (users, transactions, budgets, goals, payments, purchase_orders, purchase_order_items, reminders, reports, password_reset_tokens, audit_logs) with foreign keys, indexes, and `DECIMAL(12,2)` money columns.
- `database/seed.sql` — idempotent development sample data with real bcrypt hashes.
- `database/README.md` — cPanel-first setup walkthrough.
- `database/test-connection.php` — CLI self-test (config, connect, version, charset, tables, error surface). Blocks web SAPI.
- `database.php` — memoised `getDatabaseConnection()` plus `executeQuery`, `fetchOne`, `fetchAll`, `insertRecord`, `updateRecord` helpers; `install_db_error_handler()` for a safe production surface.
- `config.php` / `config.example.php` restructured with `database` + `mail` + `security` sections.
- `.htaccess` extended to deny `.bak`, `.backup`, `.log`, `.ini`, `.conf` files.

## Phase 1 status

Phase 1 delivered the **foundation and UI shell only**:

- Complete folder structure
- Layout shell (sidebar, topbar, main container, footer, flash area, mobile menu)
- Dashboard page with placeholder cards, transactions table, budgets, goals, payments, reminders
- Page shells for Transactions, Budgets, Goals, Payments, Purchase Orders, Reminders, Reports, Profile, Settings
- Auth page shells (login, signup, forgot-password, reset-password) — forms present, logic wired up in a later phase
- CSS design tokens, single stylesheet
- Vanilla JS module for shell behavior
- Skeletons for `auth.php`, `csrf.php`, `database.php`, `functions.php`, `mailer.php`
- `.htaccess` hardening, protected `config.php`, non-executing `uploads/`
- SQL schema draft in `database/schema.sql`

Placeholder values on the dashboard are marked in code as demo data — **they do not come from MySQL yet**.

## Local setup

1. Copy `config.example.php` to `config.php` and fill in the DB + SMTP details.
2. Create a MySQL database and import `database/schema.sql` (then `database/seed.sql` if you want demo rows).
3. Point Apache at the project root. `.htaccess` handles the rest.
4. Ensure `uploads/` is writable but cannot execute PHP (enforced by the nested `.htaccess`).

## Folder layout

```
finance-dashboard/
├── *.php               # page entry files
├── config.example.php  # committed template
├── config.php          # local only, gitignored
├── database.php        # PDO factory
├── auth.php            # session + role helpers
├── csrf.php            # CSRF token helpers
├── functions.php       # shared utilities
├── mailer.php          # SMTP sender
├── .htaccess           # Apache rules
├── assets/
│   ├── css/style.css   # single stylesheet
│   └── js/app.js       # single JS entry
├── includes/           # layout partials
├── api/                # future JSON endpoints
├── uploads/            # user files (no PHP exec)
└── database/           # schema + seed SQL
```

## What is NOT done yet

- Real authentication (session login, bcrypt verify, role guards)
- CSRF token verification wired into every POST
- CRUD for any module
- SMTP send for password reset
- GitHub Actions FTPS workflow
- Automated tests

Each of these is scheduled for later phases.
