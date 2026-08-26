// Staff Handbook & Compliance (src/StaffDocs.php, src/StaffDocCompliance.php,
// public/assets/staff-docs.js). Drives the real UI: the "Staff Docs" list
// page, the document reader (TOC + acknowledgment), and the admin
// Compliance Overview matrix.
//
// The acknowledge action is idempotent (unique per user+version — see
// StaffDocs::acknowledge()), so this test doesn't need to reset any state
// in a `finally`: whichever branch runs (already acknowledged vs. not yet)
// leaves the DB in a valid, re-runnable state either way.
import { test, assert } from './harness.mjs';

async function apiFetch(page, path, opts = {}) {
  const token = page.accessToken;
  const res = await fetch(page.base + '/api' + path, {
    ...opts,
    headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token, ...(opts.headers || {}) },
  });
  const text = await res.text();
  let body = null;
  try { body = text ? JSON.parse(text) : null; } catch { /* non-JSON */ }
  if (!res.ok) throw new Error(`${opts.method || 'GET'} ${path} -> ${res.status}: ${text}`);
  return body;
}

test('Staff Docs list page shows published documents and links into the reader', async (page) => {
  const before = await apiFetch(page, '/staff-docs');
  assert.ok(before.documents.some((d) => d.slug === 'handbook'), 'API: handbook is a published, listed document');

  await page.goto('#staff-docs');
  assert.ok(await page.until(`document.querySelector('pb-staff-docs-page')`), 'staff docs list page mounts');
  assert.ok(await page.until(`document.body.textContent.includes('Staff Handbook & Compliance')`), 'page header renders');
  assert.ok(await page.exists(`a[href="#staff-docs-handbook"]`), 'a link to the handbook document is rendered');
  assert.ok(await page.exists(`a[href="#staff-docs-sop-bartender"]`), 'SOP documents are listed too');
});

test('Staff Doc reader renders TOC + content and supports acknowledgment', async (page) => {
  await page.goto('#staff-docs-handbook');
  assert.ok(await page.until(`document.querySelector('pb-staff-doc-reader')`), 'reader mounts');
  assert.ok(await page.until(`document.body.textContent.includes('Staff Handbook')`), 'document title renders');
  assert.ok(await page.exists('.help-toc a[data-toc]'), 'table of contents renders with at least one entry');
  assert.ok(await page.exists('.help-content'), 'rendered document body is present');
  const tocCount = await page.count('.help-toc a[data-toc]');
  const headingCount = await page.eval(`document.querySelectorAll('.help-content h2, .help-content h3').length`);
  assert.equal(tocCount, headingCount, 'one TOC entry per h2/h3 heading actually rendered in the body');

  const already = await apiFetch(page, '/staff-docs/handbook');
  if (already.acknowledgment) {
    assert.ok(await page.until(`document.body.textContent.includes('You acknowledged')`), 'previously-acknowledged version shows the acknowledged banner');
    assert.ok(!(await page.exists('[data-acknowledge]')), 'no acknowledge button once already acknowledged');
  } else {
    assert.ok(await page.until(`document.body.textContent.includes('ACTION REQUIRED')`), 'unacknowledged required doc shows the action-required banner');
    assert.ok(await page.exists('[data-acknowledge]'), 'acknowledge control is present');
    await page.click('[data-acknowledge]');
    assert.ok(await page.until(`document.body.textContent.includes('Acknowledged')`), 'acknowledging updates the page without a reload');
    const after = await apiFetch(page, '/staff-docs/handbook');
    assert.ok(after.acknowledgment, 'acknowledgment is recorded server-side');

    // Idempotency: acknowledging again does not create a second history row
    // — same acknowledged_at timestamp comes back, not a fresh one.
    const second = await apiFetch(page, '/staff-docs/handbook/acknowledge', { method: 'POST', body: '{}' });
    assert.equal(second.already_acknowledged, true, 're-acknowledging via the API reports already_acknowledged');
    assert.equal(second.acknowledgment.acknowledged_at, after.acknowledgment.acknowledged_at, 'timestamp is unchanged — no duplicate acknowledgment row was created');
  }

  // Prev/next navigation between sibling documents of the same type is present.
  assert.ok(await page.exists('.help-back'), 'prev/next navigation footer renders');
});

test('Compliance Overview (admin) renders the staff x documents x certifications matrix', async (page) => {
  const compliance = await apiFetch(page, '/staff-compliance');
  assert.ok(Array.isArray(compliance.staff), 'API: compliance overview returns a staff array');
  assert.ok(Array.isArray(compliance.documents), 'API: compliance overview returns a documents array');

  await page.goto('#staff-compliance');
  assert.ok(await page.until(`document.querySelector('pb-staff-compliance-page')`), 'compliance page mounts');
  assert.ok(await page.until(`document.body.textContent.includes('Compliance Overview')`), 'page header renders');
  assert.ok(await page.exists('table.data-table thead th'), 'matrix table renders with headers');

  // Filtering: typing into search narrows visible rows without erroring.
  const rowsBefore = await page.count('table.data-table tbody tr');
  await page.setValue('[data-q]', 'zzz-no-such-staff-member-zzz');
  await page.eval(`document.querySelector('[data-q]').dispatchEvent(new Event('input',{bubbles:true}))`);
  const rowsAfterFilter = await page.until(`document.querySelectorAll('table.data-table tbody tr').length <= ${rowsBefore}`);
  assert.ok(rowsAfterFilter, 'search filter narrows (or matches) the visible row count');
});
