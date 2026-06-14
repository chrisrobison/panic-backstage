# Private Event Workflow — Implementation Plan

**Added:** 2026-06-14
**Status:** ✅ Implemented

---

## Overview

Private events (venue rentals: corporate events, private parties, film shoots, etc.)
follow a fundamentally different workflow from public shows. They skip the promo/ticketing
pipeline entirely and go straight from contract to settlement.

---

## Status Flow

**Private events:**
```
Hold (proposed) → Intake Complete (confirmed) → Booked → Archived (completed) → Settled
                                                                     ↑
                                               Any stage → Canceled
```

**Statuses NOT available to private events:**
- `needs_assets` — no promo materials needed
- `ready_to_announce` — not announced publicly
- `published` — never publicly visible
- `advanced` — not applicable

---

## Auto-Assignment & Notifications

- **Auto-assigned owner:** Colleen (user ID 40333, this.that.comedy@gmail.com)
  Configurable via `PRIVATE_EVENT_HANDLER_USER_ID` in `.env`.
- **Inquiry notification:** All `venue_admin` users emailed immediately on creation.
- **Booked notification:** Client (promoter_email) + all venue_admins.
- **Pricing hint in form:** "For rental pricing, contact Tom Watson: tom@themab.org"

---

## Required Fields

### At Hold (proposed)
- Title, date, venue, event type
- Doors time, end time
- **Client** name, email, phone (stored in `promoter_*` columns)

### At Intake Complete (confirmed) — adds
- Age restriction
- Estimated guest count
- Deposit amount

### At Booked — adds
- Contract on file (Contracts tab or `contract_url`)

---

## New Database Columns (Migration 013)

| Column | Type | Purpose |
|---|---|---|
| `client_org` | VARCHAR(255) | Company/organization renting the space |
| `estimated_guests` | INT | Expected headcount (vs. `capacity` = hard max) |
| `av_requirements` | TEXT | AV, sound, lighting, tech notes from client |
| `catering_notes` | TEXT | Bar, catering, alcohol service notes |

---

## Form Adaptations (event_type = 'private_event')

**Hidden:** ticket price, ticket URL, ticket system, public visibility, public description, Booker section

**Renamed:** "Producer / Artist" → "Client / Primary Contact"

**Added:** Organization, Estimated Guests, AV / Tech Requirements, Catering / Bar Notes

**Filtered statuses:** Only `empty, proposed, confirmed, booked, completed, settled, canceled`

**Pricing hint:** Contact Tom Watson (tom@themab.org) for rental rates

---

## Calendar & Pipeline

- Calendar: Private event chips show a 🔒 lock icon
- Pipeline: Private event cards show a "Private" badge

---

## Email Templates

- `private-event-inquiry.html/txt` — sent to all venue_admins on creation
- Uses existing `status-changed` template for Booked notification to client

---

## Configuration

In `.env`:
```
PRIVATE_EVENT_HANDLER_USER_ID=40333
PRIVATE_EVENT_HANDLER_EMAIL=this.that.comedy@gmail.com
```
