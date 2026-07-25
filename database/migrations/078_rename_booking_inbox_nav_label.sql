-- Corrective fix-up for 077_add_booking_inbox_tasks_link_and_nav.sql, which
-- originally seeded the new top-level nav group as plain "Inbox" before it
-- was noticed that nav_items already has an unrelated "Inbox" child under
-- the existing "Messages" group (src/Messages.php's staff-to-staff inbox) —
-- two identically-labeled "Inbox" entries would otherwise show in the left
-- nav. 077 has since been corrected to seed "Booking Inbox" directly for
-- any database that applies it fresh; this migration renames the row for a
-- database (like this one) that already ran the original version.
--
-- Identified unambiguously by parent_id IS NULL + capability =
-- 'view_booking_inbox' (the Messages inbox child has parent_id = the
-- Messages group's id, not NULL, and capability NULL) — never by id, since
-- ids differ across environments.
UPDATE `nav_items`
SET `label` = 'Booking Inbox'
WHERE `label` = 'Inbox' AND `parent_id` IS NULL AND `capability` = 'view_booking_inbox';
