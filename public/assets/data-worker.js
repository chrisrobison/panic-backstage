// data-worker.js — dedicated Web Worker that owns ordinary JSON API
// networking and the realtime invalidation stream for the main thread.
// See docs/realtime-data.md for the full architecture.
//
// Responsibilities (see the Worker Request Protocol in the architecture
// doc for the exact message shapes):
//   - `api.request`  → fetch, with in-flight GET dedup + a short GET cache.
//   - `realtime.start`/`realtime.stop` → own the EventSource connection.
//
// Deliberately dumb about application semantics: it doesn't know what an
// "event" or a "lead" is, doesn't parse response bodies beyond JSON, and
// never touches localStorage/DOM (neither is available to a worker). All
// of that stays in core.js / the components — this file is transport only.
//
// Structured-clone safety: every message this worker sends or receives must
// be plain data (strings/numbers/plain objects/arrays) — never a Response,
// a Headers object, or a Worker-side closure. See postMessage() call sites
// below.

const DEBUG = new URL(self.location.href).searchParams.get('debug') === '1';
function log(...args) { if (DEBUG) console.log('[data-worker]', ...args); }

let apiBase = null; // absolute URL string, trailing slash, set by the 'init' message

// ── GET cache + in-flight dedup ──────────────────────────────────────────────
// Deliberately conservative — see docs/realtime-data.md's "Cache behavior"
// section for why a short max-age + "clear everything on any mutation or
// invalidation" beats a clever partial-invalidation scheme here.
const CACHE_MAX_AGE_MS = 2000;
const cache = new Map();     // 'GET:/path' -> { status, ok, body, cachedAt }
const inflight = new Map();  // 'GET:/path' -> { epoch, promise }

// Cache generation / epoch. Bumped every time the cache is invalidated
// (successful mutation or realtime invalidation) so a GET that started
// before the bump can never satisfy a post-invalidation caller and can
// never repopulate the cache after it resolves — see docs/realtime-data.md's
// "stale in-flight GET" note. Deliberately NOT request cancellation: the
// original in-flight request still resolves for its own caller, it just
// stops being eligible for dedup/caching once the epoch has moved past it.
let cacheEpoch = 0;

function clearCache(reason) {
  cacheEpoch++;
  if (cache.size) log(`clearing GET cache (${cache.size} entries) — ${reason}`);
  cache.clear();
}

function cacheKey(method, path) {
  return `${method}:${path}`;
}

// ── Ordinary JSON API traffic ────────────────────────────────────────────────

async function doFetch(path, options, token) {
  const url = new URL(String(path).replace(/^\/+/, ''), apiBase).toString();
  // Precedence matches core.js's direct-fetch path exactly: defaults first,
  // then the token-derived Authorization, then caller-supplied headers last
  // (so an explicit options.headers entry — none of api()'s current call
  // sites set one, but the contract should hold regardless — always wins).
  const defaults = { 'Content-Type': 'application/json' };
  if (token) defaults.Authorization = `Bearer ${token}`;
  const headers = { ...defaults, ...(options.headers || {}) };
  const fetchOptions = { method: options.method || 'GET', headers };
  if (options.body !== undefined && options.body !== null) fetchOptions.body = options.body;

  let response;
  try {
    response = await fetch(url, fetchOptions);
  } catch (err) {
    // Network-level failure (offline, DNS, CORS, etc.) — never throw across
    // the worker boundary; report it as a synthetic non-ok response so
    // core.js's existing error-handling path (checks .ok / .status) works
    // unmodified whether the request went through the worker or direct fetch.
    return { status: 0, ok: false, body: { error: err?.message || 'Network error' } };
  }

  const body = response.status === 204 ? null : await response.json().catch(() => null);
  return { status: response.status, ok: response.ok, body };
}

async function handleApiRequest(msg) {
  const { id, path, options = {}, token } = msg;
  const method = String(options.method || 'GET').toUpperCase();
  // A caller-supplied header makes this request potentially semantically
  // different from a plain GET to the same path (e.g. a conditional
  // If-None-Match, a different Accept) — treat it like cache: 'no-store'
  // rather than risk sharing a cached/deduplicated response across two
  // requests that aren't actually equivalent. See docs/realtime-data.md.
  const hasCustomHeaders = !!options.headers && Object.keys(options.headers).length > 0;
  const noStore = options.cache === 'no-store' || hasCustomHeaders;
  const key = cacheKey(method, path);

  if (method !== 'GET') {
    const result = await doFetch(path, options, token);
    if (result.ok) clearCache(`mutation ${method} ${path}`);
    respond(id, result);
    return;
  }

  if (!noStore) {
    const cached = cache.get(key);
    if (cached && Date.now() - cached.cachedAt < CACHE_MAX_AGE_MS) {
      log('cache hit', key);
      respond(id, cached);
      return;
    }
    // Only dedup onto an in-flight GET that started in the *current* cache
    // generation. An in-flight GET from before the last invalidation may
    // still resolve with pre-invalidation data — it must not be handed to a
    // caller that asked for data *after* that invalidation happened.
    const existing = inflight.get(key);
    if (existing && existing.epoch === cacheEpoch) {
      log('dedup', key);
      respond(id, await existing.promise);
      return;
    }
  }

  const requestEpoch = cacheEpoch;
  const promise = doFetch(path, options, token);
  if (!noStore) inflight.set(key, { epoch: requestEpoch, promise });
  try {
    const result = await promise;
    // Only repopulate the cache if nothing invalidated it while this
    // request was in flight — an old-epoch response must never overwrite a
    // newer (or now-empty) cache generation.
    if (!noStore && result.ok && result.status !== 204 && requestEpoch === cacheEpoch) {
      cache.set(key, { ...result, cachedAt: Date.now() });
    }
    respond(id, result);
  } finally {
    if (!noStore) {
      // Only clear the map entry if it's still ours — a newer GET for the
      // same key may have already replaced it (see the epoch check above).
      const current = inflight.get(key);
      if (current && current.promise === promise) inflight.delete(key);
    }
  }
}

