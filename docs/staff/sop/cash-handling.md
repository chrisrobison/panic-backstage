---
title: Cash Handling SOP
slug: sop-cash-handling
document_type: sop
version: 0.2
effective_date:
requires_acknowledgment: true
status: draft
---

# Cash Handling SOP

<!-- requires_acknowledgment: true — cash/financial control; default-safe. -->

## What this role owns

Handling cash accurately and honestly at every point it touches the venue — bar, door/box office,
and event settlement — and making sure what actually happened with money matches what's recorded.

## What the software actually does today

The venue's real financial control lives in the **event ledger** (`event_ledger_entries`):

- Each event tracks revenue, costs, and payments.
- Every cost/payee — artist, promoter, vendor, staff, client, other — can be marked
  **paid / unpaid / partial**.
- The system automatically **nets what's still owed per payee**.
- **Finalizing closeout is blocked (HTTP 422)** if any payee still shows a positive owed balance,
  or if a required 7-item checklist isn't complete — it can only be overridden with an explicit
  `force`, which should not be routine practice.
- A "Door sales & settlement doc" section captures ticket count, gross ticket sales, and a link
  to an external settlement document.

This is real, working control at the **event settlement** level — see the
[Artist Settlement SOP](artist-settlement.md) for the full closeout workflow.

## What happens outside the software

Backstage does not currently contain a till-count or safe-drop screen, so physical counts use the
venue's count sheet or other manager-approved record. That record must show the event, station,
date/time, starting bank, cash sales, drops, ending cash, expected cash, variance, and the names of
both people who counted.

## Arrival / Setup

1. The cashier and House Manager or a second staff member count the starting bank together before
   the drawer opens. Both sign the count record.
2. Assign the drawer to one cashier at a time where practical. Record any handoff with a count;
   do not pass an open drawer between shifts without one.
3. Keep personal cash, tips, and venue cash separate from the start.

## During Service / Event

1. Keep cash handling visible and countable — no personal cash mixed with till cash, no
   "I'll square it up later."
2. Record comps/discounts through the POS as comps, not as unrecorded free items (see the
   [Bartender SOP](bartender.md)).
3. The House Manager sets the drop threshold for the event. Count each drop with a second person,
   seal and label it with the station, amount, time, and both initials, then place it in the
   designated secure location. Do not state the safe location or access method in this SOP.
4. Limit drawer access to the assigned cashier and House Manager. Never leave an open drawer
   unattended.

## Before Leaving

1. Close the drawer away from guests. The cashier and House Manager or second staff member count
   it independently, compare the result to expected cash, and sign the count record.
2. Record the actual amount even when it does not match. Do not add personal money, remove an
   overage, reopen sales, or change transactions merely to make the drawer balance.
3. The House Manager secures the closing cash and count record and reports any variance to venue
   administration before leaving.
4. For event-level settlement (not per-shift till counts): confirm payee balances in the ledger
   reflect reality before anyone attempts to finalize closeout — see
   [Artist Settlement SOP](artist-settlement.md).

## When Something Goes Wrong

- Till doesn't balance: recount once with the House Manager, check recorded drops, refunds, comps,
  and drawer handoffs, then report the remaining variance. Venue administration reviews the
  count record and POS activity by the next business day.
- Suspected theft: notify the on-duty manager immediately; see Handbook Chapter 4 (Theft) and
  Chapter 9 (Reporting Problems).
- A payee shows an owed balance that doesn't match what was actually paid out: resolve in the
  ledger before attempting to finalize — see [Artist Settlement SOP](artist-settlement.md). Do
  not use `force` to bypass this as a routine workaround.

See also: [Artist Settlement SOP](artist-settlement.md), [Bartender SOP](bartender.md),
Handbook Chapter 8 (Cash and Financial Controls).
