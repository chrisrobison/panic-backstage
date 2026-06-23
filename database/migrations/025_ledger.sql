-- Migration 013 (single-tenant): event financial ledger (append-only).
--
-- Replaces the flat event_settlements model with an append-only ledger of
-- categorized line items.  The existing event_settlements table and the
-- /api/events/{id}/settlement endpoint are preserved for backward compatibility.
--
-- event_ledger_entries — one row per revenue/cost/payment line item
-- event_closeout_state — tracks closeout workflow state and checklist

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ── 1. event_ledger_entries ───────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `event_ledger_entries` (
  `id`              INT(11)       NOT NULL AUTO_INCREMENT,
  `event_id`        INT(11)       NOT NULL,
  `category`        ENUM(
                      -- Revenue
                      'tickets','ticket_fees','bar_sales','rental_fee','hosted_bar',
                      'merch_share','sponsorship','equipment_rental','overtime_charge',
                      'other_revenue',
                      -- Costs
                      'artist_guarantee','promoter_settlement','labor','sound_production',
                      'security','cleaning','rentals','catering','vendor_cost',
                      'processing_fees','taxes','refunds','other_cost',
                      -- Payments / Receivables
                      'deposit_received','invoice_payment','credit','outstanding_balance',
                      'artist_payout','promoter_payout','vendor_payout','staff_payout',
                      'adjustment'
                    ) NOT NULL,
  `line_type`       ENUM('revenue','cost','payment','receivable') NOT NULL DEFAULT 'revenue',
  `amount`          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency`        CHAR(3)       NOT NULL DEFAULT 'USD',
  `description`     VARCHAR(500)  DEFAULT NULL,
  `source`          ENUM('manual','ticketing_sync','pos_import','vendor_link',
                         'staffing_link','payment_link','change_order_link','system')
                      NOT NULL DEFAULT 'manual',
  `source_ref_id`   INT(11)       DEFAULT NULL,
  `reconciler_id`   INT(11)       DEFAULT NULL,
  `reconciled_at`   DATETIME      DEFAULT NULL,
  `is_void`         TINYINT(1)    NOT NULL DEFAULT 0,
  `void_reason`     VARCHAR(255)  DEFAULT NULL,
  `created_by_id`   INT(11)       DEFAULT NULL,
  `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ledger_event`    (`event_id`),
  KEY `idx_ledger_category` (`category`),
  KEY `idx_ledger_type`     (`line_type`),
  KEY `reconciler_id`       (`reconciler_id`),
  KEY `created_by_id`       (`created_by_id`),
  CONSTRAINT `ledger_ibfk_event`      FOREIGN KEY (`event_id`)      REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ledger_ibfk_reconciler` FOREIGN KEY (`reconciler_id`) REFERENCES `users`  (`id`) ON DELETE SET NULL,
  CONSTRAINT `ledger_ibfk_creator`    FOREIGN KEY (`created_by_id`) REFERENCES `users`  (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. event_closeout_state ───────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `event_closeout_state` (
  `id`                        INT(11)    NOT NULL AUTO_INCREMENT,
  `event_id`                  INT(11)    NOT NULL,
  `status`                    ENUM('open','in_progress','pending_review','finalized','reopened')
                                NOT NULL DEFAULT 'open',
  -- Checklist flags
  `actual_hours_confirmed`    TINYINT(1) NOT NULL DEFAULT 0,
  `bar_revenue_reconciled`    TINYINT(1) NOT NULL DEFAULT 0,
  `ticket_revenue_reconciled` TINYINT(1) NOT NULL DEFAULT 0,
  `vendor_costs_entered`      TINYINT(1) NOT NULL DEFAULT 0,
  `incidents_reviewed`        TINYINT(1) NOT NULL DEFAULT 0,
  `final_invoice_prepared`    TINYINT(1) NOT NULL DEFAULT 0,
  `payment_obligations_recorded` TINYINT(1) NOT NULL DEFAULT 0,
  -- Finalization
  `finalized_by_id`           INT(11)    DEFAULT NULL,
  `finalized_at`              DATETIME   DEFAULT NULL,
  `reopen_reason`             TEXT       DEFAULT NULL,
  `reopened_by_id`            INT(11)    DEFAULT NULL,
  `reopened_at`               DATETIME   DEFAULT NULL,
  `notes`                     TEXT       DEFAULT NULL,
  `created_at`                TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`                TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_id` (`event_id`),
  KEY `finalized_by_id` (`finalized_by_id`),
  CONSTRAINT `closeout_ibfk_event`     FOREIGN KEY (`event_id`)       REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `closeout_ibfk_finalizer` FOREIGN KEY (`finalized_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `closeout_ibfk_reopener`  FOREIGN KEY (`reopened_by_id`)  REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Add policy_snapshot_json to events ─────────────────────────────────────
-- Snapshot of the venue policy in effect when the event was booked.
-- Allows policy edits without retroactively changing already-booked events.

ALTER TABLE `events`
  ADD COLUMN IF NOT EXISTS `policy_snapshot_json`
    LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
    CHECK (json_valid(`policy_snapshot_json`))
    AFTER `is_private`;

SET foreign_key_checks = 1;
