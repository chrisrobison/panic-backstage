# Deposit Payment Links & QR Codes

A staff member working an event — from the Payments tab or straight out of
the Booking Inbox once a lead is onboarded — can generate a hosted checkout
link (and a scannable QR of that link) for an event's deposit, pre-filled to
the exact outstanding amount. The buyer scans or clicks, pays through
whichever payment provider the venue has configured, and the payment record
flips to `received` automatically when the provider's webhook arrives — no
manual "mark received" step.

This replaces an older, Stripe-only "send invoice link" flow that minted a
brand-new Stripe Payment Link on every click (so re-sending orphaned the
previous one) and had no webhook auto-confirmation. The new flow is built on
`Panic\Payments\PaymentProviders` — the same provider-agnostic checkout
abstraction the public ticket-purchase flow already uses — so it routes to
Stripe or Square, whichever `payment_settings.active_provider` currently
selects, and reuses a still-good link instead of minting a new one on repeat
clicks.

## Architecture

```
Staff clicks "Payment Link / QR" (Payments tab)
  or "Deposit Link / QR"        (Booking Inbox action bar, post-onboarding)
        │
        ▼
POST /api/events/{id}/payments/{pid}/send-link        (Payments tab — payment record already exists)
POST /api/events/{id}/payments/0/deposit-link          (Booking Inbox — finds/creates the deposit record first)
        │
        ▼
Events\Payments::mintOrReuseCheckoutLink()
        │
        ├─ cached checkout_url still unexpired? ──▶ return it as-is (idempotent re-click)
        │
        ▼
PaymentProviders::active($db, $env)  →  StripeProvider | SquareProvider
        │  (payment_settings.active_provider; same abstraction PublicTickets.php uses)
        ▼
provider->createCheckout($order, $items, $successUrl, $cancelUrl)
        │
        ▼
event_payments row updated: status='invoiced',
  checkout_provider, external_ref, checkout_url, checkout_expires_at
        │
        ▼
Client renders GET /assets/qr.svg?text=<checkout_url>&size=240 in a modal
  (same "QR Code — Public Page" panel shape used elsewhere; no new dependency)
        │
        ▼
Buyer pays on the provider's hosted page
        │
        ▼
POST /api/webhooks/{provider}  (signature-verified, unauthenticated)
        │
        ▼
Webhooks::matchEventPayment()  — match by (checkout_provider, external_ref)
        │  (tries ticket_orders first; falls back to event_payments)
        ▼
Webhooks::fulfillEventPayment()  — idempotent
        │  status → 'received', checkout_payment_ref captured, audit row written
        ▼
Events\Payments::syncDepositStatus($db, $eventId)  (static — no BaseEndpoint needed)
        │
        ▼
events.deposit_status re-derived: requested → partially_received → received
```

## Two entry points, one code path

Both endpoints funnel into the same private `mintOrReuseCheckoutLink()` —
there is exactly one place that talks to a payment provider for this flow.

- **`POST /api/events/{eventId}/payments/{paymentId}/send-link`** — Payments
  tab. Operates on a payment record that's already on screen (`pending` or
  `invoiced`). This is also what the "Link sent (Stripe)" / "Link sent
  (Square)" button re-opens on a payment that already has a cached link — it
  doesn't mint a second one.
- **`POST /api/events/{eventId}/payments/0/deposit-link`** — Booking Inbox
  action bar. The `paymentId` in the path is always literally `0`; this is a
  convenience wrapper for a caller with no payment record in view yet. It:
  1. Looks up the event's `deposit_amount`. 422s if there isn't one, or if
     `deposit_status = 'waived'`.
  2. Looks for an existing non-voided `payment_type = 'deposit'` row in
     status `pending`/`invoiced`.
  3. If none exists, creates one for the **outstanding balance**
     (`deposit_amount` minus any already-`received` deposit payments — not
     necessarily the full `deposit_amount`, so a partially-paid deposit gets
     a link for the remainder, not the original total). 422s if that
     outstanding balance is `<= 0`.
  4. Delegates to `mintOrReuseCheckoutLink()`, same as `send-link`.

The Booking Inbox only offers "Deposit Link / QR" once `lead.converted_event_id`
is set (i.e. the inquiry has been onboarded into a real event — see
`computeActionBar()` in `public/assets/inbox/inbox-shared.js`). Before that
there's no event and no `deposit_amount` to pre-fill a link with.

Both require the `manage_payments` event capability.

## Link reuse and expiry

`mintOrReuseCheckoutLink()` returns the cached `checkout_url` as-is if all of
these hold:

- `checkout_url` and `checkout_provider` are both set, and
- `checkout_expires_at` is either `NULL` or still in the future.

