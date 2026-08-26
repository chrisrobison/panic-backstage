// Opportunities module, Phase 6: First-class Research Notes workspace.
// docs/OPPORTUNITIES-IMPLEMENTATION.md / docs/opportunity-ui/opportunity-6.png.
//
// Same throwaway-fixture-via-raw-fetch convention as 119-121: creates a real
// company + note through the API, drives the real workspace UI against
// them, deletes in cleanup tests regardless of pass/fail.
import { test, assert } from './harness.mjs';

const MARKER = 'PB UI TEST OPPNOTES (safe to delete)';

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
let noteId = null;

test('Notes workspace renders the three-pane layout and filter bar', async (page) => {
  await page.goto('#opportunities-notes');
  assert.ok(
    await page.until(`document.querySelector('pb-opportunities-notes') && document.querySelector('.opp-notes-workspace')`),
    'pb-opportunities-notes renders the workspace grid',
  );
  assert.ok(await page.exists('.opp-notes-list-pane'), 'the note list pane renders');
  assert.ok(await page.exists('.opp-notes-editor-pane'), 'the editor pane renders');
  assert.ok(await page.exists('.opp-notes-context-pane'), 'the context pane renders');
  assert.ok(await page.exists('[data-f-q]'), 'the search filter is present');
  assert.ok(await page.exists('[data-f-type]'), 'the note type filter is present');
  assert.ok(await page.exists('[data-new-note]'), '"+ New Note" is present (venue_admin can manage)');
});

test('a note created via the API appears in the list with its resolved context', async (page) => {
  const company = await apiFetch(page, '/opportunity-companies', { method: 'POST', body: JSON.stringify({ name: MARKER + ' Co' }) });
  companyId = company.company.id;
  const note = await apiFetch(page, '/opportunity-notes', {
    method: 'POST',
    body: JSON.stringify({ body: MARKER + ' body one', linked_type: 'company', linked_id: companyId, note_type: 'strategy' }),
  });
  noteId = note.note.id;

  await page.goto('#opportunities-notes');
  assert.ok(
    await page.until(`[...document.querySelectorAll('[data-select-note]')].some(b => b.dataset.selectNote === ${JSON.stringify(String(noteId))})`),
    'the new note appears in the list',
  );
  const hasContext = await page.eval(`
    (() => {
      const btn = document.querySelector('[data-select-note="${noteId}"]');
      return !!(btn && btn.textContent.includes(${JSON.stringify(MARKER + ' Co')}));
    })()
  `);
  assert.ok(hasContext, 'the list row shows the resolved company context label');
});

test('selecting a note loads it into the editor with its links, and editing persists a version', async (page) => {
  await page.goto('#opportunities-notes');
  await page.until(`document.querySelector('[data-select-note="${noteId}"]')`);
  await page.eval(`document.querySelector('[data-select-note="${noteId}"]').click()`);
  assert.ok(
    await page.until(`document.querySelector('[data-note-body]') && document.querySelector('[data-note-body]').value.includes(${JSON.stringify(MARKER + ' body one')})`),
    'the editor textarea loads the selected note\'s body',
  );
  assert.ok(await page.exists('.opp-notes-link-chip'), 'the linked company renders as a chip in the editor');
  assert.ok(await page.exists('[data-md="bold"]'), 'the formatting toolbar renders');
  assert.ok(await page.exists('[data-view-history]'), '"History" action is present for an existing note');

  await page.eval(`
    (() => {
      const ta = document.querySelector('[data-note-body]');
      ta.value = ${JSON.stringify(MARKER + ' body TWO (edited)')};
      ta.dispatchEvent(new Event('input', { bubbles: true }));
    })()
  `);
  await page.eval(`document.querySelector('[data-save-note]').click()`);
  await page.until(`document.querySelector('[data-note-body]') && document.querySelector('[data-note-body]').value.includes('body TWO')`);

  const versions = await apiFetch(page, `/opportunity-notes/${noteId}/versions`);
  assert.equal(versions.versions.length, 1, 'editing the body through the UI archived exactly one version');
  assert.ok(versions.versions[0].body.includes('body one'), 'the archived version holds the pre-edit body');
});

test('Preview toggle renders the body through the safe Markdown renderer', async (page) => {
  await page.goto('#opportunities-notes');
  await page.until(`document.querySelector('[data-select-note="${noteId}"]')`);
  await page.eval(`document.querySelector('[data-select-note="${noteId}"]').click()`);
  await page.until(`document.querySelector('[data-toggle-preview="preview"]')`);
  await page.eval(`document.querySelector('[data-toggle-preview="preview"]').click()`);
  assert.ok(await page.until(`document.querySelector('.opp-notes-preview')`), 'the preview pane renders in place of the textarea');
});

test('cleanup: delete the throwaway note and company', async (page) => {
  if (noteId) {
    try { await apiFetch(page, `/opportunity-notes/${noteId}`, { method: 'DELETE' }); } catch { /* best effort */ }
  }
  if (companyId) {
    try { await apiFetch(page, `/opportunity-companies/${companyId}`, { method: 'DELETE' }); } catch { /* best effort */ }
  }
});
