# Ticketing & Payments

In-house ticketing for events: sell tickets directly ("internal" mode) through a
pluggable payment layer, issue one-time tokenized tickets, and redeem them at the
door with a mobile QR scanner. The guiding principle is **the venue owns the
inventory, the token, and the audit trail** — payment providers handle money,
nothing else.

- There is **no vendored SDK.** Both providers (**Stripe** and **Square**) talk
  to their HTTP APIs over raw cURL with zero Composer dependencies. The QR encoder
  is also from-scratch (no third-party library, no external CDN for ticket art).
- This document covers the data model, API, payment flow, and how to
  operate/extend the system. The README has the quick-start summary
  (see *Ticketing And Payments*).

> Ticketing does not yet have an in-app **Help** section (unlike Contracts).
> This file is the operator reference until one is added to `public/assets/help.js`.

---

## Concepts

| Thing | What it is |
| --- | --- |
| **Ticketing mode** | Per-event toggle (`events.ticketing_mode`): `external` (link out to a third-party system) or `internal` (sell here). Public purchase only appears in `internal` mode. |
| **Ticket type (tier)** | A sellable tier with its own price, inventory, sales window, and status (`ticket_types`). e.g. *Advance / Door / VIP*. |
| **Order** | A purchase batch or comp batch (`ticket_orders`) with line items (`ticket_order_items`). Carries provider payment refs and lifecycle status. |
| **Ticket** | One issued, redeemable unit (`tickets`). Holds a `sha256` token hash only — the plaintext secret is delivered once by email. |
| **Scan** | An audit row written on every door scan (`ticket_scans`), admitted or not. |
| **Scanner link** | Per-event door-staff credential (`event_scanner_links`) — a token (+ optional PIN) that authorizes redemption without a user account. |
| **Payment settings** | The active provider + currency (`payment_settings`); secret keys stay in `.env`. |

Ticket-type status: `draft → on_sale → paused → sold_out → closed`.
Order status: `pending → paid → fulfilled` (plus `canceled`, `refunded`, `expired`).
Ticket status: `issued → redeemed` (plus `void`).

---

## Data model

Migration `database/migrations/020_event_ticketing.sql` (mirrored in `schema.sql`
for fresh installs) creates the ticketing tables and adds
`events.ticketing_mode`:

- `ticket_types` — tiers: `price_cents`, `currency`, `quantity_total`,
  `quantity_sold`, `sales_start`, `sales_end`, `status`, `sort_order`.
- `ticket_orders` — purchase/comp batches: `buyer_*`, `provider`
  (`stripe|square|comp`), `provider_ref`, `provider_payment_ref`, `amount_cents`,
  `status`, `is_comp`, `hold_expires_at`, `paid_at`, `refunded_at`.
- `ticket_order_items` — line items: `ticket_type_id`, `quantity`,
  `unit_price_cents`.
- `tickets` — issued units: `code`, `token_hash` (sha256 of the secret, **never**
  the plaintext), `holder_*`, `status`, `redeemed_at`, `redeemed_by_user_id`,
  `redeemed_via_scanner_id`, `voided_at`.
- `ticket_scans` — door audit: `result`
  (`admitted | already_redeemed | void | not_found | wrong_event | expired_link`),
  `scanner_link_id`, `scanned_by_user_id`, `ip`, `user_agent`.
- `event_scanner_links` — door credentials: `label`, `token_hash`, `pin_hash`,
  `expires_at`, `revoked_at`, `last_used_at`.
- `payment_settings` — `active_provider`, `currency`, `settings_json`.

**Inventory accounting.** Live availability for a tier =
`quantity_total − quantity_sold − active holds`. Holds are `pending` orders whose
`hold_expires_at` is still in the future (15 minutes from checkout start).
Oversell is prevented at fulfillment by an atomic conditional `UPDATE` rather than
a read-then-write, so concurrent webhooks can't both win.

---

## Backend

PHP, no framework, PSR-4 autoload (`Panic\Foo` → `src/Foo.php`).

