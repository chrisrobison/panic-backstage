-- 003_template_staffing.sql
-- Adds staffing_json to event_templates so templates can define
-- per-role headcount presets that auto-populate event staffing on creation.
--
-- Format: [{"role":"bartender","count":2},{"role":"security","count":2,"notes":"Front door"}]

ALTER TABLE event_templates
  ADD COLUMN staffing_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
  CHECK (json_valid(staffing_json));
