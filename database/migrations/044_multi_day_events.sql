-- Add end_date to support multi-day events (comedy workshops, private rentals, etc.).
-- NULL end_date means the event is a single day (existing behaviour unchanged).
ALTER TABLE events
    ADD COLUMN end_date DATE NULL AFTER date;
