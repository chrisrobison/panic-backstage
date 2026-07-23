-- Fix: migration 071 only backfilled `inquiry_number` for rows that already
-- existed at the time — nothing generated one for leads created afterward
-- (found by actually running scripts/ingest-booking-email.php end-to-end
-- against this database and noticing the new row's inquiry_number was
-- NULL). Every current creation path (src/Leads.php, src/PublicInquiry.php,
-- scripts/ingest-booking-email.php) inserts without one, so generating it
-- once here — rather than adding the same follow-up step to every creation
-- site (and every future one) — is the reliable fix.
--
-- This is a BEFORE INSERT trigger that sets NEW.inquiry_number directly
-- (not an AFTER INSERT trigger issuing a separate UPDATE — that was tried
-- first and rejected by MariaDB with "Can't update table `leads` in
-- stored function/trigger because it is already used by statement which
-- invoked this stored function/trigger": a trigger may not itself run a
-- statement against the same table its firing statement is already using).
-- A BEFORE trigger avoids this because assigning to NEW.* only changes the
-- pending row, it isn't a separate statement against `leads`.
--
-- That also means `leads.id` (AUTO_INCREMENT) isn't known yet at BEFORE
-- INSERT time, so the number is derived from UUID_SHORT() (a MariaDB
-- built-in: a 64-bit value combining this server's start time and a
-- monotonic counter, unique for the server's lifetime) rather than the row
-- id — still a compact, unique, human-referenceable number, just not
-- guaranteed strictly sequential. The UNIQUE KEY on inquiry_number
-- (migration 071) is the backstop against the astronomically unlikely
-- collision.
--
-- Single-statement trigger body (no BEGIN/END) deliberately: see
-- database/migrations/077_add_booking_inbox_tasks_link_and_nav.sql's
-- sibling note — scripts/migrate.php's statement splitter has no notion of
-- a MySQL DELIMITER change, so a multi-statement BEGIN...END body would be
-- silently chopped into broken fragments.
DROP TRIGGER IF EXISTS `trg_leads_inquiry_number`;

CREATE TRIGGER `trg_leads_inquiry_number` BEFORE INSERT ON `leads` FOR EACH ROW
SET NEW.inquiry_number = COALESCE(NEW.inquiry_number, CONCAT('INQ-', LPAD(MOD(UUID_SHORT(), 1000000000), 9, '0')));
