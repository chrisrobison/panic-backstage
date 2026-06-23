-- Migration 012 (tenant): event vendors, insurance/COI tracking.
--
-- First-class vendor tracking per event:
--   event_vendors — vendor record with quote/actual, COI status, confirmation

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

CREATE TABLE IF NOT EXISTS `event_vendors` (
  `id`                  INT(11)       NOT NULL AUTO_INCREMENT,
  `event_id`            INT(11)       NOT NULL,
  `company_name`        VARCHAR(255)  DEFAULT NULL,
  `contact_name`        VARCHAR(255)  DEFAULT NULL,
  `contact_email`       VARCHAR(255)  DEFAULT NULL,
  `contact_phone`       VARCHAR(60)   DEFAULT NULL,
  `service_category`    ENUM('sound','lighting','av','catering','security','cleaning','photography',
                             'videography','florist','rental','transportation','staffing_agency',
                             'entertainment','production','venue_support','other')
                          NOT NULL DEFAULT 'other',
  `description`         TEXT          DEFAULT NULL,
  `quote_amount`        DECIMAL(10,2) DEFAULT NULL,
  `approved_amount`     DECIMAL(10,2) DEFAULT NULL,
  `actual_amount`       DECIMAL(10,2) DEFAULT NULL,
  `payment_status`      ENUM('not_required','unpaid','partial','paid','voided')
                          NOT NULL DEFAULT 'unpaid',
  `coi_required`        TINYINT(1)    NOT NULL DEFAULT 0,
  `coi_status`          ENUM('not_required','requested','received','expired','waived')
                          NOT NULL DEFAULT 'not_required',
  `coi_expiry_date`     DATE          DEFAULT NULL,
  `confirmation_status` ENUM('unconfirmed','confirmed','canceled')
                          NOT NULL DEFAULT 'unconfirmed',
  `confirmed_at`        DATETIME      DEFAULT NULL,
  `load_in_time`        TIME          DEFAULT NULL,
  `load_out_time`       TIME          DEFAULT NULL,
  `notes`               TEXT          DEFAULT NULL,
  `owner_user_id`       INT(11)       DEFAULT NULL,
  `created_by_id`       INT(11)       DEFAULT NULL,
  `created_at`          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_vendors_event`    (`event_id`),
  KEY `idx_vendors_category` (`service_category`),
  KEY `owner_user_id`        (`owner_user_id`),
  CONSTRAINT `event_vendors_ibfk_event`  FOREIGN KEY (`event_id`)      REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_vendors_ibfk_owner`  FOREIGN KEY (`owner_user_id`) REFERENCES `users`  (`id`) ON DELETE SET NULL,
  CONSTRAINT `event_vendors_ibfk_creator` FOREIGN KEY (`created_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;
