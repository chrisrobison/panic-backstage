# Panic Backstage — Follow-Up Roadmap

Current status of the follow-up work first identified after the June 2026
venue-operations upgrade. This file tracks only implementation status and
remaining gaps; the authoritative API contract is `docs/openapi.yaml`.

## Completed

### Square POS ledger import

- `POST /api/webhooks/square-pos` verifies the Square signature and imports
  mapped bar, merchandise, or other revenue into the event ledger.
- Admin → Payments manages venue/location mappings through
  `/api/pos-location-map`.
- The shared `database/migrations/` directory serves both single-tenant and
  tenant databases; the retired `database/migrations/tenant/` copy must not be
  recreated.

### Payroll CSV export

- `GET /api/events/{id}/staffing/export` downloads one event's staffing CSV and
  requires `manage_staffing` for that event.
- `GET /api/payroll/export?start=YYYY-MM-DD&end=YYYY-MM-DD` downloads a
  venue-admin-only CSV across events; dates default to the current month.
- Both exports use each shift's `shift_date` (falling back to the event date)
  and include clock-in/out, actual/estimated/overtime hours, and staff contact
  details. Direct QuickBooks IIF and Gusto integrations are not implemented.

### Deposit payment links and receipts

- Event Payments can create hosted Stripe or Square checkout links and QR codes.
- Webhooks reconcile successful payments into `event_payments`, preserving
  provider references, fees, taxes, and private receipt/download tokens.
- Printable invoices reuse the same checkout URL and QR flow. See
  `docs/deposit-payments.md`.

### Client portal and report sharing

- The event workspace Share action creates expiring, revocable links without a
  staff login.
- `client_portal` links expose the event summary, contract status, inbound
  payments, and client-safe invoice lines.
- `settlement_report` links expose the formal Settlement Statement and require
  `view_settlement` on the event.

### CRM follow-up reminders

- Settling an event creates follow-up tasks through `CrmProfiles`.
- `POST /api/crm-followups` emails due/overdue reminders (up to seven days
  overdue). Kernel authentication currently runs before the endpoint, so a cron
  caller needs a valid Bearer token plus `X-Cron-Secret`; a venue-admin session
  can run it without the header.

### Incident resolution workflow

- Incident execution records support restricted visibility, admin notification,
  required resolution notes, and `resolved_at` / `resolved_by_id` audit fields.
- The Execution UI exposes the resolve action and shows resolution state.

### Promote auto-publish

- Admin → Promote Settings stores an enable switch and destination allow-list.
- Moving an event to `published` invokes `Events::maybeAutoPublish()`, creates or
  reuses campaign/post records, and broadcasts to the configured destinations.
- Failures are logged and do not roll back the event status transition.

## Remaining Work

### Accounting provider delivery

`Accounting::onCloseoutFinalized()` already creates an auditable sync record,
builds a chart-of-accounts journal payload, and is called by Closeout finalize.
The QBO and Xero OAuth refresh and outbound Journal Entry/Manual Journal HTTP
calls remain explicit stubs, and there is no Admin accounting settings UI yet.

### Stripe Connect for SaaS tenants

Ticket and event-payment providers still use installation-level credentials.
Per-tenant Stripe Connect onboarding, connected-account storage, and account
routing remain unimplemented.

### Offline day-of writes

The installed service worker supports Firebase push but intentionally does not
cache the application or queue mutations. An IndexedDB-backed offline queue and
conflict/retry UX for execution records remain future work.

### Credential and backup operations

- Rotate `CREDENTIAL_ENCRYPTION_KEY` on an operating schedule using
  `scripts/rotate-credential-keys.php`, and keep old/new key handling consistent
  with `.env.example` during the rotation.
- Verify database backups include all current schemas, especially booking inbox,
  closeout/payee tracking, ticketing, portal tokens, staff documents,
  acknowledgments, assignments, and certifications.
- Back up encrypted credential ciphertext and the encryption key separately;
  never store the key in the database backup itself.

*Last reviewed against implementation: 2026-08-30*
