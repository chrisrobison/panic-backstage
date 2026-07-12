#!/usr/bin/env bash
# Regression test for the passkey-login rate limits added to
# AuthEndpoint::passkeyLoginBegin()/passkeyLoginComplete(): both were
# previously unthrottled (unauthenticated endpoints, unlimited requests —
# passkeyLoginBegin wrote a webauthn_challenges row on every call). Confirms
# the per-email and per-credential-id buckets (cap 8 per 900s) actually
# return 429 once exhausted — see tests/rate_limiter_test.php for coverage
# of RateLimiter itself.
. "$(dirname "$0")/lib.sh"

email="ci-passkey-rate-limit-test@example.invalid"
begin_body="{\"email\":\"$email\"}"

status=""
for i in $(seq 1 9); do
    status="$(http_post /auth/passkey-login-begin "$begin_body")"
done
if [ "$status" != "429" ]; then
    fail "passkey-login-begin: expected 429 on the 9th request with the same email (cap is 8/900s), got $status"
fi

cred_id="ci-passkey-rate-limit-test-cred"
complete_body="{\"id\":\"$cred_id\",\"response\":{\"clientDataJSON\":\"e30=\"}}"

status=""
for i in $(seq 1 9); do
    status="$(http_post /auth/passkey-login-complete "$complete_body")"
done
if [ "$status" != "429" ]; then
    fail "passkey-login-complete: expected 429 on the 9th request with the same credential id (cap is 8/900s), got $status"
fi
