#!/usr/bin/env node
// Regenerate the in-app help screenshots under public/assets/help/.
//
// Self-contained: starts a local PHP dev server if one isn't already running,
// mints a *non-destructive* magic-link token for an admin (via
// scripts/login-link.php — no password is set or changed), drives headless
// Chromium over the DevTools Protocol to log in and capture each screen, then
// cleans up. No npm dependencies — uses Node's built-in fetch + WebSocket
// (Node 21+/22+) and the system Chromium/Chrome.
//
// Usage:
//   node scripts/screenshots.mjs
//
// Env overrides (all optional):
//   SHOT_EMAIL        admin email to log in as        (default admin@mabuhay.local)
//   SHOT_EVENT_ID     event id for the workspace/ticketing shots (default 641027)
//   SHOT_CONTRACT_ID  contract id for the contract shot          (default 10)
//   SHOT_PORT         port for the dev server we start (default 8088)
//   SHOT_CDP_PORT     port for Chromium remote debugging         (default 9333)
//   SHOT_BASE         full app base URL; overrides PORT/base-path autodetect
//   SHOT_OUT          output directory (default public/assets/help)
//   SHOT_SCALE        device scale factor (default 1)
//
// The chosen event must exist and have in-house ticketing enabled for the
// ticketing panel to appear; the contract id must exist. Override the ids for
// your own data.

import { spawn, execFileSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const EMAIL = process.env.SHOT_EMAIL || 'admin@mabuhay.local';
const EVENT_ID = process.env.SHOT_EVENT_ID || '641027';
const CONTRACT_ID = process.env.SHOT_CONTRACT_ID || '10';
const PORT = Number(process.env.SHOT_PORT || 8088);
const CDP_PORT = Number(process.env.SHOT_CDP_PORT || 9333);
const OUT = path.resolve(ROOT, process.env.SHOT_OUT || 'public/assets/help');
const SCALE = Number(process.env.SHOT_SCALE || 1);

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const log = (...a) => console.log('[shots]', ...a);

// Read APP_BASE_PATH from .env so the URL matches how the app is mounted.
function readBasePath() {
  const file = path.join(ROOT, '.env');
  if (!existsSync(file)) return '';
  for (const line of readFileSync(file, 'utf8').split('\n')) {
    const m = line.match(/^\s*APP_BASE_PATH\s*=\s*(.+?)\s*$/);
    if (m) return m[1].replace(/^["']|["']$/g, '').replace(/\/$/, '');
  }
  return '';
}

const BASE = (process.env.SHOT_BASE || `http://127.0.0.1:${PORT}${readBasePath()}`).replace(/\/$/, '');

function findChromium() {
  for (const bin of ['chromium', 'chromium-browser', 'google-chrome', 'google-chrome-stable']) {
    try { execFileSync('bash', ['-lc', `command -v ${bin}`], { stdio: 'pipe' }); return bin; } catch { /* next */ }
  }
  throw new Error('No Chromium/Chrome binary found on PATH.');
}

async function reachable(url) {
  try { const r = await fetch(url, { redirect: 'manual' }); return r.status > 0; } catch { return false; }
}

async function waitFor(fn, { tries = 60, gap = 200 } = {}) {
  for (let i = 0; i < tries; i++) { if (await fn()) return true; await sleep(gap); }
  return false;
}

// --- minimal Chrome DevTools Protocol client over the built-in WebSocket ----
class CDP {
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
    return this.send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true }).then((r) => r.result && r.result.value);
  }
  async until(expr, timeout = 18000) {
    return waitFor(() => this.eval(`(()=>{try{return !!(${expr})}catch(e){return false}})()`), { tries: Math.ceil(timeout / 250), gap: 250 });
  }
}

