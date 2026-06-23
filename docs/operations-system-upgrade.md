# Panic Backstage — Operations System Upgrade

> Authored June 2026. This document covers the design, migrations, and assumptions for
> evolving Panic Backstage from an event-production tool into a complete venue operating
> system covering the full lifecycle:
>
> **Lead → Evaluate → Contract + Deposit → Advance → Execute → Closeout → Retain**

---

## 1. Existing Event Lifecycle and Relevant Files

### Current status flow
```
empty → proposed → confirmed → booked → needs_assets → ready_to_announce
      → published → advanced → completed → settled → canceled
```

### Key files

| Area | Files |
|------|-------|
| Event CRUD | `src/Events.php`, `src/Events/Tasks.php`, `src/Events/Blockers.php` |
| Contracts | `src/Contracts.php`, `src/ContractService.php`, `src/ContractRenderer.php`, `src/Events/Contracts.php` |
| Staffing | `src/Events/Staffing.php` |
| Settlement | `src/Events/Settlement.php` |
| Ticketing | `src/Events/Ticketing.php`, `src/Payments/` |
| Promote credentials | `src/Promote/CredentialSettings.php`, `src/Promote/Adapters/` |
| Capabilities | `src/BaseEndpoint.php` — `EVENT_CAPABILITIES`, `GLOBAL_CAPABILITIES` |
| Database | `src/Database.php` — thin PDO wrapper |
| Migrations | `database/migrations/` (single-tenant), `database/migrations/tenant/` (multi-tenant) |

### What already works well
- Event workspace (tasks, blockers, lineup, schedule, assets, guest list)
- Contract builder with modules, templates, and e-signature
- Staffing assignment with capacity-tier presets
- Ticketing (Stripe + Square) with ticket views and scanner
- Basic settlement (flat totals per category)
- Promote with credential storage per destination
- Multi-tenant architecture with per-tenant database isolation

---

## 2. Gaps Being Addressed

### 2.1 Security: Encrypted Credentials
**Gap**: `promote_credentials.access_token` and `refresh_token` are stored in plaintext.  
**Fix**: Application-level encryption using `sodium_crypto_secretbox`. Master key from `CREDENTIAL_ENCRYPTION_KEY` env var (never in DB). Ciphertext stored as `enc_*` columns; old plaintext columns kept during migration, cleared after.  
**Files**: `src/CredentialEncryption.php`, updated `src/Promote/CredentialSettings.php`, migration `022`/`010`.

### 2.2 Lead Inbox
**Gap**: No pipeline before event creation; no source tracking; no qualification.  
**Fix**: `leads` table with full status flow (new→triage→evaluating→approved→converted/declined), notes, tasks, deal evaluation. Lead converts to an event preserving source data.  
**Files**: `src/Leads.php`, `src/Events/DealEvaluator.php`, migration `023`/`011`.

### 2.3 Contract + Deposit Gate
**Gap**: Events can reach "Booked" without a signed contract or received deposit.  
**Fix**: `event_payments` table for payment records; `events.deposit_status` column; booking status gate in `Events::update()`; readiness panel in event detail.  
**Files**: `src/Events/Payments.php`, migration `024`/`012`.

### 2.4 Private Event Closeout & Billing
**Gap**: Private events reach "Settlement" but that screen is public-show-oriented.  
**Fix**: `event_ledger_entries` append-only ledger covering all revenue/cost/payment categories. New `closeout` sub-resource for private events; original settlement preserved for public shows.  
**Files**: `src/Events/Ledger.php`, `src/Events/Closeout.php`, migration `025`/`013`.

### 2.5 Vendors, Insurance, and Advance
**Gap**: No first-class vendor tracking; no COI tracking.  
**Fix**: `event_vendors` table with service category, quote/actual, COI status, confirmation state.  
**Files**: `src/Events/Vendors.php`, migration `026`/`014`.

### 2.6 Staffing v2
**Gap**: Staffing presets replace everything destructively; no clock-in/out; no actual labor cost.  
**Fix**: `from-capacity` becomes a non-destructive preview (`/preview` sub-action); `source` column (generated/template/manual); `clock_in`, `clock_out`, `actual_hours`, `approved_overtime_hours` columns; actual labor cost calculated server-side.  
**Files**: Updated `src/Events/Staffing.php`, migration `027`/`015`.

### 2.7 Live Execution Records
**Gap**: No structured day-of records (incidents, change orders, overages, etc.).  
**Fix**: `event_execution_records` table with type enum (incident, change_order, bar_note, damage, overage, checklist, deviation, safety_note); restricted visibility for incidents.  
**Files**: `src/Events/Execution.php`, migration `028`/`016`.

### 2.8 Post-Event CRM / Retention
**Gap**: No client/promoter relationship tracking; no follow-up task generation after settlement.  
**Fix**: `client_profiles` table with event history, revenue, rebook potential, relationship status. Follow-up tasks auto-generated on event settled.  
**Files**: `src/CrmProfiles.php`, updated settlement flow, migration `029`/`017`.

