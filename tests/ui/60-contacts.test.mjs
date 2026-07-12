// Contacts (CRM) page: top-level nav item routes to the list, which renders
// KPI cards, a searchable/sortable table, and pagination. Non-destructive —
// only reads and exercises client-side search/sort.
import { test, assert } from './harness.mjs';

test('Contacts nav item routes to the contacts page with KPIs and a table', async (page) => {
  assert.ok(await page.exists('[data-nav="contacts"]'), 'Contacts nav item present (admin)');
  await page.goto('#contacts');
  await page.until(`document.querySelector('pb-contacts-page .contacts-table, pb-contacts-page .empty-note')`);
  assert.equal(await page.count('.contacts-kpis .kpi-card'), 4, 'four KPI cards render');
  if (!(await page.exists('.contacts-table tbody tr'))) {
    return page.skip('no contacts in this database (fresh seed has none) — row/pager checks need at least one');
  }
  assert.ok(await page.exists('.contacts-table tbody tr'), 'contact rows render');
  assert.ok(await page.exists('.pager [data-page="next"]'), 'pager present');
});

test('Contacts search narrows the result set', async (page) => {
  await page.goto('#contacts');
  await page.until(`document.querySelector('pb-contacts-page .contacts-table, pb-contacts-page .empty-note')`);
  const before = await page.count('.contacts-table tbody tr');
  if (before < 2) {
    return page.skip(`need at least 2 contacts to prove search narrows the set (found ${before})`);
  }
  // Derive the search term from real data instead of a hardcoded name, so
  // this works against any seeded database, not just one particular contact
  // in a specific dev DB. A short prefix of the first row's name is enough
  // to exercise narrowing without (usually) matching every row.
  const firstName = await page.text('.contacts-table tbody tr:first-child td:first-child');
  const term = (firstName || '').trim().slice(0, 4);
  if (term.length < 2) {
    return page.skip(`could not derive a usable search term from the first row (${JSON.stringify(firstName)})`);
  }
  await page.setValue('[data-q]', term);
  // debounced 250ms + network; wait for the table to settle to the filtered set
  await page.until(`document.querySelectorAll('pb-contacts-page .contacts-table tbody tr').length > 0 && document.querySelectorAll('pb-contacts-page .contacts-table tbody tr').length <= ${before}`);
  const after = await page.count('.contacts-table tbody tr');
  // Every remaining row must actually match the term client-side-visibly —
  // proves the table was filtered, not just left alone at <= its prior size.
  const rowTexts = await page.eval(
    "[...document.querySelectorAll('pb-contacts-page .contacts-table tbody tr')].map(tr => tr.textContent.toLowerCase())"
  );
  const allMatch = rowTexts.length > 0 && rowTexts.every((t) => t.includes(term.toLowerCase()));
  assert.ok(after > 0 && after <= before && allMatch, `search("${term}") narrowed to matching rows (${before} -> ${after})`);
});
