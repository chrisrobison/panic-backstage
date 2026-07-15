-- 060_contract_uploaded_asset.sql
--
-- Lets a user record "this event's contract was signed outside the system
-- and the signed document is attached as an asset" instead of always
-- generating/signing a contract through the in-app deal builder.
--
-- Why: Events.php::validateStatusTransition() already blocks advancing an
-- event to 'booked' unless there is a contracts row with
-- status IN ('signed','fully_executed') (or the legacy contract_url text
-- field). That gate has no allowance for a contract that was signed on
-- paper/DocuSign-elsewhere and simply uploaded as a PDF/photo via the
-- existing event_assets upload flow.
--
-- Rather than duplicating the gate's logic with a second flag+check, this
-- adds a thin "asset_id" link on contracts: attaching an uploaded contract
-- creates a normal contracts row (provider='manual_upload', status='signed',
-- asset_id -> event_assets.id) that satisfies the *existing* gate query with
-- zero changes to Events.php. See ContractService::attachUploaded().
--
-- 'contract' is added to event_assets.asset_type so the signed document can
-- be uploaded/tagged distinctly from flyers/posters/photos, and so the
-- picker in the Contracts tab can filter to it.

ALTER TABLE event_assets
  MODIFY COLUMN asset_type ENUM(
    'flyer','poster','band_photo','logo','social_square','social_story',
    'press_photo','qr_code','contract','other'
  ) NOT NULL DEFAULT 'other';

ALTER TABLE contracts
  ADD COLUMN IF NOT EXISTS asset_id INT NULL DEFAULT NULL AFTER template_id;

ALTER TABLE contracts
  ADD KEY IF NOT EXISTS idx_contracts_asset (asset_id);
