-- 024_scanner_link_token.sql
-- Store each door scanner link's plaintext secret alongside its sha256 hash so
-- the shareable link + QR can be re-displayed to staff after creation. Door
-- staff frequently need to re-open or re-share an existing link, but previously
-- only the hash was kept, making the URL unrecoverable.
--
-- Redemption still matches on token_hash; this column is purely additive.
-- Links created before this migration have no stored token (NULL) and must be
-- regenerated (which rotates the secret) to reveal a working link/QR again.
ALTER TABLE event_scanner_links
  ADD COLUMN token VARCHAR(64) NULL AFTER token_hash;
