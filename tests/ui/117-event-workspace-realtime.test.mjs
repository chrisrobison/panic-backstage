// Event workspace realtime invalidations (docs/realtime-data.md) —
// public/assets/event-workspace.js's EventWorkspace re-fetches and
// re-broadcasts fresh event data when a realtime invalidation names the
// event currently open, debounced, without remounting the workspace or
// disturbing the autosaving Details form. Simulates invalidations directly
// on the PAN/LARC bus (same technique as 116-booking-inbox-realtime.test.mjs)
// so these are deterministic rather than timing-dependent on a real
// db_history write.
import { test, assert } from './harness.mjs';

test('an invalidation for the currently open event triggers exactly one debounced refresh', async (page) => {
  if (!page.hasEvent) page.skip('UI_EVENT_ID not found');
  await page.openEvent();

  const result = await page.eval(`(async () => {
    const ws = document.querySelector('pb-event-workspace');
    if (!ws) return { error: 'pb-event-workspace not mounted' };
    let calls = 0;
    const original = ws._refreshFromInvalidation.bind(ws);
    ws._refreshFromInvalidation = async (...args) => { calls++; return original(...args); };
    const id = ws.eventId;
    // A burst of three, as one editor's transaction touching the event row
    // plus a couple of child tables would produce.
    window.pan.bus.publish('data.invalidated', { entity: 'event', id, revision: 910001 });
    window.pan.bus.publish('data.invalidated', { entity: 'event', id, revision: 910002 });
    window.pan.bus.publish('data.invalidated', { entity: 'event', id, revision: 910003 });
    await new Promise((r) => setTimeout(r, 600));
    ws._refreshFromInvalidation = original;
    return { calls };
  })()`);

  assert.ok(!result.error, result.error || 'ok');
  assert.equal(result.calls, 1, 'three quick invalidations for the open event debounce into exactly one refresh');
});

test('an invalidation for a different event does not trigger a refresh or remount the workspace', async (page) => {
  if (!page.hasEvent) page.skip('UI_EVENT_ID not found');
  await page.openEvent();

  const result = await page.eval(`(async () => {
    const ws = document.querySelector('pb-event-workspace');
    let calls = 0;
    const original = ws._refreshFromInvalidation.bind(ws);
    ws._refreshFromInvalidation = async (...args) => { calls++; return original(...args); };
    ws.__testMarker = 'still-the-same-node';
    const unrelatedId = Number(ws.eventId) + 999000; // definitely not the open event
    window.pan.bus.publish('data.invalidated', { entity: 'event', id: unrelatedId, revision: 910010 });
    await new Promise((r) => setTimeout(r, 400));
    ws._refreshFromInvalidation = original;
    return { calls, markerSurvived: document.querySelector('pb-event-workspace')?.__testMarker === 'still-the-same-node' };
  })()`);

  assert.equal(result.calls, 0, 'an invalidation for a different event id is ignored');
  assert.ok(result.markerSurvived, 'the workspace element was not torn down and remounted (same DOM node instance)');
});

test('a same-event realtime refresh does not disturb the active Details form', async (page) => {
  if (!page.hasEvent) page.skip('UI_EVENT_ID not found');
  await page.openEvent();
  await page.until(`document.querySelector('[name="title"]')`);

  // Simulate active, not-yet-saved typing: set the field value directly
  // WITHOUT dispatching 'change' (EventDetailsForm autosaves on 'change' —
  // see event-workspace.js's save()). Using page.setValue()/a real
  // keystroke here would trigger a genuine PATCH and persist this marker as
  // the event's real title, which would violate this suite's non-
  // destructive contract (see tests/ui/README.md) — setting .value directly
  // is the correct way to represent "mid-edit, unsaved" without a side
  // effect on the server.
  const marker = 'REALTIME TEST — unsaved edit';
  await page.eval(`document.querySelector('[name="title"]').value = ${JSON.stringify(marker)}`);

  await page.eval(`(async () => {
    const ws = document.querySelector('pb-event-workspace');
    window.pan.bus.publish('data.invalidated', { entity: 'event', id: ws.eventId, revision: 910020 });
    await new Promise((r) => setTimeout(r, 500));
  })()`);
  await new Promise((r) => setTimeout(r, 200));

  const stillThere = await page.eval(`document.querySelector('[name="title"]')?.value`);
  assert.equal(stillThere, marker, 'the unsaved Details form edit survives a realtime-triggered event refresh untouched');
});
