// Firebase Cloud Messaging web push for Backstage.
//
// Short and self-contained by design: no build step, no npm, no bundler. The
// Firebase browser SDK is loaded as a pinned ES module from Google's own CDN,
// and only ever lazily — nothing here touches the network, and nothing here
// can raise a permission prompt, until the user clicks "Enable notifications".
//
// The service worker (public/sw.js) is a plain standards-based worker that
// handles `push` itself, so the SDK is needed in the page for exactly one
// thing: turning a browser's Web Push subscription into an FCM registration
// token. We register the worker ourselves and hand the registration to
// getToken() rather than letting Firebase look for /firebase-messaging-sw.js
// at the domain root — that default lookup breaks under APP_BASE_PATH, where
// Backstage lives at e.g. /backstage/.
//
// Public surface: isPushSupported(), getPushStatus(), enablePush(),
// disablePush(), listDevices(), forgetDevice(), initPushBridge().

import { api, appUrl, publish } from './core.js';

// Pinned. Bump deliberately, never with a floating range: this URL is loaded
// straight into users' browsers with no lockfile to pin it for us.
const FIREBASE_VERSION = '12.17.1';
const FIREBASE_APP_URL = `https://www.gstatic.com/firebasejs/${FIREBASE_VERSION}/firebase-app.js`;
const FIREBASE_MESSAGING_URL = `https://www.gstatic.com/firebasejs/${FIREBASE_VERSION}/firebase-messaging.js`;

// Remembering the token locally is what lets this browser say "notifications
// are on for THIS device" and unregister itself later. It is an FCM address,
// not a Backstage credential — it grants no access to anything.
const TOKEN_KEY = 'backstage_push_token';

let _configPromise = null;
let _messaging = null;
let _bridged = false;

// ── Capability detection ─────────────────────────────────────────────────────

/**
 * Whether this browser can do web push at all. Feature detection only — no
 * user-agent sniffing, which ages badly and gets iOS wrong in both directions.
 */
function isPushSupported() {
  return typeof window !== 'undefined'
    && window.isSecureContext === true
    && 'serviceWorker' in navigator
    && 'PushManager' in window
    && 'Notification' in window;
}

/**
 * iPhone/iPad only expose web push to a Home Screen installation. Detected by
 * the shape of the platform rather than by name: `navigator.standalone` is an
 * iOS-only property, and it is `false` in the browser tab and `true` once
 * installed. So "the property exists, it is false, and Notification is
 * missing" is precisely the add-to-Home-Screen case — no UA string needed.
 */
function requiresHomeScreenInstall() {
  return typeof window !== 'undefined'
    && 'serviceWorker' in navigator
    && !('Notification' in window)
    && 'standalone' in navigator
    && navigator.standalone === false;
}

/** True when running as an installed app (iOS Home Screen or a PWA window). */
function isInstalledApp() {
  if (typeof window === 'undefined') return false;
  return navigator.standalone === true
    || Boolean(window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);
}

// ── Server configuration ─────────────────────────────────────────────────────

// Cached per page load. `enabled: false` (Firebase unconfigured) is a normal
// answer, not an error — the whole feature is optional.
function pushConfig() {
  if (!_configPromise) {
    _configPromise = api('/push/config').catch(() => ({ enabled: false }));
  }
  return _configPromise;
}

/**
 * Everything the Preferences UI needs to render one honest line of status.
 *
 * Cheap and side-effect free: it never loads the Firebase SDK, never contacts
 * FCM, and above all never calls Notification.requestPermission(). Safe to
 * call on render.
 *
 * @returns {Promise<{state: string, supported: boolean, configured: boolean,
 *                    permission: string, installed: boolean}>}
 *   state is one of: 'unconfigured' | 'unsupported' | 'needs-install' |
 *                    'denied' | 'enabled' | 'available'
 */
async function getPushStatus() {
  const config = await pushConfig();
  const configured = Boolean(config && config.enabled);
  const supported = isPushSupported();
  const permission = supported ? Notification.permission : 'default';

  let state;
  if (!configured) state = 'unconfigured';
  else if (requiresHomeScreenInstall()) state = 'needs-install';
  else if (!supported) state = 'unsupported';
  else if (permission === 'denied') state = 'denied';
  else if (permission === 'granted' && localStorage.getItem(TOKEN_KEY)) state = 'enabled';
  else state = 'available';

  return { state, supported, configured, permission, installed: isInstalledApp() };
}

// ── Firebase plumbing ────────────────────────────────────────────────────────

/**
 * Register public/sw.js at the application's own scope.
 *
 * Explicit URL and scope, both derived from core.js's appUrl(), so an install
 * mounted under APP_BASE_PATH registers /backstage/sw.js with scope
 * /backstage/ instead of silently registering nothing useful at the root.
 */
async function registerServiceWorker() {
  const registration = await navigator.serviceWorker.register(appUrl('sw.js'), { scope: appUrl('') });
  // getToken() needs an ACTIVE worker; a brand-new registration may still be
  // installing on the very first enable.
  await navigator.serviceWorker.ready;
  return registration;
}

async function getMessagingInstance(config) {
  if (_messaging) return _messaging;

  const [{ initializeApp, getApps }, messagingModule] = await Promise.all([
    import(FIREBASE_APP_URL),
    import(FIREBASE_MESSAGING_URL),
  ]);
  const { getMessaging, isSupported } = messagingModule;

  if (!(await isSupported())) {
    throw new Error('This browser cannot receive push notifications.');
  }

  // Reuse an existing Firebase app if something else already initialized one.
  const app = getApps().length ? getApps()[0] : initializeApp(config.firebase);
  _messaging = { messaging: getMessaging(app), module: messagingModule };
  return _messaging;
}

