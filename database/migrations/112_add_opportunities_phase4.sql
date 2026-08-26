-- Opportunities module — Phase 4: Company list + Company Detail + buyer
-- contacts (docs/OPPORTUNITIES-IMPLEMENTATION.md §4.4).
--
-- opportunity_contacts — corporate buyer contacts scoped to a prospect
-- company. Deliberately NOT the existing `contacts` table (a B2C
-- ticket-buyer marketing audience — see §1.15 for why that reuse was
-- unsafe). Dedup identity is normalized email *within a company*
-- (§3.1's proposed design: "unique (company_id, email) where email
-- present") — the same human can legitimately be a contact at two
-- different companies without collision, and a contact with no known
-- email yet doesn't collide with anything (MySQL unique indexes treat
-- NULL as distinct, same trick already used by
-- opportunity_companies.domain).
--
-- "Likely buyer" is intentionally NOT a stored column — Opportunities/
-- Contacts.php computes it per-request from a deterministic keyword match
-- against `title`, so it never goes stale relative to a manually-edited
-- title and needs no migration if the keyword list changes later.
CREATE TABLE IF NOT EXISTS `opportunity_contacts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `title` varchar(180) DEFAULT NULL,
  `department` varchar(120) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(60) DEFAULT NULL,
  `linkedin_url` varchar(500) DEFAULT NULL,
  `status` enum('active','cold','left_company','unknown') NOT NULL DEFAULT 'unknown',
  `last_touch_at` datetime DEFAULT NULL,
  `source_url` varchar(500) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_opportunity_contacts_company_email` (`company_id`, `email`),
  KEY `idx_opportunity_contacts_name` (`name`),
  KEY `idx_opportunity_contacts_created_by` (`created_by`),
  CONSTRAINT `opportunity_contacts_company_fk` FOREIGN KEY (`company_id`) REFERENCES `opportunity_companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `opportunity_contacts_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- opportunities.primary_contact_id — the opportunity's likely/primary buyer.
-- Deferred from the Phase 1 migration specifically because buyer contacts
-- didn't exist yet (see §4.1's "Deliberately deferred" note, which named
-- this exact column). Nullable, ON DELETE SET NULL so removing a contact
-- never blocks or cascades an opportunity.
ALTER TABLE `opportunities`
  ADD COLUMN IF NOT EXISTS `primary_contact_id` int(11) DEFAULT NULL AFTER `conference_id`;

ALTER TABLE `opportunities`
  ADD KEY IF NOT EXISTS `idx_opportunities_primary_contact` (`primary_contact_id`);

-- Guard the FK add for re-runs — MySQL has no `ADD CONSTRAINT IF NOT EXISTS`
-- (same pattern as migration 105_add_contract_series_id.sql).
SET @opp_primary_contact_fk_exists := (
  SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA = DATABASE()
     AND TABLE_NAME = 'opportunities'
     AND CONSTRAINT_NAME = 'opportunities_primary_contact_fk'
);
SET @opp_primary_contact_fk_sql := IF(
  @opp_primary_contact_fk_exists = 0,
  'ALTER TABLE `opportunities` ADD CONSTRAINT `opportunities_primary_contact_fk` FOREIGN KEY (`primary_contact_id`) REFERENCES `opportunity_contacts` (`id`) ON DELETE SET NULL',
  'DO 0'
);
PREPARE opp_primary_contact_fk_stmt FROM @opp_primary_contact_fk_sql;
EXECUTE opp_primary_contact_fk_stmt;
DEALLOCATE PREPARE opp_primary_contact_fk_stmt;
