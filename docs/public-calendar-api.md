# Public Calendar / Events API

Unauthenticated, read-only endpoints for pulling **publicly visible** event
data out of Panic Backstage — calendar subscriptions, RSS aggregators, and
the embeddable `<mab-events-carousel>` widget all read from here. No JWT, no
API key: the only gate is `events.public_visibility = 1` (plus
`status <> 'canceled'` on the feed endpoints), which is exactly the same gate
a venue admin flips on when they publish an event.

This document covers the **calendar/listing** surface (`src/Feed.php` +
`src/PublicEvents.php`). Ticket purchasing (`src/PublicTickets.php`,
`GET/POST /api/public/tickets/{eventId}`) is a separate concern — see
[`docs/ticketing.md`](ticketing.md). The full machine-readable contract for
every endpoint below (request/response schemas, error shapes) lives in
[`docs/openapi.yaml`](openapi.yaml).

---

## Concepts

| Thing | What it means here |
| --- | --- |
| **`public_visibility`** | Per-event boolean. `0` (default) = invisible to every endpoint on this page. `1` = eligible to appear, subject to the other filters below. |
| **Canceled events** | The feed endpoints (`/api/feed/*`) always exclude `status = 'canceled'`, even if `public_visibility = 1`. The single-event endpoint does not check `status` at all — a canceled-but-still-public event stays reachable by direct id/slug link. |
| **`ticketing_mode`** | `external` (default) — `ticket_url` is where to send buyers. `internal` — tickets are sold in-house; there is no `ticket_url`, buyers go through the event's own public page (see `docs/ticketing.md`). The JSON feed's `ticket.mode`/`ticket.checkout_url` encode this for you. |
| **id vs slug** | Events have a stable numeric `id` and a `slug` that's regenerated whenever title/date changes. New links always use `id`; `slug` lookups exist so old shared/printed/QR-coded links never break. |

---

## Routes

```
# Feeds — event listings (no JWT)
GET  /api/feed                 discovery index (lists the URLs below)
GET  /api/feed/events.ics      iCalendar (Google/Apple Calendar subscription)
GET  /api/feed/events.rss      RSS 2.0 (news aggregators, "what's on" widgets)
GET  /api/feed/events.json     structured JSON (embeddable widgets; CORS-open)

# Single event — full detail for one event's public page (no JWT)
GET  /public/events/{idOrSlug} event + venue + lineup + latest approved flyer
```

All four feed formats accept the same query params and apply the same
`public_visibility`/canceled gating — they're different *renderings* of one
underlying query (`Feed::fetchEvents()`), not different data sets.

### Query params (feed endpoints only)

| Param | Type | Default | Meaning |
| --- | --- | --- | --- |
| `venue` | string | — | Restrict to one venue by slug (e.g. `mabuhay-gardens`). |
| `days` | int | — | Only events within the next N days. Omit for "all upcoming". |
| `past` | `0`\|`1` | `0` | Include past events too. Default is upcoming-only (`date >= CURDATE()`). |
| `limit` | int | `500` | Cap the number of events returned. Max `1000`. |

```
curl 'https://panicbooking.com/backstage/api/feed/events.json?venue=mabuhay-gardens&limit=50'
```

---

## `GET /api/feed` — discovery index

Lists the other three feed URLs plus the count of events the current query
params match, so a consumer can hit this once to find (and self-document)
the concrete feed it wants:

```json
{
  "feeds": {
    "ics": "https://panicbooking.com/backstage/api/feed/events.ics",
    "rss": "https://panicbooking.com/backstage/api/feed/events.rss",
    "json": "https://panicbooking.com/backstage/api/feed/events.json"
  },
  "params": { "venue": "slug", "days": "int", "past": "0|1", "limit": "int" },
  "upcoming": 25
}
```

## `GET /api/feed/events.ics` — iCalendar

