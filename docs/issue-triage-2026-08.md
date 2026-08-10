# GitHub issue triage — August 2026

20 open issues (#17, #21–#36), all but one filed by `thisthatcat185` (venue
booking staff). This document triages them: what was actually verified against
production, whether the request makes sense as stated, and in what order to
address them.

**Ground rule:** these are treated as *reports and suggestions*, not a work
order. Several turn out to describe a real problem but propose a fix that
would not work, and two are already resolved. Nothing here has been
implemented yet, and no replies have been posted to the reporters — the
clarifying questions in each entry are drafts awaiting approval.

---

## The one finding that shapes everything else

**Only 76 of 272 events (28%) have `load_in_time` set.**

```
SELECT COUNT(*) total, SUM(load_in_time IS NOT NULL) with_load_in,
       SUM(end_time IS NOT NULL) with_end
FROM events WHERE date >= '2026-01-01';
→ total=272  with_load_in=76  with_end=133
```

Three issues (#22, #29, #33) ask for the calendar to block a room from
load-in through load-out. Implemented literally, that would change nothing
for 72% of events and would silently *shrink* the blocked window wherever
`load_in_time` is null — the opposite of what's being asked for. Any work in
this cluster has to solve the missing-data half first. This is the single
biggest reason not to take the requests at face value.

---

## Category A — Verified bugs

Reproduced against production. These are real regardless of what we decide
about the feature requests.

### A1. #26 — a Hold was placed on top of a confirmed show *(highest value)*

Fully reproduced:

| Event | Date | Room | Status | load_in | doors | show | end |
|---|---|---|---|---|---|---|---|
| #649878 Submarine Show | 2026-09-25 | 3 (Upstairs) | **confirmed** | 17:00 | 19:00 | 20:00 | 21:30 |
| #671392 Movie "Love your neighbor" | 2026-09-25 | 3 (Upstairs) | proposed (Hold) | **22:00** | 18:30 | 19:00 | 09:00 |

Two distinct defects in one row:

1. **The occupancy guard was gated on the wrong side.** *(Corrected during
   implementation — the original triage said "no server-side occupancy
   guard", which was wrong.)* `EventRowHelpers::checkRoomConflict()` has
   existed all along and returns a 409. The bug is that all three call sites
   gated it on **the new event's own status** being confirmed-or-later, so a
   Hold (`proposed`) skipped the check entirely — while still *counting* as
   an occupant against anyone else. Hence the asymmetry that produced this
   row: a confirmed show cannot be booked over a Hold, but a Hold could be
   dropped on a confirmed show. Worse, `Events::fromTemplate()` — the
   calendar's quick-create path, which hardcodes `status='proposed'` — ran
   no conflict check at all.
2. **No time-order validation.** Load-in at 22:00 with doors at 18:30 is
   incoherent — load-in lands four hours *after* the audience arrives.
   Nothing validates the ordering.

**Disposition: implement, with one scope correction.** The reporter asks to
block holds over events "labeled intake complete." Gate on pipeline rank
`>= confirmed` instead of equality — a hold dropped on top of a *booked* or
*published* show is at least as wrong, and equality would let those through.
This is the same `statuses`-rank pattern used for the calendar intake tint.

**Nuance that must not be lost:** `end 09:00` on that row is *legitimate* —
it is a past-midnight wrap, which `timesOverlap()` already handles by adding
1440 minutes when `end <= start`. Time-order validation must allow the wrap
and reject only genuinely impossible orderings, or someone will "fix" valid
overnight events into failures.

That nuance turned out to be load-bearing. Checking the ordering across all
252 live events found 9 violations, and a plain `doors <= show` rule would
have rejected 3 of them wrongly:

| Legitimate overnight | Real data-entry error |
|---|---|
| `652955` doors 19:00 → show 01:30 | `647103` doors 19:00 → show 18:00 |
| `656713` doors 20:00 → show 01:00 | `668814` doors 20:30 → show 20:00 |
| `671390` doors 19:00 → show 00:00 | `672174` doors 18:00 → show 17:00 |
| | `641201` doors 19:00 → show 09:00 |
| | `671392` load-in 22:00 → doors 18:30 |

Ordering alone cannot separate these columns; a **bounded forward gap** can.
Applying the same +1440 wrap and then requiring the gap to stay plausible
(doors→show ≤ 8h, load-in→doors ≤ 12h) accepts every row on the left and
rejects every row on the right.

Because 5 rows are already in violation, validation only runs when a request
actually sets a time field — otherwise those events would become permanently
unsaveable, including by the edit that repairs them.

Effort: **M** (server-side guard + validation + tests). **Implemented.**

### A2. #28 / #36 — Downstairs capacity is wrong

```
id=2  Downstairs (21+)   slug=downstairs  cap=350  zone=down  active=1
```

Production says 350; the reporter says the room caps at 250. Corroborating
evidence: the Blurry Stars event's own "Advance" ticket type was created
with `quantity_total=250`. The reporter is very likely correct.

**Disposition: fix after confirming the number** — a one-row `UPDATE`, but
it is a real-world life-safety/occupancy figure and should not be changed on
inference. Note the same wrong 350 is already copied onto the events
themselves (`events.capacity`), so decide whether to backfill existing rows
or only correct the room going forward.

Effort: **S** (plus a decision on backfill).

### A3. #28 — "0 tickets sold" is a display bug, not lost data

The alarming part of this report is false, which is good news:

```
ticket_orders for 641046:  22 orders  ($250.00)  — 21 fulfilled, 1 pending
ticket_order_items:        22 items, 25 tickets
ticket_types "Door":       quantity_sold = 24
```

The sales data is intact. `Reports.php`'s aggregate counts
`status IN ('paid','fulfilled') AND is_comp = 0`, which matches these rows —
so whichever screen the reporter was looking at is not using that query.

**Disposition: verified bug, needs one detail before it can be fixed.** We
need to know *which screen* showed zero (event workspace, settlement report,
dashboard tile, or the Reports page). Ask before hunting.

Effort: **S–M** once the screen is identified.

### A4. #23 — the Downstairs room "went missing again"

The room exists and is `active=1` right now, so there is nothing to re-add.
The word **"again"** is the actual bug report: #12 (closed) was the same
complaint. Something is intermittently hiding or deactivating this room, and
re-adding it a third time is not a fix.

**Disposition: root-cause, don't re-add.** Determine whether it's a
soft-delete toggle in the admin UI, a filter that drops rooms under some
condition, or a sync overwriting the row — then add a regression test.

Effort: **M** (investigation-led).

---

## Category B — Sensible as asked, small

Requests that make sense on their face and are cheap.

### B1. #35 — rename "Promoter / Artist" → contract signatory

The reporter's diagnosis is sharp: staff are entering *their own* name in
this field because the label doesn't say whose name belongs there. The field
wants "who signs the contract / who is performing," not a role name.

Currently labelled at `event-workspace.js:330`, which already switches to
"Client / Primary Contact" for private events — so the conditional-label
pattern exists.

**Disposition: implement.** Ask the reporter to pick the exact wording
(they offered "Contract Name/Point of Contact or something similar"). Label
change only — no schema rename; the `promoter_*` columns can keep their
names, and renaming them would be a large, risky diff for zero user-visible
gain.

Effort: **S**.

### B2. #34 — Clone event

Infrastructure already exists: `Events\Series::cloneOccurrence()` copies the
full field set (times, contacts, pricing, room, owner) into a new row.

**Disposition: implement, reusing that field list minus the series
linkage.** The reporter's use case — a Friday 6–10pm workshop plus Sat/Sun
1–4pm — is actually already servable by "Create recurring events" and then
editing each occurrence's times, since occurrences are real independent
rows. Worth mentioning to them as a today-workaround while the button is
built.

Effort: **S**.

### B3. #24 — hide service costs the venue absorbs

`security_paid_by` already exists with `venue|artist|promoter|client|shared`.
The ask is that when it's `venue`, the $/hr rate stops printing on the
contract — a promoter shouldn't see a line item they aren't paying.

`ContractRenderer.php` already omits null/zero deal terms, so conditional
suppression fits the existing rendering model.

**Disposition: implement.** Confirm scope with the reporter: they mention
"sound or security fee," but only *security* has a `paid_by` field today.
Sound is `sound_tech_included` (boolean) — if sound needs the same
treatment, it needs a `paid_by` field first.

Effort: **S** (security only) / **M** (if sound needs the same model).

### B4. #32 — remove the Owner dropdown

Gating already exists — `event-workspace.js:982,1017` inject a `disabled`
attribute into `ownerSelect()` under some condition.

**Disposition: implement as a capability tightening, not a removal.** Owner
is real data that admins still need to set; the request is that *bookers*
can't change it. Verify what currently drives `disabled` and align it to the
right capability.

Effort: **S**.

### B5. #30 — move the error box to center screen, make it larger

**Disposition: reasonable, needs identification first.** "Error box" is
ambiguous — the app has toasts (`pb-toast-stack`), inline form errors, and
modal dialogs. Ask which one, ideally with a screenshot. Cosmetic, low risk.

Effort: **S**.

---

## Category C — Real need, proposed fix needs rework

### C1. #22 + #29 + #33 — load-out time and load-in→load-out occupancy

These three are one feature. #29 is the reporter thinking out loud ("Add
load out? OR maybe your 2hr buffer is enough…"); #33 and #22 are the firm
version.

**What's true:** `events` has `load_in_time` but **no `load_out_time`**
(`event_vendors` has one, which is a different table). Conflict detection
runs on `doors_time → end_time` with a 30-minute buffer — not the 2-hour
buffer #29 assumes. So the reporter's fallback option is based on a
misunderstanding of current behavior and should not be relied on.

**What blocks it:** the 28% `load_in_time` coverage above.

**Disposition: implement, but sequenced behind a data decision.** Options to
put to the reporter:
1. Derive a fallback (load-in defaults to doors − 2h, load-out to end + 1h)
   so occupancy works for every event immediately; explicit values override.
2. Make load-in/load-out required at some pipeline status.
3. Both — fallback for display, required at Intake Complete.

Recommendation is (3): occupancy blocking is only trustworthy if it never
silently degrades, and Intake Complete is already the gate where other
fields become mandatory.

Effort: **L** (migration + validation + conflict-detection rewrite +
calendar rendering + backfill decision).

### C2. #25 — require booker/artist contact on a Hold, autofill the booker

**Verified root cause, and it's bigger than the report.** The quick-create
modal — the primary path for creating a Hold from the calendar — collects
*no* contact fields at all: template, date, end date, title, doors, show,
venue, type, room. That's it.

It also stamps **doors 19:00 / show 20:00 defaults on every hold**
regardless of reality. That is very likely the direct source of the "wonky
times" in #26: holds get plausible-looking times nobody actually entered.

**Disposition: implement — and note it partially fixes #26.** Autofilling
the booker from the logged-in user is straightforward. Whether contact info
should be *required* at Hold or only at Intake Complete is a policy call for
the reporter; requiring it at Hold adds friction to the fast-hold workflow
that the quick-create modal exists to serve.

Effort: **M**. Worth pairing with A1. **Implemented alongside A1.**

Resolved as: contacts appear on the quick-create modal but stay optional
there, and remain required to reach Intake Complete. That needed no gate
change — `validateStatusTransition()` already lists producer/artist + booker
contact in `$holdRequired`, and only fires when the status actually
*changes* (the `$body['status'] !== $old['status']` guard in `update()`), so
quick-create never tripped it while the Intake Complete transition always
does. The booker is prefilled from the signed-in user in the modal, and
`fromTemplate()` falls back to the authenticated user server-side.

The 19:00/20:00 defaults are gone rather than relaxed: `doors_time` and
`show_time` are now required by the modal, the templates board, and
`fromTemplate()`. Worth recording that `event_templates` has **no time
columns** — the OpenAPI description claiming times were "seeded from the
template" was wrong; they were always that hardcoded pair.

### C3. #31 — contract details box, required for Intake Complete

Consistent with #18 (closed), which made internal notes required at the same
gate — so the mechanism exists in
`Events::assertStatusRequirements()`.

**Disposition: needs clarification before building.** "Contract details" is
undefined — the contract module already has ~25 structured deal fields
(rental fee, deposit, splits, security, merch %). Is this asking for a free-
text box, or for a subset of the existing structured fields to be surfaced
and gated on the event? Building the wrong one is a wasted cycle.

Effort: **S** (free-text) / **M** (structured).

### C4. #21 — free-event registration + stable recurring link

Chris already answered publicly in the issue: *"Yes, absolutely to both."*
That commitment stands and this is not open for re-triage — only for
scheduling.

Two parts: (a) a $0 registration flow that captures attendee details for the
mailing list, and (b) a stable public URL across occurrences. Part (b) is
reportedly already true of recurring series and may only need verification
plus telling the reporter which link to hand Andres.

**Disposition: verify (b) immediately and reply; build (a).** Part (a)
touches the ticketing path and needs care around the payment provider being
skipped entirely for $0.

Effort: **S** to verify (b) / **M** for (a).

---

## Category D — Large, needs a design doc before code

### D1. #27 — credit-card fees and taxes in settlements

The most substantial request in the pile, and the one most likely to be
built wrong if rushed.

**Current state:** `ticket_orders` stores `amount_cents` and nothing else —
**no fee column, no tax column**. Square is the live provider (`provider=square`
on real orders); Stripe and Square adapters both exist. The ledger *has*
`processing_fees` and `taxes` categories, so today these are hand-entered
lines rather than anything derived from what the processor actually charged.

**Why this needs design first:** the processor's true fee is only knowable
from the provider's own records (Square payment/`processing_fee`, Stripe
balance transactions), it arrives *asynchronously* after settlement, it can
change on refund, and taxes are a separate question from fees with real
accounting consequences. Getting a number on screen is easy; getting a
number the reporter can put in a settlement is not.

**Disposition: agree with the need, schedule a design note before
implementation.** Deliverable is a short doc covering: fee capture at
webhook time vs. reconciliation sweep, schema (`fee_cents`, `tax_cents` on
`ticket_orders`), refund handling, backfill of historical orders, and
whether tax is computed or recorded.

Effort: **L**.

---

## Category E — Already handled

### E1. #17 — Holds expire after two weeks

**Built and shipped** in `df06b9c`, with automatic safe activation added in
the issue-closing follow-up, per the reporter's own "implement it in two
months" request (filed 2026-07-13 → due **~2026-09-13**).

**Activation:** scheduled for 2026-09-13 with
`HOLD_EXPIRY_ACTIVATES_ON`. The first active nightly run automatically gives
every open Hold a fresh 14-day baseline, persists the one-time activation in
`app_settings`, and exits; no human checklist or mass-cancellation risk
remains. `HOLD_EXPIRY_ENABLED=1` is still available for immediate activation.

---

## Questions for the reporters

Drafted, **not yet posted** — pending approval.

| # | Question |
|---|---|
| 36 | Confirm Downstairs capacity is 250 (prod says 350). Also: did you already rename it to "Ground Floor" somewhere, or are you asking us to? Should existing events keep their 350 capacity or be corrected? |
| 28 | Which screen showed "0 tickets sold"? The orders are intact (22 orders, 25 tickets, $250) so this is a display bug and we need to know where to look. |
| 35 | Exact label wording you want? |
| 31 | "Contract details" — free-text box, or specific fields from the existing contract deal terms? |
| 24 | Should *sound* get the same treatment as security? It has no "paid by" field today, so that's a larger change. |
| 30 | Which error box — a toast, an inline form error, or a popup? Screenshot ideal. |
| 22/33 | For events with no load-in time recorded (72% of them), should we assume a default (e.g. doors − 2h), or require load-in/load-out before Intake Complete? |
| 25 | Should contact info be *required* to save a Hold, or only to reach Intake Complete? Requiring it at Hold slows down fast date-grabbing. |

---

## Proposed sequence

Ordered by value-per-risk, with dependencies respected.

**Wave 1 — verified bugs and quick wins**
1. #26 hold-over-confirmed guard + time-order validation *(A1 — biggest
   real-world impact; double-bookings are the costliest failure here)*
