-- Issue #21: Zinggflower's recurring public dates were created in external
-- ticketing mode with no URL, so the existing $0 checkout could not appear.
-- Put current/future occurrences into in-house mode and seed one free tier.

-- Give the active series a memorable reusable address when that slug is free.
UPDATE `event_series` target
LEFT JOIN `event_series` conflict
  ON conflict.public_slug = 'zinggflower' AND conflict.id <> target.id
   SET target.public_slug = 'zinggflower'
 WHERE target.id = (
       SELECT chosen.id FROM (
           SELECT MAX(id) AS id FROM `event_series` WHERE LOWER(title) = 'zinggflower'
       ) chosen
   )
   AND conflict.id IS NULL;

UPDATE `events` e
JOIN `event_series` s ON s.id = e.series_id
   SET e.ticketing_mode = 'internal',
       e.ticket_url = NULL,
       e.ticket_price = 0
 WHERE LOWER(s.title) = 'zinggflower'
   AND e.date >= CURDATE()
   AND e.status <> 'canceled';

INSERT INTO `ticket_types`
  (`event_id`, `name`, `description`, `price_cents`, `currency`,
   `quantity_total`, `quantity_sold`, `status`, `sort_order`)
SELECT e.id, 'Free Registration', 'Reserve your spot and receive a ticket by email.',
       0, 'USD', COALESCE(NULLIF(e.capacity, 0), 250), 0, 'on_sale', 0
  FROM `events` e
  JOIN `event_series` s ON s.id = e.series_id
 WHERE LOWER(s.title) = 'zinggflower'
   AND e.date >= CURDATE()
   AND e.status <> 'canceled'
   AND NOT EXISTS (
       SELECT 1 FROM `ticket_types` tt WHERE tt.event_id = e.id
   );
