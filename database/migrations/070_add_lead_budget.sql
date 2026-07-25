-- Capture the prospect's stated budget on a lead, so it's saved alongside
-- the rest of a booking inquiry (from both the internal Leads pipeline form
-- and the public <panic-booking-inquiry> widget — see src/Leads.php and
-- src/PublicInquiry.php) instead of only living in free-text notes.
ALTER TABLE `leads`
  ADD COLUMN IF NOT EXISTS `budget` decimal(10,2) DEFAULT NULL AFTER `projected_attendance`;
