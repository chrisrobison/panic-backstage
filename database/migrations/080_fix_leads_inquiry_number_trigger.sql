-- Corrective fix-up for a database (like this one) that already applied
-- the original, broken 079_add_leads_inquiry_number_trigger.sql — an AFTER
-- INSERT trigger that issued a separate UPDATE against `leads`, which
-- MariaDB rejects at runtime ("Can't update table `leads` in stored
-- function/trigger because it is already used by statement which invoked
-- this stored function/trigger"), confirmed by actually inserting a lead
-- through scripts/ingest-booking-email.php against this database. 079 has
-- since been corrected in place to create the BEFORE INSERT / SET NEW.*
-- version directly (so any database applying it fresh never sees the
-- broken one); this migration re-does the same DROP+CREATE for a database
-- whose ledger already marks the old 079 as applied.
DROP TRIGGER IF EXISTS `trg_leads_inquiry_number`;

CREATE TRIGGER `trg_leads_inquiry_number` BEFORE INSERT ON `leads` FOR EACH ROW
SET NEW.inquiry_number = COALESCE(NEW.inquiry_number, CONCAT('INQ-', LPAD(MOD(UUID_SHORT(), 1000000000), 9, '0')));
