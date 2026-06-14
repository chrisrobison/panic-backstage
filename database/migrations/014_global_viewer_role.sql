-- Add global_viewer to the users.role ENUM.
-- global_viewer accounts can see all events and their full details (read-only)
-- but cannot edit, publish, delete, or perform any admin actions.
ALTER TABLE users
  MODIFY COLUMN role
    ENUM('venue_admin','event_owner','promoter','band','artist','designer','staff','viewer','global_viewer')
    NOT NULL DEFAULT 'viewer';
