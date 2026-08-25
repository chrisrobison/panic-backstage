// Opportunities module, Phase 3: Conference list + Conference Detail.
// docs/OPPORTUNITIES-IMPLEMENTATION.md / docs/opportunity-ui/opportunity-ui.txt.
//
// Same throwaway-fixture-via-raw-fetch convention as 100-tasks-app.test.mjs:
// creates a real conference through the API, drives the real UI against it,
// deletes it in `finally` regardless of pass/fail.
import { test, assert } from './harness.mjs';

const MARKER = 'PB UI TEST OPPCONF (safe to delete)';

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

let conferenceId = null;

test('Conferences list renders filters, sort, and the Add Conference action', async (page) => {
  await page.goto('#opportunities-conferences');
  assert.ok(
    await page.until(`document.querySelector('pb-opportunities-conferences-list') && document.querySelector('[data-tabs]')`),
    'pb-opportunities-conferences-list renders its tab bar',
  );
  assert.ok(await page.exists('[data-tab="upcoming"].active'), 'Upcoming tab is active by default');
  assert.ok(await page.exists('[data-q]'), 'search input is present');
  assert.ok(await page.exists('[data-sort]'), 'sort select is present');
  assert.ok(await page.exists('[data-add]'), '"+ Add Conference" button is present');
});

test('a conference created via the API appears in the list and its detail page renders every panel', async (page) => {
  const created = await apiFetch(page, '/opportunity-conferences', {
    method: 'POST',
    body: JSON.stringify({
      name: MARKER, city: 'San Francisco', state: 'CA',
      starts_at: '2099-06-10', ends_at: '2099-06-12', estimated_attendance: 4200,
    }),
  });
  conferenceId = created.conference.id;

  await page.goto(`#opportunities-conference-${conferenceId}`);
  assert.ok(
    await page.until(`document.querySelector('pb-opportunities-conference-detail') && document.querySelector('.opp-kpis')`),
    'pb-opportunities-conference-detail renders its header KPI cards',
  );
  assert.equal(await page.text('h1'), MARKER, 'the page heading is the conference name');
  assert.ok(await page.exists('[data-add-company]'), '"+ Add Company" is present (venue_admin can manage)');
  assert.ok(await page.exists('[data-form="add-fact"]'), 'Key Facts add-form is present');
  assert.ok(await page.exists('[data-form="add-signal"]'), 'Side Event Signals add-form is present');
  assert.ok(await page.exists('[data-form="add-note"]'), 'Conference Notes add-form is present');
  assert.ok(await page.exists('[data-start-tasks]'), 'Open Tasks panel offers "+ Add first task" before any task exists');
  assert.ok(await page.exists('.opp-window-bands .opp-window-band'), 'Peak Side-Event Windows renders computed date bands');

  await page.goto('#opportunities-conferences');
  await page.until(`document.querySelector('pb-opportunities-conferences-list')`);
  assert.ok(await page.until(`[...document.querySelectorAll('[data-conf-id]')].some(r => r.dataset.confId === ${JSON.stringify(String(conferenceId))})`),
    'the newly created conference appears in the list');
});

test('adding a Key Fact through the UI form persists and re-renders it', async (page) => {
  await page.goto(`#opportunities-conference-${conferenceId}`);
  await page.until(`document.querySelector('[data-form="add-fact"]')`);
  await page.eval(`document.querySelector('[data-form="add-fact"] [name="fact"]').value = 'Largest AI + CRM event'`);
  await page.eval(`document.querySelector('[data-form="add-fact"]').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))`);
  assert.ok(
    await page.until(`document.querySelector('.opp-fact-item') && document.querySelector('.opp-fact-item').textContent.includes('Largest AI + CRM event')`),
    'the new key fact appears in the Key Facts list after submit',
  );
});

test('cleanup: delete the throwaway conference', async (page) => {
  if (!conferenceId) { page.skip?.('no conference was created'); return; }
  await apiFetch(page, `/opportunity-conferences/${conferenceId}`, { method: 'DELETE' });
});
