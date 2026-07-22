// Standalone Tasks app (tasks-ui.png; public/assets/tasks/*.js,
// src/Tasks/*.php, database/migrations/069_add_tasks_app.sql). Exercises
// the real end-to-end flow: create a task document, add a parent task and
// a subtask, confirm WBS numbering, open the detail panel, add + toggle a
// checklist item, and confirm the Board/Timeline/Calendar tabs mount.
//
// Destructive against a throwaway document only, cleaned up via a direct
// API call in `finally` (same convention as 97-nav-manager.test.mjs) so
// cleanup still happens even if a UI assertion above it fails.
import { test, assert } from './harness.mjs';

async function apiFetch(page, path, opts = {}) {
  const token = await page.eval("localStorage.getItem('backstage_access_token')");
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

// Simulates pressing Enter in a bare text input (the inline task/checklist
// "add" rows submit on Enter, not via a visible submit button).
async function pressEnter(page, sel) {
  await page.eval(`document.querySelector(${JSON.stringify(sel)})?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))`);
}

// Fires a synthetic 'submit' on a <form> — used where the add-check form has
// no visible submit button either; addEventListener('submit', ...) handlers
// still run against a synthetic (untrusted) Event.
async function submitForm(page, sel) {
  await page.eval(`document.querySelector(${JSON.stringify(sel)})?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))`);
}

const DOC_NAME = 'PB UI TEST tasks doc (safe to delete)';
const DOC_MODAL = '.modal-backdrop:has([data-form="new-doc"])';

test('Tasks app: create document, add task + subtask, WBS numbers, detail panel, checklist, and other tabs', async (page) => {
  await page.eval('window.confirm = () => true;');
  await page.goto('#tasks');
  await page.until(`document.querySelector('[data-new-doc]')`);

  let docId = null;
  try {
    // --- create a throwaway task document ---
    await page.click('[data-new-doc]');
    await page.until(`document.querySelector('${DOC_MODAL}')`);
    await page.setValue(`${DOC_MODAL} [name="name"]`, DOC_NAME);
    await page.click(`${DOC_MODAL} button[type="submit"]`);
    // Wait for the header to actually contain the new document's name, not
    // just for an <h1> to exist — on an account that already has documents
    // (the common case outside a fresh test DB), an <h1> from whichever
    // document was selected on load is already present, so a plain
    // existence check races the async create+select and can read stale text.
    await page.until(`document.querySelector('.tk-doc-header-top h1')?.textContent.includes(${JSON.stringify(DOC_NAME)})`);
    assert.includes(await page.text('.tk-doc-header-top h1'), DOC_NAME, 'new document becomes the selected document');

    docId = await page.eval(`Number(document.querySelector('.tk-doc-item.active')?.dataset.docId || 0)`);
    assert.ok(docId, 'new document id captured from the active sidebar row');

    // --- add a root task via the inline "+ Add Task" row ---
    await page.until(`document.querySelector('[data-add-root]')`);
    await page.click('[data-add-root]');
    await page.until(`document.querySelector('[data-add-input]')`);
    await page.setValue('[data-add-input]', 'PB UI TEST parent task');
    await pressEnter(page, '[data-add-input]');
    await page.until(`document.querySelector('[data-row]')`);

    const parentId = await page.eval(`Number(document.querySelector('[data-row]').dataset.row)`);
    assert.ok(parentId, 'parent task row rendered with its id');

    // --- add a subtask under it ---
    await page.click(`[data-add-child="${parentId}"]`);
    await page.until(`document.querySelector('[data-add-input]')`);
    await page.setValue('[data-add-input]', 'PB UI TEST child task');
    await pressEnter(page, '[data-add-input]');
    await page.until(`document.querySelectorAll('[data-row]').length >= 2`);

    // --- WBS numbering: root task is "1", its subtask is "1.1" ---
    const wbsValues = await page.eval(`[...document.querySelectorAll('.tk-col-wbs')].map((e) => e.textContent.trim())`);
    assert.includes(wbsValues.join(','), '1', 'root task numbered 1');
    assert.includes(wbsValues.join(','), '1.1', 'subtask numbered 1.1 under its parent');

    const rows = await page.eval(`[...document.querySelectorAll('[data-row]')].map((e) => Number(e.dataset.row))`);
    const childId = rows.find((id) => id !== parentId);
    assert.ok(childId, 'subtask row id captured');

    // --- open the detail panel for the subtask ---
    await page.click(`[data-open="${childId}"]`);
    await page.until(`document.querySelector('.tk-detail-body')`);
    const detailTitle = await page.eval(`document.querySelector('.tk-detail-title-input')?.value`);
    assert.includes(detailTitle, 'PB UI TEST child task', 'detail panel shows the selected task');

    // --- add + toggle a checklist item (panel-local edit, no full reload) ---
    await page.setValue('[data-add-check-input]', 'PB UI TEST checklist item');
    await submitForm(page, '[data-add-check-form]');
    await page.until(`document.querySelector('[data-check]')`);
    await page.click('[data-check]');
    await page.until(`document.querySelector('.tk-detail-section-label .pill')?.textContent.trim() === '1/1'`);
    assert.equal(await page.text('.tk-detail-section-label .pill'), '1/1', 'checklist item toggled to done');

    // --- Board / Timeline / Calendar tabs mount ---
    await page.click('[data-tab="board"]');
    await page.until(`document.querySelector('.tk-board')`);
    assert.atLeast(await page.count('.tk-card'), 2, 'both tasks render as cards on the Board tab');

    await page.click('[data-tab="timeline"]');
    await page.until(`document.querySelector('.tk-timeline')`);
    assert.atLeast(await page.count('.tk-gantt-row'), 2, 'both tasks render as rows on the Timeline tab');

    await page.click('[data-tab="calendar"]');
    await page.until(`document.querySelector('.tk-calendar')`);
    assert.ok(await page.exists('.calendar-grid'), 'Calendar tab renders a month grid');
  } finally {
    if (docId) await apiFetch(page, `/task-documents/${docId}`, { method: 'DELETE' }).catch(() => {});
  }
});
