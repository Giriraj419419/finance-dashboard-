-- =========================================================================
-- Finance Dashboard — migration 005 (Phase 5)
--
-- Adds columns for the Purchase Orders + Reminders modules built in Phase 5.
--
-- SAFE TO RE-RUN: every change is guarded by INFORMATION_SCHEMA lookups
-- inside a stored procedure that self-cleans afterwards. Running the file
-- twice is a no-op.
--
-- Order of operations (all reversible except the enum widening):
--   * purchase_orders: widen status ENUM, add supplier_email / supplier_phone
--     / subtotal / tax_amount
--   * purchase_order_items: add description, tax_rate
--   * reminders: add priority, related_module, related_id
--
-- The purchase_orders.status widening is a superset — no existing values
-- are removed, so historical rows remain valid.
-- =========================================================================

SET NAMES utf8mb4;

DELIMITER $$

DROP PROCEDURE IF EXISTS phase5_migrate $$
CREATE PROCEDURE phase5_migrate()
BEGIN
    DECLARE db VARCHAR(64) DEFAULT DATABASE();

    -- ---- purchase_orders: widen status enum ------------------------------
    -- Includes both the Phase 2 values and the Phase 5 additions.
    ALTER TABLE purchase_orders
        MODIFY status ENUM(
            'draft','submitted','open','approved','ordered','received','closed','cancelled'
        ) NOT NULL DEFAULT 'draft';

    -- ---- purchase_orders: supplier_email ---------------------------------
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = db AND TABLE_NAME = 'purchase_orders' AND COLUMN_NAME = 'supplier_email'
    ) THEN
        ALTER TABLE purchase_orders ADD COLUMN supplier_email VARCHAR(190) NULL AFTER supplier_name;
    END IF;

    -- ---- purchase_orders: supplier_phone ---------------------------------
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = db AND TABLE_NAME = 'purchase_orders' AND COLUMN_NAME = 'supplier_phone'
    ) THEN
        ALTER TABLE purchase_orders ADD COLUMN supplier_phone VARCHAR(40) NULL AFTER supplier_email;
    END IF;

    -- ---- purchase_orders: subtotal ---------------------------------------
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = db AND TABLE_NAME = 'purchase_orders' AND COLUMN_NAME = 'subtotal'
    ) THEN
        ALTER TABLE purchase_orders
            ADD COLUMN subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER expected_date;
    END IF;

    -- ---- purchase_orders: tax_amount -------------------------------------
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = db AND TABLE_NAME = 'purchase_orders' AND COLUMN_NAME = 'tax_amount'
    ) THEN
        ALTER TABLE purchase_orders
            ADD COLUMN tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER subtotal;
    END IF;

    -- ---- purchase_order_items: description --------------------------------
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = db AND TABLE_NAME = 'purchase_order_items' AND COLUMN_NAME = 'description'
    ) THEN
        ALTER TABLE purchase_order_items ADD COLUMN description VARCHAR(255) NULL AFTER item_name;
    END IF;

    -- ---- purchase_order_items: tax_rate ----------------------------------
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = db AND TABLE_NAME = 'purchase_order_items' AND COLUMN_NAME = 'tax_rate'
    ) THEN
        ALTER TABLE purchase_order_items
            ADD COLUMN tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER unit_price;
    END IF;

    -- ---- reminders: priority ---------------------------------------------
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = db AND TABLE_NAME = 'reminders' AND COLUMN_NAME = 'priority'
    ) THEN
        ALTER TABLE reminders
            ADD COLUMN priority ENUM('low','medium','high') NOT NULL DEFAULT 'medium' AFTER description;
    END IF;

    -- ---- reminders: related_module ---------------------------------------
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = db AND TABLE_NAME = 'reminders' AND COLUMN_NAME = 'related_module'
    ) THEN
        ALTER TABLE reminders ADD COLUMN related_module VARCHAR(60) NULL;
    END IF;

    -- ---- reminders: related_id -------------------------------------------
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = db AND TABLE_NAME = 'reminders' AND COLUMN_NAME = 'related_id'
    ) THEN
        ALTER TABLE reminders ADD COLUMN related_id BIGINT UNSIGNED NULL;
    END IF;
END $$

DELIMITER ;

CALL phase5_migrate();
DROP PROCEDURE phase5_migrate;
