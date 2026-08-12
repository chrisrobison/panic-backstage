#!/usr/bin/env node
// Hermetic unit test for public/assets/data-worker.js — no browser, no DB,
// no network. Mocks the Worker global scope (self/postMessage/fetch/
// EventSource) BEFORE importing the module, since it wires up
// self.addEventListener('message', ...) and reads self.location as a
// top-level side effect (exactly as it would when constructed as a real
// dedicated Worker — see docs/realtime-data.md).
//
// Covers the "Worker/API behavior" test list in the realtime-data spec that
// doesn't need a live server: normal GET, correlation ids, GET dedup, GET
// cache (+ no-store bypass), mutation-invalidates-cache, mutations are never
// deduped, 401 passthrough (the worker never attempts its own refresh — see
// core.js's tryRefresh()), network errors never throw across the worker
// boundary, and realtime invalidation → cache clear + relay to the main
// thread. Everything requiring a real EventSource/fetch/live server (401 ->
// refresh -> retry end to end, direct-fetch fallback, FormData bypass, the
// actual SSE round trip) is covered by tests/ui/*.test.mjs against a real
// browser + server instead.
//
// Run with: node tests/data-worker_test.mjs
// (wired into the bash suite via tests/05-data-worker-unit.sh)

import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.dirname(fileURLToPath(import.meta.url));
const WORKER_PATH = path.join(ROOT, '..', 'public', 'assets', 'data-worker.js');

let passed = 0;
let failed = 0;
function ok(cond, label) {
  if (cond) { console.log(`  ✓ ${label}`); passed++; }
  else { console.log(`  ✗ FAIL: ${label}`); failed++; }
}

// ── Mock EventSource capturing listeners so tests can fire synthetic events ──
class MockEventSource {
  constructor(url) {
    this.url = String(url);
    this.readyState = MockEventSource.CONNECTING;
    this._listeners = {};
    MockEventSource.instances.push(this);
  }
  addEventListener(type, fn) { (this._listeners[type] ||= []).push(fn); }
  close() { this.readyState = MockEventSource.CLOSED; }
  emit(type, evt = {}) { (this._listeners[type] || []).forEach((fn) => fn(evt)); }
}
MockEventSource.CONNECTING = 0;
MockEventSource.OPEN = 1;
MockEventSource.CLOSED = 2;
MockEventSource.instances = [];

const messageHandlers = [];
const posted = [];
let fetchCalls = [];
let fetchImpl = async () => ({ status: 200, ok: true, json: async () => ({ ok: true }) });

globalThis.self = globalThis;
globalThis.location = new URL('http://test.local/assets/data-worker.js');
globalThis.postMessage = (msg) => posted.push(msg);
globalThis.fetch = async (url, opts) => {
  fetchCalls.push({ url: String(url), opts });
  return fetchImpl(url, opts);
};
globalThis.EventSource = MockEventSource;
globalThis.addEventListener = (type, handler) => { if (type === 'message') messageHandlers.push(handler); };

await import(WORKER_PATH);

function send(data) { for (const h of messageHandlers) h({ data }); }
function responsesFor(id) { return posted.filter((m) => m.type === 'api.response' && m.id === id); }
async function waitForResponse(id, tries = 200) {
  for (let i = 0; i < tries; i++) {
    const r = responsesFor(id);
    if (r.length) return r[r.length - 1];
    await new Promise((res) => setTimeout(res, 5));
  }
  throw new Error(`timed out waiting for api.response id=${id}`);
}
async function settle(ms = 15) { await new Promise((res) => setTimeout(res, ms)); }

console.log('\n=== data-worker.js (mocked Worker global scope) ===\n');

send({ type: 'init', apiBase: 'http://test.local/base/api/' });

// ── Normal GET ────────────────────────────────────────────────────────────
fetchCalls = [];
send({ type: 'api.request', id: 'g1', path: '/events/1', options: { method: 'GET' }, token: 'tok' });
let r = await waitForResponse('g1');
ok(r.ok === true && r.status === 200, 'normal GET resolves with {ok:true, status:200}');
ok(fetchCalls.length === 1, 'issued exactly one fetch for a single GET');
ok(fetchCalls[0].opts.headers.Authorization === 'Bearer tok', 'Authorization header carries the bearer token when provided');
ok(fetchCalls[0].url === 'http://test.local/base/api/events/1', 'resolves the request path against apiBase');

