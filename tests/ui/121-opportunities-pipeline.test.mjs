// Opportunities module, Phase 5: Pipeline board + Opportunity detail +
// conversion. docs/OPPORTUNITIES-IMPLEMENTATION.md / docs/opportunity-ui/
// opportunity-4.png (detail) + opportunity-5.png (pipeline/kanban).
//
// Same throwaway-fixture-via-raw-fetch convention as 119/120: creates a real
// company + opportunity through the API, drives the real UI against them,
// deletes in `finally`-equivalent cleanup tests regardless of pass/fail.
import { test, assert } from './harness.mjs';

const MARKER = 'PB UI TEST OPPPIPE (safe to delete)';

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
let opportunityId = null;

test('Pipeline board renders KPI summary, filters, and every stage column', async (page) => {
  await page.goto('#opportunities-pipeline');
  assert.ok(
    await page.until(`document.querySelector('pb-opportunities-pipeline') && document.querySelector('.opp-pipeline-board')`),
    'pb-opportunities-pipeline renders the kanban board',
  );
  assert.ok(await page.exists('.opp-kpis'), 'the pipeline summary KPI row renders');
  assert.ok(await page.exists('[data-conference]'), 'Conference filter is present');
  assert.ok(await page.exists('[data-owner]'), 'Owner filter is present');
  assert.ok(await page.exists('[data-date-range]'), 'Date Range filter is present');
  assert.ok(await page.exists('[data-value-range]'), 'Est. Value filter is present');
  assert.ok(await page.exists('[data-stale]'), 'Stale-only checkbox is present');
  const columnCount = await page.eval(`document.querySelectorAll('[data-column]').length`);
  assert.equal(columnCount, 8, 'the board renders all 8 pipeline columns (Lost/Nurture combined)');
});

test('a fixture opportunity created via the API appears on the board in its stage column', async (page) => {
  const company = await apiFetch(page, '/opportunity-companies', { method: 'POST', body: JSON.stringify({ name: MARKER + ' Co' }) });
  companyId = company.company.id;
  const opp = await apiFetch(page, '/opportunities', {
    method: 'POST',
    body: JSON.stringify({ name: MARKER, company_id: companyId, stage: 'qualified', estimated_value: 15000 }),
  });
  opportunityId = opp.opportunity.id;

  await page.goto('#opportunities-pipeline');
  assert.ok(
    await page.until(`[...document.querySelectorAll('[data-opp-id]')].some(c => c.dataset.oppId === ${JSON.stringify(String(opportunityId))})`),
    'the new opportunity card appears on the board',
  );
  const inQualifiedColumn = await page.eval(`
    (() => {
      const col = document.querySelector('[data-column="qualified"]');
      return !!(col && col.querySelector('[data-opp-id="${opportunityId}"]'));
    })()
  `);
  assert.ok(inQualifiedColumn, 'the card renders under the Qualified column');
});

test('moving stage via the accessible <select> control updates the board', async (page) => {
  await page.goto('#opportunities-pipeline');
  await page.until(`document.querySelector('[data-stage-select="${opportunityId}"]')`);
  await page.eval(`
    (() => {
      const sel = document.querySelector('[data-stage-select="${opportunityId}"]');
      sel.value = 'proposal_sent';
      sel.dispatchEvent(new Event('change', { bubbles: true }));
    })()
  `);
  assert.ok(
    await page.until(`(() => { const col = document.querySelector('[data-column="proposal_sent"]'); return !!(col && col.querySelector('[data-opp-id="${opportunityId}"]')); })()`),
    'the card moves to the Proposal Sent column after the stage <select> changes',
  );
});

test('Opportunity detail page renders header facts, tabs, qualification checklist, and header actions', async (page) => {
  await page.goto(`#opportunities-${opportunityId}`);
  assert.ok(
    await page.until(`document.querySelector('pb-opportunities-detail') && document.querySelector('.opp-header-facts')`),
    'pb-opportunities-detail renders its header facts row',
  );
  assert.ok((await page.text('h1')).includes(MARKER), 'the page heading includes the opportunity name');
  assert.ok(await page.exists('.opp-tabs'), 'the Overview/Notes/Activity/Linked Records tabs render');
  assert.ok(await page.exists('.opp-qual-list'), 'the Qualification Checklist renders');
  const qualItemCount = await page.eval(`document.querySelectorAll('.opp-qual-item').length`);
  assert.equal(qualItemCount, 9, 'the checklist has all 9 items');
  assert.ok(await page.exists('[data-convert]'), '"Convert to Event" action is present (not yet converted)');
  assert.ok(await page.exists('[data-create-proposal]'), '"Create Proposal" action is present');
  assert.ok(await page.exists('[data-log-activity]'), '"Log Activity" action is present');
  assert.ok(await page.exists('.opp-room-grid'), 'Proposed Event Format & Venue Fit renders real configured rooms');

  await page.eval(`document.querySelector('[data-tab="notes"]').click()`);
  assert.ok(await page.until(`document.querySelector('[data-form="add-note"]')`), 'switching to the Notes tab shows the note composer');
});

test('toggling a qualification checklist item persists', async (page) => {
  await page.goto(`#opportunities-${opportunityId}`);
  await page.until(`document.querySelector('[data-qual="event_objective_understood"]')`);
  await page.eval(`
    (() => {
      const box = document.querySelector('[data-qual="event_objective_understood"]');
      box.checked = true;
      box.dispatchEvent(new Event('change', { bubbles: true }));
    })()
  `);
  await page.until(`document.querySelector('.opp-qual-item h2, .section-head h2')`); // settle after re-render
  const qual = await apiFetch(page, `/opportunities/${opportunityId}/qualification`);
  assert.ok(qual.qualification.event_objective_understood, 'the qualification item is persisted server-side after the checkbox toggle');
});

test('cleanup: delete the throwaway opportunity and company', async (page) => {
  if (opportunityId) {
    try { await apiFetch(page, `/opportunities/${opportunityId}`, { method: 'DELETE' }); } catch { /* may already be converted/gone */ }
  }
  if (companyId) {
    try { await apiFetch(page, `/opportunity-companies/${companyId}`, { method: 'DELETE' }); } catch { /* best effort */ }
  }
});
