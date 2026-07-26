# Panic Backstage — Automation Module Completion Plan

**Prepared for:** Christopher Robison
**Date:** 2026-07-22
**Covers:** `src/Processes*`, `src/Processes/Runtime/*`, `src/Processes/CenterStage/*`,
`public/assets/processes/*`, migrations 066–069

This is the punch list for finishing the process-automation feature (sidebar
"Automation"), grounded in the current source — not the original spec. Every
item below was verified by reading the code, not inferred from commit
messages. It expands on the honesty callouts already published in
`docs/ops-manual.html` Chapter 11 and `public/assets/help.js`; those stay the
source of truth for *end users*, this doc is the source of truth for
*building the rest of it*.

The codebase already thinks in phases (see doc-comments in `Engine.php` and
`BookingHandlers.php`):

| Phase | What it is | Status |
|---|---|---|
| **Phase 1** | Graph model, designer, validator, versions/publish (`src/Processes.php`, `public/assets/processes/*`) | ✅ Done |
| **Phase 2** | Generic runtime engine — advance/pause/resume/retry, tasks, waits (`src/Processes/Runtime/Engine.php`) | ✅ Done, with known simplifications (below) |
| **Phase 3** | Real CenterStage handlers for the Event Booking process (`src/Processes/CenterStage/BookingHandlers.php`) | ✅ Done for 5 of 8 real ops; deposits/settlement/AI deliberately simulated |
| **Phase 4** *(proposed)* | Everything in this document | ⏳ Not started |

---

## Status at a glance

| # | Item | Nav/area | Status | Effort |
|---|---|---|---|---|
| 1 | Availability decision doesn't read the real check result | Event Booking sample process | 🐛 Bug, data already exists | ~0.5 day |
| 2 | No automatic trigger — every case is started by hand | Processes | ⏳ Not started | ~3–5 days |
| 3 | **Cases** nav item — cross-process instance list | Automation nav | ⏳ Placeholder | ~2–3 days |
| 4 | **Activity** nav item — cross-process execution feed | Automation nav | ⏳ Placeholder | ~2–3 days |
| 5 | **Connections** nav item — credential store for action nodes | Automation nav | ⏳ Placeholder | ~5–7 days |
| 6 | Parallel Split / Join / Subprocess don't actually fan out | Runtime engine | ⏳ Simplified pass-through, documented | ~1–2 weeks |
| 7 | Deposit collection stays simulated | Event Booking process | ⏳ Simulated by design | ~2–3 days |
| 8 | Settlement stays simulated | Event Booking process | ⏳ Simulated, no primitive exists yet | Blocked — needs a settlement feature first |
| 9 | AI steps not wired to a real model | Any process | ⏳ Simulated, optional per spec | ~3–5 days |
| 10 | Email steps never auto-send | Any process | ✅ **Not a gap** — deliberate product decision | — |

---

## 1. Wire the "Date Available?" decision to its real check — bug, quick fix

**The gap:** `Engine::evaluateDecision()` (`src/Processes/Runtime/Engine.php:502`)
already supports reading a variable — `if ($variableKey && array_key_exists($variableKey, $variables))`
— and matching it against a branch id. `BookingHandlers::checkAvailability()`
(`src/Processes/CenterStage/BookingHandlers.php:83`) already sets
`$variables['date_available'] = 'yes'|'no'` as a real side effect, and the
seeded "Date Available?" node's branches are literally `id => 'yes'` /
`id => 'no'` (`database/seed_event_booking_process.php:63-66`). Every piece
the engine needs is already there — the node's `config.variableKey` is just
never set to `'date_available'`, so it falls through to the `no` branch
(marked default) every time, regardless of the real answer.

**Two changes, both small:**
- **Data fix:** add `'variableKey' => 'date_available'` to the `date_available`
  node's config in `database/seed_event_booking_process.php`, and re-run it
  (or write a one-off migration that patches the live seeded graph for
  existing installs).
- **Designer UI fix:** `renderBranchGroup()` in
  `public/assets/processes/process-inspector.js:136` has no field to set
  `config.variableKey` on a decision node at all today — only per-branch
  label/condition/default. Add a "Read outcome from variable" text input (or
  a dropdown of variables the graph has produced so far) above the branch
  list, otherwise this bug will resurface the moment anyone builds a second
  process with a real decision.

**Effort:** ~0.5 day. No schema changes, no migration required for the code
path itself (only for patching already-seeded installs).

---

## 2. Automatic triggers — start a case from a real event, not a click

**The gap:** `trigger.centerstage_event` ("Booking Inquiry Created") exists in
the palette (`node-registry.js:76`) and the seed process uses it
(`inquiry_received` node), but nothing in the app ever fires it — every case
starts via a human clicking **Start Instance** in `Processes\Instances.php::startInstance()`.
`Engine.php:216` treats any `trigger.*` node as "already fired at instance
start," i.e. it's a no-op marker, not a live listener.

