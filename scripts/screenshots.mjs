#!/usr/bin/env node
// Regenerate the in-app help screenshots under public/assets/help/:
// dashboard, contacts, listmaster, event, ticketing, contract, leads,
// promote, automation, tasks-app, ai-assistant, admin-users,
// admin-navigation, vendors, execution.
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
const WIDTH = 1440;
const HEIGHT = 900;
const BASE = (process.env.SHOT_BASE || `http://127.0.0.1:${PORT}${readBasePath(ROOT)}`).replace(/\/$/, '');

const log = (...a) => console.log('[shots]', ...a);

// Shared fake-person pool for redacting real customer PII out of any screenshot
// that shows a table of contacts (contacts.png, listmaster.png). Real
// names/emails/phones must never end up in a committed help screenshot —
// aggregate KPIs and anonymous stats are fine to leave as-is.
const FAKE_PEOPLE = ['Jordan Rivera', 'Sam Okafor', 'Alex Chen', 'Priya Nair', 'Mateo Cruz', 'Dana Brooks', 'Noah Kim', 'Lena Petrova', 'Omar Haddad', 'Grace Lin', 'Theo Walsh', 'Ruby Santos', 'Ivan Petrov', 'Maya Johnson', 'Eli Foster'];

async function main() {
  mkdirSync(OUT, { recursive: true });

  const server = await startDevServer({ root: ROOT, base: BASE, port: PORT, log });
  const auth = await mintLogin({ root: ROOT, base: BASE, email: EMAIL, log });
  const browser = await launchBrowser({ cdpPort: CDP_PORT, scale: SCALE, width: WIDTH, height: HEIGHT, log });
  const { cdp } = browser;

  const shoot = async (name) => {
    await cdp.eval(`document.querySelectorAll('[data-credential-setup-modal],.modal-backdrop,dialog[open]').forEach(e=>e.remove())`);
    await sleep(150);
    const img = await cdp.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
    writeFileSync(path.join(OUT, name + '.png'), Buffer.from(img.data, 'base64'));
    log('saved', name + '.png');
  };
  const gotoHash = async (hash) => { await cdp.eval(`location.hash=${JSON.stringify(hash)}`); await sleep(450); };

  // Swap real people's name/email/phone/initials for fakes across a set of
  // table rows before capture — shared by every shot that renders a table of
  // real users (contacts, listmaster, leads, admin-users). `cols` is a list
  // of [cellSelector, kind] pairs evaluated per row, kind one of
  // name|email|phone|initials.
  const redactPeople = async (rowSelector, cols) => cdp.eval(`(()=>{
    const F=${JSON.stringify(FAKE_PEOPLE)};
    document.querySelectorAll(${JSON.stringify(rowSelector)}).forEach((tr,i)=>{
      const name=F[i%F.length], [f,l]=name.split(' ');
      ${JSON.stringify(cols)}.forEach(([sel,kind])=>{
        const el=sel?tr.querySelector(sel):tr;
        if(!el) return;
        if(kind==='name') el.textContent=name;
        else if(kind==='email') el.textContent=f.toLowerCase()+'.'+l.toLowerCase()+'@example.com';
        else if(kind==='phone') el.textContent='(415) 555-0'+String(100+i).slice(-3);
        else if(kind==='initials') el.textContent=(f[0]+l[0]).toUpperCase();
      });
    });
  })()`);

  try {
    await seedAuth(cdp, BASE, auth);
    // Pre-accept the cookie/preference consent banner (public/assets/consent.js
    // renders it on first visit, covering the bottom of the viewport) so it
    // never appears in a help screenshot. "essential" is enough — these shots
    // don't depend on any preference-storage behavior.
    await cdp.eval(`try { localStorage.setItem('pb.cookieConsent', 'essential'); } catch (e) {}`);

    // Dashboard nav now lands on the "Upcoming" card view (pb-events-upcoming)
    // — the old counters/cards dashboard (pb-dashboard) moved to
    // #dashboard-metrics, linked from Reports. This shot documents what a
    // user actually sees first today.
    await cdp.send('Page.navigate', { url: BASE + '/#dashboard' });
    await cdp.onceEvent('Page.loadEventFired');
    await cdp.until(`document.querySelector('pb-events-upcoming .upcoming-page') && document.querySelector('.side-nav')`);
    await sleep(1500);
    await shoot('dashboard');

    await gotoHash('#contacts');
    if (await cdp.until(`document.querySelector('pb-contacts-page .contacts-table tbody tr')`, 8000)) {
      // Redact real customer PII before capture — the help screenshot must not
      // ship real names/emails/phones. Aggregate KPIs + anonymous stats stay.
      await redactPeople('.contacts-table tbody tr', [
        ['[data-label="Name"] strong', 'name'],
        ['[data-label="Email"]', 'email'],
        ['[data-label="Phone"]', 'phone'],
      ]);
      await sleep(400); await cdp.eval(`window.scrollTo(0,0)`); await sleep(200);
      await shoot('contacts');
    } else {
      log('WARN: contacts page did not load — skipping contacts.png');
    }

    await gotoHash('#listmaster');
    if (await cdp.until(`document.querySelector('pb-listmaster') && document.querySelector('.lm-table tbody tr')`, 8000)) {
      // Same PII redaction as the contacts shot, adapted to ListMaster's table
      // markup (name lives in a .lm-row-name span alongside an avatar, email is
      // its own plain <td>).
      await redactPeople('.lm-table tbody tr', [
        ['.lm-row-name span:not(.lm-avatar)', 'name'],
        ['.lm-avatar', 'initials'],
        ['td:nth-child(3)', 'email'],
      ]);
      await sleep(400); await cdp.eval(`window.scrollTo(0,0)`); await sleep(200);
      await shoot('listmaster');
    } else {
      log('WARN: ListMaster member table did not load — skipping listmaster.png');
    }

    await gotoHash(`#event-${EVENT_ID}`);
    await cdp.until(`document.querySelector('pb-event-workspace .workspace-tabs')`);
    await sleep(1600); await cdp.eval(`window.scrollTo(0,0)`); await sleep(300);
    await shoot('event');

    // The workspace switched from scroll-to-anchor sections to a real tab
    // system a while back (see event-workspace.js setActiveTab/_applySectionVisibility)
    // — every section is mounted but hidden (display:none) except the active
    // tab, so #ticketing exists in the DOM at 0 height until its tab is
    // actually clicked. Scrolling to it (the old approach) silently did
    // nothing once that landed.
    if (await cdp.until(`document.querySelector('.workspace-tabs [data-tab="ticketing"]')`, 8000)) {
      await cdp.eval(`document.querySelector('.workspace-tabs [data-tab="ticketing"]').click()`);
      await cdp.until(`document.querySelector('#ticketing') && document.querySelector('#ticketing').children.length>0 && getComputedStyle(document.querySelector('#ticketing')).display!=='none'`, 8000);
      await sleep(900); await cdp.eval(`window.scrollTo(0,0)`); await sleep(200);
      await shoot('ticketing');
    } else {
      log('WARN: Ticketing tab not present on event ' + EVENT_ID + ' (in-house ticketing off?) — skipping ticketing.png');
    }

    await gotoHash(`#contract-${CONTRACT_ID}`);
    if (await cdp.until(`document.querySelector('pb-contract-editor') && document.querySelector('pb-contract-editor').children.length>0`)) {
      await sleep(1800); await cdp.eval(`window.scrollTo(0,0)`); await sleep(300);
      await shoot('contract');
    } else {
      log('WARN: contract ' + CONTRACT_ID + ' did not load — skipping contract.png');
    }

    // ── Booking Inbox ────────────────────────────────────────────────────
    // inbox-shell.js bootstrap() auto-opens the first lead into the
    // workspace/detail panes on desktop widths ("select the first one so
    // the workspace isn't empty") — which leaks real customer PII
    // (name/email/org, plus an AI-extracted contact name) into surfaces the
    // list-row redaction below never touches. It deliberately skips that
    // auto-open under its own mobile breakpoint (860px), so mount narrow
    // to get the no-lead-selected empty state for free, then widen back out
    // for the actual capture — .ib-body-list-active (set regardless of
    // width) only has any effect under that same breakpoint, so widening
    // after mount is a no-op for it.
    await cdp.send('Emulation.setDeviceMetricsOverride', { width: 820, height: HEIGHT, deviceScaleFactor: SCALE, mobile: false });
    await gotoHash('#inbox-unassigned');
    if (await cdp.until(`document.querySelector('pb-inbox-app .ib-list .ib-list-item')`, 8000)) {
      await sleep(300);
      await cdp.send('Emulation.setDeviceMetricsOverride', { width: WIDTH, height: HEIGHT, deviceScaleFactor: SCALE, mobile: false });
      await sleep(300);
      // Belt-and-suspenders: confirm nothing got auto-selected before we
      // trust the redaction-only list to be enough.
      const leadOpen = await cdp.eval(`!!document.querySelector('.ib-list-item.active')`);
      if (leadOpen) {
        log('WARN: a lead was auto-selected despite the narrow-mount workaround — skipping leads.png to avoid leaking PII');
      } else {
        await redactPeople('.ib-list-item', [
          ['.ib-list-item-name', 'name'],
          ['.ib-avatar', 'initials'],
        ]);
        await sleep(200);
        await shoot('leads');
      }
    } else {
      log('WARN: Booking Inbox list did not load — skipping leads.png');
    }

    // ── Promote ──────────────────────────────────────────────────────────
    await gotoHash(`#promote-event-${EVENT_ID}`);
    if (await cdp.until(`document.querySelector('pb-promote-campaign-overview .promote-overview-layout')`, 8000)) {
      await sleep(900); await cdp.eval(`window.scrollTo(0,0)`); await sleep(200);
      await shoot('promote');
    } else {
      log('WARN: Promote overview did not load for event ' + EVENT_ID + ' — skipping promote.png');
    }

    // ── Automation ───────────────────────────────────────────────────────
    await gotoHash('#automation-processes');
    if (await cdp.until(`document.querySelector('pb-processes-list .data-table tbody tr') || document.querySelector('pb-processes-list .empty-state')`, 8000)) {
      await sleep(600);
      await shoot('automation');
    } else {
      log('WARN: Automation process list did not load — skipping automation.png');
    }

    // ── Tasks app ────────────────────────────────────────────────────────
    await gotoHash('#tasks');
    if (await cdp.until(`document.querySelector('pb-tasks-app .tk-body .tk-doc-nav')`, 8000)) {
      await sleep(900);
      await shoot('tasks-app');
    } else {
      log('WARN: Tasks app did not load — skipping tasks-app.png');
    }

    // ── AI Assistant ─────────────────────────────────────────────────────
    // Topbar-triggered drawer, not a hash route — open it from wherever we
    // currently are, capture, then close so it doesn't linger into whatever
    // shot runs next.
    if (await cdp.until(`document.querySelector('[data-action="ai-drawer-toggle"]')`, 4000)) {
      await cdp.eval(`document.querySelector('[data-action="ai-drawer-toggle"]').click()`);
      if (await cdp.until(`document.querySelector('pb-ai-drawer.ai-drawer-open') && document.querySelector('.ai-drawer-transcript')`, 8000)) {
        await sleep(500);
        await shoot('ai-assistant');
        await cdp.eval(`document.querySelector('[data-ai-close]')?.click()`);
        await sleep(300);
      } else {
        log('WARN: AI drawer did not open — skipping ai-assistant.png');
      }
    } else {
      log('WARN: AI Assistant topbar button not present (capability off for this user?) — skipping ai-assistant.png');
    }

    // ── Admin: Users ─────────────────────────────────────────────────────
    await gotoHash('#admin-users');
    if (await cdp.until(`document.querySelector('pb-admin-users .admin-table tbody tr')`, 8000)) {
      // Redact real staff name/email — same treatment as customer PII above,
      // covers both the main accounts table and the "Pending access
      // requests" table (identical column order: name, email, ...).
      await redactPeople('.admin-table tbody tr', [
        ['td:nth-child(1)', 'name'],
        ['td:nth-child(2)', 'email'],
      ]);
      await sleep(300);
      await shoot('admin-users');
    } else {
      log('WARN: Admin > Users table did not load — skipping admin-users.png');
    }

    // ── Admin: Navigation manager ────────────────────────────────────────
    await gotoHash('#admin-navigation');
    if (await cdp.until(`document.querySelector('.nav-manager-list') && document.querySelector('.nav-manager-preview .side-nav a')`, 8000)) {
      await sleep(600);
      await shoot('admin-navigation');
    } else {
      log('WARN: Admin > Navigation manager did not load — skipping admin-navigation.png');
    }

    // ── Event workspace tabs: Vendors & Execution ───────────────────────
    await gotoHash(`#event-${EVENT_ID}`);
    await cdp.until(`document.querySelector('pb-event-workspace .workspace-tabs')`);
    await sleep(1200);

    if (await cdp.until(`document.querySelector('.workspace-tabs [data-tab="vendors"]')`, 8000)) {
      await cdp.eval(`document.querySelector('.workspace-tabs [data-tab="vendors"]').click()`);
      await cdp.until(`document.querySelector('#vendors') && document.querySelector('#vendors').children.length>0 && getComputedStyle(document.querySelector('#vendors')).display!=='none'`, 8000);
      await sleep(700); await cdp.eval(`window.scrollTo(0,0)`); await sleep(200);
      await shoot('vendors');
    } else {
      log('WARN: Vendors tab not present on event ' + EVENT_ID + ' — skipping vendors.png');
    }

    if (await cdp.until(`document.querySelector('.workspace-tabs [data-tab="execution"]')`, 8000)) {
      await cdp.eval(`document.querySelector('.workspace-tabs [data-tab="execution"]').click()`);
      await cdp.until(`document.querySelector('#execution') && document.querySelector('#execution').children.length>0 && getComputedStyle(document.querySelector('#execution')).display!=='none'`, 8000);
      await sleep(700); await cdp.eval(`window.scrollTo(0,0)`); await sleep(200);
      await shoot('execution');
    } else {
      log('WARN: Execution tab not present on event ' + EVENT_ID + ' — skipping execution.png');
    }
  } finally {
    browser.close();
    if (server) { try { server.kill('SIGTERM'); } catch { /* ignore */ } }
  }
  log('done →', OUT);
  process.exit(0);
}

main().catch((e) => { console.error('[shots] ERROR', e); process.exit(1); });
