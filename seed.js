const fs = require('fs');
const path = require('path');
const mysql = require('mysql2/promise');
const bcrypt = require('bcrypt');
require('dotenv').config();

const dbName = process.env.DB_NAME || 'mabuhay_show_pipeline';

const templates = [
  {
    name: 'Punk Rock Karaoke Night',
    event_type: 'karaoke',
    default_title: 'Punk Rock Karaoke',
    default_description_public: 'Grab the mic and sing loud. A weekly punk karaoke night for regulars, first-timers, and anyone ready for the chorus.',
    default_ticket_price: 0,
    default_age_restriction: '21+',
    tasks: ['Confirm KJ/host', 'Confirm song catalog', 'Confirm projection/display setup', 'Confirm microphones', 'Create/update flyer', 'Publish event page', 'Post to social media', 'Confirm door/staff coverage', 'Print or export signup sheet'],
    schedule: [['Staff call', 'staff_call', '18:30'], ['KJ setup', 'other', '19:00'], ['Doors', 'doors', '20:00'], ['Karaoke starts', 'set', '21:00'], ['Last call for singers', 'other', '23:30'], ['Event end', 'curfew', '00:00']]
  },
  {
    name: 'Three-Band Local Show',
    event_type: 'live_music',
    default_title: 'Local Band Showcase',
    default_description_public: 'Three local bands, one loud night on Broadway.',
    default_ticket_price: 12,
    default_age_restriction: '21+',
    tasks: ['Confirm headliner', 'Confirm support bands', 'Collect band bios/photos/logos', 'Confirm ticket price', 'Confirm load-in', 'Confirm backline needs', 'Create flyer', 'Approve flyer', 'Publish event page', 'Configure ticket link', 'Post social promo', 'Confirm door staff', 'Create night-of-show run sheet', 'Settle payouts'],
    schedule: [['Load-in', 'load_in', '17:00'], ['Soundcheck', 'soundcheck', '18:00'], ['Doors', 'doors', '20:00'], ['Opener set', 'set', '20:30'], ['Changeover', 'changeover', '21:10'], ['Middle band set', 'set', '21:25'], ['Changeover', 'changeover', '22:05'], ['Headliner set', 'set', '22:20'], ['Curfew/event end', 'curfew', '23:30']]
  },
  {
    name: 'Open Mic Night',
    event_type: 'open_mic',
    default_title: 'Open Mic Night',
    default_description_public: 'A low-pressure night for songs, poems, comedy, and experiments.',
    default_ticket_price: 0,
    default_age_restriction: '21+',
    tasks: ['Confirm host', 'Confirm signup process', 'Confirm equipment', 'Create/update recurring flyer', 'Publish event page', 'Post social reminder', 'Confirm house rules'],
    schedule: [['Staff call', 'staff_call', '18:30'], ['Signup opens', 'other', '19:00'], ['Doors', 'doors', '19:30'], ['Open mic starts', 'set', '20:00'], ['Event end', 'curfew', '23:00']]
  },
  {
    name: 'Promoter Night',
    event_type: 'promoter_night',
    default_title: 'Promoter Night',
    default_description_public: 'A promoter-led bill with door terms and guest list rules confirmed in advance.',
    default_ticket_price: 15,
    default_age_restriction: '21+',
    tasks: ['Confirm promoter agreement', 'Confirm lineup', 'Confirm ticket split/door split', 'Collect flyer', 'Approve public copy', 'Publish event page', 'Confirm guest list rules', 'Confirm door settlement process', 'Confirm staff and sound'],
    schedule: [['Load-in', 'load_in', '18:00'], ['Doors', 'doors', '21:00'], ['First act', 'set', '21:30'], ['Event end', 'curfew', '01:00']]
  },
  {
    name: 'Special Legacy Event',
    event_type: 'special_event',
    default_title: 'Special Legacy Event',
    default_description_public: 'A special event honoring the venue history with invited guests and legacy performers.',
    default_ticket_price: 25,
    default_age_restriction: '21+',
    tasks: ['Confirm performer/guest', 'Confirm ticket price', 'Confirm press copy', 'Confirm flyer/poster', 'Approve announcement', 'Publish event page', 'Configure ticketing', 'Confirm guest list/VIP list', 'Confirm photographer', 'Confirm settlement terms', 'Prepare post-event recap'],
    schedule: [['Staff call', 'staff_call', '17:00'], ['VIP doors', 'doors', '18:30'], ['Program starts', 'set', '19:30'], ['Event end', 'curfew', '23:00']]
  }
];

function addDays(days) {
  const d = new Date();
  d.setDate(d.getDate() + days);
  return d.toISOString().slice(0, 10);
}

