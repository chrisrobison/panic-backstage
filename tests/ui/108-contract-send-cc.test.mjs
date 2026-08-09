// The "Send for signature" modal grew an optional Cc field, which is threaded
// through to a real Cc: header (and envelope recipient) on the signing email.
//
// STRICTLY non-destructive: this test NEVER submits the send form. Submitting
// would void outstanding signers, flip the contract to `sent`, and actually
// email a live signing link via the real sendmail binary. It only opens the
// modal on its own throwaway contract and inspects the field wiring, then
// deletes the contract in a finally, per the production-DB convention.
import { test, assert } from './harness.mjs';

const OURS = '.modal-backdrop:has([data-form="sign-send"])';

async function apiCall(page, method, path, body) {
  const res = await page.eval(`(async () => {
    const r = await fetch(${JSON.stringify(page.base + '/api' + path)}, {
      method: ${JSON.stringify(method)},
      headers: {
        'Content-Type': 'application/json',
        Authorization: 'Bearer ' + localStorage.getItem('backstage_access_token'),
      },
      ${body ? `body: ${JSON.stringify(JSON.stringify(body))},` : ''}
    });
    if (r.status === 204) return { ok: true, status: 204 };
    return { ok: r.ok, status: r.status, data: await r.json().catch(() => null) };
  })()`);
  return res;
}

test('"Send for signature" modal exposes a Cc field with its sharing caveat', async (page) => {
  if (!page.hasEvent) return page.skip(`event ${page.eventId} not found`);
  await page.openEvent();

  const listed = await apiCall(page, 'GET', `/events/${page.eventId}/contracts`);
  if (!listed.ok) return page.skip('no manage_contracts capability for this user');
  const template = (listed.data?.templates || [])[0];
  if (!template) return page.skip('no active contract template to build clauses from');

  const created = await apiCall(page, 'POST', `/events/${page.eventId}/contracts`, {
    title: 'PB UI TEST — send cc field (safe to delete)',
    template_id: template.id,
    contract_type: template.contract_type,
  });
  const contractId = created.data?.id;
  assert.ok(contractId, 'throwaway contract created');

  try {
    await page.goto('#contract-' + contractId);
    const hasSend = await page.until(`document.querySelector('[data-act="sign-send"]')`);
    if (!hasSend) return page.skip('send-for-signature action not available on this contract');

    assert.notOk(await page.exists(OURS), 'no send modal open before clicking');
    await page.click('[data-act="sign-send"]');
    assert.ok(await page.exists(OURS), 'send-for-signature modal opens');

    // The field itself.
    assert.ok(await page.exists(`${OURS} [name="cc"]`), 'modal has a Cc input');
    assert.equal(
      await page.eval(`document.querySelector('${OURS} [name="cc"]').value`), '',
      'Cc defaults to empty — copies are opt-in, never silently added',
    );

    // The caveat has to be visible, since a Cc recipient can sign with the link.
    const warned = await page.eval(
      `/anyone you cc/i.test(document.querySelector('${OURS}')?.textContent || '')`,
    );
    assert.ok(warned, 'modal warns that Cc recipients receive the signing link');

    // Cc must be optional: the form stays submittable with it blank.
    assert.notOk(
      await page.eval(`document.querySelector('${OURS} [name="cc"]').required`),
      'Cc is optional',
    );

    // Close WITHOUT submitting — never send a real signing email from a test.
    await page.click(`${OURS} [data-close]`);
    assert.notOk(await page.exists(OURS), 'modal closes without sending');
  } finally {
    await apiCall(page, 'DELETE', `/contracts/${contractId}`);
  }
});
