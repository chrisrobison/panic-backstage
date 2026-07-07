-- Adds a `qr_code` asset type so a QR code pointing at an event's public page
-- can be generated and stored as a normal, downloadable event_assets row
-- (reusing the existing upload/approve/download UI, no new tables needed).
-- MODIFY is safe to re-run: restating the same enum is a no-op on re-apply.
ALTER TABLE `event_assets`
  MODIFY `asset_type` enum('flyer','poster','band_photo','logo','social_square','social_story','press_photo','qr_code','other') NOT NULL DEFAULT 'other';
