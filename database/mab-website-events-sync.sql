-- =============================================================
-- mab-website-events-sync.sql
--
-- Reconciles the events table against the "Upcoming events" carousel
-- currently live on themab.org (<section id="events">) so that
-- GET /api/feed/events.json — and the <mab-events-carousel> web component
-- that consumes it — shows exactly what the public site shows today.
--
-- Run AFTER: schema.sql, all migrations through 056.
--
-- Two kinds of rows below:
--
--   UPDATE (by id) — an existing booking row was matched to a themab.org
--   listing by date + venue + recognizable title (e.g. events.title
--   'Vaxxines' on 2026-07-31 is themab.org's "Pat Todd & The Rankoutsiders",
--   with The Vaxxines billed as support). These get their title corrected
--   and public_visibility/description/subtitle/tags/ticket info filled in
--   to match the site. Workflow `status` is left untouched — this only
--   touches public-facing fields.
--
--   INSERT ... ON DUPLICATE KEY UPDATE (by slug) — no existing row covers
--   this listing (the recurring Zinggflower Monday slot and Karaoke Sunday
--   upstairs at On Broadway). Slug is the natural idempotency key, same
--   convention as database/mabevents-import.sql.
--
-- Flyer images are left hosted on themab.org (event_assets.file_path
-- supports absolute URLs — see Feed::flyerUrl()) rather than mirrored, so
-- there's nothing to re-upload. Each flyer insert is guarded by deleting
-- any previous row this script created (generation_source =
-- 'themab-website-sync') first, so re-running this file never piles up
-- duplicate asset rows.
--
-- Safe to re-run.
-- =============================================================

START TRANSACTION;

SET @venue_main      = (SELECT id FROM venues WHERE slug = 'mabuhay-gardens' LIMIT 1);
SET @venue_upstairs   = (SELECT id FROM venues WHERE slug = 'mabuhay-upstairs' LIMIT 1);
SET @owner_id         = COALESCE(
  (SELECT id FROM users WHERE email = 'admin@mabuhay.local' LIMIT 1),
  (SELECT id FROM users WHERE role = 'venue_admin' ORDER BY id LIMIT 1)
);

-- ── I AM A SNAIL — 2026-07-11 (id 130, already public/internal-ticketing) ──
UPDATE events SET
  public_visibility = 1,
  description_public = 'A bold alt-comedy fever dream by clown NoraDell. Taking the question “would you still love me if I were a snail” to its furthest illogical extreme. $20.00.',
  public_subtitle = 'DOWNSTAIRS // DOORS 7PM // 21+',
  public_tags = 'comedy',
  age_restriction = '21+'
WHERE id = 130;

DELETE FROM event_assets WHERE event_id = 130 AND generation_source = 'themab-website-sync';
INSERT INTO event_assets (event_id, asset_type, title, filename, original_filename, file_path, approval_status, generation_source)
VALUES (130, 'flyer', 'I Am A Snail flyer', 'july-11-2026-i-am-a-snail.jpg', 'july-11-2026-i-am-a-snail.jpg',
        'https://themab.org/wp-content/uploads/2026/06/july-11-2026-i-am-a-snail.jpg', 'approved', 'themab-website-sync');

-- ── ZINGGFLOWER — recurring Mondays, 2026-07-13 / 07-20 / 07-27 (new) ──────
INSERT INTO events (venue_id, title, slug, event_type, status, description_public, public_subtitle, public_tags,
                     date, show_time, age_restriction, room, public_visibility, ticketing_mode, owner_user_id)
VALUES
  (@venue_main, 'Zinggflower', 'zinggflower-2026-07-13', 'live_music', 'published',
   'Presented by GamperDrums. Catch Zinggflower live tonight! Head into the downstairs space at The Mab to catch the show. 21+.',
   'THE MAB (DOWNSTAIRS) // 6:30 PM', 'live-music', '2026-07-13', '18:30:00', '21+', 'downstairs', 1, 'external', @owner_id),
  (@venue_main, 'Zinggflower', 'zinggflower-2026-07-20', 'live_music', 'published',
   'Presented by GamperDrums. Catch Zinggflower live tonight! Head into the downstairs space at The Mab to catch the show. 21+.',
   'THE MAB (DOWNSTAIRS) // 6:30 PM', 'live-music', '2026-07-20', '18:30:00', '21+', 'downstairs', 1, 'external', @owner_id),
  (@venue_main, 'Zinggflower', 'zinggflower-2026-07-27', 'live_music', 'published',
   'Presented by GamperDrums. Catch Zinggflower live tonight! Head into the downstairs space at The Mab to catch the show. 21+.',
   'THE MAB (DOWNSTAIRS) // 6:30 PM', 'live-music', '2026-07-27', '18:30:00', '21+', 'downstairs', 1, 'external', @owner_id)
