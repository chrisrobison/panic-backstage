-- Migration 002: Add import fields from MabEvents.xlsx
-- Run once against panic_backstage, after migration 001

-- External reference ID (e.g. EVT-1050 from the legacy tracker)
ALTER TABLE events
  ADD COLUMN IF NOT EXISTS external_id VARCHAR(50) DEFAULT NULL AFTER id;

-- Who referred this booking inquiry
ALTER TABLE events
  ADD COLUMN IF NOT EXISTS referral_source VARCHAR(255) DEFAULT NULL AFTER description_internal;

-- Organizer / promoter name (free-text from the tracker)
ALTER TABLE events
  ADD COLUMN IF NOT EXISTS promoter_name VARCHAR(255) DEFAULT NULL AFTER referral_source;

-- Which room / floor at the venue (relevant to multi-space venues like Mabuhay Gardens)
ALTER TABLE events
  ADD COLUMN IF NOT EXISTS room ENUM('upstairs','downstairs','both') DEFAULT NULL AFTER capacity;

-- Index for quick lookup by external ID (useful when cross-referencing legacy records)
CREATE INDEX IF NOT EXISTS idx_events_external_id ON events (external_id);
