// Room-conflict highlighting: when two active events are booked into the same
// room (resource_id) with overlapping times, the calendar day cell, the
// agenda day view, and the dashboard (Upcoming) card should all flag it in
// red. See roomConflictIds()/roomConflictDates() in core.js, which mirror the
// server-side rule in EventRowHelpers::checkRoomConflict.
//
// Two competing 'proposed' holds are allowed to overlap by design (both are
// still tentative — see Events::conflictBlockersFor()), which is exactly the
// realistic case this highlighting needs to surface, and conveniently lets
// this test create a genuine double-booking through the normal create API
// without fighting the 409 guard.
//
// Since issue #26 that guard also applies to holds placed over a
// confirmed-or-later booking, so the target date can no longer be hardcoded:
// a confirmed event sitting in the chosen room would now 409 the fixtures.
// pickFreeDate() below scans the dashboard's window for a date where this
// room has no committed booking.
//
// Destructive against two throwaway events only — created here, deleted in a
// `finally` block, same convention as 80-event-sessions.test.mjs.
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

function isoDate(d) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

// A date in the dashboard's 30-day window where $room holds no
// confirmed-or-later booking, so creating the two throwaway holds can't 409.
// Multi-day bookings are expanded across their whole span, matching
// EventRowHelpers::checkRoomConflict()'s own date-overlap rule.
function pickFreeDate(events, room, resources) {
  const committed = new Set(['confirmed', 'booked', 'needs_assets', 'assets_approved',
    'ready_to_announce', 'published', 'advanced', 'completed', 'settled']);
  const venueRooms = (resources || []).filter((candidate) => Number(candidate.venue_id) === Number(room.venue_id));
  const blockingRoomIds = new Set(
    room.zone === 'both'
      ? venueRooms.map((candidate) => Number(candidate.id))
      : [Number(room.id), ...venueRooms.filter((candidate) => candidate.zone === 'both').map((candidate) => Number(candidate.id))],
  );
  const busy = new Set();
  (events || []).forEach((event) => {
    if (!blockingRoomIds.has(Number(event.resource_id)) || !committed.has(event.status)) return;
    for (let d = event.date, end = event.end_date || event.date; d <= end;) {
      busy.add(d);
      const next = new Date(d + 'T00:00:00');
      next.setDate(next.getDate() + 1);
      d = isoDate(next);
    }
  });
  for (let offset = 5; offset <= 28; offset++) {
    const candidate = new Date();
    candidate.setDate(candidate.getDate() + offset);
    const iso = isoDate(candidate);
    if (!busy.has(iso)) return iso;
  }
  return null;
}

test('room double-booking is flagged red on the calendar, agenda, and dashboard', async (page) => {
  const venueData = await apiFetch(page, '/venues');
  const room = (venueData.resources || [])[0];
  assert.ok(room, 'at least one active room is available for conflict testing');

  const existing = await apiFetch(page, '/events');
  const targetIso = pickFreeDate(existing.events || existing || [], room, venueData.resources || []);
  if (!targetIso) page.skip('no date in the next 28 days is free of committed bookings in this room');

  const base = {
    venue_id: Number(room.venue_id),
    resource_id: Number(room.id),
    event_type: 'special_event',
    date: targetIso,
  };
  const eventA = await apiFetch(page, '/events', {
    method: 'POST',
    body: JSON.stringify({ ...base, title: 'PB UI TEST — room conflict A (safe to delete)', load_in_time: '17:00', doors_time: '19:00', end_time: '23:00', load_out_time: '23:45' }),
  });
  const eventB = await apiFetch(page, '/events', {
    method: 'POST',
    body: JSON.stringify({ ...base, title: 'PB UI TEST — room conflict B (safe to delete)', load_in_time: '18:00', doors_time: '20:00', end_time: '23:59', load_out_time: '00:30' }),
  });
  assert.ok(eventA.id, 'event A created');
  assert.ok(eventB.id, 'event B created (overlapping room+time — allowed while both stay "proposed")');

  try {
    // --- Calendar grid view ---
    // Force a real remount (not just a same-hash no-op — location.hash
    // assignment to its current value doesn't fire 'hashchange', so a bare
    // goto('#calendar') would reuse whatever pb-event-calendar instance an
    // earlier test already mounted, with event data fetched *before* the two
    // fixtures above existed) so the calendar's fetch is guaranteed to see them.
    await page.goto('#dashboard');
    await page.until(`document.querySelector('pb-events-upcoming .upcoming-page')`);
    await page.goto('#calendar');
    await page.until(`document.querySelector('.calendar-month-block')`);
    const [targetYear, targetMonth] = targetIso.split('-').map(Number);
    await page.eval(`document.querySelector('pb-event-calendar')._goToMonth(new Date(${targetYear}, ${targetMonth - 1}, 1))`);
    await page.until(`document.querySelector('.calendar-day[data-create-date="${targetIso}"]')`);

    const dayCell = `.calendar-day[data-create-date="${targetIso}"]`;
    assert.ok(
      await page.eval(`document.querySelector('${dayCell}')?.classList.contains('has-conflict')`),
      'day cell carries has-conflict once two events double-book the same room',
    );
    assert.atLeast(
      await page.count(`${dayCell} .mini-event-conflict`),
      2,
      'both conflicting event chips are individually flagged',
    );
    assert.includes(
      await page.eval(`document.querySelector('${dayCell} .mini-event-time')?.textContent || ''`),
      'Load-in',
      'calendar chips start with the room-occupancy load-in time',
    );

    // --- Agenda view (mini-grid dot + day list) ---
    await page.click('[data-view="agenda"]');
    await page.until(`document.querySelector('[data-view="agenda"]').classList.contains('active')`);
    await page.eval(`(() => {
      const cal = document.querySelector('pb-event-calendar');
      cal.selectedDate = ${JSON.stringify(targetIso)};
      cal._refreshAgenda();
    })()`);
    await page.until(`document.querySelector('[data-select-date="${targetIso}"]').classList.contains('is-selected')`);

    assert.ok(
      await page.eval(`document.querySelector('[data-select-date="${targetIso}"]')?.classList.contains('has-conflict')`),
      'mini-calendar day button flags the conflict',
    );
    assert.ok(await page.exists('.cal-day-conflict-flag'), 'day panel shows the room-conflict banner');
    assert.atLeast(await page.count('.cal-agenda-row-conflict'), 2, 'both agenda rows for the conflicting events are flagged');

    // --- Dashboard (Upcoming cards) ---
    await page.goto('#dashboard');
    await page.until(`document.querySelector('pb-events-upcoming .upcoming-page')`);
    // The card list only shows the first page (6) sorted by date — search
    // narrows it to just our two fixtures so pagination can't hide them.
    await page.setValue('[data-filter-search]', 'PB UI TEST — room conflict');
    await page.until(`document.querySelector('[data-event-card="${eventA.id}"]')`);
    assert.ok(
      await page.eval(`document.querySelector('[data-event-card="${eventA.id}"]')?.classList.contains('has-conflict')`),
      'dashboard card A flags the conflict',
    );
    assert.ok(
      await page.eval(`document.querySelector('[data-event-card="${eventB.id}"]')?.classList.contains('has-conflict')`),
      'dashboard card B flags the conflict',
    );
  } finally {
    await apiFetch(page, `/events/${eventA.id}`, { method: 'DELETE' });
    await apiFetch(page, `/events/${eventB.id}`, { method: 'DELETE' });
  }
});
