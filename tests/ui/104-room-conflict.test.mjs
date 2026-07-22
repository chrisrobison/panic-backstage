// Room-conflict highlighting: when two active events are booked into the same
// room (resource_id) with overlapping times, the calendar day cell, the
// agenda day view, and the dashboard (Upcoming) card should all flag it in
// red. See roomConflictIds()/roomConflictDates() in core.js, which mirror the
// server-side rule in EventRowHelpers::checkRoomConflict.
//
// The backend only enforces that rule for BOOKING_CONFIRMED_STATUSES (see
// Events.php) — a 'proposed' hold is allowed to overlap another proposed hold
// by design (both are still tentative). That's exactly the realistic case
// this feature needs to surface, and conveniently lets this test create a
// genuine double-booking through the normal create API without fighting the
// 409 guard.
//
// Destructive against two throwaway events only — created here, deleted in a
// `finally` block, same convention as 80-event-sessions.test.mjs.
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

function isoDate(d) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

test('room double-booking is flagged red on the calendar, agenda, and dashboard', async (page) => {
  const target = new Date();
  target.setDate(target.getDate() + 10); // within the dashboard's default 30-day window
  const targetIso = isoDate(target);

  const base = {
    venue_id: 1,
    resource_id: 2, // "Downstairs (21+)" — see database/schema.sql `resources`
    event_type: 'special_event',
    date: targetIso,
  };
  const eventA = await apiFetch(page, '/events', {
    method: 'POST',
    body: JSON.stringify({ ...base, title: 'PB UI TEST — room conflict A (safe to delete)', doors_time: '19:00', end_time: '23:00' }),
  });
  const eventB = await apiFetch(page, '/events', {
    method: 'POST',
    body: JSON.stringify({ ...base, title: 'PB UI TEST — room conflict B (safe to delete)', doors_time: '20:00', end_time: '23:59' }),
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
    await page.eval(`document.querySelector('pb-event-calendar')._goToMonth(new Date(${target.getFullYear()}, ${target.getMonth()}, 1))`);
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
