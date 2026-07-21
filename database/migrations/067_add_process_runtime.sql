-- Automation / process-graph engine (Phase 2: the executable runtime).
-- Phase 1 (066_add_process_automation.sql) shipped the designer + a read
-- model over hand-seeded demonstration instances. This adds the tables and
-- columns a real, persistent, resumable state machine needs to actually run
-- a process_instances row through a graph: automatic-node execution history,
-- human tasks, and event/timer waits.
--
-- Design notes (see src/Processes/Runtime/Engine.php for the state machine
-- itself):
--   * process_executions is an append-only log — one row per node the
--     runtime actually ran (not per node in the graph), so a node visited
--     twice via a loop-back edge (e.g. "Check Availability" after
--     "Alternative Accepted? -> Yes") gets two rows, `attempt` counting
--     retries of the SAME visit rather than re-visits.
--   * process_tasks/process_waits are the two ways an instance legitimately
--     stops advancing: human work, or an external event/timeout. Resuming
--     either is a `WHERE status = 'open'/'waiting'` conditional UPDATE
--     (rowCount()-checked in PHP) so a duplicate webhook delivery or a
--     double-submit can never re-run the downstream nodes twice.
--   * process_instances gets a few additive columns for transactional
--     claiming (locked_by/locked_at — advance() holds a row lock for the
--     duration of one auto-run burst) and pause/resume (paused instances
--     remember what status to return to).
--   * 'paused' is added to the status enum. MODIFY COLUMN is safe to re-run.

ALTER TABLE `process_instances` MODIFY COLUMN `status`
  enum('active','waiting','overdue','failed','completed','canceled','paused') NOT NULL DEFAULT 'active';

ALTER TABLE `process_instances`
  ADD COLUMN IF NOT EXISTS `locked_by` varchar(120) DEFAULT NULL COMMENT 'set for the duration of one advance() burst; guards against overlapping ticks/requests',
  ADD COLUMN IF NOT EXISTS `locked_at` timestamp NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `resume_status` varchar(20) DEFAULT NULL COMMENT 'status to restore to on unpause',
  ADD COLUMN IF NOT EXISTS `last_error` text DEFAULT NULL COMMENT 'most recent execution failure message, cleared on successful retry';

CREATE TABLE IF NOT EXISTS `process_executions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `process_instance_id` int(11) NOT NULL,
  `node_id` varchar(80) NOT NULL,
  `node_type` varchar(60) NOT NULL,
  `attempt` int(11) NOT NULL DEFAULT 1,
  `status` enum('succeeded','failed','skipped') NOT NULL,
  `simulated` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = ran through the Phase 2 generic simulated-operation handler (no real CenterStage side effect performed — see Runtime/Engine.php); 0 = a real flow/decision/human/wait transition',
  `input_json` longtext DEFAULT NULL,
  `output_json` longtext DEFAULT NULL,
  `error_text` text DEFAULT NULL,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `finished_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_process_executions_instance` (`process_instance_id`, `started_at`),
  CONSTRAINT `process_executions_instance_fk` FOREIGN KEY (`process_instance_id`) REFERENCES `process_instances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `process_tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `process_instance_id` int(11) NOT NULL,
  `node_id` varchar(80) NOT NULL,
  `title` varchar(190) NOT NULL,
  `description` text DEFAULT NULL,
  `assignee_user_id` int(11) DEFAULT NULL,
  `assignee_role` varchar(120) DEFAULT NULL,
  `status` enum('open','completed','canceled') NOT NULL DEFAULT 'open',
  `outcome` varchar(60) DEFAULT NULL,
  `due_at` timestamp NULL DEFAULT NULL,
  `escalated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  `completed_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_process_tasks_instance` (`process_instance_id`),
  KEY `idx_process_tasks_open` (`status`, `due_at`),
  KEY `idx_process_tasks_assignee` (`assignee_user_id`, `status`),
  CONSTRAINT `process_tasks_instance_fk` FOREIGN KEY (`process_instance_id`) REFERENCES `process_instances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `process_waits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `process_instance_id` int(11) NOT NULL,
  `node_id` varchar(80) NOT NULL,
  `awaited_event` varchar(120) DEFAULT NULL COMMENT 'null for a pure timer/delay node',
  `correlation_key` varchar(190) DEFAULT NULL,
  `timeout_at` timestamp NULL DEFAULT NULL,
  `status` enum('waiting','resumed','timed_out','canceled') NOT NULL DEFAULT 'waiting',
  `resumed_via` varchar(20) DEFAULT NULL COMMENT 'event | timeout | manual',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `resumed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_process_waits_instance` (`process_instance_id`),
  KEY `idx_process_waits_pending` (`status`, `timeout_at`),
  CONSTRAINT `process_waits_instance_fk` FOREIGN KEY (`process_instance_id`) REFERENCES `process_instances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
