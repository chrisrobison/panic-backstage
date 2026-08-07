// Door scanner: look up a purchased ticket and admit it without a QR.
//
// The guest who bought a ticket but can't produce it — dead phone, buried
// email, ticket left at home. Door staff find them by name, check ID, admit.
//
// Like the sell test, this one persists real rows (an admission is a real
// state change), so it builds a throwaway event and deletes it in a finally;
// FK cascade takes the tier, tickets and scans with it.
//
// Also covers the capability gate from the outside: a link created without
// can_lookup must not show the tab at all.
import { test, assert } from './harness.mjs';

const PREFIX = 'PB UI TEST — ticket lookup';

async function api(page, path, options = {}) {
  const res = await fetch(page.base + '/api' + path, {
    ...options,
    headers: {
      'Content-Type': 'application/json',
      Authorization: 'Bearer ' + page.accessToken,
      ...(options.headers || {}),
    },
  });
  const body = await res.json().catch(() => ({}));
  return { status: res.status, body };
}

// Event + one tier + two comped tickets with known holder names + a scanner
// link. `lookup` decides whether that link is granted the new capability.
async function setup(page, { lookup = true } = {}) {
  const venues = await api(page, '/venues');
  const venueId = (venues.body?.venues || venues.body || [])[0]?.id;
  if (!venueId) return null;

  const stamp = Date.now();
  const day = new Date(Date.now() + 400 * 86400000).toISOString().slice(0, 10);
  const created = await api(page, '/events', {
    method: 'POST',
    body: JSON.stringify({
      title: `${PREFIX} ${stamp} (safe to delete)`,
      date: day,
      venue_id: venueId,
      event_type: 'live_music',
      status: 'confirmed',
    }),
  });
  const eventId = created.body?.event?.id || created.body?.id;
  if (!eventId) return null;

  try {
    await api(page, `/events/${eventId}/ticketing`, {
      method: 'PATCH',
      body: JSON.stringify({ ticketing_mode: 'internal' }),
    });
    const seeded = await api(page, `/events/${eventId}/ticketing`);
    for (const tier of seeded.body?.tiers || []) {
      await api(page, `/events/${eventId}/ticketing/types/${tier.id}`, { method: 'DELETE' });
    }
    const tier = await api(page, `/events/${eventId}/ticketing`, {
      method: 'POST',
      body: JSON.stringify({ name: 'General', price_cents: 2000, quantity_total: 10, status: 'on_sale' }),
    });
    const tierId = tier.body?.ticket_type?.id || tier.body?.id;

    // Comps are the cheapest way to get real issued tickets with names on them.
    for (const holder of ['Ada Lovelace', 'Grace Hopper']) {
      await api(page, `/events/${eventId}/ticketing/comp`, {
        method: 'POST',
        body: JSON.stringify({ ticket_type_id: tierId, quantity: 1, holder_name: holder }),
      });
    }

    const link = await api(page, `/events/${eventId}/scanner-links`, {
      method: 'POST',
      body: JSON.stringify({ label: 'Front door', ...(lookup ? { can_lookup: '1' } : {}) }),
    });
    const token = link.body?.token;
    if (!token) throw new Error('no scanner token returned');
    return { eventId, token };
  } catch (err) {
    await api(page, `/events/${eventId}`, { method: 'DELETE' });
    throw err;
  }
}

async function openScanner(page, token) {
  await page.navigate(`${page.base}/scanner.html?token=${encodeURIComponent(token)}`);
  // Give /api/scan/context a chance to answer either way.
  await page.until(`document.querySelector('#modes')`, 8000);
  return page.until(`document.querySelector('#modes').classList.contains('show')`, 6000);
}

// scanner.html is outside the SPA; put the browser back so the next test in the
// run finds an app mounted.
async function restoreApp(page) {
  await page.navigate(page.base + '/#dashboard');
  await page.until(`document.querySelector('.side-nav')`, 10000);
}

