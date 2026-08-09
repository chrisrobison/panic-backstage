-- Look up a purchased ticket by name and admit it without a QR.
--
-- The door already handles the two people it was built for: the one holding a
-- QR (scan it) and the one holding cash (sell them a ticket). The third is
-- just as common and had no path at all — someone who genuinely bought a
-- ticket but cannot produce it. Phone dead, email buried, ticket printed and
-- left at home. Today the door's only options are to turn away a paying
-- customer or to wave them through untracked, which quietly breaks the
-- headcount the scanner exists to produce and leaves the ticket 'issued'
-- forever, so it can still be walked in a second time by whoever does find
-- the email later.
--
-- The fix is a lookup: find the ticket by holder name / code, check their ID
-- against the name on it, mark it used. Same state transition the scanner
-- already performs (issued -> redeemed), reached by a different route.
--
-- can_lookup: scanner links are bearer URLs, and this is the first scanner
--   capability that *reads* customer data rather than acting on a ticket the
--   holder physically presented. A leaked scan-only link can mark tickets
--   used; a leaked lookup link could additionally enumerate who is coming to
--   the show, and admit any of them by name without ever holding a ticket.
--   That is a real escalation, so it follows can_sell exactly: opt-in per
--   link, default 0, settable only at creation, no endpoint that upgrades an
--   existing link. Every link that exists today keeps precisely the powers it
--   was created with. Scanner::lookup()/admit() refuse any link without this
--   bit, and the lookup response is field-stripped besides — it never returns
--   a ticket token or QR URL, so the list cannot be turned into working
--   tickets.
--
-- manual_admit: appended to the ticket_scans result enum rather than reusing
--   'admitted'. Both mean the person walked in and both must count toward the
--   same headcount, but only one of them involved a machine verifying a
--   cryptographic token — the other involved a human accepting an ID. When a
--   settlement is disputed or a tier's count looks wrong, "which admissions
--   did staff wave through by hand, and which link did it" is exactly the
--   question you need the audit trail to answer. Appended at the END of the
--   enum on purpose: adding a trailing value is an instant metadata-only
--   change, while inserting or reordering rewrites the table and remaps every
--   stored value.

ALTER TABLE `event_scanner_links`
  ADD COLUMN IF NOT EXISTS `can_lookup` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Opt-in: may this link look up purchased tickets and admit without a QR?'
    AFTER `can_sell`;

ALTER TABLE `ticket_scans`
  MODIFY COLUMN `result` ENUM(
    'admitted','already_redeemed','void','not_found','wrong_event','expired_link','manual_admit'
  ) NOT NULL;
