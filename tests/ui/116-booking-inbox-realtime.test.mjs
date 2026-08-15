// Booking Inbox realtime migration (docs/realtime-data.md, Phase 3) —
// public/assets/inbox/inbox-shell.js no longer depends on the old constant
// 8-second poll when realtime is healthy. Simulates invalidations directly
// on the real PAN/LARC bus (`window.pan.bus.publish(...)`, exactly what
// core.js's own publish() wrapper does internally once the worker relays a
// real SSE event) so these assertions are deterministic and don't depend on
// the timing of an actual server-side db_history write.
import { test, assert } from './harness.mjs';

async function mountInbox(page) {
  await page.goto('#inbox-unassigned');
  await page.until(`document.querySelector('pb-inbox-app') && document.querySelectorAll('.ib-list-item').length > 0`);
}

test('a lead invalidation triggers the same refresh path the old poll used, debounced across a burst', async (page) => {
  await mountInbox(page);

  const result = await page.eval(`(async () => {
    const app = document.querySelector('pb-inbox-app');
    if (!app) return { error: 'pb-inbox-app not mounted' };
    let calls = 0;
    const original = app.pollChanges.bind(app);
    app.pollChanges = async (...args) => { calls++; return original(...args); };
    // Three lead invalidations back to back — one database transaction that
    // touches leads + lead_messages + lead_audit_log would produce exactly
    // this shape of burst in production.
    window.pan.bus.publish('data.invalidated', { entity: 'lead', id: 1, revision: 900001 });
    window.pan.bus.publish('data.invalidated', { entity: 'lead', id: 1, revision: 900002 });
    window.pan.bus.publish('data.invalidated', { entity: 'lead', id: 1, revision: 900003 });
    await new Promise((r) => setTimeout(r, 600));
    app.pollChanges = original;
    return { calls };
  })()`);

  assert.ok(!result.error, result.error || 'ok');
  assert.equal(result.calls, 1, 'three quick lead invalidations debounce into exactly one pollChanges() call');
});

test('an unrelated (non-lead) invalidation does not trigger a refresh', async (page) => {
  await mountInbox(page);

  const result = await page.eval(`(async () => {
    const app = document.querySelector('pb-inbox-app');
    let calls = 0;
    const original = app.pollChanges.bind(app);
    app.pollChanges = async (...args) => { calls++; return original(...args); };
    window.pan.bus.publish('data.invalidated', { entity: 'event', id: 999, revision: 900010 });
    window.pan.bus.publish('data.invalidated', { entity: 'global', revision: 900011 });
    await new Promise((r) => setTimeout(r, 500));
    app.pollChanges = original;
    return { calls };
  })()`);

  assert.equal(result.calls, 0, 'event/global invalidations are ignored by the Inbox — only entity "lead" triggers a refresh');
});

test('the currently selected lead remains selected across a realtime-triggered refresh', async (page) => {
  await mountInbox(page);
  await page.click('.ib-list-item');
  await page.until(`document.querySelector('.ib-workspace-title-row h1')?.textContent?.trim().length > 0`);

  const before = await page.eval(`document.querySelector('pb-inbox-app').selectedLeadId`);
  assert.ok(before, 'a lead is selected before the invalidation');

  await page.eval(`window.pan.bus.publish('data.invalidated', { entity: 'lead', id: ${JSON.stringify(before)}, revision: 900021 })`);
  await new Promise((r) => setTimeout(r, 500));

  const after = await page.eval(`document.querySelector('pb-inbox-app').selectedLeadId`);
  assert.equal(after, before, 'selection is preserved after a realtime-triggered reload (pollChanges only refreshes, never remounts)');
});

test('old constant 8-second polling is not running once realtime is healthy', async (page) => {
  await mountInbox(page);
  const healthyAndNoTimer = await page.until(
    `window.PBData.getRealtimeState().state === 'connected' && document.querySelector('pb-inbox-app')._pollTimer === null`,
    10000,
  );
  assert.ok(healthyAndNoTimer, 'the fallback poll timer is cleared once realtime.status reports connected');
});

test('a slow fallback poll resumes if realtime becomes unhealthy', async (page) => {
  await mountInbox(page);
  await page.until(`window.PBData.getRealtimeState().state === 'connected'`);

  const timerStarted = await page.eval(`(async () => {
    const app = document.querySelector('pb-inbox-app');
    window.pan.bus.publish('realtime.status', { state: 'disconnected', detail: 'test' });
    await new Promise((r) => setTimeout(r, 50));
    return app._pollTimer !== null;
  })()`);
  assert.ok(timerStarted, 'a realtime.status "disconnected" message restarts the slow fallback poll');

  // Restore healthy state so the timer doesn't linger oddly for later tests
  // in this run (harmless either way, but keeps state predictable).
  await page.eval(`window.pan.bus.publish('realtime.status', { state: 'connected', detail: null })`);
});
