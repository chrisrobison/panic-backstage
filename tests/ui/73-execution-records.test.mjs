// Event workspace "Execution" tab: the "+ Add Record" button and the add/
// edit/delete round trip for a live execution record (incidents, change
// orders, bar notes, etc — see docs/ops-manual.html).
//
// Regression test for two bugs found together:
//   1. The frontend add/edit forms posted a `summary` field, but the backend
//      (src/Events/Execution.php) requires and stores `title` — every save
//      404'd with "title is required", and the DB/API's `title` field was
//      read back as `rec.summary` (always undefined) when rendering cards.
//   2. <pb-event-execution>'s initial render() (built in connect(), which
//      fires synchronously while the workspace's outer innerHTML is being
//      assigned) ran before the workspace assigned canEdit/canManageIncidents
//      onto the element, so the "+ Add Record" button never appeared for
//      *any* role, including venue_admin — because nothing ever re-rendered
//      the shell after those properties were set.
//
// This test IS destructive against a throwaway event it creates for itself
// and deletes in a `finally` block — same convention as
// 70-runsheet-populate.test.mjs.
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

test('Event workspace: Execution tab — Add Record button renders and save round-trips', async (page) => {
  const created = await apiFetch(page, '/events', {
    method: 'POST',
    body: JSON.stringify({
      title: 'PB UI TEST — execution records (safe to delete)',
      date: '2099-08-11',
      venue_id: 1,
      event_type: 'live_music',
      status: 'confirmed',
    }),
  });
  const eventId = created.id;
  assert.ok(eventId, 'test event created');

  try {
    await page.eval('window.confirm = () => true;');

    await page.openEvent(eventId);
    await page.click('.workspace-tabs a[data-tab="execution"]');
    await page.until(`document.querySelector('#execution pb-event-execution, pb-event-execution#execution')`);
    // Shell re-render after load() resolves (the fix for bug #2) — give the
    // async /execution fetch a moment to complete and re-render the shell.
    await page.until(`document.querySelector('[data-exec-add-toggle]')`);

    assert.ok(await page.exists('[data-exec-add-toggle]'), '"+ Add Record" button renders for venue_admin');

    // --- Add a record ---
    await page.click('[data-exec-add-toggle]');
    await page.until(`document.querySelector('[data-exec-add-form]')`);
    await page.setValue('[data-exec-add-form] input[name="title"]', 'PB UI TEST record');
    await page.setValue('[data-exec-add-form] textarea[name="body"]', 'Created by 73-execution-records.test.mjs');
    await page.click('[data-exec-add-form] button[type="submit"]');
    await page.until(`document.querySelector('[data-exec-id]')`);

    let cardText = await page.text('[data-exec-id]');
    assert.includes(cardText, 'PB UI TEST record', 'new record renders with its title (not blank / undefined)');

    // --- Edit it ---
    await page.click('[data-exec-edit]');
    await page.until(`document.querySelector('[data-exec-id] .exec-record-edit-form')`);
    await page.setValue('[data-exec-id] .exec-record-edit-form input[name="title"]', 'PB UI TEST record (edited)');
    await page.click('[data-exec-id] .exec-record-edit-form button[type="submit"]');
    await page.until(`document.querySelector('[data-exec-id]') && document.querySelector('[data-exec-id]').textContent.includes('edited')`);

    cardText = await page.text('[data-exec-id]');
    assert.includes(cardText, 'PB UI TEST record (edited)', 'edited title round-trips through PATCH (also keyed on title, not summary)');

    // --- Delete it ---
    await page.click('[data-exec-delete]');
    await page.until(`!document.querySelector('[data-exec-id]')`);
    assert.notOk(await page.exists('[data-exec-id]'), 'record removed after delete');
  } finally {
    await apiFetch(page, `/events/${eventId}`, { method: 'DELETE' }).catch(() => {});
  }
});
