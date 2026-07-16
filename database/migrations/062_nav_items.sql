-- 062_nav_items.sql
--
-- Adds nav_items: the data behind the app shell's main sidebar navigation,
-- managed via the new Admin > Navigation ("Navigation Manager") screen. The
-- sidebar previously hardcoded its menu structure in public/assets/app.js;
-- from this migration on, that markup is generated from this table (see
-- public/assets/nav-shared.js) so nav changes are a data edit, not a code
-- change requiring a deploy.
--
-- At most two levels deep (top-level items + one level of children) —
-- matches what the .nav-group/.nav-children sidebar CSS actually supports.
-- A NULL parent_id is a top-level item; a purely-grouping parent (e.g.
-- "Events", "Admin") has a NULL link since it renders as an expand/collapse
-- button, not an anchor.
--
-- Seed data below reproduces the exact menu that was previously hardcoded in
-- app.js's renderShell(), minus the Help submenu (generated separately from
-- HELP_SECTIONS in help.js — a content table, not a link list) and the
-- mobile bottom tab bar (a fixed, deliberately-curated 5-shortcut UX, not
-- derived from the full tree). Capability values mirror the old
-- applyCapabilities() checks in app.js. A handful of Admin-group children
-- (Staff, admin-Templates, Contracts, Payments, Venue, All Email) had no
-- individual capability check before — they were only ever shown because,
-- of the four capabilities that old code ORed together to decide whether to
-- show the whole Admin group at all (manage_users / manage_staff_roster /
-- manage_templates / manage_db_history), only the venue_admin role holds any
-- of them, so in practice only venue_admin ever saw the group. Each of those
-- children is seeded with its closest matching real capability below —
-- identical real-world visibility today, but each item is now individually
-- correct and editable instead of riding on a bundled group-level OR-check.
CREATE TABLE IF NOT EXISTS nav_items (
  id INT NOT NULL AUTO_INCREMENT,
  parent_id INT NULL DEFAULT NULL,
  label VARCHAR(80) NOT NULL,
  icon VARCHAR(60) NOT NULL DEFAULT 'fa-solid fa-circle',
  link VARCHAR(255) NULL DEFAULT NULL,
  capability VARCHAR(60) NULL DEFAULT NULL,
  open_in_new_window TINYINT(1) NOT NULL DEFAULT 0,
  visible TINYINT(1) NOT NULL DEFAULT 1,
  is_home TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY parent_id (parent_id),
  CONSTRAINT nav_items_parent_fk FOREIGN KEY (parent_id)
    REFERENCES nav_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Top-level items (explicit ids so the child INSERTs below and any re-run of
-- this migration stay deterministic; INSERT IGNORE makes the whole seed a
-- no-op once the ids already exist).
INSERT IGNORE INTO nav_items (id, parent_id, label, icon, link, capability, open_in_new_window, visible, is_home, sort_order) VALUES
  (1, NULL, 'Dashboard', 'fa-solid fa-gauge-high', 'dashboard', NULL, 0, 1, 1, 10),
  (2, NULL, 'Reports', 'fa-solid fa-chart-line', 'reports', 'view_reports', 0, 1, 0, 20),
  (3, NULL, 'Leads', 'fa-solid fa-filter', 'leads', 'view_leads', 0, 1, 0, 30),
  (4, NULL, 'Contacts', 'fa-solid fa-address-book', 'contacts', 'manage_contacts', 0, 1, 0, 40),
  (5, NULL, 'Promote', 'fa-solid fa-bullhorn', 'promote', NULL, 0, 1, 0, 50),
  (6, NULL, 'Messages', 'fa-solid fa-envelope', NULL, NULL, 0, 1, 0, 60),
  (7, NULL, 'Events', 'fa-solid fa-ticket', NULL, NULL, 0, 1, 0, 70),
  (8, NULL, 'Settings', 'fa-solid fa-gear', NULL, NULL, 0, 1, 0, 80),
  (9, NULL, 'Admin', 'fa-solid fa-user-shield', NULL, NULL, 0, 1, 0, 90);

-- Messages children (parent_id = 6)
INSERT IGNORE INTO nav_items (id, parent_id, label, icon, link, capability, open_in_new_window, visible, is_home, sort_order) VALUES
  (10, 6, 'Inbox', 'fa-solid fa-inbox', 'inbox', NULL, 0, 1, 0, 10),
  (11, 6, 'Archive', 'fa-solid fa-box-archive', 'archive', NULL, 0, 1, 0, 20),
  (12, 6, 'Outbox', 'fa-solid fa-paper-plane', 'sent', NULL, 0, 1, 0, 30),
  (13, 6, 'Campaigns', 'fa-solid fa-envelope-open-text', 'campaigns', 'manage_campaigns', 0, 1, 0, 40),
  (14, 6, 'Lists', 'fa-solid fa-rectangle-list', 'lists', 'manage_campaigns', 0, 1, 0, 50),
  (15, 6, 'ListMaster', 'fa-solid fa-table-list', 'listmaster', 'manage_campaigns', 0, 1, 0, 60);

-- Events children (parent_id = 7)
INSERT IGNORE INTO nav_items (id, parent_id, label, icon, link, capability, open_in_new_window, visible, is_home, sort_order) VALUES
  (16, 7, 'List', 'fa-solid fa-list', 'events', NULL, 0, 1, 0, 10),
  (17, 7, 'Upcoming', 'fa-solid fa-calendar-check', 'upcoming', NULL, 0, 1, 0, 20),
  (18, 7, 'Calendar', 'fa-solid fa-calendar-days', 'calendar', NULL, 0, 1, 0, 30),
  (19, 7, 'Pipeline', 'fa-solid fa-table-columns', 'pipeline', NULL, 0, 1, 0, 40),
  (20, 7, 'Assets', 'fa-solid fa-images', 'asset-library', NULL, 0, 1, 0, 50);

-- Settings children (parent_id = 8)
INSERT IGNORE INTO nav_items (id, parent_id, label, icon, link, capability, open_in_new_window, visible, is_home, sort_order) VALUES
  (21, 8, 'Account', 'fa-solid fa-user', 'account', NULL, 0, 1, 0, 10),
  (22, 8, 'Templates', 'fa-solid fa-layer-group', 'templates', 'manage_templates', 0, 1, 0, 20),
  (23, 8, 'Preferences', 'fa-solid fa-sliders', 'preferences', NULL, 0, 1, 0, 30),
  (24, 8, 'Promote', 'fa-solid fa-bullhorn', 'promote-settings', NULL, 0, 1, 0, 40);

-- Admin children (parent_id = 9)
INSERT IGNORE INTO nav_items (id, parent_id, label, icon, link, capability, open_in_new_window, visible, is_home, sort_order) VALUES
  (25, 9, 'Users', 'fa-solid fa-user-gear', 'admin-users', 'manage_users', 0, 1, 0, 10),
  (26, 9, 'Staff', 'fa-solid fa-people-group', 'admin-staff', 'manage_staff_roster', 0, 1, 0, 20),
  (27, 9, 'Templates', 'fa-solid fa-layer-group', 'admin-templates', 'manage_templates', 0, 1, 0, 30),
  (28, 9, 'Contracts', 'fa-solid fa-file-signature', 'admin-contracts', 'manage_users', 0, 1, 0, 40),
  (29, 9, 'Payments', 'fa-solid fa-credit-card', 'admin-payments', 'manage_users', 0, 1, 0, 50),
  (30, 9, 'Venue', 'fa-solid fa-building', 'admin-venue', 'manage_users', 0, 1, 0, 60),
  (31, 9, 'DB Browser', 'fa-solid fa-database', 'admin-db', 'manage_users', 0, 1, 0, 70),
  (32, 9, 'DB History', 'fa-solid fa-clock-rotate-left', 'admin-db-history', 'manage_db_history', 0, 1, 0, 80),
  (33, 9, 'All Email', 'fa-solid fa-paper-plane', 'outbox', 'manage_users', 0, 1, 0, 90);

-- Keep AUTO_INCREMENT ahead of the explicit ids above so the next
-- admin-created item doesn't collide with a re-run of this seed.
ALTER TABLE nav_items AUTO_INCREMENT = 34;
