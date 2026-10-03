---
title: Staff Handbook
slug: handbook
document_type: handbook
version: 0.3
effective_date:
requires_acknowledgment: true
status: draft
---

# Staff Handbook

## 1. Welcome to the Venue

> **Customize this section:** this handbook ships as a generic starting template. Replace this
> chapter with your own venue's history, culture, and what makes it distinctive — the kind of
> context a new hire can't get from an org chart. Use the draft/publish workflow in Staff Docs to
> edit it (see the [Staff Handbook index](../staff/README.md)); nothing below depends on this
> section's specific content.

If you're reading this, you're about to work in — or already help run — a venue with its own
history and character. Whatever that story is — a building that's hosted decades of shows, a
brand-new room finding its identity, or something in between — it's worth telling here, because
it shapes how the room should feel to a guest.

That character is an asset, not a costume. The expectation for everyone who works here — staff,
management, contractors — is to carry that spirit in how the room feels to a guest, while
running the actual operation like professionals: on time, accountable, safe, and legal. Whatever
makes this venue distinctive does not mean chaos is an operating procedure.

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
- **Neighbors** — the venue operates in a mixed-use, occupied neighborhood. Noise, sidewalk
  behavior, and load-in/load-out conduct reflect on the venue.

This handbook is the policy layer: what's expected and why. The exact steps for how to do your
job live in your role's Standard Operating Procedure (SOP) — see the [Staff Handbook index](../staff/README.md)
for the full list. Where this handbook says "per venue policy," a specific procedure document
will tell you the mechanics.

---

## 2. How The Venue Is Organized

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
- **Booking/Event Coordinator**, **Café**, and **Kitchen** are real jobs people may do at this
  venue, but the software has no matching operational-role value for them today. Assigning these
  responsibilities to specific people is a manual management decision, not something the app
  enforces.

These remain functional assignments rather than new roster roles. Use the **Position** field for
someone's working title and the event staffing record or internal event notes for the assignment:

- A staff member with the `manager` role may be assigned as **House Manager**.
- The event **Owner** or a venue administrator owns booking and advance work unless another
  person is named in the event record.
- **Event Coordinator** duties are split between the event Owner before load-in and the House
  Manager after load-in unless a coordinator is specifically assigned.
- Café and kitchen duties stay inactive until management opens those operations and assigns
  trained staff. They should not be buried under `other` and treated as ready by default.

**Owner:** Venue administration maintains roster roles, position titles, and document
assignments. The House Manager confirms the actual event-night assignments during the staff
briefing.

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

The chain of command is based on the work being done, not the highest software role in the room:

1. **Venue administration** sets house policy, approves contract or financial exceptions, and
   is the final internal escalation point.
2. **Event Owner** owns the booking, contract, and advance until the event-day handoff.
3. **House Manager** has floor authority from load-in through lockup. Door, bar, security, and
   production leads report operational issues to the House Manager.
4. **Department leads** direct work inside their area. The sound or production lead may stop
   equipment or a performance immediately for a technical hazard; security may stop entry or
   clear an area for an immediate safety issue. Both notify the House Manager as soon as they can.
5. **All staff** may call 911, begin an evacuation, or stop unsafe work when delay would put
   someone at risk. Nobody needs management permission to make an emergency call.

The House Manager may approve routine, event-budgeted comps and operating expenses. Anything
outside the event's approved terms goes to a venue administrator. Promoter or artist disputes
are handled from the signed contract and event record; the House Manager may settle a night-of
logistics issue but may not rewrite deal terms. A person with `manage_ledger` may prepare
settlement, while final closeout stays with a person holding `finalize_closeout`.

Every event must name its House Manager in the staffing record or internal event notes before
doors. If nobody is named, the event may not open to guests until venue administration makes the
assignment.

---

## 3. Employment Basics

This chapter intentionally contains very few numbers. Wage rates, specific leave accrual rates,
and other figures that change over time do not belong hardcoded in a handbook that's hard to
update — they belong in a separate, actively maintained **Current Rates & Compliance** reference
that management keeps current. Where this handbook needs a number, it should point there instead
of repeating a value that will eventually be wrong.

