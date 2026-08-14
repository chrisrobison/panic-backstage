-- Lets one contract cover an entire recurring series instead of only the
-- single event it was generated from. When set, this contract is treated as
-- "on file" for every occurrence in the series (see Events::hasExecutedContract()
-- and Events\Contracts::index()), not just the founding event named by
-- event_id — which is left as-is (the contract still belongs to whichever
-- event it was created from; series_id is an additional "also covers this
-- whole run" marker, not a replacement for event_id).

ALTER TABLE `contracts`
  ADD COLUMN IF NOT EXISTS `series_id` int(11) DEFAULT NULL AFTER `event_id`;

ALTER TABLE `contracts`
  ADD KEY IF NOT EXISTS `idx_contracts_series` (`series_id`);

-- Guard the FK add for re-runs — MySQL has no `ADD CONSTRAINT IF NOT EXISTS`,
-- so check information_schema first (same pattern as other migrations that
-- add a named FK).
SET @contracts_series_fk_exists := (
  SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA = DATABASE()
     AND TABLE_NAME = 'contracts'
     AND CONSTRAINT_NAME = 'contracts_series_fk'
);
SET @contracts_series_fk_sql := IF(
  @contracts_series_fk_exists = 0,
  'ALTER TABLE `contracts` ADD CONSTRAINT `contracts_series_fk` FOREIGN KEY (`series_id`) REFERENCES `event_series` (`id`) ON DELETE SET NULL',
  'DO 0'
);
PREPARE contracts_series_fk_stmt FROM @contracts_series_fk_sql;
EXECUTE contracts_series_fk_stmt;
DEALLOCATE PREPARE contracts_series_fk_stmt;