async function main() {
  const root = await mysql.createConnection({
    host: process.env.DB_HOST || '127.0.0.1',
    port: Number(process.env.DB_PORT || 3306),
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASSWORD || '',
    multipleStatements: true
  });
  await root.query(fs.readFileSync(path.join(__dirname, 'schema.sql'), 'utf8'));
  await root.end();

  const db = await mysql.createConnection({
    host: process.env.DB_HOST || '127.0.0.1',
    port: Number(process.env.DB_PORT || 3306),
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASSWORD || '',
    database: dbName
  });

  await db.query('SET FOREIGN_KEY_CHECKS=0');
  for (const table of ['event_activity_log','event_invites','event_settlements','event_schedule_items','event_assets','event_blockers','event_tasks','event_lineup','bands','event_collaborators','events','event_templates','venues','users']) {
    await db.query(`TRUNCATE TABLE ${table}`);
  }
  await db.query('SET FOREIGN_KEY_CHECKS=1');

  const hash = await bcrypt.hash('changeme', 12);
  const [admin] = await db.query('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)', ['Mabuhay Admin', 'admin@mabuhay.local', hash, 'venue_admin']);
  const adminId = admin.insertId;
  const [venue] = await db.query('INSERT INTO venues (name, slug, address, city, state, timezone) VALUES (?, ?, ?, ?, ?, ?)', ['Mabuhay Gardens', 'mabuhay-gardens', '443 Broadway', 'San Francisco', 'CA', 'America/Los_Angeles']);
  const venueId = venue.insertId;

  for (const t of templates) {
    await db.query(
      'INSERT INTO event_templates (venue_id, name, event_type, default_title, default_description_public, default_ticket_price, default_age_restriction, checklist_json, schedule_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
      [venueId, t.name, t.event_type, t.default_title, t.default_description_public, t.default_ticket_price, t.default_age_restriction, JSON.stringify(t.tasks.map((title) => ({ title }))), JSON.stringify(t.schedule.map(([title, item_type, start_time]) => ({ title, item_type, start_time })))]
    );
  }

  const events = [
    ['Punk Rock Karaoke', 'punk-rock-karaoke', 'karaoke', 'published', addDays(1), '20:00', '21:00', 0, 1],
    ['Local Band Showcase', 'local-band-showcase', 'live_music', 'confirmed', addDays(3), '19:00', '20:00', 12, 0],
    ['Open Mic Night', 'open-mic-night', 'open_mic', 'needs_assets', addDays(5), '19:00', '20:00', 0, 0],
    ['Promoter Night', 'promoter-night', 'promoter_night', 'ready_to_announce', addDays(8), '21:00', '21:30', 15, 0],
    ['Empty/Hold Night', 'empty-hold-night', 'special_event', 'hold', addDays(10), null, null, 0, 0]
  ];
  const eventIds = [];
  for (const e of events) {
    const [result] = await db.query(
      `INSERT INTO events (venue_id, title, slug, event_type, status, description_public, description_internal, date, doors_time, show_time, age_restriction, ticket_price, public_visibility, owner_user_id)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '21+', ?, ?, ?)`,
      [venueId, e[0], e[1], e[2], e[3], `${e[0]} at Mabuhay Gardens.`, 'Seeded MVP event.', e[4], e[5], e[6], e[7], e[8], adminId]
    );
    eventIds.push(result.insertId);
    await db.query('INSERT INTO event_activity_log (event_id, user_id, action, details_json) VALUES (?, ?, ?, ?)', [result.insertId, adminId, 'event created', '{}']);
  }

  const [bandA] = await db.query('INSERT INTO bands (name, contact_email, instagram_url, bio) VALUES (?, ?, ?, ?)', ['The Broadway Static', 'static@example.com', 'https://instagram.com/broadwaystatic', 'Loud local punk.']);
  const [bandB] = await db.query('INSERT INTO bands (name, contact_email, bio) VALUES (?, ?, ?)', ['North Beach Feedback', 'feedback@example.com', 'Garage rock from San Francisco.']);
  await db.query('INSERT INTO event_lineup (event_id, band_id, billing_order, display_name, set_time, set_length_minutes, payout_terms, status) VALUES (?, ?, 1, ?, "20:30", 40, "Door split", "confirmed"), (?, ?, 2, ?, "21:30", 45, "Door split", "tentative")', [eventIds[1], bandA.insertId, 'The Broadway Static', eventIds[1], bandB.insertId, 'North Beach Feedback']);
  await db.query('INSERT INTO event_tasks (event_id, title, status, priority, due_date) VALUES (?, "Approve flyer", "todo", "high", ?), (?, "Configure ticket link", "todo", "high", ?), (?, "Confirm host", "done", "normal", ?)', [eventIds[1], addDays(1), eventIds[1], addDays(2), eventIds[2], addDays(3)]);
  await db.query('INSERT INTO event_blockers (event_id, title, description, owner_user_id, status, due_date) VALUES (?, "Waiting on headliner confirmation", "Need final yes before announcing.", ?, "open", ?)', [eventIds[1], adminId, addDays(1)]);
  await db.query('INSERT INTO event_schedule_items (event_id, title, item_type, start_time) VALUES (?, "Load-in", "load_in", "17:00"), (?, "Doors", "doors", "19:00"), (?, "Opener set", "set", "20:30")', [eventIds[1], eventIds[1], eventIds[1]]);

  await db.end();
  console.log('Seed complete. Login: admin@mabuhay.local / changeme');
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
