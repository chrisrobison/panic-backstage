// Contracts tab "Create contract" now opens as a modal dialog instead of an
// inline reveal-form — the default going forward for any table's add/edit
// form (see project memory: ui-conventions). Non-destructive: never submits
// the form, only checks the dialog opens/closes and the in-dialog toggle
// swaps field sets, mirroring how 10-workspace.test.mjs checks reveal forms.
import { test, assert } from './harness.mjs';

// Scoped to the dialog containing our own form — other panels (e.g. the
// Share/portal panel) use the same `.modal-backdrop` shell convention and may
// leave their own instance open in earlier tests sharing this page session.
const OURS = '.modal-backdrop:has([data-form="new"])';

test('Contracts "+" opens a modal (not an inline reveal form)', async (page) => {
  if (!page.hasEvent) return page.skip(`event ${page.eventId} not found`);
  await page.openEvent();
  if (!(await page.exists('#contracts [data-add]'))) return page.skip('no manage_contracts capability for this user');
  await page.click('.workspace-tabs a[data-tab="contracts"]');
  await page.until(`document.querySelector('#contracts [data-add]')`);

  assert.notOk(await page.exists(OURS), 'no create-contract modal open before clicking +');
  await page.click('#contracts [data-add]');
  assert.ok(await page.exists(OURS), 'modal opens with the create-contract form');
  assert.ok(await page.visible(`${OURS} [data-generate-fields]`), 'deal-builder fields shown by default');
  assert.notOk(await page.visible(`${OURS} [data-asset-fields]`), 'asset picker hidden by default');

  await page.selectRadio(`${OURS} [data-uploaded-toggle]`);
  assert.notOk(await page.visible(`${OURS} [data-generate-fields]`), 'deal-builder fields hidden once "signed and attached" is checked');
  assert.ok(await page.visible(`${OURS} [data-asset-fields]`), 'asset picker shown once "signed and attached" is checked');

  await page.click(`${OURS} [data-close]`);
  assert.notOk(await page.exists(OURS), 'modal closes via the Close button');
});

test('Contracts modal closes on Escape without submitting', async (page) => {
  if (!page.hasEvent) return page.skip(`event ${page.eventId} not found`);
  await page.openEvent();
  if (!(await page.exists('#contracts [data-add]'))) return page.skip('no manage_contracts capability for this user');
  await page.click('.workspace-tabs a[data-tab="contracts"]');
  await page.click('#contracts [data-add]');
  assert.ok(await page.exists(OURS), 'modal open');
  await page.eval(`document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))`);
  assert.notOk(await page.exists(OURS), 'modal closes on Escape');
});