Venue administration maintains a private **Current Rates & Compliance** reference for wage
rates, paydays, leave rules, reimbursement rates, required training, and the current payroll
contact. It is reviewed at least once a year and whenever a legal requirement or payroll
practice changes. Staff receive the parts that apply to them during onboarding and may request a
current copy from a venue administrator.

> **Jurisdiction note:** this chapter's regulatory citations (meal/rest periods, paid sick leave,
> harassment-prevention training) assume California/San Francisco requirements — the most common
> starting jurisdiction for this template. If this venue operates elsewhere, swap in the
> corresponding local employment-law requirements before treating this chapter as accurate.

### Classification

Employee event-shift positions are treated as hourly/non-exempt unless the employee has received
a written classification stating otherwise. Employee versus contractor status and the person's
position are recorded in the staff roster; the signed offer or service agreement controls if the
roster summary is incomplete. Venue administration is responsible for approving and
communicating any classification change before the work changes.

### Scheduling

Shifts are assigned in the event's Staffing panel, including role, call time, expected end time,
and status. Managers should publish routine assignments at least seven days ahead when the event
calendar allows it; late bookings and replacements may require less notice. A shift is not
transferred because two employees agreed by text: the House Manager or a person with
`manage_staffing` must approve the change and update the event record.

### Attendance, lateness, and call-outs

If you will be late or absent, contact the House Manager and the person who scheduled you as soon
as you know. Four hours' notice is the target when circumstances allow. If the shift starts in
less than four hours, call or use another channel that gets an immediate response; do not rely on
an unanswered message. The manager records a replacement, late arrival, decline, or no-show in
Staffing. Repeated attendance problems are reviewed by venue administration based on the pattern
and circumstances, not an automatic three-strikes formula.

### Timekeeping

Clock-in and clock-out are recorded on the event staffing shift. Those timestamps feed actual
hours and the payroll export. Staff should check their time before leaving and report a missed or
incorrect entry to the House Manager promptly. Managers correct the record; staff should never
change a time to hide lateness, overtime, or an early call. No off-the-clock work: if you are
performing venue work, that time must be recorded and paid.

### Overtime

The House Manager should approve overtime before it is worked. Emergencies, guest safety, and
closing the building safely come first; if advance approval is not practical, record the full
time and explain it in the shift notes. Unauthorized overtime may be addressed as a scheduling
issue, but hours actually worked must still be reported.

### Meal and rest periods

