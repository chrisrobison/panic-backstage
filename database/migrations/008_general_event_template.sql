-- Refresh the Punk Rock Karaoke template into "Karaoke + Open Mic" and add
-- a new "General Event" template for one-off / uncategorized shows.
--
-- Safe to re-run: the UPDATE only matches the old name (so a second run is a
-- no-op), and the INSERT for General Event is guarded by NOT EXISTS.

USE panic_backstage;

-- ── Karaoke + Open Mic (rename of "Punk Rock Karaoke Night") ───────────────
UPDATE event_templates
SET name                       = 'Karaoke + Open Mic',
    default_title              = 'Karaoke + Open Mic',
    default_description_public = 'Two-part night on Broadway: open mic for songs, poems, and experiments, then karaoke takes over. Bring originals or sing along — all experience levels welcome.',
    checklist_json             = '[{"title":"Confirm KJ/host"},{"title":"Confirm sound + microphones"},{"title":"Confirm projection/display setup"},{"title":"Confirm signup process (open mic + karaoke)"},{"title":"Create/update recurring flyer"},{"title":"Publish event page"},{"title":"Post social reminder"},{"title":"Confirm door/staff coverage"}]',
    schedule_json              = '[{"title":"Staff call","item_type":"staff_call","start_time":"18:30"},{"title":"Open mic signups open","item_type":"other","start_time":"19:00"},{"title":"Doors","item_type":"doors","start_time":"19:30"},{"title":"Open mic","item_type":"set","start_time":"20:00"},{"title":"Karaoke begins","item_type":"set","start_time":"21:30"},{"title":"Last call for singers","item_type":"other","start_time":"23:30"},{"title":"Event end","item_type":"curfew","start_time":"00:00"}]'
WHERE name = 'Punk Rock Karaoke Night';

-- ── General Event (new) ────────────────────────────────────────────────────
INSERT INTO event_templates
  (venue_id, name, event_type, default_title, default_description_public,
   default_ticket_price, default_age_restriction, checklist_json, schedule_json)
SELECT v, n, et, dt, ddp, dtp, dar, cj, sj FROM (SELECT
  1                AS v,
  'General Event'  AS n,
  'special_event'  AS et,
  'General Event'  AS dt,
  'A general-purpose template for one-off or uncategorized shows. Update the title, type, and schedule from the event page after creation.' AS ddp,
  0.00             AS dtp,
  '21+'            AS dar,
  '[{"title":"Confirm date and venue"},{"title":"Confirm staff coverage"},{"title":"Create/update flyer"},{"title":"Publish event page"},{"title":"Confirm sound setup"}]' AS cj,
  '[{"title":"Staff call","item_type":"staff_call","start_time":"17:30"},{"title":"Doors","item_type":"doors","start_time":"19:00"},{"title":"Event","item_type":"set","start_time":"20:00"},{"title":"Event end","item_type":"curfew","start_time":"23:00"}]' AS sj
) AS t
WHERE NOT EXISTS (SELECT 1 FROM event_templates WHERE name = 'General Event' AND venue_id = 1);
