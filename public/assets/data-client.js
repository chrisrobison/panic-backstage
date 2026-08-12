// data-client.js — main-thread Worker RPC wrapper around data-worker.js.
// This is the only file that constructs the Worker; core.js talks to it
// through this small API and never touches postMessage/Worker directly.
// See docs/realtime-data.md for the full architecture and the fallback
// hierarchy (worker+realtime -> worker only -> direct fetch) this exists to
// support.
//
// Everything here is best-effort: construction failures, worker runtime
// errors, and unsupported browsers all degrade to `isAvailable() === false`
// rather than throwing, so core.js's api() can fall back to a direct fetch
// transparently. See DEBUGGING.md-equivalent notes below for the debug flag.

const DEBUG_KEY = 'backstage_debug_data';
const WORKER_DISABLED_KEY = 'backstage_worker_disabled';

function readFlag(key) {
  try { return localStorage.getItem(key) === '1'; } catch { return false; }
}

const DEBUG = readFlag(DEBUG_KEY);
function log(...args) { if (DEBUG) console.log('[data-client]', ...args); }

let worker = null;
let available = false;
let nextId = 0;
const pending = new Map(); // id -> { resolve, reject }
const realtimeStatusHandlers = new Set();
const realtimeInvalidateHandlers = new Set();
let realtimeState = 'disconnected';
let realtimeDetail = null;

function notifyRealtimeStatus(state, detail) {
  realtimeState = state;
  realtimeDetail = detail || null;
  for (const cb of realtimeStatusHandlers) cb({ state, detail: detail || null });
}

function onMessage(event) {
  const msg = event.data || {};
  if (msg.type === 'api.response') {
    const entry = pending.get(msg.id);
    if (!entry) return;
    pending.delete(msg.id);
    entry.resolve({ status: msg.status, ok: msg.ok, body: msg.body });
  } else if (msg.type === 'realtime.status') {
    notifyRealtimeStatus(msg.state, msg.detail);
  } else if (msg.type === 'realtime.invalidate') {
    for (const cb of realtimeInvalidateHandlers) cb({ entity: msg.entity, id: msg.id, revision: msg.revision });
  }
}

function failAllPending(reason) {
  for (const [, entry] of pending) entry.reject(new Error(reason));
  pending.clear();
}

/** Mark the worker unusable for the rest of this page's lifetime — api() falls back to direct fetch from here on. */
function disable(reason) {
  if (!available && !worker) return;
  log('disabling worker path:', reason);
  available = false;
  failAllPending(reason || 'worker unavailable');
  try { worker?.terminate(); } catch { /* already gone */ }
  worker = null;
  notifyRealtimeStatus('disconnected', 'worker unavailable');
}

/**
 * Construct and initialize the worker. Safe to call even when Worker/module
 * workers aren't supported, or construction throws for any reason (some
 * privacy modes / extremely old browsers) — falls back to `available: false`
 * rather than propagating the error to callers.
 *
 * @param {string} apiBase Absolute base URL the worker should resolve API
 *                          paths against (mirrors core.js's apiUrl('')).
 */
function init(apiBase) {
  if (readFlag(WORKER_DISABLED_KEY)) {
    log('worker disabled via localStorage flag');
    return;
  }
  if (typeof Worker === 'undefined') {
    log('Worker is not supported in this environment');
    return;
  }
  try {
    const workerUrl = new URL('./data-worker.js', import.meta.url);
    if (DEBUG) workerUrl.searchParams.set('debug', '1');
    worker = new Worker(workerUrl, { type: 'module' });
    worker.addEventListener('message', onMessage);
    worker.addEventListener('error', (event) => {
      disable(`worker runtime error: ${event.message || 'unknown'}`);
    });
    worker.postMessage({ type: 'init', apiBase });
    available = true;
    log('worker started, apiBase =', apiBase);
  } catch (err) {
    log('worker construction failed, falling back to direct fetch:', err);
    worker = null;
    available = false;
  }
}

/** Whether `options` is eligible to go through the worker at all (see docs/realtime-data.md's "Requests that should NOT go through the worker"). */
function canHandle(options = {}) {
  return available && !(options.body instanceof FormData);
}

/**
 * Send one API request through the worker. Rejects (never resolves with a
 * thrown error) if the worker is unavailable — callers should check
 * canHandle()/isAvailable() first, but this is also safe to call blind.
 *
 * @returns {Promise<{status:number, ok:boolean, body:any}>}
 */
function request(path, options, token) {
  if (!available || !worker) return Promise.reject(new Error('worker unavailable'));
  const id = `r${++nextId}`;
  const { method, headers, body, cache } = options || {};
  return new Promise((resolve, reject) => {
    pending.set(id, { resolve, reject });
    try {
      // Only structured-clone-safe, plain-data fields cross the boundary —
      // canHandle() already excluded FormData bodies.
      worker.postMessage({ type: 'api.request', id, path, options: { method, headers, body, cache }, token });
    } catch (err) {
      pending.delete(id);
      reject(err);
    }
  });
}

function startRealtime() {
  if (!available || !worker) return;
  worker.postMessage({ type: 'realtime.start' });
}

function stopRealtime() {
  if (!available || !worker) return;
  worker.postMessage({ type: 'realtime.stop' });
}

function onRealtimeStatus(cb) {
  realtimeStatusHandlers.add(cb);
  return () => realtimeStatusHandlers.delete(cb);
}

function onRealtimeInvalidate(cb) {
  realtimeInvalidateHandlers.add(cb);
  return () => realtimeInvalidateHandlers.delete(cb);
}

function isAvailable() { return available; }
function getRealtimeState() { return { state: realtimeState, detail: realtimeDetail }; }

export const dataClient = {
  init,
  request,
  canHandle,
  startRealtime,
  stopRealtime,
  onRealtimeStatus,
  onRealtimeInvalidate,
  isAvailable,
  getRealtimeState,
  disable,
};
