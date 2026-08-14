// Event Details/Scheduling tab: recurrence panel — a room conflict on one or
// more generated dates no longer rejects the whole "Create recurring
// events"/"Add more dates" submission outright. A preflight check
// (POST /events/{id}/series/conflicts) surfaces which dates collide and with
// which room is free instead, and EventRecurrencePanel opens a "Resolve
// recurring-event conflicts" dialog (event-workspace.js) listing every
// generated date — conflicted ones get a resolution <select> (skip, or move
// to a free room); clean ones are just listed. Backend: src/Events/Series.php
// checkConflicts()/validateSeries()'s $roomOverrides plumbing, and
// EventRowHelpers::findRoomConflict()/availableRoomsFor().
//
// Destructive against its own throwaway fixtures only — the anchor event and
// its two deliberately-conflicting "blocker" events, all deleted in the
// `finally` block (unlinking series membership first, same convention as
// 115-recurrence-extend.test.mjs).
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

// Series::validateSeries()/checkConflicts() always check room conflicts with
// blockingStatuses=null (EventRowHelpers::findRoomConflict()'s "status NOT
// IN ('canceled','empty')" clause) — every live booking blocks, including a
// tentative 'proposed' Hold. That's deliberately stricter than a normal
// single-event create's Hold-vs-Hold exemption (see Events::conflictBlockersFor(),
// and 104-room-conflict.test.mjs's narrower "committed statuses" set, which
// relies on that exemption to create two competing Holds on purpose). This
// scan has to match the series' stricter rule, or it can pick a window that
// looks free but actually has a live Hold sitting in one of the rooms.
const NON_BLOCKING_STATUSES = new Set(['canceled', 'empty']);

// GET /events caps out at 250 rows (ORDER BY date DESC, LIMIT 250 — see
// Events::index()), so a single upfront fetch across the whole 90-day
// horizon can silently truncate the *earliest* dates in that window once a
// venue has more than 250 bookings ahead of it. Query a narrow window per
// candidate instead (just the 4 offsets being considered) so the row count
// per request stays trivially under that cap regardless of how busy the
// venue's calendar is further out.
async function isWindowFree(page, blockingIds, candidateDates) {
  const booked = await apiFetch(page, `/events?start_date=${candidateDates[0]}&end_date=${candidateDates[candidateDates.length - 1]}`);
  for (const row of booked.events || []) {
    if (!blockingIds.has(Number(row.resource_id)) || NON_BLOCKING_STATUSES.has(row.status)) continue;
    const rowStart = row.date;
    const rowEnd = row.end_date || row.date;
    if (candidateDates.some((d) => d >= rowStart && d <= rowEnd)) return false;
  }
  return true;
}

// Finds two ordinary (non-'both') rooms in the same venue plus a run of 4
// free weekly dates where NEITHER room (nor any 'both' room at that venue)
// has a committed booking — so this test can plant its own conflict/
// resolution fixtures without colliding with real production bookings.
// Mirrors findFreeWeeklyAnchor() in 115-recurrence-extend.test.mjs, extended
// to track two rooms instead of one whole venue.
async function findTwoRoomsAndFreeWindow(page) {
  const venueData = await apiFetch(page, '/venues');
  const resources = venueData.resources || [];
  let picked = null;
  for (const venue of venueData.venues || []) {
    const rooms = resources.filter((r) => Number(r.venue_id) === Number(venue.id) && r.zone !== 'both' && r.active);
    if (rooms.length >= 2) { picked = { venue, roomA: rooms[0], roomB: rooms[1] }; break; }
  }
  if (!picked) return null;
  const { venue, roomA, roomB } = picked;
  const bothRoomIds = resources
    .filter((r) => Number(r.venue_id) === Number(venue.id) && r.zone === 'both')
    .map((r) => Number(r.id));
  const blockingIds = new Set([Number(roomA.id), Number(roomB.id), ...bothRoomIds]);

  const horizonCutoff = daysFromNow(90);
  for (let start = 3; start <= 55; start++) {
    const dates = [0, 7, 14, 21].map((offset) => daysFromNow(start + offset));
    if (dates[dates.length - 1] > horizonCutoff) break;
    if (await isWindowFree(page, blockingIds, dates)) {
      return { venue, roomA, roomB, dates };
    }
  }
  return null;
}

