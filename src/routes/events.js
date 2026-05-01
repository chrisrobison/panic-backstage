const fs = require('fs');
const path = require('path');
const express = require('express');
const multer = require('multer');
const slugify = require('slugify');
const { requireAuth } = require('../middleware/auth');
const { attachEventRole, requireEventEdit, requireEventCapability, requireSettlementAccess } = require('../middleware/permissions');
const { query } = require('../config/db');
const Event = require('../models/Event');
const Band = require('../models/Band');
const Venue = require('../models/Venue');
const User = require('../models/User');
const { logActivity } = require('../services/activityLog');
const { getNextRecommendedAction } = require('../services/eventActions');
const { uniqueSlug } = require('../services/templateService');
const { createEventFromTemplate } = require('../services/templateService');

const router = express.Router();
router.use(requireAuth);

const upload = multer({
  storage: multer.diskStorage({
    destination(req, file, cb) {
      const dir = path.join(__dirname, '..', '..', 'uploads', 'events', String(req.params.id));
      fs.mkdirSync(dir, { recursive: true });
      cb(null, dir);
    },
    filename(req, file, cb) {
      const ext = path.extname(file.originalname).toLowerCase();
      const base = slugify(path.basename(file.originalname, ext), { lower: true, strict: true }) || 'asset';
      cb(null, `${Date.now()}-${base}${ext}`);
    }
  })
});

const enums = {
  statuses: ['empty', 'proposed', 'hold', 'confirmed', 'needs_assets', 'ready_to_announce', 'published', 'advanced', 'completed', 'settled', 'canceled'],
  types: ['live_music', 'karaoke', 'open_mic', 'promoter_night', 'dj_night', 'comedy', 'private_event', 'special_event'],
  roles: ['venue_admin', 'event_owner', 'promoter', 'band', 'artist', 'designer', 'staff', 'viewer'],
  taskStatuses: ['todo', 'in_progress', 'blocked', 'done', 'canceled'],
  blockerStatuses: ['open', 'waiting', 'resolved', 'canceled'],
  lineupStatuses: ['invited', 'tentative', 'confirmed', 'canceled'],
  scheduleTypes: ['load_in', 'soundcheck', 'doors', 'set', 'changeover', 'curfew', 'staff_call', 'other'],
  assetTypes: ['flyer', 'poster', 'band_photo', 'logo', 'social_square', 'social_story', 'press_photo', 'other']
};

router.get('/', async (req, res) => {
  const filters = req.query;
  const where = [];
  const params = [];
  if (filters.status) { where.push('e.status = ?'); params.push(filters.status); }
  if (filters.event_type) { where.push('e.event_type = ?'); params.push(filters.event_type); }
  if (filters.owner_user_id) { where.push('e.owner_user_id = ?'); params.push(filters.owner_user_id); }
  if (filters.public_visibility !== undefined && filters.public_visibility !== '') { where.push('e.public_visibility = ?'); params.push(filters.public_visibility); }
  if (filters.start_date) { where.push('e.date >= ?'); params.push(filters.start_date); }
  if (filters.end_date) { where.push('e.date <= ?'); params.push(filters.end_date); }
  const events = await query(
    `SELECT e.*, u.name owner_name FROM events e LEFT JOIN users u ON u.id = e.owner_user_id
     ${where.length ? `WHERE ${where.join(' AND ')}` : ''}
     ORDER BY e.date DESC, e.show_time DESC LIMIT 200`,
    params
  );
  const users = await User.findAll();
  res.render('events/index', { title: 'Events', events, users, filters, enums });
});

router.get('/new', async (req, res) => {
  res.render('events/form', { title: 'New Event', event: {}, venues: await Venue.findAll(), users: await User.findAll(), enums, action: '/events' });
});

