const { query } = require('../config/db');

const Event = {
  async findById(id) {
    const rows = await query(
      `SELECT e.*, v.name venue_name, v.address venue_address, v.city venue_city, v.state venue_state,
        u.name owner_name
       FROM events e
       JOIN venues v ON v.id = e.venue_id
       LEFT JOIN users u ON u.id = e.owner_user_id
       WHERE e.id = ?`,
      [id]
    );
    return rows[0];
  },
  async findWorkspace(id) {
    const event = await this.findById(id);
    if (!event) return null;
    const [lineup, tasks, blockers, schedule, assets, settlement, activity, collaborators, users, bands] = await Promise.all([
      query('SELECT el.*, b.name band_name FROM event_lineup el LEFT JOIN bands b ON b.id = el.band_id WHERE el.event_id = ? ORDER BY billing_order, set_time', [id]),
      query('SELECT t.*, u.name assigned_name FROM event_tasks t LEFT JOIN users u ON u.id = t.assigned_user_id WHERE t.event_id = ? ORDER BY FIELD(t.status,"blocked","todo","in_progress","done","canceled"), due_date', [id]),
      query('SELECT b.*, u.name owner_name FROM event_blockers b LEFT JOIN users u ON u.id = b.owner_user_id WHERE b.event_id = ? ORDER BY FIELD(b.status,"open","waiting","resolved","canceled"), due_date', [id]),
      query('SELECT * FROM event_schedule_items WHERE event_id = ? ORDER BY start_time, id', [id]),
      query('SELECT * FROM event_assets WHERE event_id = ? ORDER BY created_at DESC', [id]),
      query('SELECT * FROM event_settlements WHERE event_id = ? LIMIT 1', [id]).then((rows) => rows[0]),
      query('SELECT a.*, u.name user_name FROM event_activity_log a LEFT JOIN users u ON u.id = a.user_id WHERE a.event_id = ? ORDER BY a.created_at DESC LIMIT 50', [id]),
      query('SELECT c.*, u.name, u.email FROM event_collaborators c JOIN users u ON u.id = c.user_id WHERE c.event_id = ? ORDER BY c.role, u.name', [id]),
      query('SELECT id, name, email, role FROM users ORDER BY name'),
      query('SELECT id, name FROM bands ORDER BY name')
    ]);
    return { event, lineup, tasks, blockers, schedule, assets, settlement, activity, collaborators, users, bands };
  }
};

module.exports = Event;
