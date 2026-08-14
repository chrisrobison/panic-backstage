// New Event wizard (public/assets/event-wizard.js): the "Recurring Event"
// checkbox on Step 1 spins off a series as part of _finishCreate(), after
// the primary event itself is created. A room conflict on one of the
// generated dates used to just soft-fail with a toast; it now pauses
// _finishCreate() and opens the same "Resolve recurring-event conflicts"
// dialog the Recurrence panel uses (see resolveSeriesConflicts() in
// recurrence.js, shared by both entry points — 116-recurrence-conflict-modal
// covers the Recurrence-panel side of that shared code; this covers the
// wizard's wiring into it). The wizard doesn't navigate to the new event
// until the whole _finish() chain resolves, so the dialog blocks that
// navigation exactly like it blocks form navigation elsewhere.
//
// Destructive against its own throwaway fixtures only: the blocker event
// (created directly via the API before the wizard runs) and whatever the
// wizard itself creates (found afterward via the resulting event's series
// membership), all deleted in the `finally` block.
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

// Same strict rule Series::checkConflicts() uses (see 116's identical
// helper) — every live booking blocks, including a tentative 'proposed' Hold.
const NON_BLOCKING_STATUSES = new Set(['canceled', 'empty']);

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

// Finds one active room plus a run of 3 free weekly dates (anchor, conflict,
// clean) where that room (and any 'both'-zone room at the same venue) has no
// committed booking — narrow per-candidate queries, not one big upfront
// fetch, for the same GET /events 250-row/DESC-order reason 116 documents.
async function findRoomAndFreeWindow(page) {
  const venueData = await apiFetch(page, '/venues');
  const resources = venueData.resources || [];
  let picked = null;
  for (const venue of venueData.venues || []) {
    const room = resources.find((r) => Number(r.venue_id) === Number(venue.id) && r.zone !== 'both' && r.active);
    if (room) { picked = { venue, room }; break; }
  }
  if (!picked) return null;
  const { venue, room } = picked;
  const bothRoomIds = resources
    .filter((r) => Number(r.venue_id) === Number(venue.id) && r.zone === 'both')
    .map((r) => Number(r.id));
  const blockingIds = new Set([Number(room.id), ...bothRoomIds]);

  const horizonCutoff = daysFromNow(90);
  for (let start = 3; start <= 60; start++) {
    const dates = [0, 7, 14].map((offset) => daysFromNow(start + offset));
    if (dates[dates.length - 1] > horizonCutoff) break;
    if (await isWindowFree(page, blockingIds, dates)) {
      return { venue, room, dates };
    }
  }
  return null;
}

