// App boot + global navigation chrome.
import { test, assert } from './harness.mjs';

test('dashboard boots with the sidebar nav', async (page) => {
  await page.goto('#dashboard');
  assert.ok(
    await page.until(`document.querySelector('pb-dashboard') && document.querySelector('pb-dashboard').children.length>0`),
    'pb-dashboard renders content',
  );
  assert.ok(await page.exists('.side-nav'), 'sidebar nav is present');
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

test('Promote is a top-level nav link and has a Settings entry under Settings', async (page) => {
  await page.goto('#dashboard');
  await page.until(`document.querySelector('.side-nav a[data-nav="promote"]')`);
  assert.ok(await page.exists('.side-nav > a[data-nav="promote"][href="#promote"]'), 'Promote is a flat top-level link');
  assert.notOk(await page.exists('.nav-group[data-group="promote"]'), 'Promote is not wrapped in a nav-group');
  assert.ok(await page.exists('.nav-group[data-group="settings"] a[data-nav="promote-settings"]'), 'Promote Settings lives under the Settings group');
});