/** A human label for the device list, best-effort and never a fingerprint. */
function deviceLabel() {
  const platform = navigator.userAgentData?.platform || navigator.platform || '';
  const brand = navigator.userAgentData?.brands?.find((b) => !/Not.?A.?Brand/i.test(b.brand))?.brand;
  const browser = brand || (navigator.vendor || '').replace(/ Inc\.?$/, '') || 'Browser';
  const suffix = isInstalledApp() ? ' (installed)' : '';
  return platform ? `${browser} on ${platform}${suffix}` : `${browser}${suffix}`;
}

// ── Enable / disable ─────────────────────────────────────────────────────────

/**
 * Turn on push for THIS device.
 *
 * MUST be called directly from a user gesture (the Enable button's click
 * handler) — that is both a browser requirement for a permission prompt to be
 * honored and a product rule: Backstage never asks for notification
 * permission on login or page load.
 *
 * Idempotent: re-running re-registers the same token, which the API upserts.
 */
async function enablePush() {
  const config = await pushConfig();
  if (!config.enabled) throw new Error('Push notifications are not configured for this installation.');
  if (requiresHomeScreenInstall()) {
    throw new Error('On iPhone and iPad, add Backstage to your Home Screen first, then enable notifications from the installed app.');
  }
  if (!isPushSupported()) throw new Error('This browser does not support push notifications.');
  if (Notification.permission === 'denied') {
    // Calling requestPermission() again here would resolve 'denied' instantly
    // without showing anything — the user has to undo it in browser/OS
    // settings, so say that instead of pretending to ask.
    throw new Error('Notifications are blocked for this site. Allow them in your browser or system settings, then try again.');
  }

  const registration = await registerServiceWorker();

  const permission = await Notification.requestPermission();
  if (permission !== 'granted') {
    throw new Error('Notification permission was not granted.');
  }

  const { messaging, module } = await getMessagingInstance(config);
  const token = await module.getToken(messaging, {
    vapidKey: config.vapid_key,
    serviceWorkerRegistration: registration,
  });
  if (!token) throw new Error('The browser did not return a push registration.');

  await api('/push/subscriptions', {
    method: 'POST',
    body: JSON.stringify({
      token,
      device_label: deviceLabel(),
      platform: navigator.userAgentData?.platform || navigator.platform || null,
    }),
  });

  localStorage.setItem(TOKEN_KEY, token);
  initPushBridge();
  return { ok: true };
}

/**
 * Turn off push for this device: revoke the FCM registration, then delete the
 * server row. Order matters — if deleteToken() fails we still want the server
 * to stop addressing a token the browser no longer honors, so the delete runs
 * either way.
 */
async function disablePush() {
  const token = localStorage.getItem(TOKEN_KEY);

  try {
    const config = await pushConfig();
    if (config.enabled && isPushSupported()) {
      const { messaging, module } = await getMessagingInstance(config);
      await module.deleteToken(messaging);
    }
  } catch (err) {
    // A token that is already gone is not a failure worth surfacing.
  }

  if (token) {
    await api('/push/subscriptions', { method: 'DELETE', body: JSON.stringify({ token }) });
  }
  localStorage.removeItem(TOKEN_KEY);
  _messaging = null;
  return { ok: true };
}

/** This user's registered devices. Never includes a registration token. */
async function listDevices() {
  const response = await api('/push/subscriptions');
  return response?.subscriptions || [];
}

/** Remove one device by id (used for "sign this other laptop out of push"). */
async function forgetDevice(id) {
  await api(`/push/subscriptions/${encodeURIComponent(id)}`, { method: 'DELETE' });
  return { ok: true };
}

// ── Foreground bridge ────────────────────────────────────────────────────────

/**
 * Connect the service worker to the app's existing PAN bus.
 *
 * Two messages come from public/sw.js:
 *   push:foreground — a push arrived while a Backstage window was visible.
 *     The worker deliberately showed NO system notification in that case, so
 *     this raises the app's own toast instead. One event, one alert.
 *   push:navigate — a notification was clicked and client.navigate() was not
 *     available, so the page applies the deep link itself.
 *
 * Note this is NOT Firebase's onMessage(): that only fires when the Firebase
 * SDK owns the service worker, and ours is a plain worker on purpose.
 *
 * Safe to call on every app boot — it registers listeners only, and prompts
 * for nothing.
 */
function initPushBridge() {
  if (_bridged || typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return;
  _bridged = true;

  navigator.serviceWorker.addEventListener('message', (event) => {
    const message = event.data || {};
    if (message.type === 'push:foreground') {
      const note = message.notification || {};
      publish('toast.show', {
        tone: 'info',
        message: note.body ? `${note.title}: ${note.body}` : (note.title || 'New activity'),
      });
      publish('push.received', note);
    } else if (message.type === 'push:navigate' && message.url) {
      location.hash = String(message.url).replace(/^#/, '');
    }
  });
}

export {
  isPushSupported,
  requiresHomeScreenInstall,
  isInstalledApp,
  getPushStatus,
  enablePush,
  disablePush,
  listDevices,
  forgetDevice,
  initPushBridge,
};
