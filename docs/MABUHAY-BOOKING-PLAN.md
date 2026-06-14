# Mabuhay Gardens / Panic Backstage — Booking System Implementation Plan

**Prepared for:** Christopher Robison / Mabuhay Gardens Staff
**Date:** 2026-06-14
**System:** Panic Backstage
**Go-Live Target:** July 1, 2026

---

## Implementation Status

| # | Item | Status | Notes |
|---|---|---|---|
| P0-1 | Fix "I AM SNAIL" stuck event | ✅ Done | `validateStatusTransition` now checks `contracts` table too |
| P0-2 | Filter canceled events from calendar | ✅ Done | `dayCellBody` filters `status === 'canceled'` |
| P0-3 | Rename "Prospect" → "Hold" | ✅ Done | `STATUS_LABELS.proposed` updated in `core.js` |
| P0-4 | Remove "In Negotiations" status | ✅ Done | Migration 012 migrates `hold` → `proposed`; removed from STATUSES |
| P0-5 | "Intake Complete" label confirmed everywhere | ✅ Done | Was already in `STATUS_LABELS.confirmed` |
| P0-6 | Both-party contract check at Booked | ✅ Done | Checks `contracts` table for signed/approved contract |
| P0-7 | Minimum required fields at Hold creation | ✅ Done | `validateStatusTransition` now enforces at `proposed` |
| P0-8 | Venue names confirmed | ✅ Done | Migration 005 already applied |
| P1-1 | Show event times on calendar | ✅ Done | Doors/Show time in mini-event chips |
| P1-2 | Add Load-In/Tech time field | ✅ Done | Migration 012 + form + calendar |
| P1-3 | Needs Assets auto-email to producer/booker | ✅ Done | `notifyStatusChange` extended |
| P1-4 | Hold expiration | ⏳ Blocked | **OQ-1: How many days before hold expires?** |
| P1-5 | Archived auto-email (settlement) | ✅ Done | `auto-complete-events.php` extended |
| P1-6 | Intake Complete required-field enforcement | ✅ Done | Full field list in `validateStatusTransition` |
| P1-7 | Color-code calendar by floor | ✅ Done | Already implemented via `roomTone()` |
| P1-8 | Rename "Producer" → "Producer/Artist" | ✅ Done | `event-workspace.js` updated |
| P2-1 | Staffing ratio helper | ⏳ Blocked | **OQ-6: What are the ratios?** |
| P2-2 | Hold auto-expiration cron | ⏳ Blocked | Needs OQ-1 answered |
| P2-3 | Settled automation | ⏳ Blocked | **OQ-4: Auto or manual?** |
| P2-4 | Ready to Announce / Advanced status fate | ⏳ Blocked | **OQ-2, OQ-3** |
| P2-5 | TIXR sunset / Panic Booking ticketing pilot | ⏳ Pending | Trial smaller shows first |

---

## Priority Tiers

### P0 — Critical / Blockers (fix before July 1 go-live)

| # | Item | Why critical |
|---|---|---|
| P0-1 | Fix **"I AM SNAIL"** — cannot move to Booked despite contract uploaded | Active event stuck; staff losing trust in the system |
| P0-2 | **Filter canceled events** from calendar view | "AI vs Human Roast Battle" still showing despite canceled |
| P0-3 | Rename **"Prospect" → "Hold"** in all UI labels | Staff communication uses new terminology from July 1 |
| P0-4 | **Delete "In Negotiations"** status entirely | Dead status causes confusion in dropdowns and pipeline |
| P0-5 | Confirm **"Intake Complete"** label everywhere | Contract drafting gate — Colleen can't proceed without it |
| P0-6 | Enforce **both-party contract** before `booked` | Currently only checks URL field, not contracts module |
| P0-7 | Enforce **minimum required fields** at Hold creation | New bookings arrive July 1 |
| P0-8 | Verify venue names are correct in DB | Migration 005 applied this |

