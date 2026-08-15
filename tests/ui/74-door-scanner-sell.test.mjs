// Door scanner: walk-up sale panel + self-serve card QR.
//
// Unlike most tests here this one DOES persist — a sale is a real order — so it
// builds its own throwaway event and deletes it in a finally (FK cascade takes
// the tier, order, tickets and scans with it). Nothing touches a real event.
//
// The camera never starts in headless Chromium; that is fine and deliberate —
// the sell path and the buy-QR path are exactly the parts of the door tool that
// must work without one.
import { test, assert } from './harness.mjs';

const PREFIX = 'PB UI TEST — door sale';

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

// Build an event + on-sale tier + a sales-enabled scanner link. Returns the
// pieces the scanner page needs, or null if the fixture couldn't be made.
async function setup(page) {
  const venues = await api(page, '/venues');
  const venueId = (venues.body?.venues || venues.body || [])[0]?.id;
  if (!venueId) return null;

  // A far-future date keeps us clear of the room-conflict guard.
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
    // Switching to in-house mode auto-seeds Advance/Door/Comps tiers. Clear them
    // so the scanner shows exactly the one tier this test controls the price and
    // availability of.
    await api(page, `/events/${eventId}/ticketing`, {
      method: 'PATCH',
      body: JSON.stringify({ ticketing_mode: 'internal' }),
    });
    const seeded = await api(page, `/events/${eventId}/ticketing`);
    for (const tier of seeded.body?.tiers || []) {
      await api(page, `/events/${eventId}/ticketing/types/${tier.id}`, { method: 'DELETE' });
    }
    await api(page, `/events/${eventId}/ticketing`, {
      method: 'POST',
      body: JSON.stringify({ name: 'General', price_cents: 2000, quantity_total: 4, status: 'on_sale' }),
    });
    const link = await api(page, `/events/${eventId}/scanner-links`, {
      method: 'POST',
      body: JSON.stringify({ label: 'Front door', can_sell: '1' }),
    });
    const token = link.body?.token;
    if (!token) throw new Error('no scanner token returned');
    // The scanner's self-serve buy QR encodes event_public_path()'s output
    // (see src/Scanner.php buyUrl()) — fetch it from the server instead of
    // assuming its shape, so this test doesn't hardcode a URL scheme.
    const dash = await api(page, `/events/${eventId}`);
    const publicPage = dash.body?.links?.public_page;
    if (!publicPage) throw new Error('no public_page link returned');
    return { eventId, token, publicPage };
  } catch (err) {
    await api(page, `/events/${eventId}`, { method: 'DELETE' });
    throw err;
  }
}

async function openScanner(page, token) {
  await page.navigate(`${page.base}/scanner.html?token=${encodeURIComponent(token)}`);
  // The Sell tab only appears once /api/scan/context has answered.
  return page.until(`document.querySelector('#modes') && document.querySelector('#modes').classList.contains('show')`, 8000);
}

// scanner.html is a standalone page outside the SPA, so every test here must put
// the browser back on the app — otherwise each following test in the run finds
// no SPA mounted and fails for reasons that have nothing to do with it.
async function restoreApp(page) {
  await page.navigate(page.base + '/#dashboard');
  await page.until(`document.querySelector('.side-nav')`, 10000);
}

// Delete the fixture and put the browser back, whatever happened above.
async function teardown(page, fixture) {
  try {
    await api(page, `/events/${fixture.eventId}`, { method: 'DELETE' });
  } finally {
    await restoreApp(page);
  }
}

