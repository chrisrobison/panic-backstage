-- Migration 004: seed event templates
-- Safe insert-only: no DROP, no DELETE, no TRUNCATE.
-- Each INSERT is guarded by NOT EXISTS so it is safe to re-run.

INSERT INTO event_templates
  (venue_id, name, event_type, default_title, default_description_public,
   default_ticket_price, default_age_restriction, checklist_json, schedule_json)
SELECT v, n, et, dt, ddp, dtp, dar, cj, sj FROM (SELECT
  1                         AS v,
  'Punk Rock Karaoke Night' AS n,
  'karaoke'                 AS et,
  'Punk Rock Karaoke'       AS dt,
  'Grab the mic and sing loud. A weekly punk karaoke night for regulars, first-timers, and anyone ready for the chorus.' AS ddp,
  0.00                      AS dtp,
  '21+'                     AS dar,
  '[{"title":"Confirm KJ/host"},{"title":"Confirm song catalog"},{"title":"Confirm projection/display setup"},{"title":"Confirm microphones"},{"title":"Create/update flyer"},{"title":"Publish event page"},{"title":"Post to social media"},{"title":"Confirm door/staff coverage"},{"title":"Print or export signup sheet"}]' AS cj,
  '[{"title":"Staff call","item_type":"staff_call","start_time":"18:30"},{"title":"KJ setup","item_type":"other","start_time":"19:00"},{"title":"Doors","item_type":"doors","start_time":"20:00"},{"title":"Karaoke starts","item_type":"set","start_time":"21:00"},{"title":"Last call for singers","item_type":"other","start_time":"23:30"},{"title":"Event end","item_type":"curfew","start_time":"00:00"}]' AS sj
) AS t
WHERE NOT EXISTS (SELECT 1 FROM event_templates WHERE name = 'Punk Rock Karaoke Night' AND venue_id = 1);

INSERT INTO event_templates
  (venue_id, name, event_type, default_title, default_description_public,
   default_ticket_price, default_age_restriction, checklist_json, schedule_json)
SELECT v, n, et, dt, ddp, dtp, dar, cj, sj FROM (SELECT
  1                       AS v,
  'Three-Band Local Show' AS n,
  'live_music'            AS et,
  'Local Band Showcase'   AS dt,
  'Three local bands, one loud night on Broadway.' AS ddp,
  12.00                   AS dtp,
  '21+'                   AS dar,
  '[{"title":"Confirm headliner"},{"title":"Confirm support bands"},{"title":"Collect band bios/photos/logos"},{"title":"Confirm ticket price"},{"title":"Confirm load-in"},{"title":"Confirm backline needs"},{"title":"Create flyer"},{"title":"Approve flyer"},{"title":"Publish event page"},{"title":"Configure ticket link"},{"title":"Post social promo"},{"title":"Confirm door staff"},{"title":"Create night-of-show run sheet"},{"title":"Settle payouts"}]' AS cj,
  '[{"title":"Load-in","item_type":"load_in","start_time":"17:00"},{"title":"Soundcheck","item_type":"soundcheck","start_time":"18:00"},{"title":"Doors","item_type":"doors","start_time":"20:00"},{"title":"Opener set","item_type":"set","start_time":"20:30"},{"title":"Changeover","item_type":"changeover","start_time":"21:10"},{"title":"Middle band set","item_type":"set","start_time":"21:25"},{"title":"Changeover","item_type":"changeover","start_time":"22:05"},{"title":"Headliner set","item_type":"set","start_time":"22:20"},{"title":"Curfew/event end","item_type":"curfew","start_time":"23:30"}]' AS sj
) AS t
WHERE NOT EXISTS (SELECT 1 FROM event_templates WHERE name = 'Three-Band Local Show' AND venue_id = 1);

INSERT INTO event_templates
  (venue_id, name, event_type, default_title, default_description_public,
   default_ticket_price, default_age_restriction, checklist_json, schedule_json)
SELECT v, n, et, dt, ddp, dtp, dar, cj, sj FROM (SELECT
  1                AS v,
  'Open Mic Night' AS n,
  'open_mic'       AS et,
  'Open Mic Night' AS dt,
  'A low-pressure night for songs, poems, comedy, and experiments.' AS ddp,
  0.00             AS dtp,
  '21+'            AS dar,
  '[{"title":"Confirm host"},{"title":"Confirm signup process"},{"title":"Confirm equipment"},{"title":"Create/update recurring flyer"},{"title":"Publish event page"},{"title":"Post social reminder"},{"title":"Confirm house rules"}]' AS cj,
  '[{"title":"Staff call","item_type":"staff_call","start_time":"18:30"},{"title":"Signup opens","item_type":"other","start_time":"19:00"},{"title":"Doors","item_type":"doors","start_time":"19:30"},{"title":"Open mic starts","item_type":"set","start_time":"20:00"},{"title":"Event end","item_type":"curfew","start_time":"23:00"}]' AS sj
) AS t
WHERE NOT EXISTS (SELECT 1 FROM event_templates WHERE name = 'Open Mic Night' AND venue_id = 1);

