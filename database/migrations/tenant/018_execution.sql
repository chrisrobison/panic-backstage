-- Migration 018 (tenant): live event execution records.
--
-- Structured day-of records for the run of show:
--   event_execution_records — incidents, change orders, bar notes, etc.
--
-- Incident records have restricted visibility (view_incidents capability required).
-- Change orders / overages are linked to the financial closeout.

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

CREATE TABLE IF NOT EXISTS `event_execution_records` (
  `id`              INT(11)       NOT NULL AUTO_INCREMENT,
  `event_id`        INT(11)       NOT NULL,
  `record_type`     ENUM('incident','change_order','bar_note','damage','overage',
                         'checklist','deviation','safety_note','general')
                      NOT NULL DEFAULT 'general',
  `title`           VARCHAR(255)  NOT NULL,
  `body`            TEXT          DEFAULT NULL,
  `occurred_at`     DATETIME      DEFAULT NULL,
  -- Financial impact (for change_order and overage types)
  `amount`          DECIMAL(10,2) DEFAULT NULL,
  `client_approved` TINYINT(1)    NOT NULL DEFAULT 0,
  `approved_by`     VARCHAR(255)  DEFAULT NULL,
  `linked_ledger_entry_id` INT(11) DEFAULT NULL,
  -- Visibility
  `is_restricted`   TINYINT(1)    NOT NULL DEFAULT 0,
  -- Attachments (array of asset IDs or URLs stored as JSON)
  `attachments_json` LONGTEXT     CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
                      CHECK (json_valid(`attachments_json`)),
  `author_id`       INT(11)       DEFAULT NULL,
  `author_role`     VARCHAR(80)   DEFAULT NULL,
  `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_exec_event`  (`event_id`),
  KEY `idx_exec_type`   (`record_type`),
  KEY `author_id`       (`author_id`),
  CONSTRAINT `exec_ibfk_event`  FOREIGN KEY (`event_id`)  REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exec_ibfk_author` FOREIGN KEY (`author_id`) REFERENCES `users`  (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;
