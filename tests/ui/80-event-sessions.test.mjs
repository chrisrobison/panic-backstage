// Day-by-Day Schedule panel (event Sessions, issue #8): a multi-day event
// where each day has its own distinct time block, e.g. a two-day workshop
// (Sat 1-5pm, Sun 1-4pm) rather than one continuous overnight-spanning range.
//
// Destructive against a throwaway event only — created here and deleted in a
// `finally` block, same convention as 70-runsheet-populate.test.mjs. Drives
// the real UI end-to-end against src/Events/Sessions.php, and checks the
// server-side events.date/end_date sync via a direct API read.
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

test('Day-by-Day Schedule: add/edit sessions syncs event date range', async (page) => {
  const created = await apiFetch(page, '/events', {
    method: 'POST',
    body: JSON.stringify({
      title: 'PB UI TEST — event sessions (safe to delete)',
      date: '2099-08-22',
      venue_id: 1,
      event_type: 'special_event',
      status: 'proposed',
      doors_time: '13:00',
      end_time: '17:00',
    }),
  });
  const eventId = created.id;
  assert.ok(eventId, 'test event created');

  try {
    await page.openEvent(eventId);
    await page.click('.workspace-tabs a[data-tab="scheduling"]');
    await page.until(`document.querySelector('pb-event-sessions')`);

    assert.includes(
      await page.text('pb-event-sessions'),
      'No day-by-day blocks',
      'empty state shown before any sessions exist'
    );

    // --- Add day 1 via the real form ---
    await page.click('pb-event-sessions [data-add]');
    await page.until(`document.querySelector('pb-event-sessions form[data-add-form]')`);
    await page.setValue('pb-event-sessions form[data-add-form] [name="session_date"]', '2099-08-22');
    await page.setValue('pb-event-sessions form[data-add-form] [name="start_time"]', '13:00');
    await page.setValue('pb-event-sessions form[data-add-form] [name="end_time"]', '17:00');
    await page.setValue('pb-event-sessions form[data-add-form] [name="label"]', 'Day 1');
    await page.click('pb-event-sessions form[data-add-form] button:not([data-cancel-add])');
    await page.until(`document.querySelectorAll('pb-event-sessions .record[data-record]').length >= 1`);

    // --- Add day 2, a day later, with a DIFFERENT end time ---
    await page.click('pb-event-sessions [data-add]');
    await page.until(`document.querySelector('pb-event-sessions form[data-add-form]')`);
    await page.setValue('pb-event-sessions form[data-add-form] [name="session_date"]', '2099-08-23');
    await page.setValue('pb-event-sessions form[data-add-form] [name="start_time"]', '13:00');
    await page.setValue('pb-event-sessions form[data-add-form] [name="end_time"]', '16:00');
    await page.setValue('pb-event-sessions form[data-add-form] [name="label"]', 'Day 2');
    await page.click('pb-event-sessions form[data-add-form] button:not([data-cancel-add])');
    await page.until(`document.querySelectorAll('pb-event-sessions .record[data-record]').length >= 2`);

    const rowsText = await page.eval(`Array.from(document.querySelectorAll('pb-event-sessions .record-view')).map(r => r.textContent).join(' | ')`);
    assert.includes(rowsText, 'Day 1', 'Day 1 row rendered');
    assert.includes(rowsText, 'Day 2', 'Day 2 row rendered');

    // --- Server-side: events.date/end_date should now span both sessions ---
    let event = (await apiFetch(page, `/events/${eventId}`)).event;
    assert.equal(event.date, '2099-08-22', 'event.date synced to first session');
    assert.equal(event.end_date, '2099-08-23', 'event.end_date synced to last session');

    // --- Remove day 2: end_date should re-sync back down ---
    const sessions = (await apiFetch(page, `/events/${eventId}/sessions`)).sessions;
    const day2 = sessions.find((s) => s.session_date === '2099-08-23');
    assert.ok(day2, 'day 2 session row exists server-side');
    await apiFetch(page, `/events/${eventId}/sessions/${day2.id}`, { method: 'DELETE' });

    event = (await apiFetch(page, `/events/${eventId}`)).event;
    assert.equal(event.date, '2099-08-22', 'event.date still the remaining session');
    assert.equal(event.end_date, null, 'event.end_date cleared back to single-day once day 2 removed');
  } finally {
    await apiFetch(page, `/events/${eventId}`, { method: 'DELETE' }).catch(() => {});
  }
});
