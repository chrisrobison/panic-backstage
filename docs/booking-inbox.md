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

`assign` vs. `reassign` are also permission-distinct, not just semantically
distinct: `reassign` (`manage_booking_inbox`) lets any Trusted booker hand off
an inquiry currently their own to someone else. `assign` targets *any*
inquiry regardless of current owner/claimant, so it's gated to venue admins
only (`BaseEndpoint::isVenueAdmin()`) — see `LeadsInbox::assign()`.

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

This script is enabled in the production crontab every five minutes:

```
*/5 * * * * /home/cdr/domains/panicbooking.com/www/backstage/scripts/cron-lead-sla-tick.sh
```

(Same shape as the existing `cron-process-tick.sh`: `flock`-guarded, logs to
`storage/logs/lead-sla-tick.log`, rotates at 1 MB.)

`scripts/classify-lead-backlog.php` retries active inquiries that have an
inbound message but no current classification. Its cron wrapper processes two
at a time every fifteen minutes, so a temporary model/CLI outage does not leave
an inquiry permanently unclassified and the historical backlog drains without
rerouting leads that a person has already claimed or owned.

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

> **Note on `docs/openapi.yaml`:** the Leads, Booking Inbox, Tasks, Promote,
> and Processes modules are now documented in the OpenAPI spec (all the
> routes listed above, plus `/api/leads/{id}/*`, `/api/inbox/*`, and
> `/api/routing-rules/*`) — the gap this note used to flag has been closed.
> `scripts/check-openapi-routes.php` (`tests/openapi_route_drift_test.php`)
> enforces that every Kernel-routed path, every Booking Inbox lead child, and
> every Inbox cross-lead action stays documented going forward, so route
> drift here is caught by the hermetic test suite rather than discovered
> later.

## Realtime

No SSE/WebSocket infrastructure exists in this app. The Inbox polls
`GET /api/leads/changes?since=<ts>` every few seconds while open and
publishes results onto the existing `core.js` pub/sub bus, so the
list/workspace/detail components each react to just their slice — the same
"child reacts to a bubbling event" pattern Tasks already uses.

## Setup

1. **Migrations** (`071`–`083`) are additive/idempotent — `php
   scripts/migrate.php` applies them in order. Already applied on this box.
2. **The local `claude` CLI** (`CLAUDE_CLI_BIN`/`CLAUDE_CLI_HOME` in `.env`,
   see `src/Ai/ClaudeCli.php`) enables AI classification, riding that CLI's
   own logged-in OAuth/subscription session rather than a billed API key —
   the same mechanism the AI Assistant drawer uses. `claude login` ran as
   `cdr`, but this is also reachable via PHP-FPM's `www-data` pool user (the
   public inquiry widget, `PublicInquiry.php`) — that only works because (a)
   `CLAUDE_CLI_HOME` is pinned to `/home/cdr` explicitly rather than trusting
   the calling process's own (wrong) HOME, and (b) `www-data` is a member of
   the `cdr` group and `~/.claude/.credentials.json` (and friends) are
   `640 cdr:cdr`, group-readable. Both were confirmed live: before this fix,
   requests through `www-data` produced `is_error: true` with zero token
   usage — a silent "authenticated as nobody," not a loud failure. Without a
   working CLI, `Classifier::isEnabled()` is false and `classify()`
   short-circuits to `null` — the lead is left unclassified and routes to
   unassigned triage (the deterministic parts of the pipeline — dedup, acknowledgment, claim,
   status machine, onboarding — are unaffected).
3. **SLA cron** — enabled every five minutes through
   `scripts/cron-lead-sla-tick.sh`.
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

DB-backed tests (`RUN_DB_TESTS=1 ./tests/run-php-tests.sh`) that exercise the
claim eligibility policy and outbound identity end-to-end, each creating and
cleaning up its own throwaway rows:

```
tests/booking_inbox_role_scope_db_test.php   — restricted-booker visibility + claim policy (table below)
tests/leads_acknowledgment_test.php          — auto-ack, settings-derived From
```

