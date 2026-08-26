-- Opportunities module — Phase 6: First-class Research Notes workspace
-- (docs/OPPORTUNITIES-IMPLEMENTATION.md §4.6).
--
-- Adds the spec's 6th note type ("strategy" — general/meeting/call/research/
-- internal already shipped in Phase 1) and `updated_by` (who last edited a
-- note's body — surfaced by the workspace, and the source of the archived
-- version's `edited_by` below).
ALTER TABLE `opportunity_notes`
  MODIFY COLUMN `note_type` enum('general','meeting','call','research','internal','strategy') NOT NULL DEFAULT 'general';

ALTER TABLE `opportunity_notes`
  ADD COLUMN IF NOT EXISTS `updated_by` int(11) DEFAULT NULL AFTER `created_by`;

ALTER TABLE `opportunity_notes`
  ADD KEY IF NOT EXISTS `idx_opportunity_notes_updated_by` (`updated_by`);

SET @opp_note_updated_by_fk_exists := (
  SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA = DATABASE()
     AND TABLE_NAME = 'opportunity_notes'
     AND CONSTRAINT_NAME = 'opportunity_notes_updated_by_fk'
);
SET @opp_note_updated_by_fk_sql := IF(
  @opp_note_updated_by_fk_exists = 0,
  'ALTER TABLE `opportunity_notes` ADD CONSTRAINT `opportunity_notes_updated_by_fk` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL',
  'DO 0'
);
PREPARE opp_note_updated_by_fk_stmt FROM @opp_note_updated_by_fk_sql;
EXECUTE opp_note_updated_by_fk_stmt;
DEALLOCATE PREPARE opp_note_updated_by_fk_stmt;

-- opportunity_note_versions — immutable revision history (§3.1, deferred
-- from Phase 1 to this phase). Append-only: whenever an edit changes
-- `body`, the PRE-edit body is archived here (with who authored it and
-- when it stopped being current) before the new body is written. Mirrors
-- `lead_classifications`' versioned-row spirit (a distinct row per
-- revision, not a mutable diff column).
CREATE TABLE IF NOT EXISTS `opportunity_note_versions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `note_id` int(11) NOT NULL,
  `body` mediumtext NOT NULL,
  `edited_by` int(11) DEFAULT NULL,
  `edited_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_opportunity_note_versions_note` (`note_id`),
  CONSTRAINT `opportunity_note_versions_note_fk` FOREIGN KEY (`note_id`) REFERENCES `opportunity_notes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `opportunity_note_versions_edited_by_fk` FOREIGN KEY (`edited_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
