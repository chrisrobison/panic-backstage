-- App Settings — venue-facing brand identity shown in the app shell (upper
-- left: sidebar/topbar name + logo, browser tab title). Singleton row (id=1),
-- same shape as wizard_defaults. Deliberately narrow: only fields with no
-- existing home elsewhere (venues.name/address/etc already covers the venue
-- profile shown on contracts — see Admin > Venue). Admin contact + social
-- fields live in .env (VENUE_MANAGER_NAME/EMAIL/PHONE etc.) via a safe,
-- allow-listed writer — see src/AppSettings.php.
CREATE TABLE IF NOT EXISTS `app_settings` (
  `id` tinyint(1) NOT NULL DEFAULT 1,
  `brand_name` varchar(190) DEFAULT NULL,
  `logo_url` varchar(500) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  CONSTRAINT `app_settings_singleton` CHECK (`id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
