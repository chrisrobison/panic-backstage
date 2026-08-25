# Opportunities Module — Implementation Handoff

**Status:** Phase 2 complete — nav + the Discover dashboard (first visible
UI) shipped on top of Phase 1's backend. See §4.1 (Phase 1) and §4.2
(Phase 2) for exactly what landed.
**Branch:** `opportunities-module` (long-lived feature branch; not merged to `main`
until the module is stable — see "Branch strategy" below). Do not squash/delete
mid-project; each phase adds commits here.
**Read this file first in any new session working on Opportunities.** It is the
persistent cross-phase memory the spec asked for. Update it at the end of every
phase — architecture decisions, migrations added, endpoints added, UI components
added, open TODOs, tests added, known issues, assumptions.

---

## 0. What this module is

An outbound/private-event sales CRM bolted onto Panic Backstage: discover
conferences → find target companies/sponsors → track buyer contacts → run a
sales pipeline (Opportunities) → convert a won opportunity into exactly one
ordinary Backstage `event`. It is a **new, distinct pre-inquiry stage**
prepended to the existing product spine documented in
`docs/PRODUCT-BOUNDARIES.md`:

```text
Prospecting (Opportunities)  ←  NEW, this module
   → Inquiry (leads)
   → Event
   → Contract/payment
   → Execution
   → Settlement
```

Opportunities owns prospect/pipeline truth. It must never become a second
event, contact, or task system — it converts into the existing ones at its
boundary, same as Leads → Events onboarding today. Full product spec is the
task prompt this file's project was born from; the terminology, phase list,
and acceptance criteria there are authoritative — this file exists to pin
down *how* each phase attaches to the actual codebase.

Reference UI mockups: `docs/opportunity-ui/opportunity-1.png` … `-6.png`
(Discover dashboard, Conference detail, Company detail, Opportunity detail,
Pipeline/Kanban, Research Notes workspace). Treat them as conceptual
direction only — do not hard-code SF/Dreamforce/Mabuhay specifics from them.

---

## 1. Existing patterns (facts gathered this phase, all cited)

### 1.1 API routing — `src/Kernel.php`

No route table — `Kernel::resolve()` (`src/Kernel.php:126-889`) hand-dispatches
on `$segments[0]` via a long `if` chain, returns `[EndpointClass::class,
$params]`; `handle()` (`:38-94`) does `new $class($db, $auth, $params,
$root)` then `->handle($request)`. Kernel enforces "must be logged in"
globally (`:82-84`) unless the path is in `isPublic()` (`:895-921`);
**capability checks happen inside each endpoint's `handle()`**, not in
Kernel.

Flat top-level resource precedent (`leads`, `src/Kernel.php:429-447`):

```php
if ($segments[0] === 'leads') {
    $leadId = $this->intOrNull($segments[1] ?? null);
    $child  = $segments[2] ?? null;
    if (in_array($child, [...known sub-resources...], true)) {
        return [LeadsInbox::class, ['leadId' => $leadId, 'child' => $child]];
    }
    $childId = $this->intOrNull($segments[3] ?? null);
    return [Leads::class, ['leadId' => $leadId, 'child' => $child, 'childId' => $childId]];
}
```
Same shape at `contacts` (`:242-251`) and `crm-profiles` (`:474-479`). This
is the pattern Opportunities routes will copy — one `if` block per top-level
resource family, each endpoint class doing its own `match ($request->method())`
+ `child` dispatch internally (see `src/Leads.php:59-65`).

### 1.2 `src/BaseEndpoint.php`

Constructed `(Database $db, Auth $auth, array $params, string $root)`.
Gives: `userId()`, `ok()`/`notFound()`/`forbidden()` JSON response helpers,
`role()`/`isVenueAdmin()`/`isGlobalViewer()`, global-capability helpers
`hasGlobalCapability()`/`requireGlobalCapability()`, event-scoped
capability/access helpers, `eventScopeSql()`/`leadScopeSql()` row-level WHERE
builders. **No generic pagination or body-validation helpers exist** —
every endpoint reads `$request->body()`/`query()` and validates by hand.
Opportunities endpoints will follow that norm (no new shared validation
framework).

### 1.3 Capabilities — `src/Capabilities.php`

Hardcoded PHP const arrays, **not DB rows**: `GLOBAL_CAPABILITIES` (role →
list of capability strings, `:97-154`), `EVENT_CAPABILITIES` (per-event-role,
`:28-78`). Checked via `Capabilities::hasGlobal($role, $cap)`. **There is no
admin UI that assigns capabilities to roles** — roles are a fixed MySQL enum
(`users.role`: `venue_admin, event_owner, promoter, band, artist, designer,
staff, viewer, global_viewer`) and each role's capability list is a literal
array in this file. The only UI surface capability *names* are exposed
through is `nav-manager.js`'s `capabilityKeys` list, used to gate a nav
item's visibility (`nav_items.capability` column, checked in
`filterNavTree()`, `public/assets/nav-shared.js:31-36`).

**Implication for "seed/admin UI must expose the new capabilities"**: there
is no capability-assignment admin screen to update — adding
`view_opportunities`/`manage_opportunities`/`research_opportunities` means
(a) adding the three string keys to the right roles' arrays in
`Capabilities.php`, and (b) adding them to `nav-manager.js`'s capability
picker list so a future nav-item edit can reference them. Both are Phase 1
work.

Proposed initial grant (adjustable, document any change here when made):
| Capability | Roles |
|---|---|
| `view_opportunities` | `venue_admin`, `event_owner`, `staff` |
| `manage_opportunities` | `venue_admin`, `event_owner` |
| `research_opportunities` | `venue_admin` |

### 1.4 Migrations

`database/migrations/NNN_description.sql`, forward-only (no down), applied
by `php scripts/migrate.php` (tracked per-DB in `schema_migrations` by full
filename — safe even though two `105_*` files already coexist). Every
statement must be idempotent (`CREATE TABLE IF NOT EXISTS`, guarded `ADD
COLUMN`) since MySQL DDL auto-commits.