function respond(id, result) {
  postMessage({ type: 'api.response', id, status: result.status, ok: result.ok, body: result.body });
}

// ── Realtime (Server-Sent Events) ────────────────────────────────────────────
// Only this file knows SSE/EventSource exists — core.js and every component
// only ever see `realtime.status` / `realtime.invalidate` messages, so a
// future swap to WebSocket touches only this section (see docs/realtime-data.md).

let eventSource = null;
let realtimeWanted = false;   // true between realtime.start and realtime.stop
let reconnectTimer = null;
let reconnectDelayMs = 1000;  // manual backoff for terminal failures (e.g. 401); capped below
const RECONNECT_MAX_MS = 30000;
let lastReportedState = null;

function reportRealtimeStatus(state, detail) {
  if (state === lastReportedState && !detail) return; // avoid duplicate spam on repeated transient errors
  lastReportedState = state;
  log('realtime status:', state, detail || '');
  postMessage({ type: 'realtime.status', state, detail: detail || null });
}

function startRealtime() {
  realtimeWanted = true;
  reconnectDelayMs = 1000;
  openEventSource();
}

function stopRealtime() {
  realtimeWanted = false;
  clearTimeout(reconnectTimer);
  reconnectTimer = null;
  closeEventSource();
  reportRealtimeStatus('disconnected', 'stopped');
}

function closeEventSource() {
  if (eventSource) {
    eventSource.close();
    eventSource = null;
  }
}

function openEventSource() {
  if (!realtimeWanted || !apiBase || typeof EventSource === 'undefined') return;
  closeEventSource();
  reportRealtimeStatus('connecting');

  const url = new URL('realtime/stream', apiBase).toString();
  let source;
  try {
    source = new EventSource(url);
  } catch (err) {
    log('EventSource construction failed', err);
    scheduleReconnect('construction failed');
    return;
  }
  eventSource = source;

  // Every handler below guards on `eventSource !== source` first. A late
  // callback from an EventSource instance that's no longer the active
  // connection (e.g. a reconnect already replaced it, or stopRealtime() ran
  // in between the browser queuing the event and it firing) must not touch
  // realtime state, clear the cache, or schedule a reconnect on its behalf —
  // only the current instance's events are meaningful.
  source.addEventListener('open', () => {
    if (eventSource !== source) return;
    reconnectDelayMs = 1000; // reset backoff once we've successfully connected
    reportRealtimeStatus('connected');
  });

  source.addEventListener('invalidate', (event) => {
    if (eventSource !== source) return;
    let data;
    try {
      data = JSON.parse(event.data);
    } catch {
      return; // malformed frame — ignore rather than crash the connection
    }
    // Conservative cache policy: any realtime invalidation clears the whole
    // GET cache rather than trying to map entity->cache-key precisely.
    clearCache(`realtime invalidation (${data.entity}${data.id != null ? ':' + data.id : ''})`);
    postMessage({
      type: 'realtime.invalidate',
      entity: data.entity,
      id: data.id ?? null,
      revision: Number(event.lastEventId || data.revision),
    });
  });

  source.addEventListener('error', () => {
    if (eventSource !== source) return;
    // readyState CONNECTING: the browser is already auto-retrying (this
    // covers both plain network blips and the server's own periodic clean
    // termination — see docs/realtime-data.md's "reconnect behavior"). Only
    // readyState CLOSED means the browser gave up entirely (e.g. the server
    // answered with a non-2xx / wrong content-type, most commonly a 401),
    // which needs our own manual backoff-retry below.
    if (source.readyState === EventSource.CONNECTING) {
      reportRealtimeStatus('connecting', 'reconnecting');
      return;
    }
    reportRealtimeStatus('disconnected', 'error');
    closeEventSource();
    scheduleReconnect('connection closed');
  });
}

function scheduleReconnect(reason) {
  if (!realtimeWanted || reconnectTimer) return;
  log('scheduling reconnect in', reconnectDelayMs, 'ms —', reason);
  reconnectTimer = setTimeout(() => {
    reconnectTimer = null;
    reconnectDelayMs = Math.min(reconnectDelayMs * 2, RECONNECT_MAX_MS);
    openEventSource();
  }, reconnectDelayMs);
}

// ── Message routing ───────────────────────────────────────────────────────

self.addEventListener('message', (event) => {
  const msg = event.data || {};
  switch (msg.type) {
    case 'init':
      apiBase = msg.apiBase;
      log('initialized, apiBase =', apiBase);
      break;
    case 'api.request':
      handleApiRequest(msg);
      break;
    case 'realtime.start':
      startRealtime();
      break;
    case 'realtime.stop':
      stopRealtime();
      break;
    default:
      log('unknown message type', msg.type);
  }
});

log('worker script evaluated');
