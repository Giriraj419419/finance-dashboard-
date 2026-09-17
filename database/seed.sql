-- =========================================================================
-- Finance Dashboard — seed.sql (Phase 2)
--
-- DEVELOPMENT-ONLY sample data. Every INSERT is idempotent (INSERT IGNORE
-- on a UNIQUE key), so running this file more than once is safe and will
-- NOT create duplicate rows.
--
-- Seed passwords are DEVELOPMENT-ONLY. Change them before ANY production
-- deployment. Real bcrypt hashes (cost 12) are embedded — plain-text
-- passwords are documented here for local testing only:
--
--     admin@example.com       password: AdminDev!2026
--     manager@example.com     password: ManagerDev!2026
--     employee@example.com    password: EmployeeDev!2026
--
-- Rotate all three by hashing a new password via auth.php's hash_password()
-- or running:  php -r "echo password_hash('NEW', PASSWORD_BCRYPT, ['cost' => 12]);"
-- =========================================================================

SET NAMES utf8mb4;

-- ---- Seed users -------------------------------------------------------
INSERT IGNORE INTO users (name, email, password_hash, role, status) VALUES
    ('Demo Admin',    'admin@example.com',    '$2y$12$0fu3.THP285O/sZRgyHLde5lQOxq/sIq33Wa03c7rQtaoEQCngXxW', 'admin',    'active'),
    ('Demo Manager',  'manager@example.com',  '$2y$12$RGA4P73r69YOTFCiIdlfpOAbOg8pxWemWm3SxKT14.G1RuRIjnLD6', 'manager',  'active'),
    ('Demo Employee', 'employee@example.com', '$2y$12$HScTSarP8/9uQso7Sw74Z.ISX9.8N3gG9ucBhrWUhintJeV26zsKC', 'employee', 'active');

-- Pin @admin_id to the admin so every downstream seed row is deterministic.
SET @admin_id := (SELECT id FROM users WHERE email = 'admin@example.com' LIMIT 1);

-- ---- Sample transactions ---------------------------------------------
-- Idempotency: uniqueness enforced by the (user_id, transaction_date, description)
-- triple via INSERT ... SELECT ... WHERE NOT EXISTS. Repeated runs are no-ops.
INSERT INTO transactions (user_id, type, category, amount, description, transaction_date, payment_method, status)
SELECT * FROM (SELECT
    @admin_id AS user_id, 'income' AS type, 'Salary' AS category, 4500.00 AS amount,
    'Salary — September' AS description, DATE '2026-09-15' AS transaction_date,
    'bank_transfer' AS payment_method, 'completed' AS status) s
WHERE @admin_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM transactions t WHERE t.user_id = s.user_id AND t.transaction_date = s.transaction_date AND t.description = s.description);

INSERT INTO transactions (user_id, type, category, amount, description, transaction_date, payment_method, status)
SELECT * FROM (SELECT
    @admin_id, 'expense', 'Rent', 1800.00, 'Office rent', DATE '2026-09-14', 'ach', 'completed') s
WHERE @admin_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM transactions t WHERE t.user_id = s.user_id AND t.transaction_date = s.transaction_date AND t.description = s.description);

INSERT INTO transactions (user_id, type, category, amount, description, transaction_date, payment_method, status)
SELECT * FROM (SELECT
    @admin_id, 'expense', 'Software', 140.50, 'Cloud hosting', DATE '2026-09-13', 'card', 'pending') s
WHERE @admin_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM transactions t WHERE t.user_id = s.user_id AND t.transaction_date = s.transaction_date AND t.description = s.description);

INSERT INTO transactions (user_id, type, category, amount, description, transaction_date, payment_method, status)
SELECT * FROM (SELECT
    @admin_id, 'income', 'Revenue', 1250.00, 'Consulting invoice #4021', DATE '2026-09-12', 'wire', 'completed') s
WHERE @admin_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM transactions t WHERE t.user_id = s.user_id AND t.transaction_date = s.transaction_date AND t.description = s.description);

INSERT INTO transactions (user_id, type, category, amount, description, transaction_date, payment_method, status)
SELECT * FROM (SELECT
    @admin_id, 'expense', 'Meals', 186.20, 'Team lunch', DATE '2026-09-10', 'card', 'failed') s
WHERE @admin_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM transactions t WHERE t.user_id = s.user_id AND t.transaction_date = s.transaction_date AND t.description = s.description);

-- ---- Sample budgets ---------------------------------------------------
-- Idempotent on (user_id, name).
INSERT INTO budgets (user_id, name, category, budget_amount, spent_amount, start_date, end_date, status)
SELECT * FROM (SELECT
    @admin_id, 'Marketing FY26', 'Marketing', 4000.00, 2400.00, DATE '2026-09-01', DATE '2026-09-30', 'active') s
