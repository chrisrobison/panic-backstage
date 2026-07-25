# Booking Inbox / Inquiry Workflow

The Booking Inbox is the shared, auditable workspace multiple staff and
external bookers use to triage, claim, respond to, and onboard inbound event
inquiries — without anyone privately controlling, hiding, or deleting them.

It is **not a parallel system**. It extends the existing `leads` pipeline
(`src/Leads.php`, `public/assets/leads.js`) with a claim/assign/own model, SLA
timers, real two-way conversation threading, a formal audited status machine,
deterministic routing rules, and an Onboard-Lead wizard. **Leads** and
**Inbox** are two lenses over the same `leads` rows: Leads stays the
deal-evaluation/pipeline view; Inbox (`#inbox-*` routes,
`public/assets/inbox/*.js`) is the rich per-inquiry triage/claim/conversation
workspace.

## Architecture

```
Ingestion (email / public form / manual)
        │
        ▼
   leads row created  +  lead_messages (inbound)  +  lead_intake_emails (raw audit, email only)
        │
        ▼
   src/Leads/Classifier.php  ──▶  lead_classifications
        │   (Claude structured-output extraction: category, genre, dates,
        │    attendance, budget, requirements, spam probability, per-field
        │    + overall confidence — see "Untrusted input" below)
        ▼
   src/Leads/Acknowledgment.php  ──▶  auto-reply (send-once, outbound lead_messages row)
        │
        ▼
   src/Leads/RoutingEngine.php  ──▶  routing_rules / routing_rule_versions
        │   (first matching published rule wins; ties go to the
        │    unassigned triage queue; every decision — including "no
        │    match" — is written to lead_audit_log)
        ▼
   leads.assigned_to_user_id + sla_claim_due_at set
        │
        ▼
   staff claims  ──▶  src/Leads/ClaimService.php  ──▶  lead_claims (append-only)
        │               claim_expires_at set (business-hours-aware, src/Leads/BusinessHours.php)
        ▼
   conversation, status changes  ──▶  src/Leads/StatusMachine.php  (required-reason + high-value-approval gates)
        │
        ▼
   POST /api/leads/{id}/onboard  ──▶  src/Leads/Onboarding.php  ──▶  event created (status: proposed)
                                        (shared with Leads::convert() — same code path)
```

Everything writes to `lead_audit_log` via `log_lead_activity()`
(`src/Support.php`) — the same one-helper-per-domain convention as
`task_activity`, `process_audit_log`, etc. There is no generic audit table
app-wide; this one is scoped to leads/inquiries.

### Claim vs. Assign vs. Own

These are deliberately distinct, per spec:

- **Assigned** — the routing engine (or a manual action) points an inquiry at
  a person/queue. Nobody has committed to working it yet.
  `leads.assigned_to_user_id` + `sla_claim_due_at`.
- **Claimed** — a specific person has taken responsibility for actively
  working it right now. Only one active claim per lead
  (`src/Leads/ClaimService.php::claim()` checks before inserting).
  `leads.claimed_by_user_id` + `claim_expires_at`, backed by an append-only
  `lead_claims` history.
- **Owned** — set once the inquiry is onboarded into a real event; survives
  reassignment of day-to-day claims. `leads.owner_user_id` + `owned_since`.

A fixed list of **claim-preserving actions**
(`ClaimService::PRESERVING_ACTIONS`: send reply, schedule tour, send
availability, log call, request info, manager-approved follow-up task) both
extends `claim_expires_at` *and* is itself logged — so claim extension is
inherently bounded and auditable rather than a bare "extend" button.

### SLA timers (business-hours-aware)

`src/Leads/BusinessHours.php` walks forward through a venue's local business
window (`lead_inbox_settings`: business hours + timezone) and converts to UTC
for storage — the same UTC-conversion discipline as the ticketing fix in
`93153d4`. `src/Leads/SlaSettings.php` resolves the effective SLA hours for a
given lead (venue defaults, adjusted for `high_value_threshold`).

`scripts/lead-sla-tick.php` sweeps:

- `assigned` leads past `sla_claim_due_at` → returned to the unassigned queue.
- `claimed` leads past `claim_expires_at` → released/escalated.

**This script is shipped but deliberately not wired into cron on this box** —
there is no staging environment here, so enabling a new scheduled job against
production is left as a separate, deliberate operator action. To enable it:

```
*/5 * * * * /home/cdr/domains/panicbooking.com/www/backstage/scripts/cron-lead-sla-tick.sh
```

(Same shape as the existing `cron-process-tick.sh`: `flock`-guarded, logs to
`storage/logs/lead-sla-tick.log`, rotates at 1 MB.) It is a no-op until a lead
actually has an `sla_claim_due_at`/`claim_expires_at` in the past, so adding it
is low-risk — but that judgment call belongs to whoever owns this crontab.