router.post('/', async (req, res) => {
  const body = req.body;
  if (!body.title || !body.date || !body.venue_id || !body.event_type) {
    req.flash('error', 'Title, date, venue, and event type are required.');
    return res.redirect('/events/new');
  }
  const slug = await uniqueSlug(`${body.title}-${body.date}`);
  const result = await query(
    `INSERT INTO events (venue_id, title, slug, event_type, status, description_public, description_internal, date, doors_time, show_time, end_time, age_restriction, ticket_price, ticket_url, capacity, public_visibility, owner_user_id)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
    [body.venue_id, body.title, slug, body.event_type, body.status || 'proposed', body.description_public, body.description_internal, body.date, body.doors_time || null, body.show_time || null, body.end_time || null, body.age_restriction, body.ticket_price || 0, body.ticket_url, body.capacity || null, body.public_visibility ? 1 : 0, body.owner_user_id || req.session.user.id]
  );
  await logActivity(result.insertId, req.session.user.id, 'event created', { title: body.title });
  res.redirect(`/events/${result.insertId}`);
});

router.post('/from-template/:templateId', async (req, res) => {
  if (!req.body.date) {
    req.flash('error', 'Choose a date for the template event.');
    return res.redirect('/templates');
  }
  const eventId = await createEventFromTemplate(req.params.templateId, req.body, req.session.user.id);
  res.redirect(`/events/${eventId}`);
});

router.get('/:id', attachEventRole, async (req, res) => {
  const workspace = await Event.findWorkspace(req.params.id);
  if (!workspace) return res.status(404).render('error', { title: 'Not found', message: 'Event not found.' });
  const nextAction = getNextRecommendedAction(workspace.event, workspace);
  res.render('events/show', { title: workspace.event.title, ...workspace, nextAction, enums });
});

router.get('/:id/edit', requireEventCapability('overview'), async (req, res) => {
  const event = await Event.findById(req.params.id);
  res.render('events/form', { title: `Edit ${event.title}`, event, venues: await Venue.findAll(), users: await User.findAll(), enums, action: `/events/${event.id}` });
});

router.post('/:id', requireEventCapability('overview'), async (req, res) => {
  const b = req.body;
  const old = await Event.findById(req.params.id);
  const oldDate = old.date?.toISOString ? old.date.toISOString().slice(0, 10) : String(old.date);
  const slug = old.title !== b.title || oldDate !== String(b.date) ? await uniqueSlug(`${b.title}-${b.date}`) : old.slug;
  await query(
    `UPDATE events SET venue_id=?, title=?, slug=?, event_type=?, status=?, description_public=?, description_internal=?, date=?, doors_time=?, show_time=?, end_time=?, age_restriction=?, ticket_price=?, ticket_url=?, capacity=?, public_visibility=?, owner_user_id=? WHERE id=?`,
    [b.venue_id, b.title, slug, b.event_type, b.status, b.description_public, b.description_internal, b.date, b.doors_time || null, b.show_time || null, b.end_time || null, b.age_restriction, b.ticket_price || 0, b.ticket_url, b.capacity || null, b.public_visibility ? 1 : 0, b.owner_user_id || null, req.params.id]
  );
  await logActivity(req.params.id, req.session.user.id, b.public_visibility ? 'event updated' : 'public page unpublished', { title: b.title });
  res.redirect(`/events/${req.params.id}`);
});

router.post('/:id/status', requireEventCapability('overview'), async (req, res) => {
  await query('UPDATE events SET status = ? WHERE id = ?', [req.body.status, req.params.id]);
  await logActivity(req.params.id, req.session.user.id, 'status changed', { status: req.body.status });
  res.redirect(`/events/${req.params.id}`);
});

router.post('/:id/lineup', requireEventCapability('lineup'), async (req, res) => {
  const band = req.body.band_name ? await Band.findOrCreateByName(req.body.band_name) : null;
  await query('INSERT INTO event_lineup (event_id, band_id, billing_order, display_name, set_time, set_length_minutes, payout_terms, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
    req.params.id, band?.id || null, req.body.billing_order || 0, req.body.display_name || band?.name, req.body.set_time || null, req.body.set_length_minutes || null, req.body.payout_terms, req.body.status || 'tentative', req.body.notes
  ]);
  await logActivity(req.params.id, req.session.user.id, 'lineup changed', { action: 'added' });
  res.redirect(`/events/${req.params.id}#lineup`);
});

router.post('/:id/lineup/:lineupId', requireEventCapability('lineup'), async (req, res) => {
  const b = req.body;
  await query('UPDATE event_lineup SET billing_order=?, display_name=?, set_time=?, set_length_minutes=?, payout_terms=?, status=?, notes=? WHERE id=? AND event_id=?', [b.billing_order || 0, b.display_name, b.set_time || null, b.set_length_minutes || null, b.payout_terms, b.status, b.notes, req.params.lineupId, req.params.id]);
  await logActivity(req.params.id, req.session.user.id, 'lineup changed', { action: 'updated' });
  res.redirect(`/events/${req.params.id}#lineup`);
});

router.post('/:id/lineup/:lineupId/delete', requireEventCapability('lineup'), async (req, res) => {
  await query('DELETE FROM event_lineup WHERE id=? AND event_id=?', [req.params.lineupId, req.params.id]);
  await logActivity(req.params.id, req.session.user.id, 'lineup changed', { action: 'deleted' });
  res.redirect(`/events/${req.params.id}#lineup`);
});

router.post('/:id/tasks', requireEventCapability('tasks'), async (req, res) => {
  const r = await query('INSERT INTO event_tasks (event_id, title, description, status, assigned_user_id, due_date, priority) VALUES (?, ?, ?, ?, ?, ?, ?)', [req.params.id, req.body.title, req.body.description, req.body.status || 'todo', req.body.assigned_user_id || null, req.body.due_date || null, req.body.priority || 'normal']);
  await logActivity(req.params.id, req.session.user.id, 'task created', { task_id: r.insertId });
  res.redirect(`/events/${req.params.id}#tasks`);
});

router.post('/:id/tasks/:taskId', requireEventCapability('tasks'), async (req, res) => {
  await query('UPDATE event_tasks SET title=?, description=?, status=?, assigned_user_id=?, due_date=?, priority=? WHERE id=? AND event_id=?', [req.body.title, req.body.description, req.body.status, req.body.assigned_user_id || null, req.body.due_date || null, req.body.priority, req.params.taskId, req.params.id]);
  if (req.body.status === 'done') await logActivity(req.params.id, req.session.user.id, 'task completed', { task_id: req.params.taskId });
  res.redirect(`/events/${req.params.id}#tasks`);
});

router.post('/:id/tasks/:taskId/delete', requireEventCapability('tasks'), async (req, res) => {
  await query('DELETE FROM event_tasks WHERE id=? AND event_id=?', [req.params.taskId, req.params.id]);
  res.redirect(`/events/${req.params.id}#tasks`);
});

router.post('/:id/blockers', requireEventEdit, async (req, res) => {
  const r = await query('INSERT INTO event_blockers (event_id, title, description, owner_user_id, status, due_date) VALUES (?, ?, ?, ?, ?, ?)', [req.params.id, req.body.title, req.body.description, req.body.owner_user_id || null, req.body.status || 'open', req.body.due_date || null]);
  await logActivity(req.params.id, req.session.user.id, 'blocker created', { blocker_id: r.insertId });
  res.redirect(`/events/${req.params.id}#blockers`);
});

router.post('/:id/blockers/:blockerId', requireEventEdit, async (req, res) => {
  await query('UPDATE event_blockers SET title=?, description=?, owner_user_id=?, status=?, due_date=? WHERE id=? AND event_id=?', [req.body.title, req.body.description, req.body.owner_user_id || null, req.body.status, req.body.due_date || null, req.params.blockerId, req.params.id]);
  res.redirect(`/events/${req.params.id}#blockers`);
});

router.post('/:id/blockers/:blockerId/resolve', requireEventEdit, async (req, res) => {
  await query("UPDATE event_blockers SET status='resolved' WHERE id=? AND event_id=?", [req.params.blockerId, req.params.id]);
  await logActivity(req.params.id, req.session.user.id, 'blocker resolved', { blocker_id: req.params.blockerId });
  res.redirect(`/events/${req.params.id}#blockers`);
});

router.post('/:id/schedule', requireEventCapability('schedule'), async (req, res) => {
  await query('INSERT INTO event_schedule_items (event_id, title, item_type, start_time, end_time, notes) VALUES (?, ?, ?, ?, ?, ?)', [req.params.id, req.body.title, req.body.item_type || 'other', req.body.start_time || null, req.body.end_time || null, req.body.notes]);
  res.redirect(`/events/${req.params.id}#schedule`);
});

router.post('/:id/schedule/:scheduleId', requireEventCapability('schedule'), async (req, res) => {
  await query('UPDATE event_schedule_items SET title=?, item_type=?, start_time=?, end_time=?, notes=? WHERE id=? AND event_id=?', [req.body.title, req.body.item_type, req.body.start_time || null, req.body.end_time || null, req.body.notes, req.params.scheduleId, req.params.id]);
  res.redirect(`/events/${req.params.id}#schedule`);
});

router.post('/:id/schedule/:scheduleId/delete', requireEventCapability('schedule'), async (req, res) => {
  await query('DELETE FROM event_schedule_items WHERE id=? AND event_id=?', [req.params.scheduleId, req.params.id]);
  res.redirect(`/events/${req.params.id}#schedule`);
});

router.post('/:id/assets', requireEventCapability('assets'), upload.single('asset'), async (req, res) => {
  if (!req.file) {
    req.flash('error', 'Choose a file to upload.');
    return res.redirect(`/events/${req.params.id}#assets`);
  }
  const publicPath = `/uploads/events/${req.params.id}/${req.file.filename}`;
  await query('INSERT INTO event_assets (event_id, asset_type, title, filename, original_filename, file_path, uploaded_by_user_id, approval_status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [req.params.id, req.body.asset_type || 'other', req.body.title || req.file.originalname, req.file.filename, req.file.originalname, publicPath, req.session.user.id, 'needs_review', req.body.notes]);
  await logActivity(req.params.id, req.session.user.id, 'asset uploaded', { filename: req.file.filename });
  res.redirect(`/events/${req.params.id}#assets`);
});

router.post('/:id/assets/:assetId/approve', requireEventCapability('assets'), async (req, res) => {
  await query("UPDATE event_assets SET approval_status='approved' WHERE id=? AND event_id=?", [req.params.assetId, req.params.id]);
  await logActivity(req.params.id, req.session.user.id, 'asset approved', { asset_id: req.params.assetId });
  res.redirect(`/events/${req.params.id}#assets`);
});

router.post('/:id/assets/:assetId/reject', requireEventCapability('assets'), async (req, res) => {
  await query("UPDATE event_assets SET approval_status='rejected' WHERE id=? AND event_id=?", [req.params.assetId, req.params.id]);
  res.redirect(`/events/${req.params.id}#assets`);
});

router.post('/:id/settlement', requireEventEdit, requireSettlementAccess, async (req, res) => {
  const b = req.body;
  await query(
    `INSERT INTO event_settlements (event_id, gross_ticket_sales, tickets_sold, bar_sales, expenses, band_payouts, promoter_payout, venue_net, notes, settled_by_user_id)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE gross_ticket_sales=VALUES(gross_ticket_sales), tickets_sold=VALUES(tickets_sold), bar_sales=VALUES(bar_sales), expenses=VALUES(expenses), band_payouts=VALUES(band_payouts), promoter_payout=VALUES(promoter_payout), venue_net=VALUES(venue_net), notes=VALUES(notes), settled_by_user_id=VALUES(settled_by_user_id)`,
    [req.params.id, b.gross_ticket_sales || 0, b.tickets_sold || 0, b.bar_sales || 0, b.expenses || 0, b.band_payouts || 0, b.promoter_payout || 0, b.venue_net || 0, b.notes, req.session.user.id]
  );
  await logActivity(req.params.id, req.session.user.id, 'settlement saved', {});
  res.redirect(`/events/${req.params.id}#settlement`);
});

module.exports = router;
