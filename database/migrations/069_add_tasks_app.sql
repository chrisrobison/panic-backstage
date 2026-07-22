-- Standalone "Tasks" app — a top-level, ClickUp/Asana-style task manager
-- independent of events. Separate from (and does not touch) the existing
-- per-event checklist (`event_tasks` / src/Events/Tasks.php / TaskList in
-- event-panels.js), which stays exactly as-is for event-scoped checklists.
--
-- Design notes:
--   * A "task document" (task_documents) is one project/list, shown in the
--     app's left sidebar — e.g. "Q3 Marketing Campaign".
--   * Tasks nest via parent_task_id (self-referential, cascade delete —
--     deleting a parent drops its subtasks). WBS numbering (1.1.4) is
--     computed client-side from tree position, not stored.
--   * checklist_json / tags_json / depends_on_json are JSON blob columns on
--     the task row rather than normalized join tables — same convention as
--     event_templates.checklist_json. depends_on_json is a small JSON array
--     of task ids, resolved client-side against the document's own
--     already-loaded task list (no join table needed).
--   * task_comments + task_activity mirror the contact_activity /
--     event_activity_log shape used elsewhere for a per-entity audit trail.

CREATE TABLE IF NOT EXISTS `task_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `icon` varchar(60) NOT NULL DEFAULT 'fa-solid fa-list-check',
  `color` varchar(20) NOT NULL DEFAULT '#2563eb',
  `status` enum('on_track','at_risk','off_track','complete') NOT NULL DEFAULT 'on_track',
  `starred` tinyint(1) NOT NULL DEFAULT 0,
  `owner_user_id` int(11) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `archived_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_task_documents_owner` (`owner_user_id`),
  CONSTRAINT `task_documents_owner_fk` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `document_id` int(11) NOT NULL,
  `parent_task_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('not_started','in_progress','done') NOT NULL DEFAULT 'not_started',
  `priority` enum('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `assignee_user_id` int(11) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `tags_json` text DEFAULT NULL,
  `checklist_json` text DEFAULT NULL,
  `depends_on_json` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tasks_document` (`document_id`, `parent_task_id`),
  KEY `idx_tasks_parent` (`parent_task_id`),
  KEY `idx_tasks_assignee` (`assignee_user_id`),
  CONSTRAINT `tasks_document_fk` FOREIGN KEY (`document_id`) REFERENCES `task_documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tasks_parent_fk` FOREIGN KEY (`parent_task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tasks_assignee_fk` FOREIGN KEY (`assignee_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `task_comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `body` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_task_comments_task` (`task_id`, `created_at`),
  CONSTRAINT `task_comments_task_fk` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `task_activity` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(60) NOT NULL,
  `details_json` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_task_activity_task` (`task_id`, `created_at`),
  CONSTRAINT `task_activity_task_fk` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Navigation: new top-level "Tasks" item ──────────────────────────────────
-- Seeded once, idempotently (nav_items has no unique key on link, so guard
-- with a NOT EXISTS check, same as the Automation group in migration 066).
INSERT INTO `nav_items` (`parent_id`, `label`, `icon`, `link`, `capability`, `visible`, `sort_order`)
SELECT NULL, 'Tasks', 'fa-solid fa-list-check', 'tasks', 'view_tasks_app', 1,
       (SELECT COALESCE(MAX(sort_order), 0) + 10 FROM (SELECT sort_order FROM nav_items WHERE parent_id IS NULL) x)
WHERE NOT EXISTS (SELECT 1 FROM `nav_items` WHERE `link` = 'tasks' AND `parent_id` IS NULL);
