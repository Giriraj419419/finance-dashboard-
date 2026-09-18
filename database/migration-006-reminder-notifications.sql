-- =========================================================================
-- Finance Dashboard — migration 006 (Reminder notifications)
--
-- Adds:
--   * reminders.notification_offset_minutes — how far BEFORE the due
--     time the notification should fire. 0 = at due time.
--   * reminder_notifications — one row per notification occurrence.
--     UNIQUE (reminder_id, scheduled_for) makes the worker's INSERT the
--     atomic "claim" step, so two concurrent cron runs cannot double-send.
--   * system_health — small key/value table so the dashboard can show
--     "Reminder Worker: Healthy / Needs Attention" and last-run timestamps.
--
-- Fully idempotent — safe to re-run.
-- =========================================================================

SET NAMES utf8mb4;

DELIMITER $$

DROP PROCEDURE IF EXISTS phase6_migrate $$
CREATE PROCEDURE phase6_migrate()
BEGIN
    DECLARE db VARCHAR(64) DEFAULT DATABASE();

    -- reminders.notification_offset_minutes
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = db AND TABLE_NAME = 'reminders' AND COLUMN_NAME = 'notification_offset_minutes'
    ) THEN
        ALTER TABLE reminders
            ADD COLUMN notification_offset_minutes INT UNSIGNED NOT NULL DEFAULT 0 AFTER priority;
    END IF;
END $$

DELIMITER ;

CALL phase6_migrate();
DROP PROCEDURE phase6_migrate;

-- ------------------------------------------------------------------------
-- reminder_notifications
-- ------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reminder_notifications (
    id                 BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    reminder_id        INT UNSIGNED      NOT NULL,
    user_id            INT UNSIGNED      NOT NULL,
    scheduled_for      DATETIME          NOT NULL,
    notification_type  ENUM('email')     NOT NULL DEFAULT 'email',
    status             ENUM('pending','processing','sent','failed')
                                         NOT NULL DEFAULT 'pending',
    attempts           TINYINT UNSIGNED  NOT NULL DEFAULT 0,
    last_error         VARCHAR(500)      NULL,
    sent_at            DATETIME          NULL,
    created_at         DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- The claim key. Concurrent cron runs both try to INSERT; the second
    -- one hits this UNIQUE and knows the first already claimed it.
    UNIQUE KEY uq_rn_reminder_occurrence (reminder_id, scheduled_for, notification_type),
    KEY idx_rn_status_scheduled (status, scheduled_for),
    KEY idx_rn_user (user_id),
    CONSTRAINT fk_rn_reminder FOREIGN KEY (reminder_id) REFERENCES reminders (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_rn_user     FOREIGN KEY (user_id)     REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------
-- system_health — tiny KV store for cron heartbeat.
-- Not authoritative, just observability.
-- ------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS system_health (
    metric_key    VARCHAR(80)  NOT NULL,
    metric_value  VARCHAR(200) NULL,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                               ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (metric_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
