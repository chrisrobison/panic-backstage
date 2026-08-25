// Closeout tab: payee balances ("who's still owed money").
//
// Cost entries can now carry a payee_name/payee_type, and a payment can
// either link to one specific cost (paid_entry_id) or net against a payee
// more loosely (payee_name only) — see Ledger::calculateBalances() in
// src/Events/Ledger.php. This test creates its own throwaway event with one
// fully-paid vendor cost (linked payment) and one unpaid vendor cost, drives
// the real Closeout UI to confirm the Balances panel reflects both correctly,
// then uses the panel's own "Log Payment" action to pay off the unpaid one
// and confirms the still-owed total, the derived "All Payouts Disbursed"
// checklist row, and the Finalize hint all update to match — deleting the
// event in a `finally` (FK cascade takes the ledger rows with it).
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

async function rowStatus(page, payeeSubstring) {
  return page.eval(`(() => {
    const row = [...document.querySelectorAll('.bal-row')]
      .find(r => (r.querySelector('.payee-name')?.textContent || '').includes(${JSON.stringify(payeeSubstring)}));
    return row ? row.dataset.status : null;
  })()`);
}

test('Closeout tab: Balances panel tracks who is paid/unpaid and gates Finalize on it', async (page) => {
  const created = await apiFetch(page, '/events', {
    method: 'POST',
    body: JSON.stringify({
      title: 'PB UI TEST — Closeout Balances (safe to delete)',
      date: '2099-09-12',
      end_date: '2099-09-13',
      venue_id: 1,
      event_type: 'special_event',
      status: 'confirmed',
      promoter_name: 'Test Promoter',
      promoter_email: 'testpromoter@example.com',
    }),
  });
  const eventId = created.id;
  assert.ok(eventId, 'test event created');

  try {
    // Vendor #1: cost + a linked payment that fully covers it -> paid.
    const securityCost = await apiFetch(page, `/events/${eventId}/ledger`, {
      method: 'POST',
      body: JSON.stringify({
        category: 'security', amount: 300, description: 'Titan Security, 2 guards',
        payee_name: 'Titan Security', payee_type: 'vendor',
      }),
    });
    await apiFetch(page, `/events/${eventId}/ledger`, {
      method: 'POST',
      body: JSON.stringify({
        category: 'vendor_payout', amount: 300, description: 'Paid via Zelle',
        paid_entry_id: securityCost.id,
      }),
    });

    // Vendor #2: cost with no payment yet -> unpaid.
    await apiFetch(page, `/events/${eventId}/ledger`, {
      method: 'POST',
      body: JSON.stringify({
        category: 'sound_production', amount: 400, description: 'Doorwolf Sound Co. invoice',
        payee_name: 'Doorwolf Sound Co.', payee_type: 'vendor',
      }),
    });

    await page.openEvent(eventId);
    assert.ok(await page.exists('.workspace-tabs a[data-tab="closeout"]'), 'Closeout tab renders (admin has manage_ledger)');
    await page.click('.workspace-tabs a[data-tab="closeout"]');
    assert.ok(await page.until("document.querySelector('.balances-table')"), 'Balances table renders');

    // Both payees show up, with the correct paid/unpaid status.
    const tableText = await page.text('.balances-table');
    assert.includes(tableText, 'Titan Security', 'Balances panel lists the paid-off vendor');
    assert.includes(tableText, 'Doorwolf Sound Co.', 'Balances panel lists the unpaid vendor');
    assert.equal(await rowStatus(page, 'Titan Security'), 'paid', 'Titan Security (linked payment) shows status=paid');
    assert.equal(await rowStatus(page, 'Doorwolf Sound Co.'), 'unpaid', 'Doorwolf Sound Co. (no payment yet) shows status=unpaid');

    // The paid-off vendor has no "Log Payment" button; the unpaid one does.
    assert.ok(!(await page.exists('.log-pay-btn[data-payee="Titan Security"]')), 'no Log Payment action once a payee is fully paid');
    assert.ok(await page.exists('.log-pay-btn[data-payee="Doorwolf Sound Co."]'), 'Log Payment action offered while still owed');

    // Summary card surfaces the still-owed total and the derived checklist row.
    const summaryText = await page.text('#summary-card');
    assert.includes(summaryText, 'Still Owed to Payees', 'P&L summary shows a Still Owed to Payees row');
    assert.includes(summaryText, '$400.00', 'Still Owed reflects only the unpaid vendor');
    assert.ok(!(await page.eval("document.querySelector('.derived-check input')?.checked")), '"All Payouts Disbursed" is unchecked while money is still owed');

    // Finalize is blocked, and the hint names the unpaid payee (money-owed
    // gate, not just the checklist — see Ledger::finalize()).
    if (await page.exists('#btn-finalize')) {
      assert.ok(await page.eval("document.querySelector('#btn-finalize').disabled"), 'Finalize disabled while a payee is still owed money');
      const hint = await page.text('.finalize-hint');
      assert.includes(hint, 'Doorwolf Sound Co.', 'Finalize hint names the unpaid payee');
      assert.includes(hint, '$400.00', 'Finalize hint states the amount still owed');
    }

    // Use the panel's own Log Payment action to pay off the remaining balance.
    await page.click('.log-pay-btn[data-payee="Doorwolf Sound Co."]');
    assert.ok(await page.exists('.pay-inline'), 'inline payment form opens');
    const prefilled = await page.eval("document.querySelector('.pay-inline input[name=amount]')?.value");
    assert.equal(prefilled, '400.00', 'payment amount prefills to the full remaining balance');
    await page.click('.pay-inline button.primary');

    const settled = await waitFor(async () => (await rowStatus(page, 'Doorwolf Sound Co.')) === 'paid');
    assert.ok(settled, 'Doorwolf Sound Co. flips to paid after Log Payment');

    const summaryAfter = await page.text('#summary-card');
    assert.includes(summaryAfter, '$0.00', 'Still Owed drops to $0.00 once everyone is paid');
    assert.includes(summaryAfter, 'All payees settled', 'summary sub-line confirms everyone is settled');
    assert.ok(await page.eval("document.querySelector('.derived-check input')?.checked"), '"All Payouts Disbursed" auto-checks once the balance clears');

    // Finalize would still need the manual checklist too — assert it's no
    // longer blocked *by money* specifically, without actually finalizing
    // (this event has none of the other checklist items set).
    if (await page.exists('#btn-finalize')) {
      const hintAfter = await page.text('.finalize-hint').catch(() => '');
      assert.ok(!hintAfter.includes('still owed'), 'Finalize hint no longer cites money owed once payees are settled');
    }

    // Server-side gate: finalize must independently reject on money owed —
    // never trust the client-side disabled state alone. Re-open a fresh
    // unpaid cost via the API (bypassing the UI) and confirm the endpoint
    // itself refuses.
    await apiFetch(page, `/events/${eventId}/ledger`, {
      method: 'POST',
      body: JSON.stringify({
        category: 'other_cost', amount: 50, description: 'Late merch reorder',
        payee_name: 'Merch Vendor', payee_type: 'vendor',
      }),
    });
    let rejected = false;
    try {
      await apiFetch(page, `/events/${eventId}/ledger/finalize`, { method: 'POST', body: JSON.stringify({ force: false }) });
    } catch (err) {
      rejected = /422|Payees are still owed money/.test(String(err.message));
    }
    assert.ok(rejected, 'POST .../ledger/finalize itself (not just the UI) refuses while a payee is unpaid');
  } finally {
    await apiFetch(page, `/events/${eventId}`, { method: 'DELETE' }).catch(() => {});
  }
});
