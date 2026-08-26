---
title: Staff Handbook
slug: handbook
document_type: handbook
version: 0.1
effective_date:
requires_acknowledgment: true
status: draft
---

# Staff Handbook

## 1. Welcome to Mabuhay Gardens

If you're reading this, you're either about to work at, or already work at, one of the
buildings that helped invent a scene. Mabuhay Gardens — "the Mab" — was a nightclub on
Broadway in San Francisco's North Beach that became one of the essential venues of the
late-1970s/early-1980s American punk and new wave underground, giving stage time to bands
that went on to matter far beyond San Francisco.

> **VERIFY — Confirm before publishing:** exact founding year, specific ownership history,
> specific bands/dates/eras, and any anecdotes about the original room. This handbook
> intentionally does not state any of these as fact. If marketing or an owner wants specific
> history in this document, it should come from a verified source (not this draft) before
> publication.

What isn't in question is the throughline: Mabuhay Gardens has always been a place that made
room for unusual people and unusual ideas. Today that means live music, comedy, karaoke, and
private/corporate events, running across more than one room in the building, with food and
beverage service layered on top and more of it "being developed" (see the Café/Kitchen notes
throughout this handbook).

That history is an asset, not a costume. The expectation for everyone who works here — staff,
management, contractors — is to carry that spirit in how the room feels to a guest, while
running the actual operation like professionals: on time, accountable, safe, and legal. The Mab
has always made room for unusual people and unusual ideas. That does not mean chaos is an
operating procedure.

### How to treat people

A few things apply to every role, every shift:

- **Artists and performers** are the reason people are in the room. Treat them, their gear, and
  their guests with respect, and route anything you can't resolve yourself (rider issues,
  schedule conflicts, payment questions) to whoever owns that event — see Chapter 2.
- **Renters and promoters** are customers of the venue. Contract terms are handled through the
  booking/contract workflow (see the Booking SOP), not improvised at the door or on the night of
  the show.
- **Patrons/guests** get served, carded, and looked after — and, when necessary, refused service
  or removed — per the Guest and Event Policies chapter and the Alcohol Service policy.
- **Coworkers** get a workplace free of harassment, discrimination, and retaliation. See Standards
  of Conduct.
- **Neighbors** — the Mab operates in a mixed-use, occupied neighborhood. Noise, sidewalk
  behavior, and load-in/load-out conduct reflect on the venue.

This handbook is the policy layer: what's expected and why. The exact steps for how to do your
job live in your role's Standard Operating Procedure (SOP) — see the [Staff Handbook index](../staff/README.md)
for the full list. Where this handbook says "per venue policy," a specific procedure document
will tell you the mechanics.

---

## 2. How The Mab Is Organized

### Operational roles

The staff roster in the app currently recognizes these operational roles
(`staff_members.default_role`):

- **manager**
- **security**
- **bartender**
- **barback**
- **door**
- **sound**
- **lighting**
- **stagehand**
- **runner**
- **cleaner**
- **other**

Some functions described elsewhere in this handbook and in industry practice — **House
Manager**, **Booking/Event Coordinator**, **Production Manager**, **Café/Kitchen staff** — are
not yet their own values in that list. In practice:

- **House Manager** is a function performed by someone with the `manager` role (or the
  app-level `venue_admin` role) on a given night, not a separate database role.
- **Booking/Event Coordinator**, **Café**, and **Kitchen** are real jobs people may do at the
  Mab, but the software has no matching operational-role value for them today. Assigning these
  responsibilities to specific people is a manual management decision, not something the app
  enforces.

> **TODO — Management decision required:** decide whether House Manager, Booking/Event
> Coordinator, Café, and Kitchen should become first-class roles in the staff roster, or remain
> informally assigned. If they should be added, that's a change request for the workstream that
> owns the data model — not something this handbook can do on its own.

Separately, the app has **app-level user roles** that control system access rather than job
function: `venue_admin, event_owner, promoter, band, artist, designer, staff, viewer,
global_viewer`. These control what a person can see and do in the software (for example,
`reassign_owner` — reassigning who owns an event — is restricted to `venue_admin`). They are not
the same thing as the operational roles above, and a person can hold one of each independently.

### Event-day capabilities

