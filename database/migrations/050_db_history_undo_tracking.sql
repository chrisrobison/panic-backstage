-- 050_db_history_undo_tracking.sql
--
-- db_history itself was created out-of-band by scripts/generate-audit-triggers.php
-- (2026-07-07, the audit-trigger feature) rather than through a migration, so this
-- file both makes sure it exists for a fresh database/tenant AND adds the columns
-- needed for the undo/redo admin UI: tracking whether an entry has been undone,
-- by whom, and which later entry (the reverse write) resulted from undoing it.
--
-- Redo is deliberately not a separate mechanism: undoing an UPDATE/DELETE/INSERT
-- is itself a real write, so it fires the same triggers and lands its own
-- db_history row. "Redo" is just running undo again on *that* row. undo_of_id
-- links the two so the UI can show "this undid entry #123" / "undone by ->  #456".

CREATE TABLE IF NOT EXISTS db_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  table_name  VARCHAR(64) NOT NULL,
  pk_column   VARCHAR(64) NOT NULL,
  pk_value    VARCHAR(255) NOT NULL,
  action      ENUM('INSERT','UPDATE','DELETE') NOT NULL,
  actor       VARCHAR(128) NULL,
  old_row     JSON NULL,
  new_row     JSON NULL,
  undo_sql    MEDIUMTEXT NOT NULL,
  created_at  TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_table_pk (table_name, pk_value),
  KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE db_history
  ADD COLUMN IF NOT EXISTS undone_at TIMESTAMP(6) NULL DEFAULT NULL AFTER undo_sql,
  ADD COLUMN IF NOT EXISTS undone_by_actor VARCHAR(128) NULL DEFAULT NULL AFTER undone_at,
  ADD COLUMN IF NOT EXISTS undo_of_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER undone_by_actor;

ALTER TABLE db_history
  ADD KEY IF NOT EXISTS idx_undo_of (undo_of_id);
