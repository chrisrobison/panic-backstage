-- Provider-agnostic deposit/payment checkout links (QR + link), replacing the
-- previous Stripe-only, non-idempotent "send-link" flow in
-- src/Events/Payments.php. Mirrors ticket_orders' provider/provider_ref/
-- provider_payment_ref columns so the exact same Panic\Payments\PaymentProvider
-- interface (src/Payments/PaymentProvider.php) — already used for ticket
-- checkout and already wired to both Stripe and Square — can be reused here,
-- and so src/Webhooks.php can auto-confirm a deposit/payment the same way it
-- already auto-fulfills ticket orders.
--
-- `external_ref` already existed (previously holding a Stripe Payment Link id,
-- e.g. plink_...) and is repurposed to hold whichever checkout reference the
-- active provider's webhook actually echoes back (Stripe: checkout session
-- id; Square: order id) — same convention as ticket_orders.provider_ref. Its
-- comment is updated to match; no data migration needed since no processor
-- webhook ever matched against it before now.
ALTER TABLE `event_payments`
  MODIFY COLUMN `external_ref` varchar(255) DEFAULT NULL
    COMMENT 'Checkout reference the active provider''s webhook echoes back for matching (Stripe: checkout session id cs_...; Square: order id). See checkout_provider.',
  ADD COLUMN IF NOT EXISTS `checkout_provider` varchar(20) DEFAULT NULL
    COMMENT 'Provider that minted the current checkout link (''stripe''|''square''), captured at link-creation time so a later switch of payment_settings.active_provider never breaks an in-flight link or its webhook match.'
    AFTER `external_ref`,
  ADD COLUMN IF NOT EXISTS `checkout_payment_ref` varchar(255) DEFAULT NULL
    COMMENT 'Payment/charge reference captured from the provider''s payment_succeeded webhook (Stripe PaymentIntent id; Square payment id) — for any future refund flow, mirrors ticket_orders.provider_payment_ref.'
    AFTER `checkout_provider`,
  ADD COLUMN IF NOT EXISTS `checkout_url` text DEFAULT NULL
    COMMENT 'Cached hosted checkout URL from the last mint, so re-clicking "send link" reuses it (and its QR) instead of creating a new one every time, until it expires or is paid.'
    AFTER `checkout_payment_ref`,
  ADD COLUMN IF NOT EXISTS `checkout_expires_at` datetime DEFAULT NULL
    COMMENT 'Our own bookkeeping expiry for checkout_url reuse (not necessarily the provider''s own session TTL) — past this, the next "send link" mints a fresh checkout instead of reusing.'
    AFTER `checkout_url`,
  ADD KEY IF NOT EXISTS `idx_payments_checkout_ref` (`checkout_provider`, `external_ref`);
