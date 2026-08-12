# Realtime Data Layer

A browser-native client-side data layer that moves ordinary JSON API traffic
off the main thread into a dedicated Web Worker, and adds a realtime
server-to-client invalidation channel on top of the existing `db_history`
audit trigger log — so Backstage behaves like a realtime multi-user
operational app without a frontend framework, a build step, or a second
application API.

**The whole thing is additive and fails open.** If a browser doesn't support
workers, if the worker fails to construct, if `REALTIME_ENABLED=false`, or if
the realtime connection can't be established, `api()` keeps working exactly
as it always has — a direct `fetch()` from the main thread. Nothing in this
document changes the PHP HTTP API's shape, MySQL's role as the only
authoritative datastore, or Firebase's role as push-notification delivery
only.

---

## Architectural principle

```text
                    APPLICATION DATA

 Web Components
       │  api()
       ▼
    core.js
       │  postMessage
       ▼
 data-worker.js
       │  ordinary HTTP fetch
       ▼
 Existing PHP JSON API
       │
       ▼
     MySQL


                    REALTIME CHANGES

       MySQL
         │
      db_history            (audit trigger log — already existed)
         │
         ▼
 GET /api/realtime/stream    (src/Realtime.php — new)
         │
         ▼
   data-worker.js            (owns the EventSource — new)
         │  postMessage
         ▼
      core.js                (republishes onto the page bus — new)
         │
      PAN/LARC                `data.invalidated`
         │
         ▼
   Web Components            (re-fetch through api() — unchanged pattern)
```

**HTTP remains the application API.** The realtime channel only ever says
*"something changed; data you're holding may be stale"* — never *"here is the
new value."* A component that cares fetches the authoritative value back
through the same `api()` it already uses. This is deliberate: it means
components never need a second way to interpret server data, optimistic
writes/conflict handling stay entirely in the existing HTTP request/response
flow, and the realtime channel can be down, slow, or wrong without any risk
of the UI holding data MySQL never actually produced.

---

## Why Server-Sent Events, not WebSocket

The realtime channel only needs server → browser. SSE is plain HTTP: it
works through existing reverse proxies, `EventSource` reconnects
automatically, `Last-Event-ID` gives resumability for free, and it doesn't
require a second server process or protocol. A future move to WebSocket (if
this ever needs bidirectional RPC, which is explicitly a non-goal right now)
would only touch `data-worker.js`'s realtime section — components only ever
see `realtime.status` / `realtime.invalidate` messages via `core.js`, never
`EventSource` itself.

---

## Files

| File | Role |
| --- | --- |
| `public/assets/data-worker.js` | Dedicated Web Worker: fetch, GET dedup + cache, owns the `EventSource` |
| `public/assets/data-client.js` | Main-thread `Worker` RPC wrapper — the only file that constructs the worker |
| `public/assets/core.js` | `api()` compatibility shim over the worker + direct-fetch fallback; PAN/LARC bridge |
| `src/Realtime.php` | Authenticated SSE endpoint (`GET /api/realtime/stream`) |
| `src/RealtimeInvalidationMapper.php` | `db_history` row → safe `{entity, id}` invalidation |
| `src/Response.php` | `Response::stream()` — generic long-lived/incrementally-flushed response |

---

## Request flow (`api()`)

Every existing call site is unchanged:

```js
const data = await api(`/events/${id}`);
```

Internally, `core.js`'s `api()` now does:

1. If the request is worker-eligible (see below) and the worker is
   available, send it through `data-client.js` → `data-worker.js` → `fetch`.
2. Otherwise (or if the worker request itself fails), fall back to a direct
   `fetch()` on the main thread — the exact code path that existed before
   this feature, kept as `_directFetch()`.
3. The existing 401 → `tryRefresh()` → retry-once logic is unchanged and
   wraps *either* path identically, since both return the same
   `{status, ok, body}` shape.

A single failed worker request marks the worker unusable for the rest of the
page's lifetime (`dataClient.disable()`) — every subsequent `api()` call
falls back to direct fetch. This is a deliberately conservative policy:
guessing which failures are transient is more complexity than the payoff is
worth at this app's scale.

