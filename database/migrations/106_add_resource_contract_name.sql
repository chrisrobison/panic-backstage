-- A room can do business under a different public/legal name than its
-- parent venue, same idea as the address override added in migration 088
-- (e.g. Mabuhay Gardens' upstairs room, at its own 435 Broadway address, is
-- actually "Broadway Studios"). Until now contracts always printed the
-- parent venue's name regardless of which room the event was booked into,
-- so a Broadway Studios booking's contract read "Mabuhay Gardens" next to
-- the 435 Broadway address — a real mismatch. contract_name closes that
-- gap: when set, it overrides the venue's name for contract display only;
-- every other surface keeps using the venue's own name. Leaving it blank
-- means the room simply uses the venue's name, so existing rooms are
-- unaffected. Safe to run again.

ALTER TABLE `resources`
  ADD COLUMN IF NOT EXISTS `contract_name` varchar(255) DEFAULT NULL
    COMMENT 'Public/legal name override for this room in generated contracts; falls back to venues.name when NULL/empty.'
    AFTER `address`;

UPDATE resources r
JOIN venues v ON v.id = r.venue_id
SET r.contract_name = 'Broadway Studios'
WHERE v.slug = 'mabuhay-gardens'
  AND r.slug = 'upstairs-all-ages';
