const express = require('express');
const { requireAuth } = require('../middleware/auth');
const { query } = require('../config/db');
const Venue = require('../models/Venue');

const router = express.Router();
router.use(requireAuth);

const eventTypes = ['live_music', 'karaoke', 'open_mic', 'promoter_night', 'dj_night', 'comedy', 'private_event', 'special_event'];

router.get('/', async (req, res) => {
  const templates = await query('SELECT t.*, v.name venue_name FROM event_templates t JOIN venues v ON v.id = t.venue_id ORDER BY t.name');
  res.render('templates/index', { title: 'Templates', templates });
});

router.get('/new', async (req, res) => {
  res.render('templates/form', { title: 'New Template', venues: await Venue.findAll(), eventTypes });
});

router.post('/', async (req, res) => {
  await query(
    'INSERT INTO event_templates (venue_id, name, event_type, default_title, default_description_public, default_ticket_price, default_age_restriction, checklist_json, schedule_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
    [req.body.venue_id, req.body.name, req.body.event_type, req.body.default_title, req.body.default_description_public, req.body.default_ticket_price || 0, req.body.default_age_restriction, req.body.checklist_json || '[]', req.body.schedule_json || '[]']
  );
  res.redirect('/templates');
});

module.exports = router;
