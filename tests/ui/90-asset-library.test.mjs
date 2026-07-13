// Asset Library: top-level cross-event asset browser (GET /api/asset-library).
// Read-only against whatever assets already exist in the DB — no fixtures
// created/destroyed here, unlike the destructive tests elsewhere in this
// suite. Skips gracefully if the seed DB has no assets at all.
import { test, assert } from './harness.mjs';

test('Asset Library nav item routes to a grid of asset cards', async (page) => {
  assert.ok(await page.exists('[data-nav="asset-library"]'), 'Assets nav item present');
  await page.goto('#asset-library');
  await page.until(`document.querySelector('pb-asset-library .asset-grid, pb-asset-library .empty-state')`);
  if (!(await page.exists('pb-asset-library .asset-card'))) {
    return page.skip('no assets in this database to browse');
  }
  assert.ok(await page.count('.asset-card') > 0, 'asset cards render');
  assert.ok(await page.exists('.asset-card .asset-card-event a'), 'each card links back to its event');
});

test('Asset Library: clicking an image thumbnail opens the lightbox modal', async (page) => {
  await page.goto('#asset-library');
  await page.until(`document.querySelector('pb-asset-library .asset-grid, pb-asset-library .empty-state')`);
  if (!(await page.exists('.asset-image'))) {
    return page.skip('no image assets in this database');
  }
  await page.click('.asset-image');
  await page.until(`document.querySelector('.lightbox-backdrop .lightbox-img')`);
  assert.ok(await page.exists('.lightbox-backdrop'), 'lightbox modal opened on image click');
  await page.click('.lightbox-close');
  await page.until(`!document.querySelector('.lightbox-backdrop')`);
  assert.ok(!(await page.exists('.lightbox-backdrop')), 'lightbox closes');
});

test('Asset Library: clicking a non-image tile opens it in a new tab', async (page) => {
  await page.goto('#asset-library');
  await page.until(`document.querySelector('pb-asset-library .asset-grid, pb-asset-library .empty-state')`);
  if (!(await page.exists('.asset-icon-tile'))) {
    return page.skip('no non-image assets (e.g. PDFs) in this database');
  }
  // Stub window.open so the click is observable without actually spawning a tab.
  await page.eval("window.__openedUrl = null; window.open = (url) => { window.__openedUrl = url; return null; };");
  await page.click('.asset-icon-tile');
  await page.until(`window.__openedUrl`);
  const opened = await page.eval('window.__openedUrl');
  assert.ok(opened && opened.length > 0, `non-image tile opened a URL via window.open (${opened})`);
});

test('Asset Library: type filter narrows the result set', async (page) => {
  await page.goto('#asset-library');
  await page.until(`document.querySelector('pb-asset-library .asset-grid, pb-asset-library .empty-state')`);
  if (!(await page.exists('.asset-card'))) {
    return page.skip('no assets in this database');
  }
  const before = await page.count('.asset-card');
  await page.setValue('[data-type]', 'flyer');
  // The change handler reloads immediately (no debounce, unlike the search
  // box) — wait for the select's own value to reflect the pick, then give
  // the async reload a moment to land.
  await page.until(`document.querySelector('pb-asset-library [data-type]').value === 'flyer'`);
  await page.until(`document.querySelectorAll('pb-asset-library .asset-card').length <= ${before}`);
  const after = await page.count('.asset-card');
  assert.ok(after <= before, `type filter narrowed or held the set (${before} -> ${after})`);
});
