# Testing checklist

Run these checks after any deployment or significant PR.

## Static

- [ ] `find . -name "*.php" -type f | xargs -I{} php -l {}` returns "No syntax errors" for every file.
- [ ] `grep -rnE 'style="' --include="*.php" .` returns nothing.
- [ ] `grep -rniE 'react|vue|angular|vite|webpack|tailwind|bootstrap|jquery|amazonaws|firebase|supabase|\baws-sdk\b' --include="*.php" --include="*.js" --include="*.css" .` returns only documented mentions of the ban list.
- [ ] No `package.json`, `composer.json`, `node_modules/`, or `vendor/` exist at the project root.
- [ ] `config.php` is **not** tracked in git (`git ls-files config.php` empty).

## CSRF coverage

For every POST-accepting script:
- [ ] Calls `csrf_check_or_die()`.

For every POST form in `.php` output:
- [ ] Emits `csrf_field()`.

## Authentication

- [ ] Anonymous GET on protected pages redirects to `/login.php`:
  - `/dashboard.php`, `/transactions.php`, `/transaction-*.php`, `/budgets.php`, `/budget-*.php`,
    `/goals.php`, `/goal-*.php`, `/payments.php`, `/payment-*.php`,
    `/reminders.php`, `/reminder-*.php`, `/purchase-orders.php`, `/po-*.php`,
    `/reports.php`, `/report-*.php`, `/profile.php`, `/settings.php`, `/admin-*.php`.
- [ ] Public GETs render 200: `/login.php`, `/signup.php`, `/forgot-password.php`, `/reset-password.php`, `/logout.php`.
- [ ] Employee GET on `/settings.php` and `/admin-users.php` → 403.
- [ ] Manager GET on `/admin-users.php` → 403.
- [ ] Admin GET on `/admin-users.php` → 200.

## Ownership audit

Sign in as User A and User B (two `employee` seed users). For each of the pairs below, confirm A cannot read/modify B's:

- [ ] Transaction (`/transaction-edit.php?id=<B's txn id>` → 403)
- [ ] Budget (`/budget-edit.php?id=<B's budget id>` → 403)
- [ ] Goal (`/goal-edit.php?id=<B's goal id>` → 403)
- [ ] Goal contribution (`/goal-contribute.php?id=<B's goal id>` → 403 — admin bypass NOT allowed here on purpose)
- [ ] Payment (`/payment-edit.php?id=<B's payment id>` → 403)
- [ ] Reminder (`/reminder-edit.php?id=<B's reminder id>` → 403 unless A is admin)
- [ ] Purchase order (`/po-edit.php?id=<B's po id>` → 403 unless A is admin or manager)

## SQL audit

- [ ] Every SQL statement passes user data through `:name` bind params.
- [ ] Every dynamic identifier (table / column name) passes `_assertIdentifier()` inside `insertRecord` / `updateRecord`.
- [ ] Every `LIMIT` / `OFFSET` interpolation only mixes integer casts, never strings.

## Functional (requires live MySQL)

- [ ] Create → edit → delete transaction.
- [ ] Create → edit → delete budget.
- [ ] Create → edit → contribute → contribute → delete goal.
- [ ] Create → edit → mark paid → delete payment.
- [ ] Create → add item → remove item → change status → delete purchase order. Watch totals recompute.
- [ ] Create → edit → complete → reopen → delete reminder.
- [ ] Run each of the five reports with default and custom date ranges. Confirm they render zeros gracefully on an empty account.
- [ ] Admin: add a new user, edit them, change their role, deactivate them, reactivate them.
- [ ] Admin: attempt to demote the last active admin → refused with a flash error.
- [ ] Admin: reset another user's password → confirm email delivery.
- [ ] Sign up as a new user → default role = `employee`, active, redirected to dashboard.
- [ ] Sign in with wrong password 6 times → 6th attempt refused with "Too many failed attempts."
- [ ] Sign in with the correct password → failure count cleared for that email.

## Database

- [ ] `php database/test-connection.php` reports **PASS** with all 13 required tables.
- [ ] `INFORMATION_SCHEMA.COLUMNS` shows the Phase 5 additions:
  - `purchase_orders.supplier_email`, `supplier_phone`, `subtotal`, `tax_amount`, widened `status` enum
  - `purchase_order_items.description`, `tax_rate`
  - `reminders.priority`, `related_module`, `related_id`
- [ ] All monetary columns are `DECIMAL` (grep the schema).
- [ ] Foreign keys exist between the tables listed in `database/schema.sql`.
- [ ] Common-filter indexes exist (`user_id + date` on transactions/reminders/payments; `email` and `ip_address` on `login_attempts`).

## Tests not available locally

If MySQL / SMTP / cPanel aren't reachable from your dev machine, document that explicitly in your PR notes. Do NOT check the boxes above without actually running the checks.