Separate from both role lists, the event-day system distinguishes specific capabilities that can
be granted per event or per person, including:

- `view_incidents` / `manage_incidents` — incident records (types: incident, change_order,
  bar_note, damage, overage). Incidents and safety notes are restricted-visibility by design.
- `manage_ledger` / `finalize_closeout` — the settlement ledger and the ability to finalize it.
- `manage_staffing`, `manage_guest_list`, `manage_ticketing`
- `view_execution` / `manage_execution`
- `reassign_owner` — `venue_admin` only.

These capabilities are how the software enforces "who can do what" for a given event. They are a
useful map of real accountability but they are not automatically the same as "who is in charge in
the room" — that's a human chain of command, covered next.

### Chain of command during an event

This is the single most important organizational question this handbook has to answer honestly,
and the software cannot answer all of it. Here is what's known and what's still open:

- **Who is in charge of the building on a given night** — the software has no single field that
  says "this person runs the room tonight." An event has an **Owner** (reassignable only by
  `venue_admin` via `reassign_owner`), which is the closest system concept, but Owner is about
  event data ownership, not necessarily "who has final say on the floor."
- **Who can stop a performance** — not established in software.
- **Who responds to emergencies** — not established in software; see the Emergency Procedures
  doc for the *actions* everyone should take regardless of title.
- **Who authorizes expenditures/comps** — partially bounded by `manage_ledger` capability for
  recording them, but *authorization authority* (who is allowed to say yes) is a policy decision,
  not a software permission.
- **Who resolves promoter disputes** — not established in software.
- **Who handles settlement** — the software supports this via the ledger/closeout workflow and
  the `manage_ledger` / `finalize_closeout` capabilities, but *which specific person* is expected
  to run closeout on a given night is a staffing decision.
- **Who decides when the venue closes** — not established in software.

> **TODO — Management must define final event chain of command.** Until this is written down
> and distributed, staff should assume the on-duty manager (or whoever management has designated
> as House Manager for that event) is the default point of authority, and escalate upward from
> there. That is a reasonable default, not a documented policy.

---

## 3. Employment Basics

This chapter intentionally contains very few numbers. Wage rates, specific leave accrual rates,
and other figures that change over time do not belong hardcoded in a handbook that's hard to
update — they belong in a separate, actively maintained **Current Rates & Compliance** reference
that management keeps current. Where this handbook needs a number, it should point there instead
of repeating a value that will eventually be wrong.

> **TODO — Management decision required:** create and maintain a "Current Rates & Compliance"
> reference (wage rates, overtime multiplier, meal/rest break timing, sick leave accrual rate,
> reimbursement rates, etc.) as a living document separate from this handbook, and keep it
> current with California/San Francisco law.

### Classification

> **TODO — Management decision required:** document which roles are hourly/non-exempt vs. any
> salaried/exempt positions, and how classification is determined and communicated to each
> employee.

### Scheduling

> **TODO — Management decision required:** how shifts are published/assigned, how far in
> advance, and how shift-swap requests are handled (does the app's staffing feature — see
> `manage_staffing` — track this, or is scheduling still done outside the app?).

### Attendance, lateness, and call-outs

> **TODO — Management decision required:** the exact call-out procedure (who to notify, by when,
> through what channel), what counts as excessive lateness/absence, and what happens as a result.

### Timekeeping

> **TODO — Management decision required:** how clock-in/clock-out is actually recorded today
> (the app does not currently appear to have a dedicated time-clock feature distinct from
> staffing/scheduling — VERIFY whether one exists before publishing this section as final).
> State plainly to staff: no unauthorized off-the-clock work — if you're doing venue work, you're
> clocked in, full stop — but the mechanism for enforcing that needs to be documented here once
> decided.

### Overtime

> **TODO — Management decision required:** overtime policy and who must pre-approve it.

### Meal and rest periods

> **TODO — Management decision required:** meal/rest break policy and how it's tracked. (California
> has specific legal requirements for meal and rest periods; this section needs legal/HR
> confirmation of current practice, not an invented schedule.)

### Payroll

> **TODO — Management decision required:** pay schedule, pay method, and who to contact about
> payroll errors.

### Paid sick leave