## Operator runbook

Practical guidance for staff actually working the Inbox day to day — not a
restatement of the architecture above.

### Claim vs. wait for assignment

- If an inquiry already shows an **Owner** or is in someone's **Assigned**
  queue, don't claim it out from under them — use **Assign**/**Reassign**
  (venue admin / trusted booker only) if it genuinely needs to move.
- If an inquiry is sitting **unassigned** (the "Unassigned" saved view) and
  you're the person who'd naturally handle it, claim it before replying —
  claiming is what starts the SLA response clock and stops a second person
  from also replying to the same customer.
- Don't claim something you don't intend to work in the next few minutes
  just to "reserve" it — the claim expires on its own (see SLA tick below)
  and gets returned to the queue, which is the intended behavior, not a bug.

### What a restricted (external) booker can see and claim

A Restricted external booker (`promoter` role — `claim_leads` without
`manage_booking_inbox`) is scoped to:

| They can... | When |
|---|---|
| **See** an inquiry | it's assigned to them, they own it, they're a watcher, **or** it's still unassigned/unclaimed and in the open triage set (`new`/`classified`) |
| **Claim** an inquiry | assigned to them, owner, watcher, **or** unassigned+unclaimed and in the open triage set |
| **Claim** an inquiry assigned to someone else | never — always rejected, both in the UI and at the API |

In practice: a restricted booker can self-serve fresh, not-yet-triaged
inquiries out of the unassigned queue, but can't reach into another
person's assigned or claimed work. See `LeadsInbox::canClaim()` for the
exact rule and `tests/booking_inbox_role_scope_db_test.php` for the test
matrix.

### Quarantine: promote vs. leave skipped

Quarantined mail (the intake filter's low-confidence/junk bucket, reviewed
via the "Quarantined Mail" dialog off the Inbox) should be **promoted** to a
real inquiry when it's a genuine, if messy, booking request that the filter
misjudged — a legitimate inquiry stuck in quarantine helps no one. Leave it
**skipped**/quarantined when it's actually spam, an automated bounce/vendor
notice, or unrelated correspondence that happened to hit the intake mailbox
— promoting those just pollutes the triage queue with noise every booker
then has to re-triage.

### SLA tick

`scripts/lead-sla-tick.php` (cron every 5 minutes, see Setup above) does two
things automatically — nothing to do manually here:

- **Assigned but never claimed** past `sla_claim_due_at` → returned to the
  unassigned queue so someone else can pick it up.
- **Claimed but gone stale** past `claim_expires_at` → claim released
  (`claim_expired`), lead returned to assigned/unassigned.

If an inquiry keeps bouncing back to unassigned, that's the tick doing its
job — the fix is claiming it and then actually taking a claim-preserving
action (reply, log a call, schedule a tour, etc.) before the deadline, not
disabling the sweep.

### High-value decline → approval, not a direct decline

Declining/losing/archiving a lead above `lead_inbox_settings.high_value_threshold`
requires `decline_high_value_leads`. A booker without that capability who
tries anyway doesn't get blocked outright — a `lead_approval_requests` row
is created instead, and the inquiry stays in its current status until a
manager approves or denies it from the Inbox header's approval banner. This
is intentional friction on high-value inquiries, not a bug — don't route
around it by marking the lead `archived`/`on_hold` as a workaround; put in
the approval request and follow up with a manager.

### Outbound identity

Auto-acknowledgments and manual replies both send from the same
venue-configured identity (`lead_inbox_settings.from_name`/`from_email`,
see `Panic\Leads\OutboundIdentity`) rather than a hard-coded address. If
that ever needs to change: `from_email` **must** stay the mailbox the Exim
ingestion pipe reads (`docs/booking-email-import.md`) — Mailer always sets
Reply-To to the same address as From, so pointing `from_email` anywhere
else means customer replies stop threading back into the Inbox.
