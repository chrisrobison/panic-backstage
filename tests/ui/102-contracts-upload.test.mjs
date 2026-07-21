// Contracts tab: the Create Contract modal now clearly separates "Generate
// contract" (boilerplate deal-builder) from "Upload contract" (attach a
// signed file you already have), and Upload mode supports picking a file
// right there — it uploads immediately as a 'contract'-tagged asset, attaches
// it to a new contract row, confirms, and closes the modal, with no separate
// Save click. Uploaded contracts are then clickable in the list to view the
// file in-app (image lightbox / embedded PDF) instead of only a "View file"
// link that left the app.
//
// Destructive against a throwaway contract + its underlying uploaded asset
// only, cleaned up via direct API calls in `finally` (same convention as
// 97-nav-manager.test.mjs) so cleanup still happens even if an assertion
// above it fails.
import { test, assert } from './harness.mjs';
import { writeFileSync } from 'node:fs';
import path from 'node:path';
import { tmpdir } from 'node:os';

// A real, tiny, valid PNG (not just a renamed text file) — Events/Assets.php
// sniffs actual file bytes via mime_content_type(), it doesn't trust the
// filename, so the upload fixture has to be genuine image bytes.
const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
const FIXTURE = path.join(tmpdir(), 'pb-ui-test-contract-upload.png');
writeFileSync(FIXTURE, Buffer.from(PNG_BASE64, 'base64'));

const TITLE = 'PB UI TEST uploaded contract (safe to delete)';
const MODAL = '.modal-backdrop:has([data-form="new"])';

async function apiFetch(page, apiPath, opts = {}) {
  const token = await page.eval("localStorage.getItem('backstage_access_token')");
  const res = await fetch(page.base + '/api' + apiPath, {
    ...opts,
    headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token, ...(opts.headers || {}) },
  });
  const text = await res.text();
  let body = null;
  try { body = text ? JSON.parse(text) : null; } catch { /* non-JSON (e.g. 204) */ }
  if (!res.ok) throw new Error(`${opts.method || 'GET'} ${apiPath} -> ${res.status}: ${text}`);
  return body;
}

async function openCreateModal(page) {
  await page.openEvent();
  if (!(await page.exists('#contracts [data-add]'))) return false;
  await page.click('.workspace-tabs a[data-tab="contracts"]');
  await page.until(`document.querySelector('#contracts [data-add]')`);
  await page.click('#contracts [data-add]');
  await page.until(`document.querySelector('${MODAL}')`);
  return true;
}

test('Create Contract modal clearly separates Generate vs. Upload', async (page) => {
  if (!page.hasEvent) return page.skip(`event ${page.eventId} not found`);
  if (!(await openCreateModal(page))) return page.skip('no manage_contracts capability for this user');

  const toggleText = await page.text(`${MODAL} .contract-mode-toggle`);
  assert.includes(toggleText, 'Generate contract', 'Generate option is clearly labeled');
  assert.includes(toggleText, 'Upload contract', 'Upload option is clearly labeled');
  assert.ok(await page.visible(`${MODAL} [data-generate-fields]`), 'deal-builder fields shown by default');
  assert.notOk(await page.visible(`${MODAL} [data-asset-fields]`), 'upload fields hidden by default');

  await page.selectRadio(`${MODAL} [data-uploaded-toggle]`);
  assert.notOk(await page.visible(`${MODAL} [data-generate-fields]`), 'deal-builder fields hidden in Upload mode');
  assert.ok(await page.visible(`${MODAL} [data-asset-fields]`), 'upload fields shown in Upload mode');

  await page.click(`${MODAL} [data-close]`);
  assert.notOk(await page.exists(MODAL), 'modal closes via Close');
});