2. #25 quick-create contacts + booker autofill *(shrinks #26's cause)*
3. #36 capacity correction *(after confirmation)*
4. #35 label, #32 owner gating, #34 clone button *(all small)*

**Wave 2 — investigation-led**

5. #28 tickets-sold display bug *(after reporter identifies the screen)*
6. #23 root-cause the disappearing room + regression test
7. #21 verify the stable recurring link, reply to the reporter

**Wave 3 — designed features**

8. #22/#29/#33 load-out schema + load-in→load-out occupancy *(the data
   decision must be settled first)*
9. #31 contract details gate *(after clarification)*
10. #24 conditional service-cost rendering
11. #21 free-event registration flow

**Wave 4 — large**

12. #27 CC fees & taxes — design note first, then implement

**Calendared**

13. **2026-09-13:** hold expiry (#17) self-activates and baselines automatically.

---

## Notes on method

Every "verified" claim above was checked against the production database or
the actual source, not inferred from the issue text. Three reports changed
materially under checking:

- **#28** claimed lost ticket sales; the data is intact and it's a display bug.
- **#23** asked to re-add a room that already exists; the real bug is that it
  keeps disappearing.
- **#22/#33** asked for load-in-based blocking that would be a no-op for most
  events as the data currently stands.

That's the argument for triaging before implementing.