**Approach:**
- Pick the first real hook point deliberately — most likely a new lead/inquiry
  landing (`src/Leads.php`) or a specific event status transition — and, on
  that action, look up published process versions whose trigger node type
  matches and `entity_type` lines up, then call `Engine::startInstance()`
  programmatically with `entity_id` set to the real record.
- Needs a way to avoid double-starting a case for the same record (an
  idempotency check similar to the `idempotencyKey` pattern already used on
  operation nodes) and a per-process on/off switch so a draft or
  not-yet-trusted process can't silently start firing on real inquiries the
  moment it's published.
- Decide whether this is opt-in per process (a checkbox on the trigger node:
  "Auto-start on this event") or opt-in globally per install — the ops
  manual explicitly calls the current manual-only behavior "a deliberate,
  separate decision to make once a few manually-started real runs have been
  watched" (`BookingHandlers.php:23`), so this is a product decision to
  confirm, not just an engineering one.

**Effort:** ~3–5 days, once the hook point and opt-in model are decided.

---

## 3. Cases — cross-process instance list

**What exists today:** every process already has its own **Live Cases**
drawer (`process-live-cases.js`) showing that process's instances — status,
current step, owner, elapsed time — backed by `Processes\Instances::index()`.
The data model (`process_instances`) is already correct for a global view;
nothing new needs to be stored.

**Approach:**
- New endpoint, e.g. `GET /api/process-instances?status=&process_id=&entity_type=&q=`,
  a thin cross-definition version of `Instances::index()` that joins
  `process_definitions` for the process name/category instead of scoping to
  one `processId`.