Standard RFC 5545, CRLF line endings, 75-octet folding. Subscribe URL works
directly in Google Calendar / Apple Calendar / Outlook ("Add calendar by
URL"). Start/end times are resolved to absolute UTC using the **venue's own
timezone** (falls back to `America/Los_Angeles`), so clients render correct
local times without needing a `VTIMEZONE` block. Events with no explicit
`end_time` get a synthetic 3-hour duration. `Cache-Control: public,
max-age=900`.

## `GET /api/feed/events.rss` — RSS 2.0

One `<item>` per event; `<description>` is an HTML `CDATA` block (date/time,
venue location, flyer image, public description, ticket link). Includes an
`<enclosure>` when an approved flyer asset exists. `Cache-Control: public,
max-age=900`.

## `GET /api/feed/events.json` — structured JSON

The one meant for **browser JS**, not calendar apps — it's what
`<mab-events-carousel>` (`public/assets/mab-events-carousel.js`) fetches.
Unlike the other two formats it sends `Access-Control-Allow-Origin: *`,
because it's expected to be called cross-origin from the venue's own
marketing site rather than server-side. `Cache-Control: public,
max-age=300` (shorter than ics/rss since it's meant for live-ish widgets).

```json
{
  "venue": { "name": "Mabuhay Gardens", "city": "San Francisco", "state": "CA" },
  "generated_at": "2026-07-11T16:47:08+00:00",
  "events": [
    {
      "id": 130,
      "slug": "i-am-a-snail-2026-07-11",
      "title": "I am a Snail",
      "subtitle": "DOWNSTAIRS // DOORS 7PM // 21+",
      "description": "A bold alt-comedy fever dream by clown NoraDell. …",
      "date": "2026-07-11",
      "month": "JUL", "day": "11", "weekday": "SAT",
      "doors_time": "7:00 PM", "show_time": null,
      "age_restriction": "21+",
      "tags": ["comedy"],
      "image": "https://themab.org/wp-content/uploads/2026/06/july-11-2026-i-am-a-snail.jpg",
      "schedule_pricing": null,
      "url": "https://panicbooking.com/backstage/event.html?id=130",
      "ticket": { "mode": "internal", "url": null, "checkout_url": "https://panicbooking.com/backstage/event.html?id=130" }
    }
  ]
}
```

Field notes:

- `month` / `day` / `weekday` are pre-formatted strings (`"JUL"`/`"11"`/`"SAT"`)
  rather than raw dates — they map 1:1 onto a `<div class="mab-date-block">`'s
  three `<span>`s, since that's the only consumer today.
- `tags` comes from `events.public_tags` (a comma-separated column, split
  here into an array). These are free-form, venue-chosen marketing labels
  (`"live-music"`, `"comedy"`, `"dance"`, …) — **not** the operational
  `event_type` enum. One event can carry multiple tags.
- `schedule_pricing`, when non-null, is `{"sections":[{"heading","lines":[…]}]}`
  parsed from `events.public_schedule_pricing` — rendered as a collapsible
  "Schedule & Pricing" panel. Only used by recurring series today (e.g. Cat's
  Corner). Stored/served as structured data rather than raw HTML on purpose,
  so a consumer escapes it instead of injecting admin-entered markup verbatim
  into a public page.
- `ticket.mode` is `external` (→ `ticket.url` is set, link out), `internal`
  (→ `ticket.checkout_url` is set — the public event page, embeddable in an
  iframe/modal), or `none` (no ticket link at all — the event just isn't
  ticketed, e.g. a free karaoke night).
- `image` is the latest **approved** flyer asset. It may point off-app (this
  app doesn't require flyers to be mirrored locally — a flyer already hosted
  on the venue's own site, e.g. themab.org's WordPress media library, is a
  valid `image` value as-is).

## `GET /public/events/{idOrSlug}` — single event detail

Backs an event's own public page (`event.html?id=…`). Returns the event row
**joined with venue name/address/city/state**, its non-canceled lineup
(ordered by `billing_order`/`set_time`), and its latest approved flyer asset.
`idOrSlug` is looked up by numeric `id` when it's all digits, otherwise by
`slug`.

```json
{
  "event": { "id": 130, "title": "I am a Snail", "venue_name": "Mabuhay Gardens", "...": "..." },
  "lineup": [ { "artist_name": "NoraDell", "billing_order": 1, "...": "..." } ],
  "flyer": { "file_path": "...", "asset_type": "flyer", "...": "..." }
}
```

> **Note on field exposure:** unlike the curated `events.json` feed above,
> this endpoint's `event` object is the **entire** `events` row (`SELECT
> e.*`), not a hand-picked public subset — it includes columns that are
> internal-only in intent (`description_internal`, `promoter_email`,
> `booker_phone`, `deposit_amount`, `potential_revenue`, `referral_source`,
> …). The rendered public event page only displays a curated subset, but
> anything scripting against this JSON directly gets the full row. If you're
> building a new public-facing consumer, prefer `/api/feed/events.json`
> (task-built to only ever emit public-safe fields) unless you specifically
> need the lineup/flyer join this endpoint provides.

---

## What's never exposed here

- Any event with `public_visibility = 0` (the default for every new event) —
  404 from every endpoint on this page, indistinguishable from a
  non-existent id.
- Canceled events, from the three feed endpoints (still reachable directly
  via `/public/events/{idOrSlug}` — see the Concepts table above).
- Anything requiring a role/capability check — these are genuinely
  unauthenticated surfaces (`security: []` in `docs/openapi.yaml`), so don't
  put anything here that a venue wouldn't want indexed by search engines or
  pulled by an arbitrary script.

---

## Reference implementation: `<mab-events-carousel>`

`public/assets/mab-events-carousel.js` is a dependency-free web component
that consumes `events.json` and renders themab.org's own "Upcoming events"
carousel markup (same class names, so the venue's existing theme CSS styles
it unchanged). See [`public/mab-events-demo.html`](../public/mab-events-demo.html)
for a live demo, and the *Public Event Feeds* section of the top-level
[`README.md`](../README.md) for the embed snippet.