test('Recurrence panel: conflict dialog offers skip or move-to-another-room per date', async (page) => {
  const setup = await findTwoRoomsAndFreeWindow(page);
  if (!setup) return page.skip('could not find two rooms + a free 4-week window for any venue');
  const { venue, roomA, roomB, dates } = setup;
  const [d0, d1, d2, d3] = dates;

  const eventBase = {
    venue_id: Number(venue.id), event_type: 'live_music',
    doors_time: '19:00', show_time: '20:00', end_time: '23:00',
    load_in_time: '17:00', load_out_time: '23:30',
  };

  const anchor = await apiFetch(page, '/events', {
    method: 'POST',
    body: JSON.stringify({ ...eventBase, title: 'PB UI TEST — recurrence conflict modal (safe to delete)', date: d0, resource_id: Number(roomA.id), status: 'proposed' }),
  });
  const eventId = anchor.id;
  assert.ok(eventId, 'anchor event created');

  // Occupies roomA on d1 — the pattern below (weekly, 2 occurrences: d1+d2)
  // should surface exactly this one conflict in the dialog, with roomB
  // offered as the free alternative.
  const blockerAtD1 = await apiFetch(page, '/events', {
    method: 'POST',
    body: JSON.stringify({ ...eventBase, title: 'PB UI TEST — recurrence conflict blocker (safe to delete)', date: d1, resource_id: Number(roomA.id), status: 'confirmed' }),
  });
  assert.ok(blockerAtD1.id, 'blocker fixture on the conflicted date created');

  let blockerAtD3Id = null;
  try {
    await page.openEvent(eventId);
    await page.click('.workspace-tabs a[data-tab="scheduling"]');
    await page.until(`document.querySelector('#scheduling pb-event-recurrence pb-recurrence-fields')`);

    // --- Found the series: weekly, 2 occurrences (d1 conflicted, d2 clean) ---
    assert.ok(await page.exists('#scheduling [data-create-series]'), '"Create recurring events" button renders before any series exists');
    await page.click('#scheduling pb-recurrence-fields [name="rf_enabled"]');
    await page.setValue('#scheduling pb-recurrence-fields [name="rf_occurrences"]', '2');
    await page.until(`!document.querySelector('#scheduling [data-create-series]').disabled`);
    await page.click('#scheduling [data-create-series]');

    const scope = '.modal-backdrop:has(.recurrence-conflict-table)';
    await page.until(`document.querySelector('${scope}')`);
    assert.equal(await page.count(`${scope} tr[data-date]`), 2, 'both generated dates are listed in the dialog');
    assert.equal(await page.count(`${scope} tr[data-conflict]`), 1, 'exactly the one conflicted date is flagged');
    assert.includes(
      await page.text(`${scope} tr[data-conflict]`),
      'PB UI TEST — recurrence conflict blocker',
      'the conflict row names the colliding event',
    );
    assert.ok(await page.exists(`${scope} tr[data-conflict] a[href="#event-${blockerAtD1.id}"]`), 'the conflict row links to the colliding event');
    assert.ok(await page.exists(`${scope} tr[data-conflict] select[data-resolution] option[value="${roomB.id}"]`), 'the other, free room is offered as a resolution option');
    assert.notOk(await page.exists(`${scope} tr:not([data-conflict]) select`), 'clean dates have no resolution control');

    // --- Resolve by skipping the conflicted date (the dialog's default) ---
    await page.click(`${scope} [data-resolve-conflicts]`);
    await page.until(`!document.querySelector('${scope}')`);
    await page.until(`document.querySelector('#scheduling [data-toggle-extend]')`);

    let seriesInfo = await apiFetch(page, `/events/${eventId}/series`);
    assert.equal(seriesInfo.events.length, 2, 'skipping the conflicted date leaves only the anchor + the clean date');
    assert.ok(seriesInfo.events.every((e) => e.date !== d1), 'the conflicted date was not created');
    assert.ok(seriesInfo.events.some((e) => e.date === d2), 'the clean date was still created despite the pattern having a conflict');

    // --- Extend the series (continues from d2, so the next weekly date is
    //     d3) onto another conflicted date, resolving it this time by moving
    //     to the other free room instead of skipping ---
    const blockerAtD3 = await apiFetch(page, '/events', {
      method: 'POST',
      body: JSON.stringify({ ...eventBase, title: 'PB UI TEST — recurrence conflict blocker 2 (safe to delete)', date: d3, resource_id: Number(roomA.id), status: 'confirmed' }),
    });
    assert.ok(blockerAtD3.id, 'second blocker fixture created');
    blockerAtD3Id = blockerAtD3.id;

    await page.click('#scheduling [data-toggle-extend]');
    await page.until(`document.querySelector('#scheduling [data-extend-form] pb-recurrence-fields')`);
    await page.click('#scheduling [data-extend-form] [name="rf_enabled"]');
    await page.setValue('#scheduling [data-extend-form] [name="rf_occurrences"]', '1');
    await page.until(`!document.querySelector('#scheduling [data-extend-series]').disabled`);
    await page.click('#scheduling [data-extend-series]');

    await page.until(`document.querySelector('${scope}')`);
    assert.equal(await page.count(`${scope} tr[data-conflict]`), 1, 'the extended date is flagged as conflicted');
    await page.setValue(`${scope} tr[data-conflict] select[data-resolution]`, String(roomB.id));
    await page.click(`${scope} [data-resolve-conflicts]`);
    await page.until(`!document.querySelector('${scope}')`);
    await page.until(`document.querySelectorAll('#scheduling .recurrence-siblings li').length >= 3`);

    seriesInfo = await apiFetch(page, `/events/${eventId}/series`);
    const extended = seriesInfo.events.find((e) => e.date === d3);
    assert.ok(extended, 'the reassigned date was created despite the room conflict');

    const extendedFull = await apiFetch(page, `/events/${extended.id}`);
    assert.equal(Number(extendedFull.event.resource_id), Number(roomB.id), 'the reassigned occurrence actually lands in the alternate room, not the conflicted one');
  } finally {
    const info = await apiFetch(page, `/events/${eventId}/series`).catch(() => null);
    const memberIds = info?.events?.map((e) => e.id) || [eventId];
    for (const id of memberIds) {
      await apiFetch(page, `/events/${id}/series`, { method: 'DELETE' }).catch(() => {});
    }
    for (const id of memberIds) {
      await apiFetch(page, `/events/${id}`, { method: 'DELETE' }).catch(() => {});
    }
    for (const id of [blockerAtD1.id, blockerAtD3Id]) {
      if (id) await apiFetch(page, `/events/${id}`, { method: 'DELETE' }).catch(() => {});
    }
  }
});