### Untrusted-input discipline (AI classification)

`src/Leads/Classifier.php` calls Claude (Anthropic Messages API, structured
JSON output, `PROMPT_VERSION` stored alongside every result) to extract ~24
fields (event type, genre, category, dates, attendance, budget,
production/stage/sound/lighting requirements, urgency, spam probability,
recommended action) with per-field and overall confidence. **The model never
executes anything.** It only ever writes into `lead_classifications`;
deterministic PHP (`RoutingEngine`, `StatusMachine`) reads those stored
columns. A human correction (`PATCH /api/leads/{id}/classification`) is
stored as a new `lead_classifications` row with `source = human_correction`,
never overwriting the AI's original record.

### Routing rules

`routing_rules` + `routing_rule_versions` mirror the existing
`process_versions` pattern: a rule is edited as a draft, then published as an
immutable version. `RoutingEngine::route()` evaluates published versions in
priority order against lead + classification fields
(category/genre/attendance/budget/age-restriction/source/prior-customer
lookup), using case-insensitive substring containment
(`RoutingEngine::containsAny()`) rather than exact match, since the
classifier can return compound values like `"punk/ska"`. First match wins;
otherwise the lead lands in unassigned triage. Every decision — including "no
rule matched" — is logged to `lead_audit_log` with the rule/version id, so
the UI can render "Routed to Kathy because... 94% confidence."

Seed rules (migration `081_seed_booking_inbox_routing_rules.sql`, each
guarded by `EXISTS (SELECT 1 FROM users WHERE email = ...)` so a fresh
install without those specific accounts just skips them):

| Condition | Routes to |
|---|---|
| Comedy / clown / theatrical / experimental art | Colleen |
| Punk / ska / general music | Kathy |
| Cannabis / 4:20 | Kathy |
| Metal / hardcore | Katrina |
| Corporate / private | general queue |
| Low confidence | unassigned triage |

Manager overrides go through the same `RoutingEngine::assign()` write path as
an automated match, so they're equally audited.

### Status machine

`src/Leads/StatusMachine.php` is the single authoritative transition table —
both `Leads::update()` and the new Inbox endpoints call through it, so there
is exactly one place transitions are validated. `REASON_REQUIRED` enforces a
reason for `declined`/`lost`/`spam`/`duplicate`/`archived`/reassignment.
`isHighValue()` (reads `lead_inbox_settings.high_value_threshold`) gates
declining a high-value lead behind `decline_high_value_leads` — a restricted
booker without that capability gets a `lead_approval_requests` row created
instead of the transition applying, for a manager to approve/deny.

### Onboarding

`POST /api/leads/{id}/onboard` (`src/Leads/Onboarding.php`) —
duplicate-event detection (same contact/org + overlapping date at the venue),
availability/conflict check (reuses the existing
`venue.check_availability` handler logic), an initial task checklist via the
existing Tasks app (`tasks.related_lead_id` — no parallel checklist table),
and event creation at `proposed` status (**not** "booked" — onboarding is a
handoff, not a close). `Leads::convert()` and the wizard's `onboard()` share
`Onboarding::createEventFromLead()` so there's one code path for "a lead
became an event."

### Social Queue

The spec's Draft → ... → Archived social-media workflow extends the
existing **Panic Promote** module (`src/Promote/Posts.php`,
`promote_posts`/`promote_post_variants`) rather than adding a parallel
`social_*` schema — Promote already covered per-event posts, per-channel
variants, a destinations registry, and a draft/approved/scheduled/sent/
archived lifecycle. Migration `082_add_social_queue.sql` widens that
lifecycle to the full spec workflow (`needs_assets`, `ready_for_review`,
`changes_requested`, `awaiting_manual_publish`, `published`, `verified`) and
adds:

