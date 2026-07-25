-- Booking Inbox — link the Tasks app to inquiries, and seed the left-nav
-- "Inbox" group.
--
-- `tasks.related_lead_id` lets the Inbox workspace's own Tasks tab and the
-- Onboard-Lead checklist just insert/query ordinary `tasks` rows (Tasks app,
-- migration 069) — no parallel task table for the Inbox. No FK constraint,
-- matching the existing `leads.point_person_id` precedent (indexed,
-- app-validated) rather than fighting ALTER's lack of "ADD CONSTRAINT IF NOT
-- EXISTS" on re-run.

ALTER TABLE `tasks`
  ADD COLUMN IF NOT EXISTS `related_lead_id` int(11) DEFAULT NULL AFTER `document_id`;

SET @idx_exists := (
  SELECT COUNT(1) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks' AND INDEX_NAME = 'idx_tasks_related_lead'
);
SET @sql := IF(@idx_exists = 0,
  'ALTER TABLE `tasks` ADD KEY `idx_tasks_related_lead` (`related_lead_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── Navigation: new top-level "Booking Inbox" group ─────────────────────────
-- Mirrors the Automation group pattern (066_add_process_automation.sql):
-- guard each row with NOT EXISTS rather than a unique key, since nav_items
-- has none on `link`. Gated on a new `view_booking_inbox` capability
-- (added to BaseEndpoint::GLOBAL_CAPABILITIES in the same commit as this
-- migration) rather than reusing view_leads, so the Inbox and the existing
-- Leads pipeline view can be shown/hidden independently per role.
--
-- Named "Booking Inbox", not bare "Inbox" — nav_items already has an
-- existing top-level "Messages" group with its own "Inbox" child
-- (id 6/10, the staff-to-staff message inbox from src/Messages.php).
-- Reusing the bare label would show two identically-labeled "Inbox" entries
-- in the left nav.
INSERT INTO `nav_items` (`parent_id`, `label`, `icon`, `link`, `capability`, `visible`, `sort_order`)
SELECT NULL, 'Booking Inbox', 'fa-solid fa-inbox', NULL, 'view_booking_inbox', 1,
       (SELECT COALESCE(MAX(sort_order), 0) + 10 FROM (SELECT sort_order FROM nav_items WHERE parent_id IS NULL) x)
WHERE NOT EXISTS (SELECT 1 FROM `nav_items` WHERE `label` = 'Booking Inbox' AND `parent_id` IS NULL);

SET @inbox_group_id = (SELECT id FROM `nav_items` WHERE `label` = 'Booking Inbox' AND `parent_id` IS NULL LIMIT 1);

INSERT INTO `nav_items` (`parent_id`, `label`, `icon`, `link`, `capability`, `visible`, `sort_order`)
SELECT @inbox_group_id, 'My Inquiries', 'fa-solid fa-user-check', 'inbox-mine', 'view_booking_inbox', 1, 10
WHERE NOT EXISTS (SELECT 1 FROM `nav_items` WHERE `link` = 'inbox-mine');

INSERT INTO `nav_items` (`parent_id`, `label`, `icon`, `link`, `capability`, `visible`, `sort_order`)
SELECT @inbox_group_id, 'Unassigned', 'fa-solid fa-circle-question', 'inbox-unassigned', 'view_booking_inbox', 1, 20
WHERE NOT EXISTS (SELECT 1 FROM `nav_items` WHERE `link` = 'inbox-unassigned');

INSERT INTO `nav_items` (`parent_id`, `label`, `icon`, `link`, `capability`, `visible`, `sort_order`)
SELECT @inbox_group_id, 'All Inquiries', 'fa-solid fa-list', 'inbox-all', 'view_booking_inbox', 1, 30
WHERE NOT EXISTS (SELECT 1 FROM `nav_items` WHERE `link` = 'inbox-all');

INSERT INTO `nav_items` (`parent_id`, `label`, `icon`, `link`, `capability`, `visible`, `sort_order`)
SELECT @inbox_group_id, 'Follow Up', 'fa-solid fa-clock', 'inbox-followup', 'view_booking_inbox', 1, 40
WHERE NOT EXISTS (SELECT 1 FROM `nav_items` WHERE `link` = 'inbox-followup');

INSERT INTO `nav_items` (`parent_id`, `label`, `icon`, `link`, `capability`, `visible`, `sort_order`)
SELECT @inbox_group_id, 'Archived', 'fa-solid fa-box-archive', 'inbox-archived', 'view_booking_inbox', 1, 50
WHERE NOT EXISTS (SELECT 1 FROM `nav_items` WHERE `link` = 'inbox-archived');
