// Opportunities module, Phase 2: nav gating + the Discover dashboard.
// docs/OPPORTUNITIES-IMPLEMENTATION.md / docs/opportunity-ui/opportunity-ui.txt.
import { test, assert } from './harness.mjs';

test('Opportunities nav group renders for an authorized (venue_admin) user, with all five children', async (page) => {
  await page.goto('#dashboard');
  await page.until(`document.querySelector('.side-nav a[data-nav="opportunities"]')`);
  assert.ok(await page.exists('.side-nav a[data-nav="opportunities"]'), 'Discover link is present');
  assert.ok(await page.exists('.side-nav a[data-nav="opportunities-conferences"]'), 'Conferences link is present');
  assert.ok(await page.exists('.side-nav a[data-nav="opportunities-companies"]'), 'Companies link is present');
  assert.ok(await page.exists('.side-nav a[data-nav="opportunities-pipeline"]'), 'Pipeline link is present');
  assert.ok(await page.exists('.side-nav a[data-nav="opportunities-notes"]'), 'Notes link is present');
});

test('Discover dashboard loads real data from the API and renders KPI cards + panels', async (page) => {
  await page.goto('#opportunities');
  assert.ok(
    await page.until(`document.querySelector('pb-opportunities-discover') && document.querySelector('.opp-kpis')`),
    'pb-opportunities-discover renders its KPI row',
  );
  assert.equal(await page.count('.opp-kpis .kpi-card'), 5, 'exactly 5 KPI cards render (Open Opportunities, Projected Revenue, Upcoming Conferences, Empty Nights to Fill, Follow-ups Due)');
  assert.ok(await page.exists('.dashboard-grid'), 'the Best Opportunities / Upcoming Conferences row renders');
  assert.ok(await page.exists('.opp-panel-row-3'), 'the Venue Availability / Suggestions / Recent Notes row renders');
  const kpiValue = await page.text('.opp-kpis .kpi-card .kpi-value');
  assert.ok(kpiValue !== null && kpiValue.trim().length > 0, 'the first KPI card has a rendered value (not blank/undefined)');
  assert.ok(await page.exists('a[href="#opportunities-pipeline"]'), '"New Opportunity" links to a real (if placeholder) destination');
});

test('the nav active-state highlights Discover while on #opportunities', async (page) => {
  await page.goto('#opportunities');
  await page.until(`document.querySelector('.side-nav a[data-nav="opportunities"]')`);
  assert.equal(await page.attr('.side-nav a[data-nav="opportunities"]', 'class'), 'active', 'Discover link carries .active');
  assert.ok(await page.exists('.nav-group.active .nav-parent'), 'the owning Opportunities nav group is marked active/open');
});

test('clicking the Conferences nav item routes to the real Conferences list (placeholder retired in Phase 3)', async (page) => {
  await page.goto('#opportunities');
  await page.until(`document.querySelector('.side-nav a[data-nav="opportunities-conferences"]')`);
  await page.click('.side-nav a[data-nav="opportunities-conferences"]');
  assert.ok(
    await page.until(`document.querySelector('pb-opportunities-conferences-list')`),
    'pb-opportunities-conferences-list mounts for #opportunities-conferences',
  );
});

test('a numeric opportunity-detail deep link for a nonexistent id shows a safe error, not a crash (placeholder retired in Phase 5)', async (page) => {
  await page.goto('#opportunities-999999');
  assert.ok(
    await page.until(`document.querySelector('pb-opportunities-detail')`),
    'pb-opportunities-detail mounts for #opportunities-{id}',
  );
  assert.ok(
    await page.until(`document.querySelector('pb-opportunities-detail .error-text')`),
    'a nonexistent opportunity id renders the shared "Something went wrong" error state instead of throwing',
  );
});
