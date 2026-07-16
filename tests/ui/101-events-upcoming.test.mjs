// Events ▸ Upcoming — the card-based ticketing-aware alternative to the plain
// List table (see public/assets/event-upcoming.js). Non-destructive: only
// exercises client-side filters/search/status toggles/date-range presets and
// checks the DOM reacts; never submits a form or mutates event data.
import { test, assert } from './harness.mjs';

test('Upcoming nav item is present and routes to the card view', async (page) => {
  assert.ok(await page.exists('.side-nav [data-nav="upcoming"]'), 'sidebar has an Upcoming link under Events');
  await page.goto('#upcoming');
  const mounted = await page.cdp.until(`document.querySelector('pb-events-upcoming .upcoming-page')`);
  assert.ok(mounted, 'pb-events-upcoming mounts the .upcoming-page shell');
  assert.ok(await page.exists('.side-nav [data-nav="upcoming"].active'), 'Upcoming nav link is marked active');
});

test('Upcoming view renders a stats footer and a filters sidebar', async (page) => {
  await page.goto('#upcoming');
  await page.cdp.until(`document.querySelector('pb-events-upcoming .upcoming-page')`);
  assert.equal(await page.count('.upcoming-footer .metric-card'), 4, 'stats footer has 4 tiles (count/tickets/revenue/capacity)');
  assert.ok(await page.exists('.upcoming-sidebar [data-filter-search]'), 'sidebar search input present');
  assert.ok(await page.exists('.upcoming-sidebar [data-filter-type]'), 'sidebar event type select present');
  assert.equal(await page.count('.upcoming-sidebar [data-filter-status]'), 5, 'sidebar has 5 status checkboxes');
  assert.ok(await page.exists('.upcoming-sidebar [data-minical]'), 'mini calendar container present');
  assert.ok(await page.exists('.upcoming-sidebar .upcoming-export'), 'Export Events button present');
});

test('Search filter narrows the card list client-side', async (page) => {
  await page.goto('#upcoming');
  await page.cdp.until(`document.querySelector('pb-events-upcoming .upcoming-page')`);
  const before = await page.count('.upcoming-card');
  await page.setValue('[data-filter-search]', 'zzz-no-such-event-zzz');
  assert.equal(await page.count('.upcoming-card'), 0, 'a nonsense search yields no cards');
  assert.ok(await page.exists('.upcoming-cards .empty-state'), 'empty state shown when nothing matches');
  await page.setValue('[data-filter-search]', '');
  const after = await page.count('.upcoming-card');
  assert.equal(after, Math.min(before, after >= before ? after : before), 'clearing search restores cards');
  assert.ok(after > 0 || before === 0, 'cards reappear after clearing the search (or there were none to begin with)');
});

test('Status checkboxes and Clear all reactively filter without a network round-trip', async (page) => {
  await page.goto('#upcoming');
  await page.cdp.until(`document.querySelector('pb-events-upcoming .upcoming-page')`);
  // Unchecking every status bucket should hide every event that has a bucket
  // (badge-less "not yet announced" events, if any, still show — see bucketFor()).
  for (const key of ['on_sale', 'low_tickets', 'sold_out', 'free', 'canceled']) {
    const box = `[data-filter-status="${key}"]`;
    if (await page.exists(box)) {
      await page.eval(`(()=>{const e=document.querySelector(${JSON.stringify(`.upcoming-sidebar ${box}`)});if(e&&e.checked){e.click();}})()`);
    }
  }
  assert.ok(await page.count('.badge.sales-on_sale, .badge.sales-low_tickets, .badge.sales-sold_out, .badge.sales-free') === 0, 'no bucketed sales badges render once every bucket is unchecked');
  await page.click('[data-clear-filters]');
  assert.ok(await page.exists('.upcoming-sidebar [data-filter-status="on_sale"]:checked'), 'Clear all restores the default-checked status filters');
});

test('Clicking a card opens the event; clicking its "..." menu does not', async (page) => {
  await page.goto('#upcoming');
  await page.cdp.until(`document.querySelector('pb-events-upcoming .upcoming-page')`);
  const before = await page.count('.upcoming-card');
  if (before === 0) return page.skip('no upcoming events in range to click');
  const id = await page.attr('.upcoming-card', 'data-event-card');

  // The "..." menu toggle sits inside the card but must not navigate away.
  await page.click('.upcoming-card [data-menu-toggle]');
  assert.ok(await page.exists('.upcoming-menu:not([hidden])'), 'menu opens without navigating');
  assert.ok(!(await page.eval('location.hash')).includes('event-'), 'hash unchanged after opening the menu');

  await page.click('.upcoming-card');
  const mounted = await page.cdp.until(`document.querySelector('pb-event-workspace .workspace-tabs')`);
  assert.ok(mounted, 'clicking the card body opens the event workspace');
  assert.equal(await page.eval('location.hash'), `#event-${id}`, "hash points at the clicked card's event");
});

test('Date range preset switches trigger a reload and update the range label', async (page) => {
  await page.goto('#upcoming');
  await page.cdp.until(`document.querySelector('pb-events-upcoming .upcoming-page')`);
  const before = await page.text('[data-range-label]');
  await page.eval(`(()=>{const e=document.querySelector('[data-range]');e.value='90';e.dispatchEvent(new Event('change',{bubbles:true}));})()`);
  const reloaded = await page.cdp.until(`document.querySelector('pb-events-upcoming .upcoming-page')`);
  assert.ok(reloaded, 'view re-mounts after switching the date range preset');
  const after = await page.text('[data-range-label]');
  assert.ok(after && after !== before, 'range label reflects the new 90-day window');
});
