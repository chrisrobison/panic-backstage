# AI Assistant Drawer + Recurring-Event Horizon Cap — Implementation Plan

**Added:** 2026-07-24
**Status:** ✅ Built — Phase 0 (horizon cap), Phase 1 (read-only assistant), and Phase 2 (propose/apply write tools: `propose_booker_update`, `propose_recurring_series`) are all implemented per this doc. Phase 2 adds `src/Ai/BookerUpdate.php` (shared allowlist/match/diff/apply logic) and `Events\Series::attemptCreate()`/`previewSeries()` (extracted from `Series::create()` so the AI apply path and the human "Create recurring events" button share one validation/insert code path), plus `POST /api/ai/proposals/{id}/apply` and `DELETE /api/ai/proposals/{id}` on `src/Ai/Assistant.php`. Verified end-to-end against the live `claude` CLI: propose → apply (DB row changed, `db_history.actor = 'ai:{id}'`), propose → discard (DB untouched, replay blocked), a destructive-request refusal, and a disallowed-field request refusal — see `tests/ai_booker_update_test.php` and `tests/ai_phase2_db_test.php` for the hermetic/DB-backed coverage.

---

## Overview

Two related pieces of work:

1. **AI Assistant Drawer** — a right-side drawer, admin-only, that lets `venue_admin` users ask questions about the currently open event or events in general, and (Phase 2) request specific bulk changes ("update all Zingflower events with this booker info") or create a recurring series conversationally. Runs entirely through the **local `claude` CLI** (`/home/cdr/.local/bin/claude`, OAuth-authenticated already) — no `ANTHROPIC_API_KEY`, no billed API usage.
2. **Recurring-event 90-day horizon cap** — a new hard constraint on the existing recurring-series feature (`src/Events/Series.php`, `public/assets/recurrence.js`), independent of the AI work but consumed by it in Phase 2.

The central design problem for #1: giving an LLM the ability to touch production data is only as safe as the *surface* it's given. This plan gives the model **no shell, no filesystem, and no SQL** — only a small, named, capability-checked set of functions, with every mutation requiring an explicit human click on a server-computed diff. See "Guardrails, restated" at the end for the non-negotiables.

---

## Part A — Recurring events: 90-day horizon cap

### Current state (verified in code, 2026-07-24)

Recurring events already exist and work: `POST /events/{id}/series` (`src/Events/Series.php`) turns an existing event into the anchor of a new `event_series`, given an explicit list of occurrence dates computed client-side (`public/assets/recurrence.js` — weekly / monthly-by-weekday / monthly-by-date patterns, deliberately not a general RRULE implementation). Capped today at `MAX_OCCURRENCES = 52`, with no date-horizon limit — a weekly pattern can already reach ~52 weeks out; the new cap tightens that.

### Change

- **Measured from:** today (the day the series is created) — a rolling 90-day booking horizon, not relative to the anchor event's own date.
- **Interacts with the existing cap as:** an *additional* constraint. Both `MAX_OCCURRENCES = 52` and the new `MAX_HORIZON_DAYS = 90` apply; whichever is hit first stops generation (e.g. a daily/interval-1-week pattern hits the 90-day wall long before 52 occurrences; an every-4-weeks pattern hits 52 occurrences well before 90 days would ever be reached by that cadence... actually the reverse — hits 90 days first since 52×4 weeks ≈ 4 years. Point is: both are checked, no assumption about which binds first).

### Server-side (authoritative)

`src/Events/Series.php`, `create()`:
```php
private const MAX_OCCURRENCES = 52;
private const MAX_HORIZON_DAYS = 90;
```
Add a second validation pass alongside the existing per-date format/self-date checks: reject (422) any date later than `today + 90 days`, listing the offending dates in the same style as the existing "Occurrence dates must not include the event's own date" error. This runs **before** the room-conflict check so a horizon violation is reported without doing conflict-check work for dates that will be rejected anyway.

### Client-side (UX only — server remains authoritative)

`public/assets/recurrence.js`, `generateOccurrenceDates()`: add the same `today + 90 days` stop condition alongside the existing `cap`/`endDate` stop conditions, so the live preview never suggests a date the server would reject. Update the hint text in `RecurrenceFields.render()`: *"Max 52 occurrences or 90 days out, whichever comes first."*

`public/assets/help.js`: update the "Starting a series" copy ("...total is capped at 52 occurrences per series") to mention the 90-day horizon too.

### Testing

- `tests/*_test.php`: pure date-boundary unit test for the new horizon check (no DB needed — same style as existing hermetic tests).
- `php -l src/Events/Series.php`.
- Manual: attempt to create a weekly series with `occurrence_count` high enough to exceed 90 days; confirm 422 with the correct offending-dates list; confirm a series that fits within both caps still succeeds.