ON DUPLICATE KEY UPDATE
  description_public = VALUES(description_public), public_subtitle = VALUES(public_subtitle),
  public_tags = VALUES(public_tags), show_time = VALUES(show_time), age_restriction = VALUES(age_restriction),
  public_visibility = VALUES(public_visibility);

DELETE FROM event_assets WHERE event_id IN (SELECT id FROM events WHERE slug IN ('zinggflower-2026-07-13','zinggflower-2026-07-20','zinggflower-2026-07-27')) AND generation_source = 'themab-website-sync';
INSERT INTO event_assets (event_id, asset_type, title, filename, original_filename, file_path, approval_status, generation_source)
SELECT id, 'flyer', 'Zinggflower flyer', 'july-mondays-2026-zigg.jpg', 'july-mondays-2026-zigg.jpg',
       'https://themab.org/wp-content/uploads/2026/07/july-mondays-2026-zigg.jpg', 'approved', 'themab-website-sync'
FROM events WHERE slug IN ('zinggflower-2026-07-13','zinggflower-2026-07-20','zinggflower-2026-07-27');

-- ── CAT'S CORNER — recurring Wednesdays (existing ids 671391/671398/671399/671400) ──
UPDATE events SET
  title = 'Cat''s Corner',
  description_public = 'A New Chapter at 435 Broadway. History, elegance, nightlife, and room to grow—while continuing the tradition Catrine set in motion.',
  public_subtitle = 'JESS KING AND HER HOT MESS',
  public_tags = 'dance,live-music',
  ticket_url = 'https://www.catscornersf.com/',
  ticketing_mode = 'external',
  public_visibility = 1,
  public_schedule_pricing = '{"sections":[{"heading":"MAIN BALLROOM","lines":["7-8 PM: Int. Lindy Hop (Sign Up)","8-9 PM: Beg. Lindy Hop (Sign Up)","9-11:30 PM: Live Music Party"]},{"heading":"SIDE ROOM","lines":["8-9 PM: Adv. Topics (Sign Up)","9-9:30 PM: Beg. Swing Drop-in"]},{"heading":"COSTS","lines":["$15 GA (inc. 9 PM drop-in lesson)","$10 before 8:30 PM / after 10 PM","$5 after 11 PM"]}]}'
WHERE id = 671391;

UPDATE events SET
  title = 'Cat''s Corner',
  description_public = 'A New Chapter at 435 Broadway. History, elegance, nightlife, and room to grow—while continuing the tradition Catrine set in motion.',
  public_subtitle = 'BAND TBA',
  public_tags = 'dance,live-music',
  ticket_url = 'https://www.catscornersf.com/',
  ticketing_mode = 'external',
  public_visibility = 1,
  public_schedule_pricing = '{"sections":[{"heading":"MAIN BALLROOM","lines":["7-8 PM: Int. Lindy Hop (Sign Up)","8-9 PM: Beg. Lindy Hop (Sign Up)","9-11:30 PM: Live Music Party"]},{"heading":"SIDE ROOM","lines":["8-9 PM: Adv. Topics (Sign Up)","9-9:30 PM: Beg. Swing Drop-in"]},{"heading":"COSTS","lines":["$15 GA (inc. 9 PM drop-in lesson)","$10 before 8:30 PM / after 10 PM","$5 after 11 PM"]}]}'
WHERE id = 671398;

UPDATE events SET
  title = 'Cat''s Corner',
  description_public = 'A New Chapter at 435 Broadway. History, elegance, nightlife, and room to grow—while continuing the tradition Catrine set in motion.',
  public_subtitle = 'BAND TBA',
  public_tags = 'dance,live-music',
  ticket_url = 'https://www.catscornersf.com/',
  ticketing_mode = 'external',
  public_visibility = 1,
  public_schedule_pricing = '{"sections":[{"heading":"MAIN BALLROOM","lines":["7-8 PM: Int. Lindy Hop (Sign Up)","8-9 PM: Beg. Lindy Hop (Sign Up)","9-11:30 PM: Live Music Party"]},{"heading":"SIDE ROOM","lines":["8-9 PM: Adv. Topics (Sign Up)","9-9:30 PM: Beg. Swing Drop-in"]},{"heading":"COSTS","lines":["$15 GA (inc. 9 PM drop-in lesson)","$10 before 8:30 PM / after 10 PM","$5 after 11 PM"]}]}'
WHERE id = 671399;