// ── Correlation ids resolve the correct promise for concurrent, distinct paths ──
fetchCalls = [];
send({ type: 'api.request', id: 'ida', path: '/a', options: { method: 'GET', cache: 'no-store' }, token: null });
send({ type: 'api.request', id: 'idb', path: '/b', options: { method: 'GET', cache: 'no-store' }, token: null });
const [ra, rb] = await Promise.all([waitForResponse('ida'), waitForResponse('idb')]);
ok(ra.ok && rb.ok, 'two distinct concurrent requests each resolve their own correlation id');

// ── Dedup: identical simultaneous GETs -> one network request ──────────────
fetchCalls = [];
send({ type: 'api.request', id: 'd1', path: '/dedupe-me', options: { method: 'GET' }, token: null });
send({ type: 'api.request', id: 'd2', path: '/dedupe-me', options: { method: 'GET' }, token: null });
await Promise.all([waitForResponse('d1'), waitForResponse('d2')]);
ok(fetchCalls.length === 1, 'two simultaneous identical GETs produce exactly one network request');

// ── Cache: a follow-up GET within the cache window is served from cache ────
fetchCalls = [];
send({ type: 'api.request', id: 'c1', path: '/dedupe-me', options: { method: 'GET' }, token: null });
await waitForResponse('c1');
ok(fetchCalls.length === 0, 'a GET within the cache window reuses the cached response instead of refetching');

// ── cache: 'no-store' bypasses both dedup and the cache ─────────────────────
fetchCalls = [];
send({ type: 'api.request', id: 'ns1', path: '/dedupe-me', options: { method: 'GET', cache: 'no-store' }, token: null });
await waitForResponse('ns1');
ok(fetchCalls.length === 1, "options.cache === 'no-store' bypasses the cache and always fetches");

// ── Custom headers bypass both cache and in-flight dedup ────────────────────
// A caller-supplied header can make a request semantically different from a
// plain GET to the same path (e.g. a conditional header) — two concurrent
// GETs to the same path with (different) custom headers must not be
// deduplicated onto each other, and a request with custom headers must
// never be served from (or write into) the shared cache.
fetchCalls = [];
send({ type: 'api.request', id: 'h1', path: '/headers-path', options: { method: 'GET', headers: { 'X-Custom': 'a' } }, token: null });
send({ type: 'api.request', id: 'h2', path: '/headers-path', options: { method: 'GET', headers: { 'X-Custom': 'b' } }, token: null });
await Promise.all([waitForResponse('h1'), waitForResponse('h2')]);
ok(fetchCalls.length === 2, 'two concurrent GETs to the same path with custom headers are not deduplicated onto each other');

fetchCalls = [];
send({ type: 'api.request', id: 'h3', path: '/headers-path', options: { method: 'GET', headers: { 'X-Custom': 'a' } }, token: null });
await waitForResponse('h3');
ok(fetchCalls.length === 1, 'a GET with custom headers is never served from cache, even for an identical path+headers pair');

fetchCalls = [];
send({ type: 'api.request', id: 'h4', path: '/headers-path', options: { method: 'GET' }, token: null });
await waitForResponse('h4');
ok(fetchCalls.length === 1, 'a plain GET (no custom headers) to a path previously requested with custom headers does not hit a cache entry from those requests');

// ── A successful mutation invalidates the whole GET cache ──────────────────
fetchCalls = [];
send({ type: 'api.request', id: 'p1', path: '/dedupe-me', options: { method: 'POST', body: '{}' }, token: null });
await waitForResponse('p1');
fetchCalls = [];
send({ type: 'api.request', id: 'c2', path: '/dedupe-me', options: { method: 'GET' }, token: null });
await waitForResponse('c2');
ok(fetchCalls.length === 1, 'a successful mutation invalidates the cache — the next GET refetches');