// Preflight: this runner's dev server (`php -S`, run as the local unix user)
// writes uploaded files under public/uploads/events/{id}, which on a
// checkout of the live site is www-data-owned with no group-write bit — only
// the real Apache/php-fpm process (which runs as www-data) can write there.
// That's a host/environment fact independent of this feature: the
// pre-existing, untouched Assets-tab "Add asset" form 500s the exact same
// way here (confirmed by hand). Rather than let that show up as a flaky
// "modal never closed" failure, upload a real throwaway file directly first
// (cleaning it up immediately) to know up front whether this host can write
// there at all, and skip with a clear reason if not.
async function canUploadOnThisHost(page) {
  const token = await page.eval("localStorage.getItem('backstage_access_token')");
  const body = new FormData();
  body.set('asset', new Blob([Buffer.from(PNG_BASE64, 'base64')], { type: 'image/png' }), 'preflight.png');
  body.set('asset_type', 'other');
  body.set('title', 'PB UI TEST upload-preflight (safe to delete)');
  const res = await fetch(`${page.base}/api/events/${page.eventId}/assets`, {
    method: 'POST', body, headers: { Authorization: 'Bearer ' + token },
  });
  if (!res.ok) return false;
  const { id } = await res.json();
  await apiFetch(page, `/events/${page.eventId}/assets/${id}`, { method: 'DELETE' }).catch(() => {});
  return true;
}

test('Choosing a file in Upload mode uploads it, attaches it, and auto-closes the modal', async (page) => {
  if (!page.hasEvent) return page.skip(`event ${page.eventId} not found`);
  if (!(await canUploadOnThisHost(page))) {
    return page.skip("this host's dev server can't write to public/uploads/events/* (www-data-owned, confirmed against the pre-existing, unrelated Assets-tab upload form too) — not caused by this feature, see comment above canUploadOnThisHost");
  }
  if (!(await openCreateModal(page))) return page.skip('no manage_contracts capability for this user');

  await page.selectRadio(`${MODAL} [data-uploaded-toggle]`);
  await page.until(`document.querySelector('${MODAL} [data-asset-fields]')?.hidden === false`);
  if (!(await page.exists(`${MODAL} [data-upload-input]`))) return page.skip('this user lacks upload_assets — direct-upload control correctly hidden, nothing more to check here');

  await page.setValue(`${MODAL} input[name="title"]`, TITLE);
  await page.setFiles(`${MODAL} [data-upload-input]`, [FIXTURE]);

  const closed = await page.until(`!document.querySelector('${MODAL}')`, 15000);
  assert.ok(closed, 'modal auto-closes once the upload + attach succeed — no separate Save click needed');
  await page.until(`document.querySelector('.contracts-table')?.textContent.includes(${JSON.stringify(TITLE)})`);

  let contractId = null;
  let assetId = null;
  try {
    const list = await apiFetch(page, `/events/${page.eventId}/contracts`);
    const created = (list.contracts || []).find((c) => c.title === TITLE);
    assert.ok(created, 'the uploaded contract is persisted');
    assert.equal(created.provider, 'manual_upload', 'recorded as a manual_upload contract');
    assert.ok(created.asset_id, 'linked to a real uploaded asset row');
    contractId = created.id;
    assetId = created.asset_id;

    assert.ok(await page.exists(`tr[data-view-uploaded="${contractId}"]`), 'uploaded contract renders as a row in the list');
    assert.equal(await page.count(`tr[data-view-uploaded="${contractId}"] a`), 0, 'no more raw "View file" link — replaced by the in-app viewer button');

    await page.click(`tr[data-view-uploaded="${contractId}"] [data-view]`);
    const opened = await page.until(`document.querySelector('.lightbox-backdrop img.lightbox-img')`);
    assert.ok(opened, 'clicking View opens the image lightbox in-app for the uploaded PNG (not a new tab)');
    await page.eval(`document.querySelector('.lightbox-backdrop')?.click()`);
    assert.notOk(await page.exists('.lightbox-backdrop'), 'lightbox closes on backdrop click');
  } finally {
    if (contractId) await apiFetch(page, `/contracts/${contractId}`, { method: 'DELETE' }).catch(() => {});
    if (assetId) await apiFetch(page, `/events/${page.eventId}/assets/${assetId}`, { method: 'DELETE' }).catch(() => {});
  }
});
