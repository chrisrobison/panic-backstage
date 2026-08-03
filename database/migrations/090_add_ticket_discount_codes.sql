-- Private discount / coupon codes for in-house ticketing.
--
-- Panic previously had exactly two levers for giving somebody a break on a
-- ticket: drop the tier's public price (which everybody gets) or issue a comp
-- (100% off, one recipient at a time). Neither covers the common outreach case
-- — "send a handful of people a code worth 30% off so they'll make the trip."
-- This adds codes that are invisible on the public ticket page and only do
-- anything for a buyer who actually types (or is linked with) the code.
--
-- Money model — deliberately NOT a separate "discount" ledger:
--   The discounted price is written straight into
--   ticket_order_items.unit_price_cents, so the amount we charge the payment
--   provider, the amount stored on the order, and the amount every existing
--   report already derives from (SUM(oi.quantity * oi.unit_price_cents) — see
--   src/Reports.php, Events\Ticketing::dashboard, and the settlement sync) are
--   the same number by construction. Discounted revenue therefore nets out of
--   tier revenue, gross ticket sales, and the settlement report with no
--   reporting changes and no chance of the two drifting apart.
--
--   ticket_orders.discount_cents is the *memo* of what was given away (face
--   value minus charged), kept only so the admin UI can answer "what did this
--   code cost us" — it is never added back into revenue anywhere.
--
-- Redemption counting is intentionally derived, not a counter column: a code's
-- usage is COUNTed live over ticket_orders that are paid/fulfilled or still
-- holding inventory (status='pending' AND hold_expires_at > NOW()). That
-- mirrors how TicketingService::availableQuantity already treats holds, so an
-- abandoned checkout releases its claim on a limited code automatically when
-- the hold lapses, with no bookkeeping to get wrong.

CREATE TABLE IF NOT EXISTS `ticket_discount_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `code` varchar(40) NOT NULL
    COMMENT 'Stored already normalized (uppercase, no internal whitespace) — see Panic\\TicketDiscounts::normalizeCode. Lookups normalize the buyer input the same way, so matching is exact rather than collation-dependent.',
  `label` varchar(120) DEFAULT NULL
    COMMENT 'Internal-only note ("East Bay outreach") shown in the admin list. Never sent to the public surface.',
  `kind` enum('percent','fixed') NOT NULL DEFAULT 'percent',
  `percent_off` int(11) NOT NULL DEFAULT 0
    COMMENT 'Whole percent 1-100, used when kind=percent.',
  `amount_off_cents` int(11) NOT NULL DEFAULT 0
    COMMENT 'Flat amount off the order, used when kind=fixed. Clamped to the eligible subtotal so an order can never go negative.',
  `max_uses` int(11) DEFAULT NULL
    COMMENT 'NULL = unlimited. Counted live over paid/fulfilled orders plus unexpired holds.',
  `once_per_email` tinyint(1) NOT NULL DEFAULT 0
    COMMENT 'Block a second redemption by the same buyer_email. Soft by nature (new addresses are free) — a speed bump, not an identity check.',
  `starts_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL
    COMMENT 'Independent of the tier''s own sales window; both must be open for the code to apply.',
  `status` enum('active','disabled') NOT NULL DEFAULT 'active',
  `created_by_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ticket_discount_codes_event_code` (`event_id`, `code`),
  KEY `idx_ticket_discount_codes_event` (`event_id`),
  CONSTRAINT `fk_ticket_discount_codes_event` FOREIGN KEY (`event_id`)
    REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ticket_discount_codes_user` FOREIGN KEY (`created_by_user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional tier scoping. NO rows for a code = the code applies to every tier on
-- the event; one or more rows = it applies only to those tiers (and a cart
-- containing nothing else is rejected as "not valid for the selected tickets").
CREATE TABLE IF NOT EXISTS `ticket_discount_code_types` (
  `discount_code_id` int(11) NOT NULL,
  `ticket_type_id` int(11) NOT NULL,
  PRIMARY KEY (`discount_code_id`, `ticket_type_id`),
  KEY `idx_tdct_type` (`ticket_type_id`),
  CONSTRAINT `fk_tdct_code` FOREIGN KEY (`discount_code_id`)
    REFERENCES `ticket_discount_codes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tdct_type` FOREIGN KEY (`ticket_type_id`)
    REFERENCES `ticket_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `ticket_orders`
  ADD COLUMN IF NOT EXISTS `discount_code_id` int(11) DEFAULT NULL
    COMMENT 'Discount code redeemed on this order, if any. ON DELETE SET NULL so deleting a code never destroys order history.'
    AFTER `is_comp`,
  ADD COLUMN IF NOT EXISTS `discount_cents` int(11) NOT NULL DEFAULT 0
    COMMENT 'Memo: face value minus what was actually charged. amount_cents is ALREADY net of this — never add it back when computing revenue.'
    AFTER `discount_code_id`,
  ADD KEY IF NOT EXISTS `idx_ticket_orders_discount_code` (`discount_code_id`);

-- Added separately from the columns above: ADD CONSTRAINT has no IF NOT EXISTS
-- in MariaDB/MySQL, so a re-run after a partial failure would abort the whole
-- ALTER. Guarding on information_schema keeps this migration re-runnable.
SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA = DATABASE()
     AND TABLE_NAME = 'ticket_orders'
     AND CONSTRAINT_NAME = 'fk_ticket_orders_discount_code'
);
SET @sql := IF(@fk_exists = 0,
  'ALTER TABLE `ticket_orders` ADD CONSTRAINT `fk_ticket_orders_discount_code`
     FOREIGN KEY (`discount_code_id`) REFERENCES `ticket_discount_codes` (`id`) ON DELETE SET NULL',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
