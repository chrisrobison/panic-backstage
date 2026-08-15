-- A stable, SEO-friendly public URL for an individual event page (/e/{slug}),
-- decoupled from the existing `slug` column. `slug` is regenerated from
-- title+date on every edit (Events\EventRowHelpers::uniqueSlug(), called from
-- Events::update()), which would silently break shared/printed/QR links
-- whenever a title or date changed -- that's exactly why event_public_path()
-- moved to id-keyed query strings in the first place (see Support.php).
--
-- `public_slug` is the per-event analog of `event_series.public_slug`
-- (migration 102): assigned once at creation
-- (Events\EventRowHelpers::assignPublicSlug()) and never touched again, so a
-- shared link keeps working for the life of the event.
--
-- Unlike event_series.public_slug this column is left NULLable rather than
-- NOT NULL: events are created from several code paths (Events::create(),
-- from-template, clone, Events\Series::cloneOccurrence(),
-- Leads\Onboarding::convert(), plus dev/test scripts), and a row that
-- somehow slips through without one simply falls back to the old id-keyed
-- query string in event_public_path() -- the same graceful fallback already
-- used for pre-slug links. A UNIQUE key still allows multiple NULLs in
-- InnoDB, so this stays safe.
ALTER TABLE `events`
  ADD COLUMN IF NOT EXISTS `public_slug` varchar(191) DEFAULT NULL AFTER `slug`;

-- Backfill: every existing event already has a unique, non-empty `slug`
-- (verified at the time this migration was written), so it doubles as a
-- perfectly good one-time public_slug seed. Guard against the unexpected
-- (a duplicate or empty slug) by folding the id in, so the backfill can never
-- violate the unique key added below.
UPDATE `events` e
   SET `public_slug` = CASE
         WHEN e.slug IS NULL OR e.slug = ''
           OR (SELECT COUNT(*) FROM (SELECT slug FROM events) AS s WHERE s.slug = e.slug) > 1
         THEN CONCAT(COALESCE(NULLIF(e.slug, ''), 'event'), '-', e.id)
         ELSE e.slug
       END
 WHERE `public_slug` IS NULL;

ALTER TABLE `events`
  ADD UNIQUE KEY IF NOT EXISTS `uniq_events_public_slug` (`public_slug`);
