-- Opportunities module — Phase 5: Pipeline board + Opportunity detail +
-- conversion to event (docs/OPPORTUNITIES-IMPLEMENTATION.md §4.5).
--
-- opportunity_qualification — one row per opportunity, a fixed boolean per
-- checklist item (spec's own fixed 9-item list; §3.1 already flagged this
-- as "start with fixed boolean columns, revisit if it needs to become
-- data-driven later" — no evidence yet that it does).
CREATE TABLE IF NOT EXISTS `opportunity_qualification` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `opportunity_id` int(11) NOT NULL,
  `decision_makers_identified` tinyint(1) NOT NULL DEFAULT 0,
  `event_objective_understood` tinyint(1) NOT NULL DEFAULT 0,
  `guest_range_confirmed` tinyint(1) NOT NULL DEFAULT 0,
  `budget_range_identified` tinyint(1) NOT NULL DEFAULT 0,
  `venue_fit_explored` tinyint(1) NOT NULL DEFAULT 0,
  `target_date_confirmed` tinyint(1) NOT NULL DEFAULT 0,
  `must_have_amenities_identified` tinyint(1) NOT NULL DEFAULT 0,
  `competitor_venues_assessed` tinyint(1) NOT NULL DEFAULT 0,
  `success_metrics_established` tinyint(1) NOT NULL DEFAULT 0,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_opportunity_qualification_opportunity` (`opportunity_id`),
  CONSTRAINT `opportunity_qualification_opportunity_fk` FOREIGN KEY (`opportunity_id`) REFERENCES `opportunities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `opportunity_qualification_updated_by_fk` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- opportunity_decision_makers — contact <-> opportunity role link (spec's
-- champion/influencer/decision_maker/finance/blocker/other), deferred from
-- Phase 4 (§4.4/§5) pending the opportunity detail UI that actually manages
-- it. A contact may hold at most one role per opportunity (unique pair);
-- the same contact can naturally appear on multiple opportunities at the
-- same company with different roles on each.
CREATE TABLE IF NOT EXISTS `opportunity_decision_makers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `opportunity_id` int(11) NOT NULL,
  `contact_id` int(11) NOT NULL,
  `role` enum('champion','influencer','decision_maker','finance','blocker','other') NOT NULL DEFAULT 'other',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_opportunity_decision_makers_pair` (`opportunity_id`, `contact_id`),
  KEY `idx_opportunity_decision_makers_contact` (`contact_id`),
  CONSTRAINT `opportunity_decision_makers_opportunity_fk` FOREIGN KEY (`opportunity_id`) REFERENCES `opportunities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `opportunity_decision_makers_contact_fk` FOREIGN KEY (`contact_id`) REFERENCES `opportunity_contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `opportunity_decision_makers_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- opportunities — a handful of nullable columns for the Opportunity detail
-- page's "Proposed Event Format & Venue Fit" and "Quick Quote" panels.
-- Deliberately small: no new event-format/quoting subsystem (spec: "do not
-- build a second full contract system") — these are plain editable fields
-- read back by the detail page, same shape as the existing event_concept/
-- event_type columns.
ALTER TABLE `opportunities`
  ADD COLUMN IF NOT EXISTS `budget_range_min` decimal(10,2) DEFAULT NULL AFTER `estimated_value`,
  ADD COLUMN IF NOT EXISTS `budget_range_max` decimal(10,2) DEFAULT NULL AFTER `budget_range_min`,
  ADD COLUMN IF NOT EXISTS `recommended_resource_id` int(11) DEFAULT NULL AFTER `event_concept`,
  ADD COLUMN IF NOT EXISTS `av_requirements` text DEFAULT NULL AFTER `recommended_resource_id`,
  ADD COLUMN IF NOT EXISTS `catering_notes` text DEFAULT NULL AFTER `av_requirements`,
  ADD COLUMN IF NOT EXISTS `quote_package` varchar(120) DEFAULT NULL AFTER `catering_notes`,
  ADD COLUMN IF NOT EXISTS `quote_duration_hours` decimal(4,1) DEFAULT NULL AFTER `quote_package`;

ALTER TABLE `opportunities`
  ADD KEY IF NOT EXISTS `idx_opportunities_recommended_resource` (`recommended_resource_id`);

-- Guard the FK add for re-runs — MySQL has no `ADD CONSTRAINT IF NOT EXISTS`
-- (same pattern as migration 112's primary_contact_id FK).
SET @opp_resource_fk_exists := (
  SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA = DATABASE()
     AND TABLE_NAME = 'opportunities'
     AND CONSTRAINT_NAME = 'opportunities_recommended_resource_fk'
);
SET @opp_resource_fk_sql := IF(
  @opp_resource_fk_exists = 0,
  'ALTER TABLE `opportunities` ADD CONSTRAINT `opportunities_recommended_resource_fk` FOREIGN KEY (`recommended_resource_id`) REFERENCES `resources` (`id`) ON DELETE SET NULL',
  'DO 0'
);
PREPARE opp_resource_fk_stmt FROM @opp_resource_fk_sql;
EXECUTE opp_resource_fk_stmt;
DEALLOCATE PREPARE opp_resource_fk_stmt;
