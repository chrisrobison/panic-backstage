-- Opportunities module — Phase 2: seed the left-nav "Opportunities" group.
-- Same shape as 077_add_booking_inbox_tasks_link_and_nav.sql: nav_items has
-- no unique key on `link`, so every row is guarded with NOT EXISTS instead
-- of ON DUPLICATE KEY. Every row (group + children) is gated on
-- `view_opportunities` — Phase 1 didn't introduce finer per-child
-- capabilities, and there's no reason a role that can see the module can't
-- see all five of its top-level pages.

INSERT INTO `nav_items` (`parent_id`, `label`, `icon`, `link`, `capability`, `visible`, `sort_order`)
SELECT NULL, 'Opportunities', 'fa-solid fa-bullseye', NULL, 'view_opportunities', 1,
       (SELECT COALESCE(MAX(sort_order), 0) + 10 FROM (SELECT sort_order FROM nav_items WHERE parent_id IS NULL) x)
WHERE NOT EXISTS (SELECT 1 FROM `nav_items` WHERE `label` = 'Opportunities' AND `parent_id` IS NULL);

SET @opportunities_group_id = (SELECT id FROM `nav_items` WHERE `label` = 'Opportunities' AND `parent_id` IS NULL LIMIT 1);

INSERT INTO `nav_items` (`parent_id`, `label`, `icon`, `link`, `capability`, `visible`, `sort_order`)
SELECT @opportunities_group_id, 'Discover', 'fa-solid fa-compass', 'opportunities', 'view_opportunities', 1, 10
WHERE NOT EXISTS (SELECT 1 FROM `nav_items` WHERE `link` = 'opportunities' AND `parent_id` = @opportunities_group_id);

INSERT INTO `nav_items` (`parent_id`, `label`, `icon`, `link`, `capability`, `visible`, `sort_order`)
SELECT @opportunities_group_id, 'Conferences', 'fa-solid fa-calendar-days', 'opportunities-conferences', 'view_opportunities', 1, 20
WHERE NOT EXISTS (SELECT 1 FROM `nav_items` WHERE `link` = 'opportunities-conferences');

INSERT INTO `nav_items` (`parent_id`, `label`, `icon`, `link`, `capability`, `visible`, `sort_order`)
SELECT @opportunities_group_id, 'Companies', 'fa-solid fa-building', 'opportunities-companies', 'view_opportunities', 1, 30
WHERE NOT EXISTS (SELECT 1 FROM `nav_items` WHERE `link` = 'opportunities-companies');

INSERT INTO `nav_items` (`parent_id`, `label`, `icon`, `link`, `capability`, `visible`, `sort_order`)
SELECT @opportunities_group_id, 'Pipeline', 'fa-solid fa-diagram-project', 'opportunities-pipeline', 'view_opportunities', 1, 40
WHERE NOT EXISTS (SELECT 1 FROM `nav_items` WHERE `link` = 'opportunities-pipeline');

INSERT INTO `nav_items` (`parent_id`, `label`, `icon`, `link`, `capability`, `visible`, `sort_order`)
SELECT @opportunities_group_id, 'Notes', 'fa-solid fa-note-sticky', 'opportunities-notes', 'view_opportunities', 1, 50
WHERE NOT EXISTS (SELECT 1 FROM `nav_items` WHERE `link` = 'opportunities-notes');
