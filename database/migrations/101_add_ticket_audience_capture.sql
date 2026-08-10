-- Link public ticket registrations to the CRM and retain explicit marketing
-- consent. audience_synced_at is the idempotency marker used by
-- TicketingService so webhook retries never inflate contact statistics.

ALTER TABLE `ticket_orders`
  ADD COLUMN IF NOT EXISTS `contact_id` bigint(20) DEFAULT NULL AFTER `buyer_user_id`,
  ADD COLUMN IF NOT EXISTS `marketing_opt_in` tinyint(1) NOT NULL DEFAULT 0 AFTER `buyer_phone`,
  ADD COLUMN IF NOT EXISTS `audience_synced_at` datetime DEFAULT NULL AFTER `marketing_opt_in`,
  ADD KEY IF NOT EXISTS `idx_ticket_orders_contact` (`contact_id`);

SET @ticket_contact_fk_exists := (
  SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA = DATABASE()
     AND TABLE_NAME = 'ticket_orders'
     AND CONSTRAINT_NAME = 'fk_ticket_orders_contact'
);
SET @ticket_contact_fk_sql := IF(
  @ticket_contact_fk_exists = 0,
  'ALTER TABLE `ticket_orders` ADD CONSTRAINT `fk_ticket_orders_contact` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE ticket_contact_fk_stmt FROM @ticket_contact_fk_sql;
EXECUTE ticket_contact_fk_stmt;
DEALLOCATE PREPARE ticket_contact_fk_stmt;