// ── Mutations are never deduplicated, even if identical ─────────────────────
fetchCalls = [];
send({ type: 'api.request', id: 'pa1', path: '/x', options: { method: 'PATCH', body: '{"a":1}' }, token: null });
send({ type: 'api.request', id: 'pa2', path: '/x', options: { method: 'PATCH', body: '{"a":1}' }, token: null });
await Promise.all([waitForResponse('pa1'), waitForResponse('pa2')]);
ok(fetchCalls.length === 2, 'identical simultaneous mutations are never deduplicated — each PATCH issues its own request');

// ── 401 passes straight through — the worker never attempts its own refresh ─
fetchImpl = async () => ({ status: 401, ok: false, json: async () => ({ error: 'expired' }) });
send({ type: 'api.request', id: 'u1', path: '/needs-auth', options: { method: 'GET', cache: 'no-store' }, token: 'stale' });
r = await waitForResponse('u1');
ok(r.status === 401 && r.ok === false, 'a 401 response is reported as-is (core.js on the main thread owns tryRefresh()/retry)');
fetchImpl = async () => ({ status: 200, ok: true, json: async () => ({ ok: true }) });

// ── Network-level failures never throw across the worker boundary ──────────
fetchImpl = async () => { throw new Error('offline'); };
send({ type: 'api.request', id: 'net1', path: '/unreachable', options: { method: 'GET', cache: 'no-store' }, token: null });
r = await waitForResponse('net1');
ok(r.ok === false && r.status === 0, 'a network-level fetch failure resolves as a synthetic {ok:false,status:0} response, not an uncaught rejection');
fetchImpl = async () => ({ status: 200, ok: true, json: async () => ({ ok: true }) });

// ── Realtime: EventSource is opened against apiBase + 'realtime/stream' ────
send({ type: 'realtime.start' });
await settle();
let es = MockEventSource.instances.at(-1);
ok(!!es, 'realtime.start constructs an EventSource');
ok(es?.url === 'http://test.local/base/api/realtime/stream', 'EventSource targets <apiBase>/realtime/stream');
ok(posted.some((m) => m.type === 'realtime.status' && m.state === 'connecting'), "realtime.start reports realtime.status 'connecting'");

posted.length = 0;
es.emit('open');
await settle();
ok(posted.some((m) => m.type === 'realtime.status' && m.state === 'connected'), "EventSource 'open' reports realtime.status 'connected'");

// ── Realtime invalidation: relayed to the main thread AND clears the cache ──
fetchCalls = [];
send({ type: 'api.request', id: 'rc1', path: '/dedupe-me', options: { method: 'GET' }, token: null });
await waitForResponse('rc1');

posted.length = 0;
es.emit('invalidate', { data: JSON.stringify({ entity: 'event', id: 42, revision: 555 }), lastEventId: '555' });
await settle();
const invalidateMsg = posted.find((m) => m.type === 'realtime.invalidate');
ok(
  invalidateMsg?.entity === 'event' && invalidateMsg?.id === 42 && invalidateMsg?.revision === 555,
  'an SSE invalidate event is relayed to the main thread with entity/id/revision intact'
);

fetchCalls = [];
send({ type: 'api.request', id: 'rc2', path: '/dedupe-me', options: { method: 'GET' }, token: null });
await waitForResponse('rc2');
ok(fetchCalls.length === 1, 'a realtime invalidation clears the GET cache — the next GET refetches instead of hitting stale cache');

