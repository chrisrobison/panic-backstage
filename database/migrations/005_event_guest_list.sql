-- Guest list / door list entries for an event.
-- Used by the per-event print feature (door/guest-list printout) and by
-- future guest-list management UI.

CREATE TABLE IF NOT EXISTS event_guest_list (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  party_size INT NOT NULL DEFAULT 1,
  list_type ENUM('comp','guest','will_call','vip','press','industry') NOT NULL DEFAULT 'guest',
  guest_of VARCHAR(255) NULL,           -- "guest of <band/promoter/staff>"
  notes TEXT NULL,
  checked_in TINYINT(1) NOT NULL DEFAULT 0,
  checked_in_at TIMESTAMP NULL,
  created_by_user_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_guest_event (event_id),
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
