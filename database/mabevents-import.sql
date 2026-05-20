-- =============================================================
-- MabEvents.xlsx import (idempotent UPSERT)
-- Run AFTER: schema.sql, migration 001, migration 002
-- Events: 147  |  Staff: 9
--
-- Idempotency keys:
--   users  : email (UNIQUE)            — name refreshed; role/password preserved
--   events : slug  (UNIQUE)            — all fields refreshed EXCEPT status
--                                        (local workflow state wins)
--   event_schedule_items : delete+reinsert per event for ('load_in','curfew')
-- =============================================================

START TRANSACTION;

-- Resolve venue and default owner
SET @venue_id = (SELECT id FROM venues WHERE slug = 'mabuhay-gardens' LIMIT 1);
-- Owner: legacy seed admin if present, else the lowest-id venue_admin.
SET @owner_id = COALESCE(
  (SELECT id FROM users WHERE email = 'admin@mabuhay.local' LIMIT 1),
  (SELECT id FROM users WHERE role = 'venue_admin' ORDER BY id LIMIT 1)
);

-- ── Staff ──────────────────────────────────────────────────────
INSERT INTO users (name, email, password_hash, role) VALUES ('Chale', 'chale@staff.mabuhay.local', NULL, 'staff') ON DUPLICATE KEY UPDATE name = VALUES(name);
INSERT INTO users (name, email, password_hash, role) VALUES ('Will', 'will@staff.mabuhay.local', NULL, 'staff') ON DUPLICATE KEY UPDATE name = VALUES(name);
INSERT INTO users (name, email, password_hash, role) VALUES ('Max', 'max@staff.mabuhay.local', NULL, 'staff') ON DUPLICATE KEY UPDATE name = VALUES(name);
INSERT INTO users (name, email, password_hash, role) VALUES ('Valyre', 'valyre@staff.mabuhay.local', NULL, 'staff') ON DUPLICATE KEY UPDATE name = VALUES(name);
INSERT INTO users (name, email, password_hash, role) VALUES ('Case Newcomb', 'case.newcomb@staff.mabuhay.local', NULL, 'staff') ON DUPLICATE KEY UPDATE name = VALUES(name);
INSERT INTO users (name, email, password_hash, role) VALUES ('DeAnne', 'deanne@staff.mabuhay.local', NULL, 'staff') ON DUPLICATE KEY UPDATE name = VALUES(name);
INSERT INTO users (name, email, password_hash, role) VALUES ('Carmen Caruso', 'carmen.caruso@staff.mabuhay.local', NULL, 'staff') ON DUPLICATE KEY UPDATE name = VALUES(name);
INSERT INTO users (name, email, password_hash, role) VALUES ('Justin Vangegas', 'justin.vangegas@staff.mabuhay.local', NULL, 'staff') ON DUPLICATE KEY UPDATE name = VALUES(name);
INSERT INTO users (name, email, password_hash, role) VALUES ('Christopher/Luigi', 'christopher.luigi@staff.mabuhay.local', NULL, 'staff') ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ── Events ─────────────────────────────────────────────────────
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'J.P. Morgan Healthcare Conference', 'j-p-morgan-healthcare-conference-2025-01-13', 'live_music', 'proposed', NULL, '2025-01-13', NULL, NULL, NULL, 0, @owner_id, 'EVT-1028', 'Tom Watson', 'Steve Echtman', NULL)
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Anthony Arya', 'anthony-arya-2025-09-06', 'private_event', 'hold', NULL, '2025-09-06', NULL, NULL, NULL, 0, @owner_id, 'EVT-1001', 'Tom Watson', NULL, 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Kelley Stoltz', 'kelley-stoltz-2025-10-03', 'live_music', 'canceled', NULL, '2025-10-03', NULL, NULL, NULL, 1, @owner_id, 'EVT-1003', 'Howard Whitehouse', 'Joanna Blanche-Lioce', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Tech Week', 'tech-week-2025-10-06', 'private_event', 'hold', NULL, '2025-10-06', NULL, NULL, NULL, 0, @owner_id, 'EVT-1004', 'Andres Acosta', NULL, 'both')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Anthony Arya', 'anthony-arya-2025-10-11', 'live_music', 'proposed', NULL, '2025-10-11', NULL, NULL, NULL, 0, @owner_id, 'EVT-1005', 'Tom Watson', NULL, 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'SMUGGLER Film Crew', 'smuggler-film-crew-2025-10-17', 'live_music', 'proposed', NULL, '2025-10-17', NULL, NULL, NULL, 0, @owner_id, 'EVT-1006', 'Tom Watson', 'Patrick Milling-Smith', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'SMUGGLER Film Crew', 'smuggler-film-crew-2025-10-18', 'live_music', 'proposed', NULL, '2025-10-18', NULL, NULL, NULL, 0, @owner_id, 'EVT-1007', 'Tom Watson', 'Patrick Milling-Smith', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Psyched! Fest Cumbia Night', 'psyched-fest-cumbia-night-2025-10-25', 'live_music', 'hold', NULL, '2025-10-25', NULL, NULL, NULL, 1, @owner_id, 'EVT-1008', 'Tom Watson', NULL, 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'NEXT Village SF Halloween Fundraiser', 'next-village-sf-halloween-fundraiser-2025-10-26', 'live_music', 'proposed', NULL, '2025-10-26', NULL, NULL, NULL, 0, @owner_id, 'EVT-1009', NULL, 'Kim Rotchy', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Iko Cherie, FAROS, Levi Thomas, DJ Yung Maldita, DJ Alex Niceness', 'iko-cherie-faros-levi-thomas-dj-yung-maldita-dj-alex-niceness-2025-10-26', 'dj_night', 'proposed', NULL, '2025-10-26', '19:00:00', '02:00:00', NULL, 0, @owner_id, 'EVT-1010', NULL, 'Zane Groshelle', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'ImactAlpha', 'imactalpha-2025-10-28', 'live_music', 'proposed', NULL, '2025-10-28', NULL, NULL, NULL, 0, @owner_id, 'EVT-1011', 'Via Bobby Fishkin', 'David Bank/Cesar Chavez', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Psyched! Fest Presents Pink Breath of Heaven, Mayya, El Universo, Demora', 'psyched-fest-presents-pink-breath-of-heaven-mayya-el-universo-demora-2025-10-30', 'live_music', 'proposed', NULL, '2025-10-30', NULL, NULL, NULL, 0, @owner_id, 'EVT-1012', 'Tom Watson- via Bobby Fishkin via Patrick Cavanaugh', 'Psyched! Fest', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'TBA - AJ Macaraeg', 'tba-aj-macaraeg-2025-11-01', 'live_music', 'proposed', NULL, '2025-11-01', NULL, NULL, NULL, 0, @owner_id, 'EVT-1013', NULL, 'AJ Macaraeg', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Psyched! Fest Presents Mengers, RABBIT, Trough and Xocé Román', 'psyched-fest-presents-mengers-rabbit-trough-and-xoc-rom-n-2025-11-02', 'live_music', 'proposed', NULL, '2025-11-02', NULL, NULL, NULL, 0, @owner_id, 'EVT-1014', 'Tom Watson- via Bobby Fishkin via Patrick Cavanaugh', 'Psyched! Fest', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'SAVANT Conference', 'savant-conference-2025-11-06', 'live_music', 'hold', NULL, '2025-11-06', NULL, NULL, NULL, 1, @owner_id, 'EVT-1016', 'Tom Watson', 'Jeson Lee/Steve Echtman', 'both')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'SAVANT Conference', 'savant-conference-2025-11-07', 'live_music', 'confirmed', NULL, '2025-11-07', NULL, NULL, NULL, 0, @owner_id, NULL, 'Tom Watson', 'Jeson Lee/Steve Echtman', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Diamond The Body', 'diamond-the-body-2025-11-07', 'live_music', 'confirmed', NULL, '2025-11-07', '20:00:00', '02:00:00', NULL, 0, @owner_id, 'EVT-1017', 'Eric Roach', NULL, 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Bullseye, Chime School, Slosh, DJ Kid Frostbite', 'bullseye-chime-school-slosh-dj-kid-frostbite-2025-11-07', 'dj_night', 'proposed', NULL, '2025-11-07', '19:00:00', NULL, NULL, 0, @owner_id, 'EVT-1018', NULL, 'Nick Oka/Zack Yackel', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Silver Swoon, The Pranks, Bolero', 'silver-swoon-the-pranks-bolero-2025-11-08', 'live_music', 'proposed', NULL, '2025-11-08', NULL, NULL, NULL, 0, @owner_id, 'EVT-1019', NULL, 'Joseph Canas', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'TBA - Forest Liu', 'tba-forest-liu-2025-11-10', 'live_music', 'hold', NULL, '2025-11-10', '17:00:00', '21:00:00', NULL, 1, @owner_id, 'EVT-1015', 'Andres Acosta', 'Forrest Liu', NULL)
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Alan Onymous', 'alan-onymous-2025-11-13', 'live_music', 'confirmed', NULL, '2025-11-13', NULL, NULL, NULL, 0, @owner_id, 'EVT-1020', NULL, 'Alan Fineberg', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'DJ Dan', 'dj-dan-2025-11-14', 'live_music', 'confirmed', NULL, '2025-11-14', NULL, NULL, NULL, 1, @owner_id, 'EVT-1021', 'Eric Roach', NULL, 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'El Khat, Maz Karandish', 'el-khat-maz-karandish-2025-11-15', 'live_music', 'hold', NULL, '2025-11-15', NULL, NULL, NULL, 1, @owner_id, 'EVT-1022', NULL, 'Nina Sacco', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Pussy Velour, Grrl Band, The Pranks', 'pussy-velour-grrl-band-the-pranks-2025-11-21', 'live_music', 'hold', NULL, '2025-11-21', '17:00:00', '22:30:00', NULL, 0, @owner_id, 'EVT-1023', 'Howard Whitehouse', 'Joanna Blanche-Lioce', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'TBA - Zane Groshelle', 'tba-zane-groshelle-2025-11-22', 'live_music', 'proposed', NULL, '2025-11-22', NULL, NULL, NULL, 0, @owner_id, 'EVT-1033', NULL, 'Zane Groshelle', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'SF Fashion Week FAHRENHEIT', 'sf-fashion-week-fahrenheit-2025-11-26', 'live_music', 'hold', 'Memorial for prominent Bay Area Punk figure Missie Mae.
Ticket system: Door', '2025-11-26', NULL, NULL, NULL, 1, @owner_id, 'EVT-1002', 'Tom Watson', NULL, 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Druski - The Coulda Fest Tour', 'druski-the-coulda-fest-tour-2025-12-05', 'live_music', 'confirmed', NULL, '2025-12-05', NULL, NULL, NULL, 1, @owner_id, 'EVT-1024', 'Eric Roach', NULL, NULL)
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'TikTok Holiday Party (Great Gatsby)', 'tiktok-holiday-party-great-gatsby-2025-12-06', 'live_music', 'hold', NULL, '2025-12-06', NULL, NULL, NULL, 1, @owner_id, 'EVT-1025', 'Tom Watson', 'Madihah Akhter', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'TBA - NYE Celebration', 'tba-nye-celebration-2025-12-31', 'live_music', 'hold', '$15 presale and $20 day of
Ticket system: TIXR
Contract: text group', '2025-12-31', NULL, NULL, NULL, 1, @owner_id, NULL, 'Howard Whitehouse', NULL, NULL)
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Film crafty and mua/wardrobe', 'film-crafty-and-mua-wardrobe-2026-01-09', 'live_music', 'proposed', NULL, '2026-01-09', NULL, NULL, NULL, 0, @owner_id, 'EVT-1034', 'Tom Watson', 'Film crafty and mua/wardrobe', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Saturday afterhours', 'saturday-afterhours-2026-01-10', 'live_music', 'proposed', NULL, '2026-01-10', NULL, NULL, NULL, 0, @owner_id, NULL, 'Hilary', 'Dre', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'J.P. Morgan Healthcare Conference', 'j-p-morgan-healthcare-conference-2026-01-14', 'live_music', 'proposed', NULL, '2026-01-14', NULL, NULL, NULL, 0, @owner_id, NULL, 'Tom Watson', 'Steve Echtman', NULL)
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Elegant Trash', 'elegant-trash-2026-01-14', 'live_music', 'hold', NULL, '2026-01-14', NULL, NULL, NULL, 1, @owner_id, NULL, 'Tom Watson', 'Lee Hoffman', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'J.P. Morgan Healthcare Conference', 'j-p-morgan-healthcare-conference-2026-01-15', 'live_music', 'hold', NULL, '2026-01-15', NULL, NULL, NULL, 1, @owner_id, NULL, 'Tom Watson', 'Steve Echtman', NULL)
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Orlin Mirtchev', 'orlin-mirtchev-2026-01-30', 'live_music', 'proposed', NULL, '2026-01-30', '17:00:00', '23:00:00', NULL, 0, @owner_id, 'EVT-1027', NULL, 'Orlin Mirtchev', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Happy Death Men, False Flag, Surprise Privilege, TBD', 'happy-death-men-false-flag-surprise-privilege-tbd-2026-02-01', 'live_music', 'confirmed', NULL, '2026-02-01', '17:00:00', NULL, NULL, 1, @owner_id, NULL, 'Tom, Daniel, Katrina', 'I Hate Records', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Saturday Morning', 'saturday-morning-2026-02-07', 'live_music', 'hold', NULL, '2026-02-07', '06:00:00', '10:00:00', NULL, 0, @owner_id, NULL, 'Ana + Eric', 'Saturday Morning', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Saturday Morning', 'saturday-morning-2026-02-07-2', 'private_event', 'hold', NULL, '2026-02-07', '06:00:00', '10:00:00', NULL, 0, @owner_id, NULL, 'Ana + Eric', 'Saturday Morning', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Lee Hoffman', 'lee-hoffman-2026-02-11', 'live_music', 'hold', NULL, '2026-02-11', NULL, NULL, NULL, 1, @owner_id, NULL, 'Tom Watson', 'Elegant Trash', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Swing Lessons + Live Band', 'swing-lessons-live-band-2026-02-11', 'special_event', 'hold', NULL, '2026-02-11', NULL, NULL, NULL, 0, @owner_id, NULL, 'Matias', 'Swing Dancing', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Swing Lessons + Live Band', 'swing-lessons-live-band-2026-02-11-2', 'special_event', 'confirmed', NULL, '2026-02-11', NULL, NULL, NULL, 0, @owner_id, NULL, 'Matias', 'Swing Dancing', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'TBA - Valentine’s Ball', 'tba-valentine-s-ball-2026-02-14', 'live_music', 'confirmed', 'Ticket system: Door
Contract: Contract', '2026-02-14', NULL, NULL, NULL, 1, @owner_id, 'EVT-1031', 'Howard Whitehouse', 'Joanna Blanche-Lioce', NULL)
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Dance', 'dance-2026-02-15', 'live_music', 'hold', 'KZ in contract review with Gary Holts manager.
Ticket system: TIXR
Contract: Contract', '2026-02-15', '18:00:00', '00:00:00', NULL, 1, @owner_id, NULL, 'Tom Watson', 'Bachata - dance class', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Karaoke', 'karaoke-2026-02-19', 'karaoke', 'confirmed', NULL, '2026-02-19', '07:00:00', '19:00:00', NULL, 1, @owner_id, NULL, 'Tom Watson', 'Kiet', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Film Crew', 'film-crew-2026-02-19', 'live_music', 'confirmed', 'Ticket system: TIXR', '2026-02-19', '07:00:00', '19:00:00', NULL, 1, @owner_id, NULL, 'Tom Watson', 'Filming', NULL)
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'The Dogs', 'the-dogs-2026-02-21', 'live_music', 'proposed', NULL, '2026-02-21', '19:00:00', NULL, NULL, 0, @owner_id, NULL, 'Daniel Haver', 'Rob Vastano', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Swing Lessons + Live Band', 'swing-lessons-live-band-2026-02-25', 'special_event', 'proposed', NULL, '2026-02-25', NULL, NULL, NULL, 0, @owner_id, NULL, 'Matias', 'Swing Dancing', NULL)
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Noisepop Presents SF Music Week', 'noisepop-presents-sf-music-week-2026-02-28', 'live_music', 'confirmed', 'Contract: Verbal contract', '2026-02-28', '12:00:00', '00:00:00', NULL, 1, @owner_id, 'EVT-1032', 'Tom Watson via Bobby Fishkin via Steve De Angelo', 'Stacy Horne (Flipper)', 'both')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Swing Lessons + Live Band', 'swing-lessons-live-band-2026-03-04', 'special_event', 'confirmed', 'Ticket system: TIXR', '2026-03-04', NULL, NULL, NULL, 1, @owner_id, NULL, 'Matias', 'Swing Dancing', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'https://luma.com/8u07vkhv', 'https-luma-com-8u07vkhv-2026-03-06', 'live_music', 'hold', NULL, '2026-03-06', NULL, NULL, NULL, 0, @owner_id, NULL, 'Bobby Fishkin/ Tom Watson/Andres Acosta', 'Solarpunkification', 'both')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO event_schedule_items (event_id, title, item_type, start_time) VALUES (@eid, 'Load-in', 'load_in', '00:00:00');
INSERT INTO event_schedule_items (event_id, title, item_type, start_time) VALUES (@eid, 'Lock-up / Curfew', 'curfew', '00:00:00');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'https://luma.com/8u07vkhv', 'https-luma-com-8u07vkhv-2026-03-07', 'live_music', 'hold', '3/16 have not yet heard back from the SG team', '2026-03-07', NULL, NULL, NULL, 1, @owner_id, NULL, 'Bobby Fishkin/ Tom Watson/Andres Acosta', 'Solarpunkification', 'both')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'https://luma.com/8u07vkhv', 'https-luma-com-8u07vkhv-2026-03-08', 'live_music', 'hold', NULL, '2026-03-08', NULL, NULL, NULL, 1, @owner_id, NULL, 'Bobby Fishkin/ Tom Watson/Andres Acosta', 'Solarpunkification', 'both')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Swing Lessons + Live Band', 'swing-lessons-live-band-2026-03-11', 'special_event', 'proposed', NULL, '2026-03-11', NULL, NULL, NULL, 0, @owner_id, NULL, 'Matias', 'Cats Corner', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Elegant Trash', 'elegant-trash-2026-03-11', 'live_music', 'confirmed', NULL, '2026-03-11', NULL, NULL, NULL, 0, @owner_id, NULL, 'Tom Watson', 'Lee Hoffman', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Comedy', 'comedy-2026-03-13', 'comedy', 'proposed', NULL, '2026-03-13', '07:00:00', '00:00:00', NULL, 0, @owner_id, NULL, 'Ana + Eric', 'Alex Calleja + Kuya Jobert', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Roman\'s bday - 5 bands', 'roman-s-bday-5-bands-2026-03-14', 'live_music', 'canceled', NULL, '2026-03-14', '06:30:00', '00:00:00', NULL, 1, @owner_id, NULL, 'Katrina & Daniel', 'P.O.S.', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Swing Lessons + Live Band', 'swing-lessons-live-band-2026-03-18', 'special_event', 'proposed', NULL, '2026-03-18', NULL, NULL, NULL, 0, @owner_id, NULL, 'Matias', 'Cats Corner', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Taurus Bash - Punk Spacewalk events', 'taurus-bash-punk-spacewalk-events-2026-03-20', 'live_music', 'confirmed', 'Buy out
Contract: contract document linked, signed PDF with Tom', '2026-03-20', NULL, NULL, NULL, 1, @owner_id, NULL, 'Katrina', 'KAT', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'World Music Event', 'world-music-event-2026-03-20', 'live_music', 'confirmed', NULL, '2026-03-20', NULL, NULL, NULL, 1, @owner_id, NULL, 'Bobby', 'Oshan', 'both')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'DJ event', 'dj-event-2026-03-21', 'dj_night', 'proposed', NULL, '2026-03-21', NULL, NULL, NULL, 0, @owner_id, NULL, 'Ana + Eric', 'Halisi Norfleet', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'DSS - Rise and Bloom', 'dss-rise-and-bloom-2026-03-22', 'live_music', 'hold', NULL, '2026-03-22', '19:00:00', '21:00:00', NULL, 0, @owner_id, NULL, 'Bobby', 'Stephie', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Freestyle', 'freestyle-2026-03-22', 'live_music', 'hold', NULL, '2026-03-22', NULL, NULL, NULL, 1, @owner_id, NULL, 'Katrina & Daniel', 'Chi', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Swing Lessons + Live Band', 'swing-lessons-live-band-2026-03-25', 'special_event', 'confirmed', NULL, '2026-03-25', NULL, NULL, NULL, 1, @owner_id, NULL, 'Matias', 'Cats Corner', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Movie Production', 'movie-production-2026-03-26', 'live_music', 'hold', NULL, '2026-03-26', '07:00:00', '23:00:00', NULL, 0, @owner_id, NULL, 'Katrina', 'Movie Production', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Women\'s History Month', 'women-s-history-month-2026-03-28', 'live_music', 'hold', NULL, '2026-03-28', NULL, NULL, NULL, 1, @owner_id, NULL, 'Bobby Fishkin', 'Cassandra', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Open Day, Collab, with broader event', 'open-day-collab-with-broader-event-2026-03-29', 'live_music', 'canceled', NULL, '2026-03-29', NULL, NULL, NULL, 1, @owner_id, NULL, 'Bobby', 'Bioneers Sunday', NULL)
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'After Party', 'after-party-2026-03-31', 'live_music', 'canceled', NULL, '2026-03-31', '08:30:00', '00:00:00', NULL, 1, @owner_id, NULL, 'Katrina + Daniel', 'City Lights', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Swing Lessons + Live Band', 'swing-lessons-live-band-2026-04-01', 'special_event', 'confirmed', 'Contract: Community event - scope document here', '2026-04-01', NULL, NULL, NULL, 1, @owner_id, NULL, 'Matias', 'Cats Corner', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Private Bday', 'private-bday-2026-04-04', 'live_music', 'confirmed', NULL, '2026-04-04', '19:00:00', '02:00:00', 300, 1, @owner_id, NULL, '', 'Chelsea Freeborn', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'w  70-100+ people for the Yawanawa tribe, 7pm-10 pm April 7th.', 'w-70-100-people-for-the-yawanawa-tribe-7pm-10-pm-april-7th-2026-04-07', 'live_music', 'confirmed', NULL, '2026-04-07', NULL, NULL, NULL, 1, @owner_id, NULL, 'Bobby Fishkin, introduced Tatiana & Erik', 'Mareesa', NULL)
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Swing Lessons + Live Band', 'swing-lessons-live-band-2026-04-08', 'special_event', 'canceled', NULL, '2026-04-08', NULL, NULL, 100, 0, @owner_id, NULL, 'Matias', 'Cats Corner', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Bang The Bay', 'bang-the-bay-2026-04-08', 'private_event', 'confirmed', 'Load in and Load out - erik@erikkatz.com', '2026-04-08', '19:00:00', '23:45:00', 100, 0, @owner_id, NULL, 'Lee Hoffman', 'Lee Hoffman', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Book reading panel', 'book-reading-panel-2026-04-09', 'live_music', 'proposed', NULL, '2026-04-09', '19:00:00', '00:00:00', NULL, 0, @owner_id, NULL, 'Katrina & Daniel', 'Edwin Heaven & Prairie Prince + False Flag', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'DJ', 'dj-2026-04-11', 'live_music', 'confirmed', '! sec, 1 Bartender, DJ sound support - tables being rented by CL', '2026-04-11', '07:30:00', '01:00:00', 1, 1, @owner_id, NULL, 'Sasha & Katrina', 'Jake + DJ Kevvy kev', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO event_schedule_items (event_id, title, item_type, start_time) VALUES (@eid, 'Lock-up / Curfew', 'curfew', '01:30:00');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'DJ', 'dj-2026-04-11-2', 'live_music', 'proposed', 'Need to get deail', '2026-04-11', '07:30:00', '01:00:00', 1, 0, @owner_id, NULL, 'Sasha & Katrina', 'Jake + DJ Kevvy kev', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO event_schedule_items (event_id, title, item_type, start_time) VALUES (@eid, 'Lock-up / Curfew', 'curfew', '01:30:00');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Swing Lessons + Live Band', 'swing-lessons-live-band-2026-04-15', 'special_event', 'hold', '$3000 from 2 source, not prepaid, rev share tickets, + bar minium variable,', '2026-04-15', NULL, NULL, 100, 1, @owner_id, NULL, 'Matias', 'Cats Corner', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'https://luma.com/n3jc2qd5, https://luma.com/tgr5k0e1, https://luma.com/jna2n1iw', 'https-luma-com-n3jc2qd5-https-luma-com-tgr5k0e1-https-luma-com-jna2n1iw-2026-04-17', 'private_event', 'hold', NULL, '2026-04-17', '15:00:00', '23:00:00', NULL, 0, @owner_id, NULL, 'Warm Data Lab ++', 'Sahra, Jessica, Bobby, Erik', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Dance', 'dance-2026-04-19', 'live_music', 'confirmed', NULL, '2026-04-19', NULL, NULL, NULL, 1, @owner_id, NULL, 'Anthony', 'Bachata', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Spacewalk Band', 'spacewalk-band-2026-04-20', 'live_music', 'confirmed', NULL, '2026-04-20', '19:30:00', '13:00:00', NULL, 0, @owner_id, NULL, 'Katrina, Bobby via popo', 'Kat Hotrod', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Swing Lessons + Live Band', 'swing-lessons-live-band-2026-04-22', 'special_event', 'hold', 'Potential revenue: $600', '2026-04-22', NULL, NULL, NULL, 1, @owner_id, NULL, 'Matias', 'Cats Corner', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Swing Lessons + Live Band', 'swing-lessons-live-band-2026-04-29', 'special_event', 'confirmed', 'Contract: Link to contract document', '2026-04-29', NULL, NULL, NULL, 1, @owner_id, NULL, 'Matias', 'Cats Corner', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Trixie Rasputin Presents:  Lazer Beam, Grooblen, The Spiral Electric, plus special guests (TBA)', 'trixie-rasputin-presents-lazer-beam-grooblen-the-spiral-electric-plus-special-guests-tba-2', 'live_music', 'hold', NULL, '2026-05-01', '19:00:00', '00:00:00', NULL, 0, @owner_id, 'EVT-1036', 'Tom Watson', 'Matias Drago', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Mad Kingdom', 'mad-kingdom-2026-05-02', 'live_music', 'confirmed', 'Carmen Caruso  415 874 8623 for sound, no bar needed. Sasha is Floor Managing/Prodcer from Venue
Potential revenue: $4,500
Contract: link to contract document, PDF sent to Tom for counter sig and deposit paid', '2026-05-02', '12:00:00', '00:00:00', NULL, 1, @owner_id, NULL, 'Katrina, Bobby', 'Andrew', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Swing Lessons + Live Band', 'swing-lessons-live-band-2026-05-06', 'special_event', 'confirmed', '$500 Min, + Bar - reveneue
Contract: Verbal MGMT, Matias & Kat working on', '2026-05-06', NULL, NULL, NULL, 1, @owner_id, NULL, 'Matias', 'Cats Corner', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Private Fundraiser', 'private-fundraiser-2026-05-07', 'live_music', 'hold', 'Case Newcomb Sound 718 715 5227', '2026-05-07', NULL, NULL, NULL, 1, @owner_id, NULL, 'Bobby / Katrina', 'Private', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Elizabeth', 'elizabeth-2026-05-09', 'live_music', 'confirmed', 'DeAnne $175 415 613 7113', '2026-05-09', NULL, NULL, NULL, 1, @owner_id, NULL, 'Bobby', 'Elizabeth', NULL)
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Femme Fest', 'femme-fest-2026-05-09', 'live_music', 'hold', NULL, '2026-05-09', NULL, NULL, NULL, 1, @owner_id, NULL, 'Katrina & Daniel', 'Pretty + Natalia', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Swing Lessons + Live Band', 'swing-lessons-live-band-2026-05-13', 'special_event', 'hold', 'Justin Vangegas Sound 925.699.8701
Potential revenue: 500+700', '2026-05-13', NULL, NULL, NULL, 1, @owner_id, NULL, 'Matias', 'Cats Corner', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Bang The Bay', 'bang-the-bay-2026-05-13', 'live_music', 'confirmed', 'Potential revenue: $1,500', '2026-05-13', NULL, NULL, NULL, 1, @owner_id, NULL, 'Tom', 'Lee Hoffman', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'She\'s Geeky', 'she-s-geeky-2026-05-15', 'live_music', 'confirmed', 'approx 50 attendees, catered event from mona lisa
Potential revenue: $2,000', '2026-05-15', '09:00:00', '17:00:00', NULL, 1, @owner_id, NULL, 'Katrina    Bobby', 'Tracey', 'both')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Spunk, POS, Bombshell, Djuna, Madrona', 'spunk-pos-bombshell-djuna-madrona-2026-05-16', 'live_music', 'confirmed', 'Potential revenue: $5,000', '2026-05-16', '18:00:00', '01:00:00', 250, 1, @owner_id, NULL, 'Katrina + Daniel', 'Ava', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Memorial', 'memorial-2026-05-16', 'live_music', 'canceled', 'Needs staffing plan, and schedule cleaning prior to event', '2026-05-16', NULL, NULL, NULL, 1, @owner_id, NULL, 'Katrina', 'Kat Hotrod', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Bachata', 'bachata-2026-05-17', 'special_event', 'confirmed', 'Potential revenue: $1,000', '2026-05-17', '18:00:00', '23:00:00', NULL, 0, @owner_id, NULL, 'Anthony', 'Anthony', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO event_schedule_items (event_id, title, item_type, start_time) VALUES (@eid, 'Load-in', 'load_in', '16:00:00');
INSERT INTO event_schedule_items (event_id, title, item_type, start_time) VALUES (@eid, 'Lock-up / Curfew', 'curfew', '00:00:00');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Karaoke', 'karaoke-2026-05-17', 'karaoke', 'confirmed', 'Needs staffing plan, and schedule cleaning prior to event
Potential revenue: $500', '2026-05-17', '18:00:00', '23:00:00', NULL, 0, @owner_id, NULL, 'Kat', 'House', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Swing Lessons + Live Band', 'swing-lessons-live-band-2026-05-20', 'special_event', 'confirmed', 'Will on bar,
Potential revenue: 500+700', '2026-05-20', '19:00:00', '12:00:00', NULL, 0, @owner_id, NULL, 'Matias', 'Cats Corner', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Major Accident UK + Monsters Squad', 'major-accident-uk-monsters-squad-2026-05-26', 'live_music', 'confirmed', 'Need staffing
Ticket system: EB, Venmo & Door
Potential revenue: 3000 -4000', '2026-05-26', '19:00:00', '12:00:00', NULL, 0, @owner_id, NULL, 'Daniel + Kat', 'D + K', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Swing Lessons + Live Band', 'swing-lessons-live-band-2026-05-27', 'special_event', 'confirmed', 'Potential revenue: 500+700', '2026-05-27', NULL, NULL, NULL, 0, @owner_id, NULL, 'Matias', 'Cats Corner', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Metal', 'metal-2026-05-30', 'live_music', 'confirmed', 'Bar, Security, Door, Floor manager, need gear list - need cleaning', '2026-05-30', NULL, NULL, 250, 0, @owner_id, NULL, 'Katrina', 'Dylan + Holt', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Clown Comedy Show / Syrian SOAP', 'clown-comedy-show-syrian-soap-2026-05-31', 'comedy', 'confirmed', NULL, '2026-05-31', NULL, NULL, 150, 1, @owner_id, NULL, 'EK/ Bobby', 'Collen Barb', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Swing Lessons + Live Band', 'swing-lessons-live-band-2026-06-03', 'special_event', 'confirmed', NULL, '2026-06-03', NULL, NULL, NULL, 1, @owner_id, NULL, 'Matias', 'Cats Corner', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Stanford design party', 'stanford-design-party-2026-06-05', 'private_event', 'confirmed', 'staffing 1 bar + 1 security
Potential revenue: $2,000
Contract: EVT-1051 Stanford design - Google Docs', '2026-06-05', NULL, NULL, 50, 0, @owner_id, NULL, 'Tom', 'Anastasia', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Swing Lessons + Live Band', 'swing-lessons-live-band-2026-06-10', 'special_event', 'confirmed', NULL, '2026-06-10', NULL, NULL, NULL, 0, @owner_id, NULL, 'Matias', 'Cats Corner', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Bang The Bay', 'bang-the-bay-2026-06-10', 'live_music', 'confirmed', 'staffing 1 sec + 1 bar
Potential revenue: need a security person', '2026-06-10', NULL, NULL, NULL, 1, @owner_id, NULL, 'Tom', 'Lee Hoffman', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Anna Jae & Friends Live Recording and Music Release', 'anna-jae-friends-live-recording-and-music-release-2026-06-11', 'live_music', 'hold', 'Ticket system: TIXR
Contract: Tickets- 70/30', '2026-06-11', NULL, NULL, NULL, 1, @owner_id, NULL, 'Stefanie + Sasha', 'Stefanie', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO event_schedule_items (event_id, title, item_type, start_time) VALUES (@eid, 'Load-in', 'load_in', '18:00:00');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'DJ collab - Matthew Holt', 'dj-collab-matthew-holt-2026-06-11', 'live_music', 'proposed', 'Contract: MATT HOLT DJ NIGHT — EVENT ONE SHEET - Google Docs', '2026-06-11', '22:00:00', '02:00:00', NULL, 1, @owner_id, NULL, 'Sasha', 'Matt Holt', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Day Party DJ', 'day-party-dj-2026-06-13', 'live_music', 'proposed', 'Ticket system: TIXR
Potential revenue: $6,500
Contract: DJ DAY PARTY — EVENT ONE SHEET - Google Docs', '2026-06-13', NULL, NULL, NULL, 1, @owner_id, NULL, 'Sasha', 'Conjunction.co', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Immersive sound experience/ sound baths + violin', 'immersive-sound-experience-sound-baths-violin-2026-06-15', 'live_music', 'confirmed', 'Ticket system: Venmo & Door
Potential revenue: Immersive sound experience/ sound baths + violin
Mary, Stefanie, Armin
$25 per. ticket
6:30-9:30pm, doors open, 50% revenue share with producers and promoters
Contract: 50% revenue share with producers and promoters', '2026-06-15', NULL, NULL, NULL, 1, @owner_id, NULL, 'Bobby, Stefanie', 'Mary, Stefanie, Armin,', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Swing Lessons + Live Band', 'swing-lessons-live-band-2026-06-17', 'special_event', 'canceled', 'Will', '2026-06-17', NULL, NULL, NULL, 0, @owner_id, NULL, 'Matias', 'Cats Corner', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Cristina Helgoth', 'cristina-helgoth-2026-06-18', 'live_music', 'canceled', 'Potential revenue: $2,000', '2026-06-18', NULL, NULL, NULL, 0, @owner_id, NULL, 'Tom', 'Cristina Helgoth', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Fentanyl, SPY, POS, Detergent', 'fentanyl-spy-pos-detergent-2026-06-19', 'live_music', 'proposed', 'need staffing
Potential revenue: $6,000', '2026-06-19', '19:00:00', NULL, 450, 1, @owner_id, NULL, 'Katrina & Daniel', 'RNRG - ACE', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Pride Event / Trevor Project Benefit', 'pride-event-trevor-project-benefit-2026-06-20', 'live_music', 'confirmed', 'Ticket system: Eventbrite
Potential revenue: $4,500', '2026-06-20', '21:00:00', '02:00:00', NULL, 1, @owner_id, NULL, 'Bobby Referred / Katrina Producing', 'Sarang RAAT', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Missie Mae Memorial Show: Feat FANG', 'missie-mae-memorial-show-feat-fang-2026-06-21', 'live_music', 'confirmed', 'Potential revenue: Bar Revenue (Merch + Donations)
Contract: https://docs.google.com/document/d/1G4j-6JoY5qNWevT7FMBZRM5Ga1nD4_GTLr-Ib_FfD9s/edit?usp=share_link', '2026-06-21', '17:00:00', '01:45:00', NULL, 1, @owner_id, NULL, 'Katrina', 'Sammietown', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Bachata', 'bachata-2026-06-21', 'special_event', 'proposed', NULL, '2026-06-21', NULL, NULL, NULL, 0, @owner_id, NULL, 'Anthony', NULL, 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Community | Clothing Swap', 'community-clothing-swap-2026-06-21', 'live_music', 'proposed', 'Rescheduling', '2026-06-21', NULL, NULL, NULL, 1, @owner_id, NULL, 'Andres', 'Suzanne Agasi', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Swing Lessons + Live Band', 'swing-lessons-live-band-2026-06-24', 'private_event', 'canceled', 'Potential revenue: 500+1000', '2026-06-24', NULL, NULL, NULL, 0, @owner_id, NULL, 'Matias', 'Cats Corner', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'AI vs. Human Rap Battle', 'ai-vs-human-rap-battle-2026-06-26', 'live_music', 'proposed', 'Ticket system: TIXR
Potential revenue: $1,500
Contract: Harmon - AI vs Human - Google Docs', '2026-06-26', '20:00:00', '22:00:00', NULL, 1, @owner_id, NULL, 'Sasha', 'Harmon Leon', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO event_schedule_items (event_id, title, item_type, start_time) VALUES (@eid, 'Load-in', 'load_in', '19:00:00');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'AI vs. Human Roast Battle', 'ai-vs-human-roast-battle-2026-06-26', 'live_music', 'proposed', NULL, '2026-06-26', NULL, NULL, NULL, 0, @owner_id, NULL, 'Collen/Erik', 'Alt Comedy Society', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Jeffrey Lee Pierce Tribute Show', 'jeffrey-lee-pierce-tribute-show-2026-06-27', 'live_music', 'canceled', NULL, '2026-06-27', NULL, NULL, NULL, 0, @owner_id, NULL, 'Daniel', 'Muhammed Delgado', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Private', 'private-2026-06-27', 'private_event', 'proposed', 'Potential revenue: $6,000
Contract: RANDALL TAYLOR — 60TH BIRTHDAY CELEBRATION - Google Docs', '2026-06-27', '16:00:00', '21:00:00', NULL, 0, @owner_id, NULL, 'Sasha', 'Randall Taylor 60th', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO event_schedule_items (event_id, title, item_type, start_time) VALUES (@eid, 'Load-in', 'load_in', '14:00:00');
INSERT INTO event_schedule_items (event_id, title, item_type, start_time) VALUES (@eid, 'Lock-up / Curfew', 'curfew', '22:00:00');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'DJ -Immersive, community-centered dance experience', 'dj-immersive-community-centered-dance-experience-2026-06-27', 'live_music', 'proposed', 'Potential revenue: $1,500
Contract: Tender Riots One Sheet - Google Docs', '2026-06-27', '18:00:00', '21:30:00', NULL, 1, @owner_id, NULL, 'Sasha', 'Nuna Productions (Leucas)', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO event_schedule_items (event_id, title, item_type, start_time) VALUES (@eid, 'Load-in', 'load_in', '16:00:00');
INSERT INTO event_schedule_items (event_id, title, item_type, start_time) VALUES (@eid, 'Lock-up / Curfew', 'curfew', '22:30:00');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Sapphic Fronted Bands', 'sapphic-fronted-bands-2026-06-27', 'live_music', 'proposed', 'Potential revenue: $1,500
Contract: Mayday Mae! 6/27 Contract - Google Docs', '2026-06-27', '19:00:00', '22:00:00', NULL, 1, @owner_id, NULL, 'Sasha', 'Mayday Mae', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Bang The Bay', 'bang-the-bay-2026-07-08', 'live_music', 'hold', NULL, '2026-07-08', NULL, NULL, NULL, 1, @owner_id, NULL, 'Tom', 'Lee Hoffman', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'resiliant LLc.', 'resiliant-llc-2026-07-11', 'live_music', 'hold', NULL, '2026-07-11', NULL, NULL, NULL, 1, @owner_id, NULL, 'Sasha & Katrina', 'Kevin, Jake, Chi', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'I am Snail (Clown show)', 'i-am-snail-clown-show-2026-07-11', 'comedy', 'hold', NULL, '2026-07-11', NULL, NULL, NULL, 1, @owner_id, NULL, 'Collen/Erik', 'Alt Comedy Society', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Kommunity FK, Altar De Fey, Nervous Gender', 'kommunity-fk-altar-de-fey-nervous-gender-2026-07-11', 'live_music', 'confirmed', NULL, '2026-07-11', NULL, NULL, NULL, 1, @owner_id, NULL, 'Daniel', 'Assisted Living', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Under Fridel\'s wig', 'under-fridel-s-wig-2026-07-19', 'live_music', 'hold', NULL, '2026-07-19', '14:00:00', '15:30:00', NULL, 0, @owner_id, NULL, 'Collen/Erik', 'Alt Comedy Society', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO event_schedule_items (event_id, title, item_type, start_time) VALUES (@eid, 'Load-in', 'load_in', '12:00:00');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Ruby Ibarra', 'ruby-ibarra-2026-07-24', 'live_music', 'proposed', 'Potential revenue: $4,500
Contract: Ruby Ibarra Live - Google Docs', '2026-07-24', '20:00:00', '00:00:00', NULL, 1, @owner_id, NULL, 'Sasha', 'Cassie', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO event_schedule_items (event_id, title, item_type, start_time) VALUES (@eid, 'Load-in', 'load_in', '17:00:00');
INSERT INTO event_schedule_items (event_id, title, item_type, start_time) VALUES (@eid, 'Lock-up / Curfew', 'curfew', '01:00:00');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Leestock 110 bands', 'leestock-110-bands-2026-07-25', 'live_music', 'hold', NULL, '2026-07-25', NULL, NULL, NULL, 1, @owner_id, NULL, 'Tom', 'Lee Hoffman', 'both')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'internship conference', 'internship-conference-2026-07-27', 'private_event', 'confirmed', 'Potential revenue: $3,500', '2026-07-27', '18:00:00', '22:00:00', NULL, 0, @owner_id, NULL, 'Tom', 'Cory Levy', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Vaxxines', 'vaxxines-2026-07-31', 'live_music', 'confirmed', NULL, '2026-07-31', NULL, NULL, NULL, 1, @owner_id, NULL, 'Daniel', 'Rob Vastano + Daniel', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Boneless Ones, Dylan, Kehoe', 'boneless-ones-dylan-kehoe-2026-08-01', 'live_music', 'hold', NULL, '2026-08-01', NULL, NULL, NULL, 1, @owner_id, NULL, 'Katrina & Daniel', 'Dylan + Max', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Thrash/Death', 'thrash-death-2026-08-02', 'live_music', 'proposed', 'Potential revenue: $2,000', '2026-08-02', NULL, NULL, NULL, 1, @owner_id, NULL, 'Tom', 'Cristina Helgoth', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Close To Carl (Clown show + worksop)', 'close-to-carl-clown-show-worksop-2026-08-08', 'comedy', 'hold', NULL, '2026-08-08', '13:00:00', '20:00:00', NULL, 1, @owner_id, NULL, 'Collen/Erik', 'Alt Comedy Society', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO event_schedule_items (event_id, title, item_type, start_time) VALUES (@eid, 'Load-in', 'load_in', '12:00:00');
INSERT INTO event_schedule_items (event_id, title, item_type, start_time) VALUES (@eid, 'Lock-up / Curfew', 'curfew', '21:00:00');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'I Hate Records', 'i-hate-records-2026-08-08', 'live_music', 'hold', NULL, '2026-08-08', NULL, NULL, NULL, 0, @owner_id, NULL, 'Daniel + Kat', 'I Hate Records', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Zanni', 'zanni-2026-08-30', 'live_music', 'proposed', 'Contract: ZANNI CAMPISI PRESENTS: FOUR BAND THRASH / METAL SHOW - Google Docs', '2026-08-30', NULL, NULL, NULL, 0, @owner_id, NULL, 'Sasha, Katrina, Daniel', 'Zanni', 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Brazilian memorial', 'brazilian-memorial-2026-09-11', 'live_music', 'proposed', NULL, '2026-09-11', NULL, NULL, NULL, 0, @owner_id, NULL, 'Daniel + Michael Rosenberg', 'Vic Doublelongo', NULL)
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Punk rock war stories', 'punk-rock-war-stories-2026-09-12', 'live_music', 'proposed', NULL, '2026-09-12', NULL, NULL, NULL, 0, @owner_id, NULL, 'Kathy', 'Jeffrey and friends', NULL)
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Karlie', 'karlie-2026-09-16', 'live_music', 'hold', NULL, '2026-09-16', NULL, NULL, NULL, 1, @owner_id, NULL, 'Tom', 'Karlie', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, '25th marriage anniversary', '25th-marriage-anniversary-2026-09-19', 'live_music', 'hold', 'Potential revenue: $4,000', '2026-09-19', '20:00:00', '01:00:00', 150, 1, @owner_id, NULL, 'Tatiana V', NULL, NULL)
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Stoner Rock/Doom show with KAL-EL, Volume and Temple of the Fuzz Witch and one local.', 'stoner-rock-doom-show-with-kal-el-volume-and-temple-of-the-fuzz-witch-and-one-local-2026-0', 'live_music', 'proposed', NULL, '2026-09-29', NULL, NULL, NULL, 0, @owner_id, NULL, 'Tom', 'Cristina Helgoth', NULL)
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'heavy psych rock band The Well, will pair with two locals.', 'heavy-psych-rock-band-the-well-will-pair-with-two-locals-2026-10-23', 'live_music', 'proposed', NULL, '2026-10-23', NULL, NULL, NULL, 0, @owner_id, NULL, 'Tom', 'Cristina Helgoth', NULL)
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'NEXT Village SF Halloween Fundraiser', 'next-village-sf-halloween-fundraiser-2026-10-26', 'live_music', 'proposed', 'Potential revenue: $6,000', '2026-10-26', NULL, NULL, NULL, 0, @owner_id, 'EVT-1050', 'Tom', 'Kim Rotchy', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Avengers, Seagulls,', 'avengers-seagulls-2026-11-07', 'live_music', 'proposed', NULL, '2026-11-07', NULL, NULL, NULL, 0, @owner_id, NULL, 'Daniel + Kat', 'Rob', 'downstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Scorpio', 'scorpio-2026-11-15', 'live_music', 'proposed', NULL, '2026-11-15', NULL, NULL, NULL, 0, @owner_id, NULL, 'Prom Committee', NULL, NULL)
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Airpusher Collective', 'airpusher-collective-2026-12-20', 'private_event', 'confirmed', NULL, '2026-12-20', NULL, NULL, NULL, 0, @owner_id, 'EVT-1026', 'Bobby Fishkin', NULL, 'upstairs')
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Bikeadellic', 'bikeadellic-2027-04-19', 'live_music', 'hold', NULL, '2027-04-19', NULL, NULL, NULL, 1, @owner_id, NULL, 'Tom/Bobby', 'Oshan', NULL)
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');
INSERT INTO events (venue_id, title, slug, event_type, status, description_internal, date, doors_time, end_time, capacity, public_visibility, owner_user_id, external_id, referral_source, promoter_name, room) VALUES (@venue_id, 'Stoner Psych Rock show with Humulus and Srgt Thunderhoof and two locals.', 'stoner-psych-rock-show-with-humulus-and-srgt-thunderhoof-and-two-locals-2027-02-09', 'live_music', 'proposed', NULL, '2027-02-09', NULL, NULL, NULL, 0, @owner_id, NULL, 'Tom Watson', 'Cristina Helgoth', NULL)
  ON DUPLICATE KEY UPDATE
    id = LAST_INSERT_ID(id),
    venue_id = VALUES(venue_id),
    title = VALUES(title),
    event_type = VALUES(event_type),
    description_internal = VALUES(description_internal),
    date = VALUES(date),
    doors_time = VALUES(doors_time),
    end_time = VALUES(end_time),
    capacity = VALUES(capacity),
    public_visibility = VALUES(public_visibility),
    owner_user_id = VALUES(owner_user_id),
    external_id = VALUES(external_id),
    referral_source = VALUES(referral_source),
    promoter_name = VALUES(promoter_name),
    room = VALUES(room);
SET @eid = LAST_INSERT_ID();
DELETE FROM event_schedule_items WHERE event_id = @eid AND item_type IN ('load_in','curfew');

COMMIT;