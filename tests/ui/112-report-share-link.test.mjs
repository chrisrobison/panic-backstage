// Two ways to send someone the Settlement Report without them digging
// through the workspace:
//   1. An internal deep link — the Report tab's "Copy Link" button builds
//      `#event-<id>-report`, which app.js's router now understands well
//      enough to open the workspace straight to that tab (still requires a
//      staff/owner login).
//   2. An external, no-login link — the header's "Share" button can now
//      generate a settlement_report-kind portal_tokens link in addition to
//      the pre-existing client_portal one; report-share.html renders it
//      publicly via GET /api/portal/view?token=....
// Both are read-only; the external-link test creates and then revokes its
// own throwaway token so it doesn't leave links behind on the fixture event.
import { test, assert } from './harness.mjs';

test('Report tab: Copy Link button copies a working #event-<id>-report deep link', async (page) => {
  if (!page.hasEvent) return page.skip(`event ${page.eventId} not found`);
  await page.openEvent();
  if (!(await page.exists('[data-goto-tab="report"], a[data-tab="report"]'))) {
    return page.skip('signed-in user lacks view_settlement (or event is private) — no Report tab to test');
  }
  await page.click('a[data-tab="report"]');
  await page.until(`document.querySelector('#report')?.style.display !== 'none'`);
  if (!(await page.exists('[data-copy-report-link]'))) {
    return page.skip('Report tab did not mount pb-event-report with a Copy Link button');
  }

  // Stub the Clipboard API so the test doesn't depend on headless Chrome's
  // clipboard permission plumbing — same spirit as overriding window.confirm
  // for headless dialogs (see dev-environment notes).
  await page.eval(`(() => { window.__copied = null; navigator.clipboard.writeText = (t) => { window.__copied = t; return Promise.resolve(); }; })()`);
  await page.click('[data-copy-report-link]');
  await page.until(`window.__copied`);
  const copied = await page.eval('window.__copied');
  assert.includes(copied, `#event-${page.eventId}-report`, 'Copy Link writes a deep link to this event\'s report tab');

  // Following that link (a fresh hash navigation, as if pasted into the
  // address bar) should land straight on the Report tab, not Overview.
  await page.goto('#events'); // navigate away first so the next goto is a real route change
  await page.waitWorkspace().catch(() => {}); // events list, not a workspace — ignore
  await page.goto(`#event-${page.eventId}-report`);
  await page.waitWorkspace();
  await page.until(`document.querySelector('#report')?.style.display !== 'none'`);
  assert.ok(await page.visible('#report pb-event-report, #report'), 'deep link opens directly on the Report tab');
});

test('Share dialog: can generate and revoke a settlement_report link', async (page) => {
  if (!page.hasEvent) return page.skip(`event ${page.eventId} not found`);
  await page.openEvent();
  if (!(await page.exists('[data-portal-toggle]'))) {
    return page.skip('signed-in user lacks manage_contracts/view_settlement — no Share button to test');
  }
  await page.click('[data-portal-toggle]');
  await page.until(`document.querySelector('.modal-backdrop .modal-card')`);

  const hasKindSelect = await page.exists('.modal-backdrop [name="kind"]');
  if (hasKindSelect) {
    const isSelect = await page.eval(`document.querySelector('.modal-backdrop [name="kind"]')?.tagName`);
    if (isSelect !== 'SELECT') {
      return page.skip('signed-in user can only create one link kind — no dropdown to test');
    }
    const hasReportOption = await page.eval(`Array.from(document.querySelectorAll('.modal-backdrop [name="kind"] option')).some(o => o.value === 'settlement_report')`);
    if (!hasReportOption) return page.skip('settlement_report not offered for this user/event');
    await page.eval(`(() => { const sel = document.querySelector('.modal-backdrop [name="kind"]'); sel.value = 'settlement_report'; sel.dispatchEvent(new Event('change', { bubbles: true })); })()`);
  }

  const label = 'PB UI TEST report link (safe to delete)';
  await page.setValue('.modal-backdrop [name="label"]', label);
  await page.click('.modal-backdrop [data-create-form] button[type="submit"]');
  await page.until(`document.querySelector('.modal-backdrop .portal-links-list')?.textContent.includes(${JSON.stringify(label)})`);

  const rowText = await page.eval(`(() => {
    const row = Array.from(document.querySelectorAll('.modal-backdrop .portal-link-row')).find(r => r.textContent.includes(${JSON.stringify(label)}));
    return row ? row.querySelector('.portal-link-url')?.value : null;
  })()`);
  assert.includes(rowText || '', 'report-share.html', 'the new link points at report-share.html, not portal.html');
  assert.includes(await page.text('.modal-backdrop .portal-links-list'), 'Settlement Report', 'the link row shows a Settlement Report kind badge');

  // Clean up: revoke it, then close the dialog — leaving it open would trip
  // up 95-share-portal.test.mjs's "no modal open before clicking Share"
  // precondition, since re-navigating to the *same* #event-<id> hash the
  // next test's openEvent() uses doesn't fire a hashchange (identical
  // value), so the workspace never remounts to clear it on its own.
  await page.eval(`(() => {
    const row = Array.from(document.querySelectorAll('.modal-backdrop .portal-link-row')).find(r => r.textContent.includes(${JSON.stringify(label)}));
    row?.querySelector('[data-revoke]')?.click();
  })()`);
  await page.until(`!document.querySelector('.modal-backdrop .portal-links-list')?.textContent.includes(${JSON.stringify(label)})`);
  await page.click('.modal-backdrop [data-close]');
  await page.until(`!document.querySelector('.modal-backdrop')`);
});
