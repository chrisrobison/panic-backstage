-- Staff Handbook & Compliance: per-tenant DB-authored content.
--
-- Until now every staff_documents row was sourced from a Markdown file in
-- the shared codebase (docs/staff/**), scanned by StaffDocs::syncFromDisk()/
-- publishFromFile() -- fine for the single Mabuhay install, but wrong for
-- SaaS: every tenant's own database would otherwise only ever be able to
-- publish the same shared Mabuhay-authored text, with no way for a venue
-- admin to customize their own handbook/SOPs without git access to the
-- shared repo.
--
-- This adds a second, DB-native authoring path alongside the existing
-- file-based one: `draft_markdown` holds an in-progress edit (frontmatter +
-- body, same shape as a seed file) that StaffDocs::publishFromDraft() can
-- publish exactly like publishFromFile() does for a file on disk -- same
-- version-bump/hash rules, same staff_document_versions/acknowledgment
-- machinery, which is already tenant-scoped by virtue of SaaS mode's
-- per-tenant database. `source` records which path a given document uses;
-- `file_path` becomes nullable because a purely DB-authored document (one a
-- tenant created themselves, with no seed-template equivalent) has none.

ALTER TABLE `staff_documents`
  MODIFY COLUMN `file_path` varchar(255) DEFAULT NULL COMMENT 'Repo-relative path to the canonical Markdown seed source, e.g. docs/staff/handbook.md -- NULL for a document authored entirely in the DB (source = db)',
  ADD COLUMN `source` enum('file','db') NOT NULL DEFAULT 'file' COMMENT 'file: seeded/republished from docs/staff/** on disk. db: authored via the draft/publish-draft API, independent of any file.' AFTER `file_path`,
  ADD COLUMN `draft_markdown` longtext DEFAULT NULL COMMENT 'In-progress edit (frontmatter + body), saved via PUT /api/staff-docs/{slug}/draft and published via POST .../publish-draft; independent of the frozen staff_document_versions history.' AFTER `requires_acknowledgment`,
  ADD COLUMN `draft_updated_at` datetime DEFAULT NULL AFTER `draft_markdown`,
  ADD COLUMN `draft_updated_by` int(11) DEFAULT NULL AFTER `draft_updated_at`,
  ADD KEY `idx_staff_documents_draft_updated_by` (`draft_updated_by`),
  ADD CONSTRAINT `staff_documents_draft_updated_by_fk` FOREIGN KEY (`draft_updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
