-- Migration 039: lead_intake_emails — inbound booking-email audit + dedup store.
--
-- Booking requests forwarded to bookings@themab.org are piped (via an Exim
-- filter on the receiving mailbox) into scripts/ingest-booking-email.php, which
-- parses them and creates a `leads` row. This table records every email the
-- importer processed so that:
--   * re-delivery of the same message is detected (UNIQUE message_id) and not
--     imported twice,
--   * the original raw message is retained for re-parsing / debugging,
--   * the parsed field set and parse method are captured for auditing.
--
-- One row per inbound email. lead_id is the lead it produced (NULL if the email
-- was skipped or errored before a lead could be created).

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

CREATE TABLE IF NOT EXISTS `lead_intake_emails` (
  `id`             INT(11)      NOT NULL AUTO_INCREMENT,
  `lead_id`        INT(11)      DEFAULT NULL,
  `channel`        VARCHAR(40)  NOT NULL DEFAULT 'email',
  `message_id`     VARCHAR(255) DEFAULT NULL,
  `from_name`      VARCHAR(255) DEFAULT NULL,
  `from_email`     VARCHAR(255) DEFAULT NULL,
  `reply_to`       VARCHAR(255) DEFAULT NULL,
  `to_recipients`  VARCHAR(1000) DEFAULT NULL,
  `subject`        VARCHAR(1000) DEFAULT NULL,
  `parse_method`   ENUM('jotform','llm','jotform+llm','heuristic','none')
                     NOT NULL DEFAULT 'none',
  `status`         ENUM('imported','duplicate','error','skipped')
                     NOT NULL DEFAULT 'imported',
  `error_message`  TEXT         DEFAULT NULL,
  `parsed_json`    LONGTEXT     DEFAULT NULL,
  `raw_email`      MEDIUMTEXT   DEFAULT NULL,
  `received_at`    DATETIME     DEFAULT NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_message_id` (`message_id`),
  KEY `idx_intake_lead`   (`lead_id`),
  KEY `idx_intake_status` (`status`),
  CONSTRAINT `lead_intake_ibfk_lead`
    FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;
