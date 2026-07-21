-- Automation / process-graph engine — structured task form data.
--
-- Phase 2/3 shipped human tasks with only an `outcome` (a button click).
-- The graph document can now also declare `config.formFields` on a
-- human.* node (see public/assets/processes/process-inspector.js's Human
-- Task group) — an actual data-collection schema, not just a decision.
-- This column is where whatever a person fills in when completing that
-- task is stored, keyed by field id. See src/Processes/Runtime/Engine.php's
-- completeTask() — submitted values are also merged into the instance's
-- variables_json (same "becomes a real process variable" treatment
-- config.setVariables already gets), so a later node in the same process
-- can reference something a person typed in.
ALTER TABLE `process_tasks`
  ADD COLUMN IF NOT EXISTS `form_data_json` longtext DEFAULT NULL COMMENT 'submitted config.formFields values, keyed by field id' AFTER `outcome`;
