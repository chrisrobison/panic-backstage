-- Automation / process-graph engine (Phase 1: the graph editor + its
-- persistence). See docs discussion "workflow-ui" — visual business-process
-- designer where the diagram itself is the executable definition.
--
-- Design notes:
--   * The graph (nodes/edges/viewport/etc.) is stored as versioned JSON in
--     process_versions.graph_json rather than normalized node/edge tables —
--     the graph document is the unit of authorship, review, and rollback,
--     and normalizing it would just require reassembling it on every read
--     for no query benefit (nothing here needs to SQL-filter by node type).
--   * process_versions rows are immutable once status='published'. Editing a
--     published process inserts a new draft version; process_instances keep
--     pointing at the version they started on unless explicitly migrated
--     (process_definitions.current_published_version_id is what NEW
--     instances bind to).
--   * process_instances/process_audit_log are intentionally minimal here —
--     just enough to drive the Live Cases / History tabs with real rows.
--     The execution runtime (process_executions, process_tasks,
--     process_waits, resumable state machine) is Phase 2 and deliberately
--     not built yet; process_instances.is_demo=1 marks the seeded example
--     cases so they're never mistaken for real runtime output.

CREATE TABLE IF NOT EXISTS `process_definitions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `key_slug` varchar(80) NOT NULL COMMENT 'stable machine key, e.g. event-booking',
  `name` varchar(190) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(60) DEFAULT NULL COMMENT 'e.g. booking, onboarding, support — free-form grouping',
  `current_published_version_id` int(11) DEFAULT NULL COMMENT 'the version new instances bind to; null if never published',
  `archived` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_process_definitions_key` (`key_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `process_versions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `process_definition_id` int(11) NOT NULL,
  `version_number` int(11) NOT NULL,
  `status` enum('draft','published','archived') NOT NULL DEFAULT 'draft',
  `graph_json` longtext NOT NULL COMMENT 'the full graph document — schemaVersion, nodes, edges, viewport, variables, permissions, runtimePolicy',
  `validation_json` longtext DEFAULT NULL COMMENT 'result of the last validation run (errors/warnings), cached for the History tab',
  `note` varchar(500) DEFAULT NULL COMMENT 'change summary, shown in version history',
  `published_at` timestamp NULL DEFAULT NULL,
  `published_by` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_process_versions_def_num` (`process_definition_id`, `version_number`),
  KEY `idx_process_versions_status` (`process_definition_id`, `status`),
  CONSTRAINT `process_versions_definition_fk` FOREIGN KEY (`process_definition_id`) REFERENCES `process_definitions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `process_instances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `process_definition_id` int(11) NOT NULL,
  `process_version_id` int(11) NOT NULL COMMENT 'version this instance started on — never migrated implicitly',
  `name` varchar(190) NOT NULL COMMENT 'case label, e.g. "Acme Holiday Party"',
  `status` enum('active','waiting','overdue','failed','completed','canceled') NOT NULL DEFAULT 'active',
  `current_node_id` varchar(80) DEFAULT NULL COMMENT 'node id (from graph_json) the instance is currently sitting at',
  `entity_type` varchar(40) DEFAULT NULL COMMENT 'event, inquiry, contact, etc. — what real record this case is about',
  `entity_id` int(11) DEFAULT NULL,
  `owner_user_id` int(11) DEFAULT NULL,
  `due_at` timestamp NULL DEFAULT NULL,
  `is_demo` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'seeded demonstration case, not produced by a real runtime — see Phase 2 note above',
  `variables_json` longtext DEFAULT NULL COMMENT 'instance business data (form answers, computed values)',
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_process_instances_def` (`process_definition_id`, `status`),
  KEY `idx_process_instances_node` (`process_definition_id`, `current_node_id`),
  CONSTRAINT `process_instances_definition_fk` FOREIGN KEY (`process_definition_id`) REFERENCES `process_definitions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `process_instances_version_fk` FOREIGN KEY (`process_version_id`) REFERENCES `process_versions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `process_instance_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `process_instance_id` int(11) NOT NULL,
  `node_id` varchar(80) DEFAULT NULL,
  `event_type` varchar(40) NOT NULL COMMENT 'entered, completed, waiting, failed, note, etc. — demo timeline entries for Phase 1',
  `label` varchar(255) NOT NULL,
  `detail` text DEFAULT NULL,
  `actor` varchar(120) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_process_instance_events_instance` (`process_instance_id`, `created_at`),
  CONSTRAINT `process_instance_events_instance_fk` FOREIGN KEY (`process_instance_id`) REFERENCES `process_instances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `process_audit_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `process_definition_id` int(11) NOT NULL,
  `process_version_id` int(11) DEFAULT NULL,
  `actor_user_id` int(11) DEFAULT NULL,
  `action` varchar(60) NOT NULL COMMENT 'draft_created, draft_saved, published, restored, instance_retried, instance_canceled, ...',
  `before_json` longtext DEFAULT NULL,
  `after_json` longtext DEFAULT NULL,
  `note` varchar(500) DEFAULT NULL COMMENT 'required reason for manual/operational actions',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_process_audit_log_def` (`process_definition_id`, `created_at`),
  CONSTRAINT `process_audit_log_definition_fk` FOREIGN KEY (`process_definition_id`) REFERENCES `process_definitions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- current_published_version_id deliberately has no FK constraint: it would
-- be circular with process_versions.process_definition_id, and MySQL/MariaDB
-- DDL has no "ADD CONSTRAINT IF NOT EXISTS" — a re-run after a partial
-- migration failure would abort on a duplicate constraint name. Enforced at
-- the application layer (Processes/Versions.php) instead.

-- ── Navigation: new top-level "Automation" group ────────────────────────────
-- Seeded once, idempotently (nav_items has no unique key on link, so guard
-- with a NOT EXISTS check per row rather than relying on a constraint).
INSERT INTO `nav_items` (`parent_id`, `label`, `icon`, `link`, `capability`, `visible`, `sort_order`)
SELECT NULL, 'Automation', 'fa-solid fa-diagram-project', NULL, 'view_processes', 1,
       (SELECT COALESCE(MAX(sort_order), 0) + 10 FROM (SELECT sort_order FROM nav_items WHERE parent_id IS NULL) x)
WHERE NOT EXISTS (SELECT 1 FROM `nav_items` WHERE `label` = 'Automation' AND `parent_id` IS NULL);

SET @automation_group_id = (SELECT id FROM `nav_items` WHERE `label` = 'Automation' AND `parent_id` IS NULL LIMIT 1);

INSERT INTO `nav_items` (`parent_id`, `label`, `icon`, `link`, `capability`, `visible`, `sort_order`)
SELECT @automation_group_id, 'Processes', 'fa-solid fa-diagram-project', 'automation-processes', 'view_processes', 1, 10
WHERE NOT EXISTS (SELECT 1 FROM `nav_items` WHERE `link` = 'automation-processes');

INSERT INTO `nav_items` (`parent_id`, `label`, `icon`, `link`, `capability`, `visible`, `sort_order`)
SELECT @automation_group_id, 'Cases', 'fa-solid fa-folder-open', 'automation-cases', 'view_processes', 1, 20
WHERE NOT EXISTS (SELECT 1 FROM `nav_items` WHERE `link` = 'automation-cases');

INSERT INTO `nav_items` (`parent_id`, `label`, `icon`, `link`, `capability`, `visible`, `sort_order`)
SELECT @automation_group_id, 'Tasks', 'fa-solid fa-list-check', 'automation-tasks', 'view_processes', 1, 30
WHERE NOT EXISTS (SELECT 1 FROM `nav_items` WHERE `link` = 'automation-tasks');

INSERT INTO `nav_items` (`parent_id`, `label`, `icon`, `link`, `capability`, `visible`, `sort_order`)
SELECT @automation_group_id, 'Activity', 'fa-solid fa-clock-rotate-left', 'automation-activity', 'view_processes', 1, 40
WHERE NOT EXISTS (SELECT 1 FROM `nav_items` WHERE `link` = 'automation-activity');

INSERT INTO `nav_items` (`parent_id`, `label`, `icon`, `link`, `capability`, `visible`, `sort_order`)
SELECT @automation_group_id, 'Connections', 'fa-solid fa-plug', 'automation-connections', 'view_processes', 1, 50
WHERE NOT EXISTS (SELECT 1 FROM `nav_items` WHERE `link` = 'automation-connections');