Meal and rest periods are provided under the applicable California requirements summarized in
the Current Rates & Compliance reference. The House Manager plans coverage so a break does not
leave the bar, door, security post, or production position unattended. Staff must report a
missed, late, short, or interrupted break before the shift is closed so payroll can review it;
managers may not ask staff to clock out and continue working. The California Labor Commissioner
publishes current [meal-period](https://www.dir.ca.gov/dlse/FAQ_MealPeriods.html) and
[rest-period](https://www.dir.ca.gov/dlse/FAQ_RestPeriods.htm) guidance.

### Payroll

The current payday and payment method are provided in onboarding and the Current Rates &
Compliance reference. Venue administration runs the payroll export and is the first contact for
a missing payment, incorrect hours, or rate issue. Report errors promptly and include the event,
shift date, and disputed hours; do not edit a completed shift to make the totals fit.

### Paid sick leave

Eligible employees receive paid sick leave under the current California and San Francisco rules
and the venue's written leave policy. The current accrual method and available balance appear in
payroll records rather than this handbook. Sick leave requests go to venue administration; for a
same-day absence, also follow the call-out procedure so the shift can be covered. San Francisco's
[Paid Sick Leave Ordinance guidance](https://www.sf.gov/sites/default/files/2025-01/PSL%20FAQ%20%282023%29%20%281%29.pdf)
is the local reference.

### Expense reimbursement

Get approval from the House Manager or venue administration before spending personal money on
venue business unless delaying would create a safety problem. Submit the receipt, business
purpose, event, and approving person to venue administration within 30 days. Necessary business
expenses are reviewed under the current reimbursement policy; missing pre-approval does not by
itself erase a legally required reimbursement.

### Tips and tip pooling

Any tip pool in use must be written down for that service area before the shift, including the
eligible roles and the method used to divide it. Managers and owners do not participate in an
employee tip pool. If no written pool has been issued, staff keep tips left directly for them and
may not create an informal mandatory pool after the fact. Venue administration owns the written
tip policy and payroll reporting; the House Manager confirms the night's arrangement at briefing.
See the California Labor Commissioner's [tips and gratuities guidance](https://www.dir.ca.gov/dlse/FAQ_TipsAndGratuities.html).

### Personnel information updates

Send contact or address changes to venue administration, which maintains the staff roster. Tax,
banking, and other sensitive payroll changes use the payroll provider's secure process and must
not be placed in event notes, chat, or general staff-roster notes.

---

## 4. Standards of Conduct

The rules below apply to employees, contractors, managers, and anyone else working on behalf of
the venue. Managers are expected to enforce them consistently and document serious issues rather
than making side deals or handling them only in private messages.

### Respectful workplace; harassment, discrimination, and retaliation

Harassment, discrimination, and retaliation against anyone — coworker, guest, artist, vendor —
based on a protected characteristic have no place here, period. California requires
employers with five or more employees to provide sexual-harassment-prevention training every two
years (SB 1343); that's real regulatory context, not a house preference.

Venue administration assigns and tracks required harassment-prevention training in Backstage's
certification records. Concerns may be reported to the House Manager or any venue administrator.
If the concern involves that person, go directly to another venue administrator or ownership.
Managers who receive a report pass it to venue administration promptly and do not investigate it
in a group chat or promise secrecy they cannot keep. Retaliation for raising a concern or helping
with a review is itself a policy violation.

### Violence, threats, and fighting

Not tolerated, from anyone, toward anyone. See the Venue Safety and Emergency Procedures
documents for what to do if a violent or threatening situation happens on shift.

A guest who threatens or fights is separated from others and removed when that can be done
safely; call 911 for immediate danger. Staff involvement is reported to the House Manager and
venue administration and may result in removal from the shift while the incident is reviewed.
Any response depends on the facts and may include coaching, discipline, ending employment or a
contract, a venue ban, or police involvement. Safety comes before completing an internal review.

### Theft

Theft of venue property, guest property, or cash is a serious violation. See the Cash and
Financial Controls chapter and the Cash Handling SOP for how discrepancies are actually
surfaced through the ledger today, and what's not yet built.

Suspected theft is reported to the House Manager or venue administration and documented without
public accusations. Preserve receipts, video, drawer counts, and other records; do not search a
person or their belongings on your own. Venue administration handles the review and decides on
discipline, recovery, insurance, or a police report based on the evidence and severity.

### Drugs and alcohol while working

Do not work impaired. Staff may not drink alcohol, use cannabis, or use illegal drugs while
clocked in, on call, or still responsible for venue work. Prescription and over-the-counter
medication is allowed when it can be used safely; tell the House Manager if a side effect could
affect safety without disclosing more medical detail than necessary. After clocking out and
handing off all duties, an off-duty employee may remain as a guest if the event allows it, but is
subject to the same service, conduct, and cutoff rules as any other guest.

### Relationships with guests, artists, and promoters; sexual conduct

Do not use a staff role, access, comps, guest-list control, or backstage access to pressure or
pursue anyone. Flirting or asking for dates while working should stop the first time interest is
not clearly returned, and staff must step away when the interaction affects service or safety.
Sexual activity is not permitted in venue work areas, backstage rooms, restrooms, or other venue
space during an event. Staff must disclose a relationship or financial connection that could
affect booking, settlement, hiring, or supervision so another person can handle the decision.

### Social media, photography, and video

Staff may share public event information and venue-approved promotional material. Do not post
incident footage, guest information, contracts, settlement figures, internal messages, security
procedures, or backstage content without permission from the people shown and the House Manager
or event Owner. Do not imply that a personal account speaks for the venue. Media requests
and official statements go to venue administration.

### Confidentiality

Guest information, contract terms, financial details, and incident records are confidential.
Access to incidents and safety notes is intentionally restricted in the software
(`view_incidents`/`manage_incidents`) — if you don't have that capability, you don't have that
information, and that's by design, not an oversight.

Treat contracts, deal terms, settlement figures, payroll information, door and alarm procedures,
unpublished event details, guest lists, and incident records as confidential. Share them only
with people who need them for the work. This continues after a shift or employment ends. A legal
request, subpoena, or press inquiry goes to venue administration rather than being answered from
memory.

### Keys, door codes, alarm codes, and backstage access

This handbook will never contain an actual code, combination, or credential — that's a security
requirement, not a formatting choice. What belongs here is *who* is allowed to hold keys/codes
and how access is granted and revoked.

Venue administration approves key and alarm access and keeps a current access list. Keys and
codes are individual: do not lend them, share them in messages, or prop a secured door for
someone else. The approving administrator removes digital access and collects physical keys as
soon as access is no longer needed. During an event, the House Manager controls backstage access
through the credentialing process in Chapter 6.

### Bringing friends backstage; free drinks, comps, and vendor gifts; conflicts of interest

The House Manager may authorize a non-working backstage guest or a routine comp when it fits the
event's approved plan. Venue administration approves anything outside that plan, including an
open-ended tab or a comp with a meaningful financial impact. Every comp is rung through the POS
or ledger; staff may not comp their own drinks or admit friends by using their position.

Small, occasional hospitality may be accepted when it does not affect a business decision. Cash,
kickbacks, expensive gifts, or anything offered in exchange for access or favorable treatment
must be declined and reported. Disclose personal or financial ties to a band, promoter, vendor,
applicant, or payee before taking part in booking, hiring, purchasing, or settlement decisions.

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

**Example defaults — replace with this venue's actual permitted occupancy:** capacity of 450
upstairs and 350 downstairs. Door staff and the House Manager check the active venue policy and
event record before each event; the lower lawful or event-specific limit controls if a permit,
configuration, or event plan has changed.

Incident reporting ties directly to the app's incident-record capability: anything covered by
`manage_incidents` — incident, change_order, bar_note, damage, overage — should be logged there,
not just remembered or texted around.

**This handbook and its safety documents are not a substitute for a legally required Injury and
Illness Prevention Program (IIPP) or Workplace Violence Prevention Plan.** California requires
both.

Venue administration owns the Injury and Illness Prevention Program (IIPP) and Workplace
Violence Prevention Plan, keeps the current signed copies available to staff, and reviews them at
least annually and after a serious incident or material workplace change. The handbook may
summarize those plans but does not replace them. If either plan is missing or out of date, venue
administration must correct that before treating this safety chapter as complete.

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

  Give found property to the House Manager, who records the date, location, description, and
  claimant handoff in the event notes or lost-property log and stores it in the designated locked
  area. Government ID, payment cards, keys, medication, and electronics stay secured and are
  escalated promptly. Ordinary unclaimed items are held for 30 days, then donated or disposed of
  by management; staff may not take unclaimed property.

- **Minors** — attendance at all-ages vs. 21+ events, and ID/wristbanding for minors where
  applicable, ties directly into Alcohol Service policy.

  Example default (see Chapter 5): the upstairs room is **all ages**, the downstairs room is
  **21+**, each with its own configured capacity. The event record's age restriction controls
  when a specific event is stricter. Door staff verify the room, age rule, and capacity
  in the event record during setup; the House Manager resolves any conflict before doors open.
  Minors never receive an alcohol-service wristband and may not be served alcohol.

- **Accessibility** — accommodating guests with disabilities (entry, seating, restrooms).

  The event Owner coordinates advance requests and records them in the event notes without
  unnecessary medical detail. The House Manager handles event-day requests and works with door,
  seating, and security staff to provide a reasonable route, seating location, restroom access,
  or other available accommodation. Staff should ask what help is wanted rather than making
  assumptions or separating a guest from their companion or mobility device.

- **Backstage access, artist credentials, and green room rules** — access should be
  credential-based and limited to who actually needs to be there; see the Stagehand and House
  Manager SOPs.

  The event Owner supplies the approved artist, crew, and backstage guest list before load-in.
  The House Manager issues a visibly distinct backstage wristband or laminate and may approve a
  late addition. Door and stage staff check the credential rather than relying on recognition or
  "they're with the band." Credentials are event-specific and may not be reused.

- **Patron complaints** — see Chapter 9, Reporting Problems, and the House Manager SOP.
- **Photography** — house policy on patron and press photography, separate from the staff social
  media policy in Chapter 4.

  Personal, non-flash phone photography is allowed unless the event record, contract, or posted
  notice says otherwise. Flash, tripods, detachable-lens cameras, recording rigs, and press access
  require advance approval from the event Owner or venue administration. Door staff are briefed
  on exceptions and signage is posted when an artist or private client prohibits recording.

- **Smoking/vaping/cannabis, outside food/drink, weapons, re-entry** — each of these needs a
  stated venue policy; California law prohibits smoking in most indoor workplaces, which bounds
  (but doesn't fully determine) the smoking/vaping answer.

  Smoking and vaping are not allowed indoors. Cannabis may not be consumed on premises. Outside
  alcohol is never allowed; other outside food or drink requires House Manager approval, with
  reasonable exceptions for medical or accessibility needs. Weapons are prohibited except for
  on-duty law enforcement acting in an official capacity. The default is no re-entry after a
  ticket is scanned unless the event brief explicitly allows it; when allowed, door staff use the
  event's designated wristband or hand stamp and re-check age credentials on return.

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

Venue administration verifies RBS records in Backstage before assigning alcohol-service work.
The certification record stores issue and expiration dates, certificate number, supporting
document, verification date, and verifier. Staff are responsible for completing renewal in time;
venue administration is responsible for not scheduling someone whose required certification is
missing or expired.

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

The [Cash Handling SOP](../staff/sop/cash-handling.md) uses a two-person count for opening and
closing drawers, signed count records, manager-controlled drops, and immediate discrepancy
reporting. The House Manager owns the physical close; a person with `manage_ledger` records the
event financials; a person with `finalize_closeout` performs the final locked closeout. No one
should count and approve their own drawer alone when a second staff member is available.

---

## 9. Reporting Problems

Every one of the following has a place it should go. The event staffing record supplies the
night's names; the table uses stable role names so it does not go stale when staffing changes.

| Problem | Where it goes |
|---|---|
| Harassment, discrimination, retaliation | House Manager or venue administrator; use another venue administrator or ownership if the complaint involves that person |
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

The event staffing record is the contact list for that night. Operational and safety issues go to
the named House Manager; booking and contract issues go to the event Owner; payroll,
certification, policy, and access issues go to a venue administrator. A complaint about the
House Manager or a venue administrator goes to a different venue administrator or ownership.
Venue administration is responsible for keeping at least two reporting contacts available to
staff and publishing their current phone/email details in the private staff contact list.

---

## 10. Handbook Acknowledgment

This handbook describes current policy at this venue as of the version noted in this
document's metadata. Policies will change as the operation develops; material changes are
revised and reissued, and acknowledgment is tracked per version, not once for all time.

By acknowledging this handbook, you are confirming:

> "I acknowledge that I have received and reviewed this document and understand that I am
> responsible for following the policies and procedures applicable to my role."

You are also confirming that you know where to find the current version (linked from the Staff
Handbook index) and understand that a future revision will require a new acknowledgment.

> **VERIFY — Legal/regulatory review required:** the exact acknowledgment language above should
> be reviewed by counsel before this is used as a real employment record, particularly regarding
> whether it creates or disclaims any contractual relationship.
