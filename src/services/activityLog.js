const { query } = require('../config/db');

async function logActivity(eventId, userId, action, details = {}) {
  await query(
    'INSERT INTO event_activity_log (event_id, user_id, action, details_json) VALUES (?, ?, ?, ?)',
    [eventId, userId || null, action, JSON.stringify(details)]
  );
}

module.exports = { logActivity };