> **TODO — Management decision required:** California and San Francisco both have paid sick
> leave requirements; document Mabuhay's actual accrual/use policy here once confirmed with
> HR/legal. Do not state an accrual rate without confirmation.

### Expense reimbursement

> **TODO — Management decision required:** what's reimbursable, what documentation is required,
> and how reimbursement is requested.

### Tips and tip pooling

> **TODO — Management decision required:** whether/how tips are pooled, split, and reported, and
> how this is communicated to tipped roles (bartender, barback, door, etc.).

### Personnel information updates

> **TODO — Management decision required:** how staff update address/contact/tax information, and
> who maintains that record (this may already live in the staff roster in the app — VERIFY).

---

## 4. Standards of Conduct

The pattern in this chapter is consistent: the *issue* is stated plainly (these are not
optional, and several are legal requirements regardless of what Mabuhay decides), and the exact
*policy mechanics* are marked TODO where management hasn't yet supplied them to this document.

### Respectful workplace; harassment, discrimination, and retaliation

Harassment, discrimination, and retaliation against anyone — coworker, guest, artist, vendor —
based on a protected characteristic have no place at the Mab, period. California requires
employers with five or more employees to provide sexual-harassment-prevention training every two
years (SB 1343); that's real regulatory context, not a house preference.

> **TODO — Management decision required:** confirm SB 1343 training is currently being delivered
> and tracked (who schedules it, who verifies completion), and document the actual internal
> reporting path (see also Chapter 9, Reporting Problems) including the alternate path when a
> complaint is about the reader's own manager.

### Violence, threats, and fighting

Not tolerated, from anyone, toward anyone. See the Venue Safety and Emergency Procedures
documents for what to do if a violent or threatening situation happens on shift.

> **TODO — Management decision required:** the exact disciplinary/response process when this
> policy is violated by a staff member versus a guest.

### Theft

Theft of venue property, guest property, or cash is a serious violation. See the Cash and
Financial Controls chapter and the Cash Handling SOP for how discrepancies are actually
surfaced through the ledger today, and what's not yet built.

> **TODO — Management decision required:** disciplinary/legal response process for confirmed
> theft.

### Drugs and alcohol while working

> **TODO — Management decision required:** the specific on-shift substance policy (this venue
> serves alcohol as its business, which makes "no alcohol, ever, for anyone on shift" a real
> policy question rather than an obvious default — management needs to state the actual rule,
> including for staff drinking after their own shift ends while still on premises).

### Relationships with guests, artists, and promoters; sexual conduct

> **TODO — Management decision required:** any policy on staff pursuing romantic/sexual
> relationships with guests, artists, or promoters while working, and any rules about sexual
> conduct on premises.

### Social media, photography, and video

> **TODO — Management decision required:** what staff may post about the venue, artists, or
> guests (including backstage photos/video), and any required approvals.

### Confidentiality

Guest information, contract terms, financial details, and incident records are confidential.
Access to incidents and safety notes is intentionally restricted in the software
(`view_incidents`/`manage_incidents`) — if you don't have that capability, you don't have that
information, and that's by design, not an oversight.

> **TODO — Management decision required:** any additional confidentiality expectations beyond
> what the software already restricts (e.g., discussing settlement figures, guest list details).

### Keys, door codes, alarm codes, and backstage access

This handbook will never contain an actual code, combination, or credential — that's a security
requirement, not a formatting choice. What belongs here is *who* is allowed to hold keys/codes
and how access is granted and revoked.

> **TODO — Management decision required:** who may possess building keys, who may know alarm
> codes, how access is granted/revoked when someone's employment ends, and how backstage/green
> room access is controlled during an event (credentialing is referenced in Chapter 6).

### Bringing friends backstage; free drinks, comps, and vendor gifts; conflicts of interest

> **TODO — Management decision required:** who may authorize non-working guests backstage; who
> may authorize complimentary drinks/comps (the software records comps/costs through the ledger,
> but *authorization authority* is a policy call — see Chapter 8); any policy on accepting gifts
> from vendors/promoters; and any conflict-of-interest disclosure expectations (e.g., a staff
> member booking their own band, or working for a promoter they also do outside work for).

---

## 5. Venue Safety