### 2.9 Venue Policy Configuration
**Gap**: Room names, capacities, age rules, deposit policies hardcoded or absent.  
**Fix**: `venue_policies` table (versioned, effective-dated); events and contracts snapshot the policy at time of booking.  
**Files**: `src/VenuePolicy.php`, migration `030`/`018`.

### 2.10 Systems Inventory
**Gap**: No lightweight catalog of connected platforms/services with renewal/contact info.  
**Fix**: `systems_inventory` table — name, URL, owner, purpose, vault reference, renewal date, notes. No passwords stored.  
**Files**: `src/SystemsInventory.php`, migration `031`/`019`.

---

## 3. Database Changes

### New tables (tenant migrations 010-019 / global migrations 022-031)

| Table | Purpose |
|-------|---------|
| `leads` | Lead pipeline before event creation |
| `lead_notes` | Notes, tasks, and audit entries on leads |
| `lead_deal_evaluations` | Deal math snapshots (server-calculated) |
| `event_payments` | Individual payment/invoice records (deposit, balance, etc.) |
| `event_ledger_entries` | Append-only financial ledger replacing flat settlement |
| `event_vendors` | Vendor records per event |
| `client_profiles` | Promoter/client CRM records |
| `client_events` | Many-to-many linking profiles to events |
| `venue_policies` | Versioned venue policy configuration |
| `systems_inventory` | Non-credential systems catalog |

### Modified tables

| Table | Changes |
|-------|---------|
| `events` | `deposit_status`, `lead_id`, `policy_snapshot_json`, `is_private` columns |
| `event_staffing` | `source`, `clock_in`, `clock_out`, `actual_hours`, `approved_overtime_hours` columns |
| `promote_credentials` | `enc_access_token`, `enc_refresh_token`, `enc_key_version`, `enc_nonce` columns; plaintext columns nulled post-migration |
| `users` | `onboarding_dismissed` (migration 021/009, already applied) |

---

## 4. API Changes

### New endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET/POST | `/api/leads` | Lead list / create |
| GET/PATCH/DELETE | `/api/leads/{id}` | Lead detail / update / delete |
| POST | `/api/leads/{id}/notes` | Add note/task to lead |
| POST | `/api/leads/{id}/convert` | Convert lead to event |
| GET/POST | `/api/leads/{id}/evaluation` | Deal evaluation (server-calculated) |
| GET/POST | `/api/events/{id}/payments` | Payment records for event |
| PATCH/DELETE | `/api/events/{id}/payments/{pid}` | Update/delete payment |
| GET/POST | `/api/events/{id}/ledger` | Ledger entries |
| GET | `/api/events/{id}/ledger/summary` | Server-calculated P&L summary |
| GET | `/api/events/{id}/closeout` | Closeout & billing view |
| POST | `/api/events/{id}/closeout/finalize` | Finalize closeout (gated) |
| GET/POST | `/api/events/{id}/vendors` | Vendor records |
| PATCH/DELETE | `/api/events/{id}/vendors/{vid}` | Vendor detail |
| GET/POST | `/api/events/{id}/execution` | Live execution records |
| PATCH/DELETE | `/api/events/{id}/execution/{rid}` | Execution record detail |
| POST | `/api/events/{id}/staffing/preview` | Non-destructive staffing preview |
| GET/POST | `/api/crm-profiles` | Client/promoter CRM |
| GET/PATCH | `/api/crm-profiles/{id}` | Profile detail |
| GET/POST | `/api/venue-policy` | Policy config |
| GET/POST | `/api/systems-inventory` | Systems catalog |
| PATCH/DELETE | `/api/systems-inventory/{id}` | Item detail |
| GET | `/api/promote/credentials` | (unchanged, tokens still never returned) |
| PUT | `/api/promote/credentials/{key}` | Now encrypts before saving |
| POST | `/api/admin/rotate-credential-keys` | Admin: re-encrypt all credentials with new key version |

### Modified endpoints

| Endpoint | Change |
|----------|--------|
| `PATCH /api/events/{id}` | Gate: blocks transition to booked/confirmed without executed contract + received/waived deposit |
| `GET /api/events/{id}` | Adds `deposit_status`, `payment_summary`, `readiness_panel`, `policy_snapshot` |
| `GET /api/dashboard` | Adds `leads_needing_review`, `contracts_awaiting_signature`, `deposits_overdue`, `events_awaiting_closeout`, `overdue_followups` |
| `POST /api/events/{id}/staffing/from-capacity` | Becomes non-destructive by default; add `?replace=1` to force replace |

---

## 5. Migration / Backward-Compatibility Strategy

