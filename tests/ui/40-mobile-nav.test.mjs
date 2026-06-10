// Mobile navigation: the bottom bar no longer overflows (Pipeline removed) and
// the slide-in drawer opens/closes. Runs at a phone viewport, then restores
// desktop metrics so it doesn't affect other tests.
import { test, assert } from './harness.mjs';

const PHONE = [390, 844];

test('mobile bottom bar drops Pipeline and keeps Help reachable', async (page) => {
  await page.goto('#dashboard');
  await page.setViewport(...PHONE);
  try {
    assert.ok(await page.visible('.mobile-tabs'), 'bottom tab bar is shown on mobile');
    assert.notOk(await page.exists('.mobile-tabs a[data-nav="pipeline"]'), 'Pipeline is not in the bottom bar');
    assert.ok(await page.exists('.mobile-tabs a[data-nav="help"]'), 'Help tab is present');
    // 5-column grid; with Pipeline gone the 5 tabs fill exactly one row (no
    // wrap to a clipped second row that hid Help).
    assert.equal(await page.count('.mobile-tabs a'), 5, 'bottom bar has 5 tabs (admin user)');
  } finally {
    await page.resetViewport();
  }
});

test('menu button opens the drawer; backdrop and navigation close it', async (page) => {
  await page.goto('#dashboard');
  await page.setViewport(...PHONE);
  try {
    assert.ok(await page.visible('[data-drawer-open]'), 'menu (hamburger) button is shown on mobile');
    assert.notOk(await page.exists('.app-shell.drawer-open'), 'drawer starts closed');

    await page.click('[data-drawer-open]');
    assert.ok(await page.exists('.app-shell.drawer-open'), 'drawer opens on menu tap');
    assert.ok(await page.visible('.sidebar .side-nav a[data-nav="dashboard"]'), 'full nav is on-screen in the drawer');

    await page.click('.drawer-backdrop');
    assert.notOk(await page.exists('.app-shell.drawer-open'), 'tapping the backdrop closes the drawer');

    // Re-open and navigate via a drawer link — navigating should close it.
    await page.click('[data-drawer-open]');
    assert.ok(await page.exists('.app-shell.drawer-open'), 'drawer re-opens');
    await page.click('.sidebar .side-nav a[data-nav="calendar"]');
    assert.notOk(await page.exists('.app-shell.drawer-open'), 'navigation closes the drawer');
  } finally {
    await page.resetViewport();
  }
});

test('the drawer menu button is hidden on desktop widths', async (page) => {
  await page.goto('#dashboard');
  await page.resetViewport();
  assert.notOk(await page.visible('[data-drawer-open]'), 'no hamburger on desktop (the rail is always visible)');
});
