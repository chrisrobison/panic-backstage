// Self-guided in-app tours (public/assets/tour.js): the "Take a tour" picker
// in the Help sidebar group, stepping through a tour's spotlight + tooltip,
// Back/Next/Skip/Escape, and the Dashboard onboarding card's own "Take the
// tour" shortcut. Purely client-side UI state — nothing persisted server
// side beyond a localStorage completion flag, so this is fully
// non-destructive.
import { test, assert } from './harness.mjs';

async function openPicker(page) {
  // The Help group starts collapsed unless it owns the active route.
  if (!(await page.visible('[data-action="open-tour-picker"]'))) {
    await page.click('[data-group-toggle="help"]');
  }
  await page.click('[data-action="open-tour-picker"]');
  await page.until(`document.querySelector('.pb-tour-picker')`);
}

test('Take a tour: picker lists the welcome tour and launches it', async (page) => {
  await page.goto('#dashboard');
  await openPicker(page);
  assert.ok(await page.exists('[data-tour-key="welcome"]'), 'welcome tour card present in the picker');

  await page.click('[data-tour-key="welcome"]');
  await page.until(`document.querySelector('[data-tour-tip]')`);
  assert.equal(await page.text('[data-tour-tip] h3'), 'Welcome to Panic Backstage', 'first (centered) step renders');
  assert.ok(await page.visible('.pb-tour-tip-center'), 'un-anchored intro step is centered, no spotlight');
  assert.notOk(await page.exists('[data-tour-spotlight]'), 'no spotlight on a selector-less step');

  await page.click('[data-tour-next]');
  await page.until(`document.querySelector('[data-tour-spotlight]')`);
  assert.includes(await page.text('.pb-tour-tip-eyebrow'), 'step 2 of', 'advanced to step 2');

  await page.click('[data-tour-prev]');
  await page.until(`document.querySelector('.pb-tour-tip-center')`);
  assert.equal(await page.text('[data-tour-tip] h3'), 'Welcome to Panic Backstage', 'Back returns to step 1');

  await page.click('[data-tour-skip]');
  assert.notOk(await page.exists('.pb-tour-overlay'), 'Skip tour closes the overlay');
});

test('Take a tour: Escape closes an active step', async (page) => {
  await page.goto('#dashboard');
  await openPicker(page);
  await page.click('[data-tour-key="welcome"]');
  await page.until(`document.querySelector('[data-tour-tip]')`);
  await page.eval(`document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))`);
  assert.notOk(await page.exists('.pb-tour-overlay'), 'Escape closes the tour');
});

test('Take a tour: finishing a tour marks it completed in the picker', async (page) => {
  // Completion is written via window.PBConsent.savePref(), same as the
  // existing pb.navCollapsed/pb.navGroups UI prefs — it no-ops until the
  // cookie/preference consent banner has been accepted. Grant it here so
  // this test reflects a real accepted-consent browser.
  await page.eval(`localStorage.setItem('pb.cookieConsent', 'all')`);
  await page.goto('#dashboard');
  await openPicker(page);
  if (!(await page.exists('[data-tour-key="running-an-event"]'))) return page.skip('running-an-event tour not available to this user');
  await page.click('[data-tour-key="running-an-event"]');
  await page.until(`document.querySelector('[data-tour-tip]')`);

  // Click Next/Finish until the overlay closes on its own (last step's
  // button is labeled "Finish" but carries the same [data-tour-next]
  // handler) — bounded so a regression that stops advancing fails loudly
  // instead of hanging.
  for (let i = 0; i < 20 && (await page.exists('.pb-tour-overlay')); i++) {
    await page.click('[data-tour-next]');
  }
  assert.notOk(await page.exists('.pb-tour-overlay'), 'tour overlay closed after the last step');

  await openPicker(page);
  assert.includes(await page.text('[data-tour-key="running-an-event"]'), 'Completed', 'picker shows the tour as completed');
  await page.click('[data-tour-close]');
});

test('Dashboard "Take the tour" launches the venue-setup tour directly', async (page) => {
  await page.goto('#dashboard');
  if (!(await page.exists('[data-start-tour]'))) return page.skip('onboarding checklist not shown for this user (already dismissed, or not venue_admin)');
  await page.click('[data-start-tour]');
  await page.until(`document.querySelector('[data-tour-tip]')`);
  assert.equal(await page.text('[data-tour-tip] h3'), 'Set up your venue', 'venue-setup tour opened without going through the picker');
  await page.eval(`document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))`);
});