- All migrations use `CREATE TABLE IF NOT EXISTS` and `ADD COLUMN IF NOT EXISTS` so they are safe to re-run.
- Existing `event_settlements` table and `/api/events/{id}/settlement` endpoint are preserved untouched. Legacy events continue to use the flat settlement model.
- New ledger system is additive — events can have both `event_settlements` (legacy) and `event_ledger_entries` (new).
- `promote_credentials` keeps the plaintext `access_token` / `refresh_token` columns during migration. The encrypt-credentials CLI script (`scripts/encrypt-credentials.php`) reads plaintext, encrypts, writes to `enc_*` columns, then nulls plaintext. The script is idempotent.
- Existing integrations (Promote adapters) use a `CredentialEncryption::decrypt()` shim that falls back to plaintext if `enc_access_token` is NULL (covers pre-migration rows).
- Events without a deposit record get `deposit_status = 'not_required'` default — this means the booking gate is not retroactively enforced on old events.
- The staffing `from-capacity` endpoint becomes non-destructive by default. `?replace=1` or the body flag `{"replace": true}` restores destructive behavior for callers that already relied on it.

---

## 6. New Capabilities

Added to `src/BaseEndpoint.php`:

**Event-scoped:**
- `manage_leads` — create/edit leads, convert to event
- `view_leads` — read-only lead access
- `manage_vendors` — vendor records
- `manage_payments` — payment/invoice records
- `manage_ledger` — ledger entries (add line items)
- `finalize_closeout` — finalize event closeout (finance role)
- `view_execution` — view live execution records
- `manage_execution` — create/edit execution records
- `view_incidents` — view incident-type execution records (restricted)
- `manage_incidents` — create/edit incidents (restricted)

**Global:**
- `manage_leads` — full lead pipeline access
- `manage_crm_profiles` — client/promoter CRM
- `manage_venue_policy` — edit venue policy
- `manage_systems_inventory` — systems catalog
- `admin_credential_encryption` — rotate encryption keys
- `waive_deposit` — waive deposit requirement (high-privilege)
- `reopen_settlement` — reopen a finalized closeout/settlement

---

## 7. Assumptions and Unresolved Venue-Policy Items

The following items need confirmation from Mabuhay Gardens before going live:

1. **Deposit amounts**: Is deposit always a fixed percentage of rental fee, or event-specific? Currently stored per-event; policy config allows a default percentage.
2. **Bar minimum thresholds**: What are the room-specific bar minimums? Currently in contracts; policy config provides a default.
3. **Alcohol service rules**: The current schema has `age_restriction` as a free text field. Policy config adds structured `age_rule` (all_ages / 18_plus / 21_plus) and `alcohol_mode` (none / cash_bar / hosted_bar / bar_minimum).
4. **Staffing rate sheet**: Hourly rates by role are currently per-shift overrides. Policy config adds default rates per role — confirm current rates.
5. **COI requirements**: Is COI required for all private events? All external vendors? Currently defaulted to "required" for private events — adjust in policy config.
6. **Settlement finalization authority**: Who can finalize a closeout and reopen it? Currently `venue_admin` only — confirm if a finance/operations role is needed.
7. **Follow-up task assignees**: Post-event follow-up tasks (thank-you, feedback, rebooking) are auto-assigned to the event owner. Confirm if they should go to a specific role instead.
8. **Systems inventory access**: Should systems inventory be visible to all venue_admins or only specific roles? Currently all venue_admins.
9. **Encryption key rotation cadence**: Recommend rotating `CREDENTIAL_ENCRYPTION_KEY` annually. Rotation procedure is documented in `scripts/rotate-credential-keys.php`.
10. **POS integration**: Bar sales are entered manually (or via import adapter). Square POS integration is a separate future phase.

---

## 8. Key Design Decisions

- **No client-side financial calculations for approvals or closeout**: All `venue_net`, P&L, and deal evaluation totals are computed server-side. Client submits line-item inputs; server returns computed totals.
- **Append-only ledger**: `event_ledger_entries` uses INSERT only (no UPDATE to amounts). Corrections are additional entries (adjustment type). Full audit trail preserved.
- **Staffing preview vs. replace**: `POST /staffing/from-capacity` now returns a preview diff. The caller must explicitly pass `replace: true` (or use `/from-capacity?replace=1`) to apply. This prevents accidental destruction of manually edited staffing.
- **Private vs. public event workflow**: `is_private` flag on events gates the UI pathway — private events see "Closeout & Billing" instead of "Settlement", no public event page, no Promote workflow.
- **Lead → Event conversion**: Conversion is atomic (DB transaction). Lead fields map to event fields with a recorded audit trail. Lead status set to `converted`; `events.lead_id` FK set.
- **Credential encryption fallback**: During and after migration, `CredentialEncryption::decrypt()` checks `enc_access_token` first; falls back to `access_token` (plaintext) if encrypted value is NULL. This allows a rolling migration without service interruption.