// ── Cache generation / epoch: invalidation while a GET is in flight ────────
// A GET that began before an SSE invalidation must not (a) be handed to a
// later caller asking for the same path post-invalidation via dedup, or
// (b) repopulate the cache with its (now stale) response once it resolves.
{
  const resolvers = [];
  fetchImpl = () => new Promise((resolve) => resolvers.push(resolve));

  fetchCalls = [];
  send({ type: 'api.request', id: 'ep-a', path: '/epoch-path', options: { method: 'GET' }, token: null });
  await settle();
  ok(fetchCalls.length === 1 && resolvers.length === 1, 'GET A starts and its fetch is left unresolved');

  // Invalidate via realtime while GET A is still in flight.
  es.emit('invalidate', { data: JSON.stringify({ entity: 'global', revision: 900 }), lastEventId: '900' });
  await settle();

  fetchCalls = [];
  send({ type: 'api.request', id: 'ep-b', path: '/epoch-path', options: { method: 'GET' }, token: null });
  await settle();
  ok(
    fetchCalls.length === 1 && resolvers.length === 2,
    'GET B, issued after the invalidation, produces its own new network request instead of deduplicating onto pre-invalidation GET A'
  );

  // Resolve A (old data) first, then B (new data) — order matters: A
  // resolving last would be the easy case, this specifically exercises A
  // finishing *after* it has already been superseded.
  resolvers[0]({ status: 200, ok: true, json: async () => ({ value: 'old' }) });
  const ra = await waitForResponse('ep-a');
  ok(ra.ok && ra.body?.value === 'old', 'GET A still resolves to its own original caller with its (stale) response');

  resolvers[1]({ status: 200, ok: true, json: async () => ({ value: 'new' }) });
  const rb = await waitForResponse('ep-b');
  ok(rb.ok && rb.body?.value === 'new', 'GET B resolves with the fresh response');

  fetchCalls = [];
  send({ type: 'api.request', id: 'ep-c', path: '/epoch-path', options: { method: 'GET' }, token: null });
  const rcEpoch = await waitForResponse('ep-c');
  ok(
    fetchCalls.length === 0 && rcEpoch.body?.value === 'new',
    'the cache ends up containing the new (post-invalidation) response, not the stale pre-invalidation one that resolved later'
  );

  fetchImpl = async () => ({ status: 200, ok: true, json: async () => ({ ok: true }) });
}

// ── Cache generation / epoch: a successful mutation while a GET is in flight ─
// A successful POST/PATCH/DELETE must advance the same cache generation as
// an SSE invalidation — same race, same fix, different trigger.
{
  const resolvers = [];
  fetchImpl = (url, opts) => {
    if ((opts?.method || 'GET') === 'GET') {
      return new Promise((resolve) => resolvers.push(resolve));
    }
    return Promise.resolve({ status: 200, ok: true, json: async () => ({ ok: true }) });
  };

  fetchCalls = [];
  send({ type: 'api.request', id: 'mu-a', path: '/mutate-path', options: { method: 'GET' }, token: null });
  await settle();
  ok(fetchCalls.length === 1 && resolvers.length === 1, 'GET A starts and its fetch is left unresolved');

  send({ type: 'api.request', id: 'mu-p', path: '/mutate-path', options: { method: 'PATCH', body: '{}' }, token: null });
  await waitForResponse('mu-p');

  fetchCalls = [];
  send({ type: 'api.request', id: 'mu-b', path: '/mutate-path', options: { method: 'GET' }, token: null });
  await settle();
  ok(
    fetchCalls.length === 1 && resolvers.length === 2,
    'GET B, issued after a successful mutation, produces its own new network request instead of deduplicating onto the pre-mutation in-flight GET'
  );

  resolvers[0]({ status: 200, ok: true, json: async () => ({ value: 'old' }) });
  await waitForResponse('mu-a');

  resolvers[1]({ status: 200, ok: true, json: async () => ({ value: 'new' }) });
  await waitForResponse('mu-b');

  fetchCalls = [];
  send({ type: 'api.request', id: 'mu-c', path: '/mutate-path', options: { method: 'GET' }, token: null });
  const rcMutation = await waitForResponse('mu-c');
  ok(
    fetchCalls.length === 0 && rcMutation.body?.value === 'new',
    'the cache ends up containing the new (post-mutation) response, not the stale pre-mutation one that resolved later'
  );

  fetchImpl = async () => ({ status: 200, ok: true, json: async () => ({ ok: true }) });
}

// Malformed frame does not crash the worker or emit garbage.
posted.length = 0;
es.emit('invalidate', { data: 'not json' });
await settle();
ok(!posted.some((m) => m.type === 'realtime.invalidate'), 'a malformed invalidate frame is ignored rather than relayed or crashing the worker');

// ── Terminal failure (readyState CLOSED) reports disconnected ──────────────
posted.length = 0;
es.readyState = MockEventSource.CLOSED;
es.emit('error');
await settle();
ok(posted.some((m) => m.type === 'realtime.status' && m.state === 'disconnected'), 'a terminal EventSource error (readyState CLOSED) reports realtime.status disconnected');