### P1 — High Value (July 1–15)

| # | Item |
|---|---|
| P1-1 | Display event times on calendar (Doors / Show / Load-In) |
| P1-2 | Add Load-In/Tech time field |
| P1-3 | Needs Assets auto-email producer + booker on status change |
| P1-4 | Hold expiration notifications (blocked: OQ-1) |
| P1-5 | Archived auto-email to settlement team |
| P1-6 | Intake Complete required-field enforcement |
| P1-7 | Color-code calendar by floor |
| P1-8 | Rename "Producer" → "Producer/Artist" everywhere |

### P2 — Nice to Have (July 15+)

| # | Item |
|---|---|
| P2-1 | Staffing ratio helper (blocked: OQ-6) |
| P2-2 | Hold expiration auto-clean of calendar (blocked: OQ-1) |
| P2-3 | Settled automation (blocked: OQ-4) |
| P2-4 | Decide fate of `ready_to_announce` and `advanced` statuses (blocked: OQ-2, OQ-3) |
| P2-5 | TIXR sunset — migrate smaller shows to Panic Booking ticketing first |
| P2-6 | Venue cost awareness display in booking form ($2K/$3K per day note) |

---

## New Status Workflow

### Old → New Status Map

| Old Display Label | DB Value | New Display Label | Action |
|---|---|---|---|
| Prospect | `proposed` | **Hold** | Renamed — this is now the minimum entry stage |
| In Negotiations | `hold` | *(removed)* | Migration 012 moves these events to `proposed` |
| Intake Complete | `confirmed` | **Intake Complete** | Unchanged |
| Booked | `booked` | **Booked** | Gate tightened — requires contract on file |
| Needs Assets | `needs_assets` | **Needs Assets** | Auto-email producer/booker when set |
| Ready to Announce | `ready_to_announce` | TBD | **OQ-2: Keep or remove?** |
| Published | `published` | **Published** | Unchanged |
| Advanced | `advanced` | TBD | **OQ-3: Keep or remove?** |
| Archived | `completed` | **Archived** | Auto-set when event date passes |
| Settled | `settled` | **Settled** | OQ-4: explore automation |
| Cancelled | `canceled` | Cancelled | Hidden from calendar view |

### Status Flow

```
Hold (proposed) → Intake Complete (confirmed) → Booked → Needs Assets → Published → Archived (completed) → Settled
                                                                   ↑
                                                     (ready_to_announce?) — pending OQ-2

Any status → Canceled at any time
```

### Required Fields by Status

**At Hold (proposed) — enforced on creation and status set:**
- Event name (`title`)
- Date
- Event type
- Location/Venue
- Doors time
- End time
- Producer/Artist name, email, phone
- Booker name, email, phone

**At Intake Complete (confirmed) — all above plus:**
- Deposit amount
- Ticket price
- Capacity
- Age restriction

**At Booked — Intake Complete fields plus:**
- A contract in the `contracts` table with status `approved`, `sent`, or `signed`

---

## Automation & Notifications

| Trigger | Recipients | Email Subject |
|---|---|---|
| Hold expires (cron — **needs OQ-1**) | Producer + Booker | "Your Mabuhay Gardens hold has expired" |
| Status → Intake Complete (`confirmed`) | Colleen (venue_admin) | "[Backstage] Intake Complete: [title]" |
| Status → Booked | Colleen (venue_admin) | "[Backstage] Booked (contract signed): [title]" |
| Status → Needs Assets | Producer + Booker | "[Backstage] Promo assets needed for [title]" |
| Event date passes (auto-archive cron) | Settlement team (**OQ-5: who?**) | "[Backstage] Settlement needed: [title]" |
| Status → Published | Producer + Booker | (future) |
| Status → Settled | venue_admins | (future) |

---

## UI/UX Changes

