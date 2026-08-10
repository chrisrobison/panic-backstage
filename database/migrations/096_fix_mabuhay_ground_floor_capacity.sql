-- Issue #36: the Mabuhay ground-floor room was still presented as
-- "Downstairs" with a 350-person capacity. Keep the stable internal slug
-- (`downstairs`) so existing references continue to resolve, but correct the
-- user-facing room record and every linked event whose stated capacity exceeds
-- the room's hard 250-person limit.
--
-- This migration is shared by every tenant, so scope it to the Mabuhay venue
-- and room slugs. It is intentionally safe to run again.

UPDATE resources r
JOIN venues v ON v.id = r.venue_id
SET r.name = 'Ground Floor (21+)',
    r.description = 'This is the ground floor Mabuhay Gardens event space. 21+ only',
    r.capacity = 250,
    r.active = 1
WHERE v.slug = 'mabuhay-gardens'
  AND r.slug = 'downstairs';

UPDATE events e
JOIN resources r ON r.id = e.resource_id
JOIN venues v ON v.id = r.venue_id
SET e.capacity = 250
WHERE v.slug = 'mabuhay-gardens'
  AND r.slug = 'downstairs'
  AND e.capacity > 250;
