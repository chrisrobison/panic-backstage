---
title: House Manager SOP
slug: sop-house-manager
document_type: sop
version: 0.1
effective_date:
requires_acknowledgment: true
status: draft
---

# House Manager SOP

<!-- requires_acknowledgment: true — House Manager carries event-night authority and safety responsibility; default-safe. -->

## What this role owns

House Manager is a *function*, not a distinct role value in the staff roster today — whoever is
performing it on a given night typically holds the `manager` operational role (or `venue_admin`
app-level role). It's the closest thing to "who's actually running the room" that this document
can point to, and even that isn't fully formalized — see the Handbook, Chapter 2, Chain of
Command.

> **TODO — Management must define final event chain of command** — until it's written down,
> treat the designated House Manager for the event as the default point of authority on the
> floor, escalating from there.

## Arrival / Setup

1. Review the event record: status (Hold/Intake Complete/Booked/Settled — VERIFY exact field
   names in the app if presenting this to staff as literal UI labels), staffing assigned
   (`manage_staffing`), guest list, ticketing setup, any contract terms relevant to the night
   (rider requirements, capacity, comps).
2. Confirm opening checks are complete (see [Opening SOP](opening.md)) if not personally doing
   them.
3. Brief staff on anything specific to the night: known VIPs/guests, special comps, capacity
   concerns, any advance notice from the booking/production side.

## During the Event

1. Serve as the point of escalation for door, security, bar, and production staff on anything
   they can't resolve at their level — refusals of entry, guest disputes, comp authorization
   questions, safety concerns.
2. Monitor capacity against the configured limit for the space.

   > **VERIFY — Confirm current Mabuhay Gardens procedure:** exact configured capacity per
   > space and how it's being tracked in real time at the door.

3. Authorize or decline requests that fall under Chapter 4/8 of the Handbook (comps, backstage
   guests) per whatever authorization policy management ultimately sets — currently TODO.
4. Log incidents (`manage_incidents`) as they occur rather than batching them for later.

## Before Leaving

1. Oversee or confirm closing per the [Closing SOP](closing.md).
2. Confirm the night's ledger entries are accurate before anyone attempts to finalize closeout —
   see the [Artist Settlement SOP](artist-settlement.md).
3. Sign off on the building being secured.

## When Something Goes Wrong

- Any emergency: follow [Emergency Procedures](../emergency.md) — House Manager is a natural
  point of internal notification but does not replace calling 911 for anything urgent.
- Promoter/artist dispute: not currently assigned a defined resolution path in software — see
  Handbook Chapter 2 (TODO). Use judgment, document what happened, and escalate to ownership if
  unresolved.
- Guest incident requiring removal: coordinate with security/door per their SOPs; document via
  incident record.

See also: [Venue Safety](../venue-safety.md), [Guest and Event Policies (Handbook Ch. 6)](../handbook.md),
[Artist Settlement SOP](artist-settlement.md).