UPDATE events SET
  title = 'Cat''s Corner',
  description_public = 'A New Chapter at 435 Broadway. History, elegance, nightlife, and room to grow—while continuing the tradition Catrine set in motion.',
  public_subtitle = 'ANGELA LAFLAMME & HER SWING ALL-STARS',
  public_tags = 'dance,live-music',
  ticket_url = 'https://www.catscornersf.com/',
  ticketing_mode = 'external',
  public_visibility = 1,
  public_schedule_pricing = '{"sections":[{"heading":"MAIN BALLROOM","lines":["7-8 PM: Int. Lindy Hop (Sign Up)","8-9 PM: Beg. Lindy Hop (Sign Up)","9-11:30 PM: Live Music Party"]},{"heading":"SIDE ROOM","lines":["8-9 PM: Adv. Topics (Sign Up)","9-9:30 PM: Beg. Swing Drop-in"]},{"heading":"COSTS","lines":["$15 GA (inc. 9 PM drop-in lesson)","$10 before 8:30 PM / after 10 PM","$5 after 11 PM"]}]}'
WHERE id = 671400;

DELETE FROM event_assets WHERE event_id IN (671391,671398,671399,671400) AND generation_source = 'themab-website-sync';
INSERT INTO event_assets (event_id, asset_type, title, filename, original_filename, file_path, approval_status, generation_source)
SELECT id, 'flyer', 'Cat''s Corner flyer', 'mar-2026-cats-corner-wed.jpg', 'mar-2026-cats-corner-wed.jpg',
       'https://themab.org/wp-content/uploads/2026/04/mar-2026-cats-corner-wed.jpg', 'approved', 'themab-website-sync'
FROM events WHERE id IN (671391,671398,671399,671400);

-- ── THE BLURRY STARS — 2026-07-18 (id 641046, already public/internal-ticketing) ──
UPDATE events SET
  title = 'The Blurry Stars',
  description_public = 'Live music featuring Niblits, The Blurry Stars, Fan Fiction, and a special guest! $10 General Admission.',
  public_subtitle = 'DOWNSTAIRS // 8:00 PM // 21+',
  public_tags = 'live-music',
  public_visibility = 1
WHERE id = 641046;

DELETE FROM event_assets WHERE event_id = 641046 AND generation_source = 'themab-website-sync';
INSERT INTO event_assets (event_id, asset_type, title, filename, original_filename, file_path, approval_status, generation_source)
VALUES (641046, 'flyer', 'The Blurry Stars flyer', 'july-18-2026-the-blurry-stars.jpg', 'july-18-2026-the-blurry-stars.jpg',
        'https://themab.org/wp-content/uploads/2026/07/july-18-2026-the-blurry-stars.jpg', 'approved', 'themab-website-sync');

-- ── UNDER FRIEDL'S WING — 2026-07-19 (id 132) ──────────────────────────────
UPDATE events SET
  title = 'Under Friedl''s Wing',
  description_public = 'Using painting, puppetry, and music to explore artist Friedl Dicker-Brandeis''s life opening creative reprieves for children inside the Theresienstadt ghetto. Directed by Jeff Raz.',
  public_subtitle = 'ACTIVISM & ART // 21+',
  public_tags = 'live-music',
  ticket_url = 'https://www.tixr.com/groups/mab/events/mabuhaygardens-under-friedl-s-wing-193250',
  ticketing_mode = 'external',
  public_visibility = 1
WHERE id = 132;

DELETE FROM event_assets WHERE event_id = 132 AND generation_source = 'themab-website-sync';
INSERT INTO event_assets (event_id, asset_type, title, filename, original_filename, file_path, approval_status, generation_source)
VALUES (132, 'flyer', 'Under Friedl''s Wing flyer', 'jul-19-2026-under-friedls-wing.jpg', 'jul-19-2026-under-friedls-wing.jpg',
        'https://themab.org/wp-content/uploads/2026/06/jul-19-2026-under-friedls-wing.jpg', 'approved', 'themab-website-sync');

-- ── LEESTOCK 6 — 2026-07-25 (id 285443, "Both Rooms" venue) ────────────────
UPDATE events SET
  title = 'Leestock 6',
  description_public = 'Support your local music scene! A $25 ticket gets you access to both stages for 110 musical acts and vendors. Noon – 11pm.',
  public_subtitle = 'BANG THE BAY PRESENTS // 21+',
  public_tags = 'live-music',
  doors_time = '12:00:00',
  end_time = '23:00:00',
  age_restriction = '21+',
  public_visibility = 1
WHERE id = 285443;

