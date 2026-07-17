// Admin > App Settings (src/AppSettings.php + public/assets/admin.js's
// AdminAppSettings, "pb-admin-app-settings"). What this feature claims:
//   1. The form round-trips real data through the API — save persists, a
//      hard reload shows the saved value (not just in-memory state), and
//      the app shell's sidebar/topbar brand + browser tab title actually
//      come from it, not the hardcoded "Panic Backstage" default.
//
// AppShell also applies a save live (no reload) via an `app-settings.updated`
// pub/sub event — real, and worth having for the UX, but not asserted here:
// the bus adapter in core.js buffers subscribe()/publish() calls until a
// `pan:sys.ready` event (or a 3s fallback) fires, and this test's hash-only
// navigation + immediate save can easily land inside that first-3-seconds
// window right after the harness's initial page load, racing the fallback
// in a way a real user (who takes longer than 3s to reach this page) never
// would. The hard-reload assertion below is the reliable way to prove the
// same thing without racing that timer.
//
// Destructive against the real brand_name value only, restored via a direct
// API call in `finally` (same convention as 97-nav-manager.test.mjs) so a
// failed assertion above it doesn't leave the live site's brand changed.
import { test, assert } from './harness.mjs';

async function hardReload(page, hash) {
  if (hash) await page.eval(`location.hash = ${JSON.stringify(hash)}`);
  await page.cdp.send('Page.reload', { ignoreCache: true });
  await page.cdp.onceEvent('Page.loadEventFired');
}

async function apiFetch(page, path, opts = {}) {
  const token = await page.eval("localStorage.getItem('backstage_access_token')");
  const res = await fetch(page.base + '/api' + path, {
    ...opts,
    headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token, ...(opts.headers || {}) },
  });
  const text = await res.text();
  let body = null;
  try { body = text ? JSON.parse(text) : null; } catch { /* non-JSON */ }
  if (!res.ok) throw new Error(`${opts.method || 'GET'} ${path} -> ${res.status}: ${text}`);
  return body;
}

test('Admin > App Settings shows real data and saves changes that survive a reload', async (page) => {
  const before = await apiFetch(page, '/app-settings');
  const throwaway = 'PB UI TEST brand (safe to delete)';

  try {
    await page.goto('#admin-settings');
    await page.until(`document.querySelector('input[name="brand_name"]')`);

    // Form reflects the real API data, not placeholder/blank state.
    assert.equal(await page.eval(`document.querySelector('input[name="brand_name"]').value`), before.settings.brand_name || '', 'brand_name field matches GET /api/app-settings');
    assert.equal(await page.eval(`document.querySelector('input[name="manager_name"]').value`), before.env.manager_name || '', 'manager_name field matches GET /api/app-settings');

    // Change brand_name and save through the real form/button.
    await page.setValue('input[name="brand_name"]', throwaway);
    await page.click('.as-save');
    assert.ok(await page.until(`document.querySelector('.toast-stack .toast')?.textContent.includes('saved')`), 'save shows a confirmation toast');

    // Hard reload proves it's real persisted state, not just in-memory —
    // and exercises the same applyBrand() code path the live-update
    // subscription uses, just via the reliable "fresh page load" trigger
    // instead of the racy pub/sub one (see file header).
    await hardReload(page, '#admin-settings');
    await page.until(`document.querySelector('input[name="brand_name"]')`);
    assert.equal(await page.eval(`document.querySelector('input[name="brand_name"]').value`), throwaway, 'saved brand_name survives a full reload');
    assert.equal(await page.text('.brand span:last-child'), throwaway, 'sidebar brand reflects the persisted value after reload too');
    assert.equal(await page.text('.mobile-brand span:last-child'), throwaway, 'topbar mobile-brand reflects it too');
    assert.equal(await page.eval('document.title'), throwaway, 'browser tab title reflects it too');
  } finally {
    await apiFetch(page, '/app-settings', {
      method: 'PUT',
      body: JSON.stringify({ settings: before.settings, env: {} }),
    });
  }
});

test('GET /api/app-settings response never includes a secret env key', async (page) => {
  // The write side is allow-listed to a fixed set of venue-contact fields
  // (src/AppSettings.php::ENV_KEYS) and the manage_settings capability gate
  // on PUT is covered directly against the running app in the manual
  // verification for this feature (403 for a non-venue_admin role, 401
  // unauthenticated) — this harness's one session is always venue_admin, so
  // there's no second role here to re-assert that against. What's worth
  // pinning as a regression test is the response shape itself: no matter
  // what ENV_KEYS grows to include later, it must never echo back a secret.
  const data = await apiFetch(page, '/app-settings');
  assert.ok('settings' in data && 'env' in data, 'GET returns both settings and env sections');
  const envKeys = Object.keys(data.env);
  const secretish = envKeys.filter((k) => /pass|secret|token|key/i.test(k));
  assert.equal(secretish.length, 0, `env fields look secret-free (got: ${envKeys.join(', ')})`);
});
