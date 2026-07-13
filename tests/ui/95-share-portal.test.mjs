// Share Portal link: the "Share" button in the event workspace toolbar used
// to toggle an inline card into the page flow (janky — shifted layout around
// it). It now opens the same content in a modal dialog, matching every other
// "Add/Edit ..." action in the workspace (see EventPayments._openPaymentForm
// for the sibling pattern this follows). Non-destructive: only reads/creates
// a throwaway-safe portal link scoped to the fixture event and revokes it.
import { test, assert } from './harness.mjs';

test('Share button opens the portal panel as a modal dialog, not inline', async (page) => {
  if (!page.hasEvent) return page.skip(`event ${page.eventId} not found`);
  await page.openEvent();
  if (!(await page.exists('[data-portal-toggle]'))) {
    return page.skip('signed-in user lacks manage_contracts — no Share button to test');
  }

  assert.ok(!(await page.exists('.modal-backdrop')), 'no modal open before clicking Share');
  await page.click('[data-portal-toggle]');
  await page.until(`document.querySelector('.modal-backdrop .modal-card')`);
  assert.ok(await page.exists('.modal-backdrop .modal-card'), 'Share click opens a modal-card dialog');
  assert.includes(await page.text('.modal-backdrop h2'), 'Share Portal Link', 'modal header is the portal panel content');
  // It should no longer render inline in the workspace toolbar/flow.
  assert.ok(!(await page.exists('pb-portal-panel .portal-panel')), 'portal panel no longer renders inline');

  // Clicking the backdrop (outside the card) closes it.
  await page.eval(`document.querySelector('.modal-backdrop').click()`);
  await page.until(`!document.querySelector('.modal-backdrop')`);
  assert.ok(!(await page.exists('.modal-backdrop')), 'clicking the backdrop closes the modal');

  // Re-open and close via the explicit Close button this time.
  await page.click('[data-portal-toggle]');
  await page.until(`document.querySelector('.modal-backdrop .modal-card')`);
  await page.click('.modal-backdrop [data-close]');
  await page.until(`!document.querySelector('.modal-backdrop')`);
  assert.ok(!(await page.exists('.modal-backdrop')), 'Close button closes the modal');
});

test('Share modal: generating a link shows it in the active list', async (page) => {
  if (!page.hasEvent) return page.skip(`event ${page.eventId} not found`);
  await page.openEvent();
  if (!(await page.exists('[data-portal-toggle]'))) {
    return page.skip('signed-in user lacks manage_contracts — no Share button to test');
  }
  await page.click('[data-portal-toggle]');
  await page.until(`document.querySelector('.modal-backdrop .modal-card')`);

  const label = 'PB UI TEST link (safe to delete)';
  await page.setValue('.modal-backdrop [name="label"]', label);
  await page.click('.modal-backdrop [data-create-form] button[type="submit"]');
  await page.until(`document.querySelector('.modal-backdrop .portal-links-list')?.textContent.includes(${JSON.stringify(label)})`);
  assert.includes(await page.text('.modal-backdrop .portal-links-list'), label, 'new link appears in the active links list inside the modal');

  // Clean up: revoke the link we just created (scoped to its own row, in
  // case the fixture event already has other active portal links).
  await page.eval(`(() => {
    const row = Array.from(document.querySelectorAll('.modal-backdrop .portal-link-row')).find(r => r.textContent.includes(${JSON.stringify(label)}));
    row?.querySelector('[data-revoke]')?.click();
  })()`);
  await page.until(`!document.querySelector('.modal-backdrop .portal-links-list')?.textContent.includes(${JSON.stringify(label)})`);
});
