// Opportunities module, Phase 4: Company list + Company Detail + buyer
// contacts. docs/OPPORTUNITIES-IMPLEMENTATION.md / docs/opportunity-ui/opportunity-3.png.
//
// Same throwaway-fixture-via-raw-fetch convention as 119-opportunities-conferences.test.mjs:
// creates a real company through the API, drives the real UI against it,
// deletes it in `finally` regardless of pass/fail.
import { test, assert } from './harness.mjs';

const MARKER = 'PB UI TEST OPPCO (safe to delete)';

async function apiFetch(page, path, opts = {}) {
  const token = page.accessToken;
  const res = await fetch(page.base + '/api' + path, {
    ...opts,
    headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token, ...(opts.headers || {}) },
  });
  const text = await res.text();
  let body = null;
  try { body = text ? JSON.parse(text) : null; } catch { /* non-JSON (e.g. 204) */ }
  if (!res.ok) throw new Error(`${opts.method || 'GET'} ${path} -> ${res.status}: ${text}`);
  return body;
}

let companyId = null;

test('Companies list renders filters, sort, and the Add Company action', async (page) => {
  await page.goto('#opportunities-companies');
  assert.ok(
    await page.until(`document.querySelector('pb-opportunities-companies-list') && document.querySelector('[data-q]')`),
    'pb-opportunities-companies-list renders its search input',
  );
  assert.ok(await page.exists('[data-industry]'), 'industry filter is present');
  assert.ok(await page.exists('[data-status]'), 'relationship status filter is present');
  assert.ok(await page.exists('[data-sort]'), 'sort select is present');
  assert.ok(await page.exists('[data-add]'), '"+ Add Company" button is present');
});

test('a company created via the API appears in the list and its detail page renders every panel', async (page) => {
  const created = await apiFetch(page, '/opportunity-companies', {
    method: 'POST',
    body: JSON.stringify({ name: MARKER, industry: 'Enterprise Software', employee_range: '10,001+', hq_city: 'San Francisco', hq_state: 'CA' }),
  });
  companyId = created.company.id;

  await page.goto(`#opportunities-company-${companyId}`);
  assert.ok(
    await page.until(`document.querySelector('pb-opportunities-company-detail') && document.querySelector('.opp-kpis')`),
    'pb-opportunities-company-detail renders its header KPI cards',
  );
  assert.ok((await page.text('h1')).includes(MARKER), 'the page heading includes the company name');
  assert.ok(await page.exists('[data-add-contact]'), '"+ Add Contact" is present (venue_admin can manage)');
  assert.ok(await page.exists('[data-add-opportunity]'), '"+ New Opportunity" is present');
  assert.ok(await page.exists('[data-form="add-note"]'), 'Notes add-form is present');
  assert.ok(await page.exists('[data-form="add-signal"]'), 'Buying Signals add-form is present');
  assert.ok(await page.exists('[data-start-tasks]'), 'Open Tasks panel offers "+ Add first task" before any task exists');
  assert.ok(await page.exists('[data-delete-company]'), 'Delete action is present (venue_admin can manage)');
  assert.ok(await page.exists('.opp-detail-layout'), 'the main+rail detail layout renders');

  await page.goto('#opportunities-companies');
  await page.until(`document.querySelector('pb-opportunities-companies-list')`);
  assert.ok(await page.until(`[...document.querySelectorAll('[data-company-id]')].some(r => r.dataset.companyId === ${JSON.stringify(String(companyId))})`),
    'the newly created company appears in the list');
});

test('adding a buyer contact through the UI form persists, renders, and flags Likely Buyer', async (page) => {
  await page.goto(`#opportunities-company-${companyId}`);
  await page.until(`document.querySelector('[data-add-contact]')`);
  await page.eval(`document.querySelector('[data-add-contact]').click()`);
  await page.until(`document.querySelector('[data-form="contact-form"]')`);
  await page.eval(`document.querySelector('[data-form="contact-form"] [name="name"]').value = 'Jane Smith'`);
  await page.eval(`document.querySelector('[data-form="contact-form"] [name="title"]').value = 'Field Marketing Director'`);
  await page.eval(`document.querySelector('[data-form="contact-form"]').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))`);
  assert.ok(
    await page.until(`document.querySelector('.opp-contact-name-cell') && document.querySelector('.opp-contact-name-cell').textContent.includes('Jane Smith')`),
    'the new contact appears in the Key Contacts table after submit',
  );
  assert.ok(await page.exists('.badge.success'), 'the Field Marketing Director contact is badged as a Likely Buyer');
});

test('cleanup: delete the throwaway company', async (page) => {
  if (!companyId) { page.skip?.('no company was created'); return; }
  await apiFetch(page, `/opportunity-companies/${companyId}`, { method: 'DELETE' });
});
