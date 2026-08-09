// Event workspace Print menu: "Invoice" — a client-facing bill built from the
// event's billable (revenue) ledger lines, with a QR encoding the payment
// provider's checkout URL.
//
// Unlike 72-qr-flyer.test.mjs this creates its own throwaway event *and* its
// own ledger/payment rows, deleting the event in a `finally` (FK cascade takes
// the ledger/payment rows with it). It drives the real UI — opens the real
// Print menu, clicks the real button — and inspects the HTML the real
// openPrintWindow() writes into the print popup.
import { test, assert } from './harness.mjs';
import { waitFor } from './browser.mjs';

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

test('Event workspace: Print > Invoice renders line items, balance due and payment QR', async (page) => {
  const created = await apiFetch(page, '/events', {
    method: 'POST',
    body: JSON.stringify({
      title: 'PB UI TEST — Invoice (safe to delete)',
      date: '2099-08-08',
      end_date: '2099-08-09',
      venue_id: 1,
      event_type: 'special_event',
      status: 'confirmed',
      promoter_name: 'Test Client',
      promoter_email: 'testclient@example.com',
    }),
  });
  const eventId = created.id;
  assert.ok(eventId, 'test event created');

  try {
    // Two billable revenue lines + one cost line. The cost must NOT appear on
    // the invoice — an invoice bills the client, it doesn't expose venue costs.
    await apiFetch(page, `/events/${eventId}/ledger`, {
      method: 'POST',
      body: JSON.stringify({ category: 'rental_fee', amount: 1820, description: 'Two-day venue rental' }),
    });
    await apiFetch(page, `/events/${eventId}/ledger`, {
      method: 'POST',
      body: JSON.stringify({ category: 'other_revenue', amount: 131.25, description: 'Security — 5.25 hrs @ $25.00/hr' }),
    });
    await apiFetch(page, `/events/${eventId}/ledger`, {
      method: 'POST',
      body: JSON.stringify({ category: 'sound_production', amount: 150, description: 'INTERNAL SOUND COST' }),
    });

    // An unpaid invoice record. No checkout link is minted here (that would hit
    // the live payment provider), so the QR block is asserted separately below
    // against a stubbed checkout_url.
    await apiFetch(page, `/events/${eventId}/payments`, {
      method: 'POST',
      body: JSON.stringify({
        payment_type: 'client_payment',
        direction: 'received',
        amount: 1951.25,
        status: 'pending',
        due_date: '2099-08-16',
      }),
    });

    // Stub window.open so the print popup's document.write() lands in a
    // capturable global instead of spawning a window under headless CDP.
    await page.eval(`
      window.__invoiceHtml = null;
      window.open = function () {
        return {
          document: { open() {}, write(html) { window.__invoiceHtml = (window.__invoiceHtml || '') + html; }, close() {} },
          focus() {},
        };
      };
    `);

    await page.openEvent(eventId);
    assert.ok(await page.exists('details.print-menu summary'), 'Print menu renders');
    await page.click('details.print-menu summary');
    assert.ok(await page.exists('[data-print="invoice"]'), '"Invoice" print option renders in the menu');
    await page.click('[data-print="invoice"]');

    // Unlike the other printouts, Invoice fetches the ledger + payments before
    // it can render, so the popup HTML lands a tick or two after the click.
    const wrote = await waitFor(async () => await page.eval('!!window.__invoiceHtml'));
    assert.ok(wrote, 'print window received written HTML');

    const html = await page.eval('window.__invoiceHtml');
    assert.ok(html && html.length > 0, 'print window HTML is non-empty');

    // Letterhead comes from the venue settings record, not the event row.
    assert.includes(html, 'Mabuhay Gardens', 'invoice letterhead shows the venue name');
    assert.includes(html, '443 Broadway', 'letterhead shows the venue street address from venue settings');
    assert.includes(html, '(415) 989-3939', 'letterhead shows the venue phone from venue settings');

    // Bill-to + event identification.
    assert.includes(html, 'Test Client', 'invoice bills the promoter/client');
    assert.includes(html, 'testclient@example.com', 'invoice shows the client email');
    assert.includes(html, 'PB UI TEST — Invoice', 'invoice names the event');

    // Line items: revenue only, correctly totalled.
    assert.includes(html, 'Two-day venue rental', 'invoice lists the rental line item');
    assert.includes(html, 'Security — 5.25 hrs @ $25.00/hr', 'invoice lists the security line item');
    assert.includes(html, '$1,820', 'invoice shows the rental amount');
    assert.includes(html, '$131.25', 'invoice shows the security amount');
    assert.includes(html, '$1,951.25', 'invoice totals the billable lines');
    assert.ok(!html.includes('INTERNAL SOUND COST'), 'invoice excludes internal cost lines');

    // A pending payment is NOT money received — balance must equal the total.
    assert.includes(html, 'Balance Due', 'invoice shows a balance due row');
    assert.ok(!html.includes('Payments received'), 'no payments-received row when nothing is collected');
  } finally {
    await apiFetch(page, `/events/${eventId}`, { method: 'DELETE' }).catch(() => {});
  }
});

test('Event workspace: Invoice QR encodes the checkout URL and paid amounts reduce the balance', async (page) => {
  // Renders the invoice body directly (no popup) so we can feed it a stubbed
  // checkout_url and a received payment without minting a real provider link
  // or marking live money as collected.
  const html = await page.eval(`(async () => {
    const { renderPrintBody } = await import('./assets/print.js');
    return renderPrintBody('invoice', {
      event: {
        id: 1, external_id: 'EVT-TEST', title: 'Stub Event', date: '2099-08-08', end_date: '2099-08-09',
        venue_id: 1, promoter_name: 'Stub Client', booker_name: 'Booker', booker_email: 'booker@example.com',
      },
      venues: [{ id: 1, name: 'Stub Venue', address: '1 Test St', city: 'San Francisco', state: 'CA', phone: '(415) 555-0100' }],
      ledgerEntries: [
        { line_type: 'revenue', category: 'rental_fee', amount: '1000.00', description: 'Rental' },
        { line_type: 'cost', category: 'security', amount: '200.00', description: 'Guard cost' },
      ],
      payments: [
        { id: 9, status: 'received', direction: 'received', amount: '400.00' },
        { id: 10, status: 'invoiced', direction: 'received', amount: '600.00', due_date: '2099-08-16',
          checkout_url: 'https://square.link/u/STUBLINK', checkout_provider: 'square', invoice_reference: null },
      ],
    });
  })()`);

  assert.ok(html && html.length > 0, 'renderPrintBody returned invoice markup');
  assert.includes(html, 'assets/qr.png?text=', 'invoice embeds a QR pointing at the QR PNG endpoint');
  assert.includes(html, 'square.link%2Fu%2FSTUBLINK', 'QR payload encodes the provider checkout URL');
  assert.includes(html, 'https://square.link/u/STUBLINK', 'invoice prints the payment URL as fallback text');
  assert.includes(html, 'Square', 'invoice names the payment provider');
  assert.includes(html, 'INV-EVT-TEST', 'invoice number derives from the event code');
  assert.includes(html, 'Stub Venue', 'letterhead uses the venue settings record');

  // $1000 billed, $400 actually received -> $600 balance. The 'invoiced' row
  // must not count as paid (status, not direction, decides).
  assert.includes(html, 'Payments received', 'shows a payments-received row');
  assert.includes(html, '$400', 'shows the amount already paid');
  assert.includes(html, '$600', 'balance due nets out only genuinely received payments');
  assert.ok(!html.includes('Guard cost'), 'cost lines stay off the client invoice');
});
