-- Promote five MabEvents sheet columns from "stuffed into description_internal"
-- (or silently discarded) into proper structured columns:
--
--   sheet col 6  Potential Revenue   → events.potential_revenue
--   sheet col 15 Ticket Sys.         → events.ticket_system
--   sheet col 16 Contract Link       → events.contract_url
--   sheet col 17 Walk Through?       → events.walkthrough_done
--   sheet col 19 Settlement Document → events.settlement_doc_url
--
-- Sheet col 18 "Ticket Link" was also being discarded by the importer; it now
-- populates the pre-existing events.ticket_url, so no schema change needed.
--
-- Safe to re-run: each ADD COLUMN is guarded by INFORMATION_SCHEMA.

USE panic_backstage;

DROP PROCEDURE IF EXISTS _add_event_col;
DELIMITER //
CREATE PROCEDURE _add_event_col(IN col_name VARCHAR(64), IN ddl TEXT)
BEGIN
  IF (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND COLUMN_NAME = col_name) = 0
  THEN
    SET @sql = CONCAT('ALTER TABLE events ADD COLUMN ', ddl);
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END//
DELIMITER ;

CALL _add_event_col('potential_revenue',  'potential_revenue DECIMAL(10,2) NULL DEFAULT NULL AFTER deposit_amount');
CALL _add_event_col('ticket_system',      'ticket_system VARCHAR(40) NULL DEFAULT NULL AFTER ticket_url');
CALL _add_event_col('contract_url',       'contract_url VARCHAR(500) NULL DEFAULT NULL AFTER ticket_system');
CALL _add_event_col('walkthrough_done',   'walkthrough_done TINYINT(1) NOT NULL DEFAULT 0 AFTER contract_url');
CALL _add_event_col('settlement_doc_url', 'settlement_doc_url VARCHAR(500) NULL DEFAULT NULL AFTER walkthrough_done');

DROP PROCEDURE _add_event_col;
