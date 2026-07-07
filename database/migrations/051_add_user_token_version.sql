-- 051_add_user_token_version.sql
--
-- Access tokens are HS256 JWTs valid for 90 days with no server-side session
-- record, so there was previously no way to invalidate one before it expired
-- naturally — a stolen bearer token (or a user whose credentials changed)
-- stayed usable for up to 90 days. token_version adds a cheap revocation
-- check: it's embedded in every freshly issued JWT as the `tv` claim, and
-- Kernel::handle() compares that claim against the current DB value on every
-- authenticated request, rejecting the token on a mismatch. Bumping the
-- column (e.g. on password change — see AuthEndpoint::setPassword) instantly
-- invalidates every access token issued before the bump.

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS token_version INT UNSIGNED NOT NULL DEFAULT 0
    AFTER password_hash;
