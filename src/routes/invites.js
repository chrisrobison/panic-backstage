const bcrypt = require('bcrypt');
const express = require('express');
const { randomUUID } = require('crypto');
const { requireAuth } = require('../middleware/auth');
const { requireEventEdit } = require('../middleware/permissions');
const { query } = require('../config/db');
const User = require('../models/User');
const Event = require('../models/Event');

const router = express.Router();
const roles = ['venue_admin', 'event_owner', 'promoter', 'band', 'artist', 'designer', 'staff', 'viewer'];

router.get('/events/:id/invites/new', requireAuth, requireEventEdit, async (req, res) => {
  const event = await Event.findById(req.params.id);
  const invites = await query('SELECT * FROM event_invites WHERE event_id = ? ORDER BY created_at DESC', [req.params.id]);
  res.render('events/invite-form', { title: 'Invite collaborator', event, invites, roles });
});

router.post('/events/:id/invites', requireAuth, requireEventEdit, async (req, res) => {
  const token = randomUUID();
  await query('INSERT INTO event_invites (event_id, email, role, token, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 14 DAY))', [req.params.id, req.body.email, req.body.role || 'viewer', token]);
  req.flash('success', `Invite created: /invite/${token}`);
  res.redirect(`/events/${req.params.id}/invites/new`);
});

router.get('/invite/:token', async (req, res) => {
  const rows = await query(
    `SELECT i.*, e.title event_title FROM event_invites i JOIN events e ON e.id = i.event_id
     WHERE i.token = ? AND i.used_at IS NULL AND i.expires_at > NOW() LIMIT 1`,
    [req.params.token]
  );
  const invite = rows[0];
  if (!invite) return res.status(404).render('error', { title: 'Invite unavailable', message: 'This invite is invalid, expired, or already used.' });
  res.render('auth/invite', { title: 'Accept Invite', invite });
});

router.post('/invite/:token', async (req, res) => {
  const rows = await query('SELECT * FROM event_invites WHERE token = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1', [req.params.token]);
  const invite = rows[0];
  if (!invite) {
    req.flash('error', 'Invite unavailable.');
    return res.redirect('/login');
  }
  let user = await User.findByEmail(invite.email);
  if (!user) {
    const hash = await bcrypt.hash(req.body.password || randomUUID(), 12);
    await User.create({ name: req.body.name || invite.email, email: invite.email, password_hash: hash, role: invite.role });
    user = await User.findByEmail(invite.email);
  }
  await query('INSERT IGNORE INTO event_collaborators (event_id, user_id, role) VALUES (?, ?, ?)', [invite.event_id, user.id, invite.role]);
  await query('UPDATE event_invites SET used_at = NOW() WHERE id = ?', [invite.id]);
  req.session.user = { id: user.id, name: user.name, email: user.email, role: user.role };
  res.redirect(`/events/${invite.event_id}`);
});

module.exports = router;