// ── Stale EventSource guard: a late event from a no-longer-active connection
// must not affect current realtime state, relay an invalidation, or tear
// down the actually-active connection. `es` above is now closed/stale;
// force a fresh reconnect and keep both instances around to prove events
// from the old one are inert once a new one has taken over.
{
  const staleSource = es; // closed above, but its listeners are still wired
  send({ type: 'realtime.start' });
  await settle();
  const freshSource = MockEventSource.instances.at(-1);
  ok(freshSource && freshSource !== staleSource, 'a fresh EventSource instance replaces the stale one');

  // Late 'open' from the stale source must not report 'connected' — the
  // fresh (real) connection hasn't opened yet at this point.
  posted.length = 0;
  staleSource.emit('open');
  await settle();
  ok(
    !posted.some((m) => m.type === 'realtime.status' && m.state === 'connected'),
    "a late 'open' from a stale EventSource does not report the connection as connected"
  );

  // The fresh connection actually opening still works normally.
  posted.length = 0;
  freshSource.emit('open');
  await settle();
  ok(
    posted.some((m) => m.type === 'realtime.status' && m.state === 'connected'),
    'the fresh (actually active) connection opening still reports connected'
  );

  // Late 'invalidate' from the stale source must not relay a message or
  // clear the cache.
  fetchCalls = [];
  send({ type: 'api.request', id: 'stale-warm', path: '/stale-guard-path', options: { method: 'GET' }, token: null });
  await waitForResponse('stale-warm');
  ok(fetchCalls.length === 1, 'cache warmed for the stale-guard scenario');

  posted.length = 0;
  staleSource.emit('invalidate', { data: JSON.stringify({ entity: 'global', revision: 12345 }), lastEventId: '12345' });
  await settle();
  ok(
    !posted.some((m) => m.type === 'realtime.invalidate'),
    'a late invalidate event from a stale EventSource is not relayed to the main thread'
  );
  fetchCalls = [];
  send({ type: 'api.request', id: 'stale-check', path: '/stale-guard-path', options: { method: 'GET' }, token: null });
  await waitForResponse('stale-check');
  ok(fetchCalls.length === 0, "a late invalidate from a stale EventSource does not clear the cache — the follow-up GET is still a cache hit");

  // Late 'error' from the stale source must not disturb the fresh
  // connection's status, tear it down, or schedule a redundant reconnect.
  const instanceCountBefore = MockEventSource.instances.length;
  posted.length = 0;
  staleSource.readyState = MockEventSource.CLOSED;
  staleSource.emit('error');
  await settle();
  ok(!posted.some((m) => m.type === 'realtime.status'), "a late 'error' from a stale EventSource produces no realtime.status update");
  ok(MockEventSource.instances.length === instanceCountBefore, "a late 'error' from a stale EventSource does not construct another reconnect EventSource");

  // Prove the fresh connection is still fully wired as the active one (i.e.
  // the stale 'error' above did not null out the module's active-source
  // reference): its own invalidate should still relay and clear the cache.
  posted.length = 0;
  freshSource.emit('invalidate', { data: JSON.stringify({ entity: 'global', revision: 12346 }), lastEventId: '12346' });
  await settle();
  ok(
    posted.some((m) => m.type === 'realtime.invalidate'),
    "the fresh connection's own invalidate still relays normally after a stale sibling's late error"
  );
  fetchCalls = [];
  send({ type: 'api.request', id: 'stale-check-2', path: '/stale-guard-path', options: { method: 'GET' }, token: null });
  await waitForResponse('stale-check-2');
  ok(fetchCalls.length === 1, "the fresh connection's invalidate still clears the cache — it remains the fully-functional active connection");

  es = freshSource; // subsequent tests continue against the now-current connection
}

// ── realtime.stop reports disconnected and stops reconnecting ──────────────
posted.length = 0;
send({ type: 'realtime.stop' });
await settle();
ok(
  posted.some((m) => m.type === 'realtime.status' && m.state === 'disconnected' && m.detail === 'stopped'),
  'realtime.stop reports realtime.status disconnected/stopped'
);

console.log(`\ndata-worker.js: ${passed} passed, ${failed} failed.`);
process.exit(failed > 0 ? 1 : 0);