This is a summary. The full version, with real depth on each topic, lives in
[Venue Safety](../staff/venue-safety.md), and the fast phone-readable version lives in
[Emergency Procedures](../staff/emergency.md). Everyone should be able to find both without
searching.

Topics covered in the full documents: exits and occupancy limits, fire, earthquake, medical
emergencies and 911, active threats, evacuation, crowd surge, suspicious packages, power loss,
water leaks, broken glass/spills/slip hazards, ladders, stage safety, electrical safety, hearing
protection, and injury reporting.

On occupancy: the app has a per-space capacity configuration concept (the ground floor and other
spaces each have a configured capacity used elsewhere in the system), so a specific number exists
somewhere in venue configuration — but this handbook will not print a number it can't verify at
authoring time.

> **VERIFY — Confirm current Mabuhay Gardens procedure:** the actual configured capacity for each
> space, pulled from the current venue configuration, not guessed.

Incident reporting ties directly to the app's incident-record capability: anything covered by
`manage_incidents` — incident, change_order, bar_note, damage, overage — should be logged there,
not just remembered or texted around.

**This handbook and its safety documents are not a substitute for a legally required Injury and
Illness Prevention Program (IIPP) or Workplace Violence Prevention Plan.** California requires
both.

> **TODO — Management decision required:** confirm whether Mabuhay Gardens has a current, written
> IIPP and Workplace Violence Prevention Plan on file, and if not, treat creating them as a
> standalone compliance priority independent of this handbook.

---

## 6. Guest and Event Policies

Guest service, de-escalation, and knowing when to hand a situation off are core to every
front-of-house role. This chapter states the policy; your role's SOP (door, security, bartender,
house manager) states the exact steps.

- **Guest service and de-escalation** — the expectation is to defuse before you escalate, and to
  loop in security/management before a situation becomes physical or a safety risk.
- **Refusing entry / removing guests** — venue staff may refuse entry or ask a guest to leave for
  cause (intoxication, violence, underage, etc.); see the Door and Security SOPs for the exact
  steps and documentation expected.
- **When to call security, management, or 911** — covered concretely in Emergency Procedures;
  the short version is: anything involving weapons, serious injury, or immediate danger is a 911
  call first, notify-internally second.
- **Lost property** — see the Door/House Manager SOPs.

  > **TODO — Management decision required:** where lost property is logged/stored and for how
  > long before disposal/donation.

- **Minors** — attendance at all-ages vs. 21+ events, and ID/wristbanding for minors where
  applicable, ties directly into Alcohol Service policy.

  > **TODO — Management decision required:** confirm current all-ages/minor-admission policy per
  > event type, and how it's marked in the system so door staff see it.

- **Accessibility** — accommodating guests with disabilities (entry, seating, restrooms).

  > **VERIFY — Confirm current Mabuhay Gardens procedure:** current accessibility accommodations
  > and who coordinates them for a given event.

- **Backstage access, artist credentials, and green room rules** — access should be
  credential-based and limited to who actually needs to be there; see the Stagehand and House
  Manager SOPs.

  > **TODO — Management decision required:** the actual credentialing mechanism (wristbands,
  > laminates, a guest-list-style system) and who issues it.

- **Patron complaints** — see Chapter 9, Reporting Problems, and the House Manager SOP.
- **Photography** — house policy on patron and press photography, separate from the staff social
  media policy in Chapter 4.

  > **TODO — Management decision required:** current photography/recording policy for patrons
  > and press, and how it's communicated (signage, door staff verbal notice, etc.).

