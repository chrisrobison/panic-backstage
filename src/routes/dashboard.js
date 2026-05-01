const express = require('express');
const { requireAuth } = require('../middleware/auth');
const { query } = require('../config/db');

const router = express.Router();
router.use(requireAuth);

router.get('/', async (req, res) => {
  const events = await query(
    `SELECT e.*, u.name owner_name,
      (SELECT title FROM event_blockers b WHERE b.event_id = e.id AND b.status IN ('open','waiting') ORDER BY due_date, id LIMIT 1) primary_blocker,
      (SELECT COUNT(*) FROM event_tasks t WHERE t.event_id = e.id AND t.status NOT IN ('done','canceled')) incomplete_tasks,
      (SELECT COUNT(*) FROM event_assets a WHERE a.event_id = e.id AND a.asset_type = 'flyer' AND a.approval_status = 'approved') approved_flyers
     FROM events e
     LEFT JOIN users u ON u.id = e.owner_user_id
     WHERE e.date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)
     ORDER BY e.date, e.show_time`,
    []
  );
  const cards = {
    empty: await query("SELECT COUNT(*) count FROM events WHERE date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY) AND status IN ('empty','hold')").then((r) => r[0].count),
    needsAssets: await query("SELECT COUNT(*) count FROM events WHERE status IN ('confirmed','needs_assets') AND id NOT IN (SELECT event_id FROM event_assets WHERE asset_type='flyer' AND approval_status='approved')").then((r) => r[0].count),
    ready: await query("SELECT COUNT(*) count FROM events WHERE status = 'ready_to_announce'").then((r) => r[0].count),
    blockers: await query("SELECT COUNT(DISTINCT event_id) count FROM event_blockers WHERE status IN ('open','waiting')").then((r) => r[0].count),
    published: await query("SELECT COUNT(*) count FROM events WHERE status = 'published' AND date >= CURDATE()").then((r) => r[0].count),
    unsettled: await query("SELECT COUNT(*) count FROM events e LEFT JOIN event_settlements s ON s.event_id = e.id WHERE e.status = 'completed' AND s.id IS NULL").then((r) => r[0].count)
  };
  res.render('dashboard/index', { title: 'Dashboard', events, cards });
});

module.exports = router;
