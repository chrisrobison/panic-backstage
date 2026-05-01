const { query } = require('../config/db');
const { logActivity } = require('./activityLog');
const slugify = require('slugify');

async function uniqueSlug(base, table = 'events') {
  const root = slugify(base || 'event', { lower: true, strict: true }) || 'event';
  let slug = root;
  let i = 2;
  while ((await query(`SELECT id FROM ${table} WHERE slug = ? LIMIT 1`, [slug])).length) {
    slug = `${root}-${i++}`;
  }
  return slug;
}

async function createEventFromTemplate(templateId, payload, userId) {
  const [template] = await query('SELECT * FROM event_templates WHERE id = ?', [templateId]);
  if (!template) throw new Error('Template not found');
  const date = payload.date;
  const title = payload.title || template.default_title || template.name;
  const slug = await uniqueSlug(`${title}-${date}`);

  const result = await query(
    `INSERT INTO events
      (venue_id, title, slug, event_type, status, description_public, date, doors_time, show_time, age_restriction, ticket_price, owner_user_id)
     VALUES (?, ?, ?, ?, 'proposed', ?, ?, ?, ?, ?, ?, ?)`,
    [
      template.venue_id,
      title,
      slug,
      template.event_type,
      template.default_description_public,
      date,
      payload.doors_time || '19:00',
      payload.show_time || '20:00',
      template.default_age_restriction,
      template.default_ticket_price,
      userId
    ]
  );

  const eventId = result.insertId;
  const checklist = Array.isArray(template.checklist_json) ? template.checklist_json : JSON.parse(template.checklist_json || '[]');
  const schedule = Array.isArray(template.schedule_json) ? template.schedule_json : JSON.parse(template.schedule_json || '[]');
  for (const task of checklist) {
    await query('INSERT INTO event_tasks (event_id, title, priority) VALUES (?, ?, ?)', [eventId, task.title || task, task.priority || 'normal']);
  }
  for (const item of schedule) {
    await query('INSERT INTO event_schedule_items (event_id, title, item_type, start_time, end_time) VALUES (?, ?, ?, ?, ?)', [
      eventId,
      item.title,
      item.item_type || 'other',
      item.start_time || null,
      item.end_time || null
    ]);
  }
  await logActivity(eventId, userId, 'event created from template', { template_id: templateId });
  return eventId;
}

module.exports = { createEventFromTemplate, uniqueSlug };
