-- Clarify the venue/space vocabulary used across the app.
--
-- The two rooms at 443 Broadway were named "Mabuhay Gardens" (the downstairs
-- room) and "Mabuhay Upstairs". Relabel both to the unambiguous
-- "Mabuhay Gardens: <space>" form, and add a third "Both Rooms" option for
-- events that take over the whole building. Matched/guarded by the stable slug
-- so every statement is safe to re-run.
UPDATE venues SET name = 'Mabuhay Gardens: On Broadway' WHERE slug = 'mabuhay-upstairs';
UPDATE venues SET name = 'Mabuhay Gardens: The Mab'     WHERE slug = 'mabuhay-gardens';

INSERT INTO venues (name, slug, address, city, state, timezone)
SELECT 'Mabuhay Gardens: Both Rooms', 'mabuhay-both', '443 Broadway', 'San Francisco', 'CA', 'America/Los_Angeles'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM venues WHERE slug = 'mabuhay-both');
