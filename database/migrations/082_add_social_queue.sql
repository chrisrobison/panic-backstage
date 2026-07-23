-- Social Queue (spec workflow: Draft -> Needs Assets -> Ready for Review ->
-- Changes Requested -> Approved -> Scheduled -> Awaiting Manual Publish ->
-- Published -> Verified -> Archived) — extends the EXISTING "Panic Promote"
-- module (promote_posts / promote_post_variants / promote_destinations,
-- src/Promote/*.php) rather than adding a parallel social_campaigns/
-- social_post_variants/social_approvals/social_publications schema as the
-- spec's own "suggested data entities" section literally lists. Promote
-- already covers: per-event posts, per-channel variants, a destinations
-- registry (direct-post/manual-submission), and a draft/approved/
-- scheduled/sent/archived lifecycle — this migration widens that lifecycle
-- to the full spec workflow and adds the revision/approval-invalidation
-- and manual-publish-task tracking it was missing.

ALTER TABLE `promote_posts`
  MODIFY COLUMN `status` enum(
    'draft','needs_assets','ready_for_review','changes_requested','approved',
    'scheduled','awaiting_manual_publish','sent','published','verified','archived'
  ) NOT NULL DEFAULT 'draft';

ALTER TABLE `promote_posts`
  ADD COLUMN IF NOT EXISTS `approval_tier` enum('routine','manager') NOT NULL DEFAULT 'routine'
    COMMENT 'Routine posts need one approval; paid ads/policy/cancellation/controversial announcements need a manager (approval_tier=manager) per the spec.' AFTER `status`,
  ADD COLUMN IF NOT EXISTS `content_hash` varchar(64) DEFAULT NULL
    COMMENT 'sha256 of the fields that constitute "the content" (title, master_text, target_url, asset_id) — recomputed on every save.' AFTER `approval_tier`,
  ADD COLUMN IF NOT EXISTS `approved_content_hash` varchar(64) DEFAULT NULL
    COMMENT 'content_hash at the moment of approval. A later edit that changes content_hash away from this value means the approval no longer covers the current content — enforced in src/Promote/Posts.php, not just display.' AFTER `content_hash`,
  ADD COLUMN IF NOT EXISTS `approved_by_user_id` int(11) DEFAULT NULL AFTER `approved_content_hash`,
  ADD COLUMN IF NOT EXISTS `approved_at` datetime DEFAULT NULL AFTER `approved_by_user_id`,
  ADD COLUMN IF NOT EXISTS `public_post_url` varchar(500) DEFAULT NULL
    COMMENT 'Filled in once actually published (auto for API-integrated destinations, manually pasted for manual-publish platforms).' AFTER `approved_at`,
  ADD COLUMN IF NOT EXISTS `related_task_id` int(11) DEFAULT NULL
    COMMENT 'The Tasks-app task (tasks.id) created for a manual-publish platform when this post enters awaiting_manual_publish — see Onboarding-style reuse of the existing Tasks app rather than a parallel checklist.' AFTER `public_post_url`;

SET @idx_exists := (
  SELECT COUNT(1) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'promote_posts' AND INDEX_NAME = 'idx_promote_posts_related_task'
);
SET @sql := IF(@idx_exists = 0,
  'ALTER TABLE `promote_posts` ADD KEY `idx_promote_posts_related_task` (`related_task_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Symmetry with the post-level workflow — a variant can independently need
-- changes requested after the post-level review starts.
ALTER TABLE `promote_post_variants`
  MODIFY COLUMN `status` enum('draft','ready','needs_review','changes_requested','approved') NOT NULL DEFAULT 'draft';

-- tasks.related_promote_post_id mirrors tasks.related_lead_id (migration
-- 077) — same "reuse the Tasks app, one nullable FK-ish column per linkable
-- domain" convention, no FK constraint (indexed, app-validated), consistent
-- with every other cross-domain reference on this table.
ALTER TABLE `tasks`
  ADD COLUMN IF NOT EXISTS `related_promote_post_id` int(11) DEFAULT NULL AFTER `related_lead_id`;

SET @idx_exists := (
  SELECT COUNT(1) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks' AND INDEX_NAME = 'idx_tasks_related_promote_post'
);
SET @sql := IF(@idx_exists = 0,
  'ALTER TABLE `tasks` ADD KEY `idx_tasks_related_promote_post` (`related_promote_post_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── Navigation: "Social Queue" placeholder ──────────────────────────────────
-- Points at the existing Promote UI (#promote -> pb-promote-campaign-list,
-- see public/assets/app.js's route()) rather than a new page — this venue
-- already has a top-level "Promote" nav item but it's currently hidden
-- (nav_items.visible = 0, an admin's deliberate choice via the Navigation
-- Manager — see tests/ui/97-nav-manager.test.mjs's note on the same item).
-- Adding a separate, visible "Social Queue" entry satisfies the spec's
-- "add a Social Queue nav placeholder" without silently un-hiding a nav
-- item an admin chose to hide.
INSERT INTO `nav_items` (`parent_id`, `label`, `icon`, `link`, `capability`, `visible`, `sort_order`)
SELECT NULL, 'Social Queue', 'fa-solid fa-share-nodes', 'promote', 'view_social_queue', 1,
       (SELECT COALESCE(MAX(sort_order), 0) + 10 FROM (SELECT sort_order FROM nav_items WHERE parent_id IS NULL) x)
WHERE NOT EXISTS (SELECT 1 FROM `nav_items` WHERE `label` = 'Social Queue' AND `parent_id` IS NULL);
