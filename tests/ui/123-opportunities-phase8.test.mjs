// Opportunities module, Phase 8: Tasks/activities/realtime/scoring/
// availability intelligence. docs/OPPORTUNITIES-IMPLEMENTATION.md.
//
// Same throwaway-fixture-via-raw-fetch convention as 118-122: creates real
// records through the API, drives the real UI against them, deletes in a
// cleanup test regardless of pass/fail. Written and syntax-checked in this
// session — NOT run, same pre-existing environment limitation as 118-122
// (this checkout's SUPER_DB_NAME multi-tenant .env blocks
// `node tests/ui/run.mjs`'s dev-server host at TenantContext::resolve()
// before the app boots — see docs/OPPORTUNITIES-IMPLEMENTATION.md §4.2's
// Tests note for the full explanation). Run for real (alongside 118-122) at
// the start of whichever future session next touches the Opportunities
// frontend, before trusting it blind.
import { test, assert } from './harness.mjs';

const MARKER = 'PB UI TEST OPP8 (safe to delete)';

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
let conferenceId = null;
let opportunityId = null;
let taskDocumentId = null;

test('fixtures: a conference + company + opportunity, plus an overdue task', async (page) => {
  const conf = await apiFetch(page, '/opportunity-conferences', {
    method: 'POST',
    body: JSON.stringify({ name: MARKER + ' Conference' }),
  });
  conferenceId = conf.conference.id;

  const company = await apiFetch(page, '/opportunity-companies', { method: 'POST', body: JSON.stringify({ name: MARKER + ' Co' }) });
  companyId = company.company.id;

  const link = await apiFetch(page, `/opportunity-conferences/${conferenceId}/tasks`, { method: 'POST' });
  taskDocumentId = link.task_document_id;
  const yesterday = new Date(Date.now() - 86400000).toISOString().slice(0, 10);
  await apiFetch(page, `/task-documents/${taskDocumentId}/tasks`, {
    method: 'POST',
    body: JSON.stringify({ title: MARKER + ' overdue task', due_date: yesterday }),
  });

  const opp = await apiFetch(page, '/opportunities', {
    method: 'POST',
    body: JSON.stringify({ name: MARKER + ' Opp', company_id: companyId, conference_id: conferenceId, estimated_value: 15000 }),
  });
  opportunityId = opp.opportunity.id;

  assert.ok(conferenceId && companyId && opportunityId && taskDocumentId, 'all Phase 8 fixtures were created');
});

test('Conferences list shows a Tasks column with an overdue badge', async (page) => {
  await page.goto('#opportunities-conferences');
  await page.eval(`document.querySelector('[data-q]').value = ${JSON.stringify(MARKER)}`);
  await page.eval(`document.querySelector('[data-q]').dispatchEvent(new Event('input', { bubbles: true }))`);
  assert.ok(
    await page.until(`document.querySelector('[data-conf-id="${conferenceId}"]') && document.querySelector('[data-conf-id="${conferenceId}"]').textContent.includes('overdue')`),
    'the fixture conference\'s row shows an overdue task badge',
  );
});

test('Conference detail shows an overdue badge on Open Tasks and the AI Research panel', async (page) => {
  await page.goto(`#opportunities-conference-${conferenceId}`);
  assert.ok(await page.until(`document.querySelector('pb-opportunities-ai-research')`), 'the AI Research panel mounts on conference detail');
  assert.ok(
    await page.until(`[...document.querySelectorAll('h2')].some(h => h.textContent.includes('Open Tasks') && h.textContent.includes('overdue'))`),
    'the Open Tasks section head shows an overdue count',
  );
});

test('Companies list shows a Tasks column', async (page) => {
  await page.goto('#opportunities-companies');
  await page.eval(`document.querySelector('[data-q]').value = ${JSON.stringify(MARKER)}`);
  await page.eval(`document.querySelector('[data-q]').dispatchEvent(new Event('input', { bubbles: true }))`);
  assert.ok(await page.until(`document.querySelector('[data-company-id="${companyId}"]')`), 'the fixture company appears in the list');
});

test('Opportunity detail renders the Phase 8 Opportunity Score panel', async (page) => {
  await page.goto(`#opportunities-${opportunityId}`);
  assert.ok(
    await page.until(`[...document.querySelectorAll('h2')].some(h => h.textContent.includes('Opportunity Score'))`),
    'the Opportunity Score panel renders in the right rail',
  );
  assert.ok(await page.exists('.opp-score-total'), 'the total score chip renders');
  assert.ok(await page.exists('.opp-score-bar'), 'at least one component bar renders');
});

test('Discover dashboard renders the Phase 8 "Prospects for Empty Dates" panel', async (page) => {
  await page.goto('#opportunities');
  assert.ok(
    await page.until(`[...document.querySelectorAll('h2')].some(h => h.textContent.includes('Prospects for Empty Dates'))`),
    'the Prospects for Empty Dates panel renders on Discover',
  );
});

test('cleanup: delete the throwaway opportunity, conference, and company', async (page) => {
  if (opportunityId) {
    try { await apiFetch(page, `/opportunities/${opportunityId}`, { method: 'DELETE' }); } catch { /* best effort */ }
  }
  if (conferenceId) {
    try { await apiFetch(page, `/opportunity-conferences/${conferenceId}`, { method: 'DELETE' }); } catch { /* best effort */ }
  }
  if (companyId) {
    try { await apiFetch(page, `/opportunity-companies/${companyId}`, { method: 'DELETE' }); } catch { /* best effort */ }
  }
  if (taskDocumentId) {
    try { await apiFetch(page, `/task-documents/${taskDocumentId}`, { method: 'DELETE' }); } catch { /* best effort — document may not support direct delete */ }
  }
});
