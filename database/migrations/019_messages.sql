-- Migration 019: messages — in-app messaging for staff.
--
-- Backs the Messages app (Inbox / Archive / Outbox). A single row serves two
-- views: it appears in the recipient's Inbox (recipient_user_id) and in the
-- sender's Outbox (sender_user_id). sender_user_id is NULL for system-generated
-- messages that were fanned out from an outgoing email (see Mailer).
CREATE TABLE IF NOT EXISTS `messages` (
  `id`                BIGINT(20)   NOT NULL AUTO_INCREMENT,
  `sender_user_id`    INT(11)      DEFAULT NULL,           -- NULL = system-generated
  `recipient_user_id` INT(11)      NOT NULL,
  `recipient_email`   VARCHAR(320) NOT NULL DEFAULT '',
  `subject`           VARCHAR(998) NOT NULL DEFAULT '',
  `body_text`         MEDIUMTEXT   DEFAULT NULL,
  `body_html`         MEDIUMTEXT   DEFAULT NULL,
  `template`          VARCHAR(120) DEFAULT NULL,           -- source template / category
  `in_reply_to_id`    BIGINT(20)   DEFAULT NULL,
  `outbox_id`         BIGINT(20)   DEFAULT NULL,           -- linked outbox row when fanned out from email
  `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `read_at`           DATETIME     DEFAULT NULL,
  `archived_at`       DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_inbox`  (`recipient_user_id`, `archived_at`, `created_at`),
  KEY `idx_sent`   (`sender_user_id`, `created_at`),
  KEY `idx_unread` (`recipient_user_id`, `read_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
