# Realtime Broker in Rust — Implementation Plan

**Prepared for:** Christopher Robison
**Date:** 2026-08-12
**Status:** design, not started — no code exists yet
**Covers:** a proposed replacement connection-handling backend for
`GET /api/realtime/stream`, alongside (not instead of) `src/Realtime.php`

This is a plan, not a build. It exists so the design decisions are written
down before code is, and so a future session (or another person) can pick
this up without re-deriving the reasoning from a chat transcript.

---

## Why this exists

`docs/realtime-data.md`'s "Production configuration" section already names
the problem precisely: an SSE connection pins one PHP-FPM worker for up to
`REALTIME_STREAM_TTL_SECONDS` (~55s), and every open Backstage tab holds
one. That's fine at small scale — it's why `REALTIME_ENABLED` defaults to
`false` and the reference pool (`deploy/php-fpm/panic.conf`) was sized to
`pm.max_children = 20` for it — but it's a ceiling, not a scaling story.
Two compounding problems, not one:

1. **Process-per-connection.** PHP-FPM gives each request a whole OS
   process/thread for the request's lifetime. An SSE connection is
   *mostly idle* — it just waits to relay an occasional message — but it
   still costs a full worker (tens of MB, a process slot) for ~55 seconds
   at a time. This is the classic C10k problem: thread/process-per-connection
   servers top out in the hundreds-to-low-thousands of concurrent
   connections; an event-loop server handles tens of thousands on the same
   hardware because an idle connection costs a few KB, not a process.
2. **N independent pollers hitting the same table.** Every open connection
   today runs its own `while` loop polling `db_history` every second
   (`Realtime.php::streamLoop()`). 20 open tabs is 20 queries/second against
   the same rows. Fixing #1 alone doesn't fix this — you still want **one**
   poller feeding many subscribers, not N.

Both problems point the same direction: a small, dedicated, event-loop-based
broker process, separate from PHP-FPM, that holds the open connections and
polls `db_history` exactly once regardless of how many browsers are
listening.

---

## Goals

- Hold thousands of concurrent, mostly-idle SSE connections on modest
  hardware — orders of magnitude past the current per-tab-pins-a-worker
  ceiling.
- One `db_history` poll per tenant, fanned out in-process to every
  subscriber for that tenant — not one poll per connection.
