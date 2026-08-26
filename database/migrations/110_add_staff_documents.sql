-- Staff Handbook & Compliance: documents, versions, acknowledgments, assignments.
--
-- Markdown files under docs/staff/ are the canonical source of prose (see
-- docs/staff/README.md). These tables hold operational metadata: which
-- documents exist, what the currently-published version is, a durable
-- frozen record of every published version's exact text, and who has
-- acknowledged what. Acknowledgments always point at an immutable
-- staff_document_versions row (frozen rendered_html + source_markdown),
-- never at the live, editable file on disk — so "what did this person
-- actually agree to" can never drift out from under a historical record.
--
-- Multi-tenant note: this app's SaaS mode isolates tenants by giving each
-- one its own database (see README "Multi-Tenant / SaaS Mode"), not by a
-- shared-database tenant_id column — no other table in this schema carries
-- one, and these follow the same convention.

CREATE TABLE IF NOT EXISTS `staff_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slug` varchar(80) NOT NULL,
  `title` varchar(255) NOT NULL,
  `document_type` enum('handbook','policy','sop') NOT NULL DEFAULT 'policy',
  `current_version` varchar(20) DEFAULT NULL COMMENT 'Denormalized copy of the current published staff_document_versions.version, for quick display',
  `current_version_id` int(11) DEFAULT NULL COMMENT 'Points at the currently-published row in staff_document_versions (no FK constraint: that table FKs back to this one, and MySQL cannot forward-reference a not-yet-created table in the same statement)',
  `file_path` varchar(255) NOT NULL COMMENT 'Repo-relative path to the canonical Markdown source, e.g. docs/staff/handbook.md',
  `status` enum('draft','review','published','retired') NOT NULL DEFAULT 'draft',
  `requires_acknowledgment` tinyint(1) NOT NULL DEFAULT 0,
  `published_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_documents_slug` (`slug`),
  KEY `idx_staff_documents_status` (`status`),
  KEY `idx_staff_documents_type` (`document_type`),
  KEY `idx_staff_documents_current_version_id` (`current_version_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `staff_document_versions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `document_id` int(11) NOT NULL,
  `version` varchar(20) NOT NULL,
  `content_hash` char(64) NOT NULL COMMENT 'sha256 of the frozen source_markdown, for tamper/drift detection',
  `frontmatter_json` text DEFAULT NULL COMMENT 'Parsed Markdown frontmatter at publish time, JSON-encoded',
  `source_markdown` longtext NOT NULL COMMENT 'Frozen copy of the exact Markdown text as of publish -- the live file on disk may move on without this changing',
  `rendered_html` longtext NOT NULL COMMENT 'Frozen rendered HTML of the above, so historical acknowledgments never depend on a live re-render matching',
  `effective_date` date DEFAULT NULL,
  `published_at` datetime NOT NULL DEFAULT current_timestamp(),
  `published_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_document_versions_doc_version` (`document_id`, `version`),
  KEY `idx_staff_document_versions_published_by` (`published_by`),
  CONSTRAINT `staff_document_versions_ibfk_1` FOREIGN KEY (`document_id`) REFERENCES `staff_documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `staff_document_versions_ibfk_2` FOREIGN KEY (`published_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `staff_document_acknowledgments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `document_version_id` int(11) NOT NULL,
  `version` varchar(20) NOT NULL COMMENT 'Denormalized from staff_document_versions.version so history reads without a join even if a version row is ever pruned',
  `acknowledged_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ip_address` varchar(64) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_document_ack_user_version` (`user_id`, `document_version_id`) COMMENT 'One acknowledgment per person per version -- re-acknowledging the same version is a no-op, not a new history row',
  KEY `idx_staff_document_ack_document_id` (`document_id`),
  KEY `idx_staff_document_ack_version_id` (`document_version_id`),
  CONSTRAINT `staff_document_acknowledgments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `staff_document_acknowledgments_ibfk_2` FOREIGN KEY (`document_id`) REFERENCES `staff_documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `staff_document_acknowledgments_ibfk_3` FOREIGN KEY (`document_version_id`) REFERENCES `staff_document_versions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `staff_document_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `document_id` int(11) NOT NULL,
  `role_key` varchar(40) NOT NULL COMMENT 'staff_members.default_role value this document applies to, or the sentinel "all_staff" for every active staff member',
  `required` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_document_assignments_doc_role` (`document_id`, `role_key`),
  CONSTRAINT `staff_document_assignments_ibfk_1` FOREIGN KEY (`document_id`) REFERENCES `staff_documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
