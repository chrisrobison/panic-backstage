---
title: Institutional Knowledge Audit
slug: knowledge-audit
document_type: policy
version: 0.2
effective_date:
requires_acknowledgment: false
status: draft
---

<!-- requires_acknowledgment: false — this is an internal audit document for management/workstream owners, not a staff policy. -->

# Institutional Knowledge Audit

This document records what is grounded in Backstage or the verified venue policy, what operating
defaults version 0.2 adopts, and what still requires a physical check or specialist review. It is
kept separate from staff-facing policy so future editors can tell the difference between a system
fact, a management choice, and a building-specific fact.

## Confirmed

Things clearly established by the app's actual code/data/workflow, safe to state as fact in
staff-facing docs:

- **Event lifecycle pipeline**: public events use Hold → Intake Complete → Booked → Needs Assets
  → Assets Approved → Ready to Announce → Published → Advanced → Completed → Settled. Private
  events skip the promotion stages and move from Booked to Completed, then Settled.
- **Staff roster operational roles** (`staff_members.default_role`): manager, security,
  bartender, barback, door, sound, lighting, stagehand, runner, cleaner, other. House Manager,
  Booking/Event Coordinator, Café, and Kitchen are not distinct values in this enum today.
- **App-level user roles** (access control): venue_admin, event_owner, promoter, band, artist,
  designer, staff, viewer, global_viewer.
- **Event-day capabilities**: `view_incidents`/`manage_incidents` (types: incident, change_order,
  bar_note, damage, overage — incidents and safety_notes restricted-visibility by design),
  `manage_ledger`/`finalize_closeout`, `manage_staffing`, `manage_guest_list`,
  `manage_ticketing`, `view_execution`/`manage_execution`, and `reassign_owner`
  (`venue_admin`-only).
- **Booking Inbox workflow**: inquiries from `bookings@themab.org`, the website widget, phone, or
  manual entry land in a shared inbox, never a personal one. States: Assigned → Claimed (with an
  expiry countdown back to the queue) → Owned (auto on first real reply, or manual by a
  manager). Internal notes vs. customer-facing replies are distinct. Duplicate-reply protection
  blocks sending on conversation drift and surfaces in-progress drafts. An AI classifier suggests
  routing but never acts autonomously — a human always claims and decides.
- **Contracts**: deal-builder plus clause library, sent for e-signature, and audit-logged on
  view/sign/decline.
- **Event ledger / closeout**: `event_ledger_entries` tracks revenue, costs, and payments per
  event; each payee (artist, promoter, vendor, staff, client, other) can be marked
  paid/unpaid/partial; the system nets what's still owed per payee automatically; **finalizing
  closeout returns HTTP 422 if any payee still shows a positive owed balance, or if a 7-item
  checklist isn't complete**, and can only be overridden with an explicit `force`. A "Door sales
  & settlement doc" section captures ticket count, gross ticket sales, and a link to an external
  settlement document.
- **Ticketing**: tiers, orders, discounts, QR tickets, a door Scanner for admit/lookup, physical
  pre-printed ticket batches with their own registration/PDF flow. Guest lists exist per event.
- **No till/safe-drop feature exists in software today.** Cash handling at point-of-sale is not
  encoded in the app beyond the ledger's payee/payment tracking.
- **Staffing records contain clock-in, clock-out, actual hours, and approved overtime hours**, and
  Backstage can export payroll CSVs by event or date range.
- **Certification records exist** for RBS, Guard Card, Food Handler, harassment-prevention, and
  other training, including issue/expiration dates, certificate number, document, and manager
  verification.
- **Incident workflow exists**: incident creation notifies venue administrators, and resolution
  requires a resolution note and records who resolved it and when.
- **Verified venue policy, version 1** *(Mabuhay's own record — not part of the generic starter
  template shipped to other tenants, which presents these same figures as illustrative defaults;
  see docs/staff/handbook.md § 5–6, alcohol-service.md, and sop/booking.md)*: upstairs is all
  ages/capacity 450; downstairs is 21+/capacity 350; standard deposit is 25% due in 14 days; venue
  curfew is 2:00 a.m.; contracts and certificates of insurance are required by default.
- **The seven closeout checks** are contract signed, deposit received, vendors confirmed,
  staffing confirmed, bar closed, cash reconciled, and all invoices collected.
- **Café/kitchen operations are inactive** pending a formal activation package and trained staff.
- **Regulatory context that is real and citable, independent of Mabuhay's specific practice**:
  California RBS certification requirement (on-sale licensed premises, since 2022); SB 1343
  sexual-harassment-prevention training requirement (employers with 5+ employees, every 2 years);
  California/San Francisco food handler card requirements for certain food-service roles;
  California BSIS Guard Card requirement for security guard work generally.

## Operating Defaults Adopted in Version 0.2

- Venue administration owns policy, access, certifications, payroll administration, contract
  exceptions, and final financial authority.
- The event Owner owns booking and advance work. The named House Manager runs the floor from
  load-in through lockup. Event Coordinator remains a functional assignment split between them
  unless a separate coordinator is named.
- Routine event-budgeted comps and backstage additions sit with the House Manager; exceptions go
  to venue administration.
- Staff use Backstage staffing records for assignments and timekeeping. Routine schedules target
  seven days' notice, and shift changes require manager approval in the event record.
- Opening and closing cash use two-person signed counts. The House Manager controls drops and
  reports discrepancies; Backstage remains the event-level ledger rather than the drawer ledger.
- Café and kitchen stay closed until venue administration and a named lead complete the activation
  requirements in their SOPs.
- The default is no re-entry unless the event brief allows it. Backstage credentials use a
  distinct event wristband or laminate issued by the House Manager.
- `force` closeout is limited by policy to venue administrators with `finalize_closeout` and
  requires a written exception note.

## Remaining Verification Before Publication

These items require a site walk, private personnel/payroll information, or professional review;
they should not be guessed from code:

- Exact fire-extinguisher, pull-station, first-aid kit, AED, and evacuation assembly-point
  locations; current inspection status; and which staff hold first-aid/CPR/AED training.
- The live door headcount method and the location/format of physical cash count sheets and secure
  drops. Security-sensitive locations and access methods stay out of public documents.
- Current IIPP and Workplace Violence Prevention Plan documents and review dates.
- Current payroll provider, payday, leave accrual details, wage/rate schedule, tip arrangement,
  and the two private reporting contacts published to staff.
- Whether specific security assignments require a BSIS Guard Card, plus the venue's approved
  training vendor and any separate equipment/restraint policy.
- Final legal/regulatory review of the handbook acknowledgment, ID guidance, employment sections,
  alcohol procedures, and food-operation activation package.
- Cleaning/bar stock par levels and any event-specific opening checklist that exists outside this
  library.

Use [`management-interview.md`](management-interview.md) and the [`interviews/`](interviews/)
worksheets to validate these defaults with the people doing the work and to capture the remaining
site-specific facts.
