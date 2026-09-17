-- =========================================================================
-- Finance Dashboard — schema.sql (Phase 2)
--
-- MySQL 8+, InnoDB, utf8mb4_unicode_ci.
-- Money columns use DECIMAL(12,2) — never floating point.
--
-- Foreign-key policy (documented, per Phase 2 §5):
--   * user-owned records (transactions, budgets, goals, payments,
--     purchase_orders, reminders, reports, password_reset_tokens)
--     → ON DELETE RESTRICT so a user with financial history cannot be
--       deleted by accident. Deactivate the user instead
--       (users.status = 'inactive' or 'suspended'), or soft-delete first.
--   * purchase_order_items → purchase_orders: ON DELETE CASCADE
--     (child rows follow the parent order).
--   * audit_logs → users: ON DELETE SET NULL, preserving the audit trail
--     when a user record is genuinely removed.
--
-- All tables use IF NOT EXISTS so re-running is safe on an existing DB.
-- =========================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------------
-- users
-- ------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id             INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    name           VARCHAR(160)   NOT NULL,
    email          VARCHAR(190)   NOT NULL,
    password_hash  VARCHAR(255)   NOT NULL,
    role           ENUM('admin','manager','employee') NOT NULL DEFAULT 'employee',
    status         ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
    last_login_at  DATETIME       NULL,
    created_at     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role_status (role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------
-- transactions
-- ------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS transactions (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id           INT UNSIGNED    NOT NULL,
    type              ENUM('income','expense') NOT NULL,
    category          VARCHAR(120)    NOT NULL,
    amount            DECIMAL(12,2)   NOT NULL,
    description       VARCHAR(255)    NULL,
    transaction_date  DATE            NOT NULL,
    payment_method    ENUM('cash','card','bank_transfer','ach','wire','upi','other')
                                      NOT NULL DEFAULT 'bank_transfer',
    status            ENUM('pending','completed','failed','cancelled')
                                      NOT NULL DEFAULT 'completed',
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                      ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_txn_user_date (user_id, transaction_date),
    KEY idx_txn_category  (category),
    KEY idx_txn_type      (type),
    CONSTRAINT fk_txn_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------
-- budgets
-- ------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS budgets (
    id             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED    NOT NULL,
    name           VARCHAR(160)    NOT NULL,
    category       VARCHAR(120)    NOT NULL,
    budget_amount  DECIMAL(12,2)   NOT NULL,
    spent_amount   DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    start_date     DATE            NOT NULL,
    end_date       DATE            NULL,
    status         ENUM('active','paused','completed','archived')
                                   NOT NULL DEFAULT 'active',
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_budgets_user_status (user_id, status),
    KEY idx_budgets_category    (category),
    CONSTRAINT fk_budgets_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------
-- goals
-- ------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS goals (
    id              INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED   NOT NULL,
    name            VARCHAR(160)   NOT NULL,
    target_amount   DECIMAL(12,2)  NOT NULL,
    current_amount  DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
    target_date     DATE           NULL,
    status          ENUM('active','paused','achieved','archived')
                                   NOT NULL DEFAULT 'active',
    created_at      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_goals_user_status (user_id, status),
    CONSTRAINT fk_goals_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------
-- payments
-- ------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
    id              INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED   NOT NULL,
    title           VARCHAR(200)   NOT NULL,
    amount          DECIMAL(12,2)  NOT NULL,
    due_date        DATE           NOT NULL,
    payment_date    DATE           NULL,
    payment_method  ENUM('cash','card','bank_transfer','ach','wire','upi','other')
                                   NOT NULL DEFAULT 'bank_transfer',
    status          ENUM('scheduled','paid','overdue','cancelled')
                                   NOT NULL DEFAULT 'scheduled',
    notes           TEXT           NULL,
    created_at      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_payments_user_due   (user_id, due_date),
    KEY idx_payments_status     (status),
    CONSTRAINT fk_payments_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------
-- purchase_orders
-- ------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS purchase_orders (
    id             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED    NOT NULL,
    supplier_name  VARCHAR(200)    NOT NULL,
    order_number   VARCHAR(60)     NOT NULL,
    order_date     DATE            NOT NULL,
    expected_date  DATE            NULL,
    total_amount   DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    status         ENUM('draft','open','approved','received','closed','cancelled')
                                   NOT NULL DEFAULT 'draft',
    notes          TEXT            NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_po_order_number (order_number),
    KEY idx_po_user_status  (user_id, status),
    KEY idx_po_supplier     (supplier_name),
    CONSTRAINT fk_po_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------
-- purchase_order_items
-- ------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS purchase_order_items (
    id                  INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    purchase_order_id   INT UNSIGNED   NOT NULL,
    item_name           VARCHAR(200)   NOT NULL,
    quantity            DECIMAL(12,2)  NOT NULL DEFAULT 1.00,
    unit_price          DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
    total_price         DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
    created_at          DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                       ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_poi_po (purchase_order_id),
    CONSTRAINT fk_poi_po
        FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------
-- reminders
-- ------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reminders (
    id                    INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    user_id               INT UNSIGNED   NOT NULL,
    title                 VARCHAR(200)   NOT NULL,
    description           TEXT           NULL,
    reminder_date         DATETIME       NOT NULL,
    recurrence_type       ENUM('none','daily','weekly','monthly','yearly')
                                         NOT NULL DEFAULT 'none',
    recurrence_end_date   DATE           NULL,
    status                ENUM('pending','completed','snoozed','cancelled')
                                         NOT NULL DEFAULT 'pending',
    created_at            DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_reminders_user_date (user_id, reminder_date),
    KEY idx_reminders_status    (status),
    CONSTRAINT fk_reminders_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------
-- reports
-- ------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reports (
    id             INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED   NOT NULL,
    report_type    ENUM('profit_loss','cash_flow','category_breakdown','custom')
                                  NOT NULL DEFAULT 'custom',
    title          VARCHAR(200)   NOT NULL,
    filters_json   JSON           NULL,
    generated_at   DATETIME       NULL,
    created_at     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_reports_user_type (user_id, report_type),
    CONSTRAINT fk_reports_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------
-- password_reset_tokens
-- ------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id          INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED   NOT NULL,
    token_hash  VARCHAR(255)   NOT NULL,
    expires_at  DATETIME       NOT NULL,
    used_at     DATETIME       NULL,
    created_at  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_prt_token_hash (token_hash),
    KEY idx_prt_user (user_id),
    KEY idx_prt_expires (expires_at),
    CONSTRAINT fk_prt_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------
-- audit_logs
-- ------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
    id           BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED      NULL,
    action       VARCHAR(80)       NOT NULL,
    entity_type  VARCHAR(80)       NOT NULL,
    entity_id    BIGINT UNSIGNED   NULL,
    details      JSON              NULL,
    ip_address   VARCHAR(45)       NULL,
    created_at   DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_user           (user_id),
    KEY idx_audit_entity         (entity_type, entity_id),
    KEY idx_audit_action_created (action, created_at),
    CONSTRAINT fk_audit_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------
-- goal_contributions (added in Phase 4)
-- ------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS goal_contributions (
    id                 INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    goal_id            INT UNSIGNED   NOT NULL,
    user_id            INT UNSIGNED   NOT NULL,
    amount             DECIMAL(12,2)  NOT NULL,
    contribution_date  DATE           NOT NULL,
    note               VARCHAR(255)   NULL,
    created_at         DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_gc_goal_date (goal_id, contribution_date),
    KEY idx_gc_user      (user_id),
    CONSTRAINT fk_gc_goal FOREIGN KEY (goal_id) REFERENCES goals (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_gc_user FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------
-- login_attempts (added in Phase 4)
-- ------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id              BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    email           VARCHAR(190)      NULL,
    ip_address      VARCHAR(45)       NULL,
    attempted_at    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    was_successful  TINYINT(1)        NOT NULL DEFAULT 0,
    created_at      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_la_email_time (email, attempted_at),
    KEY idx_la_ip_time    (ip_address, attempted_at),
    KEY idx_la_success    (was_successful)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
