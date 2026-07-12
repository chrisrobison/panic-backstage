// Run Sheet tab: "Populate from event data" button and "Add preset" dropdown
// (3 Bands / 4 Bands / Staff Only).
//
// Unlike most files in this suite, this test IS destructive — but only against
// a throwaway event it creates for itself and deletes again in a `finally`
// block, so it never touches real production data. It drives the real UI
// (clicks the actual buttons) end-to-end against the API endpoints added in
// src/Events/Schedule.php (from-event-data, from-preset).
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

test('Run Sheet: Populate from event data + preset dropdown', async (page) => {
  // --- Set up a throwaway event with lineup/staffing/time data to pull from ---
  const created = await apiFetch(page, '/events', {
    method: 'POST',
    body: JSON.stringify({
      title: 'PB UI TEST — run sheet populate (safe to delete)',
      date: '2099-06-15',
      venue_id: 1,
      event_type: 'live_music',
      status: 'proposed',
      load_in_time: '16:00',
      doors_time: '19:00',
      end_time: '23:30',
    }),
  });
  const eventId = created.id;
  assert.ok(eventId, 'test event created');

  try {
    await apiFetch(page, `/events/${eventId}/lineup`, {
      method: 'POST',
      body: JSON.stringify({ display_name: 'Test Band One', billing_order: 1, set_time: '20:00', set_length_minutes: 45 }),
    });
    await apiFetch(page, `/events/${eventId}/lineup`, {
      method: 'POST',
      body: JSON.stringify({ display_name: 'Test Band Two', billing_order: 2, set_time: '21:00', set_length_minutes: 45 }),
    });
    await apiFetch(page, `/events/${eventId}/staffing`, {
      method: 'POST',
      body: JSON.stringify({ role: 'security', call_time: '18:00' }),
    });
    await apiFetch(page, `/events/${eventId}/staffing`, {
      method: 'POST',
      body: JSON.stringify({ role: 'bartender', call_time: '18:00' }),
    });

    // Auto-accept confirm() dialogs the buttons raise, without native-dialog CDP plumbing.
    await page.eval('window.confirm = () => true;');

    await page.openEvent(eventId);
    await page.click('.workspace-tabs a[data-tab="schedule"]');
    await page.until(`document.querySelector('#schedule pb-run-sheet')`);

    assert.ok(await page.exists('#schedule [data-populate-schedule]'), '"Populate from event data" button renders');
    assert.ok(await page.exists('#schedule [data-preset]'), 'preset dropdown buttons render');

    // --- Populate from event data ---
    await page.click('#schedule [data-populate-schedule]');
    await page.until(`document.querySelectorAll('#schedule .record[data-record]').length >= 5`);
    let rows = await page.eval(`Array.from(document.querySelectorAll('#schedule .record-view')).map(r => r.textContent)`);
    const joined = rows.join(' | ');
    assert.includes(joined, 'Load In', 'Load In row present');
    assert.includes(joined, 'Doors', 'Doors row present');
    assert.includes(joined, 'Curfew', 'Curfew row present');
    assert.includes(joined, 'Test Band One Set', 'lineup set row present');
    assert.includes(joined, 'Test Band Two Set', 'lineup set row present');
    assert.includes(joined, 'Staff Call', 'grouped staff call row present');
    const countAfterFirst = rows.length;

    // --- Clicking again should be a no-op (dedup on item_type + start_time) ---
    await page.click('#schedule [data-populate-schedule]');
    await page.eval("new Promise(r => setTimeout(r, 400))"); // let refreshSection settle
    rows = await page.eval(`Array.from(document.querySelectorAll('#schedule .record-view')).map(r => r.textContent)`);
    assert.equal(rows.length, countAfterFirst, 'second click adds no duplicate rows');

    // --- Preset dropdown: 3 Bands ---
    await page.click('#schedule .print-menu summary');
    await page.click('#schedule [data-preset="3_bands"]');
    await page.until(`document.querySelectorAll('#schedule .record[data-record]').length > ${countAfterFirst}`);
    rows = await page.eval(`Array.from(document.querySelectorAll('#schedule .record-view')).map(r => r.textContent)`);
    const afterPreset = rows.join(' | ');
    assert.includes(afterPreset, 'Band 1 Set', '3-bands preset added its own Band 1 Set row');
    assert.includes(afterPreset, 'Changeover', '3-bands preset added Changeover rows');
    assert.ok(rows.length > countAfterFirst, 'preset added new rows on top of existing ones (additive, no dedup)');
  } finally {
    await apiFetch(page, `/events/${eventId}`, { method: 'DELETE' }).catch(() => {});
  }
});
