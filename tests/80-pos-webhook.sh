#!/usr/bin/env bash
# Regression test for the PosWebhook fail-open bug: an unset/empty
# SQUARE_POS_WEBHOOK_SECRET used to skip signature verification entirely,
# letting anyone POST a fabricated Square POS sale straight into
# event_ledger_entries. It must fail closed regardless of whether a secret
# is configured in this environment — a request with no signature header
# is always rejected, never silently accepted.
. "$(dirname "$0")/lib.sh"

body='{"type":"payment.updated","data":{"object":{"payment":{
    "id":"ci-test-payment-unsigned","location_id":"ci-test-location",
    "status":"COMPLETED","total_money":{"amount":500,"currency":"USD"}
}}}}'

status="$(http_post /webhooks/square-pos "$body")"
if [ "$status" = "200" ]; then
    fail "square-pos webhook accepted a request with no signature header (got 200) — fail-open regression"
fi
assert_status 400 "$status"
