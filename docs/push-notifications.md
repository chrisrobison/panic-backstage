# Push Notifications (Firebase Cloud Messaging)

Opt-in web push for signed-in Backstage staff, on desktop and mobile browsers.
It exists to shorten the gap between "a booking inquiry arrived" and "somebody
is looking at it" — not to mirror every email.

**The whole integration is optional.** With no `FIREBASE_*` configuration,
`/api/push/config` returns `enabled: false`, Preferences says push is
unavailable instead of offering a dead button, nothing is queued, and every
other part of the application behaves exactly as it did before this feature
existed. CI runs unconfigured on purpose.

---

## How it works

```text
business event  (new inquiry / assignment / contract signed)
      ↓
PushNotifier    picks recipients, applies push preferences
      ↓
JobQueue        durable background_jobs row (same queue as everything else)
      ↓
JobWorker       job_type = push_notification
      ↓
FcmClient       native PHP: RS256 JWT → OAuth2 token → FCM HTTP v1
      ↓
FCM
      ↓
public/sw.js    service worker: showNotification, or an in-app toast if a
                Backstage window is already visible
      ↓
notification click → focus the existing window → Backstage deep link
```

No Firebase Admin SDK, no Composer runtime package, no npm, no bundler, no
build step. The server side is cURL plus OpenSSL; the browser side is a pinned
ES module from Google's CDN loaded lazily, and a plain standards-based service
worker.

### Files

| File | Role |
| --- | --- |
| `src/Notifications/PushConfig.php` | Reads `FIREBASE_*`; single answer to "is push on?" |
| `src/Notifications/PushMessage.php` | Canonical, transport-agnostic notification (category/title/body/deep link/entity) |
| `src/Notifications/PushMessages.php` | Factory for the notifications we actually send — wording, links, dedupe keys |
| `src/Notifications/PushPreferences.php` | The `push_*` user columns (separate from the `notify_*` email columns) |
| `src/Notifications/PushSubscriptions.php` | `push_subscriptions` data access; all reads scoped by user id |
| `src/Notifications/PushNotifier.php` | Recipient/preference selection → `JobQueue`; worker-side delivery |
| `src/Notifications/FcmClient.php` | FCM HTTP v1 transport: JWT assertion, OAuth2, send, failure classification |
| `src/Push.php` | `/api/push/*` endpoints |
| `public/sw.js` | Service worker: `push` + `notificationclick` |
| `public/assets/push.js` | `isPushSupported()`, `getPushStatus()`, `enablePush()`, `disablePush()` |

Adding a category later (task assignments, day-of-show changes, unresolved
blockers) means a `PushPreferences` key, a method on `PushMessages`, and a
caller. It should never require touching `FcmClient`.

---

## Firebase Console setup

1. **Create or select a Firebase project** at <https://console.firebase.google.com>.
   An existing Google Cloud project can be added rather than creating a new one.
2. **Enable Cloud Messaging** — Project settings → **Cloud Messaging**. The
   modern **Firebase Cloud Messaging API (V1)** must be enabled; the legacy
   server-key API is *not* used and does not need to be enabled.
3. **Register the Backstage web app** — Project settings → **General** → *Your
   apps* → **Web** (`</>`). Give it a nickname (e.g. "Backstage"); you do not
   need Firebase Hosting. Copy the `projectId`, `apiKey`, `messagingSenderId`
   and `appId` from the config snippet it shows.
4. **Create Web Push (VAPID) credentials** — Project settings → **Cloud
   Messaging** → *Web configuration* → **Web Push certificates** → **Generate
   key pair**. Copy the **public** key. Only the public half is ever
   configured here or sent to a browser.
5. **Create a service account for sending** — Project settings → **Service
   accounts** → **Generate new private key**. This downloads a JSON file
   containing a private key. The account needs permission to send FCM messages
   (the default *Firebase Admin SDK* service account has it; a custom account
   needs `roles/firebasecloudmessaging.messages.create` or
   *Firebase Cloud Messaging API Admin*).
6. **Store the JSON outside the web root** and lock it down. Same handling as
   `GOOGLE_SA_KEY_FILE`:

   ```bash
   install -m 640 -o "$USER" -g www-data ~/Downloads/panic-backstage-*.json \
     /home/cdr/secrets/firebase-service-account.json
   ```

   Never commit it. Never place it under `public/`.
7. **Configure the environment** (`.env`):

   ```text
   FIREBASE_PUSH_ENABLED=1
   FIREBASE_PROJECT_ID=panic-backstage
   FIREBASE_WEB_API_KEY=AIza...
   FIREBASE_MESSAGING_SENDER_ID=123456789012
   FIREBASE_APP_ID=1:123456789012:web:abc123
   FIREBASE_VAPID_PUBLIC_KEY=BN...
   FIREBASE_SERVICE_ACCOUNT_FILE=/home/cdr/secrets/firebase-service-account.json
   ```

   `FIREBASE_PUSH_ENABLED` is an explicit opt-in switch: leaving it blank keeps
   push off even if the other values are filled in, so a half-finished rollout
   never starts prompting users.
8. **Run the database migration**:

   ```bash
   php scripts/migrate.php            # single-tenant
   php scripts/migrate.php tenants    # every SaaS tenant database
   ```

   Migration `094_add_push_notifications.sql` adds `push_subscriptions` and the
   four `push_*` preference columns on `users`.
9. **Run the background worker normally.** Push uses the existing queue —
   `scripts/cron-background-jobs.sh` / `scripts/run-background-jobs.php`. No new
   process, no new cron entry.

### Requirements and gotchas

