-- 057_ticket_order_receipt_token.sql
--
-- Adds ticket_orders.receipt_token: a random opaque credential (distinct
-- from any individual ticket's secret token) minted when a public checkout
-- is created and embedded in the payment provider's success_url.
--
-- Why: today the buyer's ticket QR is only delivered by email, sent
-- asynchronously when the payment webhook fulfills the order. A door/
-- walk-up buyer who scans an event QR, pays, and gets bounced back to the
-- public event page sees nothing until they go find that email. This token
-- lets the public page poll "how did my just-completed purchase turn out?"
-- (GET /api/public/tickets/{eventId}/orders/{orderId}?receipt=...) and
-- render the QR inline the moment fulfillment completes.
--
-- Deliberately NOT the sequential ticket_orders.id: that's guessable/
-- enumerable, so the lookup endpoint requires this token before revealing
-- anything about an order — a leaked/guessed order id alone exposes nothing.
ALTER TABLE ticket_orders
  ADD COLUMN IF NOT EXISTS receipt_token VARCHAR(64) NULL DEFAULT NULL AFTER provider_payment_ref;

ALTER TABLE ticket_orders
  ADD UNIQUE KEY IF NOT EXISTS idx_ticket_orders_receipt_token (receipt_token);
