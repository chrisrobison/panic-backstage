-- Private, token-gated receipts for hosted event payments.  The token is
-- minted before checkout so the provider can return the payer directly to a
-- receipt page even when the underlying event is not publicly visible.
ALTER TABLE `event_payments`
  ADD COLUMN IF NOT EXISTS `receipt_token` char(64) DEFAULT NULL
    COMMENT 'Opaque token granting access to this payment receipt and PDF; never use the event public_visibility flag for receipt authorization.'
    AFTER `checkout_expires_at`,
  ADD COLUMN IF NOT EXISTS `receipt_emailed_at` datetime DEFAULT NULL
    COMMENT 'UTC time the receipt email was accepted by the local MTA; NULL keeps webhook retries eligible to resend after a delivery failure.'
    AFTER `receipt_token`,
  ADD UNIQUE KEY IF NOT EXISTS `uq_event_payments_receipt_token` (`receipt_token`);
