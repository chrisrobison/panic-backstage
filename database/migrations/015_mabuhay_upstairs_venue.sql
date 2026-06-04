-- 015_mabuhay_upstairs_venue.sql
--
-- Add "Mabuhay Upstairs" as a selectable venue alongside "Mabuhay Gardens".
-- Same building (443 Broadway, North Beach), separate upstairs performance
-- room. The event-detail venue dropdown is data-driven (SELECT * FROM venues
-- ORDER BY name), so inserting the row is all that's needed for it to appear.
--
-- Idempotent: guarded by the unique `slug`, so it is safe to re-run.

USE panic_backstage;

INSERT INTO venues (name, slug, address, city, state, timezone)
SELECT 'Mabuhay Upstairs', 'mabuhay-upstairs', '443 Broadway', 'San Francisco', 'CA', 'America/Los_Angeles'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM venues WHERE slug = 'mabuhay-upstairs');