async function teardown(page, fixture) {
  try {
    await api(page, `/events/${fixture.eventId}`, { method: 'DELETE' });
  } finally {
    await restoreApp(page);
  }
}

test('door scanner looks up a ticket and admits without a QR', async (page) => {
  const fixture = await setup(page);
  if (!fixture) return page.skip('could not build a throwaway event fixture');

  try {
    assert.ok(await openScanner(page, fixture.token), 'Look-up tab appears for a lookup-enabled link');
    assert.notOk(await page.visible('#look-view'), 'look-up panel hidden in scan mode');

    await page.click('#mode-look');
    assert.ok(await page.visible('#look-view'), 'look-up panel shows in look-up mode');
    assert.notOk(await page.visible('#reader'), 'camera view hidden while looking up');

    assert.ok(await page.until(`document.querySelectorAll('#guests .guest').length===2`, 8000), 'both tickets listed');
    const listText = await page.text('#guests');
    assert.ok(/Ada Lovelace/.test(listText), 'holder name rendered');
    assert.ok(/General/.test(listText), 'tier rendered');

    // The list must never carry anything that could reconstruct a ticket.
    const html = await page.eval(`document.querySelector('#guests').innerHTML`);
    assert.notOk(/\/t\//.test(html), 'no ticket URL is rendered into the list');

    // Filter down to one person, the way staff would.
    await page.setValue('#look-q', 'Lovelace');
    assert.ok(await page.until(`document.querySelectorAll('#guests .guest').length===1`, 5000), 'search narrows the list');
    assert.ok(/Ada Lovelace/.test(await page.text('#guests')), 'the right person survives the filter');

    // Admitting asks for confirmation — staff are vouching for an ID check.
    await page.eval(`window.confirm = () => true;`);
    await page.click('#guests .guest .act button');

    assert.ok(
      await page.until(`document.querySelector('#result').classList.contains('admit')`, 8000),
      'admitting shows the ADMIT state'
    );
    assert.ok(/Ada Lovelace/.test(await page.text('#result')), 'the admitted guest is named back to staff');

    // The row flips to used, and offers no second admit.
    assert.ok(
      await page.until(`(()=>{const r=document.querySelector('#guests .guest');return r&&r.classList.contains('done')})()`, 6000),
      'the admitted row is marked used'
    );
    assert.notOk(await page.eval(`!!document.querySelector('#guests .guest .act button')`), 'no Admit button remains on a used ticket');

    // Server-side truth: exactly one ticket redeemed, the other untouched.
    const tickets = await api(page, `/events/${fixture.eventId}/ticketing/tickets`);
    const list = tickets.body?.tickets || [];
    const ada = list.find((t) => t.holder_name === 'Ada Lovelace');
    const grace = list.find((t) => t.holder_name === 'Grace Hopper');
    assert.equal(ada?.status, 'redeemed', 'the looked-up ticket is redeemed');
    assert.equal(grace?.status, 'issued', 'the other ticket is untouched');
  } finally {
    await teardown(page, fixture);
  }
});

// The office-side counterpart: same admission, reached from the Ticketing tab
// by whoever has the full app open when the door calls them.
test('Ticketing tab marks a ticket used without a scan', async (page) => {
  const fixture = await setup(page);
  if (!fixture) return page.skip('could not build a throwaway event fixture');

  try {
    await page.openEvent(fixture.eventId);
    if (!(await page.exists('.workspace-tabs a[data-tab="ticketing"]'))) {
      return page.skip('ticketing tab not present (no manage_ticketing?)');
    }
    await page.click('.workspace-tabs a[data-tab="ticketing"]');
    assert.ok(
      await page.until(`document.querySelectorAll('#ticketing [data-use-ticket]').length===2`, 10000),
      'both issued tickets offer a Mark used button'
    );

    await page.eval(`window.confirm = () => true;`);
    await page.click('#ticketing [data-use-ticket]');

    // The list reloads from the server after the action.
    assert.ok(
      await page.until(`document.querySelectorAll('#ticketing [data-use-ticket]').length===1`, 10000),
      'the marked ticket no longer offers Mark used'
    );
    assert.ok(
      await page.until(`/Scanned in/.test(document.querySelector('#ticketing').textContent)`, 5000),
      'the row shows as scanned in'
    );

    const after = await api(page, `/events/${fixture.eventId}/ticketing/tickets`);
    const list = after.body?.tickets || [];
    assert.equal(list.filter((t) => t.status === 'redeemed').length, 1, 'exactly one ticket was admitted');
    assert.equal(list.filter((t) => t.status === 'issued').length, 1, 'the other is untouched');
  } finally {
    await teardown(page, fixture);
  }
});

// The endpoint contract behind that button, including the back-compat rule
// that a POST with no body still means "resend".
test('the ticket action endpoint redeems, refuses twice, and defaults to resend', async (page) => {
  const fixture = await setup(page);
  if (!fixture) return page.skip('could not build a throwaway event fixture');

  try {
    const before = await api(page, `/events/${fixture.eventId}/ticketing/tickets`);
    const target = (before.body?.tickets || []).find((t) => t.status === 'issued');
    assert.ok(target, 'a fixture ticket is available to admit');

    const first = await api(page, `/events/${fixture.eventId}/ticketing/tickets/${target.id}`, {
      method: 'POST',
      body: JSON.stringify({ action: 'redeem' }),
    });
    assert.equal(first.status, 200, 'redeem succeeds');
    assert.equal(first.body?.status, 'redeemed', 'the response reports the new status');

    // Same atomic guard as the door: a second admission is refused, not silently
    // repeated, so two people acting at once cannot double-admit.
    const second = await api(page, `/events/${fixture.eventId}/ticketing/tickets/${target.id}`, {
      method: 'POST',
      body: JSON.stringify({ action: 'redeem' }),
    });
    assert.equal(second.status, 409, 'admitting an already-used ticket is refused');

    // No body at all must still mean resend — the documented back-compat rule.
    // These comps carry no holder_email, so resend answers 422; that it is 422
    // and not a redeem is the point.
    const legacy = await api(page, `/events/${fixture.eventId}/ticketing/tickets/${target.id}`, { method: 'POST' });
    assert.equal(legacy.status, 422, 'a body-less POST still routes to resend');

    const unknown = await api(page, `/events/${fixture.eventId}/ticketing/tickets/${target.id}`, {
      method: 'POST',
      body: JSON.stringify({ action: 'explode' }),
    });
    assert.equal(unknown.status, 422, 'an unrecognized action is rejected');
  } finally {
    await teardown(page, fixture);
  }
});

test('a scanner link without look-up permission never offers the tab', async (page) => {
  const fixture = await setup(page, { lookup: false });
  if (!fixture) return page.skip('could not build a throwaway event fixture');

  try {
    await page.navigate(`${page.base}/scanner.html?token=${encodeURIComponent(fixture.token)}`);
    await page.until(`document.querySelector('#modes')`, 8000);
    // Let context settle before asserting on an absence.
    assert.ok(await page.until(`document.querySelector('#event-label').textContent.length>0`, 8000), 'scanner link is valid and context loaded');

    assert.notOk(await page.visible('#mode-look'), 'Look-up tab is not offered');
    assert.notOk(await page.visible('#look-view'), 'look-up panel is not reachable');

    // And the API refuses it directly, not just in the UI.
    const res = await fetch(page.base + '/api/scan/tickets', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ scanner_token: fixture.token }),
    });
    const body = await res.json().catch(() => ({}));
    assert.equal(body.result, 'not_permitted', 'the lookup endpoint refuses a scan-only link');
    assert.equal((body.tickets || []).length, 0, 'and returns no ticket data');
  } finally {
    await teardown(page, fixture);
  }
});
