-- Track when the buyer confirmation email was sent for an order.
-- Used as a database-level deduplication guard: only the first webhook delivery
-- that wins the UPDATE (emailed_at IS NULL) proceeds to send; retries get 0
-- rows and bail out before even loading the order.  This is a second line of
-- defence on top of the token-nulling in TicketingService::fulfillOrder().
ALTER TABLE ticket_orders
    ADD COLUMN emailed_at DATETIME NULL DEFAULT NULL
        AFTER paid_at;
