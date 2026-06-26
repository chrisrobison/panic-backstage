-- Migration 028 (tenant): per-user dashboard metric selection.
--
-- Stores the ordered set of metric-card keys a user wants shown at the top of
-- the dashboard, as a JSON array (e.g. ["newLeads","nextShow","openItems"]).
-- NULL means "use the default set", so existing users see no change until they
-- customize. Mirrors single-tenant migration 041.
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `dashboard_metrics` LONGTEXT
      CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
      CHECK (`dashboard_metrics` IS NULL OR json_valid(`dashboard_metrics`))
      COMMENT 'JSON array of dashboard metric-card keys the user has chosen to show'
      AFTER `events_sort`;