### Migration

None — pure validation-logic change, no schema change.

---

## Part B — AI Assistant Drawer

### Architecture summary

```
Admin types in drawer
        │
        ▼
POST /api/ai/ask  (src/Ai/Assistant.php)
        │  requireAuth() + requireGlobalCapability('use_ai_assistant')
        │  RateLimiter::tooMany('ai_ask:user:{id}', ...)
        │  loads/creates ai_conversations row, persists user message
        │  if event_id given: fetches curated event context directly (no tool
        │    round-trip needed for "the currently selected event")
        ▼
spawns `claude -p ...` (headless, per-request, scoped temp dir — same
   subprocess-hygiene pattern as src/Events/GenerateFlyer.php's `codex exec`)
        │  --tools ""                     → Claude's own Bash/Read/Write/Edit/etc. NEVER available
        │  --mcp-config <temp config>      → points at scripts/ai-mcp-server.php
        │  --strict-mcp-config             → ignore any other MCP config on the box
        │  --allowedTools mcp__panic__...  → explicit per-phase tool allowlist
        │  --permission-mode bypassPermissions  (safe: only pre-allowlisted MCP
        │    tools are callable at all; this just removes the interactive
        │    prompt that would hang forever headless — it grants no capability)
        │  --output-format json --no-session-persistence
        │  --append-system-prompt <file>  → behavior rules + injected event context
        │  --model $AI_ASSISTANT_MODEL --max-budget-usd (optional)
        ▼
scripts/ai-mcp-server.php (stdio MCP server, spawned fresh per request)
   env: PB_ACTING_USER_ID / PB_ACTING_ROLE / PB_TENANT_SLUG / PB_EVENT_ID
   — taken from the ALREADY-authenticated request in Assistant.php, never
   from anything the model says, so the model cannot impersonate a role.
   Exposes ONLY the named tool functions below, each re-running the exact
   same Panic\Capabilities checks the human-facing endpoints use.
        │
        ▼
Assistant.php parses the final response, persists assistant message(s),
   and — if the model asked for a proposal-type tool — returns the stored
   diff to the frontend. Returns plain text otherwise.
```

**The single most important guardrail:** `apply_*` is never a model-callable tool. The model can only *propose*. Applying a proposal is a plain REST call (`POST /api/ai/proposals/{id}/apply`) triggered by a human clicking "Apply" on the diff card in the drawer — the LLM has no code path that leads to a write executing without that click.

### New capability

`use_ai_assistant` — added to `GLOBAL_CAPABILITIES['venue_admin']` in `src/BaseEndpoint.php` (via the `Capabilities` extraction below). Not granted to any other role in v1. Gates both the endpoint (`requireGlobalCapability('use_ai_assistant')`) and whether the frontend renders the drawer trigger at all (same pattern as other admin-only buttons keyed off the `capabilities` payload).

### Capability-logic extraction (prerequisite refactor)

`scripts/ai-mcp-server.php` runs as its own PHP process (spawned by the `claude` CLI, not by `Kernel`), so it has no `Request`/`Endpoint` object to hang capability checks off of. Rather than duplicate `EVENT_CAPABILITIES`/`GLOBAL_CAPABILITIES`/`eventAccess()` and risk the two copies drifting apart, extract the pure logic out of `src/BaseEndpoint.php` into a new `src/Capabilities.php` static class:

```php
final class Capabilities
{
    public static function hasGlobal(string $role, string $capability): bool { ... }
    public static function eventAccess(Database $db, int $eventId, ?int $userId, string $role): ?array { ... }
    public static function hasEvent(Database $db, int $eventId, ?int $userId, string $role, string $capability): bool { ... }
}
```
`BaseEndpoint` becomes a thin wrapper delegating to `Capabilities::*` — pure refactor, no behavior change (cover with the existing endpoint test suite before/after to confirm). This is what lets the MCP tool server enforce **identical** per-event and global permission rules as every other endpoint in the app, from a plain user id + role passed in via env var.

### New tables — migration `073_add_ai_assistant.sql`

```sql
CREATE TABLE ai_conversations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  event_id INT NULL,
  title VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL
);

CREATE TABLE ai_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT NOT NULL,
  role ENUM('user','assistant','tool_call','tool_result') NOT NULL,
  content LONGTEXT NULL,
  tool_name VARCHAR(100) NULL,
  tool_args_json LONGTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE CASCADE
);

CREATE TABLE ai_action_proposals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT NOT NULL,
  user_id INT NOT NULL,
  event_id INT NULL,
  tool_name VARCHAR(100) NOT NULL,
  args_json LONGTEXT NOT NULL,
  diff_json LONGTEXT NOT NULL,
  status ENUM('pending','applied','discarded','expired') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP NOT NULL,
  applied_at TIMESTAMP NULL,
  applied_by_user_id INT NULL,
  FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (applied_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);
```
Proposals expire (`expires_at`, suggest 30 minutes) — an old proposal can't be replayed/applied after the underlying data may have changed.

