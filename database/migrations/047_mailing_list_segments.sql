-- Mailing list management upgrades: bulk add, CSV import, and "smart"
-- (auto-populated) lists on top of the static lists added in 046.
--
-- `list_type` distinguishes a manually-curated list from a `segment` list
-- whose membership is computed from `segment_rules` (JSON filter criteria,
-- e.g. {"opted":1,"min_spend":500}) via a "Refresh" action rather than edited
-- by hand. `list_type` is treated as immutable by the application once a list
-- is created — converting a list's type would raise unanswerable questions
-- about what to do with existing members, so the app just has you create a
-- new list instead.
--
-- `added_via` records how a membership row came to exist (manual pick,
-- bulk-by-filter, CSV import, or segment refresh) so a segment refresh can
-- safely prune only the rows it added itself (`added_via='segment'`) without
-- ever evicting someone who was added by hand or via CSV/bulk.
ALTER TABLE `mailing_lists`
  ADD COLUMN `list_type` enum('static','segment') NOT NULL DEFAULT 'static' AFTER `description`,
  ADD COLUMN `segment_rules` text DEFAULT NULL COMMENT 'JSON object of filter criteria, e.g. {"opted":1,"min_spend":500}' AFTER `list_type`,
  ADD COLUMN `segment_refreshed_at` datetime DEFAULT NULL AFTER `segment_rules`;

ALTER TABLE `list_membership`
  ADD COLUMN `added_via` enum('manual','bulk','csv_import','segment') NOT NULL DEFAULT 'manual' AFTER `status`;