DELETE FROM event_assets WHERE event_id = 285443 AND generation_source = 'themab-website-sync';
INSERT INTO event_assets (event_id, asset_type, title, filename, original_filename, file_path, approval_status, generation_source)
VALUES (285443, 'flyer', 'Leestock 6 flyer', 'july-25-2026-wed.jpg', 'july-25-2026-wed.jpg',
        'https://themab.org/wp-content/uploads/2026/06/july-25-2026-wed.jpg', 'approved', 'themab-website-sync');

-- ── PAT TODD & THE RANKOUTSIDERS — 2026-07-31 (id 137, was "Vaxxines") ─────
UPDATE events SET
  title = 'Pat Todd & The Rankoutsiders',
  description_public = 'LA punk legend Pat Todd joins three of the Bay''s finest for a night of loud guitars, hooks, attitude, and no-frills rock at The Mab. Doors 7 PM. 21+.',
  public_subtitle = 'THE VAXXINES, THE SEAGULLS & SF REJECTS',
  public_tags = 'live-music',
  ticket_url = 'https://www.tixr.com/groups/mab/events/mabuhaygardens-live-at-the-mab-pat-todd-the-rankoutsiders-with-the-vaxxines-the-seagulls-sf-rejects-197934',
  ticketing_mode = 'external',
  doors_time = '19:00:00',
  age_restriction = '21+',
  public_visibility = 1
WHERE id = 137;

DELETE FROM event_assets WHERE event_id = 137 AND generation_source = 'themab-website-sync';
INSERT INTO event_assets (event_id, asset_type, title, filename, original_filename, file_path, approval_status, generation_source)
VALUES (137, 'flyer', 'Pat Todd & The Rankoutsiders flyer', 'july-31-pat-todd.jpg', 'july-31-pat-todd.jpg',
        'https://themab.org/wp-content/uploads/2026/07/july-31-pat-todd.jpg', 'approved', 'themab-website-sync');

-- ── UNION JACK & THE RIPPERS — 2026-08-01 (id 138, was "Boneless Ones, Dylan, Kehoe") ──
UPDATE events SET
  title = 'Union Jack & The Rippers',
  description_public = 'A genre-crossing lineup of NWOBHM metal classics, East Bay skate punk legends, and heavy surf tunes with a dose of 90s insanity. Doors 7 PM. 21+.',
  public_subtitle = 'BONELESS ONES & DÜMSÜRF',
  public_tags = 'live-music',
  ticket_url = 'https://www.tixr.com/groups/mab/events/mabuhaygardens-union-jack-the-rippers-boneless-ones-d-ms-rf-the-mab-197932',
  ticketing_mode = 'external',
  doors_time = '19:00:00',
  age_restriction = '21+',
  public_visibility = 1
WHERE id = 138;

DELETE FROM event_assets WHERE event_id = 138 AND generation_source = 'themab-website-sync';
INSERT INTO event_assets (event_id, asset_type, title, filename, original_filename, file_path, approval_status, generation_source)
VALUES (138, 'flyer', 'Union Jack & The Rippers flyer', 'aug-1-2026-union-jack.jpg', 'aug-1-2026-union-jack.jpg',
        'https://themab.org/wp-content/uploads/2026/07/aug-1-2026-union-jack.jpg', 'approved', 'themab-website-sync');

-- ── KARAOKE SUNDAY — 2026-08-02, On Broadway upstairs (new) ────────────────
INSERT INTO events (venue_id, title, slug, event_type, status, description_public, public_subtitle, public_tags,
                     date, public_visibility, ticketing_mode, room, owner_user_id)
VALUES (@venue_upstairs, 'Karaoke Sunday', 'karaoke-sunday-2026-08-02', 'karaoke', 'published',
        'Grab the mic! Be the star. Join us every Sunday upstairs at On Broadway for karaoke night.',
        'ON BROADWAY (UPSTAIRS)', 'comedy', '2026-08-02', 1, 'external', 'upstairs', @owner_id)
ON DUPLICATE KEY UPDATE
  description_public = VALUES(description_public), public_subtitle = VALUES(public_subtitle),
  public_tags = VALUES(public_tags), public_visibility = VALUES(public_visibility);

DELETE FROM event_assets WHERE event_id IN (SELECT id FROM events WHERE slug = 'karaoke-sunday-2026-08-02') AND generation_source = 'themab-website-sync';
INSERT INTO event_assets (event_id, asset_type, title, filename, original_filename, file_path, approval_status, generation_source)
SELECT id, 'flyer', 'Karaoke Sunday flyer', 'karaoke-sunday-poster.jpg', 'karaoke-sunday-poster.jpg',
       'https://themab.org/wp-content/uploads/2026/06/karaoke-sunday-poster.jpg', 'approved', 'themab-website-sync'
FROM events WHERE slug = 'karaoke-sunday-2026-08-02';

COMMIT;