| File | Responsibility |
| --- | --- |
| `src/TicketingService.php` | Core: token generation (plaintext + sha256), live availability, idempotent `fulfillOrder()`, per-unit ticket issuance, comps, voids. |
| `src/Events/Ticketing.php` | `/api/events/{id}/ticketing` — dashboard, tier CRUD, mode/settings, comps, refunds (admin). |
| `src/PublicTickets.php` | `/api/public/tickets/{eventId}` — on-sale tiers + checkout (no JWT). |
| `src/TicketView.php` | `GET /t/{token}` — public ticket page (HTML, no auth). |
| `src/Scanner.php` | Scanner-link management (JWT) + `POST /api/scan/redeem` (scanner token). |
| `src/QrCode.php` | `GET /assets/qr.svg` — from-scratch QR encoder (byte mode, ECC level M). |
| `src/Webhooks.php` | `POST /api/webhooks/{stripe,square}` — signature-verified fulfillment. |
| `src/PaymentSettings.php` | `/api/payment-settings` — active provider + currency (admin). |
| `src/Payments/PaymentProvider.php` | Provider interface: `createCheckout`, `verifyWebhook`, `refund`. |
| `src/Payments/StripeProvider.php` | Stripe over raw cURL + HMAC webhook verify. |
| `src/Payments/SquareProvider.php` | Square over raw cURL + webhook normalization. |

### Capabilities (`src/BaseEndpoint.php`)

- Event-scoped: **`manage_ticketing`** — granted to `venue_admin` and
  `event_owner` only (promoters are excluded). Gates the per-event ticketing tab,
  comps, refunds, and scanner-link management.
- Global: **`manage_users`** gates `/api/payment-settings` (provider config).
- Public routes (`/api/public/tickets/*`, `/t/{token}`, `/assets/qr.svg`) need no
  auth. Webhook routes are authenticated by **signature**, not JWT.
- Redemption (`/api/scan/redeem`) is authenticated by a **scanner-link token**
  (+ PIN if set), not a JWT — door staff need no accounts.

### Routes

```
# Admin / event workspace (JWT + manage_ticketing)
GET    /api/events/{id}/ticketing                 dashboard: tiers, live sales, settings
POST   /api/events/{id}/ticketing                 create a tier
PATCH  /api/events/{id}/ticketing                 update ticketing_mode / payment settings
PATCH  /api/events/{id}/ticketing/types/{typeId}  update a tier
DELETE /api/events/{id}/ticketing/types/{typeId}  delete a tier
POST   /api/events/{id}/ticketing/comp            issue comp tickets (emails QR)
POST   /api/events/{id}/ticketing/refund          cancel-event refund + void fulfilled orders
GET    /api/events/{id}/scanner-links             list scanner links
POST   /api/events/{id}/scanner-links             create one (secret returned ONCE)
DELETE /api/events/{id}/scanner-links/{linkId}    revoke

# Public (no JWT)
GET    /api/public/tickets/{eventId}              on-sale tiers + live availability
POST   /api/public/tickets/{eventId}/checkout     create held order + hosted-checkout URL
GET    /t/{token}                                 holder ticket page (HTML)
GET    /assets/qr.svg?text=<token>&size=<240-1024> scannable QR (SVG, same-origin)

# Door scanner (scanner-link token, no JWT)
POST   /api/scan/redeem                           atomic redeem + ticket_scans audit row

# Payment webhooks (HMAC signature, no JWT)
POST   /api/webhooks/stripe
POST   /api/webhooks/square

# Provider config (JWT + manage_users)
GET    /api/payment-settings                      active provider + per-provider key presence
PATCH  /api/payment-settings                      switch provider / currency
```

---

## Payment & fulfillment flow

1. **Buyer** opens the public event page (`internal` mode) and the
   `<pb-ticket-purchase>` component lists on-sale tiers with live availability.
2. `POST /api/public/tickets/{eventId}/checkout` creates a **`pending` order**
   with a 15-minute `hold_expires_at`, then asks the active provider for a hosted
   checkout URL and redirects the buyer there.
3. The buyer pays on the **provider's** hosted page (no card data touches this app).
4. The provider calls back to `POST /api/webhooks/{provider}`. The handler:
   - verifies the **HMAC signature** (bad/absent signature → `400`, no action);
   - on `payment_succeeded`, matches the order by `(provider, provider_ref)`,
     records `provider_payment_ref`, and calls
     `TicketingService::fulfillOrder()`;
   - `fulfillOrder()` is **idempotent** and guards oversell with an atomic
     conditional `UPDATE`, issues one `tickets` row per unit with a fresh
     plaintext token (hash stored), and emails each holder their ticket link + QR;
   - on `payment_failed`, cancels the still-`pending` order to release the hold.
5. Webhook **retries never double-issue or double-email** — plaintext tokens are
   generated only on the first successful fulfillment.

**Comps** skip the provider: `POST /api/events/{id}/ticketing/comp` creates a
`comp` order, issues tickets immediately, and emails the QR.

