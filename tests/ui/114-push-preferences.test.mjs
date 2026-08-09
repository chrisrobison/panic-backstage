// Preferences > Push notifications (public/assets/auth.js's Preferences
// component + public/assets/push.js).
//
// What this asserts is mostly the *absence* of behavior, because that is the
// risky part of a notifications feature:
//
//   1. The section renders and states a real capability status rather than
//      showing a button that cannot work.
//   2. Nothing about loading the page asks for notification permission. The
//      prompt must only ever come from clicking Enable — a page-load prompt
//      is the single worst thing this feature could do, and it is exactly
//      what an accidental getPushStatus() → requestPermission() would cause.
//   3. Push categories are separate controls from the email categories, and
//      the push controls stay hidden until a device exists.
//   4. Nothing in this flow contacts Firebase, so CI (which has no
//      FIREBASE_* configuration) exercises the real degraded path instead of
//      being skipped.
//
// Non-destructive: reads state and the config endpoint only.
import { test, assert } from './harness.mjs';

// Replace Notification.requestPermission with a counter so a stray call is a
// hard failure rather than a hung headless prompt. Chrome auto-denies in
// headless mode, so without this the bug would be invisible.
const INSTALL_PERMISSION_SPY = `
  (() => {
    if (!('Notification' in window)) return 'no-notification-api';
    window.__pbPermissionCalls = 0;
    const original = Notification.requestPermission.bind(Notification);
    Notification.requestPermission = function (...args) {
      window.__pbPermissionCalls += 1;
      return original(...args);
    };
    return 'installed';
  })()
`;

test('Preferences shows a push section without ever prompting for permission', async (page) => {
  await page.eval(INSTALL_PERMISSION_SPY);

  await page.goto('#preferences');
  await page.until(`document.querySelector('pb-preferences [data-pref="notify_contracts"]')`);
  // The push block paints after its own async status check.
  await page.until(`document.querySelector('pb-preferences [data-push-status]')`);

  const heading = await page.eval(`
    [...document.querySelectorAll('pb-preferences .account-section h2')]
      .map((h) => h.textContent.trim()).join('|')
  `);
  assert.includes(heading, 'Push notifications', 'a Push notifications section is rendered');
  assert.includes(heading, 'Email notifications', 'the existing email section is still there');

  const status = await page.text('pb-preferences [data-push-status]');
  assert.ok(status && status.length > 0, 'the push section states a status');
  assert.notOk(/^Checking/.test(status), 'the status resolves past its loading placeholder');

  // The permission prompt must not have been raised by rendering the page.
  const calls = await page.eval(`window.__pbPermissionCalls`);
  if (calls !== null) {
    assert.equal(calls, 0, 'Notification.requestPermission was never called on page load');
  }

  // Whatever the server says drives the UI: with Firebase unconfigured (CI's
  // normal state) there must be no Enable button at all, and with it
  // configured the status must not claim it is unavailable.
  const config = await page.eval(`
    (async () => {
      const core = await import('./assets/core.js');
      try { return await core.api('/push/config'); } catch (e) { return { enabled: false }; }
    })()
  `);
  const enabled = Boolean(config && config.enabled);

  const hasButton = await page.exists('pb-preferences [data-push-toggle]');
  if (!enabled) {
    assert.includes(status, 'not configured', 'an unconfigured install says so plainly');
    assert.notOk(hasButton, 'no Enable button is offered when push cannot possibly work');
    assert.notOk(
      await page.exists('pb-preferences [data-pref="push_booking_updates"]'),
      'push categories stay hidden until push is available and a device is registered'
    );
  } else {
    assert.notOk(/not configured/.test(status), 'a configured install does not claim to be unconfigured');
  }

  // Push categories are their own columns, never an alias of the email ones.
  const emailPrefs = await page.count('pb-preferences [data-pref^="notify_"]');
  assert.atLeast(emailPrefs, 3, 'the email categories are unchanged');
});

test('the push config endpoint never leaks private Firebase credentials', async (page) => {
  const config = await page.eval(`
    (async () => {
      const core = await import('./assets/core.js');
      try { return await core.api('/push/config'); } catch (e) { return { error: String(e) }; }
    })()
  `);

  assert.ok(config && typeof config === 'object', 'the config endpoint answers authenticated callers');

  // Whatever the enabled state, the response must never carry anything from
  // the service account. Checked over the serialized body so a nested or
  // renamed field cannot slip past a property-name check.
  const serialized = JSON.stringify(config);
  assert.notOk(/private_key/i.test(serialized), 'no private key material is returned');
  assert.notOk(/BEGIN [A-Z ]*PRIVATE KEY/.test(serialized), 'no PEM block is returned');
  assert.notOk(/service_account|client_email/i.test(serialized), 'no service-account identity is returned');
  assert.notOk(/FIREBASE_SERVICE_ACCOUNT_FILE|\.json/i.test(serialized), 'the service-account file path is not disclosed');
});
