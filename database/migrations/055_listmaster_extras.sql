-- 055_listmaster_extras.sql
--
-- Backing store for the new "ListMaster"-style list-management UI
-- (public/assets/listmaster.js), layered on top of the existing
-- contacts / mailing_lists / list_membership tables (see src/MailingLists.php,
-- src/Contacts.php). Adds the pieces that UI needs and this app genuinely
-- didn't have yet:
--
--   contact_tags / contact_tag_assignments  free-form contact tagging
--   contact_activity                        per-contact audit trail (list
--                                            joins/leaves, status changes,
--                                            tag changes, CSV imports, edits)
--   list_import_history / list_export_history  logs of CSV import/export runs
--   contact_storage_settings                the single editable "N of LIMIT
--                                            contacts" cap shown by the
--                                            storage meter
--   list_membership.status + 'bounced'      lets a member be manually marked
--                                            bounced (this app has no email
--                                            provider bounce-webhook
--                                            integration to set it
--                                            automatically — see MailingLists.php)

CREATE TABLE IF NOT EXISTS contact_tags (
  id         INT NOT NULL AUTO_INCREMENT,
  name       VARCHAR(60) NOT NULL,
  color      VARCHAR(20) NOT NULL DEFAULT '#2563eb',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_contact_tag_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_tag_assignments (
  contact_id BIGINT NOT NULL,
  tag_id     INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (contact_id, tag_id),
  KEY tag_id (tag_id),
  CONSTRAINT contact_tag_assignments_ibfk_1 FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE CASCADE,
  CONSTRAINT contact_tag_assignments_ibfk_2 FOREIGN KEY (tag_id) REFERENCES contact_tags (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Modeled on event_activity_log (see ../schema.sql) — same action/details_json shape.
CREATE TABLE IF NOT EXISTS contact_activity (
  id           INT NOT NULL AUTO_INCREMENT,
  contact_id   BIGINT NOT NULL,
  user_id      INT DEFAULT NULL,
  type         VARCHAR(60) NOT NULL,
  message      VARCHAR(500) NOT NULL,
  details_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(details_json)),
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY contact_id (contact_id),
  KEY user_id (user_id),
  CONSTRAINT contact_activity_ibfk_1 FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE CASCADE,
  CONSTRAINT contact_activity_ibfk_2 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS list_import_history (
  id                 INT NOT NULL AUTO_INCREMENT,
  list_id            INT NOT NULL,
  filename           VARCHAR(255) DEFAULT NULL,
  created_count      INT NOT NULL DEFAULT 0,
  updated_count      INT NOT NULL DEFAULT 0,
  added_to_list      INT NOT NULL DEFAULT 0,
  skipped_count      INT NOT NULL DEFAULT 0,
  errors_json        LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (errors_json IS NULL OR json_valid(errors_json)),
  imported_by_user_id INT DEFAULT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY list_id (list_id),
  KEY imported_by_user_id (imported_by_user_id),
  CONSTRAINT list_import_history_ibfk_1 FOREIGN KEY (list_id) REFERENCES mailing_lists (id) ON DELETE CASCADE,
  CONSTRAINT list_import_history_ibfk_2 FOREIGN KEY (imported_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- list_id is nullable: NULL means "exported across all lists" (the top-level
-- Export Lists button with no single list selected). ON DELETE SET NULL (not
-- CASCADE) so a list's export history survives the list being deleted later.
CREATE TABLE IF NOT EXISTS list_export_history (
  id                 INT NOT NULL AUTO_INCREMENT,
  list_id            INT DEFAULT NULL,
  list_name_snapshot VARCHAR(160) DEFAULT NULL,
  format             VARCHAR(10) NOT NULL DEFAULT 'csv',
  row_count          INT NOT NULL DEFAULT 0,
  filters_json       LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (filters_json IS NULL OR json_valid(filters_json)),
  exported_by_user_id INT DEFAULT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY list_id (list_id),
  KEY exported_by_user_id (exported_by_user_id),
  CONSTRAINT list_export_history_ibfk_1 FOREIGN KEY (list_id) REFERENCES mailing_lists (id) ON DELETE SET NULL,
  CONSTRAINT list_export_history_ibfk_2 FOREIGN KEY (exported_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Singleton settings row (id is always 1) backing the "List storage: N of
-- LIMIT contacts" meter. No billing/plan system exists in this app, so this
-- is a plain admin-editable cap rather than a real subscription tier.
CREATE TABLE IF NOT EXISTS contact_storage_settings (
  id             TINYINT NOT NULL DEFAULT 1,
  contact_limit  INT NOT NULL DEFAULT 250000,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT chk_contact_storage_singleton CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO contact_storage_settings (id, contact_limit) VALUES (1, 250000);

-- Adds 'bounced' as a status a member can be manually marked with (see
-- MailingLists::updateMember()/bulkUpdateMembers() — this app has no email
-- provider bounce-webhook feed, so nothing sets this automatically yet, but
-- an admin who learns an address hard-bounced can now record it instead of
-- just unsubscribing them). MODIFY COLUMN is naturally idempotent.
ALTER TABLE list_membership
  MODIFY COLUMN status ENUM('subscribed','unsubscribed','bounced') NOT NULL DEFAULT 'subscribed';
