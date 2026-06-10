#!/usr/bin/env node
// Regenerate the in-app help screenshots under public/assets/help/.
//
// Self-contained: starts a local PHP dev server if one isn't already running,
// mints a *non-destructive* magic-link token for an admin (no password is set
// or changed), drives headless Chromium over the DevTools Protocol to log in
// and capture each screen, then cleans up. No npm dependencies — the browser /
// CDP / login machinery is the shared kit in tests/ui/browser.mjs.
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

import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { sleep, readBasePath, startDevServer, mintLogin, launchBrowser, seedAuth } from '../tests/ui/browser.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const EMAIL = process.env.SHOT_EMAIL || 'admin@mabuhay.local';
const EVENT_ID = process.env.SHOT_EVENT_ID || '641027';
const CONTRACT_ID = process.env.SHOT_CONTRACT_ID || '10';
const PORT = Number(process.env.SHOT_PORT || 8088);
const CDP_PORT = Number(process.env.SHOT_CDP_PORT || 9333);
const OUT = path.resolve(ROOT, process.env.SHOT_OUT || 'public/assets/help');
const SCALE = Number(process.env.SHOT_SCALE || 1);
const BASE = (process.env.SHOT_BASE || `http://127.0.0.1:${PORT}${readBasePath(ROOT)}`).replace(/\/$/, '');

const log = (...a) => console.log('[shots]', ...a);

async function main() {
  mkdirSync(OUT, { recursive: true });

  const server = await startDevServer({ root: ROOT, base: BASE, port: PORT, log });
  const auth = await mintLogin({ root: ROOT, base: BASE, email: EMAIL, log });
  const browser = await launchBrowser({ cdpPort: CDP_PORT, scale: SCALE, log });
  const { cdp } = browser;

  const shoot = async (name) => {
    await cdp.eval(`document.querySelectorAll('[data-credential-setup-modal],.modal-backdrop,dialog[open]').forEach(e=>e.remove())`);
    await sleep(150);
    const img = await cdp.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
    writeFileSync(path.join(OUT, name + '.png'), Buffer.from(img.data, 'base64'));
    log('saved', name + '.png');
  };
  const gotoHash = async (hash) => { await cdp.eval(`location.hash=${JSON.stringify(hash)}`); await sleep(450); };

  try {
    await seedAuth(cdp, BASE, auth);

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
  } finally {
    browser.close();
    if (server) { try { server.kill('SIGTERM'); } catch { /* ignore */ } }
  }
  log('done →', OUT);
  process.exit(0);
}

main().catch((e) => { console.error('[shots] ERROR', e); process.exit(1); });
