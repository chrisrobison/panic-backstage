// Regression test for the contract editor's inline contenteditable clause bodies.
//
// The bug: `refreshPreviewOnly()` (deal-terms autosave) replaced the preview's
// innerHTML wholesale and re-bound only the [data-token] click handlers, so the
// freshly rendered `.contract-section-body` divs came back WITHOUT
// contentEditable — inline editing silently died the moment you touched any
// deal field, until a full page reload. Now both render() and
// refreshPreviewOnly() go through wirePreview(), and a refresh is skipped
// entirely while the user is mid-edit so unsaved text is never clobbered.
//
// Non-destructive: creates its own throwaway contract on the test event and
// deletes it in a finally, per the production-DB convention in the other tests.
import { test, assert } from './harness.mjs';

const DEBOUNCE_MS = 800; // matches the deal-form autosave debounce in contracts.js
const BODY = '[data-preview] .contract-section-body';

// Small fetch helper run in-page so it rides the real auth token.
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

test('contract clause bodies stay editable after a deal-terms autosave', async (page) => {
  if (!page.hasEvent) return page.skip(`event ${page.eventId} not found`);
  await page.openEvent();

  // Need a template so the new contract actually has clauses to render.
  const listed = await apiCall(page, 'GET', `/events/${page.eventId}/contracts`);
  if (!listed.ok) return page.skip('no manage_contracts capability for this user');
  const template = (listed.data?.templates || [])[0];
  if (!template) return page.skip('no active contract template to build clauses from');

  const created = await apiCall(page, 'POST', `/events/${page.eventId}/contracts`, {
    title: 'PB UI TEST — inline edit regression (safe to delete)',
    template_id: template.id,
    contract_type: template.contract_type,
  });
  const contractId = created.data?.id;
  assert.ok(contractId, 'throwaway contract created');

  try {
    await page.goto('#contract-' + contractId);
    const mounted = await page.until(`document.querySelector('${BODY}')`);
    if (!mounted) return page.skip('contract preview rendered no clause bodies');

    // Baseline: inline editing is live on first render.
    assert.equal(
      await page.attr(BODY, 'contenteditable'), 'true',
      'clause bodies are contenteditable on initial render',
    );

    // Trigger the deal-terms autosave that used to wipe it out.
    await page.setValue('[data-form="deal"] [name="counterparty_name"]', 'PB UI Test Counterparty');
    await page.until(`!document.querySelector('[data-autosave-status]')?.textContent.includes('Saving')`, DEBOUNCE_MS + 6000);

    // The regression: this came back null once the preview was re-rendered.
    assert.equal(
      await page.attr(BODY, 'contenteditable'), 'true',
      'clause bodies are STILL contenteditable after the deal-terms autosave refresh',
    );

    // ...and the listeners are really re-attached, not just the attribute.
    const dirtyTracked = await page.eval(`(() => {
      const el = document.querySelector('${BODY}');
      if (!el) return false;
      el.dispatchEvent(new Event('input', { bubbles: true }));
      const ok = el.dataset.dirty === '1';
      delete el.dataset.dirty; // don't leave it dirty — blur would POST a save
      return ok;
    })()`);
    assert.ok(dirtyTracked, 'the input listener is re-attached after the refresh, not just the attribute');
  } finally {
    await apiCall(page, 'DELETE', `/contracts/${contractId}`);
  }
});

test('an in-progress clause edit survives a concurrent deal-terms autosave', async (page) => {
  if (!page.hasEvent) return page.skip(`event ${page.eventId} not found`);
  await page.openEvent();

  const listed = await apiCall(page, 'GET', `/events/${page.eventId}/contracts`);
  if (!listed.ok) return page.skip('no manage_contracts capability for this user');
  const template = (listed.data?.templates || [])[0];
  if (!template) return page.skip('no active contract template to build clauses from');

  const created = await apiCall(page, 'POST', `/events/${page.eventId}/contracts`, {
    title: 'PB UI TEST — inline edit clobber (safe to delete)',
    template_id: template.id,
    contract_type: template.contract_type,
  });
  const contractId = created.data?.id;
  assert.ok(contractId, 'throwaway contract created');

  try {
    await page.goto('#contract-' + contractId);
    const mounted = await page.until(`document.querySelector('${BODY}')`);
    if (!mounted) return page.skip('contract preview rendered no clause bodies');

    // Simulate the user typing into a clause: mark it dirty with text the
    // server copy does not have. A refresh must not blow this away.
    const sentinel = 'PB-UI-TEST-UNSAVED-SENTINEL';
    await page.eval(`(() => {
      const el = document.querySelector('${BODY}');
      el.textContent = ${JSON.stringify(sentinel)};
      el.dataset.dirty = '1';
    })()`);

    await page.setValue('[data-form="deal"] [name="counterparty_org"]', 'PB UI Test Org');
    await page.until(`!document.querySelector('[data-autosave-status]')?.textContent.includes('Saving')`, DEBOUNCE_MS + 6000);

    const survived = await page.eval(
      `document.querySelector('${BODY}')?.textContent.includes(${JSON.stringify(sentinel)}) === true`,
    );
    assert.ok(survived, 'unsaved clause text is not clobbered by the autosave preview refresh');

    // Clear the dirty flag so the teardown's navigation can't fire a blur-save.
    await page.eval(`delete document.querySelector('${BODY}')?.dataset.dirty`);
  } finally {
    await apiCall(page, 'DELETE', `/contracts/${contractId}`);
  }
});