- **HTTPS is mandatory.** Browsers refuse service workers and push over plain
  `http://` (bare `localhost` excepted). If `APP_URL` is not `https://`, push
  will not work no matter how the Firebase side is configured.
- **iPhone and iPad require a Home Screen installation.** iOS delivers web push
  only to an installed web app, never to a Safari tab. Users must open
  Backstage in Safari → Share → **Add to Home Screen**, then launch it from the
  new icon. Preferences detects this case by capability (not by sniffing the
  user agent) and explains it rather than showing an Enable button that would
  silently fail.
- **The web app manifest** is `public/assets/favicon/site.webmanifest`, linked
  from `public/index.html`. `start_url` and `scope` are relative (`../../`) so
  they resolve to the application root under any `APP_BASE_PATH`. `id` is
  deliberately omitted: it must be an absolute path, which a static file cannot
  know for an install mounted at an arbitrary base path, and browsers correctly
  default it to `start_url`.
- **Icons** reuse the existing brand assets (`android-chrome-192x192.png`,
  `android-chrome-512x512.png`, `apple-touch-icon.png`). There is no maskable
  variant; if you want a polished Android adaptive icon, supply a purpose-built
  `maskable` PNG with safe-zone padding and add it to the manifest — nothing
  else needs to change.

---

## Using it

A signed-in user goes to **Preferences → Push notifications** and clicks
**Enable notifications**. That click — and only that click — raises the browser
permission prompt. Backstage never calls `Notification.requestPermission()` on
login or page load.

The status line always states one true thing:

| Status | Meaning |
| --- | --- |
| Enabled on this device | Registered and receiving |
| Available but disabled on this device | Supported and configured; not turned on here |
| Notifications are blocked for this site | Permission was denied; must be changed in browser/OS settings (we do not re-prompt) |
| Add Backstage to your Home Screen first | iPhone/iPad, not yet installed |
| This browser does not support push notifications | No service worker / Push API / secure context |
| This Backstage installation is not configured for them | No `FIREBASE_*` configuration |

Once a device is registered, the four categories appear. They are **separate
from the email preferences** — agreeing to email about contracts is not consent
to be interrupted on a phone.

One user may register many devices; the device list lets any of them be
removed. Registering the same browser repeatedly is idempotent (the row is
keyed on a SHA-256 of the token).

### What gets pushed

Only high-signal operational events:

| Event | Title | Deep link |
| --- | --- | --- |
| New booking inquiry reaches the Booking Inbox | New booking inquiry | `#inbox-unassigned` |
| An inquiry is assigned/reassigned to you by somebody else | Booking inquiry assigned to you | `#inbox-mine` |
| A contract is signed / declined / fully executed | Contract signed (etc.) | `#contract-{id}` |

Routine event edits, status transitions, ordinary saves and marketing activity
are deliberately **not** pushed. A user is never notified about an action they
themselves just performed.

### Foreground behavior

If a Backstage window is visible when a push arrives, the service worker shows
**no** system notification and instead posts the message to the page, which
raises the app's normal toast. One event produces one alert, never both.
(Browsers only substitute their own "site updated in the background" notice
when a push is handled with nothing visible to the user, so suppressing it here
is safe.)

Clicking a notification focuses an already-open Backstage window and navigates
it to the deep link, opening a new window only if none exists. The destination
travels in the FCM `data` payload, so the service worker contains no per-entity
URL logic.

---

## Security notes

- Registration tokens are treated as sensitive application data: stored
  alongside a SHA-256 digest, never returned by any endpoint, never written to
  a log, and never included in an exception message (which matters because
  `JobQueue::fail()` persists exception text into `background_jobs.last_error`).
- The API never accepts a `user_id` from the browser. Every read and delete is
  filtered by the session's user id, so another user's registration is simply
  not found.
- `/api/push/config` returns only the public web config and the **public** VAPID
  key. The service-account path, its contents, and the OAuth access token never
  leave the server.
- Registrations live in the current tenant database like every other table, so a
  push cannot cross tenants. Broad FCM topics (`venue_admin`, etc.) are
  deliberately not used — every message is addressed to one registration token.
- The client-side `push.js` never sees a credential; the Firebase web config it
  receives is designed to be public.

## Failure handling

`FcmClient::classify()` decides what each FCM failure means:

| FCM response | Behavior |
| --- | --- |
| `UNREGISTERED`, 404, `SENDER_ID_MISMATCH` | The registration is dead — disable that one row, do not retry |
| `INVALID_ARGUMENT` blaming `message.token` | Malformed registration — disable that one row |
| `INVALID_ARGUMENT` blaming anything else | Our payload is wrong — sanitized error, job fails per queue policy |
| 429, `QUOTA_EXCEEDED`, `UNAVAILABLE`, `INTERNAL`, 5xx | Transient — throw, and the existing `JobQueue` backoff owns the retry |
| 401/403, `PERMISSION_DENIED` | Credential/config problem — sanitized error, cached OAuth token discarded |

A dead token therefore cleans itself up without any operator involvement. It is
*disabled*, not deleted, so the user can still see the device in Preferences and
simply re-enable it — re-registering reactivates the same row.

## Testing

```bash
./tests/run-php-tests.sh                  # hermetic: JWT/base64url, FCM classification,
                                          # preference filtering, payload generation
RUN_DB_TESTS=1 ./tests/run-php-tests.sh   # + registration lifecycle, multi-device,
                                          # cross-user isolation, queue dispatch
node tests/ui/run.mjs                     # Preferences surface + "never prompts on load"
```

No test contacts Firebase, and an unconfigured environment exercises the real
degraded path rather than skipping. `tests/ui/114-push-preferences.test.mjs`
specifically asserts that loading Preferences never calls
`Notification.requestPermission()`.
