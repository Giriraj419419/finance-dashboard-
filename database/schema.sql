-- =========================================================================
-- Finance Dashboard — schema.sql
-- Draft schema for Phase 2 (CRUD phase). Not yet loaded by the app.
-- MySQL 8+, utf8mb4.
-- =========================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---- Users ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    first_name         VARCHAR(80)  NOT NULL,
    last_name          VARCHAR(80)  NOT NULL,
    email              VARCHAR(190) NOT NULL,
    password_hash      VARCHAR(255) NOT NULL,
    role               ENUM('admin', 'manager', 'employee') NOT NULL DEFAULT 'employee',
    is_active          TINYINT(1)   NOT NULL DEFAULT 1,
    last_login_at      DATETIME     NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Password reset tokens -------------------------------------------
CREATE TABLE IF NOT EXISTS password_resets (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NOT NULL,
    token_hash   VARCHAR(255) NOT NULL,
    expires_at   DATETIME     NOT NULL,
    used_at      DATETIME     NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pr_user (user_id),
    CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Accounts / wallets ----------------------------------------------
CREATE TABLE IF NOT EXISTS accounts (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    name        VARCHAR(120) NOT NULL,
    type        ENUM('bank', 'card', 'cash', 'wallet', 'other') NOT NULL DEFAULT 'bank',
    currency    CHAR(3)      NOT NULL DEFAULT 'USD',
    opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_acc_user (user_id),
    CONSTRAINT fk_acc_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Categories -------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
    id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id  INT UNSIGNED NOT NULL,
    name     VARCHAR(120) NOT NULL,
    kind     ENUM('income', 'expense') NOT NULL DEFAULT 'expense',
    color    VARCHAR(9) NULL,
    PRIMARY KEY (id),
    KEY idx_cat_user (user_id),
    UNIQUE KEY uq_cat_user_name (user_id, name),
    CONSTRAINT fk_cat_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Transactions -----------------------------------------------------
CREATE TABLE IF NOT EXISTS transactions (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    account_id  INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NULL,
    occurred_on DATE NOT NULL,
    description VARCHAR(255) NOT NULL,
    method      ENUM('cash', 'card', 'bank_transfer', 'ach', 'wire', 'upi', 'other') NOT NULL DEFAULT 'bank_transfer',
    amount      DECIMAL(14,2) NOT NULL,
    direction   ENUM('in', 'out') NOT NULL,
    status      ENUM('pending', 'success', 'failed') NOT NULL DEFAULT 'success',
    notes       TEXT NULL,
    attachment  VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_txn_user_date (user_id, occurred_on),
    KEY idx_txn_account (account_id),
    KEY idx_txn_category (category_id),
    CONSTRAINT fk_txn_user     FOREIGN KEY (user_id)     REFERENCES users      (id) ON DELETE CASCADE,
    CONSTRAINT fk_txn_account  FOREIGN KEY (account_id)  REFERENCES accounts   (id) ON DELETE CASCADE,
    CONSTRAINT fk_txn_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Budgets ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS budgets (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NULL,
    name        VARCHAR(120) NOT NULL,
    period      ENUM('weekly', 'monthly', 'quarterly', 'yearly') NOT NULL DEFAULT 'monthly',
    amount      DECIMAL(14,2) NOT NULL,
    starts_on   DATE NOT NULL,
    ends_on     DATE NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bud_user (user_id),
    CONSTRAINT fk_bud_user     FOREIGN KEY (user_id)     REFERENCES users      (id) ON DELETE CASCADE,
    CONSTRAINT fk_bud_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Goals ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS goals (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED NOT NULL,
    name           VARCHAR(140) NOT NULL,
    target_amount  DECIMAL(14,2) NOT NULL,
    saved_amount   DECIMAL(14,2) NOT NULL DEFAULT 0,
    target_date    DATE NULL,
    status         ENUM('active', 'paused', 'achieved', 'archived') NOT NULL DEFAULT 'active',
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_goal_user (user_id),
    CONSTRAINT fk_goal_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Payments ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NOT NULL,
    account_id    INT UNSIGNED NULL,
    recipient     VARCHAR(200) NOT NULL,
    category      VARCHAR(120) NULL,
    amount        DECIMAL(14,2) NOT NULL,
    due_on        DATE NOT NULL,
    paid_on       DATE NULL,
    status        ENUM('scheduled', 'paid', 'overdue', 'cancelled') NOT NULL DEFAULT 'scheduled',
    notes         TEXT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pay_user (user_id),
    CONSTRAINT fk_pay_user    FOREIGN KEY (user_id)    REFERENCES users    (id) ON DELETE CASCADE,
    CONSTRAINT fk_pay_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Purchase orders --------------------------------------------------
CREATE TABLE IF NOT EXISTS purchase_orders (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NOT NULL,
    po_number    VARCHAR(40)  NOT NULL,
    vendor       VARCHAR(200) NOT NULL,
    issued_on    DATE NOT NULL,
    expected_on  DATE NULL,
    total        DECIMAL(14,2) NOT NULL DEFAULT 0,
    status       ENUM('draft', 'open', 'approved', 'received', 'closed', 'cancelled') NOT NULL DEFAULT 'draft',
    notes        TEXT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_po_number (po_number),
    KEY idx_po_user (user_id),
    CONSTRAINT fk_po_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_order_items (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    po_id        INT UNSIGNED NOT NULL,
    description  VARCHAR(255) NOT NULL,
    quantity     DECIMAL(12,2) NOT NULL DEFAULT 1,
    unit_price   DECIMAL(14,2) NOT NULL DEFAULT 0,
    line_total   DECIMAL(14,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_poi_po (po_id),
    CONSTRAINT fk_poi_po FOREIGN KEY (po_id) REFERENCES purchase_orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Reminders --------------------------------------------------------
CREATE TABLE IF NOT EXISTS reminders (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NOT NULL,
    title        VARCHAR(200) NOT NULL,
    description  TEXT NULL,
    due_at       DATETIME NOT NULL,
    frequency    ENUM('none', 'daily', 'weekly', 'monthly', 'quarterly', 'yearly') NOT NULL DEFAULT 'none',
    is_done      TINYINT(1) NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rem_user_due (user_id, due_at),
    CONSTRAINT fk_rem_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Audit log --------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NULL,
    action     VARCHAR(80) NOT NULL,
    entity     VARCHAR(80) NOT NULL,
    entity_id  BIGINT UNSIGNED NULL,
    details    JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_user (user_id),
    KEY idx_audit_entity (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
