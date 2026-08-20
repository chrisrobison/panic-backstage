-- Physical ticket batch printing: an operator requests N pre-printed tickets
-- for a tier (e.g. to send to a commercial print shop), the app creates real
-- `tickets` rows up front (unique token + sequential printed_number per
-- ticket) and can regenerate a print-ready PDF from those same rows at any
-- time. See src/PhysicalTicketBatchService.php / src/PhysicalTicketPdfGenerator.php.
--
-- Deliberately reuses existing columns rather than inventing parallel ones:
--   tickets.printed_number  -> the human-readable "Ticket #000037" (migration 107)
--   tickets.token/token_hash -> the QR credential (TicketingService::generateToken())
--   tickets.status           -> unchanged ('issued'/'redeemed'/'void'), so the
--                               door scanner's existing state machine still works
-- New columns only cover what's genuinely new: which batch a ticket belongs
-- to, whether it was born physical vs. digital, and — importantly — a finer
-- physical lifecycle (printed/allocated/sold/...) so a freshly printed but
-- unsold ticket can be told apart from one actually sold to someone. Without
-- that second state, Scanner::redeem() would admit ANY printed stub the
-- instant it exists, before it's ever sold — see the Scanner.php change in
-- this same feature.

CREATE TABLE IF NOT EXISTS `physical_ticket_batches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `ticket_type_id` int(11) NOT NULL,
  `name` varchar(200) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `first_ticket_number` int(11) NOT NULL,
  `last_ticket_number` int(11) NOT NULL,
  `number_pad_width` tinyint(3) unsigned NOT NULL DEFAULT 6,
  -- Free text, not a FK: an external box office / seller (e.g. "Amoeba
  -- Records") is not a Panic user account and there is no seller entity in
  -- this schema to reference.
  `seller_label` varchar(200) DEFAULT NULL,
  `ticket_width_in` decimal(6,3) NOT NULL DEFAULT 2.000,
  `ticket_height_in` decimal(6,3) NOT NULL DEFAULT 5.500,
  `bleed_in` decimal(6,3) NOT NULL DEFAULT 0.125,
  `crop_marks` tinyint(1) NOT NULL DEFAULT 0,
  -- Relative path under storage/ (web-inaccessible) to an optional PNG/JPEG
  -- background image; NULL for a text-only ticket.
  `artwork_path` varchar(255) DEFAULT NULL,
  `created_by_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','void') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  KEY `idx_physical_ticket_batches_event` (`event_id`),
  KEY `idx_physical_ticket_batches_type` (`ticket_type_id`),
  CONSTRAINT `physical_ticket_batches_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `physical_ticket_batches_ibfk_2` FOREIGN KEY (`ticket_type_id`) REFERENCES `ticket_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ON DELETE CASCADE (not RESTRICT): there is no admin endpoint that deletes a
-- batch on its own, so this only matters for the rare hard-delete-an-event
-- path (events -> tickets is already ON DELETE CASCADE, and events ->
-- physical_ticket_batches is CASCADE too). If this FK were RESTRICT instead,
-- deleting an event with a physical batch could fail outright, depending on
-- the order MySQL happens to walk the cascade graph: it might try to cascade
-- physical_ticket_batches away before tickets, hit this FK, and abort the
-- whole DELETE. CASCADE here is redundant with (not a substitute for) the
-- direct tickets->events cascade in the normal case, and simply avoids that
-- ordering conflict in the edge case.
ALTER TABLE `tickets`
  ADD COLUMN IF NOT EXISTS `physical_batch_id` int(11) DEFAULT NULL
    COMMENT 'Set only for tickets born from a physical print batch; see physical_ticket_batches.'
    AFTER `order_id`;

ALTER TABLE `tickets`
  ADD COLUMN IF NOT EXISTS `delivery_method` enum('digital','physical') NOT NULL DEFAULT 'digital'
    AFTER `physical_batch_id`;

-- NULL for every digital ticket (delivery_method='digital'). For a physical
-- ticket this is the real admission gate: 'printed'/'allocated' means the
-- stub exists but has not been sold to anyone yet and MUST NOT admit at the
-- door (see Scanner.php); 'sold' means a buyer has it; 'checked_in' mirrors
-- tickets.status='redeemed' for a physical ticket. The other states (RETURNED,
-- VOID, LOST, REFUNDED) are here so future workflows have somewhere to land
-- without another migration.
ALTER TABLE `tickets`
  ADD COLUMN IF NOT EXISTS `physical_status` enum('printed','allocated','sold','checked_in','returned','void','lost','refunded') DEFAULT NULL
    AFTER `delivery_method`;

ALTER TABLE `tickets`
  ADD KEY IF NOT EXISTS `idx_tickets_physical_batch` (`physical_batch_id`);

-- Only add the FK if it doesn't already exist (MySQL has no
-- "ADD CONSTRAINT IF NOT EXISTS"; guard via information_schema so a re-run
-- after a partial failure is harmless, per this folder's README).
SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA = DATABASE()
     AND TABLE_NAME = 'tickets'
     AND CONSTRAINT_NAME = 'fk_tickets_physical_batch'
);
-- The no-op branch must NOT be a statement that returns a result set (e.g.
-- SELECT): this app's PDO connection runs with ATTR_EMULATE_PREPARES=false,
-- and EXECUTE-ing a SELECT via PREPARE/EXECUTE through plain PDO::exec()
-- leaves an unbuffered result set open, which breaks the very next statement
-- (DEALLOCATE PREPARE) with "Cannot execute queries while other unbuffered
-- queries are active." SET produces no result set, so it's safe either way.
SET @sql = IF(@fk_exists = 0,
  'ALTER TABLE `tickets` ADD CONSTRAINT `fk_tickets_physical_batch` FOREIGN KEY (`physical_batch_id`) REFERENCES `physical_ticket_batches` (`id`) ON DELETE CASCADE',
  'SET @dummy = 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- New audit outcome: a scan of a physical ticket that has not been sold yet
-- (physical_status IN ('printed','allocated')) is rejected distinctly from
-- 'not_found'/'void' so the door log and scanner UI can say exactly what
-- happened. Re-running this MODIFY is a harmless no-op.
ALTER TABLE `ticket_scans`
  MODIFY COLUMN `result` enum('admitted','already_redeemed','void','not_found','wrong_event','expired_link','manual_admit','not_activated') NOT NULL;