Otherwise it mints a fresh checkout session/link through the active provider
and overwrites the cache with a new 24-hour bookkeeping expiry
(`time() + 24 * 3600`). This is **our own reuse-decision expiry**, not
necessarily the provider's own session TTL (Stripe Checkout Sessions default
to ~24h; Square payment links don't expire on their own by default) — past
it, the next click simply mints a new one rather than presenting a possibly-
stale provider session.

A payment already `received` or `voided` throws (422) rather than minting a
link — there's nothing to pay.

## QR code

The QR is generated client-side by pointing an `<img>` at the existing
from-scratch SVG QR encoder, `GET /assets/qr.svg?text=<url>&size=240`
(`src/QrCode.php`) — the same endpoint tickets and the public-page QR modal
already use. No new dependency, no separate "QR" field in the API response:
the client always derives it from `payment_link`.

Both entry points render it in the same modal shape (`_openPaymentLinkModal()`
in `public/assets/event-workspace.js`; the Booking Inbox's inline equivalent
in `public/assets/inbox/inbox-workspace.js`): QR image, a read-only
click-to-select URL field, **Copy Link**, and **Open Link**.

## Webhook auto-confirmation

`src/Webhooks.php` receives `POST /api/webhooks/{provider}` (public,
signature-verified — see `docs/ticketing.md` for the shared webhook
plumbing). On a `payment_succeeded` event it now tries two matches in order:

1. `ticket_orders` by `(provider, provider_ref)` — the pre-existing ticket
   checkout flow.
2. If no order matches, `event_payments` by `(checkout_provider,
   external_ref)` — this feature. `matchEventPayment()` excludes voided rows;
   there's no legacy-data fallback needed here (unlike the Square ticket-order
   match) since this column pair only ever existed after both were written
   together by `mintOrReuseCheckoutLink()`.

`fulfillEventPayment()` is idempotent — a retried webhook delivery against an
already-`received` row is a no-op — and on success:

- Sets `status = 'received'`, `received_at = NOW()`, and
  `checkout_payment_ref` (the provider's payment/charge id, kept for any
  future refund flow — mirrors `ticket_orders.provider_payment_ref`).
- Reads the provider's exact reported processor fee and tax, persists those
  values on `event_payments`, and idempotently syncs protected
  `processing_fees` / `taxes` cost rows into the event Closeout ledger.
- Writes an `event_payment_audit` row (`action = 'checkout_paid'`) and an
  `event_activity_log` entry, same trail a manual "mark received" edit would
  leave.
- If `payment_type = 'deposit'`, calls the now-`static`
  `Events\Payments::syncDepositStatus($db, $eventId)` to re-derive
  `events.deposit_status`. It's static specifically so the webhook receiver
  — which has no `Request`/JWT auth context — can call it directly instead of
  needing a full `BaseEndpoint` instance.

A `payment_failed` webhook is a no-op for this flow (unlike ticket orders,
which release an inventory hold): there's no hold to release, and the
existing cached link/QR simply stays valid until its own bookkeeping expiry.

## Deposit status lifecycle

`events.deposit_status` (re-derived by `syncDepositStatus()`, never hand-set
except by the waive flow):

| Status                | Meaning                                                              |
|------------------------|-----------------------------------------------------------------------|
| `not_required`         | Schema default. Either no deposit is configured (`deposit_amount <= 0`), or one hasn't been recorded against yet. |
| `requested`            | `deposit_amount > 0`, nothing received yet.                          |
| `partially_received`   | Some `received` deposit payments exist, but less than `deposit_amount` in total. |
| `received`             | Received deposit payments total `>= deposit_amount`.                  |
| `waived`               | Set only by `POST /api/events/{id}/payments/0/waive-deposit` (`waive_deposit` capability, distinct from `manage_payments`; requires a reason). Sticky — `syncDepositStatus()` deliberately never overwrites it. |
| `refunded`             | Also sticky against `syncDepositStatus()`; set elsewhere in the refund flow. |

`not_required` is **not** treated as sticky the way `waived`/`refunded` are —
it's just the column's default, so `syncDepositStatus()` derives the real
state from `deposit_amount` and payment history every time rather than
trusting a stale default.

## Data model (migration 087)

`database/migrations/087_add_event_payment_checkout_fields.sql` extends
`event_payments`:

