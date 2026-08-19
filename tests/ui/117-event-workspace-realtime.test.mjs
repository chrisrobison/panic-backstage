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

// The three tests below exercise EventDetailsForm's OWN realtime subscription
// (event-workspace.js's connectedCallback()/_onRemoteInvalidation()) — the
// one component excluded from the blanket "just reapply the fresh payload"
// pattern every other Events subscriber uses, because it owns live editable
// form state. Each test defensively resets interaction/banner state at the
// top rather than assuming a clean slate: page.openEvent() re-navigates to
// the same hash the whole file shares (UI_EVENT_ID), which per the test
// above does NOT remount the workspace, so `pb-event-details-form` is the
// same persisted DOM node — and therefore the same JS instance — across
// every test in this file.
test('a focused, unsaved Details field survives a realtime refresh and shows an "updated elsewhere" banner instead', async (page) => {
  if (!page.hasEvent) page.skip('UI_EVENT_ID not found');
  await page.openEvent();
  await page.until(`document.querySelector('[name="title"]')`);
  // The two tests above this one also publish `data.invalidated` for this
  // same event id, which EventDetailsForm's own subscription (independent
  // of the pb-event-workspace-level one those tests are actually testing)
  // reacts to as well — a silent refresh those tests triggered can still be
  // in flight here since pb-event-details-form is the same persisted node/
  // instance across the whole file (see the block comment above). Settle
  // before touching state so a stray late refresh can't land mid-test and
  // wipe the focus/marker this test is about to set.
  await new Promise((r) => setTimeout(r, 800));
  await page.eval(`document.querySelector('[data-realtime-stale]')?.remove()`);

  const marker = 'REALTIME TEST — unsaved edit';
  // Confirmed by hand (see the diagnostic run that motivated this comment):
  // this harness's headless page never gains document-level focus
  // (document.hasFocus() === false throughout), so a script-triggered
  // .focus() is a no-op for document.activeElement here — a headless/CDP
  // characteristic, not something a real browser tab (which does have
  // focus) exhibits. EventDetailsForm's guard is driven by real
  // focusin/focusout events, which this harness can't reliably synthesize,
  // so — like the own-echo test below, which sets _lastLocalSaveAt directly
  // — this drives the internal _interacting flag directly rather than via a
  // simulated interaction. Setting .value without dispatching 'change'
  // still avoids a genuine PATCH/persisted write either way, keeping this
  // non-destructive per tests/ui/README.md.
  await page.eval(`document.querySelector('pb-event-details-form')._interacting = true`);
  await page.eval(`document.querySelector('[name="title"]').value = ${JSON.stringify(marker)}`);

  await page.eval(`(async () => {
    const ws = document.querySelector('pb-event-workspace');
    window.pan.bus.publish('data.invalidated', { entity: 'event', id: ws.eventId, revision: 910020 });
    await new Promise((r) => setTimeout(r, 500));
  })()`);
  await new Promise((r) => setTimeout(r, 200));

  const stillThere = await page.eval(`document.querySelector('[name="title"]')?.value`);
  assert.equal(stillThere, marker, 'the actively-edited Details form field survives a realtime-triggered invalidation untouched');

  const bannerShown = await page.until(`document.querySelector('[data-realtime-stale]')`, 2000);
  assert.ok(bannerShown, 'an "updated elsewhere" banner appears instead of silently overwriting the active edit');

  // _interacting was set directly above (see the comment on that line), not
  // via a real, self-clearing focus/blur pair — reset it explicitly so it
  // doesn't leak into the next test sharing this same persisted instance.
  await page.eval(`document.querySelector('pb-event-details-form')._interacting = false`);
});

test('a realtime invalidation silently refreshes the Details form when no field is focused', async (page) => {
  if (!page.hasEvent) page.skip('UI_EVENT_ID not found');
  await page.openEvent();
  await page.until(`document.querySelector('[name="title"]')`);
  await new Promise((r) => setTimeout(r, 800)); // let any refresh left in flight by the previous test land first
  // No top-level const/let here — Runtime.evaluate shares one global lexical
  // scope across separate eval() calls, so redeclaring the same identifier
  // in more than one of these template strings throws a SyntaxError.
  await page.eval(`document.activeElement?.blur(); document.querySelector('[data-realtime-stale]')?.remove(); document.querySelector('pb-event-details-form') && (document.querySelector('pb-event-details-form')._interacting = false);`);

  const result = await page.eval(`(async () => {
    const detailsForm = document.querySelector('pb-event-details-form');
    detailsForm._lastLocalSaveAt = 0; // not a self-save echo
    const originalFormNode = document.querySelector('pb-event-details-form form');
    originalFormNode.dataset.preRefreshMarker = '1';
    const ws = document.querySelector('pb-event-workspace');
    window.pan.bus.publish('data.invalidated', { entity: 'event', id: ws.eventId, revision: 910030 });
    await new Promise((r) => setTimeout(r, 600));
    const currentFormNode = document.querySelector('pb-event-details-form form');
    return { rerendered: currentFormNode?.dataset.preRefreshMarker !== '1' };
  })()`);

  assert.ok(result.rerendered, 'the idle Details form re-rendered from a fresh fetch (the old <form> node was replaced)');
});

test('a Details form autosave does not show itself an "updated elsewhere" banner (own-write echo suppression)', async (page) => {
  if (!page.hasEvent) page.skip('UI_EVENT_ID not found');
  await page.openEvent();
  await page.until(`document.querySelector('[name="title"]')`);
  await new Promise((r) => setTimeout(r, 800)); // let any refresh left in flight by the previous test land first
  // No top-level const/let here — Runtime.evaluate shares one global lexical
  // scope across separate eval() calls, so redeclaring the same identifier
  // in more than one of these template strings throws a SyntaxError.
  await page.eval(`document.activeElement?.blur(); document.querySelector('[data-realtime-stale]')?.remove(); document.querySelector('pb-event-details-form') && (document.querySelector('pb-event-details-form')._interacting = false);`);

  const result = await page.eval(`(async () => {
    const detailsForm = document.querySelector('pb-event-details-form');
    detailsForm._lastLocalSaveAt = Date.now(); // simulate "we just autosaved" without a real PATCH
    const originalFormNode = document.querySelector('pb-event-details-form form');
    originalFormNode.dataset.preRefreshMarker = '1';
    const ws = document.querySelector('pb-event-workspace');
    window.pan.bus.publish('data.invalidated', { entity: 'event', id: ws.eventId, revision: 910040 });
    await new Promise((r) => setTimeout(r, 500));
    const currentFormNode = document.querySelector('pb-event-details-form form');
    return {
      bannerShown: !!document.querySelector('[data-realtime-stale]'),
      rerendered: currentFormNode?.dataset.preRefreshMarker !== '1',
    };
  })()`);

  assert.ok(!result.bannerShown, "an invalidation landing right after a local save is treated as this tab's own echo, not shown as a remote change");
  assert.ok(!result.rerendered, 'the echoed invalidation triggers no refetch/re-render at all');
});
