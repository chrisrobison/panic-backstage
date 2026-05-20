-- Staffing: roster of employees + per-event shifts.
--
-- staff_members is a reusable roster (security, bartenders, door staff, etc.)
-- maintained independently of the users table — most night-of-show staff do
-- not need a backstage login. A staff row may optionally link to a user via
-- user_id when the same person also has an account.
--
-- event_staffing assigns one shift per row. staff_member_id is nullable so a
-- shift can be created as "TBD" before assignment.

USE panic_backstage;

CREATE TABLE IF NOT EXISTS staff_members (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(255) NOT NULL,
  email        VARCHAR(255) DEFAULT NULL,
  phone        VARCHAR(64)  DEFAULT NULL,
  default_role ENUM(
    'manager','security','bartender','barback','door',
    'sound','lighting','stagehand','runner','cleaner','other'
  ) NOT NULL DEFAULT 'other',
  hourly_rate  DECIMAL(10,2) DEFAULT NULL,
  notes        TEXT DEFAULT NULL,
  active       TINYINT(1) NOT NULL DEFAULT 1,
  user_id      INT DEFAULT NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_staff_active (active),
  INDEX idx_staff_default_role (default_role),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_staffing (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  event_id        INT NOT NULL,
  staff_member_id INT DEFAULT NULL,
  role            ENUM(
    'manager','security','bartender','barback','door',
    'sound','lighting','stagehand','runner','cleaner','other'
  ) NOT NULL DEFAULT 'other',
  call_time       TIME DEFAULT NULL,
  end_time        TIME DEFAULT NULL,
  hourly_rate     DECIMAL(10,2) DEFAULT NULL,
  status          ENUM('scheduled','confirmed','declined','no_show','completed','canceled')
                  NOT NULL DEFAULT 'scheduled',
  notes           TEXT DEFAULT NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_staffing_event (event_id),
  INDEX idx_staffing_role (role),
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (staff_member_id) REFERENCES staff_members(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