**Refunds** (`/refund`) are the cancel-event path: refund via the provider and
`void` all fulfilled tickets/orders for the event.

---

## Tickets, tokens & QR

- Each fulfilled unit gets a **one-time plaintext token**; only its `sha256`
  hash is stored in `tickets.token_hash`. The token is Crockford base32 for
  QR/URL friendliness.
- The holder page is `GET /t/{token}` — it hashes the token, looks up the row,
  and renders a standalone HTML page (holder, event, status, scannable QR). No
  auth, no JSON.
- The QR is generated on the fly by `GET /assets/qr.svg?text=<token>` — a
  from-scratch encoder (model 2, byte mode, ECC level M), served **same-origin**
  so ticket/scanner tokens are never sent to a third-party CDN. Verified scannable
  with OpenCV/ZBar.

---

## Door scanner

`public/scanner.html` is a mobile camera scanner (`scanner.js`, using the
`html5-qrcode` library to decode):

1. Door staff open the scanner with a **scanner-link token** (and PIN if the link
   requires one) — created in the event's Ticketing tab,
   `POST /api/events/{id}/scanner-links` (the secret is shown **once**).
2. The camera decodes a ticket QR and posts the bare token to
   `POST /api/scan/redeem` with the scanner token.
3. Redemption is an **atomic single-row flip** `issued → redeemed`, and **always**
   writes a `ticket_scans` audit row — even on failure (`already_redeemed`,
   `void`, `not_found`, `wrong_event`, `expired_link`).
4. The UI shows the result (admit / already-used / void / not-found) in real time;
   a manual text field is the fallback when the camera can't read a code.

Scanner links can be labeled, PIN-protected, expired, and revoked — revoking a
link instantly stops redemption from that device without touching tickets.

---

## Frontend

Vanilla web components under `public/assets/` (no build step, native ESM;
`core.js` holds the shared kit, `app.js` is the shell/router):

- `pb-ticketing-admin` (`ticketing-admin.js`) — the event-workspace **Ticketing**
  tab: live sales dashboard, mode toggle, tier CRUD, sales windows, comps, refunds,
  and scanner-link management. QR previews come from the same-origin `/assets/qr.svg`.
- `pb-ticket-purchase` (`tickets-public.js`) — mounted on `public/event.html`;
  lists on-sale tiers and starts checkout.
- `scanner.js` — drives `public/scanner.html` (camera decode + manual entry).

---

## Configuration

Required `.env` keys (see `.env.example`):

```
APP_URL
STRIPE_SECRET_KEY
STRIPE_WEBHOOK_SECRET
SQUARE_ACCESS_TOKEN
SQUARE_LOCATION_ID
SQUARE_WEBHOOK_SIGNATURE_KEY
SQUARE_ENV
SQUARE_WEBHOOK_URL
```

Secret keys live **only** in `.env` — `GET /api/payment-settings` reports just
which keys are present (a boolean per provider), never the values. Pick the active
provider and currency in **Admin → Payments** (`#admin-payments`, gated by
`manage_users`). The ticketing schema ships in migration `020`.

---

## Operating checklist

1. **Admin → Payments:** choose the active provider + currency; confirm the
   provider's keys are present.
2. **Event → Ticketing tab:** set `ticketing_mode = internal`, create tiers
   (price, inventory, sales window), set each to `on_sale`.
3. **Register the webhook** with the provider, pointing at
   `/api/webhooks/{provider}` (Square uses `SQUARE_WEBHOOK_URL`).
4. **Publish** the event so the public page shows `<pb-ticket-purchase>`.
5. **Before doors:** create a scanner link (note the one-time secret + PIN) and
   open `public/scanner.html` on the door device.
6. **Comps/refunds** as needed from the Ticketing tab.

---

## Security notes

- Plaintext ticket tokens are delivered exactly once (email at fulfillment) and
  never stored — only their `sha256` hashes are.
- QR art is generated and served same-origin; tokens never reach a third-party CDN.
- Webhooks are authenticated by HMAC signature; fulfillment is idempotent.
- Card data never touches this app — payment happens on the provider's hosted page.
- Door redemption needs only a scoped, revocable scanner-link token, not a user
  account, and every scan is audited.

## Not yet built (future)

In-app **Help** section for ticketing, partial/line-item refunds (today's refund
is the cancel-event path), waitlists/queueing for sold-out tiers, and flowing
ticket revenue into `event_settlements`.