- **Revision-based approval invalidation.** `approved_content_hash` records
  the content hash at the moment of approval. `Posts::update()` recomputes
  the hash on every save; if the content actually changed and the post's
  approval-locked status (`approved`/`scheduled`/`awaiting_manual_publish`)
  would otherwise be left standing — whether because the caller omitted
  `status` entirely, or (as the real editor form does) resubmitted the same
  status the post already had — it's dropped back to `changes_requested`
  instead. A caller that explicitly requests a *different* status (or calls
  the dedicated `approve()` action, which sets `approved_content_hash`
  directly and doesn't go through `update()` at all) is respected as-is.
- **Manual-publish tasks.** Entering `awaiting_manual_publish` auto-creates a
  Tasks-app task (`Posts::ensureManualPublishTask()`) carrying the approved
  caption for every channel, filed into a shared "Social Publishing" task
  document — reusing the Tasks app rather than a parallel checklist, the
  same convention as `Leads\Onboarding`. `mark-published` closes that task.

New sub-routes on the existing post resource:

```
POST /api/promote/events/{id}/posts/{postId}/approve          approves the CURRENT revision
POST /api/promote/events/{id}/posts/{postId}/mark-published    records the public URL, status -> published/verified
```

The "Social Queue" nav item points at the existing `#promote` route rather
than a new page (`nav_items` seed, migration `082`) — a venue's own Promote
nav item is currently hidden by an admin's deliberate choice, so a separate
visible entry was added instead of silently un-hiding it.

## API surface

All new endpoints enforce their capability **server side**
(`BaseEndpoint::GLOBAL_CAPABILITIES`), never UI-only. A restricted booker's
row visibility (`assigned_to_user_id = me OR owner_user_id = me OR EXISTS
lead_watchers`) is applied in the SQL `WHERE` of every list/read for that
role via `BaseEndpoint::leadScopeSql()`, not bolted on after the query.

```
POST   /api/leads/{id}/claim
POST   /api/leads/{id}/release-claim
POST   /api/leads/{id}/assign
POST   /api/leads/{id}/reassign            (reason required)
POST   /api/leads/{id}/status              (goes through StatusMachine)
GET    /api/leads/{id}/messages
POST   /api/leads/{id}/messages            (based_on_message_id required — optimistic concurrency)
GET    /api/leads/{id}/drafts
POST   /api/leads/{id}/drafts
GET    /api/leads/{id}/presence
POST   /api/leads/{id}/presence            (heartbeat)
POST   /api/leads/{id}/attachments
GET    /api/leads/{id}/classification
PATCH  /api/leads/{id}/classification      (human correction)
POST   /api/leads/{id}/onboard
GET    /api/leads/{id}/audit
GET    /api/leads/changes?since=...        (polling feed)
GET    /api/inbox/list?view=...
GET    /api/inbox/counts
GET/POST/PATCH  /api/routing-rules[/{id}/versions...]
GET    /api/reports/booking-inbox
```

Capabilities added to `BaseEndpoint::GLOBAL_CAPABILITIES`:
`view_booking_inbox`, `manage_booking_inbox`, `manage_assigned_leads`,
`claim_leads`, `override_lead_claims`, `manage_lead_routing`,
`decline_high_value_leads`, `export_leads`, `view_lead_audit`,
`manage_social_queue`, `view_social_queue`, `publish_social` — mapped onto
`venue_admin` (all), `staff`/`event_owner` (Trusted booker set), and
`promoter` (Restricted external booker: view + claim, scoped to
assigned/watched rows only).

> **Note on `docs/openapi.yaml`:** the Leads, Tasks, Promote, and Processes
> modules were never added to the OpenAPI spec (it currently documents auth,
> users, and events/contracts/tasks-under-events only) — this predates the
> Booking Inbox work. Rather than bolt on partial coverage for just the new
> routes above, leaving a confusing half-documented module, that gap is
> flagged here for a dedicated future pass across all four modules at once.

## Realtime

No SSE/WebSocket infrastructure exists in this app. The Inbox polls
`GET /api/leads/changes?since=<ts>` every few seconds while open and
publishes results onto the existing `core.js` pub/sub bus, so the
list/workspace/detail components each react to just their slice — the same
"child reacts to a bubbling event" pattern Tasks already uses.

## Setup

1. **Migrations** (`071`–`082`) are additive/idempotent — `php
   scripts/migrate.php` applies them in order. Already applied on this box.
2. **Anthropic key** (`ANTHROPIC_API_KEY` / `ANTHROPIC_API_KEY_FILE` in
   `.env`) enables AI classification; without it, `Classifier::classify()`
   still runs but every result is generic/low-confidence and routes to
   unassigned triage (the deterministic parts of the pipeline — dedup,
   acknowledgment, claim, status machine, onboarding — are unaffected).
3. **SLA cron** — not enabled; see the crontab line above when you're ready.
4. **Ingestion** — reuses the existing `bookings@themab.org` Exim pipe (see
   `docs/booking-email-import.md`); no additional mail setup needed.

## Tests

Hermetic PHP unit tests (no DB writes):

```
php tests/leads_status_machine_test.php
php tests/leads_classifier_test.php
php tests/leads_acknowledgment_test.php
php tests/leads_business_hours_test.php
php tests/leads_routing_engine_test.php
php tests/leads_claim_service_test.php
```

UI tests (headless Chromium over CDP, `tests/ui/run.mjs`):

```
tests/ui/110-booking-inbox.test.mjs     — queue render, workspace open, saved views, mobile collapse
tests/ui/111-social-queue.test.mjs      — Social Queue status workflow surfaces in the Promote post editor
```

Both suites are non-destructive by convention — they assert render/navigation
behavior and, where a write path must be exercised (e.g. the approval
revision-invalidation logic), that's done via a live curl round-trip against
a throwaway post/lead that is created and deleted in the same pass, not left
in the UI test files.
