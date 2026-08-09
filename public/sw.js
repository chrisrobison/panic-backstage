// Backstage push service worker.
//
// Deliberately a plain, standards-based worker: it handles `push` and
// `notificationclick` itself and imports nothing. The Firebase SDK is used
// only in the page (public/assets/push.js) to obtain a registration token —
// putting it in here as well would mean either importScripts()ing the compat
// bundle or introducing a bundler, and this app has no build step by design.
//
// That works because FCM's web transport IS the standard Web Push protocol:
// a message sent through FCM HTTP v1 arrives here as an ordinary `push`
// event whose payload is FCM's JSON envelope ({ notification, data, ... }).
// We read `data` — which src/Notifications/PushMessage.php fills with the
// title, body and Backstage deep link — and fall back to `notification` if a
// message ever arrives without it.
//
// This worker intentionally does NOT cache anything. Backstage is a live
// operations tool served from one origin; an offline cache here would only
// create stale-asset bugs. Its whole job is notifications.

const FALLBACK_TITLE = 'Panic Backstage';

// Take over immediately so a freshly-registered worker can receive a push and
// (importantly for notificationclick) call client.navigate() on already-open
// tabs without waiting for them to be closed and reopened.
self.addEventListener('install', (event) => {
  event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

function parsePayload(event) {
  if (!event.data) return {};
  try {
    return event.data.json() || {};
  } catch (err) {
    // A non-JSON payload should still surface something rather than throw.
    try {
      return { notification: { body: event.data.text() } };
    } catch (innerErr) {
      return {};
    }
  }
}

// Normalize FCM's envelope into the flat shape the notification needs.
function toNotification(payload) {
  const data = payload.data || {};
  const note = payload.notification || {};
  return {
    title: data.title || note.title || FALLBACK_TITLE,
    body: data.body || note.body || '',
    // Everything a click needs travels in `data`, so notificationclick below
    // never has to know that "a contract was signed" means "#contract-456".
    url: data.url || '',
    tag: data.dedupe_key || data.url || 'backstage',
    category: data.category || '',
    entityType: data.entity_type || '',
    entityId: data.entity_id || '',
    eventId: data.event_id || '',
  };
}

self.addEventListener('push', (event) => {
  const payload = parsePayload(event);
  const notification = toNotification(payload);

  event.waitUntil((async () => {
    const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    const visible = clients.filter((client) => client.visibilityState === 'visible');

    if (visible.length > 0) {
      // The app is on screen. Hand it to the page so it can raise an in-app
      // toast, and show NO system notification — one event must not produce
      // both a banner and a toast. Suppressing the notification is allowed
      // here specifically because a visible window exists; browsers only
      // substitute their own "site updated in the background" notice when a
      // push is handled with nothing visible to the user.
      visible.forEach((client) => client.postMessage({ type: 'push:foreground', notification }));
      return;
    }

    await self.registration.showNotification(notification.title, {
      body: notification.body,
      icon: './assets/favicon/android-chrome-192x192.png',
      badge: './assets/favicon/favicon-32x32.png',
      // Re-notifying with the same tag REPLACES the earlier banner, so a
      // device that was offline through several updates to one inquiry shows
      // the current state rather than a stack of stale alerts. Matches the
      // collapse topic FcmClient sends for the same dedupe key.
      tag: notification.tag,
      renotify: true,
      data: notification,
    });
  })());
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  const data = event.notification.data || {};
  const scope = new URL(self.registration.scope);
  // `url` is a Backstage route hash ('#inbox-mine', '#event-123'), resolved
  // against the worker's own scope so this works unchanged under APP_BASE_PATH.
  const target = new URL(data.url || './', scope).href;

  event.waitUntil((async () => {
    const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    const existing = clients.find((client) => client.url.startsWith(scope.href)
      || client.url.startsWith(scope.href.replace(/\/$/, '')));

    if (existing) {
      // Reuse the window the operator already has open — focusing and
      // deep-linking beats spawning a second copy of the app.
      await existing.focus();
      try {
        await existing.navigate(target);
      } catch (err) {
        // navigate() is unavailable for uncontrolled clients (and rejects on
        // some browsers); the page applies the hash itself instead.
        existing.postMessage({ type: 'push:navigate', url: data.url || '' });
      }
      return;
    }

    await self.clients.openWindow(target);
  })());
});
