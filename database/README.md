# Database setup (cPanel + MySQL)

This guide walks you from a fresh cPanel account to a working Finance Dashboard database. It assumes you already uploaded the project files to your hosting account.

Files touched in this phase:
- `database/schema.sql` — table definitions
- `database/seed.sql` — optional development sample rows
- `database/test-connection.php` — CLI self-test
- `config.php` — where connection settings live (git-ignored)

---

## 1. Create the MySQL database

1. Log in to cPanel.
2. Open **MySQL® Databases**.
3. Under **Create New Database**, enter a name — e.g. `finance_dashboard`.
   cPanel prefixes it with your account name, so the final name looks like `youracct_finance_dashboard`. Use that full name in `config.php`.
4. Click **Create Database**.

## 2. Create a database user

1. On the same page, scroll to **MySQL Users → Add New User**.
2. Pick a username (again, cPanel prefixes with your account name).
3. Use **Password Generator** to create a long random password. **Copy it now** — you'll paste it into `config.php` and won't see it again.
4. Click **Create User**.

## 3. Assign privileges

1. Scroll to **Add User To Database**.
2. Pick your new user and your new database.
3. Click **Add**.
4. On the next screen, tick **ALL PRIVILEGES** and click **Make Changes**.
   (You can tighten this to `SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES` later — those are all schema.sql needs.)

## 4. Import `schema.sql`

Option A — **phpMyAdmin** (recommended for cPanel):

1. Open **phpMyAdmin** from cPanel.
2. Pick your new database on the left.
3. Click the **Import** tab.
4. **Choose File** → select `database/schema.sql` from your local project.
5. Leave the format as **SQL** and click **Import**.
6. Confirm you now see tables: `users`, `transactions`, `budgets`, `goals`, `payments`, `purchase_orders`, `purchase_order_items`, `reminders`, `reports`, `password_reset_tokens`, `audit_logs`.

Option B — **cPanel Terminal / SSH**:

```bash
mysql -u youracct_dbuser -p youracct_finance_dashboard < database/schema.sql
```

## 5. (Optional) Import `seed.sql`

Only in **development**. Skip on production.

Same steps as (4), using `database/seed.sql`. It's idempotent: running it a second time will not create duplicate rows.

Development-only seed logins (change or remove before production):

| Email | Password | Role |
|---|---|---|
| `admin@example.com` | `AdminDev!2026` | admin |
| `manager@example.com` | `ManagerDev!2026` | manager |
| `employee@example.com` | `EmployeeDev!2026` | employee |

To rotate a password, generate a new bcrypt hash:

```bash
php -r "echo password_hash('YourNewPassword', PASSWORD_BCRYPT, ['cost' => 12]);"
```

Then `UPDATE users SET password_hash = '<hash>' WHERE email = '...';`.

## 6. Update `config.php`

Open `config.php` in the project root (never in git). Fill in:

```php
'database' => [
    'host'     => 'localhost',           // cPanel usually uses localhost
    'port'     => 3306,
    'name'     => 'youracct_finance_dashboard',
    'username' => 'youracct_dbuser',
    'password' => 'the-generated-password',
    'charset'  => 'utf8mb4',
],
```

`config.php` is git-ignored — it never leaves your server.

## 7. Test the connection

From a shell (cPanel Terminal or SSH):

```bash
php database/test-connection.php
```

You should see:

```
Finance Dashboard — database self-test
[ OK ]  config loads
[ OK ]  PDO connects
[ OK ]  server version — 8.0.x
[ OK ]  charset — utf8mb4
[ OK ]  all required tables present — 11 tables
[ OK ]  safe error surface — PDO throws on error
Result: PASS
```

If your cPanel doesn't offer a Terminal, run it from **Cron Jobs → Add New Cron Job** as a one-off with command:

```
/usr/local/bin/php /home/youracct/public_html/database/test-connection.php > /home/youracct/db-test.log 2>&1
```

Then read `db-test.log` from File Manager.

## 8. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `SQLSTATE[HY000] [1045] Access denied` | Wrong username or password in `config.php` | Copy the credentials again from cPanel MySQL → Databases |
| `SQLSTATE[HY000] [1049] Unknown database` | Database name typo — cPanel prefixes with your account | Use the full `youracct_finance_dashboard` name |
| `SQLSTATE[HY000] [2002] Connection refused` | Wrong host, or MySQL not local | Try `127.0.0.1` instead of `localhost`, or ask host support |
| `Charset — latin1` in the test | Server default is not utf8mb4 | Verify the DB was created with `utf8mb4` collation in phpMyAdmin → Operations |
| Missing tables in the test | schema.sql not imported | Redo Step 4; check phpMyAdmin for import errors |
| Foreign key errors on import | An existing DB has old tables | Drop the DB and recreate, or drop only the affected tables |

## 9. Keep credentials safe

- `config.php` is git-ignored — never `git add` it.
- The root `.htaccess` denies web access to `config.php`, `.env*`, `.sql`, and the `database/` folder.
- Never paste credentials into JavaScript, HTML, or GitHub issues.
- Rotate the seed passwords before any public deployment.
