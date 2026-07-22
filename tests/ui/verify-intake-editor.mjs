// Standalone verification for the inline-edit feature on
// docs/event-intake-status.html. NOT part of the tests/ui/*.test.mjs suite
// picked up by run.mjs (deliberately, see below) — run by hand:
//
//   node tests/ui/verify-intake-editor.mjs
//
// Creates one throwaway event, edits several fields through the real page
// UI (real click -> contenteditable -> blur -> PATCH round trip), asserts
// the DB actually changed via a fresh API read, then deletes the event.
// Safe against the shared prod DB per the repo's testing convention
// (throwaway title prefix, cleanup in a finally block).
//
// Why standalone instead of a `NN-*.test.mjs` file: the rest of the suite
// shares one dev server rooted at `public/` (the SPA's docroot). This page
// lives at `docs/`, one level up, which that server can't serve — so this
// script spins up its own repo-root-docroot dev server with a tiny router
// that mimics just enough of the production .htaccess (static files as-is,
// `/api/*` -> `public/api/index.php`) for `docs/*.html` to work standalone.
import { spawn } from 'node:child_process';
import { writeFileSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { mintLogin, launchBrowser, seedAuth, sleep, reachable, waitFor } from './browser.mjs';

const root = '/home/cdr/backstage';
const port = 8199;
const base = `http://127.0.0.1:${port}`;
const log = (...a) => console.log('[verify]', ...a);

let server, browser, eventId, routerDir;

const ROUTER_SRC = (repoRoot) => `<?php
declare(strict_types=1);
const REPO_ROOT = ${JSON.stringify(repoRoot)};
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (str_starts_with($path, '/api/')) {
    require REPO_ROOT . '/public/api/index.php';
    return true;
}
$file = REPO_ROOT . $path;
if ($path !== '/' && is_file($file)) {
    return false; // let the built-in server serve it as a static file
}
require REPO_ROOT . '/public/index.html';
`;

async function startDocsDevServer() {
  if (await reachable(base + '/docs/event-intake-status.html')) { log('using already-running server at ' + base); return null; }
  routerDir = mkdtempSync(path.join(tmpdir(), 'pb-docs-router-'));
  const routerFile = path.join(routerDir, 'router.php');
  writeFileSync(routerFile, ROUTER_SRC(root));
  log(`starting repo-root PHP dev server on :${port} (for docs/ + api/)`);
  const proc = spawn('php', ['-S', `127.0.0.1:${port}`, routerFile], { cwd: root, stdio: 'ignore' });
  if (!(await waitFor(() => reachable(base + '/docs/event-intake-status.html')))) {
    try { proc.kill('SIGTERM'); } catch { /* ignore */ }
    throw new Error('docs dev server did not come up at ' + base);
  }
  return proc;
}

async function main() {
  server = await startDocsDevServer();
  const auth = await mintLogin({ root, base, email: 'admin@mabuhay.local', log });
  const authHeader = { Authorization: 'Bearer ' + auth.access_token, 'Content-Type': 'application/json' };

  const date = new Date(Date.now() + 5 * 86400000).toISOString().slice(0, 10);
  const createRes = await fetch(base + '/api/events', {
    method: 'POST', headers: authHeader,
    body: JSON.stringify({
      title: 'PB UI TEST — Inline Edit (safe to delete)',
      date, event_type: 'live_music', venue_id: 1,
      doors_time: '19:00', end_time: '23:00',
      status: 'proposed',
    }),
  });
  const created = await createRes.json();
  if (!created.id) throw new Error('event create failed: ' + JSON.stringify(created));
  eventId = created.id;
  log('created test event', eventId, 'on', date);

  const before = await (await fetch(base + `/api/events/${eventId}`, { headers: authHeader })).json();
  const beforeEvent = before.event;
  log('before: owner_user_id=', beforeEvent.owner_user_id, 'public_visibility=', beforeEvent.public_visibility, 'status=', beforeEvent.status);

  browser = await launchBrowser({ cdpPort: 9377, log });
  const { cdp } = browser;
  // Headless Chrome under CDP doesn't grant the page real OS/window focus by
  // default, so contenteditable's .focus()/.blur() (which the inline editor
  // depends on) silently no-ops without this — a real user's browser tab
  // always has focus, so this only matters for automated verification.
  await cdp.send('Emulation.setFocusEmulationEnabled', { enabled: true });
  await seedAuth(cdp, base, auth);
  await cdp.send('Page.navigate', { url: base + '/docs/event-intake-status.html' });
  await cdp.onceEvent('Page.loadEventFired');

  const gotRows = await cdp.until(`document.querySelectorAll('tr[data-idx]').length > 0`, 15000);
  if (!gotRows) throw new Error('grid never populated with live data — status banner: ' + await cdp.eval(`document.getElementById('status-banner').textContent`));
  log('live grid loaded,', await cdp.eval(`document.querySelectorAll('tr[data-idx]').length`), 'rows');

  await cdp.eval(`(() => {
    const input = document.getElementById('search');
    input.value = 'PB UI TEST — Inline Edit';
    input.dispatchEvent(new Event('input', { bubbles: true }));
  })()`);
  await sleep(200);
  const visibleRows = await cdp.eval(`document.querySelectorAll('#grid-body tr[data-idx]').length`);
  log('rows after search filter:', visibleRows);
  if (visibleRows !== 1) throw new Error(`expected exactly 1 filtered row, got ${visibleRows}`);

  await cdp.eval(`document.querySelector('#grid-body tr[data-idx]').click()`);
  await sleep(150);
  const expanded = await cdp.eval(`document.querySelector('#grid-body tr.detail-row')?.classList.contains('show')`);
  if (!expanded) throw new Error('detail row did not expand on click');

  async function editField(field, value) {
    const ok = await cdp.eval(`(async () => {
      const span = document.querySelector('[data-field="${field}"]');
      if (!span) return 'NOT_FOUND';
      span.click();
      if (!span.classList.contains('editing')) return 'DID_NOT_ENTER_EDIT_MODE';
      span.textContent = ${JSON.stringify(value)};
      span.blur();
      return 'OK';
    })()`);
    if (ok !== 'OK') throw new Error(`editField(${field}) failed: ${ok}`);
    await sleep(700); // let the PATCH round-trip resolve, even under load from the background contract-status fetch
  }

  await editField('booker_name', 'Test Booker');
  await editField('booker_email', 'test-booker@example.com');
  await editField('booker_phone', '415-555-0100');
  await editField('deposit_amount', '250');

  // Revert-on-invalid-input path for a time field.
  const invalidResult = await cdp.eval(`(async () => {
    const span = document.querySelector('[data-field="doors_time"]');
    const original = span.textContent;
    span.click();
    span.textContent = 'not a time';
    span.blur();
    await new Promise(r => setTimeout(r, 300));
    return { hasError: span.classList.contains('save-error'), text: span.textContent, original };
  })()`);
  log('invalid time input result:', invalidResult);
  if (!invalidResult.hasError) throw new Error('invalid time input did not flash save-error');
  if (invalidResult.text !== invalidResult.original) throw new Error('invalid time input was not reverted to original text');

  // The DOM/bulb refresh after a save can lag briefly under concurrent load
  // (this dev harness also has ~58 background contract-status requests in
  // flight) — poll for it rather than a single point-in-time read.
  const bookerBulbOn = await cdp.until(`document.querySelector('tr[data-idx] td:nth-child(2) .bulb')?.classList.contains('on')`, 5000);
  log('booker bulb now on:', bookerBulbOn);
  if (!bookerBulbOn) log('WARNING: booker bulb still not green after 5s — investigate if this recurs');

  const after = await (await fetch(base + `/api/events/${eventId}`, { headers: authHeader })).json();
  const afterEvent = after.event;
  log('after: booker_name=', afterEvent.booker_name, 'booker_email=', afterEvent.booker_email, 'booker_phone=', afterEvent.booker_phone, 'deposit_amount=', afterEvent.deposit_amount);
  log('after: owner_user_id=', afterEvent.owner_user_id, 'public_visibility=', afterEvent.public_visibility, 'status=', afterEvent.status, 'title=', afterEvent.title, 'doors_time=', afterEvent.doors_time);

  const checks = [
    ['booker_name', afterEvent.booker_name === 'Test Booker'],
    ['booker_email', afterEvent.booker_email === 'test-booker@example.com'],
    ['booker_phone', afterEvent.booker_phone === '415-555-0100'],
    ['deposit_amount', Number(afterEvent.deposit_amount) === 250],
    ['doors_time survived the invalid edit unchanged', afterEvent.doors_time === beforeEvent.doors_time],
    ['owner_user_id unchanged (not nulled by the safety-bundle)', afterEvent.owner_user_id === beforeEvent.owner_user_id],
    ['public_visibility unchanged', Number(afterEvent.public_visibility) === Number(beforeEvent.public_visibility)],
    ['status unchanged', afterEvent.status === beforeEvent.status],
    ['title unchanged', afterEvent.title === beforeEvent.title],
  ];
  let failed = false;
  for (const [label, pass] of checks) {
    console.log(pass ? `  PASS: ${label}` : `  FAIL: ${label}`);
    if (!pass) failed = true;
  }
  if (failed) throw new Error('one or more assertions failed — see FAIL lines above');

  console.log('\nALL CHECKS PASSED');
}

main()
  .catch((err) => { console.error('VERIFY FAILED:', err); process.exitCode = 1; })
  .finally(async () => {
    try { if (browser) browser.close(); } catch { /* ignore */ }
    if (eventId) {
      try {
        const auth = await mintLogin({ root, base, email: 'admin@mabuhay.local', log });
        await fetch(base + `/api/events/${eventId}`, { method: 'DELETE', headers: { Authorization: 'Bearer ' + auth.access_token } });
        log('deleted test event', eventId);
      } catch (e) { console.error('cleanup failed, delete manually:', eventId, e); }
    }
    try { if (server) server.kill('SIGTERM'); } catch { /* ignore */ }
    try { if (routerDir) rmSync(routerDir, { recursive: true, force: true }); } catch { /* ignore */ }
  });
