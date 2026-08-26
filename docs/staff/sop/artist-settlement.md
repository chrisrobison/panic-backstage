---
title: Artist Settlement SOP
slug: sop-artist-settlement
document_type: sop
version: 0.1
effective_date:
requires_acknowledgment: true
status: draft
---

# Artist Settlement SOP

<!-- requires_acknowledgment: true — financial closeout, payee balances, and legal/contract exposure; default-safe. -->

## What this role owns

Reconciling an event's finances after the show and formally closing it out: confirming what came
in, what went out, who's been paid, and getting the event to a state where closeout can actually
be finalized. This SOP walks the real workflow the software supports today.

## The workflow

### 1. Gross vs. costs vs. payout

Every event has a financial ledger (`event_ledger_entries`) tracking:

- **Revenue** — including ticket sales.
- **Costs** — payments owed to payees: artist, promoter, vendor, staff, client, other.
- **Payments** — what's actually been paid against those costs.

### 2. Door sales & settlement doc

The event record includes a "Door sales & settlement doc" section: ticket count, gross ticket
sales, and a link to an external settlement document. Confirm these numbers against the actual
door reconciliation (see [Door SOP](door.md)) before proceeding — this is the fallback/summary
view of ticketing revenue, and it should agree with the ticketing system's own numbers.

> **VERIFY — Confirm current Mabuhay Gardens procedure:** the expected source of truth if the
> door's reconciled count and the ticketing system's count disagree, and who resolves the
> discrepancy.

### 3. Payee balances

For each payee on the event, mark payments as **paid / unpaid / partial** as they're actually
made. The system automatically nets what's still owed per payee — this is not a manual
calculation, but it's only correct if entries are kept current and accurate as money actually
moves.

### 4. The finalize gate

**Finalizing closeout is blocked (HTTP 422)** if:

- Any payee still shows a positive owed balance, **or**
- A required 7-item checklist isn't complete.

It can be overridden only with an explicit `force`. Treat `force` as an exception path, not a
routine step — if you're reaching for it regularly, that's a sign either the ledger data is
wrong or there's a real unresolved balance that needs to be dealt with, not bypassed.

> **TODO — Management decision required:** confirm who is authorized to use `force` to override
> the finalize gate, and what justification/documentation is expected when they do.

### 5. Finalize

Once all payee balances are zeroed out (or legitimately zero because nothing further is owed)
and the 7-item checklist is complete, finalize closeout. This moves the event to its final
settled state (**Settled** in the Hold → Intake Complete → Booked → Settled pipeline).

## Before Leaving (Same Night, if Closing Out Same-Night)

1. Confirm every payee on the event has an accurate, current paid/unpaid/partial status.
2. Confirm the door sales & settlement doc numbers are filled in and consistent with the actual
   door reconciliation.
3. If the checklist can't be completed the same night, don't force it — leave the event
   un-finalized and pick it up at the next opportunity.

## When Something Goes Wrong

- A payee balance won't zero out because a payment genuinely hasn't been made yet: don't force
  finalize — resolve the actual payment first, or escalate per Handbook Chapter 2 (chain of
  command for settlement authority is a management TODO).
- Door numbers and ticketing numbers disagree: reconcile before finalizing; don't average them
  or guess.
- The 7-item checklist has an item that doesn't apply to this specific event: escalate rather
  than force-completing it — VERIFY what the actual checklist items are and whether any have
  documented exceptions.

> **VERIFY — Confirm current Mabuhay Gardens procedure:** the exact 7 checklist items required
> for finalize, so this SOP can list them explicitly rather than referring to "the checklist"
> generically.

See also: [Cash Handling SOP](cash-handling.md), [Door SOP](door.md), Handbook Chapter 8.
