-- Support pre-printed physical tickets: stock printed with a ticket number
-- before the app ever sees it (e.g. a box office's own numbered stubs), sold
-- for cash outside the app, then registered afterward to a buyer's name/
-- email/phone via the public ticket-registration form (PublicTickets::
-- register(), src/TicketingService.php::registerPrintedTicket()).
--
-- printed_number holds exactly what's written on the stub. It is deliberately
-- separate from `code` (always system-generated, globally unique, used for
-- QR/token flows) so a printed number reused across different shows' print
-- runs never collides — the UNIQUE constraint below is scoped per event, not
-- global. NULL for every ticket issued the normal way (online/comp/door-scan
-- sale), so this is additive and changes no existing row.
ALTER TABLE `tickets`
  ADD COLUMN IF NOT EXISTS `printed_number` varchar(40) DEFAULT NULL
    COMMENT 'Ticket number as printed on a pre-sold physical ticket stub; NULL for system-issued tickets.'
    AFTER `code`;

ALTER TABLE `tickets`
  ADD UNIQUE KEY IF NOT EXISTS `uq_tickets_event_printed_number` (`event_id`, `printed_number`);
