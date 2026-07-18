-- Fully-executed contracts email each signer a "your signed agreement is
-- ready" notification linking straight to the JWT-protected admin download
-- endpoint (/api/contracts/{id}/download) — external signers have no
-- backstage account, so the link just shows them a bare "Authentication
-- required" JSON error instead of the PDF.
--
-- signing_token_hash can't be reused for this: it's nulled out the moment
-- that signer signs (single-use, see ContractSigningEndpoint), so by the
-- time a contract reaches fully_executed every signer's own token is
-- already gone. A separate, longer-lived download token is generated once
-- at finalization instead (see ContractSigningEndpoint::finalizeContract()).
ALTER TABLE `contract_signers`
  ADD COLUMN `download_token_hash` varchar(64) DEFAULT NULL COMMENT 'sha256(raw_token) for the post-execution PDF download link — raw token never persisted' AFTER `signature_image_path`,
  ADD COLUMN `download_token_expires_at` datetime DEFAULT NULL AFTER `download_token_hash`,
  ADD KEY `idx_signers_download_token_hash` (`download_token_hash`);
