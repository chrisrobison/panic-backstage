// Admin > Navigation ("Navigation Manager", src/NavItems.php +
// public/assets/nav-manager.js). Confirms the core claim of this feature:
// the app shell's real sidebar is *derived* from nav_items, not just a
// lookalike admin screen — creating/hiding an item here changes what
// actually renders in .side-nav after a reload.
//
// Destructive against throwaway nav items only, cleaned up via a direct API
// call in `finally` (same convention as 80-event-sessions.test.mjs) so
// cleanup still happens even if a UI assertion above it fails.
import { test, assert } from './harness.mjs';

// A hard, force-fresh reload — needed rather than page.navigate() here
// because CDP's Page.navigate to a URL identical to the current one (as
// happens when this test reloads to the same #dashboard hash the run.mjs
// bootstrap already visited) is a same-document no-op in Chromium: it
// resolves Page.loadEventFired without re-executing app.js, so the shell's
// in-memory nav_items would silently stay whatever it was at first boot.
// Page.reload with ignoreCache always re-fetches and re-runs everything,
// which is exactly what's needed to prove the sidebar re-derives from a
// change just made through the Navigation Manager.
async function hardReload(page, hash) {
  if (hash) await page.eval(`location.hash = ${JSON.stringify(hash)}`);
  await page.cdp.send('Page.reload', { ignoreCache: true });
  await page.cdp.onceEvent('Page.loadEventFired');
}

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

const LABEL = 'PB UI TEST nav root (safe to delete)';
const CHILD_LABEL = 'PB UI TEST nav child (safe to delete)';
// Scoped to our own modal's form — other panels' modals (e.g. the Share/
// portal panel in 95-share-portal.test.mjs, which doesn't close its modal at
// the end of its test) may still be open in this shared browser-page test
// session, and an unscoped `.modal-backdrop [name="label"]` would match
// whichever one is first in DOM order instead of ours.
const MODAL = '.modal-backdrop:has([data-form="new-nav-item"])';

test('Admin > Navigation renders the seeded tree and a live preview', async (page) => {
  await page.goto('#admin-navigation');
  await page.until(`document.querySelector('.nav-manager-list')`);

  const listText = await page.text('.nav-manager-list');
  assert.includes(listText, 'Dashboard', 'seeded Dashboard item renders in the list');
  assert.includes(listText, 'Admin', 'seeded Admin group renders in the list');

  const previewMarks = await page.count('.nav-manager-preview .side-nav a, .nav-manager-preview .side-nav .nav-group');
  assert.atLeast(previewMarks, 1, 'Live Preview pane renders nav markup from the same data');
});

test('Creating and hiding a nav item changes the real sidebar', async (page) => {
  await page.goto('#admin-navigation');
  await page.until(`document.querySelector('[data-add-root]')`);

  let rootId = null;
  try {
    // --- create a throwaway top-level item via the real "+ Add Item" UI ---
    await page.click('[data-add-root]');
    await page.until(`document.querySelector('${MODAL}')`);
    await page.setValue(`${MODAL} [name="label"]`, LABEL);
    await page.setValue(`${MODAL} [name="link"]`, 'admin-users');
    await page.click(`${MODAL} button[type="submit"]`);
    await page.until(`document.querySelector('[data-nav-edit-form]')`);

    rootId = await page.eval(`Number(document.querySelector('[data-nav-edit-form]')?.dataset.itemId || 0)`);
    assert.ok(rootId, 'new item is selected into the edit pane after creation');
    assert.includes(await page.text('.nav-manager-list'), LABEL, 'new item appears in the Navigation Items list');

    // --- it shows up in the REAL app shell sidebar after a reload ---
    await hardReload(page, 'dashboard');
    await page.until(`document.querySelector('.side-nav')`);
    assert.includes(await page.text('.side-nav'), LABEL, 'new nav item renders in the real sidebar — the shell derives its nav from nav_items');

    // --- add a child under it from the edit pane's "+ Add Child Item" ---
    await page.goto('#admin-navigation');
    await page.until(`document.querySelector('.nav-manager-list')`);
    await page.click(`.nav-row[data-row-id="${rootId}"]`);
    await page.until(`document.querySelector('[data-nav-edit-form][data-item-id="${rootId}"]')`);
    await page.click(`[data-add-child="${rootId}"]`);
    await page.until(`document.querySelector('${MODAL}')`);
    await page.setValue(`${MODAL} [name="label"]`, CHILD_LABEL);
    await page.setValue(`${MODAL} [name="link"]`, 'admin-users');
    await page.click(`${MODAL} button[type="submit"]`);
    await page.until(`document.querySelector('[data-nav-edit-form]')`);
    assert.includes(await page.text('.nav-manager-list'), CHILD_LABEL, 'child item appears nested under the root in the list');

    // --- hide the root item and confirm it disappears from the real sidebar ---
    await page.click(`.nav-row[data-row-id="${rootId}"]`);
    await page.until(`document.querySelector('[data-nav-edit-form][data-item-id="${rootId}"]')`);
    await page.click('[data-nav-edit-form] [name="visible"]');
    await page.click('[data-nav-edit-form] button[type="submit"]');
    await page.until(`document.querySelector('[name="visible"]') && document.querySelector('[name="visible"]').checked === false`);

    await hardReload(page, 'dashboard');
    await page.until(`document.querySelector('.side-nav')`);
    const sidebarText = await page.text('.side-nav');
    assert.notOk(sidebarText.includes(LABEL), 'hidden nav item no longer renders in the real sidebar');
  } finally {
    if (rootId) await apiFetch(page, `/nav-items/${rootId}`, { method: 'DELETE' }).catch(() => {});
  }
});
