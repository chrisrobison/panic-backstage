# Private Event & Venue Rental — Booking Guide

---

## What is a private event?

A private event is a venue rental where a client books your venue for their own occasion, such as a corporate party, private birthday, album release, film shoot, wedding reception, or similar event.

Unlike a public show, a private event is never listed publicly, never promoted, and never given a public ticket page. Everything stays internal.

---

## How a rental inquiry comes in

When you receive a rental inquiry, create a new event in Backstage and set the **Type** to **Private Event**.

Once the event type is set:

* The form changes to a rental-specific layout.
* Ticket fields and booker sections are removed.
* A venue admin is automatically assigned as the event owner.
* All venue admins receive an email with the client details and event information.
* The event starts at **Hold** status, which informally blocks the date on the calendar.

---

## The private event form

The **Details** tab for a private event is different from a regular show. Fill in the following information.

### Event basics

* Title
* Date
* Venue
* Load-in time
* Doors time
* End time
* Age restriction, if applicable
* Capacity / hard maximum
* Estimated guests

> **Note:** Capacity and estimated guests can be different. Capacity is the hard maximum; estimated guests are the expected headcount.

### Client / Primary Contact

Required for **Hold** status:

* Client name
* Client email
* Client phone number
* Organization — the company, family, or group making the booking

### Event requirements

* AV / tech requirements — sound, lighting, projection, microphones, or other technical needs
* Catering / bar notes — bar service, outside vendors, alcohol preferences, or restrictions

### Financials

* Paid deposit — record once received
* Internal notes — anything staff needs to know that the client should not see

> 💰 **Rental pricing:** Contact venue management for a quote.

---

## Status workflow

Private events move through a shorter pipeline than public shows. They skip all promotional stages.

| Status          | What it means                                                  | What you need to get here                                        |
| --------------- | -------------------------------------------------------------- | ---------------------------------------------------------------- |
| Hold            | Inquiry in progress; date informally held                      | Client name, email, phone number; date, doors time, and end time |
| Intake Complete | All client details confirmed; contract being built             | Load-in/out, estimated guests, age restriction, deposit amount, internal notes, and agreed terms recorded |
| Booked          | Contract executed and deposit resolved — the event is happening | A signed/fully executed (or manually attached) contract plus a received, waived, or not-required deposit |
| Archived        | Event happened; settlement is pending                          | Automatically set by the system the morning after the event date |
| Settled         | Books closed                                                   | Finalize the Closeout checklist after all payee balances reach zero |
| Cancelled       | Rental fell through                                            | Manual status change                                             |

Requirements are cumulative even if an administrator selects a later status
directly. In particular, **Booked** requires a signed/fully executed contract
(an approved or merely sent contract is not enough) and any required deposit
must be received or explicitly waived. **Intake Complete** enforces all intake
fields, including load-in/load-out, internal notes, and agreed contract terms.

---

## Notifications

| Trigger                           | Who gets an email                                              |
| --------------------------------- | -------------------------------------------------------------- |
| New private event created         | All venue admins receive the full inquiry details              |
| Status changes to Intake Complete | All venue admins                                               |
| Status changes to Booked          | All venue admins, and the client receives a confirmation email |

The client confirmation email is sent to the email address listed in the **Client / Primary Contact** field.

---

## Contracts

Use the **Contracts** tab on the event to build the rental agreement.

Select the **Private Event Rental** template. This template already includes:

* Rental fee
* Deposit terms
* Security requirements
* Bar minimum
* Force-majeure clause

Move the contract through the normal contract workflow:

**Draft → Needs Review → Approved → Sent → (e-sign flow) → Fully Executed**

The event cannot advance to **Booked** until the contract is signed/fully
executed, or a signed outside contract has been attached through the Contracts
tab (the legacy Contract URL also satisfies this gate). An approved or sent
contract alone does not qualify. Once sent for e-signature, the system tracks
each signer's progress automatically and generates a tamper-evident Final
Executed PDF when everyone has signed.

---

## How private events appear in the app

Private events are visually distinct from regular shows.

### Calendar

* Shows a 🔒 lock icon on the event chip
* Uses a subtle grey background

### Pipeline board

* Shows 🔒 before the event name
* Displays a left-side border
* The status dropdown only shows valid private-event statuses
* Promotional statuses such as **Needs Assets** and **Published** are hidden

### Event workspace

* The event title shows a 🔒 badge
* **Promote**, **Public Page**, and **Publish** buttons are hidden

---

## Automatic system behavior

The system handles several private-event tasks automatically.

### On creation

* A venue admin is assigned as the event owner.
* All venue admins are emailed with the inquiry details.

### When the event is booked

* The client automatically receives a “Your event is confirmed” email.

### Nightly

If a private event date has passed and the event is still in an active status, the system automatically moves it to **Archived**.

An email is also sent to venue admins as a reminder to complete Closeout.

---

## Quick checklist

* [ ] Create the event and set **Type** to **Private Event**
* [ ] Fill in client name, email, phone number, and organization
* [ ] Enter date, doors time, and end time
* [ ] Add AV / tech requirements
* [ ] Add catering / bar notes
* [ ] Advance to **Hold**
* [ ] Confirm deposit amount and estimated guest count
* [ ] Advance to **Intake Complete**
* [ ] Build the contract using the **Private Event Rental** template
* [ ] Get the contract fully executed (or attach the signed outside contract)
* [ ] Record the deposit as received, or waive/not-require it as appropriate
* [ ] Advance to **Booked**
* [ ] Confirm the client receives the automatic confirmation email
* [ ] Run the event
* [ ] Reconcile the Closeout ledger and payee balances
* [ ] Complete the seven Closeout checklist items and click **Finalize**
