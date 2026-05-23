-- Promote two Staff Contact sheet columns from "discarded" into proper
-- structured columns:
--
--   sheet col 4 Pronoun  → staff_members.pronoun
--   sheet col 8 Position → staff_members.position
--
-- Sheet col 5 "Staffing Status" maps to the pre-existing staff_members.active
-- column; sheet col 9 "Staffing Notes" maps to the pre-existing notes column.
--
-- Safe to re-run.

USE panic_backstage;

DROP PROCEDURE IF EXISTS _add_staff_col;
DELIMITER //
CREATE PROCEDURE _add_staff_col(IN col_name VARCHAR(64), IN ddl TEXT)
BEGIN
  IF (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_members' AND COLUMN_NAME = col_name) = 0
  THEN
    SET @sql = CONCAT('ALTER TABLE staff_members ADD COLUMN ', ddl);
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END//
DELIMITER ;

CALL _add_staff_col('pronoun',  'pronoun VARCHAR(40) NULL DEFAULT NULL AFTER phone');
CALL _add_staff_col('position', 'position VARCHAR(120) NULL DEFAULT NULL AFTER default_role');

DROP PROCEDURE _add_staff_col;
