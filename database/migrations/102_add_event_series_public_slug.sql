-- A recurring series needs one public address that continues to resolve to
-- its next visible occurrence as individual dates pass.

ALTER TABLE `event_series`
  ADD COLUMN IF NOT EXISTS `public_slug` varchar(191) DEFAULT NULL AFTER `title`;

-- Existing series receive deterministic, collision-free slugs. New series use
-- a title-derived slug from Events\Series::uniquePublicSlug().
UPDATE `event_series`
   SET `public_slug` = CONCAT('series-', `id`)
 WHERE `public_slug` IS NULL OR `public_slug` = '';

ALTER TABLE `event_series`
  MODIFY COLUMN `public_slug` varchar(191) NOT NULL,
  ADD UNIQUE KEY IF NOT EXISTS `uniq_event_series_public_slug` (`public_slug`);
