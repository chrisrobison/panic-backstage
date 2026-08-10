-- Issues #22, #29, #33: track the full room-occupancy window from load-in
-- through load-out. Existing events inherit End as their initial Load Out so
-- the new conflict logic remains at least as protective as it was before.
ALTER TABLE events
  ADD COLUMN IF NOT EXISTS load_out_time TIME NULL AFTER load_in_time;

UPDATE events
SET load_out_time = end_time
WHERE load_out_time IS NULL
  AND end_time IS NOT NULL;