test('door scanner sells a walk-up ticket and admits the buyer', async (page) => {
  const fixture = await setup(page);
  if (!fixture) return page.skip('could not build a throwaway event fixture');

  try {
    assert.ok(await openScanner(page, fixture.token), 'Sell tab appears for a sales-enabled link');

    // Scan mode is the default; the sell panel stays out of the way.
    assert.notOk(await page.visible('#sell-view'), 'sell panel hidden in scan mode');

    await page.click('#mode-sell');
    assert.ok(await page.visible('#sell-view'), 'sell panel shows in sell mode');
    assert.notOk(await page.visible('#reader'), 'camera view hidden while selling');

    // The tier came from the server with its live price and availability.
    assert.ok(await page.until(`document.querySelectorAll('#tiers .tier').length===1`, 5000), 'one tier offered');
    const tierText = await page.text('#tiers .tier');
    assert.ok(/General/.test(tierText), 'tier name rendered');
    assert.ok(/\$20\.00/.test(tierText), 'tier price rendered');
    assert.ok(/4 left/.test(tierText), 'live availability rendered');

    // A single tier is pre-selected — one less tap for door staff working a line.
    assert.ok(await page.eval(`document.querySelector('#tiers .tier').classList.contains('on')`), 'the only tier is pre-selected');
    assert.notOk(await page.eval(`document.querySelector('#sell-go').disabled`), 'Complete Sale is ready with a tier selected');
    assert.equal(await page.text('#sell-total'), '$20.00', 'total for 1 ticket');

    // Quantity drives the total.
    await page.click('#qty-up');
    assert.equal(await page.text('#qty-n'), '2', 'quantity steps up');
    assert.equal(await page.text('#sell-total'), '$40.00', 'total tracks quantity');
    await page.click('#qty-down');
    assert.equal(await page.text('#sell-total'), '$20.00', 'total tracks quantity back down');

    // Quantity can never go below one.
    await page.click('#qty-down');
    assert.equal(await page.text('#qty-n'), '1', 'quantity floors at 1');

    await page.click('#pays button[data-pay="card"]');
    await page.setValue('#sell-name', 'Jane Doe');

    await page.click('#sell-go');
    assert.ok(
      await page.until(`document.querySelector('#result').classList.contains('admit')`, 8000),
      'sale completes and shows the ADMIT state'
    );
    const result = await page.text('#result');
    assert.ok(/Sold/i.test(result), 'result announces the sale');
    assert.ok(/Jane Doe/.test(result), 'buyer name shown back to door staff');
    assert.ok(/card/i.test(result), 'payment method shown for the drawer');

    // The form resets for the next person in line, and availability re-reads.
    assert.equal(await page.text('#qty-n'), '1', 'quantity resets after a sale');
    assert.equal(await page.eval(`document.querySelector('#sell-name').value`), '', 'name clears after a sale');
    assert.ok(await page.until(`/3 left/.test(document.querySelector('#tiers').textContent)`, 5000), 'availability drops after the sale');

    // Server-side truth: one fulfilled door order, one already-redeemed ticket.
    const dash = await api(page, `/events/${fixture.eventId}/ticketing`);
    const tickets = await api(page, `/events/${fixture.eventId}/ticketing/tickets`);
    const list = tickets.body?.tickets || [];
    assert.equal(list.length, 1, 'exactly one ticket issued');
    assert.equal(list[0].status, 'redeemed', 'the door-sold ticket is already redeemed (buyer admitted)');
    const tier = (dash.body?.tiers || [])[0];
    assert.equal(tier?.revenue_cents, 2000, 'door revenue lands in the standard tier rollup');
  } finally {
    await teardown(page, fixture);
  }
});

test('door scanner offers a self-serve card QR', async (page) => {
  const fixture = await setup(page);
  if (!fixture) return page.skip('could not build a throwaway event fixture');

  try {
    if (!(await openScanner(page, fixture.token))) return page.skip('scanner context did not load');

    assert.ok(await page.visible('#buy-bar'), 'card-purchase button offered');
    assert.notOk(await page.visible('#buy-overlay'), 'QR overlay closed initially');

    await page.click('#buy-btn');
    assert.ok(await page.visible('#buy-overlay'), 'QR overlay opens');

    // The QR must point at this event's public buy page and actually load.
    const src = await page.attr('#buy-qr', 'src');
    assert.ok(/\/assets\/qr\.png\?text=/.test(src || ''), 'QR renders through the app QR endpoint');
    assert.ok(
      decodeURIComponent(src).includes(fixture.publicPage),
      'QR encodes this event\'s public ticket page'
    );
    assert.ok(
      await page.until(`(()=>{const i=document.querySelector('#buy-qr');return i.complete&&i.naturalWidth>0})()`, 8000),
      'QR image actually loads'
    );

    await page.click('#buy-close');
    assert.notOk(await page.visible('#buy-overlay'), 'QR overlay closes again');
  } finally {
    await teardown(page, fixture);
  }
});
