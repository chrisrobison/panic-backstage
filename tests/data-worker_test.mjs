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
const es = MockEventSource.instances.at(-1);
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
