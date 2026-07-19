// Event workspace Print menu: "QR Flyer" — a door-poster print sheet with the
// show title, a QR code straight to the public event page (where in-house
// ticketing shows the buy/checkout form), price, doors time, and lineup.
//
// This test IS destructive against a throwaway event it creates for itself
// and deletes in a `finally` block — same convention as
// 70-runsheet-populate.test.mjs. It drives the real UI (opens the real Print
// menu, clicks the real button) and inspects the HTML the real openPrintWindow()
// writes into the print popup.
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

test('Event workspace: Print > QR Flyer renders title, QR, price, doors, lineup', async (page) => {
  const created = await apiFetch(page, '/events', {
    method: 'POST',
    body: JSON.stringify({
      title: 'PB UI TEST — QR Flyer (safe to delete)',
      date: '2099-07-20',
      venue_id: 1,
      event_type: 'live_music',
      status: 'confirmed',
      doors_time: '19:00',
      show_time: '20:00',
      ticket_price: 20,
      public_visibility: 1,
    }),
  });
  const eventId = created.id;
  assert.ok(eventId, 'test event created');

  try {
    await apiFetch(page, `/events/${eventId}/lineup`, {
      method: 'POST',
      body: JSON.stringify({ display_name: 'Band One', billing_order: 1, set_time: '20:00', set_length_minutes: 30 }),
    });
    await apiFetch(page, `/events/${eventId}/lineup`, {
      method: 'POST',
      body: JSON.stringify({ display_name: 'Band Two', billing_order: 2, set_time: '21:00', set_length_minutes: 30 }),
    });
    await apiFetch(page, `/events/${eventId}/lineup`, {
      method: 'POST',
      body: JSON.stringify({ display_name: 'Band Three', billing_order: 3, set_time: '22:00', set_length_minutes: 30 }),
    });

    // Stub window.open so the print popup's document.write() calls land in a
    // capturable global instead of actually spawning a window under headless CDP.
    await page.eval(`
      window.__qrFlyerHtml = null;
      window.open = function () {
        return {
          document: { open() {}, write(html) { window.__qrFlyerHtml = (window.__qrFlyerHtml || '') + html; }, close() {} },
          focus() {},
        };
      };
    `);

    await page.openEvent(eventId);
    assert.ok(await page.exists('details.print-menu summary'), 'Print menu renders');
    await page.click('details.print-menu summary');
    assert.ok(await page.exists('[data-print="qr-flyer"]'), '"QR Flyer" print option renders in the menu');
    await page.click('[data-print="qr-flyer"]');

    const html = await page.eval('window.__qrFlyerHtml');
    assert.ok(html && html.length > 0, 'print window received written HTML');
    assert.includes(html, 'PB UI TEST — QR Flyer', 'flyer includes the show title');
    assert.includes(html, 'qf-title', 'flyer uses the big-title class');
    assert.includes(html, 'assets/qr.png?text=', 'flyer embeds a QR image pointing at the QR PNG endpoint');
    assert.includes(html, 'event.html%3Fid%3D' + eventId, 'QR payload encodes this event\'s public page URL');
    assert.includes(html, '$20', 'flyer shows the ticket price');
    assert.includes(html, '7:00', 'flyer shows the doors time');
    assert.includes(html, 'Band One', 'flyer lists first band');
    assert.includes(html, 'Band Two', 'flyer lists second band');
    assert.includes(html, 'Band Three', 'flyer lists third band');
    assert.includes(html, '8:00', 'flyer shows a band set time');
  } finally {
    await apiFetch(page, `/events/${eventId}`, { method: 'DELETE' }).catch(() => {});
  }
});