### Worker request protocol

```js
// main thread → worker
{ type: 'api.request', id, path, options: { method, headers, body, cache }, token }

// worker → main thread
{ type: 'api.response', id, status, ok, body }
```

Correlation ids (`id`) resolve pending Promises on the main thread; nothing
crosses the boundary that isn't structured-clone-safe plain data — no
`Response`/`Headers` objects, no closures.

### Requests that do NOT go through the worker

`FormData` bodies (file uploads — `event-panels.js`'s asset upload,
`mailing-lists.js`'s CSV import, etc.) always go through `_directFetch()`.
`FormData` is not structured-clone-safe, so this isn't an optimization, it's
a correctness requirement. Anything else that isn't ordinary JSON
request/response (downloads, QR/PDF generation, OAuth redirects, public
feeds) already bypasses `api()` entirely in this codebase via a raw `fetch()`
— see e.g. `contracts.js`, `reports.js`, `event-workspace.js`'s
CSV export, all of which call `fetch()` directly rather than `api()`. The
worker only ever sees traffic that already went through `api()`.

---

## Deduplication and cache

Inside `data-worker.js`:

- **In-flight GET dedup**: simultaneous identical `GET /events/123` requests
  from different components share one `fetch()` and one response.
- **Cache**: successful JSON GET responses only, keyed `METHOD:path`, 2
  second max age. `options.cache === 'no-store'` bypasses both dedup and
  cache for a single call — as does supplying any custom `options.headers`
  (see below).
- **Invalidation**: any successful mutation (non-GET, `ok: true`) clears the
  *entire* cache. Any realtime invalidation (any entity, including
  `global`) also clears the entire cache.

This is intentionally the simplest thing that's still correct — a `Map`, not
a normalized entity store. Precise per-key invalidation (e.g. only clearing
`GET:/events/123` when `event:123` is invalidated) was considered and
rejected for now: the cost of getting that wrong (a stale value silently
served) is much higher than the cost of an extra fetch, and clearing
everything keeps the cache trivially easy to reason about. This does leave
room for a future stale-while-revalidate layer without a rewrite — the cache
entry shape (`{ status, ok, body, cachedAt }` per key) already supports it.
The short 2s max age is deliberate: the primary optimization is in-flight
dedup + realtime invalidation, not long-lived browser-side caching — a
short TTL bounds how stale a GET made *without* a realtime signal (e.g. a
non-mapped table, or realtime disabled) can be.

### Request identity: custom headers bypass cache/dedup

The cache/dedup key is `METHOD:path` — it does not account for headers. A
caller that supplies its own `options.headers` (none of today's call sites
do, but the contract must hold regardless) could otherwise share a cached or
in-flight response with a request that is not actually equivalent (e.g. a
different `Accept` or a conditional header). Rather than build a canonical
header-hashing scheme, a GET with any custom headers is simply treated like
`cache: 'no-store'`: it always fetches, and it neither reads nor writes the
cache or the in-flight map. Plain GETs (the overwhelming majority) are
unaffected and keep full caching/dedup.

### Cache generation (epoch) and the stale in-flight GET race

Clearing the cache on invalidation isn't enough by itself: a GET that began
*before* an invalidation can still resolve *after* it, and without more care
that late arrival could both (a) get handed to a caller that asked for data
after the invalidation, via in-flight dedup, and (b) repopulate the
now-supposedly-fresh cache with the stale value it fetched. `data-worker.js`
guards against both with a simple in-memory `cacheEpoch` counter, bumped by
`clearCache()` (so both a successful mutation and a realtime invalidation
advance it identically):

- Each GET captures `requestEpoch = cacheEpoch` when it starts.
- Its in-flight map entry carries that epoch: `{ epoch, promise }`.
- A later GET for the same key only dedups onto that entry if
  `existing.epoch === cacheEpoch` (i.e. nothing has invalidated since the
  in-flight request started) — otherwise it starts its own fetch.
- When a GET resolves, it's only written into the cache if
  `requestEpoch === cacheEpoch` still holds.

The original in-flight request is never cancelled — it still resolves
normally for whichever caller originally issued it — it just stops being
eligible to satisfy a newer request or repopulate the cache once an
invalidation has moved the epoch past it.

---

## Realtime invalidation flow

### `db_history`'s role

`scripts/generate-audit-triggers.php` already installs `AFTER INSERT/UPDATE/
DELETE` triggers on (almost) every table, writing old/new row JSON and an
actor into `db_history` regardless of whether the write came from the app,
a cron job, or a manual `mysql` session. This feature adds nothing to that
pipeline — it only *reads* `db_history` as an ordered change journal.
`db_history.id` is the realtime revision number: monotonically increasing,
already the SSE `id:` field, already what `Last-Event-ID` resumes from.

### The endpoint

`GET /api/realtime/stream` (`src/Realtime.php`), authenticated exactly like
every other endpoint (Kernel's normal bearer/cookie auth — it is not in
`Kernel::isPublic()`). Every ~1 second (`REALTIME_POLL_INTERVAL_MS`) it:

1. Reads `db_history` rows with `id > $since`.
2. Maps each to `{entity, id}` via `RealtimeInvalidationMapper` (below).
3. Drops anything the requesting user isn't allowed to see (below).
4. Collapses duplicate `(entity, id)` pairs within one poll batch to one
   frame — a single transaction touching `events` + `event_tasks` +
   `event_blockers` only produces one `event:123` invalidation.
5. Writes one SSE frame per surviving invalidation:

   ```text
   id: 48291
   event: invalidate
   data: {"entity":"event","id":123,"revision":48291}
   ```

   Unclassified tables get a content-free fallback instead of being
   dropped:

   ```text
   id: 48293
   event: invalidate
   data: {"entity":"global","revision":48293}
   ```

The connection intentionally ends after `REALTIME_STREAM_TTL_SECONDS`
(default 55s) rather than running forever — see **Reconnect behavior**
below. While idle, it sends `: heartbeat\n\n` comment lines (invisible to
`EventSource`'s event handling) every `REALTIME_HEARTBEAT_SECONDS` so
intermediary proxies don't kill it for being quiet.

### `RealtimeInvalidationMapper`

A small, explicit table → entity mapping (`src/RealtimeInvalidationMapper.php`):

- **Direct**: `events` → `event:{id}`, `leads` → `lead:{id}` (the row's own
  primary key).
- **Child**: `event_tasks`, `event_blockers`, `event_lineup`,
  `event_schedule_items`, `contracts`, … → `event:{event_id}` (recovered
  from a foreign key in `old_row`/`new_row`); `lead_messages`, `lead_notes`,
  `lead_watchers`, `lead_status_history`, … → `lead:{lead_id}`. Only reads
  `old_row`/`new_row` server-side to recover that id — never forwards either
  to the browser.
- **Ignore**: `rate_limits`, `refresh_tokens`, `magic_link_tokens`,
  `email_verification_tokens`, `webauthn_challenges`, `portal_tokens` — high-
  write-frequency auth/rate-limit plumbing with zero UI signal. Without this
  list, these tables would dominate the `global` fallback traffic (nearly
  every request touches `rate_limits`) for no benefit.
- **Everything else**: falls back to `{entity: 'global'}` — a safe, content-
  free "something changed" signal rather than silently dropping a table this
  map hasn't been taught about yet. The mapper only covers Events and
  Booking Inbox for now, per spec; extend the `CHILD`/`DIRECT` consts rather
  than adding a parallel mechanism.

### Why `global` is safe

A `global` invalidation carries no table name, no id, no row data — nothing
an unauthorized user couldn't already infer just by using the app normally.
It's safe to send to any authenticated user regardless of their
capabilities. The tradeoff is precision: a `global` event doesn't tell a
component *what* to refresh, so components only treat `event`/`lead`
invalidations as an actionable "go refetch" signal; `global` mainly serves
the worker's own "clear the cache to be safe" policy. See **Known
limitations** for what this trades away.

### Permission filtering

Re-checked on every row, not just at connection time:

- `entity: 'lead'` → requires the `view_booking_inbox` global capability
  (`Capabilities::hasGlobal()`).
- `entity: 'event'` → requires `read_event` on that specific event
  (`Capabilities::hasEvent()` — the same per-event ownership/collaborator
  check every other event endpoint uses). Memoized per database polling
  batch (not for the lifetime of the connection) per event id, so a burst of
  child-table rows for one event within a single poll still costs one
  permission query, not one per row — while a permission change (e.g. a
  collaborator removed mid-stream) is re-checked on the very next poll
  rather than staying effectively cached until the connection's ~55s TTL
  forces a reconnect.
- `entity: 'global'` → always visible (see above).

A user who isn't allowed to see a lead or event never learns even the bare
fact that it changed — the row is silently skipped (the connection's
`$since` still advances past it, so it isn't retried forever).

### Multi-tenant isolation

`src/Realtime.php` extends `BaseEndpoint` and only ever queries
`$this->db` — the tenant-scoped `Database` Kernel already resolved (from
`HTTP_HOST` via `TenantContext`) before constructing the endpoint, identical
to every other endpoint in the app. There is no separate connection pool,
cache, or process-global state for realtime to leak across tenants through;
one tenant's `db_history` table is a physically different database than
another's.

### Authentication over SSE

Browsers cannot attach a custom `Authorization` header to an `EventSource`
request. In practice this endpoint is reached via the `backstage_access`
HttpOnly session cookie — set on every login (`SessionCookies::issue()`),
sent automatically for this same-origin request. `Auth::authenticate()`
already tries the Bearer header first and falls back to that cookie, so no
change was needed there. A request with no valid session gets Kernel's
ordinary 401 JSON response before `Realtime` is ever instantiated —
native `EventSource` does not auto-retry after a non-2xx/non-`text/event-
stream` response (a "fail the connection" per the SSE spec). `data-worker.js`
layers its own manual capped-backoff retry on top of that (see **Reconnect
behavior** below) rather than treating it as permanently dead, since a 401
here could later become valid again (a stale cookie mid-refresh, a brief
auth hiccup) without the tab ever reloading.

### Reconnect behavior

The endpoint intentionally ends its own response after ~55 seconds. This is
not a failure — the browser's `EventSource` reconnects automatically,
sending `Last-Event-ID` set to the last event id it received, and
`resolveSince()` resumes exactly there. No changes are lost across a
reconnect: the DB poll always starts from `db_history.id > $since`. Ending
the connection periodically (rather than looping forever) keeps one PHP-FPM
worker from being pinned indefinitely and keeps the request inside normal
proxy/PHP timeout budgets — see **Production configuration** below.

On a genuine network-level failure the browser also auto-reconnects (with
its own default ~2s delay, set explicitly via a leading `retry: 2000` line).
`data-worker.js` distinguishes that (`EventSource.readyState ===
CONNECTING`, already being retried natively — just relay a `connecting`
status) from a terminal failure (`readyState === CLOSED`, e.g. a 401),
where it applies its own capped exponential backoff (1s → 30s) and retries
manually as long as `realtime.start` is still in effect.

---

## Worker realtime status + PAN/LARC bridge

The worker never lets a component touch `EventSource` directly:

```js
// worker → main thread
{ type: 'realtime.status', state: 'connecting' | 'connected' | 'disconnected', detail }
{ type: 'realtime.invalidate', entity, id, revision }
```

`core.js` republishes both onto the existing PAN/LARC page bus — the only
bridge between the worker and the rest of the app:

```js
publish('realtime.status', { state, detail });
publish('data.invalidated', { entity, id, revision });
```

Components subscribe to `data.invalidated` exactly like any other bus topic
(`subscribe('data.invalidated', handler, signal)`). No new event bus, no
global DOM-mutation engine, no component ever instantiates `EventSource` or
talks to the worker directly.

---

## Booking Inbox

`public/assets/inbox/inbox-shell.js` no longer polls `/api/inbox/changes`
every 8 seconds unconditionally. Instead:

- A `lead`-entity `data.invalidated` message is debounced 200ms, then
  triggers the exact same `pollChanges()` the old timer used to call
  (fetches `/api/inbox/changes`, reloads the queue, and refreshes the
  currently-selected lead only if it's one of the changed ones) — selection
  and scroll state are untouched, since that method already worked that way.
- A slow (60s) fallback poll only runs while realtime is unavailable or
  unhealthy (tracked via `realtime.status`), for resilience if the stream is
  down. It's suspended the moment realtime reports `connected`.

## Events

`public/assets/event-workspace.js`'s `EventWorkspace` already had an
`event.changed` bus topic and a `broadcastEventData()` helper (used after
this tab's own saves) that a small set of read-only subcomponents
(`EventSummary`, `EventReadiness`, `EventAutomation`, the workspace header
patch) subscribe to. Realtime reuses that exact mechanism rather than adding
a parallel one: an `event`-entity invalidation matching the currently open
event is debounced 250ms, re-fetches `/events/{id}`, and calls
`broadcastEventData()` with the fresh payload — which the existing
subscribers already know how to apply in place.

This is safe for in-progress editing *by construction*, not by convention:
`EventDetailsForm` (the autosaving Details tab) was audited and confirmed to
never subscribe to `event.changed` at all — it manages its own state
independently and only updates itself from its own save flow. A remote
invalidation therefore cannot overwrite a field the user is actively
editing, because the one component that owns editable form state was never
wired to react to this bus topic in the first place. Same for every other
section panel (Tasks, Blockers, Lineup, Schedule, …) — none of them
subscribe to `event.changed`; each owns and refreshes its own data
independently, unaffected by this feature.

---

## Firebase boundary

Unchanged and untouched by this feature. Firebase Cloud Messaging
(`src/Notifications/`, `public/assets/push.js`) remains **push-notification
delivery only** — see `docs/push-notifications.md`. Realtime UI
synchronization never travels through FCM, and this feature adds no
Firestore/Realtime Database usage, no Firebase listener, and no second copy
of application data anywhere outside MySQL.

---

## Rollout / feature flags

Three states, and the app is fully usable in all of them:

```text
best:      worker + HTTP + realtime
degraded:  worker + HTTP, no realtime         (REALTIME_ENABLED=false, or stream unreachable)
fallback:  direct HTTP only                    (worker unsupported/broken)
```

- **Server**: realtime is **opt-in** — `REALTIME_ENABLED` is absent, blank,
  `false`, or `0` in `.env.example`'s shipped default, and any of those
  (including simply never setting it) makes the endpoint 404, exactly like
  explicitly setting `REALTIME_ENABLED=false`. Only `REALTIME_ENABLED=true`
  or `REALTIME_ENABLED=1` turns it on. See **Production configuration**
  below for why this defaults off and what to check before enabling it. The
  client falls back to direct HTTP/no realtime meanwhile, but each open tab
  keeps retrying with capped backoff (up to every 30s — see **Reconnect
  behavior**) so flipping the flag on later is picked up automatically, with
  no page reload required. That backoff means a disabled/unreachable stream
  costs roughly one small request every 30s per open tab, not zero — worth
  knowing even while it's off by default.
- **Client, worker path**: `localStorage.backstage_worker_disabled = '1'`
  forces every `api()` call to the pre-existing direct-fetch path, skipping
  worker construction entirely.
- **Client, debug logging**: `localStorage.backstage_debug_data = '1'`
  turns on verbose `console.log` in both `data-client.js` and
  `data-worker.js` (worker started/stopped, requests going through it,
  dedup/cache hits, realtime connect/disconnect/reason, last revision seen).
  Off by default so normal users see no extra console noise.
- `window.PBData` (== the `data-client.js` singleton) is exposed for manual
  QA — `PBData.isAvailable()`, `PBData.getRealtimeState()`.

---

## Production configuration

An SSE connection pins one PHP-FPM (or `php -S`) worker for up to
`REALTIME_STREAM_TTL_SECONDS`, and each open tab immediately reconnects when
that ends — so, unlike an ordinary short-lived endpoint, "concurrent open
tabs" is a better sizing input than "requests per second." Size
`pm.max_children` for a meaningful fraction of concurrently signed-in staff
holding a live connection at once, not just burst HTTP concurrency.

**This is exactly why realtime defaults to off.** `deploy/php-fpm/panic.conf`
(this repo's own reference pool config) ships `pm.max_children = 6` — sized
for ordinary short-lived requests across ~200 shared vhosts, not for several
staff each pinning a worker continuously. Setting `REALTIME_ENABLED=true`
against an unsized pool risks starving ordinary API requests once more than
a handful of staff have the app open, which is why the flag requires an
explicit opt-in (`REALTIME_ENABLED=true` or `REALTIME_ENABLED=1` — anything
else, including leaving it unset, stays disabled; see **Rollout / feature
flags** above) rather than shipping on by default. Before enabling it on an
existing install: raise `pm.max_children` for that pool to comfortably cover
the number of staff who might have the app open in a tab at the same time,
first. The app is fully functional with realtime off.

- **PHP-FPM**: `request_terminate_timeout` must be ≥
  `REALTIME_STREAM_TTL_SECONDS` (plus headroom) or FPM will kill the worker
  mid-stream before it ends the connection cleanly itself.
- **Apache**: `Timeout` directive, same constraint. `mod_deflate` must not
  compress this response (buffers the whole thing before sending, defeating
  incremental flush) — `SetEnvIf` on `Content-Type: text/event-stream` to
  disable it, or scope `mod_deflate` to exclude `/api/realtime/`.
- **nginx** (reverse-proxying to PHP-FPM): `proxy_buffering off;` and
  `fastcgi_read_timeout`/`proxy_read_timeout` ≥ the TTL for the
  `/api/realtime/` location. `X-Accel-Buffering: no` (already sent by
  `Response::stream()`) is nginx's per-response equivalent, but the timeout
  values still need to be configured — a response header can't override a
  connection-level timeout.
- **`php -S` (dev only)**: single-threaded by default; set
  `PHP_CLI_SERVER_WORKERS=8` (or similar) or a long-held stream starves
  every other concurrent request. `tests/ui/browser.mjs` and CI's dev-server
  steps already do this.

---

## Known limitations

- **`global` invalidations are unclassified.** They're a safe fallback, not
  a precise signal — the worker clears its cache on one, but the Booking
  Inbox and Events workspace only actively refetch on their specific
  entity (`lead`/`event`) to avoid needless refetch storms from unrelated
  background writes (e.g. an unrelated table getting the `global` fallback).
  A future gap in `RealtimeInvalidationMapper` therefore degrades to "no
  proactive refresh for that specific change" rather than "wrong data
  shown" — the next normal navigation/API call still gets fresh data.
- **Deleting an entity can suppress its own invalidation.** Permission
  filtering re-checks `read_event`/`view_booking_inbox` against current
  state; if an event row is deleted, `Capabilities::hasEvent()` can no
  longer confirm a given viewer's access to it, so that specific deletion
  may not reach every viewer who was actually looking at it. Deletions are
  rare/administrative; this is an accepted tradeoff for keeping permission
  checks simple rather than caching prior-access decisions past a row's
  lifetime.
- **No field-level conflict resolution.** Realtime tells you *that* an
  event changed, not *what* changed or by whom. Two people editing the same
  field concurrently can still race at the HTTP layer exactly as before this
  feature — unchanged from prior behavior, and out of scope here. The wire
  format (`{entity, id, revision}`) intentionally leaves room for a future
  `expectedRevision` / `409 Conflict` optimistic-concurrency layer without
  a redesign, but that isn't built yet.
- **No presence, no cursors, no CRDTs.** Not attempted; not needed at this
  app's scale.
- **`SharedWorker`/WebSocket are intentionally not used yet.** See
  **Future migration path** below.

---

## Future migration path to WebSocket

If scale ever justifies bidirectional realtime RPC (not needed today — see
the Non-Negotiable Constraints this feature was built under), the seam is
already isolated: `data-worker.js`'s realtime section is the only code that
knows `EventSource` exists. Swapping it for a `WebSocket` (or a
`SharedWorker` wrapping one, if per-tab connections become a real cost)
would change that one file's internals; `data-client.js`'s
`onRealtimeStatus`/`onRealtimeInvalidate` API and `core.js`'s
`realtime.status`/`data.invalidated` bus topics would not need to change,
so no component would need to change either.
