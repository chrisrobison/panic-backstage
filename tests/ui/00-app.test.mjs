// App boot + global navigation chrome.
import { test, assert } from './harness.mjs';

test('dashboard boots with the sidebar nav', async (page) => {
  await page.goto('#dashboard');
  assert.ok(
    await page.until(`document.querySelector('pb-events-upcoming') && document.querySelector('pb-events-upcoming').children.length>0`),
    'pb-events-upcoming renders content',
  );
  assert.ok(await page.exists('.side-nav'), 'sidebar nav is present');
});

test('classic metrics dashboard still renders at #dashboard-metrics', async (page) => {
  await page.goto('#dashboard-metrics');
  assert.ok(
    await page.until(`document.querySelector('pb-dashboard') && document.querySelector('pb-dashboard').children.length>0`),
    'pb-dashboard renders content',
  );
});

test('sidebar nav items expose title tooltips for the collapsed rail', async (page) => {
  await page.goto('#dashboard');
  await page.until(`document.querySelector('.side-nav a[data-nav="dashboard"]')`);
  assert.equal(await page.attr('.side-nav a[data-nav="dashboard"]', 'title'), 'Dashboard', 'Dashboard link has a title attribute');
  assert.ok(await page.exists('.nav-group[data-group="events"] .nav-parent[title]'), 'Events parent button has a title');
});

test('Help nav is a collapsible group built from the help sections', async (page) => {
  await page.goto('#dashboard');
  await page.until(`document.querySelector('.nav-group[data-group="help"]')`);
  assert.ok(await page.exists('.nav-group[data-group="help"] .nav-parent'), 'Help group has a parent toggle');
  assert.atLeast(await page.count('.nav-group[data-group="help"] .nav-children a'), 2, 'Help group has child links');
});

// Note: there used to be a test here asserting Promote's exact position in
// the sidebar (flat top-level link, not grouped). Removed — the nav tree is
// fully admin-customizable data (nav_items table, see src/NavItems.php /
// Admin > Navigation), so a specific venue can legitimately move, nest, or
// hide any item including Promote. Asserting one fixed layout here just
// bakes in a snapshot of whatever an admin happened to configure and breaks
// the moment they change it (which is what happened: Promote's top-level
// item was hidden via the Navigation Manager, not a code regression). The
// nav tree's *mechanism* — that the real sidebar derives from nav_items and
// reacts to add/hide — is covered generically in 97-nav-manager.test.mjs.
