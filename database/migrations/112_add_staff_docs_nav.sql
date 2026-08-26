-- Staff Handbook & Compliance: sidebar nav entries.
-- "Staff Docs" is visible to every authenticated user (no capability gate
-- -- everyone needs to read/acknowledge what applies to their role).
-- "Compliance" is admin-only (manage_staff_docs).

INSERT INTO `nav_items` (`parent_id`, `label`, `icon`, `link`, `capability`, `visible`, `sort_order`)
SELECT NULL, 'Staff Docs', 'fa-solid fa-book-open', NULL, NULL, 1,
       (SELECT COALESCE(MAX(sort_order), 0) + 10 FROM (SELECT sort_order FROM nav_items WHERE parent_id IS NULL) x)
WHERE NOT EXISTS (SELECT 1 FROM `nav_items` WHERE `label` = 'Staff Docs' AND `parent_id` IS NULL);

INSERT INTO `nav_items` (`parent_id`, `label`, `icon`, `link`, `capability`, `visible`, `sort_order`)
SELECT id, 'Handbook & SOPs', 'fa-solid fa-book-open', 'staff-docs', NULL, 1, 10
FROM `nav_items` WHERE `label` = 'Staff Docs' AND `parent_id` IS NULL
  AND NOT EXISTS (
    SELECT 1 FROM `nav_items` c
    WHERE c.parent_id = `nav_items`.id AND c.label = 'Handbook & SOPs'
  );

INSERT INTO `nav_items` (`parent_id`, `label`, `icon`, `link`, `capability`, `visible`, `sort_order`)
SELECT id, 'Compliance Overview', 'fa-solid fa-clipboard-check', 'staff-compliance', 'manage_staff_docs', 1, 20
FROM `nav_items` WHERE `label` = 'Staff Docs' AND `parent_id` IS NULL
  AND NOT EXISTS (
    SELECT 1 FROM `nav_items` c
    WHERE c.parent_id = `nav_items`.id AND c.label = 'Compliance Overview'
  );
