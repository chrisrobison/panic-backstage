// Realtime data layer smoke tests (docs/realtime-data.md) — the Web Worker
// transport and the SSE realtime connection, end to end against the real
// dev server. Deep worker-internal behavior (dedup/cache/mutation
// invalidation) is covered hermetically in tests/data-worker_test.mjs,
// which mocks the Worker global scope directly; this file only asserts
// what's observable from the page: the worker actually started in a real
// browser, and the realtime connection actually reaches src/Realtime.php
// and comes up healthy (cookie auth, tenant routing, SSE headers all
// working end to end).
import { test, assert } from './harness.mjs';

test('the data-worker starts and becomes available on a real page load', async (page) => {
  await page.goto('#dashboard');
  assert.ok(
    await page.eval(`typeof window.PBData === 'object' && window.PBData !== null`),
    'window.PBData (the data-client singleton) is exposed for diagnostics',
  );
  assert.ok(
    await page.until(`window.PBData.isAvailable() === true`, 5000),
    'the worker constructs successfully and reports itself available in a real browser',
  );
});

test('realtime connects end to end against the live server', async (page) => {
  await page.goto('#dashboard');
  // <pb-app-shell> calls dataClient.startRealtime() once /me resolves — give
  // it a real round trip (cookie auth -> Kernel -> tenant DB -> SSE headers)
  // to reach 'connected'.
  const connected = await page.until(`window.PBData.getRealtimeState().state === 'connected'`, 10000);
  assert.ok(connected, 'the realtime EventSource reaches the connected state against the real /api/realtime/stream endpoint');
});

test('ordinary api() traffic actually renders real server data through the worker (not a silent fallback masking a broken path)', async (page) => {
  await page.goto('#events');
  await page.until(`window.PBData.isAvailable() === true`);
  // The whole app's api() calls now default through the worker (see
  // core.js's _execute()); every other UI test implicitly depends on this
  // succeeding, but this one asserts it directly against a page that's
  // pure server data (the Events list has no client-only content to fall
  // back on if fetching silently failed).
  assert.ok(
    await page.until(`document.querySelectorAll('.data-table tbody tr, .empty-state').length > 0`),
    'the Events list renders real content fetched through the worker-backed api()',
  );
});
