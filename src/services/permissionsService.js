const { query } = require('../config/db');

const ROLE_CAPABILITIES = {
  venue_admin: ['all'],
  event_owner: ['all_event'],
  promoter: ['overview', 'lineup', 'assets', 'public_copy'],
  band: ['lineup_notes'],
  artist: ['lineup_notes'],
  designer: ['assets'],
  staff: ['tasks', 'schedule'],
  viewer: ['read']
};

async function getEventRole(user, eventId) {
  if (!user) return null;
  if (user.role === 'venue_admin') return 'venue_admin';
  const rows = await query('SELECT role FROM event_collaborators WHERE event_id = ? AND user_id = ? LIMIT 1', [eventId, user.id]);
  return rows[0]?.role || user.role || 'viewer';
}

function can(role, capability) {
  if (!role) return false;
  const caps = ROLE_CAPABILITIES[role] || [];
  return caps.includes('all') || caps.includes('all_event') || caps.includes(capability);
}

function canEditEventRole(role) {
  return ['venue_admin', 'event_owner', 'promoter'].includes(role);
}

module.exports = { getEventRole, can, canEditEventRole };
