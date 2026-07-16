#!/usr/bin/env node
// Zero-dependency UI test runner for Panic Backstage.
//
// Starts a local PHP dev server (if one isn't already running), logs in via a
// non-destructive magic-link token, drives headless Chromium over the DevTools
// Protocol, then runs every tests/ui/*.test.mjs case against the live DOM and
// reports pass / fail / skip. Exit code is non-zero if any test fails.
//
// Usage:
//   node tests/ui/run.mjs
//
// Env overrides (all optional):
//   UI_EMAIL      admin email to log in as              (default admin@mabuhay.local)
//   UI_EVENT_ID   event id used by workspace/ticketing tests (default 641027)
//   UI_PORT       port for the dev server we start       (default 8099)
//   UI_CDP_PORT   Chromium remote-debugging port         (default 9344)
//   UI_BASE       full app base URL; overrides PORT/base-path autodetect
//
// The tests are non-destructive: they assert client-side behaviour (form
// reveals, mode toggles, computed values) without persisting changes.

import { readdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { startDevServer, mintLogin, launchBrowser, seedAuth, makePage, readBasePath } from './browser.mjs';
import { registeredTests } from './harness.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..', '..');

const EMAIL = process.env.UI_EMAIL || 'admin@mabuhay.local';
const EVENT_ID = process.env.UI_EVENT_ID || '641027';
const PORT = Number(process.env.UI_PORT || 8099);
const CDP_PORT = Number(process.env.UI_CDP_PORT || 9344);
const BASE = (process.env.UI_BASE || `http://127.0.0.1:${PORT}${readBasePath(ROOT)}`).replace(/\/$/, '');

const log = (...a) => console.log('[ui]', ...a);
const GREEN = '\x1b[32m'; const RED = '\x1b[31m'; const YEL = '\x1b[33m'; const DIM = '\x1b[2m'; const OFF = '\x1b[0m';

async function eventExists(token, id) {
  try {
    const r = await fetch(`${BASE}/api/events/${encodeURIComponent(id)}`, { headers: { Authorization: 'Bearer ' + token } });
    return r.status === 200;
  } catch { return false; }
}

async function main() {
  const server = await startDevServer({ root: ROOT, base: BASE, port: PORT, log });
  const auth = await mintLogin({ root: ROOT, base: BASE, email: EMAIL, log });
  const browser = await launchBrowser({ cdpPort: CDP_PORT, log });

  let passed = 0; let failed = 0; let skipped = 0;
  try {
    await seedAuth(browser.cdp, BASE, auth);
    await browser.cdp.send('Page.navigate', { url: BASE + '/#dashboard' });
    await browser.cdp.onceEvent('Page.loadEventFired');
    // "#dashboard" now mounts the Upcoming-events view (pb-events-upcoming) —
    // see public/assets/app.js's route() — the old metrics/cards view
    // (pb-dashboard) lives on at "#dashboard-metrics".
    const booted = await browser.cdp.until(`document.querySelector('pb-events-upcoming') && document.querySelector('pb-events-upcoming').children.length>0 && document.querySelector('.side-nav')`);
    if (!booted) throw new Error('app did not boot (pb-events-upcoming / .side-nav never appeared)');

    const page = makePage(browser.cdp, BASE);
    page.eventId = EVENT_ID;
    page.hasEvent = await eventExists(auth.access_token, EVENT_ID);
    if (!page.hasEvent) log(`${YEL}WARN${OFF} event ${EVENT_ID} not found — event-dependent tests will skip (set UI_EVENT_ID)`);

    const files = readdirSync(HERE).filter((f) => f.endsWith('.test.mjs')).sort();
    for (const f of files) { await import(pathToFileURL(path.join(HERE, f)).href); }

    const tests = registeredTests();
    log(`running ${tests.length} test(s) from ${files.length} file(s)\n`);
    for (const t of tests) {
      try {
        await t.fn(page);
        console.log(`  ${GREEN}✓${OFF} ${t.name}`);
        passed++;
      } catch (err) {
        if (err && err.skip) {
          console.log(`  ${YEL}•${OFF} ${t.name} ${DIM}(skipped: ${err.message})${OFF}`);
          skipped++;
        } else {
          const detail = String(err && err.message || err).split('\n').join('\n      ');
          console.log(`  ${RED}✗ ${t.name}${OFF}\n      ${RED}${detail}${OFF}`);
          failed++;
        }
      }
    }
  } finally {
    browser.close();
    if (server) { try { server.kill('SIGTERM'); } catch { /* ignore */ } }
  }

  const tone = failed ? RED : GREEN;
  console.log(`\n[ui] ${tone}${passed} passed, ${failed} failed, ${skipped} skipped${OFF}`);
  process.exit(failed ? 1 : 0);
}

main().catch((e) => { console.error('[ui] ERROR', e); process.exit(1); });
