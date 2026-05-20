-- Per-user preference for the credential-setup nudge.
--
-- The login flow shows a dismissible modal after sign-in when the user has
-- neither a password nor a passkey, encouraging them to set up at least one
-- so future sign-ins don't depend on an emailed link (which can be eaten by
-- link previewers in iMessage / SMS / corporate scanners before the user
-- ever clicks).
--
-- The modal is re-shown on every sign-in by default. Users who really only
-- want email-link sign-in can flip this flag from the modal or the Account
-- page to silence the prompt.

USE panic_backstage;

ALTER TABLE users
  ADD COLUMN hide_credential_setup_prompt TINYINT(1) NOT NULL DEFAULT 0;
