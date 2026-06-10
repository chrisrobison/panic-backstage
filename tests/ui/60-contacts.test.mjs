// Contacts (CRM) page: top-level nav item routes to the list, which renders
// KPI cards, a searchable/sortable table, and pagination. Non-destructive —
// only reads and exercises client-side search/sort.
import { test, assert } from './harness.mjs';

test('Contacts nav item routes to the contacts page with KPIs and a table', async (page) => {
  assert.ok(await page.exists('[data-nav="contacts"]'), 'Contacts nav item present (admin)');
  await page.goto('#contacts');
  await page.until(`document.querySelector('pb-contacts-page .contacts-table, pb-contacts-page .empty-note')`);
  assert.equal(await page.count('.contacts-kpis .kpi-card'), 4, 'four KPI cards render');
  assert.ok(await page.exists('.contacts-table tbody tr'), 'contact rows render');
  assert.ok(await page.exists('.pager [data-page="next"]'), 'pager present');
});

test('Contacts search narrows the result set', async (page) => {
  await page.goto('#contacts');
  await page.until(`document.querySelector('pb-contacts-page .contacts-table')`);
  const before = await page.count('.contacts-table tbody tr');
  await page.setValue('[data-q]', 'levy');
  // debounced 250ms + network; wait for the table to settle to the filtered set
  await page.until(`document.querySelectorAll('pb-contacts-page .contacts-table tbody tr').length > 0 && document.querySelectorAll('pb-contacts-page .contacts-table tbody tr').length < ${before}`);
  const after = await page.count('.contacts-table tbody tr');
  assert.ok(after > 0 && after < before, `search narrowed rows (${before} -> ${after})`);
});
