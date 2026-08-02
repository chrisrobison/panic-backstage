-- portal_tokens has so far only ever backed one kind of shareable link (the
-- client-facing payments/invoice portal — see src/Portal.php). Adding a
-- second kind (a read-only, no-login link to a single event's P&L /
-- Settlement Report — src/Events/Report.php's data, same content as the
-- printed Settlement Statement) reuses the same table/token/expiry/revoke
-- machinery rather than standing up a parallel one. `kind` defaults to the
-- existing behavior so every already-issued link keeps working unchanged.
ALTER TABLE `portal_tokens`
  ADD COLUMN IF NOT EXISTS `kind` enum('client_portal','settlement_report') NOT NULL DEFAULT 'client_portal'
    COMMENT 'What the token unlocks: the client payments/invoice portal, or a single event''s Settlement Report.'
    AFTER `event_id`;
