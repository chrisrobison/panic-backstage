-- Walk-up ticket sales at the door.
--
-- Until now the door scanner could only *consume* tickets that already
-- existed: someone bought online, got a QR, and the scanner flipped it
-- issued -> redeemed. Every real door has the other half of the transaction
-- too — the person who shows up with cash and no ticket. That sale was
-- invisible to Panic, so headcount, tier revenue, and the settlement report
-- all under-reported by exactly the walk-up business, which at this venue is
-- most of it.
--
-- Money model — deliberately reuses the existing order pipeline:
--   A door sale writes an ordinary ticket_orders row (provider='door',
--   is_comp=0, status='fulfilled', paid_at=NOW()) with its
--   ticket_order_items.unit_price_cents set to the tier price actually
--   charged. That is the same shape a Stripe/Square order lands in, so every
--   report that already derives revenue from
--   SUM(oi.quantity * oi.unit_price_cents) — src/Reports.php,
--   Events\Ticketing::dashboard, the settlement sync — picks up door revenue
--   with zero reporting changes and no second source of truth to drift.
--   Inventory moves through the identical oversell-guarded quantity_sold
--   increment used by fulfillOrder()/issueComp(), so the door cannot sell a
--   tier past its capacity even while online checkout is racing it.
--
-- payment_method: the one thing the existing order shape could not express.
--   Provider tells you *which system* took the money ('stripe', 'square',
--   'comp', now 'door'); it does not tell you whether the cash drawer or a
--   card reader has it. Reconciling the drawer at the end of the night is the
--   whole point of logging door sales, so the split gets a real column rather
--   than being parsed back out of provider_ref later. NULL for every existing
--   and every online order — the column only means something when the venue
--   itself handled the money.
--
-- can_sell: scanner links are bearer URLs. A leaked link previously bounded
--   the damage at "marks tickets used"; the sell endpoint would let it mint
--   orders and inflate quantity_sold and revenue. So the capability is opt-in
--   per link and defaults to 0 — every link that exists today keeps exactly
--   the powers it was created with, and granting sales is a deliberate act at
--   link-creation time. Scanner::sell() refuses any link without this bit.

ALTER TABLE `ticket_orders`
  ADD COLUMN IF NOT EXISTS `payment_method` ENUM('cash','card','other') DEFAULT NULL
    COMMENT 'How the venue itself took the money (door sales); NULL for online/comp orders'
    AFTER `provider_payment_ref`;

ALTER TABLE `event_scanner_links`
  ADD COLUMN IF NOT EXISTS `can_sell` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Opt-in: may this link ring up walk-up sales, not just scan?'
    AFTER `pin_hash`;

-- Door sales are recorded as sold *and admitted* in one step (the buyer is
-- standing at the door), so they also write a ticket_scans audit row. The
-- existing 'admitted' result is the honest value there — the person did walk
-- in — which keeps "scans" a true headcount without an enum change.
