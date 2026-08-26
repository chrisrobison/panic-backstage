---
title: Institutional Knowledge Audit
slug: knowledge-audit
document_type: policy
version: 0.1
effective_date:
requires_acknowledgment: false
status: draft
---

<!-- requires_acknowledgment: false — this is an internal audit document for management/workstream owners, not a staff policy. -->

# Institutional Knowledge Audit

This document tracks, honestly, what the rest of the Staff Handbook & Compliance content library
actually knows versus what it's guessing at, deferring, or flatly cannot determine. Its entire
value is refusing to paper over uncertainty — treat a thin "Confirmed" section and a long
"Management Decisions Needed" section as success, not failure.

## Confirmed

Things clearly established by the app's actual code/data/workflow, safe to state as fact in
staff-facing docs:

- **Event lifecycle pipeline**: Hold → Intake Complete → Booked → Settled.
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
- **Café/kitchen operations are described as "being developed"** — not a fully built standing
  operation.
- **Regulatory context that is real and citable, independent of Mabuhay's specific practice**:
  California RBS certification requirement (on-sale licensed premises, since 2022); SB 1343
  sexual-harassment-prevention training requirement (employers with 5+ employees, every 2 years);
  California/San Francisco food handler card requirements for certain food-service roles;
  California BSIS Guard Card requirement for security guard work generally.

## Probable — Verify

Strongly implied by the software or general practice but needing a human to confirm before
being stated as fact:

- Exact per-space occupancy/capacity numbers (a per-venue-space capacity configuration concept
  exists; the specific current numbers were not confirmed).
- Exact field-level status strings beyond Hold/Intake Complete/Booked/Settled (the task brief
  flagged possible additional granular states like "Advance"/"Complete" — unconfirmed).
- The exact 7 checklist items required to finalize closeout.
- Whether the app tracks RBS certification expiry, food handler card expiry, or BSIS Guard Card
  status as distinct fields (probably not, based on what's known of the data model, but not
  confirmed).
- Whether a dedicated time-clock/clock-in-out feature exists distinct from staffing/scheduling.
- Current door headcount method (scanner count vs. manual clicker vs. both).
- Fire extinguisher/pull-station locations, first aid/AED locations and staff training status,
  designated outdoor evacuation assembly point(s).
- Current accessibility accommodation process, current re-entry policy, current
  smoking/vaping/photography signage and enforcement.

## Missing

Operational information the software simply cannot determine, because it either isn't a
software concern or isn't built yet:

- Wages, exact overtime/meal/rest-break policy, paid sick leave accrual rate, expense
  reimbursement policy, tip pooling arrangement — none of this lives in, or can be derived from,
  the app.
- The full event-night chain of command: who has final authority over the building, who can stop
  a performance, who authorizes expenditures/comps, who resolves promoter disputes, who decides
  when the venue closes. The app has an event Owner field and capability flags, but no single
  "who's in charge tonight" concept.
- Till count / cash drop / safe procedures — genuinely not built in software at all.
- Named contacts for internal reporting (harassment, safety, theft, etc.), including an alternate
  path when the complaint is about the reader's own manager.
- Whether a written IIPP and Workplace Violence Prevention Plan currently exist — this is an
  operational/compliance fact entirely outside the software's domain.
- Building key/alarm code holder policy — deliberately never captured in these docs even if it
  existed in the software, per the "no secrets in documents" rule.
- Café/kitchen procedures — can't be derived because the operation itself isn't built out yet.

## Contradictions

No hard contradictions were found between different parts of the app's actual behavior. The one
soft tension worth flagging:

- The event **Owner** field (reassignable only by `venue_admin`) is the closest system concept to
  "who's responsible for this event," but it is fundamentally a data-ownership/access-control
  concept, not necessarily the same person management would name as having final say on the floor
  during a live event. Treating them as identical in staff-facing material would be an
  invention this audit deliberately avoided — the Handbook and House Manager SOP both flag this
  as an open chain-of-command question rather than asserting Owner = floor authority.

## Management Decisions Needed

Every TODO written across the content library, grouped by document, so nothing gets lost between
here and the management interview.

### Handbook (`handbook.md`)

- Add House Manager, Booking/Event Coordinator, Café, Kitchen as distinct roster roles, or keep
  informal?
- Define final event-night chain of command (authority over the building, stopping a
  performance, expenditure/comp authorization, promoter disputes, closing decision).
