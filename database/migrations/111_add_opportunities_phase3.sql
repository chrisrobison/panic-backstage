-- Opportunities module — Phase 3: Conference list + Conference Detail.
--
-- 1) venues.latitude/longitude — the one missing piece flagged as an open
--    TODO in Phase 0/1 (docs/OPPORTUNITIES-IMPLEMENTATION.md §5): there was
--    no venue-location source anywhere in the schema, so "distance from
--    venue" could never be computed. Nullable, no default, never
--    auto-populated (no geocoding call anywhere in this codebase) — a
--    venue_admin enters it once via Admin > Venue (src/Venues.php /
--    public/assets/admin.js), same as every other venue field. Distance
--    stays "Unknown" (per spec) until both this and a conference's own
--    latitude/longitude are set.
ALTER TABLE `venues`
  ADD COLUMN IF NOT EXISTS `latitude` decimal(10,6) DEFAULT NULL AFTER `zone`;
ALTER TABLE `venues`
  ADD COLUMN IF NOT EXISTS `longitude` decimal(10,6) DEFAULT NULL AFTER `latitude`;

-- 2) Key Facts — spec: "Facts should be stored and sourceable", distinct
-- from freeform Notes (short discrete bullets, each with its own optional
-- source_url) and from opportunity_signals (which already covers Side Event
-- Signals — reused as-is, no new table needed for that panel).
CREATE TABLE IF NOT EXISTS `opportunity_conference_facts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `conference_id` int(11) NOT NULL,
  `fact` varchar(500) NOT NULL,
  `source_url` varchar(500) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_opportunity_conference_facts_conference` (`conference_id`, `sort_order`),
  KEY `idx_opportunity_conference_facts_created_by` (`created_by`),
  CONSTRAINT `opportunity_conference_facts_conference_fk` FOREIGN KEY (`conference_id`) REFERENCES `opportunity_conferences` (`id`) ON DELETE CASCADE,
  CONSTRAINT `opportunity_conference_facts_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
