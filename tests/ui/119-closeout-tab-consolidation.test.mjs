// Settlement/Closeout tab consolidation: the old standalone Settlement tab
// (a flat hand-typed form, disconnected from the real ledger) is gone —
// folded into the Closeout tab as a collapsed "door sales entered manually"
// fallback (tickets_sold/gross_ticket_sales — the only two fields Report.php
// actually reads back) plus the settlement-doc-URL link the old tab also
// owned. See src/Events/Settlement.php, public/assets/event-closeout.js,
// public/assets/event-workspace.js.
//
// This also regression-tests the accompanying backend fix: Settlement::save()
// used to be a blind upsert that defaulted every omitted field to 0/null —
// harmless while the old tab's full 7-field form was the only caller (it
// always submitted everything together), but the new door-sales-only fallback
// form only ever submits two fields, so a blind upsert would silently zero
// out a fuller settlement (bar_sales, band_payouts, etc.) recorded earlier
// through some other path (e.g. directly against the API, or historical
// data). It's now a true partial update — this test seeds a "legacy" full
// settlement row directly via the API, saves only the two door-sales fields
// through the real UI, then reads the row back via the API to confirm the
// other fields survived untouched.
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
  try { body = text ? JSON.parse(text) : null; } catch { /* non-JSON */ }
  if (!res.ok) throw new Error(`${opts.method || 'GET'} ${path} -> ${res.status}: ${text}`);
  return body;
}

test('Settlement tab is gone; Closeout hosts a door-sales fallback that partial-updates without clobbering other fields', async (page) => {
  const created = await apiFetch(page, '/events', {
    method: 'POST',
    body: JSON.stringify({
      title: 'PB UI TEST — Tab Consolidation (safe to delete)',
      date: '2099-10-04',
      end_date: '2099-10-05',
      venue_id: 1,
      event_type: 'special_event',
      status: 'confirmed',
      promoter_name: 'Test Promoter',
      promoter_email: 'testpromoter2@example.com',
    }),
  });
  const eventId = created.id;
  assert.ok(eventId, 'test event created');

  try {
    // Seed a "legacy" full settlement row — as if it were saved by the old
    // standalone Settlement tab's full 7-field form.
    await apiFetch(page, `/events/${eventId}/settlement`, {
      method: 'POST',
      body: JSON.stringify({
        gross_ticket_sales: 100, tickets_sold: 10, bar_sales: 250,
        expenses: 75, band_payouts: 500, promoter_payout: 50, venue_net: -275,
        notes: 'legacy note from the old Settlement tab',
      }),
    });

    await page.openEvent(eventId);

    // The Settlement tab no longer exists at all.
    assert.ok(!(await page.exists('.workspace-tabs a[data-tab="settlement"]')), 'no Settlement tab in the workspace nav');
    assert.ok(!(await page.exists('pb-settlement-form')), 'pb-settlement-form is never mounted');

    // The Overview card's Financial/Ticketing footer now points at Closeout,
    // not the removed Settlement tab.
    assert.ok(!(await page.exists('[data-goto-tab="settlement"]')), 'no overview card footer still targets the removed settlement tab');
    assert.ok(await page.exists('[data-goto-tab="closeout"]'), 'Financial/Ticketing overview card footer targets Closeout');

    // Closeout tab hosts the door-sales fallback.
    assert.ok(await page.exists('.workspace-tabs a[data-tab="closeout"]'), 'Closeout tab renders');
    await page.click('.workspace-tabs a[data-tab="closeout"]');
    assert.ok(await page.until("document.querySelector('.door-sales-toggle')"), 'door-sales fallback section renders inside Closeout');

    // Expand it and confirm the legacy values are visible (proves GET still
    // returns the full row even though only 2 fields are editable here).
    await page.click('.door-sales-toggle summary');
    const ticketsInput = await page.eval("document.querySelector('#door-sales-form [name=tickets_sold]')?.value");
    const grossInput = await page.eval("document.querySelector('#door-sales-form [name=gross_ticket_sales]')?.value");
    assert.equal(ticketsInput, '10', 'door-sales form prefills the previously-saved tickets_sold');
    assert.equal(grossInput, '100.00', 'door-sales form prefills the previously-saved gross_ticket_sales');

    // Save new door-sales numbers through the real UI form.
    await page.setValue('#door-sales-form [name=tickets_sold]', '42');
    await page.setValue('#door-sales-form [name=gross_ticket_sales]', '999.50');
    await page.click('#door-sales-form button[type=submit]');

    const saved = await waitFor(async () => {
      const row = await apiFetch(page, `/events/${eventId}/settlement`);
      return Number(row?.settlement?.tickets_sold) === 42;
    });
    assert.ok(saved, 'door sales save round-trips through the real POST /events/{id}/settlement endpoint');

    // The regression check: fields the door-sales form never touched must
    // survive exactly as seeded — NOT reset to 0/null by the partial update.
    const after = (await apiFetch(page, `/events/${eventId}/settlement`)).settlement;
    assert.equal(Number(after.gross_ticket_sales), 999.5, 'gross_ticket_sales updated to the new value');
    assert.equal(Number(after.tickets_sold), 42, 'tickets_sold updated to the new value');
    assert.equal(Number(after.bar_sales), 250, 'bar_sales untouched by the door-sales-only save');
    assert.equal(Number(after.expenses), 75, 'expenses untouched by the door-sales-only save');
    assert.equal(Number(after.band_payouts), 500, 'band_payouts untouched by the door-sales-only save');
    assert.equal(Number(after.promoter_payout), 50, 'promoter_payout untouched by the door-sales-only save');
    assert.equal(after.notes, 'legacy note from the old Settlement tab', 'notes untouched by the door-sales-only save');

    // Settlement document link form (folded in from the old tab's "doc" form).
    assert.ok(await page.exists('#settlement-doc-form'), 'settlement document link form renders in the same section');
    await page.setValue('#settlement-doc-form [name=settlement_doc_url]', 'https://docs.example.com/night-of-tally');
    await page.click('#settlement-doc-form button[type=submit]');
    const docSaved = await waitFor(async () => {
      const ev = await apiFetch(page, `/events/${eventId}`);
      return ev?.event?.settlement_doc_url === 'https://docs.example.com/night-of-tally';
    });
    assert.ok(docSaved, 'settlement doc URL save round-trips through PATCH /events/{id}');
  } finally {
    await apiFetch(page, `/events/${eventId}`, { method: 'DELETE' }).catch(() => {});
  }
});
