---
title: Cash Handling SOP
slug: sop-cash-handling
document_type: sop
version: 0.1
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

## What the software does NOT do today

**There is no till count, cash drawer, or safe-drop feature built into the app.** Point-of-sale
cash handling — counting a bar or door drawer in and out, dropping cash to a safe, reconciling a
till at shift change — is not currently encoded in software beyond the ledger's payee/payment
tracking described above.

> **TODO — Management decision required:** document the actual till-count, cash-drop, and safe
> procedure used at Mabuhay Gardens today: who counts, at what points in the shift, witnessed by
> whom, how the count is recorded, and how a discrepancy gets escalated. Until this exists, staff
> should follow whatever verbal/interim practice management has communicated, and this SOP will
> be revised the moment a real procedure is documented.

## Arrival / Setup

1. Confirm starting cash at any cash-handling station per current (interim, until documented)
   house practice.
2. Note who else is present/witnessing, if that's part of current practice.

## During Service / Event

1. Keep cash handling visible and countable — no personal cash mixed with till cash, no
   "I'll square it up later."
2. Record comps/discounts through the POS as comps, not as unrecorded free items (see the
   [Bartender SOP](bartender.md)).

## Before Leaving

1. Close out per current (interim) practice; report the count and any discrepancy immediately.
2. For event-level settlement (not per-shift till counts): confirm payee balances in the ledger
   reflect reality before anyone attempts to finalize closeout — see
   [Artist Settlement SOP](artist-settlement.md).

## When Something Goes Wrong

- Till doesn't balance: report immediately, don't adjust the recorded count to make it match
  without documenting what actually happened.
- Suspected theft: notify the on-duty manager immediately; see Handbook Chapter 4 (Theft) and
  Chapter 9 (Reporting Problems).
- A payee shows an owed balance that doesn't match what was actually paid out: resolve in the
  ledger before attempting to finalize — see [Artist Settlement SOP](artist-settlement.md). Do
  not use `force` to bypass this as a routine workaround.

See also: [Artist Settlement SOP](artist-settlement.md), [Bartender SOP](bartender.md),
Handbook Chapter 8 (Cash and Financial Controls).