Files on disk today go up to `108_add_physical_ticket_batches.sql`
(`README.md`'s stated "next: 106" is stale).
**Next migration number to use: `109`.**

`database/schema.sql` is a periodically-resquashed `mysqldump` snapshot, not
touched per-migration — leave it alone; someone re-squashes it separately.

### 1.5 Frontend shell / router — `public/index.html`, `public/assets/app.js`

`<pb-app-shell>` is the only body element; `app.js` (loaded `type="module"`)
is the real entry point and statically imports ~20 feature modules. No
import map, no build step — plain relative ES module imports.

`AppShell.route()` (`app.js:422-517`) is the whole router: reads
`location.hash`, if/else-chain on `route === 'x'` / `route.startsWith('x-')`
/ regex, each branch calls `this.mount(outlet, tagName, props)`, where
`mount()` does `document.createElement(tagName); Object.assign(element,
props); outlet.replaceChildren(element)` (props become JS instance
properties, not HTML attributes). Example precedent for a detail route with
an id param (`app.js:459-461`, events):

```js
const eventTabMatch = route.match(/^event-(\d+)-([a-z-]+)$/);
if (eventTabMatch) return this.mount(outlet, 'pb-event-workspace',
    { eventId: Number(eventTabMatch[1]), initialTab: eventTabMatch[2] });
if (route.startsWith('event-')) return this.mount(outlet, 'pb-event-workspace',
    { eventId: Number(route.slice(6)) });
```

`navKeyForRoute()` (`app.js:396-411`) maps a route back to the nav-highlight
key — needs a case added for Opportunities' sub-routes.

### 1.6 Nav rendering

DB-driven: `nav_items` table (`id, parent_id, label, icon, link, capability,
open_in_new_window, visible, is_home, sort_order`, `database/schema.sql:1509-1525`),
fetched in `AppShell.connect()`, filtered by `filterNavTree()`
(`nav-shared.js:31-36` — drops an item, and its now-childless parent, unless
`capabilities[item.capability]` is truthy), rendered by `renderNavHtml()`.
Adding the Opportunities nav section = inserting `nav_items` rows (parent
"Opportunities" + children Discover/Conferences/Companies/Pipeline/Notes),
each gated `capability = 'view_opportunities'`. Do this via a migration
(`INSERT ... ON DUPLICATE KEY` style guard, or check-then-insert) — no admin
UI needed to *create* items, `nav-manager.js` already lets an admin edit them
afterward.

### 1.7 Shared JS primitives — `public/assets/core.js`

- `api(path, options)` — routes through a Web Worker data client when
  eligible else `fetch`; 401 → refresh-and-retry once else redirect to
  login; non-OK → publishes `api.error` and throws. Callers try/catch.
- `esc()` — HTML-escape, mandatory for any AI/web-sourced string rendered
  into a template (Phase 7 note: **all research output is untrusted and
  must go through `esc()`**, never innerHTML'd raw).
- `publish(topic, payload)` / `subscribe(topic, handler, signal)` — PAN/LARC
  bus. Existing channel names include `event.saved`, `event.changed`,
  `data.invalidated`, `toast.show`, `api.error`. `subscribe` takes an
  `AbortSignal` for auto-cleanup (`this.abort.signal`).
- `openModal({ title, bodyHtml, wide, focus })` → `{ dialog, close }` — the
  add/edit/view modal convention (see project memory `ui-conventions.md`:
  this is the *default going forward*).
- `PanicElement` base class: `connectedCallback` builds
  `this.abort = new AbortController()` then calls `this.connect?.()`;
  `disconnectedCallback` aborts it. Gives `setLoading()`/`showError()`. No
  built-in `.data` setter convention — components implement their own ad hoc
  (`refreshSection()` pattern, `core.js:356-362`, does `component.data =
  data` to trigger re-render).
- Reusable CSS already in `app.css`, reuse rather than reinvent:
  `.data-table`, `.kpi-card`/`.kpi-icon`/`.kpi-label`/`.kpi-value`
  (dashboard summary cards), `.modal-card`/`.modal-backdrop`
  (`openModal()`'s shell), `.section-head`, `.panel`/`.panel-body`,
  `.badge` (+ `.success`/`.info`/`.warning`/`.error` tones), `.pill`, and —
  important for Phase 5 — **`.pipeline-board`** (`app.css:886`, plus
  responsive variants), the existing kanban CSS already used by
  `pb-pipeline-board` for the events pipeline. Reuse it for the
  Opportunities Kanban rather than inventing new board CSS.

### 1.8 Module shape precedent — `leads.js`, `contacts.js`, `tasks/`

`leads.js`: three custom elements in one file (`pb-leads-page` list,
`pb-lead-modal` tabbed detail modal, `pb-lead-form` inline create),
registered together at file end. `contacts.js`: one page element
(`pb-contacts-page`), hand-built modal (predates `openModal()` — don't copy
that part). Both fetch via `api()`, render `esc()`-escaped template strings
into `<table class="data-table">`.

**Directory-split precedent** (directly matches the spec's suggested
`public/assets/opportunities/` layout): `public/assets/tasks/` —
`tasks-shell.js` (root/shell, owns state, imports the rest),
`task-list-view.js`, `task-board-view.js`, `task-timeline-view.js`,
`task-calendar-view.js` (one element per tab), `task-detail-panel.js`
(slide-over), `task-shared.js` (constants/helpers). Convention per that
file's header comment: children get read-only `.data` from the shell, talk
back via bubbling `CustomEvent`s (`task-open`, `task-changed`, ...) — "child
calls the API itself, parent just reacts to an event." `inbox/` and
`processes/` use the same directory shape. **This is the pattern
Opportunities will follow** — a shell + one file per screen + a shared
helpers file, imported into `app.js` as one new `import
'./opportunities/opportunities-shell.js';` line.

### 1.9 Realtime invalidation

`db_history` trigger → SSE (`src/Realtime.php`) → Web Worker → `core.js`
republishes `publish('data.invalidated', { entity, id, revision })`.
`src/RealtimeInvalidationMapper.php` maps table → entity name; currently only
`events`→`event`, `leads`→`lead` exist in its `DIRECT` map, everything else
falls back to `{entity:'global'}`. **Opportunities tables are not mapped —
Phase 8 (or earlier, opportunistically) must add `opportunities`→`opportunity`
and child-table→parent-id entries to that mapper**, or new panels simply
won't live-refresh (fails open, not a hard blocker, but listed as a known
gap until then). Subscription pattern to copy
(`event-workspace.js:612-616`): filter by `msg.entity`+`msg.id`, debounce
~250ms, then re-`api()`-fetch — never trust the pushed payload as data.

### 1.10 Leads → Event conversion (the direct precedent for Convert-to-Event)

`leads.converted_event_id` (FK, `ON DELETE SET NULL`) + `converted_at` +
`status = 'converted'` enum value. `Leads::convert()`
(`src/Leads.php:581-622`) requires `manage_leads`, rejects if already
converted or status not in an allowed set, then calls
`Leads\Onboarding::createEventFromLead()`
(`src/Leads/Onboarding.php:119-200`). **The real double-conversion guard is
inside that method**: a transaction does `SELECT ... FOR UPDATE` re-checking
`converted_event_id` is still empty before inserting the event
(`Onboarding.php:142-150`) — the caller's pre-check alone is not
authoritative, and Opportunities' conversion must copy this same
lock-then-recheck-then-insert shape, not just a pre-flight check. It prefills
`events` fields from the lead (title, slug, event_type, date,
promoter/booker contact fields, `client_org`, `estimated_guests`,
`description_internal`, `lead_id` FK, `owner_user_id`, status `proposed`),
then updates the lead's conversion columns, writes an audit note, and calls
`log_activity()`. Opportunities will mirror this exactly:
`opportunities.won_event_id` + `converted_at`, a
`OpportunityConversion::createEventFromOpportunity()` service doing the same
locked re-check, prefilling from opportunity + company + conference fields.

### 1.11 Tasks — no generic polymorphic link exists

Two separate, non-polymorphic task systems:
- `event_tasks` — plain `event_id` FK, served by `src/Events/Tasks.php`.
- Standalone Tasks app: `tasks` table has `document_id` FK →
  **`task_documents`** (`id, name, icon, color, status enum(on_track, at_risk,
  off_track, complete), starred, owner_user_id, sort_order, archived_at,
  ...` — `database/schema.sql:2254-2269`), served by `src/Tasks/Items.php`,
  routed `/api/task-documents/{docId}/tasks`.

Closest *polymorphic* precedent anywhere is `process_instances.entity_type`
(varchar) + `entity_id`, used by `src/Processes/EntityInstances.php` — but
that's a different subsystem (workflow instances), not reusable as-is for
plain tasks.

**Decision:** do not add a new tasks table, and do not bolt polymorphic
columns onto `tasks`/`event_tasks`. Instead, **lazily provision one
`task_documents` row per opportunity/conference/company the first time a
task is created from it**, store its id on the owning row
(`opportunities.task_document_id`, `opportunity_conferences.task_document_id`,
`opportunity_companies.task_document_id`, all nullable), and drive all task
CRUD through the **existing, unmodified** `/api/task-documents/{id}/tasks`
endpoints. "Open Tasks" panels just read that one document's tasks. This
satisfies "a task created from a conference must still be an ordinary
Backstage task" literally — it's the exact same `tasks` row type, `Tasks`
UI, and API surface used everywhere else, zero schema changes to Tasks.

### 1.12 Notes — no generic notes engine, always a dedicated FK'd table

`lead_notes` (FK `lead_id`) and `client_notes` (FK `profile_id`) are the
precedent — both: `type` enum, `body text`, `is_done`, `due_date`,
`user_id`, timestamps. **No shared/reusable notes table exists.** This
matches the spec's own instruction to implement first-class
`opportunity_notes` persistence (not reuse of an existing engine), but with
one addition the precedent doesn't have: Opportunities notes must attach to
*multiple* record kinds (conference, company, contact, opportunity) per
the spec's linking requirement, and to multiple records per note (the
"Dreamforce 2026 Sponsorship Strategy" example links a conference + company
+ contact + opportunity, all four). A single FK column (like
`lead_notes.lead_id`) can't express that — see §3 table design
(`opportunity_notes` + `opportunity_note_links`).

### 1.13 AI / Claude CLI integration — `src/Ai/Assistant.php`

Fully synchronous today (`POST /api/ai/ask` blocks on the subprocess, no job
queue involved for the AI call itself) — **Phase 7 must NOT copy that**;
the spec explicitly requires background jobs for research, and no
"AI research" job type currently exists to copy (closest is
`src/Leads/Classifier.php`'s classification call, which itself runs
synchronously inside a `public_inquiry_followup` job handler — i.e. AI-in-a-
job-handler is fine, but there's no existing dedicated job-type
abstraction).

Subprocess pattern to reuse/refactor (`Assistant::runClaude()`,
`src/Ai/Assistant.php:478-615`): `exec()` (not `proc_open`) of

```
env -u ANTHROPIC_API_KEY -u ANTHROPIC_API_KEY_FILE HOME=<pinned> \
  timeout --signal=KILL <N>s <CLAUDE_CLI_BIN> \
  -p <message> --output-format json --no-session-persistence \
  --tools '' --mcp-config <tmp>/mcp-config.json --strict-mcp-config \
  --allowedTools <space-separated mcp__panic__* names> \
  --permission-mode bypassPermissions \
  --append-system-prompt-file <tmp>/system-prompt.txt \
  --model <model> [--max-budget-usd N] </dev/null 2>&1
```
— isolated 0700 temp dir (mcp config + system prompt, deleted in `finally`),
`HOME` pinned via `CLAUDE_CLI_HOME`, hard `timeout --signal=KILL` +
PHP-side `set_time_limit`, stdin from `/dev/null`, every variable through
`escapeshellarg()`, strict `--tools ''` (no Bash/Read/Write/Edit — only
explicitly allowlisted `mcp__panic__*` MCP tools, `--strict-mcp-config`
blocks any other MCP server), output parsed as one JSON blob and validated
(`is_array`, string `result`, `!is_error`) before use, rate-limited via
`RateLimiter::tooMany()`. Env vars already available:
`CLAUDE_CLI_BIN` (default `/home/cdr/.local/bin/claude`), `CLAUDE_CLI_HOME`
(default `/home/cdr`), `AI_ASSISTANT_MODEL` (default `sonnet`),
`AI_ASSISTANT_TIMEOUT_SECONDS` (default 60), `AI_ASSISTANT_MAX_BUDGET_USD`.

**Nothing in the codebase today exercises `WebSearch`/`WebFetch` tool
names** — every existing invocation passes `--tools ''` and only ever
allowlists `mcp__panic__*` MCP tools. Phase 7 will need to confirm the
installed `claude` CLI version's exact built-in tool names (run `claude
--help` / check release notes at that time — do not guess) before wiring
`--allowedTools WebSearch WebFetch` alongside a new, read-only
`mcp__panic-opportunities__*` MCP tool set (a second, narrower MCP server —
`scripts/ai-mcp-server.php` is a precedent, but *do not* just add
Opportunities tools to that existing server, since its tool set is
purpose-built + already capability-scoped for the booking assistant; a
sibling `scripts/ai-opportunities-mcp-server.php` keeps blast radius
contained per the "strictly controlled" requirement).

Existing DB precedent for AI-vs-human provenance: `lead_classifications`
(`source enum('ai','human_correction')`, `model`, `prompt_version`,
`is_current` — a **versioned-row** pattern, not a boolean flag,
`database/migrations/074_add_booking_inbox_classification.sql:10-32`).
Stronger than a plain boolean; worth following for `opportunity_notes`'
revision history (Phase 6) even though `opportunity_signals` itself can use
a simpler `is_ai_generated` boolean (signals aren't edited/revised, notes
are).

Background job queue precedent: generic `background_jobs` table
(`id, queue, job_type, payload_json, unique_key UNIQUE, status
enum(pending,processing,completed,failed), attempts, max_attempts,
available_at, locked_at, locked_by, last_error, completed_at, ...`,
`database/migrations/085_add_background_jobs.sql`), reserved via `SELECT ...
FOR UPDATE SKIP LOCKED`, dispatched via a hardcoded `match($job_type)` in
`JobWorker::dispatch()`. **It has no result-payload column** — existing job
types write their effect directly to their own domain tables. Since
Opportunities research jobs need to return a structured, poll-able result,
Phase 7 will add a dedicated `opportunity_research_jobs` domain table (own
`status`/`result_json`/`error` columns) and enqueue a `background_jobs` row
with `job_type = 'opportunity_research'` whose handler loads that row,
which is exactly the `PublicInquiry.php` pattern of enqueueing inside the
same transaction as the domain row it describes, keyed by a `unique_key`
for idempotency.

### 1.14 OpenAPI + route-contract check

`docs/openapi.yaml` — one 29k-line file, flat `paths:` map, `tags:` per
operation, shared schemas under `components/schemas`. Every operation
documents its required capability in prose + always documents `401`/`403`.
`scripts/check-openapi-routes.php` (part of `scripts/static-analysis.sh`)
verifies via reflection on `Kernel::resolve()` (no DB needed): every
documented path resolves to a real class, no dup paths/operationIds, and —
important — **every Kernel top-level route family needs at least one
documented `/api/{family}` path** (reverse coverage check). New
Opportunities route families must each get a `/api/{family}` entry in the
spec or CI fails.

### 1.15 Contacts model — the "important decision"

`contacts` (`database/schema.sql:238-266`) is explicitly a **B2C
ticket-buyer marketing audience table**, per its own docblock: "the
audience that buys tickets and receives event emails, seeded from the
ticketing provider's Fan View export." Fields: name/email/phone/gender/
birthday, `events_count`/`tickets_count`/`usd_spend` (purchase history),
`marketing_opted_in`, tag/list membership. Dedup key is `(source,
external_id)` — **email is only a plain index, not unique**, so there's no
existing safe email-identity dedup to lean on. No company/org, job title,
department, LinkedIn, or relationship-status field exists, and no FK links
`contacts` to `leads` or `events` at all — by contrast, `leads` itself
stores business-contact info as flat denormalized columns
(`contact_name`, `contact_email`, `contact_org`, `contact_phone`) directly
on the deal, with no FK into `contacts`. Capability gate is a single
`manage_contacts` (no read-only tier). `docs/PRODUCT-BOUNDARIES.md`
explicitly calls Contacts a *supporting* module ("supplies identity/history
to inquiry routing and campaigns"), not part of the booking spine.

**Decision: do not extend `contacts`.** Reasons: (1) semantically wrong —
corporate buyer contacts (Field Marketing Director at NVIDIA) are not
ticket-buying fans and stuffing B2B CRM fields onto a B2C marketing-audience
table would corrupt its purpose and its `usd_spend`/`tickets_count`/opt-in
semantics; (2) unsafe — no unique email constraint means the existing dedup
story (`source`+`external_id`) doesn't transfer, and the spec explicitly
wants normalized-email dedup; (3) the existing `leads` precedent already
shows this codebase's answer to "business contact for a deal" is a
dedicated table/columns scoped to the deal domain, not a link into
`contacts`. **Use a new `opportunity_contacts` table** (§3), scoped to a
`company_id`, with `email` normalized + unique *within that company*, plus
a link table (`opportunity_decision_makers`) for opportunity↔contact role
assignment (champion/influencer/decision_maker/finance/blocker/other). This
is the "linking/profile table" fallback the spec explicitly allows when
extending Contacts is unsafe.

### 1.16 Test infrastructure

- `tests/run-php-tests.sh` runs `tests/*_test.php`, hermetic by default (a
  `DB_TESTS` array opts specific scripts into `RUN_DB_TESTS=1`), pure-logic
  assertions (e.g. scoring math) fit here well.
- `run-tests.sh` runs `tests/[0-9]*.sh` against a live server via
  `tests/lib.sh` helpers (`http_get`, `assert_status`, `json_get`).
- `tests/ui/run.mjs` runs `tests/ui/*.test.mjs` over real headless
  Chromium/CDP against a throwaway local PHP server pointed at the
  production DB — the right tool for Kanban drag-drop, modal, and
  conversion-flow tests. Existing tests favor deriving fixtures from
  already-seeded data and `page.skip()`-ing when data is insufficient
  rather than always creating/tearing down; Opportunities tests that mutate
  data should still follow the project-wide throwaway-fixture-in-`finally`
  convention from `dev-environment.md` memory (title-prefix, delete after).

### 1.17 Global search

`public/assets/search-results.js` only searches events (`GET /events?q=`)
— there is no unified cross-entity search endpoint/component today.
"Extend global search" (Phase 9) means either adding Opportunities' own
`?q=` filters to its list endpoints (straightforward, same as Contacts does
locally) and/or a heavier follow-up to genuinely unify search later — not
in scope to build a new unified search engine for this module alone.

---

## 2. Branch strategy

This checkout is the live production docroot (no staging exists — see
project `dev-environment.md` memory). Given the module spans ~10 phases
across many sessions, work happens on **`opportunities-module`**, a
long-lived feature branch, rather than landing directly on `main` per phase.
`main` stays exactly as it is today until the module is reviewed and ready;
merge (regular merge or squash, TBD at that time) happens once, deliberately,
not automatically at phase boundaries. Each phase still gets its own
small, verified, Conventional-Commits commit(s) *on this branch*. Rebase
onto `main` periodically if `main` moves during the project.

---

## 3. Proposed module architecture

### 3.1 Tables (all new, migrations starting at `109`)

| Table | Purpose | Key columns beyond spec's field list |
|---|---|---|
| `opportunity_conferences` | Conference/trade-show source-of-demand records | `slug` unique, `latitude`/`longitude` nullable (no distance without them), `distance_from_venue_miles` computed+cached, `task_document_id` nullable FK |
| `opportunity_companies` | Prospect companies | `domain` normalized+unique for dedup, `task_document_id` nullable FK |
| `opportunity_conference_companies` | Conference↔company participation | unique `(conference_id, company_id)`, `role` enum |
| `opportunity_contacts` | Corporate buyer contacts (NOT `contacts`) | `company_id` FK, `email` normalized, unique `(company_id, email)` where email present |
| `opportunities` | The sales pipeline record | per spec field list; `task_document_id`, `won_event_id` FK → `events.id` `ON DELETE SET NULL`, `converted_at` |
| `opportunity_decision_makers` | Contact↔opportunity role link | unique `(opportunity_id, contact_id)`, `role` enum |
| `opportunity_signals` | Research/buying signals | nullable `company_id`/`conference_id`/`opportunity_id` (at least one required, enforced in PHP not SQL), `is_ai_generated` |
| `opportunity_notes` | First-class notes | `body` (markdown, sanitized on render not on write), `note_type` enum, `is_pinned`, `is_ai_generated`, `ai_model`/`ai_prompt_version` nullable |
| `opportunity_note_versions` | Immutable revision history (Phase 6) | previous `body`, `edited_by`, `edited_at` — append-only, mirrors `lead_classifications`' versioned-row spirit |
| `opportunity_note_links` | Polymorphic note↔record linking | `note_id`, `linked_type` enum(`conference`,`company`,`contact`,`opportunity`), `linked_id`; unique `(note_id, linked_type, linked_id)` |
| `opportunity_activities` | Audit/activity feed | `opportunity_id`, `activity_type` enum, `payload_json`, `created_by`, `created_at` |
| `opportunity_qualification` | Qualification checklist state | one row per opportunity, boolean per checklist item (or a small `opportunity_qualification_items` link table if the item list needs to be data-driven later — start with fixed boolean columns per spec's fixed 9-item list, simplest thing that works) |
| `opportunity_research_jobs` | Durable AI research job tracking (Phase 7) | `job_type` enum (discover_conferences, research_conference, find_target_companies, research_company, research_side_events, generate_outreach_angles), `status`, `input_json`, `result_json`, `error`, `requested_by`, timestamps; enqueues into existing `background_jobs` with `job_type='opportunity_research'` and a `unique_key` back-reference |

Not creating (per spec + recon): no new Tasks table (§1.11), no new Contacts
table beyond `opportunity_contacts` which is intentionally NOT a
general-purpose contacts replacement, no new Events table, no new generic
notes engine (this *is* the first one, scoped to this module only — it does
not attempt to replace `lead_notes`/`client_notes`).

### 3.2 Compact relationship diagram

```text
opportunity_conferences ──< opportunity_conference_companies >── opportunity_companies
        │                                                              │  │
        │                                                              │  └──< opportunity_contacts
        │                                                              │            │
        └──────────────────────┐                                      │            │
                                ▼                                      ▼            ▼
                          opportunities ──────────────────────────────────< opportunity_decision_makers
                                │  │  │                                            (role: champion/
                                │  │  └── won_event_id ──> events (existing)         decision_maker/...)
                                │  └── task_document_id ──> task_documents (existing)
                                │
                                ├──< opportunity_activities
                                ├──< opportunity_qualification (1:1)
                                └──< opportunity_signals >── (optional FK to conference / company / opportunity)

opportunity_notes ──< opportunity_note_links >── {conference | company | contact | opportunity}
       │
       └──< opportunity_note_versions

opportunity_research_jobs ──(enqueues)──> background_jobs (existing generic queue)
```

### 3.3 PHP classes (namespacing follows existing flat `src/` + subfolder convention)

```text
src/Opportunities.php                    top-level CRUD + /api/opportunities/dashboard
src/Opportunities/Conferences.php        opportunity-conferences CRUD
src/Opportunities/Companies.php          opportunity-companies CRUD
src/Opportunities/ConferenceCompanies.php  link CRUD
src/Opportunities/Contacts.php           opportunity_contacts CRUD (buyer contacts)
src/Opportunities/Notes.php              notes CRUD + linking + versions
src/Opportunities/Signals.php            signals CRUD (mostly read + AI-import write)
src/Opportunities/Activities.php         read-only activity feed
src/Opportunities/Qualification.php      checklist state
src/Opportunities/Conversion.php         convert-to-event (mirrors Leads\Onboarding)
src/Opportunities/Scoring.php            deterministic scoring service (Phase 8, pure/testable)
src/Opportunities/Availability.php       venue-availability-match + empty-night calc (Phase 2/8)
src/Opportunities/Research/*.php         Claude CLI research job handlers (Phase 7)
```

### 3.4 JS modules

```text
public/assets/opportunities/
  opportunities-shell.js     root element, nav sub-tabs, imports the rest
  discover-page.js           Phase 2
  conferences-list.js        Phase 3
  conference-detail.js       Phase 3
  companies-list.js          Phase 4
  company-detail.js          Phase 4
  pipeline-board.js          Phase 5 (reuse .pipeline-board CSS)
  opportunity-detail.js      Phase 5
  notes-workspace.js         Phase 6
  ai-research-panel.js       Phase 7
  opportunities-shared.js    constants, badge/score renderers, esc()'d templates
```
One new `import './opportunities/opportunities-shell.js';` line added to
`app.js`'s existing import block.

### 3.5 Routes (hash, added to `AppShell.route()`)

```text
#opportunities                     → pb-opportunities-discover (default landing under the shell)
#opportunities-conferences         → pb-opportunities-conferences-list
#opportunities-conference-{id}     → pb-opportunities-conference-detail
#opportunities-companies           → pb-opportunities-companies-list
#opportunities-company-{id}        → pb-opportunities-company-detail
#opportunities-pipeline            → pb-opportunities-pipeline
#opportunities-{id}                → pb-opportunities-detail
#opportunities-notes               → pb-opportunities-notes
#opportunities-note-{id}           → pb-opportunities-notes (with initial selection)
```
Matches the existing `event-{id}`, `event-{id}-{tab}` regex-then-prefix
fallback shape.

### 3.6 API routes (Kernel top-level families, each needs an OpenAPI `/api/{family}` entry per §1.14)

```text
opportunities                → src/Opportunities.php (list/create; /dashboard; /{id}; /{id}/convert; /{id}/notes; /{id}/signals; /{id}/activities; /{id}/qualification; /{id}/decision-makers)
opportunity-conferences      → src/Opportunities/Conferences.php (+ /{id}/companies, /{id}/notes)
opportunity-companies        → src/Opportunities/Companies.php (+ /{id}/contacts, /{id}/notes)
opportunity-contacts         → src/Opportunities/Contacts.php
opportunity-notes            → src/Opportunities/Notes.php (cross-cutting note list/search, versions)
opportunity-research         → src/Opportunities/Research/Jobs.php (Phase 7: POST .../jobs, GET .../jobs/{id})
```
`GET /api/opportunities/availability-prospects` (Phase 8) hangs off the
`opportunities` family as a computed/bulk action, same convention as
`tasks/from-template` etc. in `dev-environment.md` memory (an `if` block
before the generic `match`, keyed on a non-numeric segment).

### 3.7 Capabilities

`view_opportunities`, `manage_opportunities`, `research_opportunities` — see
§1.3 table. Added to `Capabilities::GLOBAL_CAPABILITIES` + surfaced in
`nav-manager.js`'s capability picker.

### 3.8 Background jobs

`job_type = 'opportunity_research'` added to `JobWorker::dispatch()`'s
`match`, payload references an `opportunity_research_jobs.id`. See §1.13 /
§3.1.

---

## 4. Phase status

| Phase | Status | Notes |
|---|---|---|
| 0 — Recon & plan | **Done** (this doc) | |
| 1 — DB/capabilities/API skeleton | **Done** | See §4.1 below |
| 2 — Nav + Discover dashboard | **Done** | See §4.2 below |
| 3 — Conference list/detail | Not started | |
| 4 — Company list/detail + buyer contacts | Not started | |
| 5 — Pipeline + Opportunity detail + conversion | Not started | |
| 6 — Notes workspace | Not started | |
| 7 — Claude CLI research | Not started | |
| 8 — Tasks/activities/realtime/scoring/availability | Not started | |
| 9 — Polish/tests/docs/perf/a11y | Not started | |

---

### 4.1 Phase 1 — what actually shipped

**Migration:** `database/migrations/109_add_opportunities_module.sql` — 10
new tables: `opportunity_conferences`, `opportunity_companies`,
`opportunity_conference_companies`, `opportunities`, `opportunity_signals`,
`opportunity_notes`, `opportunity_note_links`, `opportunity_note_tags`,
`opportunity_activities`, `opportunity_research_jobs` (schema-only stub,
unused until Phase 7). Applied to the live DB; audit triggers regenerated
(`php scripts/generate-audit-triggers.php`) so every write lands in
`db_history` like every other table.

**Deliberately deferred, contrary to §3.1's full list** (that section is the
whole-module target architecture; this phase followed
`docs/opportunity-ui/opportunity-ui.txt`'s own narrower Phase 1 table list,
which omits these): `opportunity_contacts` and `opportunity_decision_makers`
(Phase 4/5 — buyer contacts don't exist yet, so `opportunities` has no
`primary_contact_id` column yet either; add it alongside
`opportunity_contacts`), `opportunity_qualification` (Phase 5, checklist
UI), `opportunity_note_versions` (Phase 6, revision history — the doc's own
§3.1 already scoped this to Phase 6).

**Capabilities** (`src/Capabilities.php` `GLOBAL_CAPABILITIES`): added
`view_opportunities` (venue_admin, event_owner, staff), `manage_opportunities`
(venue_admin, event_owner), `research_opportunities` (venue_admin only, unused
until Phase 7) — exactly the §1.3 proposed grant. **Correction to §1.3's
claim that `nav-manager.js` needs a capability-list edit**: it doesn't —
`NavItems::index()` (`src/NavItems.php:67`) sources the capability picker
dynamically from `Capabilities::globalCapabilities()`, so the three new keys
already appear there with zero JS changes. Nav items themselves (Phase 2)
still need a migration to insert `nav_items` rows.

**PHP classes** (6, not the full §3.3 list — `Contacts`/`Qualification`/
`Conversion`/`Scoring`/`Availability`/`Research` are later-phase work):
- `src/Opportunities.php` — dashboard, list/create/get/update/delete,
  read-only `/{id}/activities`. Writes `opportunity_activities` rows via a
  new `log_opportunity_activity()` helper in `src/Support.php` (same shape
  as `log_activity()`/`log_lead_activity()`).
- `src/Opportunities/Conferences.php`, `Companies.php` — CRUD for the two
  prospect-source tables. `Companies` normalizes `domain` (lowercase,
  scheme/www/path stripped) and enforces uniqueness in PHP.
- `src/Opportunities/ConferenceCompanies.php` — one class serves both
  directions of the conference<->company participation link (write side
  nested under a conference, read-only reverse list nested under a company).
- `src/Opportunities/Notes.php` — **polymorphic**, shared by every nested
  `/{family}/{id}/notes` route and the cross-cutting `/api/opportunity-notes`
  family. A note's `links` (type+id pairs) and `tags` live in
  `opportunity_note_links`/`opportunity_note_tags`; `linked_type=contact` is
  explicitly rejected with a clear 422 (not silently accepted) until Phase 4.
- `src/Opportunities/Signals.php` — shared by every nested `/{family}/{id}/signals`
  route; scope (conference/company/opportunity) comes from which route
  Kernel dispatched through, not from the request body.

**Kernel routes** (`src/Kernel.php`, inserted after the `crm-followups`
block): four top-level families — `opportunities`, `opportunity-conferences`,
`opportunity-companies`, `opportunity-notes` — each dispatching `notes`/
`signals`/`companies`/`conferences` children straight to the shared
Notes/Signals/ConferenceCompanies classes rather than through
`Opportunities.php`. `/api/opportunities/dashboard` is matched before generic
id parsing, same convention as other computed/bulk actions in this codebase.

**OpenAPI:** `docs/openapi.yaml` — added 4 tags (Opportunities, Opportunity
Conferences, Opportunity Companies, Opportunity Notes & Signals), 15 schemas,
9 path parameters, and all 34 documented path+method operations backing the
routes above. `php scripts/check-openapi-routes.php` and full
`scripts/static-analysis.sh` (includes `phpstan` level 5) both pass clean.

**Tests:** `tests/opportunities_module_db_test.php` (added to
`run-php-tests.sh`'s `DB_TESTS` opt-in list — `RUN_DB_TESTS=1`). 34
assertions, DB-backed, throwaway-fixture-in-`finally`: Kernel routing
spot-checks (via reflection, no HTTP), a capability-boundary check (`band`
role gets 403 on both read and write), and the full acceptance-criteria flow
— create conference -> create company -> associate them -> create opportunity
-> retrieve detail (joined names) -> move stage -> add note (verifies the
`opportunity_note_links` row and tags) -> add signal -> read activity feed
(asserts `created`/`stage_changed`/`note_added`/`signal_added` all present) —
plus negative cases (bad FK, bad enum, duplicate conference/company link,
`linked_type=contact` rejected).

**Seed/demo data:** none added — Phase 1 acceptance criteria didn't require
it and the repo has no existing dev-seed pattern to plug into (per the
"do not pollute production migrations with Dreamforce/NVIDIA demo rows"
instruction); revisit if a later phase's UI needs something to render against.

**Known gaps carried forward** (not blocking Phase 1, tracked for later
phases): `RealtimeInvalidationMapper` has no `opportunities`(+children)
entries yet, so new panels won't live-refresh until Phase 5/8 adds them
(fails open, per §1.9). No nav UI yet (Phase 2). No frontend at all yet — this
phase is backend-only per its own spec.

---

### 4.2 Phase 2 — what actually shipped

**Nav:** `database/migrations/110_add_opportunities_nav.sql` seeds the
left-nav "Opportunities" group (Discover/Conferences/Companies/Pipeline/
Notes), all gated on `view_opportunities`, same NOT-EXISTS-guarded-INSERT
shape as `077_add_booking_inbox_tasks_link_and_nav.sql`. Applied to the live
DB. **Correction to Phase 1's §1.3/§4.1 note that `nav-manager.js` needs a
capability-list edit for the picker to show the new capabilities**: already
verified false in Phase 1 (dynamic, sourced from `NavItems::index()`) — no
further action needed here either.

**Backend — Discover dashboard aggregates:** `GET /api/opportunities/dashboard`
(`src/Opportunities.php::dashboard()`) was rewritten (not a new endpoint —
Phase 1 already reserved this route) into one aggregate-heavy response
backing every KPI card and panel the mockup (`opportunity-1.png`) needs:
`kpis` (open_opportunities, projected_revenue, upcoming_conferences,
empty_nights, followups_due — every delta/potential figure is a real query
result), `best_opportunities`, `upcoming_conferences`, `availability_matches`,
`recent_notes` (each note gets a resolved `context` label like "NVIDIA — GTC
DC" from its links, batch-resolved in O(1) extra queries per linked type —
never per note), and `suggestions` (deterministic, data-derived — see below).
Accepts `?window_days=N` (7–365, default 30) matching the mockup's date-range
selector. Old Phase 1 keys (`stage_counts`, `stages`, `capabilities`) are
kept alongside the new ones for continuity.

**Backend — venue availability matching:** new `src/Opportunities/Availability.php`,
`Availability::emptyNightMatches(Database, windowDays)` — for every
upcoming conference in the window, which of its own calendar dates have no
active event booked anywhere in this tenant's venue(s). Exactly 2 SQL
queries total regardless of how many conferences/dates are involved (one for
candidate conferences, one for busy event-date spans in the relevant range;
date-set membership done in PHP) — satisfies the spec's explicit "avoid
N+1 queries" / "dashboard-ready aggregates" requirement. No venue_id
filtering: a single-tenant DB holds exactly one venue's calendar (verified:
`SELECT id,name FROM venues` returns exactly 1 row on this DB; multi-venue
is handled by separate tenant DBs in SaaS mode, not multiple `venues` rows
here), so "any active event in this DB" already means "this venue."
Deliberately reusable as its own service — Phase 8's "find prospects for
empty dates" is expected to call the same primitive rather than duplicate
the date math.

**"AI Suggestions" panel — deliberately not AI**: `Opportunities::dashboardSuggestions()`
is a small deterministic rule set (overdue follow-ups, opportunities with no
next action, conferences with an open venue night, conferences with zero
linked companies) — every entry backed by a real COUNT, matching the spec's
explicit Phase 2 allowance ("these may be deterministic/non-AI suggestions
generated from data. AI integration arrives later.").

**Frontend — new files** (`public/assets/opportunities/`, imported into
`app.js` as one `import './opportunities/opportunities-shell.js';` line, per
the directory-split precedent in `tasks/`):
- `shared.js` — stage labels/badges, score-tone class helper, date
  formatting (mirrors `core.js`'s `eventDate()` noon-local convention to
  avoid UTC-midnight day-shift), note-type labels. Grows with each later
  phase, not ahead of them.
- `discover-page.js` — `<pb-opportunities-discover>`, the real Phase 2 page:
  5 `.kpi-card`s (reused verbatim from Contacts' CSS), the Best
  Opportunities / Upcoming Conferences panels (reusing `.dashboard-grid`),
  and a 3-panel Venue Availability Match / Suggestions / Recent Notes row
  (new `.opp-panel-row-3` grid, collapsing to 1 column at the same
  breakpoints `.dashboard-grid` already does). One `GET
  /api/opportunities/dashboard` call per load/window-change; nothing else
  fetched separately (no N+1 on the frontend either).
- `opportunities-shell.js` — the module's entry point; also defines
  `<pb-opportunities-placeholder>`, an honest "Planned — not yet built" page
  (same shape as `processes/automation-placeholder.js`) for every nav
  destination/detail route without a real page yet (Conferences/Companies/
  Pipeline/Notes lists = Phase 3/4/5/6; every `*-detail` route follows its
  list). This is what satisfies "clicking company/conference/opportunity
  placeholders routes correctly or to a safe not-yet-implemented state."

**Routes** (`public/assets/app.js`): `#opportunities` → real Discover page;
`#opportunities-conferences`/`-companies`/`-pipeline`/`-notes`,
`#opportunities-conference-{id}`/`-company-{id}`/`-note-{id}`, and
`#opportunities-{id}` (numeric — opportunity detail) → the placeholder,
parameterized by page. `navKeyForRoute()` maps every detail route back to
its owning list's nav leaf (e.g. `opportunities-conference-42` highlights
the Conferences nav item); the 5 top-level pages match `nav_items.link`
exactly and fall through the existing `return route` default unchanged.

**CSS** (`public/assets/app.css`): new "── Opportunities ──" section
(`.opp-kpis`, `.kpi-sub`, `.kpi-card.kpi-warn`, `.opp-panel-row-3`,
`.opp-score-*`, `.opp-suggestion-list`, `.opp-note-list`) plus one
pre-existing-gap fix made in passing: `.table-scroll` (already used as a
wrapper by 8+ other panels across the app) had **no actual CSS behind it**
— a repo comment near `tk-list-scroll` said so explicitly. Added
`overflow-x: auto`, which the Phase 2 "data tables scroll appropriately"
responsive requirement needed for the new Opportunities tables and which
now also fixes every other panel already using that class name.

**Tests:**
- `tests/opportunities_dashboard_db_test.php` (new, DB-backed,
  `RUN_DB_TESTS=1`) — 17 assertions: creates a conference spanning two
  genuinely-free future dates (same free-date-finding technique as
  `room_conflict_guard_db_test.php`), asserts both are empty-night matches,
  books a throwaway event on one, re-asserts that date drops out while the
  other stays, then asserts the dashboard endpoint's `kpis`/
  `best_opportunities`/`upcoming_conferences`/`availability_matches`/
  `recent_notes` (incl. resolved `context`) all reflect the fixtures.
- `tests/ui/118-opportunities-discover.test.mjs` (new, headless-Chromium) —
  nav-gating, KPI-card count, panel presence, active-state highlighting, and
  the placeholder-routing acceptance criterion. **Could not be run from
  this checkout**: `/home/cdr/domains/panicbackstage.com/app/.env` has
  `SUPER_DB_NAME` set (multi-tenant SaaS mode active on this specific
  docroot), so `TenantContext::resolve()` rejects the UI harness's
  `127.0.0.1:PORT` dev-server host before the app ever boots ("Unrecognized
  host") — a pre-existing property of *this* docroot, not something Phase 2
  introduced (confirmed: `/home/cdr/backstage`, the other live docroot, has
  no `SUPER_DB_NAME`, and CI's own `.env` explicitly sets `SUPER_DB_NAME=`
  empty for exactly this reason — see `.github/workflows/*.yml`). The test
  is written and syntax-checked (`node --check`) and will run normally in
  CI or from a single-tenant checkout; verify it there before trusting it
  blind. Registered in `tests/run-php-tests.sh`'s `DB_TESTS` list
  (dashboard test) — the UI test runs via `node tests/ui/run.mjs` per
  existing convention, not that list.

---

## 5. Open TODOs / assumptions to verify in later phases

- `opportunity_qualification`'s fixed-boolean-columns design (§3.1) assumes
  the 9-item checklist stays fixed. If it needs to become configurable,
  revisit before Phase 5 ships.
- RealtimeInvalidationMapper needs `opportunities`(+children) added — not
  blocking, but do it as part of whichever phase first needs live-refresh
  (likely Phase 5 pipeline board, or defer cleanly to Phase 8 as the spec
  suggests).
- Phase 7 must confirm the installed Claude CLI's actual supported
  built-in tool names (`WebSearch`/`WebFetch` or otherwise) before wiring
  anything — nothing in the codebase currently exercises them, so this is
  unverified, not just unused.
- `opportunity_signals`/`opportunity_notes` "at least one FK set" rule is
  enforced in PHP validation, not a SQL `CHECK` constraint (matches
  existing repo style of validating in the endpoint, not the schema).
- Distance calculation: spec forbids calling Google Maps automatically and
  requires "if coordinates are absent, show unknown until researched" — venue
  lat/long needs a source; check for an existing venue-config location
  field (not yet confirmed — search for one in Phase 3, don't assume it
  exists).
- No generic job-status polling endpoint pattern exists yet in the repo;
  `opportunity_research_jobs` + `GET .../jobs/{id}` will be the first one —
  reasonable and self-contained, but worth flagging as new-pattern-not-just-
  reuse.

## 6. Known issues

- No live-refresh on Opportunities panels yet — `RealtimeInvalidationMapper`
  doesn't map any Opportunities table (fails open, not a hard bug; see §1.9).
  The Discover dashboard is fetch-once-per-load/window-change, no polling.
- `opportunity_research_jobs` exists as a schema-only stub (no reader/writer)
  until Phase 7. `opportunity_note_versions` doesn't exist yet (Phase 6).
- Only Discover is a real page — Conferences/Companies/Pipeline/Notes and
  every detail route are the honest placeholder until Phase 3/4/5/6 land.
- `tests/ui/118-opportunities-discover.test.mjs` has not actually been run
  (only syntax-checked) — this checkout's multi-tenant `.env` blocks the
  whole UI harness, not just this test. See §4.2's Tests note for the full
  explanation and where it's confirmed to run (CI, or a single-tenant
  checkout like `/home/cdr/backstage`). **Run it for real at the start of
  whichever future session next touches the Opportunities frontend**, before
  trusting it.
- "Best Opportunities" table's Likely Buyer column always renders "—" — no
  buyer-contact data exists until `opportunity_contacts` (Phase 4); the
  column header is kept so the table doesn't need a structural change later.

## 7. Tests added

- `tests/opportunities_module_db_test.php` — DB-backed, opt-in via
  `RUN_DB_TESTS=1` (registered in `tests/run-php-tests.sh`'s `DB_TESTS`).
  34 assertions covering Kernel routing, capability boundaries, the full
  Phase 1 acceptance-criteria flow, and FK/enum/duplicate-link validation
  failure cases. Cleans up its own throwaway rows (`PB TEST OPP — ` prefix)
  in a `finally` block regardless of pass/fail.
- `tests/opportunities_dashboard_db_test.php` — DB-backed, opt-in via
  `RUN_DB_TESTS=1`. 17 assertions covering `Availability::emptyNightMatches()`
  directly (before/after booking an event on a matched date) and the
  dashboard endpoint's full Phase 2 response shape. `PB TEST OPPDASH — `
  throwaway-row prefix, cleaned up in `finally`.
- `tests/ui/118-opportunities-discover.test.mjs` — headless-Chromium, nav
  gating / KPI rendering / active-state / placeholder-routing. Written and
  syntax-checked but **not run** in this session — see Known issues above.
