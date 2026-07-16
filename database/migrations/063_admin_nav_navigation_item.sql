-- 063_admin_nav_navigation_item.sql
--
-- Migration 062 added the Admin > Navigation ("Navigation manager") screen
-- but its seed data never gave it a sidebar entry — every other Admin tab
-- (Users, Staff, Templates, Contracts, Payments, Venue, DB Browser, DB
-- History, All Email) has a matching child row under the "Admin" nav group
-- (id 9) that deep-links straight to it, but Navigation didn't, so it was
-- only reachable by first landing on Admin via some other tab and then
-- clicking the in-page tab bar. This adds the missing entry, gated by
-- manage_navigation (only venue_admin holds it — see
-- BaseEndpoint::GLOBAL_CAPABILITIES) to match who can actually use the
-- screen, same reasoning migration 062 used for the other Admin children.
--
-- Uses INSERT...SELECT...WHERE NOT EXISTS rather than a hardcoded id
-- (unlike 062's seed) since this runs against databases that may already
-- have admin-created nav_items rows occupying ids past the original seed
-- range — this only needs "does this link already exist", not a specific id.
INSERT INTO nav_items (parent_id, label, icon, link, capability, open_in_new_window, visible, is_home, sort_order)
SELECT 9, 'Navigation', 'fa-solid fa-bars', 'admin-navigation', 'manage_navigation', 0, 1, 0, 100
WHERE NOT EXISTS (
  SELECT 1 FROM nav_items WHERE parent_id = 9 AND link = 'admin-navigation'
);
