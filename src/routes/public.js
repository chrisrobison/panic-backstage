const express = require('express');
const { query } = require('../config/db');

const router = express.Router();

router.get('/e/:slug', async (req, res) => {
  const rows = await query(
    `SELECT e.*, v.name venue_name, v.address, v.city, v.state
     FROM events e JOIN venues v ON v.id = e.venue_id
     WHERE e.slug = ? AND e.public_visibility = 1 LIMIT 1`,
    [req.params.slug]
  );
  const event = rows[0];
  if (!event) return res.status(404).render('error', { title: 'Event unavailable', message: 'This event is not public.' });
  const [lineup, flyer] = await Promise.all([
    query("SELECT * FROM event_lineup WHERE event_id = ? AND status != 'canceled' ORDER BY billing_order, set_time", [event.id]),
    query("SELECT * FROM event_assets WHERE event_id = ? AND asset_type = 'flyer' AND approval_status = 'approved' ORDER BY created_at DESC LIMIT 1", [event.id]).then((r) => r[0])
  ]);
  res.render('public/event', { title: event.title, event, lineup, flyer, layout: 'layouts/public' });
});

module.exports = router;
