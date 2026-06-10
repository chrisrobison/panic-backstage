// Zero-dependency headless-browser kit for Panic Backstage UI tooling.
//
// Drives the system Chromium/Chrome over the DevTools Protocol using only
// Node's built-in `fetch` + `WebSocket` (Node 21+/22+) — no npm, matching the
// app's no-build philosophy. Shared by:
//   • tests/ui/run.mjs        (the UI test runner)
//   • scripts/screenshots.mjs (the help-screenshot generator)
//
// Auth is non-destructive: it mints a one-shot magic-link token via
// scripts/login-link.php (no password is set or changed) and exchanges it for
// JWTs, exactly as a real login would.

import { spawn, execFileSync } from 'node:child_process';
import { mkdtempSync, existsSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

export async function waitFor(fn, { tries = 60, gap = 200 } = {}) {
  for (let i = 0; i < tries; i++) { if (await fn()) return true; await sleep(gap); }
  return false;
}

export async function reachable(url) {
  try { const r = await fetch(url, { redirect: 'manual' }); return r.status > 0; } catch { return false; }
}

// Read APP_BASE_PATH from .env so the URL matches how the app is mounted.
export function readBasePath(root) {
  const file = path.join(root, '.env');
  if (!existsSync(file)) return '';
  for (const line of readFileSync(file, 'utf8').split('\n')) {
    const m = line.match(/^\s*APP_BASE_PATH\s*=\s*(.+?)\s*$/);
    if (m) return m[1].replace(/^["']|["']$/g, '').replace(/\/$/, '');
  }
  return '';
}

export function findChromium() {
  for (const bin of ['chromium', 'chromium-browser', 'google-chrome', 'google-chrome-stable']) {
    try { execFileSync('bash', ['-lc', `command -v ${bin}`], { stdio: 'pipe' }); return bin; } catch { /* next */ }
  }
  throw new Error('No Chromium/Chrome binary found on PATH.');
}

// --- minimal Chrome DevTools Protocol client over the built-in WebSocket ----
export class CDP {
  constructor(ws) {
    this.ws = ws; this.id = 0; this.cbs = new Map(); this.handlers = [];
    ws.onmessage = (ev) => {
      const m = JSON.parse(ev.data);
      if (m.id && this.cbs.has(m.id)) {
        const { res, rej } = this.cbs.get(m.id); this.cbs.delete(m.id);
        m.error ? rej(new Error(JSON.stringify(m.error))) : res(m.result);
      } else if (m.method) { this.handlers.forEach((h) => h(m)); }
    };
  }
  send(method, params = {}) {
    const id = ++this.id;
    return new Promise((res, rej) => { this.cbs.set(id, { res, rej }); this.ws.send(JSON.stringify({ id, method, params })); });
  }
  onceEvent(method, timeout = 12000) {
    return new Promise((res) => {
      const to = setTimeout(() => { this.handlers = this.handlers.filter((x) => x !== h); res(null); }, timeout);
      const h = (m) => { if (m.method === method) { clearTimeout(to); this.handlers = this.handlers.filter((y) => y !== h); res(m.params); } };
      this.handlers.push(h);
    });
  }
  eval(expr) {
    return this.send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true })
      .then((r) => {
        if (r.exceptionDetails) {
          const msg = r.exceptionDetails.exception?.description || r.exceptionDetails.text || 'page eval threw';
          throw new Error(msg);
        }
        return r.result && r.result.value;
      });
  }
  async until(expr, timeout = 18000) {
    return waitFor(() => this.eval(`(()=>{try{return !!(${expr})}catch(e){return false}})()`).catch(() => false), { tries: Math.ceil(timeout / 250), gap: 250 });
  }
}

// Start a local PHP dev server unless one is already serving `base`.
// Returns the spawned child (so the caller can kill it) or null if reused.
export async function startDevServer({ root, base, port, log = () => {} }) {
  if (await reachable(base + '/')) { log('using already-running server at ' + base); return null; }
  log(`starting PHP dev server on :${port}`);
  const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', 'public', 'public/router.php'], { cwd: root, stdio: 'ignore' });
  if (!(await waitFor(() => reachable(base + '/')))) {
    try { server.kill('SIGTERM'); } catch { /* ignore */ }
    throw new Error('dev server did not come up at ' + base);
  }
  return server;
}

// Mint a one-shot magic-link token and exchange it for JWTs (non-destructive).
export async function mintLogin({ root, base, email, log = () => {} }) {
  log(`minting login token for ${email}`);
  const out = execFileSync('php', ['scripts/login-link.php', email, '1'], { cwd: root, encoding: 'utf8' });
  const token = (out.match(/token=([A-Za-z0-9]+)/) || [])[1];
  if (!token) throw new Error('could not parse a token from login-link.php output:\n' + out);
  const verify = await (await fetch(base + '/api/auth/verify', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ token }),
  })).json();
  if (!verify.access_token) throw new Error('verify did not return an access token: ' + JSON.stringify(verify));
  log(`authenticated as ${verify.user?.email} (${verify.user?.role})`);
  return verify;
}

