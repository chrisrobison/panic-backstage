-- Booking Inbox — core columns on `leads`.
--
-- The Booking Inbox is an extension of the existing Leads pipeline (one
-- inquiry = one `leads` row), not a parallel schema — see docs/booking-inbox.md
-- for the full architecture. This migration:
--   1. Widens `leads.status` to the full inbox state machine, while keeping
--      every existing value so old rows/code (src/Leads.php, leads.js) keep
--      working untouched. `onboarded` is the semantic successor to
--      `converted` (kept for backward compatibility — see
--      src/Leads/StatusMachine.php).
--   2. Adds the assign/claim/own columns the spec requires as three distinct
--      concepts (see the model note in docs/booking-inbox.md):
--        assigned_to_user_id/assigned_at  — system recommendation/routing
--        claimed_by_user_id/claimed_at    — a user took responsibility
--        owner_user_id/owned_since        — first meaningful response sent,
--                                            or a manager assigned long-term
--                                            ownership
--   3. Adds SLA deadline columns computed by src/Leads/ClaimService.php and
--      swept by scripts/lead-sla-tick.php.
--   4. Adds a human-friendly inquiry number and a small set of
--      classification-mirror columns the inbox list needs to render fast
--      without joining lead_classifications on every row.
--
-- Note: assigned_to_user_id / claimed_by_user_id / owner_user_id intentionally
-- have no FOREIGN KEY constraint, matching the existing `leads.point_person_id`
-- precedent in this same table (indexed, app-validated, not FK-enforced).

ALTER TABLE `leads`
  MODIFY COLUMN `status` enum(
    'new','triage','evaluating','needs_review','approved','declined','converted','canceled',
    'classified','assigned','claimed','acknowledged','qualifying','awaiting_customer',
    'availability_sent','tour_scheduled','proposal_sent','negotiating','on_hold','onboarded',
    'contract_sent','deposit_pending','booked','lost','spam','duplicate','archived'
  ) NOT NULL DEFAULT 'new';

ALTER TABLE `leads`
  ADD COLUMN IF NOT EXISTS `assigned_to_user_id` int(11) DEFAULT NULL AFTER `point_person_id`,
  ADD COLUMN IF NOT EXISTS `assigned_at` datetime DEFAULT NULL AFTER `assigned_to_user_id`,
  ADD COLUMN IF NOT EXISTS `claimed_by_user_id` int(11) DEFAULT NULL AFTER `assigned_at`,
  ADD COLUMN IF NOT EXISTS `claimed_at` datetime DEFAULT NULL AFTER `claimed_by_user_id`,
  ADD COLUMN IF NOT EXISTS `claim_expires_at` datetime DEFAULT NULL AFTER `claimed_at`,
  ADD COLUMN IF NOT EXISTS `owner_user_id` int(11) DEFAULT NULL AFTER `claim_expires_at`,
  ADD COLUMN IF NOT EXISTS `owned_since` datetime DEFAULT NULL AFTER `owner_user_id`,
  ADD COLUMN IF NOT EXISTS `sla_claim_due_at` datetime DEFAULT NULL AFTER `owned_since`,
  ADD COLUMN IF NOT EXISTS `sla_response_due_at` datetime DEFAULT NULL AFTER `sla_claim_due_at`,
  ADD COLUMN IF NOT EXISTS `first_response_at` datetime DEFAULT NULL AFTER `sla_response_due_at`,
  ADD COLUMN IF NOT EXISTS `inquiry_number` varchar(20) DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `inquiry_score` int(11) DEFAULT NULL AFTER `inquiry_number`,
  ADD COLUMN IF NOT EXISTS `event_category` varchar(80) DEFAULT NULL AFTER `event_type`,
  ADD COLUMN IF NOT EXISTS `music_genre` varchar(80) DEFAULT NULL AFTER `event_category`,
  ADD COLUMN IF NOT EXISTS `age_restriction` varchar(80) DEFAULT NULL AFTER `music_genre`;

-- Backfill a stable human-friendly number for any rows created before this
-- migration (INQ-000123). Safe to re-run — only touches rows still NULL.
UPDATE `leads` SET `inquiry_number` = CONCAT('INQ-', LPAD(`id`, 6, '0')) WHERE `inquiry_number` IS NULL;

-- Unique index added separately (guarded) so re-running the ALTER above never
-- fails if the index already exists.
SET @idx_exists := (
  SELECT COUNT(1) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND INDEX_NAME = 'uniq_leads_inquiry_number'
);
SET @sql := IF(@idx_exists = 0,
  'ALTER TABLE `leads` ADD UNIQUE KEY `uniq_leads_inquiry_number` (`inquiry_number`)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(1) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND INDEX_NAME = 'idx_leads_assigned'
);
SET @sql := IF(@idx_exists = 0,
  'ALTER TABLE `leads` ADD KEY `idx_leads_assigned` (`assigned_to_user_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(1) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND INDEX_NAME = 'idx_leads_claimed'
);
SET @sql := IF(@idx_exists = 0,
  'ALTER TABLE `leads` ADD KEY `idx_leads_claimed` (`claimed_by_user_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(1) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND INDEX_NAME = 'idx_leads_owner'
);
SET @sql := IF(@idx_exists = 0,
  'ALTER TABLE `leads` ADD KEY `idx_leads_owner` (`owner_user_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