- **Smoking/vaping/cannabis, outside food/drink, weapons, re-entry** — each of these needs a
  stated venue policy; California law prohibits smoking in most indoor workplaces, which bounds
  (but doesn't fully determine) the smoking/vaping answer.

  > **TODO — Management decision required:** confirm current policy and signage for
  > smoking/vaping/cannabis, outside food/drink, weapons, and re-entry, per venue and per event
  > type.

---

## 7. Alcohol Service

The full policy lives in [Alcohol Service](../staff/alcohol-service.md) — read it if you serve,
sell, or handle alcohol in any capacity, and read it before your first shift, not after an
incident.

At the policy level: nobody underage is served, ever; every guest whose age is in question gets
carded against acceptable ID; visibly intoxicated guests are cut off, not served further; and
anyone who serves or sells alcohol on premises is expected to hold current California RBS
(Responsible Beverage Service) certification, which has been a state requirement for on-sale
licensed premises since 2022. That requirement is real regulatory context; it is not this
handbook inventing a rule.

> **TODO — Management decision required:** confirm who currently tracks RBS certification status
> and renewal per employee, since the software does not currently appear to track certification
> expiry as a distinct field — VERIFY this before assuming it's already handled.

Comps, drink tickets, and free drinks for performers are addressed at the policy level in Chapter
4 (who may authorize them) and at the procedural level in the Bartender SOP and Artist Settlement
SOP (how they get recorded so the ledger nets out correctly).

---

## 8. Cash and Financial Controls

The venue's financial controls today live primarily in the event **ledger**: each event tracks
revenue, costs, and payments in `event_ledger_entries`, and every cost/payee (artist, promoter,
vendor, staff, client, other) can be marked paid/unpaid/partial. The system nets what's still
owed per payee automatically, and **finalizing closeout is blocked (HTTP 422) if any payee still
shows a positive owed balance, or if a 7-item checklist isn't complete** — it can only be
overridden with an explicit `force`, which should not be routine. A "Door sales & settlement doc"
section on the event captures ticket count, gross ticket sales, and a link to an external
settlement document.

That is real, working financial control at the *event settlement* level, and it's covered in
depth in the [Artist Settlement SOP](../staff/sop/artist-settlement.md).

**What the software does not yet do:** there is no till count, cash drawer, or safe-drop feature
in the app today. Cash handling at the point of sale (bar, door, box office) is not currently
encoded in software beyond the ledger's payee/payment tracking described above.

> **TODO — Management decision required:** document the actual till-count, cash-drop, and safe
> procedures used at Mabuhay Gardens today (who counts, when, witnessed by whom, how discrepancies
> are escalated). Until that exists, the [Cash Handling SOP](../staff/sop/cash-handling.md) is
> deliberately incomplete rather than invented.

---

## 9. Reporting Problems

Every one of the following has a place it should go. Where this handbook can't name a specific
person or channel yet, it says so — see Contacts (TODO below) rather than a name that might be
wrong tomorrow.

| Problem | Where it goes |
|---|---|
| Harassment, discrimination, retaliation | Per Chapter 4 — see Contacts (TODO) for the primary and alternate reporting path |
| Safety hazard | On-duty manager/House Manager immediately; log via incident record if applicable |
| Theft | On-duty manager immediately |
| Cash discrepancy | Per the Cash Handling SOP (once written) and the ledger's payee-balance tracking |
| Injury | Immediate first aid/911 if needed, then an incident record — see Venue Safety |
| Property damage | Incident record (`damage` type) |
| Intoxicated patron | Per Alcohol Service policy and the on-shift bartender/security chain |
| Violent behavior | 911 if immediate danger, then security/management, then incident record |
| Security incident | Security/management immediately, then incident record |
| Equipment failure | Sound/production lead or on-duty manager |
| Customer complaint | House Manager/on-duty manager |

> **TODO — Management decision required:** build a real "Contacts" reference (named roles, not
> necessarily named individuals, e.g. "on-duty manager," "venue admin on call") that this table
> and the rest of this handbook can point to, including an **alternate reporting path for
> complaints about the reader's own manager** — this cannot be a dead end.

---

## 10. Handbook Acknowledgment

This handbook describes current policy at Mabuhay Gardens as of the version noted in this
document's metadata. Policies here — and especially the many items marked TODO or VERIFY above —
will change as management finalizes them; when they do, this document will be revised and
reissued, and acknowledgment is tracked per version, not once for all time.

By acknowledging this handbook, you are confirming:

> "I acknowledge that I have received and reviewed this document and understand that I am
> responsible for following the policies and procedures applicable to my role."

You are also confirming that you know where to find the current version (linked from the Staff
Handbook index) and understand that a future revision will require a new acknowledgment.

> **VERIFY — Legal/regulatory review required:** the exact acknowledgment language above should
> be reviewed by counsel before this is used as a real employment record, particularly regarding
> whether it creates or disclaims any contractual relationship.
