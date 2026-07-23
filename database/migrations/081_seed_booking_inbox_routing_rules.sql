-- Seed the spec's example routing rules as DATA — routing_rules /
-- routing_rule_versions rows an admin can edit or version further through
-- the Inbox UI — never as hard-coded PHP logic (src/Leads/RoutingEngine.php
-- has zero awareness of any specific person; it only ever reads these
-- rows).
--
-- IMPORTANT — this targets REAL staff at this real venue, not fixture data:
-- `users` on this database already has real accounts for Colleen Piontek,
-- Kathy Favognano, and Katrina Zanotto (all currently role=venue_admin).
-- Every INSERT below is guarded with `EXISTS (SELECT 1 FROM users WHERE
-- email = ...)`, so on a different install (a fresh dev DB, a different
-- venue's tenant) where these specific people don't exist, this migration
-- simply seeds nothing rather than inventing placeholder assignees.
--
-- Deliberately NOT included here: the spec also asks to "seed Katrina as a
-- restricted external booker in development fixtures." That means changing
-- a real, currently-active staff member's `users.role` from venue_admin to
-- promoter — a real access-control change to a real person's real account,
-- on a database that is production with no separate fixtures/dev
-- environment to make that change in safely. That's a deliberate decision
-- for a human operator to make, not something a migration should do
-- silently — see docs/booking-inbox.md for how to do it by hand if wanted.
--
-- Rule precedence (ascending priority, first match wins — see
-- RoutingEngine::route()):
--   10  Comedy / clown / theatrical / experimental-art  -> Colleen
--   15  Cannabis / 4-20 events                          -> Kathy
--   20  Punk / ska (genre-specific)                     -> Kathy
--   25  Metal / hardcore                                -> Katrina
--   40  Corporate & private rentals                     -> general sales/management queue (unassigned, explained)
--   90  General live-music catch-all                    -> Kathy
--  999  Low-confidence classification                   -> unassigned triage queue

-- ── Rule: Comedy & experimental-art -> Colleen ──────────────────────────────
INSERT INTO `routing_rules` (`name`, `description`, `is_active`, `priority`)
SELECT 'Comedy & experimental-art -> Colleen',
       'Comedy, clown, theatrical, and experimental-art inquiries route to Colleen.', 1, 10
WHERE NOT EXISTS (SELECT 1 FROM `routing_rules` WHERE `name` = 'Comedy & experimental-art -> Colleen')
  AND EXISTS (SELECT 1 FROM `users` WHERE `email` = 'this.that.comedy@gmail.com');

INSERT INTO `routing_rule_versions` (`routing_rule_id`, `version_number`, `status`, `conditions_json`, `action_json`, `note`, `published_at`)
SELECT rr.`id`, 1, 'published',
       '{"event_category_in":["comedy","clown","theatrical","experimental_art"]}',
       JSON_OBJECT('assign_to_user_id', (SELECT id FROM `users` WHERE `email` = 'this.that.comedy@gmail.com')),
       'Seed example rule from the Booking Inbox spec.', NOW()
FROM `routing_rules` rr
WHERE rr.`name` = 'Comedy & experimental-art -> Colleen'
  AND NOT EXISTS (SELECT 1 FROM `routing_rule_versions` WHERE `routing_rule_id` = rr.`id`);

UPDATE `routing_rules` rr
JOIN `routing_rule_versions` rv ON rv.`routing_rule_id` = rr.`id` AND rv.`version_number` = 1
SET rr.`current_published_version_id` = rv.`id`
WHERE rr.`name` = 'Comedy & experimental-art -> Colleen' AND rr.`current_published_version_id` IS NULL;

-- ── Rule: Cannabis / 4-20 -> Kathy ───────────────────────────────────────────
INSERT INTO `routing_rules` (`name`, `description`, `is_active`, `priority`)
SELECT 'Cannabis & 4-20 events -> Kathy', 'Cannabis and 4-20-related events route to Kathy.', 1, 15
WHERE NOT EXISTS (SELECT 1 FROM `routing_rules` WHERE `name` = 'Cannabis & 4-20 events -> Kathy')
  AND EXISTS (SELECT 1 FROM `users` WHERE `email` = 'kathotrod@gmail.com');

INSERT INTO `routing_rule_versions` (`routing_rule_id`, `version_number`, `status`, `conditions_json`, `action_json`, `note`, `published_at`)
SELECT rr.`id`, 1, 'published',
       '{"event_category_in":["cannabis_event","4_20","420"]}',
       JSON_OBJECT('assign_to_user_id', (SELECT id FROM `users` WHERE `email` = 'kathotrod@gmail.com')),
       'Seed example rule from the Booking Inbox spec.', NOW()
FROM `routing_rules` rr
WHERE rr.`name` = 'Cannabis & 4-20 events -> Kathy'
  AND NOT EXISTS (SELECT 1 FROM `routing_rule_versions` WHERE `routing_rule_id` = rr.`id`);

UPDATE `routing_rules` rr
JOIN `routing_rule_versions` rv ON rv.`routing_rule_id` = rr.`id` AND rv.`version_number` = 1
SET rr.`current_published_version_id` = rv.`id`
WHERE rr.`name` = 'Cannabis & 4-20 events -> Kathy' AND rr.`current_published_version_id` IS NULL;

-- ── Rule: Punk / ska (genre-specific) -> Kathy ───────────────────────────────
INSERT INTO `routing_rules` (`name`, `description`, `is_active`, `priority`)
SELECT 'Punk & ska -> Kathy', 'Punk and ska inquiries route to Kathy.', 1, 20
WHERE NOT EXISTS (SELECT 1 FROM `routing_rules` WHERE `name` = 'Punk & ska -> Kathy')
  AND EXISTS (SELECT 1 FROM `users` WHERE `email` = 'kathotrod@gmail.com');

INSERT INTO `routing_rule_versions` (`routing_rule_id`, `version_number`, `status`, `conditions_json`, `action_json`, `note`, `published_at`)
SELECT rr.`id`, 1, 'published',
       '{"music_genre_in":["punk","ska"]}',
       JSON_OBJECT('assign_to_user_id', (SELECT id FROM `users` WHERE `email` = 'kathotrod@gmail.com')),
       'Seed example rule from the Booking Inbox spec.', NOW()
FROM `routing_rules` rr
WHERE rr.`name` = 'Punk & ska -> Kathy'
  AND NOT EXISTS (SELECT 1 FROM `routing_rule_versions` WHERE `routing_rule_id` = rr.`id`);

UPDATE `routing_rules` rr
JOIN `routing_rule_versions` rv ON rv.`routing_rule_id` = rr.`id` AND rv.`version_number` = 1
SET rr.`current_published_version_id` = rv.`id`
WHERE rr.`name` = 'Punk & ska -> Kathy' AND rr.`current_published_version_id` IS NULL;

-- ── Rule: Metal / hardcore -> Katrina ────────────────────────────────────────
INSERT INTO `routing_rules` (`name`, `description`, `is_active`, `priority`)
SELECT 'Metal & hardcore -> Katrina', 'Metal and hardcore inquiries route to Katrina.', 1, 25
WHERE NOT EXISTS (SELECT 1 FROM `routing_rules` WHERE `name` = 'Metal & hardcore -> Katrina')
  AND EXISTS (SELECT 1 FROM `users` WHERE `email` = 'kzanotto@stardustguild.co');

INSERT INTO `routing_rule_versions` (`routing_rule_id`, `version_number`, `status`, `conditions_json`, `action_json`, `note`, `published_at`)
SELECT rr.`id`, 1, 'published',
       '{"music_genre_in":["metal","hardcore"]}',
       JSON_OBJECT('assign_to_user_id', (SELECT id FROM `users` WHERE `email` = 'kzanotto@stardustguild.co')),
       'Seed example rule from the Booking Inbox spec.', NOW()
FROM `routing_rules` rr
WHERE rr.`name` = 'Metal & hardcore -> Katrina'
  AND NOT EXISTS (SELECT 1 FROM `routing_rule_versions` WHERE `routing_rule_id` = rr.`id`);

UPDATE `routing_rules` rr
JOIN `routing_rule_versions` rv ON rv.`routing_rule_id` = rr.`id` AND rv.`version_number` = 1
SET rr.`current_published_version_id` = rv.`id`
WHERE rr.`name` = 'Metal & hardcore -> Katrina' AND rr.`current_published_version_id` IS NULL;

-- ── Rule: Corporate & private rentals -> general sales/management queue ─────
-- No specific assignee — the spec asks for "general sales or management
-- queue," not a named person, so this leaves the lead unassigned with a
-- clear routing explanation rather than guessing at a person.
INSERT INTO `routing_rules` (`name`, `description`, `is_active`, `priority`)
SELECT 'Corporate & private rentals -> general queue', 'Corporate and private-rental inquiries go to the general sales/management queue rather than a specific person.', 1, 40
WHERE NOT EXISTS (SELECT 1 FROM `routing_rules` WHERE `name` = 'Corporate & private rentals -> general queue');

INSERT INTO `routing_rule_versions` (`routing_rule_id`, `version_number`, `status`, `conditions_json`, `action_json`, `note`, `published_at`)
SELECT rr.`id`, 1, 'published',
       '{"event_category_in":["corporate","private_event"]}',
       '{"fallback_unassigned":true}',
       'Seed example rule from the Booking Inbox spec.', NOW()
FROM `routing_rules` rr
WHERE rr.`name` = 'Corporate & private rentals -> general queue'
  AND NOT EXISTS (SELECT 1 FROM `routing_rule_versions` WHERE `routing_rule_id` = rr.`id`);

UPDATE `routing_rules` rr
JOIN `routing_rule_versions` rv ON rv.`routing_rule_id` = rr.`id` AND rv.`version_number` = 1
SET rr.`current_published_version_id` = rv.`id`
WHERE rr.`name` = 'Corporate & private rentals -> general queue' AND rr.`current_published_version_id` IS NULL;

-- ── Rule: general live-music catch-all -> Kathy ─────────────────────────────
INSERT INTO `routing_rules` (`name`, `description`, `is_active`, `priority`)
SELECT 'General live-music catch-all -> Kathy', 'Any concert/live-music inquiry not caught by a more specific genre rule above routes to Kathy.', 1, 90
WHERE NOT EXISTS (SELECT 1 FROM `routing_rules` WHERE `name` = 'General live-music catch-all -> Kathy')
  AND EXISTS (SELECT 1 FROM `users` WHERE `email` = 'kathotrod@gmail.com');

INSERT INTO `routing_rule_versions` (`routing_rule_id`, `version_number`, `status`, `conditions_json`, `action_json`, `note`, `published_at`)
SELECT rr.`id`, 1, 'published',
       '{"event_category_in":["concert"]}',
       JSON_OBJECT('assign_to_user_id', (SELECT id FROM `users` WHERE `email` = 'kathotrod@gmail.com')),
       'Seed example rule from the Booking Inbox spec.', NOW()
FROM `routing_rules` rr
WHERE rr.`name` = 'General live-music catch-all -> Kathy'
  AND NOT EXISTS (SELECT 1 FROM `routing_rule_versions` WHERE `routing_rule_id` = rr.`id`);

UPDATE `routing_rules` rr
JOIN `routing_rule_versions` rv ON rv.`routing_rule_id` = rr.`id` AND rv.`version_number` = 1
SET rr.`current_published_version_id` = rv.`id`
WHERE rr.`name` = 'General live-music catch-all -> Kathy' AND rr.`current_published_version_id` IS NULL;

-- ── Rule: low-confidence classification -> unassigned triage queue ──────────
-- No EXISTS-on-a-real-user guard: this one has no assignee at all, so it's
-- safe to seed on any install.
INSERT INTO `routing_rules` (`name`, `description`, `is_active`, `priority`)
SELECT 'Low-confidence -> unassigned triage', 'Any inquiry whose AI classification confidence is 50% or below (or that has no classification at all) is left for a human to triage rather than auto-routed.', 1, 999
WHERE NOT EXISTS (SELECT 1 FROM `routing_rules` WHERE `name` = 'Low-confidence -> unassigned triage');

INSERT INTO `routing_rule_versions` (`routing_rule_id`, `version_number`, `status`, `conditions_json`, `action_json`, `note`, `published_at`)
SELECT rr.`id`, 1, 'published',
       '{"max_confidence":0.5}',
       '{"fallback_unassigned":true}',
       'Seed example rule from the Booking Inbox spec.', NOW()
FROM `routing_rules` rr
WHERE rr.`name` = 'Low-confidence -> unassigned triage'
  AND NOT EXISTS (SELECT 1 FROM `routing_rule_versions` WHERE `routing_rule_id` = rr.`id`);

UPDATE `routing_rules` rr
JOIN `routing_rule_versions` rv ON rv.`routing_rule_id` = rr.`id` AND rv.`version_number` = 1
SET rr.`current_published_version_id` = rv.`id`
WHERE rr.`name` = 'Low-confidence -> unassigned triage' AND rr.`current_published_version_id` IS NULL;
