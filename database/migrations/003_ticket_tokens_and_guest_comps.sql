-- Support viewing/printing/resending a ticket's QR, and comping guests.
--
-- 1) Store each ticket's scannable token alongside its hash, so an admin can
--    re-display the QR or resend the link after issuance. Same deliberate
--    tradeoff the scanner links already make (token + token_hash) — a DB leak
--    could expose scannable tokens, accepted for operability. token_hash stays
--    the unique redeem key.
-- 2) Give guest-list entries an email and a link to the comp order issued for
--    them, so comps can be emailed straight from the guest list and re-sent.
ALTER TABLE `tickets`           ADD COLUMN IF NOT EXISTS `token` varchar(64) DEFAULT NULL AFTER `token_hash`;
ALTER TABLE `event_guest_list`  ADD COLUMN IF NOT EXISTS `email` varchar(255) DEFAULT NULL AFTER `name`;
ALTER TABLE `event_guest_list`  ADD COLUMN IF NOT EXISTS `comp_order_id` int(11) DEFAULT NULL AFTER `list_type`;
