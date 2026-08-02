-- Rooms (resources) can now carry their own street address, for venues whose
-- spaces sit at genuinely different addresses (e.g. an annex or a separate
-- building on the same block). City/state/timezone/etc. still come from the
-- parent venue — only the street line is overridable per room. Every display
-- site falls back to the venue's `address` when a room's is unset, so
-- existing rooms behave exactly as before until someone opts in.
ALTER TABLE `resources`
  ADD COLUMN IF NOT EXISTS `address` varchar(255) DEFAULT NULL
    COMMENT 'Street address override for this room; falls back to venues.address when NULL/empty.'
    AFTER `description`;