### Backend endpoint

`src/Ai/Assistant.php` (extends `BaseEndpoint`), wired into `Kernel::resolve()` next to the `app-settings` block:
```php
if ($segments[0] === 'ai') {
    return [Ai\Assistant::class, ['action' => $segments[1] ?? null, 'id' => $this->intOrNull($segments[2] ?? null)]];
}
```
Routes:
- `POST /api/ai/ask` — `{conversation_id?, event_id?, message}` → `{conversation_id, reply, proposal?}`
- `POST /api/ai/proposals/{id}/apply` — re-validates capability + `expires_at` + field allowlist + caps, executes via the **same code path** the human UI uses (see "Shared apply logic" below), marks the proposal `applied`, `log_activity()`, returns the result.
- `DELETE /api/ai/proposals/{id}` — marks `discarded`.

Every route: `requireAuth()` then `requireGlobalCapability('use_ai_assistant')` first.

### Shared apply logic (no duplicated mutation code)

- Booker-info bulk update: factor the actual `UPDATE events SET ... WHERE id IN (...)` (currently inline SQL, ad-hoc per endpoint per this codebase's convention) into one method callable from both a possible future human bulk-edit UI and `Ai\Assistant::applyBookerUpdate()`.
- Recurring series: `apply_recurring_series` calls straight into `Events\Series`'s existing `create()`-equivalent logic (may need a small internal refactor to expose a callable method rather than only an HTTP `handle()`), so the AI path and the human "Create recurring events" button are provably running identical validation, including the new 90-day cap from Part A.

### Tool registry

**Phase 1 — read-only:**
| Tool | Capability check | Notes |
|---|---|---|
| `get_event(event_id)` | `Capabilities::hasEvent(..., 'read_event')` | Curated field subset only (title, date, status, promoter/booker fields, capacity, ticket info, lineup count) — never a raw row dump. |
| `list_events(status?, date_from?, date_to?, promoter_name?, limit≤25)` | `view_all_events` (or user's own event scope) | Hard cap 25 rows/call; must be called again with different filters to page — bounds both cost and prompt-injection-driven bulk data exposure. |

**Phase 2 — write (propose only; apply is never model-callable):**
| Tool | Capability check | Cap / allowlist |
|---|---|---|
| `propose_booker_update(event_ids[] or promoter_name_filter, fields{})` | `edit_event` per matched event | Field allowlist: `promoter_name`, `promoter_email`, `promoter_phone`, `client_org`, `booker_name`, `booker_email`, `booker_phone` only — any other key rejected server-side even if present in the model's call. Max 25 matched events. |
| `propose_recurring_series(event_id, pattern)` | `edit_event` | `MAX_OCCURRENCES = 52` and `MAX_HORIZON_DAYS = 90` from Part A, enforced by the shared `Series` logic. |

**Permanently excluded, not just deferred:** `delete_event`, `cancel_event`, any raw-SQL tool, any shell/file tool, anything that mutates `status`, financial fields, or dates outside the two allowlisted proposal tools above. If a future request needs one of these, it gets the same propose/apply treatment and an explicit new row in this table — never a generic "run this" escape hatch.

### CLI invocation — confirmed flags (this box, `claude` v2.1.219)

```
claude -p "$PROMPT" \
  --output-format json \
  --no-session-persistence \
  --tools "" \
  --mcp-config /tmp/pb-ai-<rand>/mcp-config.json \
  --strict-mcp-config \
  --allowedTools mcp__panic__get_event mcp__panic__list_events [+ write tools in Phase 2] \
  --permission-mode bypassPermissions \
  --append-system-prompt-file /tmp/pb-ai-<rand>/system-prompt.txt \
  --model "$AI_ASSISTANT_MODEL"
```
Wrapped in `exec()`/`proc_open()` with a hard timeout (`AI_ASSISTANT_TIMEOUT_SECONDS`, default 60s) and per-request scoped temp dir cleaned up in `finally` — directly modeled on `GenerateFlyer::runCodex()`'s `escapeshellarg()`-everywhere, timeout, and temp-dir-cleanup pattern.

### MCP tool server

`scripts/ai-mcp-server.php` — a standalone stdio MCP server (own bootstrap: loads `.env`, constructs `Database`, does **not** go through `Kernel`/`Auth`/JWT at all — the acting identity is handed to it via env vars set by `Assistant.php`, which already validated the JWT for this HTTP request). Implements only the tool functions in the registry above; the dispatcher is a fixed `match` (not reflection over arbitrary methods) so adding a new tool is a deliberate, reviewable code change, never data-driven.

### Frontend

- `public/assets/ai-drawer.js` — new Web Component `<pb-ai-drawer>`, mounted once in the app shell (topbar), following existing `core.js` conventions (`api()`, `publish`/`subscribe`, custom elements).
- Trigger button in the topbar, rendered only when `capabilities.use_ai_assistant` is true (same gating pattern used for other admin-only controls).
- New slide-over drawer CSS/JS (there's no existing generic drawer component — `openModal()` doesn't fit a persistent scrolling transcript well). Loosely modeled on the mobile-nav drawer's `drawer-open`/backdrop/Escape-to-close mechanics in `app.js`/`app.css`, not on `openModal()`.
- When opened from an event workspace tab: `.eventId` is set on the component so questions default to "this event" without the user naming it. A topbar-level entry point (no `eventId`) covers cross-event questions.
- A proposal comes back from `/api/ai/ask` as a distinct message type in the transcript: a diff card (before → after, or the recurring-series date list) with **Apply** / **Discard** buttons. Apply uses a native `confirm()` before calling `POST /api/ai/proposals/{id}/apply`, matching the existing destructive-action UX elsewhere in the app (e.g. `event-workspace.js`'s payment-void confirm).

### Config (`.env.example` additions)

```
CLAUDE_CLI_BIN=/home/cdr/.local/bin/claude
AI_ASSISTANT_MODEL=sonnet
AI_ASSISTANT_TIMEOUT_SECONDS=60
AI_ASSISTANT_MAX_BUDGET_USD=
```

### Audit & rate limiting

- `Database::setActor('ai:' . $userId)` before any AI-triggered write — `db_history` triggers attribute and can undo it distinctly from a human edit.
- `log_activity()` on: proposal created (booker update / recurring series) and proposal applied — not on every read-only Q&A turn, to avoid drowning the per-event activity log in chat noise.
- `RateLimiter::tooMany()` buckets: `ai_ask:user:{id}` (suggest 30/hour) and `ai_apply:user:{id}` (suggest 10/hour) — bounds runaway usage against the shared Claude Code subscription quota (this is OAuth/subscription-based, not metered API billing — a "noisy neighbor" problem across concurrent admins is the real cost risk here, not a dollar bill).

### Testing

- `php -l` on every new/changed file.
- New hermetic tests: `Capabilities` extraction produces identical results to the pre-refactor `BaseEndpoint` logic; `Series.php` horizon-cap boundary cases.
- `tests/ui/NN-ai-drawer.test.mjs`: open drawer on the `UI_EVENT_ID` fixture, ask a scripted read-only question, assert a rendered answer; separately, against a throwaway `"PB UI TEST — ... (safe to delete)"` event, drive a `propose_booker_update` round trip through to a clicked Apply, assert the DB row changed, delete the throwaway event in `finally`.
- Manual: mint a `venue_admin` token via `scripts/login-link.php`, `curl /api/ai/ask` directly to verify the MCP wiring before wiring up the UI.

### Rollout

1. **Phase 0** (ship first, independent): recurring-event 90-day horizon cap.
2. **Phase 1**: read-only assistant. `--allowedTools` contains only `get_event`/`list_events` — the write tools don't exist in the MCP server binary at all yet, so there's nothing to accidentally allow.
3. **Phase 2**: add `propose_booker_update` / `propose_recurring_series` + Apply/Discard UI, once Phase 1 has run in production and the propose/apply pattern has been validated end-to-end on the read-only foundation.

---

## Guardrails, restated (the non-negotiables)

- The model never gets Claude Code's own Bash/Read/Write/Edit tools (`--tools ""`), and never gets any MCP server except ours (`--strict-mcp-config`).
- The model never gets a SQL tool or a shell tool, under any name, ever.
- The model never gets a `delete_event`/`cancel_event`-capable tool.
- The model can *propose* a write; it can never *apply* one — `apply_*` is a plain human-clicked REST call against a stored, expiring, server-computed diff, not a model tool call.
- Every write tool has a field allowlist (rejecting unlisted fields server-side, not just by prompt instruction) and a row/occurrence cap.
- Every tool re-runs the same `Panic\Capabilities` checks as the human-facing endpoints — an admin using the AI can never do more than that same admin could already do by hand.
- Every AI-triggered write is attributed (`setActor('ai:...')`) and logged (`log_activity()`), landing in the same undo-capable `db_history` audit trail as any other change.