async function main() {
  mkdirSync(OUT, { recursive: true });

  // 1) Ensure a dev server is up (start one if not).
  let server = null;
  if (!(await reachable(BASE + '/'))) {
    log(`starting PHP dev server on :${PORT}`);
    server = spawn('php', ['-S', `127.0.0.1:${PORT}`, '-t', 'public', 'public/router.php'], { cwd: ROOT, stdio: 'ignore' });
    if (!(await waitFor(() => reachable(BASE + '/')))) throw new Error('dev server did not come up at ' + BASE);
  } else {
    log('using already-running server at ' + BASE);
  }

  // 2) Mint a non-destructive magic-link token and exchange it for JWTs.
  log(`minting login token for ${EMAIL}`);
  const linkOut = execFileSync('php', ['scripts/login-link.php', EMAIL, '1'], { cwd: ROOT, encoding: 'utf8' });
  const token = (linkOut.match(/token=([A-Za-z0-9]+)/) || [])[1];
  if (!token) throw new Error('could not parse a token from login-link.php output:\n' + linkOut);
  const verify = await (await fetch(BASE + '/api/auth/verify', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ token }),
  })).json();
  if (!verify.access_token) throw new Error('verify did not return an access token: ' + JSON.stringify(verify));
  log(`authenticated as ${verify.user?.email} (${verify.user?.role})`);

  // 3) Launch headless Chromium and connect.
  const bin = findChromium();
  const udd = mkdtempSync(path.join(tmpdir(), 'shots-cdp-'));
  const chrome = spawn(bin, [
    '--headless=new', '--disable-gpu', '--no-sandbox', '--hide-scrollbars',
    `--remote-debugging-port=${CDP_PORT}`, '--remote-debugging-address=127.0.0.1',
    `--user-data-dir=${udd}`, '--window-size=1440,900', 'about:blank',
  ], { stdio: 'ignore' });

  let wsUrl = null;
  await waitFor(async () => {
    try {
      const list = await (await fetch(`http://127.0.0.1:${CDP_PORT}/json/list`)).json();
      const page = list.find((t) => t.type === 'page');
      if (page?.webSocketDebuggerUrl) { wsUrl = page.webSocketDebuggerUrl; return true; }
    } catch { /* not ready */ }
    return false;
  });
  if (!wsUrl) throw new Error('Chromium DevTools endpoint never came up');

  const ws = new WebSocket(wsUrl);
  await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });
  const cdp = new CDP(ws);
  await cdp.send('Page.enable');
  await cdp.send('Runtime.enable');
  await cdp.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: SCALE, mobile: false });

  const shoot = async (name) => {
    await cdp.eval(`document.querySelectorAll('[data-credential-setup-modal],.modal-backdrop,dialog[open]').forEach(e=>e.remove())`);
    await sleep(150);
    const img = await cdp.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
    writeFileSync(path.join(OUT, name + '.png'), Buffer.from(img.data, 'base64'));
    log('saved', name + '.png');
  };
  const gotoHash = async (hash) => { await cdp.eval(`location.hash=${JSON.stringify(hash)}`); await sleep(450); };

  // 4) Seed auth on the same origin, then capture each screen.
  await cdp.send('Page.navigate', { url: BASE + '/login.html' });
  await cdp.onceEvent('Page.loadEventFired');
  await cdp.eval(`localStorage.setItem('backstage_access_token', ${JSON.stringify(verify.access_token)});localStorage.setItem('backstage_refresh_token', ${JSON.stringify(verify.refresh_token || '')});`);

  await cdp.send('Page.navigate', { url: BASE + '/#dashboard' });
  await cdp.onceEvent('Page.loadEventFired');
  await cdp.until(`document.querySelector('pb-dashboard') && document.querySelector('pb-dashboard').children.length>0 && document.querySelector('.side-nav')`);
  await sleep(1500);
  await shoot('dashboard');

  await gotoHash(`#event-${EVENT_ID}`);
  await cdp.until(`document.querySelector('pb-event-workspace .workspace-tabs')`);
  await sleep(1600); await cdp.eval(`window.scrollTo(0,0)`); await sleep(300);
  await shoot('event');

  if (await cdp.until(`document.querySelector('#ticketing') && document.querySelector('#ticketing').children.length>0`, 8000)) {
    await cdp.eval(`(document.querySelector('#ticketing')||document.body).scrollIntoView({block:'start'});window.scrollBy(0,-8);`);
    await sleep(900);
    await shoot('ticketing');
  } else {
    log('WARN: #ticketing panel not present on event ' + EVENT_ID + ' (in-house ticketing off?) — skipping ticketing.png');
  }

  await gotoHash(`#contract-${CONTRACT_ID}`);
  if (await cdp.until(`document.querySelector('pb-contract-editor') && document.querySelector('pb-contract-editor').children.length>0`)) {
    await sleep(1800); await cdp.eval(`window.scrollTo(0,0)`); await sleep(300);
    await shoot('contract');
  } else {
    log('WARN: contract ' + CONTRACT_ID + ' did not load — skipping contract.png');
  }

  // 5) Clean up.
  try { ws.close(); } catch { /* ignore */ }
  try { chrome.kill('SIGTERM'); } catch { /* ignore */ }
  if (server) { try { server.kill('SIGTERM'); } catch { /* ignore */ } }
  log('done →', OUT);
  process.exit(0);
}

main().catch((e) => { console.error('[shots] ERROR', e); process.exit(1); });
