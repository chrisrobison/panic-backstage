-- Migration 016: wizard_defaults — admin-configurable default field values for
-- the Event Creation Wizard. Single-row table (id = 1) storing a JSON object
-- keyed by wizard field ID (e.g. venue_id, doors_time, age_restriction, …).
CREATE TABLE IF NOT EXISTS `wizard_defaults` (
  `id`           TINYINT(1)   NOT NULL DEFAULT 1,
  `defaults_json` JSON         DEFAULT NULL,
  `updated_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `wizard_defaults_singleton` CHECK (`id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed sensible out-of-the-box defaults.
INSERT IGNORE INTO `wizard_defaults` (id, defaults_json) VALUES (1, JSON_OBJECT(
  'doors_time',            '19:00',
  'show_time',             '20:00',
  'end_time',              '23:00',
  'age_restriction',       '21+',
  'deal_type',             'talent_buy',
  'deposit_amount',        '500',
  'bar_minimum',           '1000',
  'security_count',        '2',
  'security_rate',         '25',
  'security_paid_by',      'venue',
  'sound_tech_included',   '1',
  'lighting_tech_included','0',
  'merch_venue_percent',   '15'
));
