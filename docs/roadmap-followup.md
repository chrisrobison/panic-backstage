# Panic Backstage — Follow-Up Roadmap

Items carried forward from the venue operating system upgrade (completed 2026-06-23).
Each item is a self-contained feature that builds on the new backend infrastructure.

---

## High Priority

### POS Integration
- **Goal:** Square POS (bar/merch) sales automatically appear as `bar_sales` / `merch_share` ledger entries instead of manual entry.
- **Approach:** Square Webhooks → `/api/webhooks/square` → insert into `event_ledger_entries` (source='pos', source_ref_id=square_payment_id). Match to event by date + venue.
- **Needs:** Square API key (already in `.env`), Square location ID, event-to-POS-location mapping.
- **Effort:** ~2–3 days.

### Payroll Export
- **Goal:** Export actual labor hours (clock-in/out from `event_staffing`) in a format usable by payroll systems (CSV, QuickBooks IIF, or Gusto API).
- **Approach:** New `/api/events/{id}/staffing/export` endpoint returning CSV; or a batch export `/api/payroll/export?period=...` aggregating across events.
- **Needs:** Confirm payroll system/format. `actual_hours` column is already tracked.
- **Effort:** ~1–2 days for CSV; ~3–5 days for direct integration.

---

## Medium Priority

### Accounting Integration
- **Goal:** Push finalized event P&L to QuickBooks Online or Xero automatically after closeout.
- **Approach:** On `event_closeout_state.finalized_at` set, sync `event_ledger_entries` as a Journal Entry via QBO/Xero OAuth API. Map ledger categories to chart-of-accounts codes (configurable in Admin → Accounting).
- **Needs:** QBO or Xero API credentials; chart-of-accounts mapping table; OAuth flow for accounting provider.
- **Effort:** ~5–8 days.

### Payment Links / Online Deposits
- **Goal:** Send promoters/clients a Stripe or Square invoice link for their deposit directly from the Payments panel.
- **Approach:** New "Send Invoice Link" action in `/api/events/{id}/payments` — creates Stripe Payment Link or Square Invoice → updates `event_payments.status='invoiced'`; webhook confirms receipt.
- **Needs:** Stripe Connect per-tenant OR existing Stripe key; payment_settings table already exists.
- **Effort:** ~3–4 days.

### Stripe Connect (SaaS mode)
- **Goal:** Per-tenant Stripe accounts so each Backstage tenant processes their own payments.
- **Approach:** Stripe Connect OAuth flow during tenant onboarding; store connected account ID in tenant config; route payment API calls to connected account.
- **Effort:** ~4–6 days.

---

## Lower Priority / Future

### Client Portal
- **Goal:** Read-only web portal for promoters/clients to view their event details, contract status, invoice, and payment history without needing a Backstage login.
- **Approach:** Separate `/portal/{token}` route; short-lived signed token (similar to magic-link pattern); read-only views of event, contract, `event_payments`, `event_ledger_entries` (revenue lines only).
- **Needs:** Portal token table; portal-specific templates for event summary and invoice.
- **Effort:** ~5–7 days.

### CRM / Email Delivery for Follow-Ups
- **Goal:** Auto-generated follow-up tasks (created by `CrmProfiles::createFollowupTasks()`) actually send emails, not just create internal notes.
- **Approach:** Hook into existing `Outbox` / message system; or add a scheduled job that checks `client_notes` where `type IN ('task','followup')` and `due_date <= today` and `is_done = 0`.
- **Needs:** Confirm email delivery infrastructure (SMTP config is already in `.env`).
- **Effort:** ~2–3 days.

### Incident Reporting Workflow
- **Goal:** Incidents logged in Execution Records trigger a formal review workflow — notification to venue admin, required resolution note, optional escalation.
- **Approach:** On INSERT to `event_execution_records` where `record_type='incident'`, emit a notification (email + dashboard alert). Add `resolved_at`, `resolved_by_id`, `resolution_notes` columns to the table.
- **Effort:** ~2–3 days.

### Mobile / Offline Day-Of Mode
- **Goal:** Staff can log bar notes, damage, and incidents from a phone during an event — even with poor connectivity.
- **Approach:** PWA with service worker; IndexedDB queue for offline writes; sync on reconnect. Simplified mobile-first UI for execution records only.
- **Effort:** ~1–2 weeks.

### Luma / Eventbrite Auto-Publish from Booking
- **Goal:** When an event reaches `published` status, automatically create/update the Luma or Eventbrite listing using the existing Promote integration.
- **Approach:** Status transition hook in `Events::validateStatusTransition()` → trigger Promote broadcast if auto-publish setting is enabled.
- **Effort:** ~2–3 days (Promote infrastructure already exists).

---

## Infrastructure / Ops

### Key Rotation Reminder
- `CREDENTIAL_ENCRYPTION_KEY` should be rotated annually. Add a `systems_inventory` entry with `renewal_date` set to next year and `expiry_alert_days=30` so it surfaces in the dashboard.
- Run: `INSERT INTO systems_inventory (name, category, url, owner, purpose, renewal_date, expiry_alert_days) VALUES ('Credential Encryption Key', 'security', NULL, 'venue_admin', 'Encrypts OAuth tokens at rest (libsodium secretbox). Rotate annually.', DATE_ADD(CURDATE(), INTERVAL 1 YEAR), 30);`

### Database Backup Verification
- Verify that automated backups cover the new tables: `leads`, `event_payments`, `event_ledger_entries`, `client_profiles`, `venue_policies`, `event_execution_records`.
- Ensure backup includes `promote_credentials.enc_access_token` (ciphertext) and the key is separately backed up in a vault.

---

*Last updated: 2026-06-23*  
*Owner: Christopher Robison*