INSERT INTO event_templates
  (venue_id, name, event_type, default_title, default_description_public,
   default_ticket_price, default_age_restriction, checklist_json, schedule_json)
SELECT v, n, et, dt, ddp, dtp, dar, cj, sj FROM (SELECT
  1                AS v,
  'Promoter Night' AS n,
  'promoter_night' AS et,
  'Promoter Night' AS dt,
  'A promoter-led bill with door terms and guest list rules confirmed in advance.' AS ddp,
  15.00            AS dtp,
  '21+'            AS dar,
  '[{"title":"Confirm promoter agreement"},{"title":"Confirm lineup"},{"title":"Confirm ticket split/door split"},{"title":"Collect flyer"},{"title":"Approve public copy"},{"title":"Publish event page"},{"title":"Confirm guest list rules"},{"title":"Confirm door settlement process"},{"title":"Confirm staff and sound"}]' AS cj,
  '[{"title":"Load-in","item_type":"load_in","start_time":"18:00"},{"title":"Doors","item_type":"doors","start_time":"21:00"},{"title":"First act","item_type":"set","start_time":"21:30"},{"title":"Event end","item_type":"curfew","start_time":"01:00"}]' AS sj
) AS t
WHERE NOT EXISTS (SELECT 1 FROM event_templates WHERE name = 'Promoter Night' AND venue_id = 1);

INSERT INTO event_templates
  (venue_id, name, event_type, default_title, default_description_public,
   default_ticket_price, default_age_restriction, checklist_json, schedule_json)
SELECT v, n, et, dt, ddp, dtp, dar, cj, sj FROM (SELECT
  1                      AS v,
  'Special Legacy Event' AS n,
  'special_event'        AS et,
  'Special Legacy Event' AS dt,
  'A special event honoring the venue history with invited guests and legacy performers.' AS ddp,
  25.00                  AS dtp,
  '21+'                  AS dar,
  '[{"title":"Confirm performer/guest"},{"title":"Confirm ticket price"},{"title":"Confirm press copy"},{"title":"Confirm flyer/poster"},{"title":"Approve announcement"},{"title":"Publish event page"},{"title":"Configure ticketing"},{"title":"Confirm guest list/VIP list"},{"title":"Confirm photographer"},{"title":"Confirm settlement terms"},{"title":"Prepare post-event recap"}]' AS cj,
  '[{"title":"Staff call","item_type":"staff_call","start_time":"17:00"},{"title":"VIP doors","item_type":"doors","start_time":"18:30"},{"title":"Program starts","item_type":"set","start_time":"19:30"},{"title":"Event end","item_type":"curfew","start_time":"23:00"}]' AS sj
) AS t
WHERE NOT EXISTS (SELECT 1 FROM event_templates WHERE name = 'Special Legacy Event' AND venue_id = 1);

INSERT INTO event_templates
  (venue_id, name, event_type, default_title, default_description_public,
   default_ticket_price, default_age_restriction, checklist_json, schedule_json)
SELECT v, n, et, dt, ddp, dtp, dar, cj, sj FROM (SELECT
  1                     AS v,
  'Swing Dancing Night' AS n,
  'promoter_night'      AS et,
  'Swing Dancing Night' AS dt,
  'An evening of swing dancing with a beginner lesson followed by social dancing and a live band or DJ. Hosted by an experienced swing dance instructor.' AS ddp,
  10.00                 AS dtp,
  '21+'                 AS dar,
  '[{"title":"Confirm dance host/instructor"},{"title":"Confirm beginner lesson format and length"},{"title":"Confirm band or DJ"},{"title":"Confirm dance floor layout"},{"title":"Create/update flyer"},{"title":"Publish event page"},{"title":"Post social promo"},{"title":"Confirm door/staff coverage"},{"title":"Confirm sound setup and monitor needs"}]' AS cj,
  '[{"title":"Staff call","item_type":"staff_call","start_time":"18:00"},{"title":"Setup and floor clear","item_type":"other","start_time":"18:30"},{"title":"Doors","item_type":"doors","start_time":"19:00"},{"title":"Beginner lesson","item_type":"set","start_time":"19:30"},{"title":"Social dancing starts","item_type":"other","start_time":"20:30"},{"title":"Live band/DJ set","item_type":"set","start_time":"21:00"},{"title":"Event end","item_type":"curfew","start_time":"00:00"}]' AS sj
) AS t
WHERE NOT EXISTS (SELECT 1 FROM event_templates WHERE name = 'Swing Dancing Night' AND venue_id = 1);
