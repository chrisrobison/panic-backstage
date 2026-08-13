// Event Details/Scheduling tab: recurrence panel — creating a series via
// <pb-recurrence-fields> + "Create recurring events", then extending that
// same series via the new "Add more dates" affordance
// (EventRecurrencePanel in public/assets/event-workspace.js) instead of
// forking a second event_series row. Backend: src/Events/Series.php
// attemptCreate()'s $existingSeriesId branch.
//
// Destructive against its own throwaway event only — deleting the anchor
// event cascades (ON DELETE SET NULL on events.series_id / CASCADE
// elsewhere) to clean up everything this test creates, per the finally block.
import { test, assert } from './harness.mjs';

async function apiFetch(page, path, opts = {}) {
  const token = page.accessToken;
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

function daysFromNow(n) {
  const d = new Date();
  d.setDate(d.getDate() + n);
  return d.toISOString().slice(0, 10);
}

// This runs against the real shared production DB (no separate test DB —
// see dev-environment memory), and the weekly pattern below needs anchorDate,
// +7d and +14d (founding) then +21d (extending) to all be conflict-free at
// venue_id 1 — picking a fixed date risks an unrelated real booking landing
// on one of those and failing the "Create recurring events" click with a 409
// that has nothing to do with this feature. Scan forward for the first
// 4-in-a-row-weekly window that's actually free instead.
async function findFreeWeeklyAnchor(page) {
  const horizonCutoff = daysFromNow(90);
  const booked = await apiFetch(page, `/events?start_date=${daysFromNow(0)}&end_date=${horizonCutoff}`);
  const takenDates = new Set();
  for (const row of booked.events || []) {
    if (Number(row.venue_id) !== 1) continue;
    for (let d = new Date(row.date + 'T12:00:00'), end = new Date((row.end_date || row.date) + 'T12:00:00'); d <= end; d.setDate(d.getDate() + 1)) {
      takenDates.add(d.toISOString().slice(0, 10));
    }
  }
  for (let start = 3; start <= 60; start++) {
    const candidateDates = [0, 7, 14, 21].map((offset) => daysFromNow(start + offset));
    if (candidateDates.every((d) => !takenDates.has(d) && d <= horizonCutoff)) {
      return candidateDates[0];
    }
  }
  throw new Error('Could not find 4 free consecutive weekly slots within the 90-day horizon for this test.');
}

test('Recurrence panel: create a series, then extend it with more dates from a sibling occurrence', async (page) => {
  const anchorDate = await findFreeWeeklyAnchor(page);
  const created = await apiFetch(page, '/events', {
    method: 'POST',
    body: JSON.stringify({
      title: 'PB UI TEST — recurrence extend (safe to delete)',
      date: anchorDate,
      venue_id: 1,
      event_type: 'live_music',
      status: 'proposed',
    }),
  });
  const eventId = created.id;
  assert.ok(eventId, 'test event created');

  try {
    await page.openEvent(eventId);
    await page.click('.workspace-tabs a[data-tab="scheduling"]');
    await page.until(`document.querySelector('#scheduling pb-event-recurrence pb-recurrence-fields')`);

    // --- Found the series: weekly, 2 occurrences ---
    assert.ok(await page.exists('#scheduling [data-create-series]'), '"Create recurring events" button renders before any series exists');
    await page.click('#scheduling pb-recurrence-fields [name="rf_enabled"]');
    await page.setValue('#scheduling pb-recurrence-fields [name="rf_occurrences"]', '2');
    await page.until(`!document.querySelector('#scheduling [data-create-series]').disabled`);
    await page.click('#scheduling [data-create-series]');
    await page.until(`document.querySelector('#scheduling [data-toggle-extend]')`);

    let siblingCount = await page.count('#scheduling .recurrence-siblings li');
    assert.equal(siblingCount, 3, 'series shows 3 members after founding it (anchor + 2 weekly occurrences)');
    assert.ok(!(await page.exists('#scheduling [data-create-series]')), '"Create recurring events" no longer renders once the event is part of a series');

    // --- Extend that same series with 1 more date ---
    assert.ok(await page.exists('#scheduling [data-remove-series]'), '"Remove from series" still renders alongside the new "Add more dates" button');
    await page.click('#scheduling [data-toggle-extend]');
    await page.until(`document.querySelector('#scheduling [data-extend-series]')`);

    // The extend form anchors the new pattern to the series' latest known
    // date (anchor + 14d for a 2-occurrence weekly series), not the page's
    // own event date — confirms the "continues from wherever the series
    // currently ends" behavior regardless of which occurrence page this is.
    const expectedLatest = new Date(anchorDate + 'T12:00:00');
    expectedLatest.setDate(expectedLatest.getDate() + 14);
    const fieldsAnchorDate = await page.eval(`document.querySelector('#scheduling pb-recurrence-fields').anchorDate`);
    assert.equal(fieldsAnchorDate, expectedLatest.toISOString().slice(0, 10), 'extend form anchors to the series\' latest occurrence, not this event\'s own date');

    await page.click('#scheduling [data-extend-form] [name="rf_enabled"]');
    await page.setValue('#scheduling [data-extend-form] [name="rf_occurrences"]', '1');
    await page.until(`!document.querySelector('#scheduling [data-extend-series]').disabled`);
    await page.click('#scheduling [data-extend-series]');
    await page.until(`document.querySelectorAll('#scheduling .recurrence-siblings li').length >= 4`);

    siblingCount = await page.count('#scheduling .recurrence-siblings li');
    assert.equal(siblingCount, 4, 'series shows 4 members after extending (anchor + 2 + 1 extended), not a second series');

    const seriesInfo = await apiFetch(page, `/events/${eventId}/series`);
    assert.equal(seriesInfo.events.length, 4, 'GET /events/{id}/series confirms 4 members server-side');
    assert.ok(seriesInfo.events.every((e) => e.id), 'all 4 members share one event_series row (no fork)');
  } finally {
    // Deleting only the anchor event would leave its siblings (independent
    // rows sharing series_id) and the now-orphaned event_series row behind —
    // unlink every member first (the DELETE /series route auto-deletes the
    // event_series row once the last member is unlinked), then delete each
    // event row.
    const info = await apiFetch(page, `/events/${eventId}/series`).catch(() => null);
    const memberIds = info?.events?.map((e) => e.id) || [eventId];
    for (const id of memberIds) {
      await apiFetch(page, `/events/${id}/series`, { method: 'DELETE' }).catch(() => {});
    }
    for (const id of memberIds) {
      await apiFetch(page, `/events/${id}`, { method: 'DELETE' }).catch(() => {});
    }
  }
});