| Column                  | Purpose |
|--------------------------|---------|
| `external_ref` *(repurposed)* | Previously held only a Stripe Payment Link id. Now holds whichever checkout reference the active provider's webhook echoes back for matching — Stripe: checkout session id (`cs_...`); Square: order id. Same convention as `ticket_orders.provider_ref`. |
| `checkout_provider`      | `'stripe'` \| `'square'` — captured at link-creation time, so a later change to `payment_settings.active_provider` never breaks an in-flight link or its webhook match. |
| `checkout_payment_ref`   | Payment/charge id from the `payment_succeeded` webhook (Stripe PaymentIntent id; Square payment id) — for a future refund flow. Mirrors `ticket_orders.provider_payment_ref`. |
| `checkout_url`           | Cached hosted checkout URL from the last mint, so re-clicking reuses it (and its QR) instead of creating a new one every time. |
| `checkout_expires_at`    | Our own bookkeeping expiry for `checkout_url` reuse — see [Link reuse and expiry](#link-reuse-and-expiry). |

Plus `idx_payments_checkout_ref (checkout_provider, external_ref)`, the index
the webhook match above depends on. The migration is additive and idempotent
(`ADD COLUMN IF NOT EXISTS`), applied in order by `php scripts/migrate.php`.

## API surface

```text
POST /api/events/{eventId}/payments/{paymentId}/send-link     mint/reuse a checkout link + QR for an existing payment record
POST /api/events/{eventId}/payments/0/deposit-link             find-or-create the deposit payment record, then the above
```

Both require the `manage_payments` event capability and return:

```json
{ "payment_link": "https://checkout.stripe.com/...", "provider": "stripe" }
```

`deposit-link` additionally includes `"payment_id": <int>` (the found-or-created
record's id, for a client that wants to jump straight to it).

Error responses:

| Status | When |
|---|---|
| `401` | Not authenticated |
| `403` | Missing `manage_payments` |
| `404` | Event (or, for `send-link`, the payment record) not found |
| `422` | `send-link`: payment amount `<= 0`, or payment already `received`/`voided`. `deposit-link`: no deposit configured, deposit `waived`, or outstanding balance `<= 0` (already fully received). |
| `502` | The active payment provider is not configured or rejected the checkout request |

Full request/response schema: `docs/openapi.yaml`, paths
`/api/events/{eventId}/payments/{paymentId}/send-link` and
`/api/events/{eventId}/payments/{paymentId}/deposit-link`.

## Setup

- Migration 087 applied (`php scripts/migrate.php`) — already applied on
  this box.
- A payment provider configured and selected: Admin → Payments
  (`#admin-payments`, `manage_users`) sets `payment_settings.active_provider`
  (`'stripe'` or `'square'`) and the provider's own credentials, same
  settings the ticketing checkout flow depends on. See the required `.env`
  keys in the [Ticketing And Payments](../README.md#ticketing-and-payments)
  README section — this feature adds no new environment variables.
- `APP_URL` must be set — success/cancel redirects bounce back to
  `{APP_URL}/{event_public_path}?deposit=paid` /
  `...&deposit=canceled` (deliberately **not** the `?checkout=success&order=...`
  shape `tickets-public.js` reads; a payment id there would 404 against the
  ticket-orders lookup, so a distinct query-flag shape is used instead).

## Operator runbook

**Sending a deposit link once a lead is onboarded, without opening the event:**
From the Booking Inbox, open the lead and use the overflow menu →
**Deposit Link / QR**. This only appears after the inquiry has been onboarded
into an event. It finds or creates the outstanding deposit payment record for
you — nothing to set up on the Payments tab first.

**Sending/re-sending from the Payments tab:** the button reads **Payment
Link / QR** (not "Send Invoice Link" — nothing is emailed automatically;
share the QR or copy the link yourself). Clicking it on a payment that
already has a cached, unexpired link re-opens the same link/QR rather than
minting a new one — safe to click again if you just want to see the QR once
more.

**A payment shows "Link sent (Stripe)" but the customer says they paid:**
Click the **Link sent (…)** button to re-open the modal and confirm it's the
right link/amount. If the webhook genuinely didn't arrive (provider outage,
misconfigured `STRIPE_WEBHOOK_SECRET`/Square signature key), the payment
record stays `invoiced` rather than `received` — check the provider's own
dashboard for the checkout session by `external_ref`, and if it's genuinely
paid, edit the payment record directly (Payments tab → Edit → status
`received`) rather than waiting on a webhook redelivery that may not come.

**Switching the active payment provider mid-flight:** safe. Each payment
record captured which provider minted its link at the time
(`checkout_provider`), so an in-flight Stripe link keeps matching Stripe's
webhook even after `payment_settings.active_provider` is switched to Square —
only the *next* link minted (cache expired or a brand-new payment) picks up
the new provider.

**Deposit already fully received, but someone clicks Deposit Link / QR
again:** 422, "This event's deposit has already been fully received." — by
design; there's nothing left to collect.

**Deposit was waived, but someone clicks Deposit Link / QR:** 422, "This
event's deposit has been waived — no payment is needed." `waived` is sticky
against `syncDepositStatus()`, so this won't silently flip back to
`requested` even after unrelated payment activity on the event.
