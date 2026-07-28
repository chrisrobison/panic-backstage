-- Booking Inbox outbound "From" identity, read from settings instead of the
-- hard-coded 'bookings@themab.org' / 'Mabuhay Gardens Booking Team' that
-- previously lived directly in src/Leads/Acknowledgment.php and
-- src/LeadsInbox.php's reply-send path. See src/Leads/OutboundIdentity.php.
--
-- from_email is intentionally NOT free-form-safe to repoint at just any
-- address: Mailer always sets Reply-To to the same address as From (see
-- Mailer::buildMessage()), and the Exim ingestion pipe
-- (scripts/ingest-booking-email.php, docs/booking-email-import.md) only
-- reads one fixed mailbox. Changing from_email away from that mailbox means
-- customer replies stop being ingested — operational constraint documented
-- here and in docs/booking-inbox.md, not (yet) enforced in code since there
-- is no settings admin UI for lead_inbox_settings at all today.
ALTER TABLE `lead_inbox_settings`
  ADD COLUMN IF NOT EXISTS `from_name` varchar(255) DEFAULT NULL
    COMMENT 'Outbound display name for auto-ack + manual replies. Falls back to "{venue name} Booking Team" when null (see OutboundIdentity).'
    AFTER `ack_body`,
  ADD COLUMN IF NOT EXISTS `from_email` varchar(255) DEFAULT NULL
    COMMENT 'Outbound From/Reply-To address. Must match the mailbox the Exim ingestion pipe reads (docs/booking-email-import.md), or customer replies stop threading back in.'
    AFTER `from_name`;

-- Backfill existing rows with the previously hard-coded values so behavior
-- is unchanged for the current (single-tenant) Mabuhay install until an
-- admin chooses to configure something different.
UPDATE `lead_inbox_settings`
SET `from_email` = 'bookings@themab.org'
WHERE `from_email` IS NULL;

UPDATE `lead_inbox_settings` s
JOIN `venues` v ON v.id = s.venue_id
SET s.`from_name` = 'Mabuhay Gardens Booking Team'
WHERE s.`from_name` IS NULL AND v.`slug` = 'mabuhay-gardens';

UPDATE `lead_inbox_settings` s
JOIN `venues` v ON v.id = s.venue_id
SET s.`from_name` = CONCAT(v.`name`, ' Booking Team')
WHERE s.`from_name` IS NULL;