- New page component (replace `automation-placeholder.js`'s `cases` entry)
  reusing `process-live-cases.js`'s row rendering, with an added "Process"
  column and a process-name filter.
- Same `view_processes` / `manage_processes` permission split as the
  per-process view already uses.

**Effort:** ~2–3 days — mostly a read-side aggregation query plus UI, no new
tables.

---

## 4. Activity — cross-process execution/audit feed

**What exists today:** two separate trails already exist per process:
`process_audit_log` (draft saves, publishes — served by `Processes\Audit.php`)
and `process_executions` (one row per node run, real or simulated — written
by `Engine::recordExecution()`). Nothing unions them across processes today.

**Approach:**
- New endpoint that reads both tables across all `process_definitions`,
  normalized into one feed shape (`{type: 'audit'|'execution', process_name,
  instance_id?, node_name?, actor, created_at, detail}`), paginated/filterable
  by process, type, and date range.
- Consider whether this should also surface `process_waits` timeouts flagged
  by `scripts/process-tick.php` as their own feed entries — the tick script
  already marks waits overdue; that event just isn't visible anywhere
  cross-process yet.
- Replace `automation-placeholder.js`'s `activity` entry with a real feed
  component; a simple reverse-chronological list is enough for v1, no need
  to over-build filtering before there's real usage to learn from.

**Effort:** ~2–3 days.

---

## 5. Connections — named credentials for action nodes

**What exists today:** nothing. This is the one placeholder with no backing
data model at all yet. The intent is stated directly in the placeholder's own
copy (`automation-placeholder.js:22-25`): action nodes should resolve a named
connection at runtime instead of any credential ever being embedded in a
graph document.

**Approach:**
- New table, e.g. `process_connections` (`id`, `name`, `type` — e.g.
  `smtp`/`http_bearer`/`api_key`, `enc_config` encrypted at rest using the
  same libsodium secretbox pattern already used for
  `promote_credentials.enc_access_token`/OAuth tokens elsewhere in this
  codebase, `created_by`, timestamps).
- CRUD endpoint, `manage_processes`-gated, replacing the `connections`
  placeholder page with a real add/edit modal (per this repo's UI
  convention — table add/view/edit as a modal via `openModal()`).
- `HandlerRegistry`/`Engine` change: an operation node config gets an
  optional `connectionId`; a real handler resolves it via a small
  `ConnectionResolver` service before running, rather than each handler
  reaching into raw config. This only matters once a handler actually needs
  an external credential (e.g. a generic HTTP Request or outbound-email
  operation) — today's real handlers (`BookingHandlers.php`) don't call
  anything external, so this can land independently of item 2/6/7.
- **Security note:** this is the one piece of Phase 4 that touches secret
  storage — plan for a security review before shipping, same bar as the
  existing encrypted-credential work (see `operations-system-upgrade.md`
  §2.1).

**Effort:** ~5–7 days, most of it the credential encryption + CRUD, not the
engine wiring.

---

## 6. Real parallel split / join / subprocess execution

**The gap:** `Engine::advance()` (`src/Processes/Runtime/Engine.php:191-209`)
handles `flow.parallel_split`, `flow.join`, and `flow.subprocess` identically:
take the first outgoing edge, record the execution as simulated with an
explicit note, and move on. The engine models exactly one "current node" per
instance — there's no structural way today to be in two branches
simultaneously.

**Approach:** this is the largest single piece of remaining work and
probably the last thing to schedule, not the first:
- Requires `process_instances` (or a new child table,
  e.g. `process_instance_branches`) to track multiple concurrently-active
  node pointers per instance instead of one `current_node_id`.
- `flow.join` needs join semantics (wait for N of M branches vs. all
  branches) and needs to reconcile variables written independently by
  parallel branches.
- `flow.subprocess` needs to spawn a real nested `process_instances` row
  linked to a parent instance/node, and resume the parent when the child
  reaches an end node — closer in shape to item 2's trigger wiring than to
  a simple engine tweak.
- Recommend doing this only after items 1–5 are live and at least one real
  process has needed true parallelism in practice — it's easy to over-design
  this speculatively.

**Effort:** ~1–2 weeks; the riskiest and least well-specified item here.

---

## 7. Real deposit collection

**Current state:** deliberately simulated. Real deposit collection already
works today through the existing Stripe Payment Link flow
(`Events\Payments.php::sendPaymentLink()`), just not from inside a process.

**Approach:** register a `payments.request_deposit` handler in
`BookingHandlers.php` that calls the same `sendPaymentLink()` path used by
the Payments tab today (reuse it, don't reimplement Stripe calls in the
engine), keyed to the case's linked `entity_id`. Needs an idempotency check
so a retried step can't send a second payment link for the same instance.

**Effort:** ~2–3 days, contingent on confirming this is wanted — the
original pass skipped it specifically because of "financial-consequence
risk," so get explicit sign-off before wiring real money movement through a
newer, less-proven code path.

---

## 8. Real settlement

**Current state:** simulated — and blocked, not just undone. Per
`BookingHandlers.php:53`, "no existing 'run settlement' primitive was found
to call into." This isn't an Automation-module gap so much as a
venue-operations gap: settlement itself doesn't exist as a first-class
action anywhere in the app yet (see `event_closeout_state` /
`event_ledger_entries` finalization in `operations-system-upgrade.md` for
the closest existing concept).

**Approach:** out of scope for the Automation module alone. Once a real
settlement/closeout action exists elsewhere in the app, wiring
`events.run_settlement` into `BookingHandlers.php` is then the same small
shape as item 7.

**Effort:** not estimable until the underlying settlement feature is scoped.

---

## 9. AI steps

**Current state:** `ai.decision` is handled as a plain decision (no model
call — same `evaluateDecision()` path as `flow.decision`); other `ai.*` node
types (e.g. Classify Inquiry) fall through to the generic simulated-operation
handler like any unregistered operation.

**Approach:** register real handlers in a new `AiHandlers.php` alongside
`BookingHandlers.php`, calling out to whatever model/provider this app
standardizes on elsewhere (check `claude-api` conventions already used in
this codebase before picking a client library). Since AI was explicitly
called out as optional in the original spec, this can slot in independently,
any time after item 5 (Connections) exists, if the model call needs an API
key stored as a named connection rather than a raw config value.

**Effort:** ~3–5 days for one or two real handlers (e.g. Classify Inquiry);
more per additional AI step type.

---

## 10. Email auto-send — explicitly not a gap

Flagging this so it doesn't get "fixed" by accident: every email-shaped step
in Automation is designed to produce a pre-filled Gmail compose link, never
to call a mailer directly (`BookingHandlers.php:39-43`, reaffirmed in the ops
manual as a cross-cutting rule, not a per-process choice). Don't wire real
sending here without a deliberate product conversation first — this is
different in kind from items 1–9, which are unfinished pieces of a spec
this module already commits to.

---

## Suggested sequencing

1. **Item 1** (decision wiring) — it's a live bug with existing data, do it
   first regardless of anything else.
2. **Items 3 & 4** (Cases, Activity) — pure read-side aggregation over
   existing tables, no new risk, and they make every other change in this
   list observable once shipped.
3. **Item 2** (automatic triggers) — needs a product decision on the opt-in
   model before engineering starts.
4. **Item 5** (Connections) — do this before item 7 or 9 if either needs a
   real external credential.
5. **Items 7, 9** (deposits, AI) — each independent, each needs explicit
   sign-off given financial/AI-accuracy risk respectively.
6. **Item 6** (real parallelism) — last; biggest engine change, best
   informed by real usage of everything above.
7. **Item 8** (settlement) — blocked on a separate, non-Automation feature.

---

*Last updated: 2026-07-22*
*Owner: Christopher Robison*