WHERE @admin_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM budgets b WHERE b.user_id = s.user_id AND b.name = s.name);

INSERT INTO budgets (user_id, name, category, budget_amount, spent_amount, start_date, end_date, status)
SELECT * FROM (SELECT
    @admin_id, 'Software FY26', 'Software', 1500.00, 1150.00, DATE '2026-09-01', DATE '2026-09-30', 'active') s
WHERE @admin_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM budgets b WHERE b.user_id = s.user_id AND b.name = s.name);

INSERT INTO budgets (user_id, name, category, budget_amount, spent_amount, start_date, end_date, status)
SELECT * FROM (SELECT
    @admin_id, 'Travel FY26', 'Travel', 800.00, 780.00, DATE '2026-09-01', DATE '2026-09-30', 'active') s
WHERE @admin_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM budgets b WHERE b.user_id = s.user_id AND b.name = s.name);

-- ---- Sample goals ----------------------------------------------------
INSERT INTO goals (user_id, name, target_amount, current_amount, target_date, status)
SELECT * FROM (SELECT
    @admin_id, 'Emergency fund', 15000.00, 8200.00, DATE '2026-12-31', 'active') s
WHERE @admin_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM goals g WHERE g.user_id = s.user_id AND g.name = s.name);

INSERT INTO goals (user_id, name, target_amount, current_amount, target_date, status)
SELECT * FROM (SELECT
    @admin_id, 'New workstation', 3000.00, 1450.00, DATE '2026-11-30', 'active') s
WHERE @admin_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM goals g WHERE g.user_id = s.user_id AND g.name = s.name);

-- ---- Sample payments -------------------------------------------------
INSERT INTO payments (user_id, title, amount, due_date, payment_date, payment_method, status, notes)
SELECT * FROM (SELECT
    @admin_id, 'Adobe Creative Cloud', 59.99, DATE '2026-09-22', NULL, 'card', 'scheduled', 'Monthly subscription') s
WHERE @admin_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.user_id = s.user_id AND p.title = s.title AND p.due_date = s.due_date);

INSERT INTO payments (user_id, title, amount, due_date, payment_date, payment_method, status, notes)
SELECT * FROM (SELECT
    @admin_id, 'Insurance premium', 210.00, DATE '2026-09-30', NULL, 'ach', 'scheduled', NULL) s
WHERE @admin_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.user_id = s.user_id AND p.title = s.title AND p.due_date = s.due_date);

-- ---- Sample purchase order + items -----------------------------------
INSERT IGNORE INTO purchase_orders
    (user_id, supplier_name, order_number, order_date, expected_date, total_amount, status, notes)
SELECT @admin_id, 'Acme Office Supplies', 'PO-2026-0001',
       DATE '2026-09-05', DATE '2026-09-19', 512.00, 'approved', 'Q3 office restock'
WHERE @admin_id IS NOT NULL;

-- Only add items when the PO was just created (no items yet).
INSERT INTO purchase_order_items (purchase_order_id, item_name, quantity, unit_price, total_price)
SELECT po.id, 'Standing desk mat', 4, 68.00, 272.00
FROM purchase_orders po
WHERE po.order_number = 'PO-2026-0001'
  AND NOT EXISTS (SELECT 1 FROM purchase_order_items i WHERE i.purchase_order_id = po.id AND i.item_name = 'Standing desk mat');

INSERT INTO purchase_order_items (purchase_order_id, item_name, quantity, unit_price, total_price)
SELECT po.id, 'Wireless keyboard', 6, 40.00, 240.00
FROM purchase_orders po
WHERE po.order_number = 'PO-2026-0001'
  AND NOT EXISTS (SELECT 1 FROM purchase_order_items i WHERE i.purchase_order_id = po.id AND i.item_name = 'Wireless keyboard');

-- ---- Sample reminders ------------------------------------------------
INSERT INTO reminders (user_id, title, description, reminder_date, recurrence_type, recurrence_end_date, status)
SELECT * FROM (SELECT
    @admin_id, 'File quarterly VAT return', 'Q3 filing due to tax authority.',
    TIMESTAMP '2026-09-18 09:00:00', 'quarterly', NULL, 'pending') s
WHERE @admin_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM reminders r WHERE r.user_id = s.user_id AND r.title = s.title AND r.reminder_date = s.reminder_date);

INSERT INTO reminders (user_id, title, description, reminder_date, recurrence_type, recurrence_end_date, status)
SELECT * FROM (SELECT
    @admin_id, 'Review payroll for October', NULL,
    TIMESTAMP '2026-09-20 09:00:00', 'monthly', NULL, 'pending') s
WHERE @admin_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM reminders r WHERE r.user_id = s.user_id AND r.title = s.title AND r.reminder_date = s.reminder_date);
