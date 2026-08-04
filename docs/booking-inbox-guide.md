# The Booking Inbox — Workflow Guide

A plain-language walkthrough of why the Booking Inbox exists, how an inquiry moves through it, and the exact path from a first message to a booked, contracted show.

> **Looking for the technical/architecture reference instead?** See [`docs/booking-inbox.html`](booking-inbox.html) (source: `docs/booking-inbox.md`) — data model, API surface, migrations, and test suite. This guide is for the people actually working the Inbox day to day, not for extending the code.

---

## Table of Contents

1. [Why the Booking Inbox Exists](#why-the-booking-inbox-exists)
2. [How an Inquiry Arrives](#how-an-inquiry-arrives)
3. [Assigned, Claimed, Owned](#assigned-claimed-owned)
4. [Working an Inquiry](#working-an-inquiry)
5. [From Inquiry to Booked Show](#from-inquiry-to-booked-show)
6. [Who Can Do What](#who-can-do-what)
7. [Operator Cheat Sheet](#operator-cheat-sheet)
8. [Where to Go Next](#where-to-go-next)

---

## Why the Booking Inbox Exists

Before the Booking Inbox, a booking inquiry's life looked like this: it landed in a shared mailbox, someone eventually noticed it, replied from whatever account they happened to be in, and — if it turned into a real show — someone re-typed the details into a new event by hand. Nothing tracked who was actually working an inquiry versus who'd just glanced at it. Two people could reply to the same booker with two different answers. A promising inquiry could sit unanswered for a week because everyone assumed someone else had it. There was no record of *why* a booking was declined, and a manager had no way to see that a high-value inquiry had been quietly turned away.

The Booking Inbox is the fix: a shared, auditable workspace where staff and outside bookers triage, claim, respond to, and hand off inbound inquiries — without anyone privately controlling, hiding, or deleting them.

**It is not a second system.** Backstage already has a [Leads pipeline](ops-manual.html#lead-inbox) for evaluating deals. The Inbox is a second lens over those same rows, purpose-built for the day-to-day work:

| | What it's for | What it's not |
|---|---|---|
| **Leads** | Evaluating a deal — pipeline view, filtering, reporting | The place you reply to a booker |
| **Booking Inbox** | Working an inquiry — claim it, read the thread, reply, hand it off | A separate database of inquiries |

Every inquiry you see in the Inbox is a lead row underneath. Converting it or onboarding it from either view does the same thing.

The design leans on a few deliberate ideas, each solving a specific failure mode from the old workflow:

- **Claim, not just assign** — assigning an inquiry to a person doesn't mean anyone's actually working it *right now*. A claim is a real-time "I've got this," and only one person can hold it, so two people can't step on each other mid-conversation.
- **AI classification, human decisions** — every inquiry gets read by a classifier that fills in event type, genre, dates, attendance, and a spam score automatically. It never assigns, declines, or deletes anything on its own — it only saves a human (or a deterministic routing rule) the work of typing that in.
- **Deterministic routing** — once the AI has read an inquiry, a plain rule set (not a model) decides who it goes to, and every decision is logged in plain English, including "nothing matched."
- **Business-hours SLA timers** — an inquiry that nobody claims, or a claim nobody acts on, ages out automatically and goes back to the shared queue — a stuck inquiry can't hide in one person's queue forever.
- **A hard gate on high-value declines** — turning away a big booking isn't a one-click action for everyone; above a threshold, it becomes an approval request instead.
- **Everything logged** — every claim, reply, status change, and reassignment is written to an audit trail, so "what happened to this inquiry" is always answerable.

---

## How an Inquiry Arrives

Inquiries reach the Inbox two ways: through the venue's configured intake mailbox, or through the public inquiry form on the venue's website (the same drop-in widget used by the Leads pipeline). Either way, the same thing happens next:

1. **An instant, neutral acknowledgment** goes out automatically, so the person who wrote in knows it was received — before any human has even looked at it.
2. **An AI classifier reads the message** and fills in ~24 fields: event type, genre, category, requested dates, expected attendance, budget, production/stage/sound/lighting requirements, urgency, and a spam probability score, each with a confidence level. Nobody has to type this in by hand, and nothing here is a guess presented as fact — it's all visible, and a human can always correct it.
3. **A routing rule decides where it goes.** Rules match on things like genre, category, attendance, or budget (e.g. "punk/ska → Kathy," "comedy → Colleen") and the first one that matches wins. If nothing matches confidently enough, the inquiry drops into the shared **unassigned** queue instead of being silently routed to the wrong person. Open the Details tab on any inquiry to see the plain-English reason — "Routed to Kathy because: genre contains punk/ska (94% confidence)."
4. **Two clocks start**, both aware of the venue's business hours (they pause overnight and on closed days rather than counting real wall-clock time): a claim-due deadline on the assignment, and — once someone claims it — a claim-expiry countdown. Both are covered under [Assigned, Claimed, Owned](#assigned-claimed-owned).

### Mail that isn't confident enough to be a real inquiry

Some mail the intake filter isn't sure about — an automated bounce, a vendor notice, or something that just doesn't read like a booking request — lands in a separate **Quarantined Mail** queue instead of silently becoming a lead or silently disappearing. For each item, a venue admin decides:

- **Promote** it — it's a genuine, if messy, booking request the filter misjudged. This turns it into a real inquiry in the normal queue.
- **Leave it** — it really is spam, a bounce, or unrelated correspondence. Promoting these just adds noise every booker then has to re-triage.

---

## Assigned, Claimed, Owned

These are three different things in the Inbox, on purpose — conflating them is exactly how inquiries used to get lost or double-worked.

- **Assigned** — the routing rules (or a manager) pointed this inquiry at a person or team. Nobody has actually started working it yet.
- **Claimed** — a specific person clicked **Claim Inquiry** and is actively on it right now. Only one active claim per inquiry — the system checks before letting a second person claim it — so two people can't send conflicting replies to the same booker.
- **Owned** — set automatically once the inquiry is onboarded into a real event. Ownership survives later claim changes; it marks "this is the deal this person is responsible for," separate from who's actively typing a reply today.

A claim carries a countdown, and it exists to answer one question honestly: is someone *actually* working this, or did they just click a button to reserve it? A short, fixed list of **claim-preserving actions** — sending a reply, logging a call, sending availability or a proposal, scheduling a tour — extends the countdown, because each of those is genuine forward progress. Sitting on a claim without doing one of those lets it expire, and the inquiry goes back to the shared queue automatically.

> **Why you can't just hold a claim forever:** a silently-held, indefinite claim is exactly how an inquiry gets lost — the booker thinks someone's on it, and nobody actually is. Don't claim something you don't intend to act on in the next few minutes just to "reserve" it; letting it expire back to the queue is the system working correctly, not a bug.

---

## Working an Inquiry

Opening an inquiry gives you one workspace with everything about it:

- **One conversation thread** — inbound messages, your replies, internal notes only staff can see, and a system log of status changes and reassignments, all interleaved in order. If a teammate already has a draft reply open, you'll see a banner before you start typing your own.
- **A tiered action bar**, so the 1–2 things you'd actually do next are what you see, not a wall of buttons:
  - **Primary (filled button)** — the obvious next step: usually **Onboard Lead**, or **Send Proposal** once availability or a tour has already gone out.
  - **Secondary (outline buttons)** — one or two supporting actions, like sending availability or adding a task.
  - **More** — everything else: Assign, Reassign, Decline, Archive, and the other status changes (lost, spam, duplicate, on hold, awaiting customer). Same actions as always, just one click further away instead of crowding the bar.
- **Reply templates** — the composer can insert a canned body (availability follow-up, proposal follow-up, schedule-a-tour, request-missing-info) with placeholders like preferred date, event name, and contact name already filled in. Edit before sending like any other draft.
- **Watchers** — add a colleague to an inquiry so they see the conversation and status changes without taking the claim. Useful when a manager wants visibility on a tricky one, or a second person needs context before a handoff.

Anything that requires a reason — decline, archive, lost, spam, duplicate — prompts for one before it applies, no matter where you trigger it from. And if an inquiry is above the venue's high-value threshold, a restricted booker can't unilaterally decline, lose, or archive it — that action files an approval request for a manager instead, so a promising booking can't be quietly turned away while it stays untouched in its current status.

---

## From Inquiry to Booked Show

This is the full path, start to finish. Steps 1–3 happen in the Inbox; steps 4 onward happen in the event workspace the Inbox hands off into.

1. **The inquiry lands, gets classified, and gets routed** (see [How an Inquiry Arrives](#how-an-inquiry-arrives)) — either straight to you/your team, or into the shared unassigned queue.

2. **Claim it.** If it's sitting in your assigned queue or the unassigned queue and you're the right person to work it, click **Claim Inquiry** before you reply — claiming starts the response clock and stops anyone else from also answering the same booker.

3. **Have the conversation.** Reply, answer questions, send availability, propose terms, schedule a tour — whatever it takes to get the deal to a "yes." Each of these is a claim-preserving action, so the countdown keeps resetting as long as you're genuinely moving it forward.

4. **Onboard the Lead.** Once it's ready to become a real booking, click **Onboard Lead**. The wizard:
   - checks for a duplicate event (same contact/organization, overlapping date at the venue) and a date/room conflict;
   - lets you pick the room, a starting template, and the event owner;
   - drops in a starter task checklist;
   - creates the event at **Hold** status.

   This is a *handoff*, not a shortcut — nothing is marked booked yet, and the original inquiry isn't deleted. It stays visible from the new event's workspace for later reference (useful for comparing what was projected at evaluation time against what actually happened).

5. **Hold.** The event now blocks the date on the calendar. If any of the baseline fields weren't already carried over from the inquiry — event name, date, event type, venue, doors/end time, producer and booker contact info — fill them in now.

6. **Advance to Intake Complete.** This is the "everything we need to write the deal" checkpoint. It requires the deposit amount, advance ticket price, hard capacity, and age restriction on top of the Hold fields. The moment this status is set, venue admins are notified automatically to start drafting the contract.

7. **Build and send the contract.** In the event's Contracts tab, the deal builder assembles a contract from structured terms and a clause library — you fill in terms, not boilerplate. Send it for e-signature, or attach a contract that was already signed outside the system.

8. **Collect (or waive) the deposit.** Record the payment in the Payments tab once it's received, or mark it waived/not required if that's the deal.

9. **Advance to Booked.** This is the biggest gate in the pipeline, and the system enforces it rather than trusting a person to remember: **an event cannot become Booked without both a fully executed contract (signed — not merely sent or approved) *and* the deposit resolved (received, waived, or not required).** Try to advance without either one and the status change is blocked with a specific message about what's missing.

10. **What Booked means.** The date is now treated as a firm commitment. All venue admins are notified. The show moves out of the pending part of the pipeline into the active-show section, and the next job is collecting promotional assets (flyer, description, ticket link) to get it published and on sale.

From here the show continues through the normal production pipeline (assets, promotion, the night itself, settlement) — the Inbox's job ends at the handoff in step 4, and it did its job if that handoff happened cleanly, on time, with a full record of the conversation behind it.

---

## Who Can Do What

| You are... | You may claim... |
|---|---|
| Venue admin / Trusted booker (full Booking Inbox access) | Any visible inquiry that has no active claim. |
| Restricted external booker (brought in for a specific act of business) | An inquiry already assigned to you, that you own, or that you're watching — **or** an unassigned, unclaimed inquiry still sitting in the fresh triage queue. |
| Restricted external booker | Never someone else's already-assigned or already-claimed inquiry — the Claim button doesn't even appear on it, and the server rejects a direct request too. |

In practice, a restricted booker can self-serve fresh, not-yet-triaged inquiries out of the unassigned queue — useful when someone's brought in for a specific specialty — but can't reach into another person's active work.

`assign` and `reassign` are also permission-distinct: **reassign** lets any trusted booker hand off an inquiry that's currently theirs to someone else. **assign** can target *any* inquiry regardless of current owner or claimant, so it's restricted to venue admins.

---

## Operator Cheat Sheet

- **Don't claim something you don't intend to work in the next few minutes.** The countdown expiring and returning it to the queue is intended behavior, not a bug — use it, don't fight it.
- **If an inquiry already has an Owner or sits in someone's Assigned queue, don't claim it out from under them.** Use Assign/Reassign if it genuinely needs to move.
- **Quarantined mail:** promote genuine-if-messy inquiries the filter misjudged; leave spam, bounces, and unrelated mail alone rather than polluting the triage queue.
- **A stuck "keeps bouncing back to unassigned" inquiry is the SLA sweep working correctly** — the fix is claiming it and then taking a real, claim-preserving action before the deadline, not disabling the sweep.
- **High-value decline isn't a workaround-able block.** Don't route around it by marking the inquiry on-hold/archived instead — file the approval request and follow up with a manager.
- **Outbound replies and auto-acknowledgments share one venue-configured sender identity.** If that address ever needs to change, it must stay the mailbox the venue's intake actually reads, or customer replies stop threading back into the Inbox.

---

## Where to Go Next

- **In-app help:** Help → Booking Inbox (and the sub-topics under it) for the same material with screenshots, right where you're working.
- **Full operations manual:** [`docs/ops-manual.html`](ops-manual.html) — the Booking Inbox chapter covers this same ground as part of the complete booking pipeline (Parts I–II), alongside the rest of the venue's day-to-day operations.
- **Technical/architecture reference:** [`docs/booking-inbox.html`](booking-inbox.html) (source: `docs/booking-inbox.md`) — for anyone extending the system: data model, API surface, migrations, and test suite.