- Keep the wire format, the frontend, and the authorization model
  unchanged. `data-worker.js`'s realtime section is already the only code
  that knows `EventSource` exists (per `docs/realtime-data.md`'s "Future
  migration path" section, written for exactly this kind of swap) — nothing
  downstream of `data-client.js`'s `onRealtimeInvalidate()` / `core.js`'s
  `data.invalidated` bus topic should need to change.
- A small, boring, long-lived dependency set — a handful of mainstream
  crates, not a sprawling tree. No Redis, no message queue, no second
  language runtime to operate, for a single-node deployment.
- No duplicated *authorization decisions*. Permission logic
  (`Capabilities::hasEvent()`, `hasGlobal()`, `token_version` revocation)
  stays in PHP, checked exactly as it is for every other endpoint. The
  broker asks PHP "what can this connection see" once per connect and
  trusts the answer for that connection's lifetime — it never reimplements
  a permission query itself.

## Non-goals

- Not a WebSocket migration — still SSE, still server→browser only, still
  the same `{entity, id, revision}` payload. Nothing bidirectional is
  needed here (see `docs/realtime-data.md`'s "Why SSE, not WebSocket").
- Not a message queue / pub-sub product (Redis, NATS, Kafka). A single
  Rust process holding the connections and doing the polling can fan out
  in-process (`tokio::sync::broadcast`); an external broker only becomes
  relevant if this needs to run on more than one node, which is not a
  today problem.
- Not a rewrite of `Capabilities.php` or `RealtimeInvalidationMapper.php`'s
  *decision logic* in Rust (see "What gets ported vs. what stays in PHP"
  below) — the mechanical table→entity mapping gets ported (it's static
  data, not a live decision); permission checks do not.
- Not a forced migration. `src/Realtime.php` is not deleted or deprecated
  by this plan — see the next section.

---

## Relationship to the existing PHP implementation

**Both implementations stay in the codebase indefinitely, selected per
install at the reverse-proxy layer — not failed-over between at runtime.**

Two live implementations of the same endpoint that silently pick between
each other on failure is exactly the kind of complexity/code-rot risk this
plan is trying to avoid elsewhere (see the crate-selection notes below) —
it doubles the surface that has to be kept behaviorally identical forever,
for a failure mode (the broker being down) that's already handled by the
existing fail-open design: if `/api/realtime/stream` is unreachable for any
reason, `data-worker.js` already degrades to `disconnected` status and the
app already works fully without realtime (see `docs/realtime-data.md`'s
"Rollout / feature flags").

So: which backend serves `/api/realtime/stream` is a **deploy-time choice**,
made in Apache/nginx config (see "Deployment" below), not a runtime
decision either service makes. An install either:

- proxies straight to PHP-FPM, running `src/Realtime.php` as it does today
  (default, zero new moving parts), or
- installs and runs the Rust broker, and proxies to it instead.

`REALTIME_ENABLED` keeps its current meaning ("is realtime a thing at all,"
consumed by the frontend and by `src/Realtime.php`'s own opt-in check) and
doesn't need to know which backend is actually serving the stream.

---

## Architecture overview

```text
 Browser (data-worker.js, unchanged)
       │  EventSource('/api/realtime/stream')
       ▼
 Apache/nginx reverse proxy            ← picks ONE of these two, per install
       │
       ├──────────────► PHP-FPM (src/Realtime.php)         [today's path]
       │
       └──────────────► realtime-broker (Rust, this plan)   [new path]
                              │
                              │  loopback HTTP, forwards the session cookie
                              ▼
                         PHP-FPM: GET /api/realtime/authorize
                              │  (Kernel auth + Capabilities, unchanged)
                              ▼
                         { tenant_id, event_ids | "all", view_booking_inbox }
                              │
                              ▼
                    one poller task PER TENANT
                    (started lazily on first subscriber,
                     stopped after the last one leaves)
                              │  SELECT ... FROM db_history WHERE id > ?
                              ▼
                          tenant's MySQL DB
                              │
                              ▼
                    tokio::sync::broadcast per tenant
                              │
                       fan out to every subscribed
                       connection for that tenant,
                       filtered to its topic list
                              │
                              ▼
                    SSE frame, byte-identical to
                    Realtime.php::buildFrame() today
```

---

## Component design

### 1. Connection handling (axum + tokio)

One `axum` route (`GET /realtime/stream`, or whatever path the reverse
proxy maps `/api/realtime/stream` onto) per incoming SSE connection. `axum`
has SSE support built in (`axum::response::sse::Sse`), so there's no need
to hand-roll `text/event-stream` framing, retry lines, or comment-line
heartbeats — same shape as `Realtime.php::writeRaw()`/`buildFrame()` today,
just emitted from Rust.

Each connection, on accept:
1. Reads the inbound `Host` header (to resolve tenant — see below) and the
   `Cookie` header (to authorize — see below).
2. Calls the PHP `/api/realtime/authorize` endpoint once, over loopback.
3. Subscribes to that tenant's `tokio::sync::broadcast` receiver, filtered
   to the topic list the authorize call returned.
4. Streams SSE frames to the browser as they arrive on that receiver, until
   the connection's own TTL elapses (mirroring
   `REALTIME_STREAM_TTL_SECONDS` today) or the client disconnects — same
   "end periodically, let `EventSource` reconnect with `Last-Event-ID`"
   behavior as today, for the same reason (bounds how long any one
   connection can hold stale authorization — see below).

### 2. Per-tenant polling & fan-out

One `tokio` task per tenant with at least one active subscriber, each
running its own `SELECT id, table_name, old_row, new_row FROM db_history
WHERE id > ? ORDER BY id ASC LIMIT ?` loop against that tenant's own
database — structurally identical to `Realtime.php::streamLoop()` today,
just running once per tenant instead of once per connection.

- **Lazy start:** a tenant's poller only starts when its first subscriber
  connects.
- **Lazy stop:** stops (and releases its DB connection) some grace period
  (e.g. 30s) after its last subscriber disconnects, so an idle tenant with
  realtime enabled but nobody currently watching costs nothing.
- Each poll batch is turned into `{entity, id}` invalidations via the
  ported mapper (below), then published once onto that tenant's
  `broadcast::Sender`; every subscribed connection receives its own copy
  from the channel and filters it against its own topic list before
  writing the SSE frame.

This is the actual fix for problem #2 above: N browsers watching the same
tenant costs one poll, not N.

### 3. Topic scheme

Everything a connection can be subscribed to, mirroring
`Realtime.php::visibleTo()`'s three cases exactly:

```text
tenant:{tenant_id}:event:{event_id}   — one specific event
tenant:{tenant_id}:lead                — the whole Booking Inbox (blanket
                                          view_booking_inbox capability
                                          today, not per-lead — see
                                          Realtime.php::visibleTo())
tenant:{tenant_id}:global              — always subscribed if the tenant
                                          matches; carries no identifying
                                          info, safe for any authenticated
                                          connection (see
                                          docs/realtime-data.md's "Why
                                          global is safe")
```

A connection's subscription list is exactly the topic list the authorize
call returns, translated 1:1 from that endpoint's response.

### 4. Authorization: the PHP `/api/realtime/authorize` handoff

**New, small, read-only PHP endpoint.** Not a new auth mechanism — it rides
through `Kernel`'s existing bearer/cookie auth exactly like every other
endpoint (so `token_version` revocation is checked for free, same as
today), and its whole body is assembling a response from `Capabilities`
calls that already exist:

```text
GET /api/realtime/authorize

→ 200 {
    "tenant_id": 7,
    "user_id": 42,
    "event_ids": [101, 118, 203] | "all",
    "view_booking_inbox": true
  }
```

`"all"` covers whatever role(s) `Capabilities::hasEvent()` already grants
blanket visibility to today, if any — **confirm this in Phase 2** rather
than assuming; if no such role exists, drop the `"all"` case and always
enumerate.

The broker calls this **once per connection, over loopback** (not through
the public reverse proxy), forwarding the browser's `Cookie` header
untouched. Rust never parses or verifies the session cookie/JWT itself —
PHP remains the only place that owns that decision, which is what avoids
duplicating `Capabilities` (a real, live, DB-backed decision) into a second
language.

**Staleness tradeoff, stated explicitly:** the snapshot is only as fresh as
the last connect/reconnect. A permission revoked mid-connection isn't
picked up until the next reconnect (bounded by the same
`REALTIME_STREAM_TTL_SECONDS`-driven reconnect cadence the PHP
implementation already uses) — this is *not* a new gap introduced by this
plan; it's the same tradeoff `Realtime.php`'s per-connection memoization
had **before** the per-polling-batch fix (see `docs/realtime-data.md`'s
permission-filtering section and this repo's realtime hardening commit).
Re-introducing it here, bounded to one reconnect interval, is an accepted
tradeoff for this design — revisit only if it proves too coarse in
practice (e.g. by having the broker re-call `/authorize` on an interval
without dropping the connection, as a later enhancement, not v1).

### 5. Invalidation mapping — what gets ported vs. what stays in PHP

**Ported to Rust, as a straight mechanical translation:** the `DIRECT`/
`CHILD`/`IGNORE` tables from `RealtimeInvalidationMapper.php` — a small,
static, stateless lookup (table name → entity + which FK column recovers
the parent id). No live DB state, no permission decision, nothing security
sensitive — just data. Porting it avoids a PHP round-trip per polled row.

**Not ported — stays exclusively in PHP:** anything `Capabilities.php`
decides, and the `token_version` revocation check. Those are live,
DB-backed, security-relevant decisions; duplicating them into Rust would
mean two implementations of "who can see what" that have to be kept in
sync by hand forever, which is a real code-rot risk of the kind you
explicitly want to avoid — the `/authorize` handoff above exists
specifically so this never has to happen.

**Maintenance note:** the two copies of the table-mapping data (PHP's
`RealtimeInvalidationMapper::DIRECT`/`CHILD`/`IGNORE` consts, Rust's port
of the same) *do* need to be kept in sync by hand when a new table is
added to either. Flag this in code review; a stretch goal for later is a
small script that diffs them or generates one from the other, not required
for v1.

### 6. Wire format — unchanged

The SSE frame emitted to the browser is byte-for-byte the same shape
`Realtime.php::buildFrame()` produces today:

```text
id: 48291
event: invalidate
data: {"entity":"event","id":123,"revision":48291}
```

This is what makes the frontend untouched by this whole plan — see Goals.

---

## Dependency list

Deliberately small — five mainstream crates, all from the same
`tokio`/`serde` ecosystem you'd depend on for anything in this space:

| Crate | Purpose | Why this one |
|---|---|---|
| `tokio` | async runtime | the standard; mature, in production at AWS/Cloudflare/Discord scale for exactly this "many idle long-lived connections" workload — not a code-rot risk |
| `axum` | HTTP + SSE | built on `tokio`/`hyper` by the same team; has `Sse` response support out of the box, avoids hand-rolling frame formatting |
| `sqlx` (async, MySQL) | polling `db_history` | compile-time-checked queries; note the offline-mode caveat (needs `cargo sqlx prepare` against a live schema, or a `.sqlx` cache checked in) — evaluate `mysql_async` instead in Phase 1 if that friction isn't worth it |
| `serde` / `serde_json` | JSON in/out | ubiquitous, no real alternative |
| *(none needed for HTTP-to-PHP)* | the loopback call to `/authorize` | reuse `axum`'s underlying `hyper` client rather than pulling in `reqwest` as a second HTTP stack |

**Explicitly not needed:** no JWT crate (Rust never parses the token — see
above), no Redis/pub-sub crate (in-process `broadcast` channel), no dotenv
crate (systemd's `EnvironmentFile=` covers config; tenant DB connection
info comes from the super DB at runtime, not from static config).

---

## What changes in the existing codebase

- **New:** `GET /api/realtime/authorize` (small PHP endpoint, Phase 2).
- **New:** the broker itself — a new top-level directory, e.g.
  `realtime-broker/` (Cargo crate), not under `src/` (PHP) or
  `public/assets/` (JS).
- **New:** `deploy/realtime-broker/` — a reference systemd unit and an
  Apache/nginx proxy snippet, mirroring how `deploy/php-fpm/panic.conf` and
  `deploy/apache/panic-fpm-handler.conf` document the PHP-FPM pool today.
  Same "prepared, not applied" convention as `docs/php-fpm-cdr-pool.md`.
- **Unchanged:** `public/assets/data-worker.js`, `data-client.js`,
  `core.js` — the `EventSource` URL is still whatever the reverse proxy
  maps `/api/realtime/stream` to; the frontend has no idea which backend is
  behind it.
- **Unchanged:** `src/Realtime.php`, `src/RealtimeInvalidationMapper.php`
  — stay as the default/reference implementation for installs that don't
  run the broker.

---

## Phased implementation plan

| Phase | What | Depends on | Effort |
|---|---|---|---|
| 0 | Confirm open questions (below) before writing code | — | ~1 day |
| 1 | Single-tenant prototype: hardcoded topic list, no `/authorize` yet, prove SSE fan-out + `db_history` polling works end to end against one DB | 0 | ~3–5 days |
| 2 | Real per-connection authorization via `/api/realtime/authorize` + topic filtering | 1 | ~2–3 days |
| 3 | Multi-tenant support: tenant resolution from `Host`, per-tenant lazy poller start/stop, tenant DB connection pooling | 2 | ~3–5 days |
| 4 | Deployment: systemd unit, reverse-proxy routing, `deploy/realtime-broker/` reference config | 3 | ~1–2 days |
| 5 | Operational hardening: graceful shutdown, slow-client/backpressure behavior, logging/metrics | 3–4 | ~2–3 days |
| 6 | Testing: port `realtime_invalidation_mapper_test.php`'s cases, integration test against a real DB + mock `/authorize`, point one of `tests/ui/115-117-*.test.mjs` at it | throughout | ~2–3 days |

Total: roughly two to three weeks of focused work, not counting time spent
running it in production and tuning `pm`/pool-equivalent settings (there is
no PHP-FPM-style pool to size here — the point of this plan is that there
isn't one).

---

## Operational concerns

- **Backpressure / slow clients:** `tokio::sync::broadcast` is a bounded
  channel; a receiver that falls too far behind gets `Err(Lagged(n))`
  instead of blocking the sender. The existing wire contract already
  treats gaps as recoverable — every frame carries its own revision id, and
  `EventSource`'s reconnect-with-`Last-Event-ID` (already implemented in
  `data-worker.js`) replays anything missed. A lagged receiver can simply
  be dropped/closed; the browser's own reconnect logic handles the rest,
  unchanged.
- **Graceful shutdown:** on `SIGTERM`, stop accepting new connections, let
  in-flight ones finish their current frame, then close — standard `tokio`
  shutdown pattern, not a new design problem.
- **Observability:** structured logs (`tracing` crate would be the natural
  addition here if this list grows) plus, at minimum, a `/healthz` route
  and counters for open connections / active tenant pollers, so an ops
  person can see at a glance whether it's actually cheaper than the PHP
  path it replaces.
- **Expected ceiling:** a few KB per idle connection under `tokio` puts
  tens of thousands of concurrent connections comfortably within this
  host's memory (see the reasoning already in
  `deploy/php-fpm/panic.conf`'s comments for the equivalent PHP-FPM
  math) — several orders of magnitude past the `pm.max_children = 20`
  ceiling the PHP path is bound by today.

## Testing strategy

- **Unit:** port `tests/realtime_invalidation_mapper_test.php`'s table
  cases to Rust — same inputs, same expected `{entity, id}` outputs,
  against the ported `DIRECT`/`CHILD`/`IGNORE` tables.
- **Integration:** spin up the broker against a real (throwaway) tenant
  DB, seed `db_history` rows, assert the right SSE frames come out, with a
  stub `/authorize` endpoint standing in for PHP.
- **End-to-end:** once Phase 4 (deployment) exists, point one of the
  existing realtime UI tests (`tests/ui/115-realtime-data-layer.test.mjs`
  is the natural first target) at a reverse-proxy config routing to the
  broker instead of PHP, and confirm it passes unmodified — that's the
  actual proof the frontend contract held.

---

## Open questions to confirm before Phase 1

1. **Does any role get blanket ("all events") visibility** in
   `Capabilities::hasEvent()` today? Determines whether `/authorize` needs
   the `"all"` case or can always enumerate ids.
2. **`sqlx` vs `mysql_async`** — is the compile-time query-checking of
   `sqlx` worth its offline-mode setup friction for a query set this small
   (effectively one query)? Leaning `mysql_async` for less build-time
   ceremony, but worth 30 minutes of hands-on comparison in Phase 1 rather
   than deciding on paper.
3. **Reconnect/backoff parity** — should the broker mirror
   `data-worker.js`'s existing capped-backoff reconnect expectations
   exactly (it already handles server-side disconnects generically), or
   does anything here need frontend changes? Current read: no frontend
   changes needed, but confirm once Phase 1's prototype exists to test
   against.
4. **How many real tenants exist today?** Phase 3's multi-tenant work is
   worth doing regardless (the codebase already supports it via
   `SUPER_DB_NAME`), but if there's currently exactly one live tenant, it's
   reasonable to ship Phases 1–2 against that one tenant first and treat
   Phase 3 as a distinct, lower-urgency follow-up rather than a blocker for
   getting *anything* running.
