// Venue-wide Reports page + per-event Report tab.
import { test, assert } from './harness.mjs';

test('Reports is a top-level nav link', async (page) => {
  await page.goto('#dashboard');
  await page.until(`document.querySelector('.side-nav a[data-nav="reports"]')`);
  assert.ok(await page.exists('.side-nav > a[data-nav="reports"][href="#reports"]'), 'Reports is a flat top-level link');
});

test('Reports Overview renders KPI cards and a trend chart', async (page) => {
  await page.goto('#reports');
  assert.ok(
    await page.until(`document.querySelector('pb-reports-page .metric-grid')`),
    'pb-reports-page renders the KPI metric grid',
  );
  assert.atLeast(await page.count('pb-reports-page .metric-card'), 3, 'at least a few KPI cards render');
  assert.ok(await page.exists('pb-reports-page .rpt-chart'), 'monthly trend chart (SVG) renders');
  // The category breakdown renders either a bar list (when there are
  // categorized ledger entries in range) or an empty-state message — the
  // seeded CI demo data has events but zero event_ledger_entries rows, so
  // this must not hard-require the populated case.
  assert.ok(
    await page.exists('pb-reports-page .rpt-cat-grid .rpt-cat-list') || await page.exists('pb-reports-page .rpt-cat-grid .empty-state'),
    'category breakdown renders either bars or an empty state',
  );
});

test('Reports KPI card values never wrap onto a second line, even for large/negative totals', async (page) => {
  await page.goto('#reports');
  await page.until(`document.querySelector('pb-reports-page [data-rpt-tab="overview"]')`);
  await page.click('pb-reports-page [data-rpt-tab="overview"]');
  await page.until(`document.querySelector('pb-reports-page .metric-grid')`);
  // Simulate a bigger venue with a losing month. This previously wrapped
  // mid-token (e.g. "$812.35" / "K") because the KPI value's font-size was a
  // vw-relative clamp() sized off the *whole viewport*, not this grid's
  // actual per-card column width — six narrow cards fit on a wide viewport,
  // but the vw unit didn't know that.
  await page.eval(`
    const el = document.querySelector('pb-reports-page');
    el.overviewData.totals = Object.assign({}, el.overviewData.totals, {
      gross_revenue: 1040392.55, total_costs: 812345.10, venue_net: -228047.45,
      margin_pct: -21.9, avg_net_per_event: -6584.32,
    });
    el.render();
  `);
  const wrapped = await page.eval(`
    Array.from(document.querySelectorAll('pb-reports-page .metric-card strong')).some((s) => {
      const lineHeight = parseFloat(getComputedStyle(s).lineHeight) || 1;
      return s.getBoundingClientRect().height > lineHeight * 1.5;
    })
  `);
  assert.notOk(wrapped, 'no KPI card value wraps onto a second line for a large/negative total');
});

test('Reports Settlements tab lists events with an export button', async (page) => {
  await page.goto('#reports');
  await page.until(`document.querySelector('pb-reports-page [data-rpt-tab="overview"]')`);
  await page.click('pb-reports-page [data-rpt-tab="settlements"]');
  assert.ok(
    await page.until(`document.querySelector('pb-reports-page [data-export-csv]')`),
    'Settlements tab renders with an Export CSV button',
  );
  assert.atLeast(await page.count('pb-reports-page table.data-table tbody tr'), 1, 'settlement rows render');
});

test('Reports filter bar changing the status re-fetches without error', async (page) => {
  await page.goto('#reports');
  await page.until(`document.querySelector('pb-reports-page [data-rpt-tab="overview"]')`);
  // The previous test leaves the component on the Settlements tab, and since
  // the hash is already #reports this goto() is a no-op (no hashchange, no
  // remount) — switch back to Overview explicitly rather than relying on a
  // fresh instance.
  await page.click('pb-reports-page [data-rpt-tab="overview"]');
  await page.until(`document.querySelector('pb-reports-page [data-status]')`);
  await page.setValue('pb-reports-page [data-status]', 'completed');
  assert.ok(
    await page.until(`document.querySelector('pb-reports-page .metric-grid')`),
    'Overview re-renders after changing the status filter',
  );
});

test('Event workspace has a Report tab that renders a P&L statement', async (page) => {
  await page.openEvent();
  const hasReportTab = await page.exists('.workspace-tabs a[data-tab="report"]');
  if (!hasReportTab) page.skip('current UI_EVENT_ID has no Report tab (private event or missing view_settlement)');
  await page.click('.workspace-tabs a[data-tab="report"]');
  assert.ok(
    await page.until(`document.querySelector('pb-event-report .er-summary')`),
    'pb-event-report renders the P&L summary card',
  );
  assert.ok(await page.exists('pb-event-report [data-print-report]'), 'Print button is present');
});
