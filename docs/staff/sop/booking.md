---
title: Booking SOP
slug: sop-booking
document_type: sop
version: 0.2
effective_date:
requires_acknowledgment: true
status: draft
---

# Booking SOP

<!-- requires_acknowledgment: true — contract/deposit-adjacent responsibilities; default-safe. -->

## What this role owns

Turning an inbound inquiry into a fully advanced, contracted, production-ready event — from the
first email/call/form submission through handoff to event-night operations. "Booking/Event
Coordinator" is not yet a distinct role value in the staff roster (see the Handbook, Chapter 2),
so this SOP describes the workflow itself, regardless of exactly whose job title covers it today.

## Arrival / Setup — Working the Booking Inbox

1. Inbound inquiries — email to this venue's booking address (e.g. `bookings@yourvenue.com`), the
   website widget, phone, or manual entry — land in the shared **Booking Inbox**, never a personal
   inbox. Nobody privately redirects, permanently deletes, or hides an inquiry.
2. Inquiries move through **Assigned → Claimed → Owned**:
   - **Assigned**: the system or a manager has pointed it at you; you haven't started working it.
   - **Claimed**: you're actively working it right now. Claiming starts a claim-expiry countdown
     — if you abandon it, it returns to the queue automatically so it doesn't quietly die in
     someone's queue.
   - **Owned**: set automatically after the first real reply goes out, or manually by a manager.
3. An AI classifier suggests routing/category on new inquiries. It's a suggestion trail, not an
   autonomous action — a human always claims the inquiry and makes the actual call.
4. Claim an inquiry before replying to it. Don't reply to something you haven't claimed, and
   don't leave something claimed if you're not actually working it — that just blocks a teammate
   without the safety net of the auto-expiry countdown.

## During the Event Lifecycle

### Availability and holds

1. Check date availability before quoting anything.
2. Create a **Hold** for a date under consideration — this is not a confirmed booking, and should
   be communicated to the inquirer as such.

### Working the inquiry

1. Use **internal notes** (staff-only) to leave context for teammates — deal terms discussed,
   red flags, special requirements — separate from **replies** (customer-facing).
2. All replies go out looking like they're from the venue, never a personal account.
3. Before sending a reply, be aware of **duplicate-reply protection** — it blocks sending if the
   conversation changed underneath you (e.g., the guest replied again, or a teammate is already
   drafting), and shows you when someone else is mid-draft. Respect that signal — don't route
   around it by replying from elsewhere.

### Deal terms and contact info

1. Capture accurate contact info, event details (date, type, expected capacity, technical needs),
   and proposed deal terms as the conversation develops.
2. Deal terms get built out in the **deal builder** with the appropriate **clause library**
   entries once terms are agreed in principle.

### Approval

The event Owner may negotiate and prepare the deal, but a venue administrator approves the final
business terms before the contract is sent. Record approval in an internal note that identifies
the approver and date. Any later change to price, deposit, guarantee, split, bar terms, capacity,
curfew, insurance, cancellation, or venue obligations requires fresh approval and a new
audit-logged contract version.

### Contracts and e-signature

1. Once terms are approved, send the contract for e-signature.
2. The signing process is **audit-logged** — every view, sign, or decline is tracked. Use that
   audit trail if there's ever a dispute about whether/when something was seen or signed.
3. Move the event from **Hold** to **Intake Complete** once the required deal and logistics fields
   are complete. **Booked** requires an executed contract and a received, waived, or not-required
   deposit. Public events then move through **Needs Assets → Assets Approved → Ready to Announce
   → Published → Advanced → Completed → Settled**. Private events skip the promotion stages and
   move from **Booked → Completed → Settled**.

### Deposits

**Example default — replace with this venue's actual deposit policy:** a 25% deposit due within
14 days, unless the signed deal sets a different approved amount or date. Enter the deposit amount on the event before moving it
to Intake Complete. Record requests, receipts, refunds, and status through the event payment
workflow. A missing deposit keeps the booking from being treated as financially clear; waiving
it requires the `waive_deposit` capability and a written reason.

### Advancing to operations/production

1. Once **Booked**, make sure event info is complete enough for operations to run the night:
   technical requirements/rider info (for sound/stagehand), guest list/comp arrangements, ticketing
   setup if applicable, and any special terms relevant to door/security/bar.
2. Hand off to the House Manager/production lead for the event with anything they need that
   isn't obvious from the event record alone — use internal notes or direct communication as
   appropriate.

## Before "Leaving" (End of Booking-Stage Work)

1. Confirm the event record is complete and accurate before handing off to operations.
2. Confirm the Owner field reflects who's actually responsible going forward (reassigning Owner
   is a `venue_admin`-only capability if a change is needed).

## What to Do When Something Goes Wrong

- Two people both start replying to the same inquiry: duplicate-reply protection should catch
  this — trust it, don't override it without checking with the other person first.
- A promoter/artist disputes agreed terms: check the contract and audit log for what was actually
  sent/signed. The event Owner handles the conversation; any concession or contract change goes
  to venue administration for approval.
- An inquiry sits Claimed too long with no reply: it will auto-expire back to the queue — if you
  see this happening to your own inquiries, that's a signal to either move faster or hand it off
  intentionally.
- Contract needs to change after being sent: don't edit outside the audit-logged flow; issue a
  new version through the proper channel so the audit trail stays accurate.

See also: the Handbook Chapter 2 (roles and chain of command), `docs/training.md` for a hands-on
walkthrough of the Booking Inbox UI, [Artist Settlement SOP](artist-settlement.md) for what
happens after the event.