// Launch headless Chromium and open a CDP session. Returns { cdp, chrome, ws, close }.
export async function launchBrowser({ cdpPort, scale = 1, width = 1440, height = 900, log = () => {} }) {
  const bin = findChromium();
  const udd = mkdtempSync(path.join(tmpdir(), 'pb-cdp-'));
  const chrome = spawn(bin, [
    '--headless=new', '--disable-gpu', '--no-sandbox', '--hide-scrollbars',
    `--remote-debugging-port=${cdpPort}`, '--remote-debugging-address=127.0.0.1',
    `--user-data-dir=${udd}`, `--window-size=${width},${height}`, 'about:blank',
  ], { stdio: 'ignore' });

  let wsUrl = null;
  await waitFor(async () => {
    try {
      const list = await (await fetch(`http://127.0.0.1:${cdpPort}/json/list`)).json();
      const page = list.find((t) => t.type === 'page');
      if (page?.webSocketDebuggerUrl) { wsUrl = page.webSocketDebuggerUrl; return true; }
    } catch { /* not ready */ }
    return false;
  });
  if (!wsUrl) { try { chrome.kill('SIGTERM'); } catch { /* ignore */ } throw new Error('Chromium DevTools endpoint never came up'); }

  const ws = new WebSocket(wsUrl);
  await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });
  const cdp = new CDP(ws);
  await cdp.send('Page.enable');
  await cdp.send('Runtime.enable');
  await cdp.send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: scale, mobile: false });

  const close = () => { try { ws.close(); } catch { /* ignore */ } try { chrome.kill('SIGTERM'); } catch { /* ignore */ } };
  return { cdp, chrome, ws, close };
}

// Seed JWTs into localStorage on the app origin so the SPA loads authenticated.
export async function seedAuth(cdp, base, auth) {
  await cdp.send('Page.navigate', { url: base + '/login.html' });
  await cdp.onceEvent('Page.loadEventFired');
  await cdp.eval(`localStorage.setItem('backstage_access_token', ${JSON.stringify(auth.access_token)});localStorage.setItem('backstage_refresh_token', ${JSON.stringify(auth.refresh_token || '')});`);
}

// High-level page object for tests: thin, intention-revealing wrappers over the
// CDP eval primitive. Selectors are embedded with JSON.stringify so quotes in
// attribute selectors (e.g. [data-form="tier"]) never need manual escaping.
export function makePage(cdp, base) {
  const q = (sel) => JSON.stringify(sel);
  const page = {
    base,
    cdp,
    eventId: null,
    hasEvent: false,
    eval: (expr) => cdp.eval(expr),
    until: (expr, timeout) => cdp.until(expr, timeout),

    async goto(hash) { await cdp.eval(`location.hash=${JSON.stringify(hash)}`); await sleep(300); },
    async navigate(url) { await cdp.send('Page.navigate', { url }); await cdp.onceEvent('Page.loadEventFired'); },

    exists: (sel) => cdp.eval(`!!document.querySelector(${q(sel)})`),
    count: (sel) => cdp.eval(`document.querySelectorAll(${q(sel)}).length`),
    // Rendered (display:none / [hidden] → not visible). Offscreen still counts as visible.
    visible: (sel) => cdp.eval(`(()=>{const e=document.querySelector(${q(sel)});return !!e&&e.getClientRects().length>0})()`),
    text: (sel) => cdp.eval(`(()=>{const e=document.querySelector(${q(sel)});return e?e.textContent.trim():null})()`),
    attr: (sel, name) => cdp.eval(`(()=>{const e=document.querySelector(${q(sel)});return e?e.getAttribute(${JSON.stringify(name)}):null})()`),

    async click(sel) {
      const ok = await cdp.eval(`(()=>{const e=document.querySelector(${q(sel)});if(!e)return false;e.click();return true})()`);
      if (!ok) throw new Error('click: element not found: ' + sel);
      await sleep(140);
    },
    async setValue(sel, val) {
      const ok = await cdp.eval(`(()=>{const e=document.querySelector(${q(sel)});if(!e)return false;e.value=${JSON.stringify(String(val))};e.dispatchEvent(new Event('input',{bubbles:true}));e.dispatchEvent(new Event('change',{bubbles:true}));return true})()`);
      if (!ok) throw new Error('setValue: element not found: ' + sel);
      await sleep(140);
    },
    // Check a radio/checkbox and fire `change` (reactive UI, no form submit).
    async selectRadio(sel) {
      const ok = await cdp.eval(`(()=>{const e=document.querySelector(${q(sel)});if(!e)return false;e.checked=true;e.dispatchEvent(new Event('change',{bubbles:true}));return true})()`);
      if (!ok) throw new Error('selectRadio: element not found: ' + sel);
      await sleep(160);
    },

    async waitWorkspace() {
      const ok = await cdp.until(`document.querySelector('pb-event-workspace .workspace-tabs')`);
      if (!ok) throw new Error('event workspace did not mount');
      await sleep(450);
    },
    async openEvent(id) {
      await page.goto('#event-' + (id ?? page.eventId));
      await page.waitWorkspace();
    },

    // Mark the current test skipped (e.g. required fixture data is absent).
    skip(reason) { const e = new Error(reason || 'skipped'); e.skip = true; throw e; },
  };
  return page;
}