### Calendar
- ✅ Event times shown in mini-chips (Doors / Show time)
- ✅ Canceled events hidden from calendar view
- ✅ Load In/Tech time field added alongside Doors and Show
- ✅ Color-coded by floor (already implemented via `roomTone()`)

### Forms
- ✅ "Producer / Promoter" renamed to "Producer / Artist"
- ✅ Load In/Tech time input added to event form
- 🔲 Venue cost note (Downstairs: ~$2,000/day · Upstairs: ~$3,000/day) — P2

---

## Database Migrations

### Migration 012 (applied)
- Migrates all `hold` status events → `proposed`
- Removes `hold` from the `status` ENUM
- Adds `load_in_time TIME NULL` column
- Adds `venue_contract_url VARCHAR(500) NULL` column (for dual-signature tracking)

---

## I AM SNAIL Bug — Root Cause & Fix

**Root cause:** The event had a contract created via the Contracts module (in the `contracts` table, status `approved`), but `validateStatusTransition` only checked the legacy `events.contract_url` URL field, which was NULL. The two systems were disconnected.

**Fix applied:** `validateStatusTransition` now checks EITHER:
1. `events.contract_url` is set, OR
2. A contract exists in the `contracts` table for this event with status `approved`, `sent`, or `signed`

---

## TIXR Sunset Plan

| Phase | Timeline | What |
|---|---|---|
| Parallel | Now–July 31 | Keep existing TIXR events; new events default to Panic Booking |
| Pilot | July 1–31 | Run 2–3 small shows through Panic Booking; test door scanning, comps, reports |
| Decision | Aug 1 | If pilot clean → full cutover; if gaps → document for Chris to prioritize |
| Full Cutover | Sept 1 | Remove TIXR option; export historical data for accounting |

---

## Open Questions (Awaiting Answers)

Send to Colleen/Tom — need answers before implementing remaining items.

| # | Question | Owner | Blocks |
|---|---|---|---|
| OQ-1 | **How many days before a Hold auto-expires?** (7/14/30/custom?) | Colleen | P1-4, P2-2 |
| OQ-2 | **Keep "Ready to Announce"?** If yes, what does it gate and who gets notified? | Colleen | P2-4 |
| OQ-3 | **Keep "Advanced"?** What does it mean operationally? | Colleen | P2-4 |
| OQ-4 | **Settled automation?** Auto-trigger 48hrs after event, or always manual? | Chris/Accountant | P2-3 |
| OQ-5 | **Settlement email recipients?** Which addresses/roles? | Colleen/Chris | P1-5 full implementation |
| OQ-6 | **Staffing ratios?** (e.g., 1 bartender per 50 attendees?) | Chris | P2-1 |
| OQ-7 | **Free events + contracts:** Enforce $0 contract before Booked? | Colleen | Validation logic |
| OQ-8 | **Venue contract dual-signature:** Use same `contract_url` field or separate `venue_contract_url` field? | Tom/Colleen | Full dual-sig enforcement |

---

## Implementation Sequence

### Phase 0 — Emergency Fixes (Week of June 14) ✅ COMPLETE
- Fix I AM SNAIL
- Filter canceled events from calendar
- Rename Prospect → Hold, remove In Negotiations
- Show event times on calendar

### Phase 1 — Core Workflow (June 21–July 1) ✅ COMPLETE
- Field enforcement at Hold and Intake Complete
- Load-In time field
- Rename Producer/Artist
- Expand notification emails

### Phase 2 — Automation (July 1–15) 🔄 IN PROGRESS
- Hold expiry cron (blocked: OQ-1)
- Needs Assets auto-email ✅
- Archive cron + settlement email ✅ (recipients TBD: OQ-5)

### Phase 3 — Polish (July 15+) ⏳ PENDING
- Staffing helper (OQ-6)
- Status cleanup (OQ-2, OQ-3)
- Settled automation (OQ-4)
- TIXR pilot and sunset
