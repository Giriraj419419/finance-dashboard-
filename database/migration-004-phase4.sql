-- =========================================================================
-- Finance Dashboard — migration 004 (Phase 4)
--
-- Run this ONCE on an existing database that was already created from an
-- earlier schema.sql. `schema.sql` also contains these two tables (with
-- IF NOT EXISTS), so a fresh install picks them up automatically and this
-- file becomes a no-op — you can still run it safely on any environment.
-- =========================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------------
-- goal_contributions
-- Every top-up toward a goal is one row here. Amount is DECIMAL(12,2).
-- The goals.current_amount is kept in sync by application code inside a
-- transaction (see goal-contribute.php).
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
-- login_attempts
-- Every login attempt (success or failure) is one row. The application
-- throttles by counting rows for a given email/ip inside a config window.
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
