# Phase 5 — Purchase Orders, Reminders, Reports, Admin Users

## What ships in Phase 5

- **Purchase Orders**: list ([purchase-orders.php](../purchase-orders.php)), create ([po-new.php](../po-new.php)), edit + items ([po-edit.php](../po-edit.php)), delete ([po-delete.php](../po-delete.php)). Auto-computed subtotal / tax / total. Status enum widened to include Phase 5 values while keeping Phase 2 values valid.
- **Reminders**: full CRUD ([reminders.php](../reminders.php), [reminder-new.php](../reminder-new.php), [reminder-edit.php](../reminder-edit.php), [reminder-delete.php](../reminder-delete.php)), plus complete ([reminder-complete.php](../reminder-complete.php)) and reopen ([reminder-reopen.php](../reminder-reopen.php)). Priority column, filter tabs for pending / overdue / upcoming / completed / all.
- **Reports**: hub ([reports.php](../reports.php)) + five reports — Income & Expense ([report-income-expense.php](../report-income-expense.php)), Transactions ([report-transactions.php](../report-transactions.php)), Budget Performance ([report-budgets.php](../report-budgets.php)), Goal Progress ([report-goals.php](../report-goals.php)), Payment Summary ([report-payments.php](../report-payments.php)). All server-side SQL, no chart libraries.
- **Admin User Management**: [admin-users.php](../admin-users.php) list, [admin-user-form.php](../admin-user-form.php) create + edit, [admin-user-status.php](../admin-user-status.php) POST activate/deactivate, [admin-user-reset.php](../admin-user-reset.php) POST reset-password trigger.
- **Dashboard** picks up PO status summary, overdue reminders, recent activity from `audit_logs`.

## Migration order

Every migration is idempotent — running twice does no harm.

1. `database/schema.sql` — fresh install baseline (all Phase 2 + Phase 4 + Phase 5 columns).
2. `database/migration-004-phase4.sql` — for DBs first created before Phase 4 (`goal_contributions`, `login_attempts`).
3. `database/migration-005-phase5.sql` — Phase 5: widens `purchase_orders.status`, adds supplier email/phone/subtotal/tax columns, adds `purchase_order_items.description` + `tax_rate`, adds `reminders.priority` + `related_module` + `related_id`.

For a fresh install, only step 1 is required; the schema already has the Phase 5 shape.

## Config

Existing config values are unchanged. No new keys were added for Phase 5. The Phase 4 `security.login_throttle` block still applies.

## Roles

| Role | Purchase orders | Reminders | Reports | Admin users |
|---|---|---|---|---|
| Employee | Own only, full CRUD | Own only, full CRUD | Own data only | ❌ |
| Manager | Own + team-wide read/list, may edit any per business rule | Own only | Own data only | ❌ |
| Admin | Full | Full (own + read/edit any) | Own data only | ✅ |

Admin bypass is granted on **transactions / budgets / goals / payments / reminders / purchase_orders** edit-and-delete paths. It is **not** granted on `goal-contribute.php` (money movement stays owner-only) — same policy as Phase 4.

## Last-active-admin protection

`admin-user-form.php` and `admin-user-status.php` count `role='admin' AND status='active'` rows before allowing any change that would drop the count to 0. Trying to demote or deactivate the sole remaining active admin returns a flash error and leaves the DB unchanged.

## Report filter behaviour

- Every date filter accepts `YYYY-MM-DD` only; malformed values are ignored and the report resets to a safe default.
- Sortable columns are whitelisted; unknown `sort=` values are ignored.
- Every filter parameter binds through prepared statements — no string interpolation of user input into SQL.
- Every report is scoped to the current user only (no cross-user leakage).

## Known limitations

- **CSV export not implemented in Phase 5** — the spec allowed skipping it since the project has no existing safe export pattern. Add later if needed.
- **Managers and admins see their own data on the report pages, not team-wide aggregate reports** — team reporting is a Phase 6 candidate.
- **Reminders are database-only** — no cron worker sends email/push notifications yet. Overdue state is computed on read.
- **Password reset from the admin list** uses the same email delivery as the public `forgot-password.php` flow (cPanel SMTP). It requires a working `mail.host` in `config.php` or the reset link cannot be delivered.
- **Test coverage** in `database/test-connection.php` now enforces all 13 tables. Extend the required-tables array if additional tables are added later.

## Rollback

- Schema changes in migration-005 are additive only. No columns or values are dropped.
- The `purchase_orders.status` enum widening is a superset, so existing rows remain valid.
- To roll back to Phase 4, you can drop the new columns (`supplier_email`, `supplier_phone`, `subtotal`, `tax_amount`, `purchase_order_items.description`, `purchase_order_items.tax_rate`, `reminders.priority`, `reminders.related_module`, `reminders.related_id`) — but only after confirming nothing else uses them.
