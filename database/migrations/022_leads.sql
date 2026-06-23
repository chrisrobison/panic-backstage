-- Migration 010 (single-tenant): lead pipeline and deal evaluator.
--
-- Adds a full lead intake pipeline before event creation:
--   leads          — the lead record with status flow and qualification fields
--   lead_notes     — notes, tasks, and audit entries on a lead
--   lead_deal_evaluations — server-calculated deal math snapshots
--
-- Also adds events.lead_id and events.deposit_status, is_private columns
-- needed by the contract+deposit gate (migration 012) and the private-event
-- closeout workflow (migration 013).

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ── 1. leads ─────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `leads` (
  `id`                INT(11)       NOT NULL AUTO_INCREMENT,
  `status`            ENUM('new','triage','evaluating','needs_review','approved','declined','converted','canceled')
                        NOT NULL DEFAULT 'new',
  `source`            ENUM('internal','website','promoter','referral','peerspace','eventective','giggster','phone','email','manual','other')
                        NOT NULL DEFAULT 'manual',
  `contact_name`      VARCHAR(255)  DEFAULT NULL,
  `contact_email`     VARCHAR(255)  DEFAULT NULL,
  `contact_org`       VARCHAR(255)  DEFAULT NULL,
  `contact_phone`     VARCHAR(60)   DEFAULT NULL,
  `event_name`        VARCHAR(255)  DEFAULT NULL,
  `event_type`        VARCHAR(80)   DEFAULT NULL,
  `desired_date`      DATE          DEFAULT NULL,
  `desired_date_alt`  DATE          DEFAULT NULL,
  `rooms_requested`   VARCHAR(255)  DEFAULT NULL,
  `projected_attendance` INT(11)    DEFAULT NULL,
  `is_private`        TINYINT(1)    NOT NULL DEFAULT 0,
  `alcohol_plan`      VARCHAR(120)  DEFAULT NULL,
  `notes`             TEXT          DEFAULT NULL,
  `point_person_id`   INT(11)       DEFAULT NULL,
  `risk_level`        ENUM('low','medium','high','unknown') NOT NULL DEFAULT 'unknown',
  `decline_reason`    VARCHAR(255)  DEFAULT NULL,
  `decision_notes`    TEXT          DEFAULT NULL,
  `decision_by_id`    INT(11)       DEFAULT NULL,
  `decided_at`        DATETIME      DEFAULT NULL,
  `converted_event_id` INT(11)      DEFAULT NULL,
  `converted_at`      DATETIME      DEFAULT NULL,
  `created_by_id`     INT(11)       DEFAULT NULL,
  `created_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_leads_status`    (`status`),
  KEY `idx_leads_source`    (`source`),
  KEY `idx_leads_date`      (`desired_date`),
  KEY `point_person_id`     (`point_person_id`),
  KEY `decision_by_id`      (`decision_by_id`),
  KEY `created_by_id`       (`created_by_id`),
  KEY `converted_event_id`  (`converted_event_id`),
  CONSTRAINT `leads_ibfk_point`     FOREIGN KEY (`point_person_id`)    REFERENCES `users`   (`id`) ON DELETE SET NULL,
  CONSTRAINT `leads_ibfk_decision`  FOREIGN KEY (`decision_by_id`)     REFERENCES `users`   (`id`) ON DELETE SET NULL,
  CONSTRAINT `leads_ibfk_creator`   FOREIGN KEY (`created_by_id`)      REFERENCES `users`   (`id`) ON DELETE SET NULL,
  CONSTRAINT `leads_ibfk_event`     FOREIGN KEY (`converted_event_id`) REFERENCES `events`  (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. lead_notes ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `lead_notes` (
  `id`          INT(11)       NOT NULL AUTO_INCREMENT,
  `lead_id`     INT(11)       NOT NULL,
  `user_id`     INT(11)       DEFAULT NULL,
  `type`        ENUM('note','task','status_change','audit') NOT NULL DEFAULT 'note',
  `body`        TEXT          NOT NULL,
  `is_done`     TINYINT(1)    NOT NULL DEFAULT 0,
  `due_date`    DATE          DEFAULT NULL,
  `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `lead_id` (`lead_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `lead_notes_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lead_notes_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. lead_deal_evaluations ──────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `lead_deal_evaluations` (
  `id`                      INT(11)       NOT NULL AUTO_INCREMENT,
  `lead_id`                 INT(11)       NOT NULL,
  `event_id`                INT(11)       DEFAULT NULL,
  `deal_type`               ENUM('rental_buyout','guarantee','door_split','guarantee_plus_pct','bar_minimum','hybrid','private_hosted_bar','other')
                              NOT NULL DEFAULT 'other',
  -- Forecast inputs
  `room_capacity`           INT(11)       DEFAULT NULL,
  `expected_attendance`     INT(11)       DEFAULT NULL,
  `ticket_price`            DECIMAL(10,2) DEFAULT NULL,
  `ticket_fee_per`          DECIMAL(10,2) DEFAULT NULL,
  `rental_fee`              DECIMAL(10,2) DEFAULT NULL,
  `artist_guarantee`        DECIMAL(10,2) DEFAULT NULL,
  `projected_bar_spend`     DECIMAL(10,2) DEFAULT NULL,
  `bar_minimum`             DECIMAL(10,2) DEFAULT NULL,
  `labor_forecast`          DECIMAL(10,2) DEFAULT NULL,
  `production_costs`        DECIMAL(10,2) DEFAULT NULL,
  `facility_costs`          DECIMAL(10,2) DEFAULT NULL,
  `other_costs`             DECIMAL(10,2) DEFAULT NULL,
  -- Server-calculated outputs (stored at snapshot time)
  `calc_gross_revenue`      DECIMAL(10,2) DEFAULT NULL,
  `calc_estimated_cost`     DECIMAL(10,2) DEFAULT NULL,
  `calc_venue_net`          DECIMAL(10,2) DEFAULT NULL,
  `calc_margin_pct`         DECIMAL(6,2)  DEFAULT NULL,
  `calc_break_even_attendance` INT(11)    DEFAULT NULL,
  `calc_min_tickets_guarantee` INT(11)   DEFAULT NULL,
  `risk_flags_json`         LONGTEXT      CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
                              CHECK (json_valid(`risk_flags_json`)),
  `approval_status`         ENUM('pending','approved','needs_review','declined') NOT NULL DEFAULT 'pending',
  `approved_by_id`          INT(11)       DEFAULT NULL,
  `approved_at`             DATETIME      DEFAULT NULL,
  `notes`                   TEXT          DEFAULT NULL,
  `created_by_id`           INT(11)       DEFAULT NULL,
  `created_at`              TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`              TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `lead_id`       (`lead_id`),
  KEY `event_id`      (`event_id`),
  KEY `approved_by_id` (`approved_by_id`),
  KEY `created_by_id`  (`created_by_id`),
  CONSTRAINT `lead_eval_ibfk_lead`    FOREIGN KEY (`lead_id`)       REFERENCES `leads`  (`id`) ON DELETE CASCADE,
  CONSTRAINT `lead_eval_ibfk_event`   FOREIGN KEY (`event_id`)      REFERENCES `events` (`id`) ON DELETE SET NULL,
  CONSTRAINT `lead_eval_ibfk_approver` FOREIGN KEY (`approved_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `lead_eval_ibfk_creator`  FOREIGN KEY (`created_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. Extend events ──────────────────────────────────────────────────────────

-- lead_id: back-reference to the lead this event was converted from
ALTER TABLE `events`
  ADD COLUMN IF NOT EXISTS `lead_id`    INT(11)  DEFAULT NULL AFTER `owner_user_id`;

ALTER TABLE `events`
  ADD KEY IF NOT EXISTS `lead_id` (`lead_id`);

-- is_private: explicit flag (can differ from event_type='private_event')
ALTER TABLE `events`
  ADD COLUMN IF NOT EXISTS `is_private` TINYINT(1) NOT NULL DEFAULT 0 AFTER `public_visibility`;

-- deposit_status: tracks state of the required deposit
ALTER TABLE `events`
  ADD COLUMN IF NOT EXISTS `deposit_status`
    ENUM('not_required','requested','partially_received','received','waived','refunded')
    NOT NULL DEFAULT 'not_required' AFTER `deposit_amount`;

-- deposit_waived_by_id + deposit_waived_reason: high-privilege waive action
ALTER TABLE `events`
  ADD COLUMN IF NOT EXISTS `deposit_waived_by_id` INT(11) DEFAULT NULL AFTER `deposit_status`,
  ADD COLUMN IF NOT EXISTS `deposit_waived_reason` VARCHAR(500) DEFAULT NULL AFTER `deposit_waived_by_id`;

SET foreign_key_checks = 1;