- Create and maintain a "Current Rates & Compliance" reference separate from this handbook.
- Employment classification (hourly/exempt), scheduling process, call-out procedure, timekeeping
  mechanism, overtime approval, meal/rest break policy, payroll process, paid sick leave policy,
  expense reimbursement policy, tip pooling policy, personnel-info-update process.
- Confirm SB 1343 training delivery/tracking and the internal harassment/discrimination/
  retaliation reporting path.
- Disciplinary process for violence/threats/fighting and for confirmed theft.
- On-shift alcohol/drug policy, including staff drinking after their own shift while on premises.
- Policy on staff relationships with guests/artists/promoters and sexual conduct.
- Social media / photography-video policy for staff.
- Confidentiality expectations beyond what the software already restricts.
- Who may hold keys/know alarm codes, and the access grant/revoke process.
- Who may authorize non-working backstage guests, comps, and vendor gifts; conflict-of-interest
  disclosure expectations.
- Confirm current per-space occupancy limits.
- Confirm IIPP / Workplace Violence Prevention Plan status.
- Lost property process; all-ages/minor admission policy per event type; accessibility process;
  backstage credentialing mechanism; photography/recording policy;
  smoking/vaping/cannabis/outside-food/weapons/re-entry policy.
- Who tracks RBS certification/renewal.
- Document actual till/drop/safe procedures.
- Build a real internal "Contacts" reference, including an alternate path for complaints about a
  reader's own manager.
- Legal review of the handbook acknowledgment language.

### Emergency (`emergency.md`) / Venue Safety (`venue-safety.md`)

- On-site first aid/AED locations and trained staff.
- Fire extinguisher/pull-station locations and staff training.
- Designated outdoor evacuation assembly point(s).
- Confirm per-space occupancy limits and headcount method.
- IIPP / Workplace Violence Prevention Plan status.
- Ladder/rigging use authorization and training.
- Hearing protection stocking/availability.
- Expected timeline for logging an incident record after it happens, and who reviews them.

### Alcohol Service (`alcohol-service.md`)

- Complete, current ABC-acceptable ID list (legal/regulatory review).
- Exact procedure once a guest is cut off.
- Current last-call timing.
- Additional escalation steps for suspected drink tampering.
- Who tracks RBS certification/renewal per employee.

### Booking SOP (`sop/booking.md`)

- Who approves final deal terms before a contract is sent.
- Deposit policy (amount, timing, consequence of non-payment).
- Confirm exact field-level status names/transitions in the app.

### Cash Handling SOP (`sop/cash-handling.md`) / Handbook Ch. 8

- Full till-count/cash-drop/safe procedure (who, when, witnessed by whom, escalation).

### Artist Settlement SOP (`sop/artist-settlement.md`)

- Who is authorized to use `force` to override the finalize gate, and required documentation.
- The exact 7 finalize-checklist items.
- Source-of-truth rule when door reconciliation and ticketing numbers disagree.

### Door SOP (`sop/door.md`)

- All-ages/minor admission policy communication to door staff.
- Exact capacity number and headcount method.
- Re-entry policy.

### Security SOP (`sop/security.md`)

- Whether BSIS Guard Card is required and how it's verified.
- Use-of-force/removal guidelines and training requirements.

### House Manager SOP (`sop/house-manager.md`) / Opening SOP (`sop/opening.md`) / Closing SOP (`sop/closing.md`)

- Final chain-of-command definition (repeated from Handbook — same open question).
- Who is authorized to open the building.
- Exact opening walkthrough checklist, if one exists beyond this SOP.
- Exact end-of-night checklist and sign-off owner.

### Stagehand SOP (`sop/stagehand.md`)

- Who authorizes non-performing guests backstage, and how credentials are verified in the moment.

### Café SOP (`sop/cafe.md`) / Kitchen SOP (`sop/kitchen.md`)

- Current operational status and timeline for real service.
- Which roles require food handler cards and who tracks them.
- Opening/service/closing food-safety procedures once operations are live.
- Food-safety and equipment-failure incident response once live.

### Cleaning SOP (`sop/cleaning.md`) / Barback SOP (`sop/barback.md`)

- Specific pre-open checklist and supply par levels, if different from general practice.

### Event Coordinator SOP (`sop/event-coordinator.md`)

- Whether this should be a distinct assignable role or stay covered by Booking + House Manager.

---

See [`management-interview.md`](management-interview.md) for these same open questions organized
as an interview script, and the [`interviews/`](interviews/) worksheets for role-specific
follow-up conversations.
