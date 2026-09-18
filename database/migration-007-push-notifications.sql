-- =========================================================================
-- Finance Dashboard — migration 007 (Web Push notifications)
--
-- Adds:
--   * push_subscriptions — one row per browser subscription per user.
--     endpoint is UNIQUE (Web Push endpoints are naturally unique).
--   * user_notification_preferences — per-user opt-in flags for email vs push.
--   * reminder_notifications.channel  — email | push, tracked per-attempt.
--
-- Fully idempotent. Safe to re-run.
-- =========================================================================

SET NAMES utf8mb4;

DELIMITER $$

DROP PROCEDURE IF EXISTS phase7_migrate $$
CREATE PROCEDURE phase7_migrate()
BEGIN
    DECLARE db VARCHAR(64) DEFAULT DATABASE();

    -- reminder_notifications.channel
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = db AND TABLE_NAME = 'reminder_notifications' AND COLUMN_NAME = 'channel'
    ) THEN
        ALTER TABLE reminder_notifications
            ADD COLUMN channel ENUM('email','push') NOT NULL DEFAULT 'email' AFTER notification_type;
    END IF;
END $$

DELIMITER ;

CALL phase7_migrate();
DROP PROCEDURE phase7_migrate;

-- ------------------------------------------------------------------------
-- push_subscriptions
-- ------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id             BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED      NOT NULL,
    endpoint       VARCHAR(500)      NOT NULL,
    p256dh_key     VARCHAR(255)      NOT NULL,
    auth_key       VARCHAR(64)       NOT NULL,
    user_agent     VARCHAR(255)      NULL,
    is_active      TINYINT(1)        NOT NULL DEFAULT 1,
    last_used_at   DATETIME          NULL,
    last_error     VARCHAR(255)      NULL,
    created_at     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ps_endpoint (endpoint),
    KEY idx_ps_user_active (user_id, is_active),
    CONSTRAINT fk_ps_user FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------
-- user_notification_preferences
-- One row per user; upserted lazily by the settings page.
-- ------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_notification_preferences (
    user_id           INT UNSIGNED   NOT NULL,
    email_enabled     TINYINT(1)     NOT NULL DEFAULT 1,
    push_enabled      TINYINT(1)     NOT NULL DEFAULT 1,
    updated_at        DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_np_user FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
