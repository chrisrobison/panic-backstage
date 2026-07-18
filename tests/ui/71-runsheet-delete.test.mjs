// Run Sheet tab: deleting a run sheet item via the edit-form "Delete" button.
//
// Destructive only against a throwaway event created and torn down here —
// never touches real production data. Drives the real UI end-to-end against
// the DELETE /events/{id}/schedule/{scheduleId} endpoint in src/Events/Schedule.php.
import { test, assert } from './harness.mjs';

async function apiFetch(page, path, opts = {}) {
  const token = await page.eval("localStorage.getItem('backstage_access_token')");
  const res = await fetch(page.base + '/api' + path, {
    ...opts,
    headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token, ...(opts.headers || {}) },
  });
  const text = await res.text();
  let body = null;
  try { body = text ? JSON.parse(text) : null; } catch { /* non-JSON (e.g. 204) */ }
  if (!res.ok) throw new Error(`${opts.method || 'GET'} ${path} -> ${res.status}: ${text}`);
  return body;
}

test('Run Sheet: delete an item', async (page) => {
  const created = await apiFetch(page, '/events', {
    method: 'POST',
    body: JSON.stringify({
      title: 'PB UI TEST — run sheet delete (safe to delete)',
      date: '2099-06-16',
      venue_id: 1,
      event_type: 'live_music',
      status: 'proposed',
    }),
  });
  const eventId = created.id;
  assert.ok(eventId, 'test event created');

  try {
    const item = await apiFetch(page, `/events/${eventId}/schedule`, {
      method: 'POST',
      body: JSON.stringify({ title: 'Test Delete Me', item_type: 'other', start_time: '20:00' }),
    });
    assert.ok(item.id, 'schedule item created via API');

    // Auto-accept confirm() dialogs the Delete button raises.
    await page.eval('window.confirm = () => true;');

    await page.openEvent(eventId);
    await page.click('.workspace-tabs a[data-tab="schedule"]');
    await page.until(`document.querySelector('#schedule pb-run-sheet')`);
    await page.until(`document.querySelectorAll('#schedule .record[data-record]').length >= 1`);

    let rows = await page.eval(`Array.from(document.querySelectorAll('#schedule .record-view')).map(r => r.textContent)`);
    assert.includes(rows.join(' | '), 'Test Delete Me', 'row renders before delete');

    // Reveal the edit form (pencil) for that row, then click its Delete button.
    await page.click('#schedule [data-record] [data-edit]');
    await page.until(`document.querySelector('#schedule [data-record].editing [data-delete]')`);
    await page.click('#schedule [data-record].editing [data-delete]');

    await page.until(`document.querySelectorAll('#schedule .record[data-record]').length === 0`);
    rows = await page.eval(`Array.from(document.querySelectorAll('#schedule .record-view')).map(r => r.textContent)`);
    assert.equal(rows.length, 0, 'row removed from DOM after delete');

    const remaining = await apiFetch(page, `/events/${eventId}/schedule`);
    // Endpoint may return {schedule:[...]} or a bare array depending on route shape;
    // handle both to keep this test resilient to that detail.
    const list = Array.isArray(remaining) ? remaining : (remaining?.schedule || []);
    assert.equal(list.length, 0, 'item removed server-side too');
  } finally {
    await apiFetch(page, `/events/${eventId}`, { method: 'DELETE' }).catch(() => {});
  }
});