test('New Event wizard: a room conflict on a generated date opens the resolution dialog before creating the series', async (page) => {
  const setup = await findRoomAndFreeWindow(page);
  if (!setup) return page.skip('could not find a room + a free 3-week window for any venue');
  const { room, dates: [d0, d1, d2] } = setup;

  const blocker = await apiFetch(page, '/events', {
    method: 'POST',
    body: JSON.stringify({
      title: 'PB UI TEST — wizard conflict blocker (safe to delete)',
      venue_id: Number(room.venue_id), resource_id: Number(room.id),
      event_type: 'live_music', status: 'confirmed', date: d1,
      doors_time: '19:00', show_time: '20:00', end_time: '23:00',
      load_in_time: '17:00', load_out_time: '23:30',
    }),
  });
  assert.ok(blocker.id, 'blocker fixture on the to-be-conflicted date created');

  let createdEventId = null;
  try {
    await page.goto('#new-event');
    await page.until(`document.querySelector('pb-event-wizard [data-wizard-form]')`);

    // --- Step 1: Event Basics — title, date, room, event type, recurrence ---
    await page.setValue('input[name="title"]', 'PB UI TEST — wizard recurrence conflict (safe to delete)');
    await page.setValue('input[name="date"]', d0);
    if (await page.exists('select[name="resource_id"]')) {
      await page.setValue('select[name="resource_id"]', String(room.id));
    }
    await page.setValue('select[name="event_type"]', 'live_music');
    await page.click('pb-recurrence-fields [name="rf_enabled"]');
    await page.setValue('pb-recurrence-fields [name="rf_occurrences"]', '2');
    await page.until(`document.querySelector('pb-event-wizard').wizardData?.recurrence?.dates?.length === 2`);
    await page.click('[data-next]');

    // --- Step 2: Deal Structure — pick Free Event so Counterparty/Deal
    //     Terms (which require more fields) are skipped entirely ---
    await page.until(`document.querySelector('[data-deal-type="free_event"]')`);
    await page.click('[data-deal-type="free_event"]');
    await page.click('[data-next]');

    // --- Remaining steps (Production, Promotion, …) have no required
    //     fields — advance until Review's Finish button appears. Bounded so
    //     a wizard-flow change that removes [data-finish] entirely fails
    //     loudly instead of looping forever.
    for (let guard = 0; guard < 8 && !(await page.exists('[data-finish]')); guard++) {
      await page.until(`document.querySelector('[data-next], [data-finish]')`);
      if (await page.exists('[data-finish]')) break;
      await page.click('[data-next]');
    }
    assert.ok(await page.exists('[data-finish]'), 'reached the Review step\'s Finish button');

    // --- Review step: Finish triggers _finishCreate(), which creates the
    //     event first, then hits the conflict on d1 while spinning off the
    //     series — pausing here until the dialog is resolved ---
    await page.click('[data-finish]');

    const scope = '.modal-backdrop:has(.recurrence-conflict-table)';
    await page.until(`document.querySelector('${scope}')`, 25000);
    assert.equal(await page.count(`${scope} tr[data-date]`), 2, 'both generated dates are listed in the dialog');
    assert.equal(await page.count(`${scope} tr[data-conflict]`), 1, 'exactly the one conflicted date is flagged');
    assert.includes(
      await page.text(`${scope} tr[data-conflict]`),
      'PB UI TEST — wizard conflict blocker',
      'the conflict row names the colliding blocker event',
    );

    // --- Resolve by skipping the conflicted date (the dialog's default) —
    //     the wizard should then finish creating the event + the clean date,
    //     and navigate to the new event's workspace ---
    await page.click(`${scope} [data-resolve-conflicts]`);
    await page.until(`!document.querySelector('${scope}')`);
    await page.until(`document.querySelector('pb-event-workspace .workspace-tabs')`, 25000);

    const currentHash = await page.eval('location.hash');
    const idMatch = /^#event-(\d+)/.exec(currentHash);
    assert.ok(idMatch, `navigated to the new event's workspace (hash: ${currentHash})`);
    createdEventId = Number(idMatch[1]);

    const seriesInfo = await apiFetch(page, `/events/${createdEventId}/series`);
    assert.equal(seriesInfo.events.length, 2, 'skipping the conflicted date leaves only the anchor + the clean date');
    assert.ok(seriesInfo.events.every((e) => e.date !== d1), 'the conflicted date was not created');
    assert.ok(seriesInfo.events.some((e) => e.date === d2), 'the clean date was still created despite the pattern having a conflict');
  } finally {
    if (createdEventId) {
      const info = await apiFetch(page, `/events/${createdEventId}/series`).catch(() => null);
      const memberIds = info?.events?.map((e) => e.id) || [createdEventId];
      for (const id of memberIds) {
        await apiFetch(page, `/events/${id}/series`, { method: 'DELETE' }).catch(() => {});
      }
      for (const id of memberIds) {
        await apiFetch(page, `/events/${id}`, { method: 'DELETE' }).catch(() => {});
      }
    }
    await apiFetch(page, `/events/${blocker.id}`, { method: 'DELETE' }).catch(() => {});
  }
});
