#!/usr/bin/env bash
# With the saved Bearer token, /api/me should now identify us.
. "$(dirname "$0")/lib.sh"

[ -n "${ACCESS_TOKEN:-}" ] || fail "ACCESS_TOKEN not set — did 40-verify.sh run?"

status="$(http_get /api/me)"
assert_status 200 "$status"

email="$(json_get user.email < "$RESP_BODY")" || fail "user is null with a valid Bearer token"
[ "$email" = "$TEST_EMAIL" ] || fail "/api/me email mismatch: $email vs $TEST_EMAIL"

# A garbage token must NOT be accepted.
bad_status="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 \
    -H 'Authorization: Bearer not-a-real-token' \
    "$BASE_URL/api/events")"
[ "$bad_status" = "401" ] || fail "invalid Bearer token was accepted (got $bad_status)"
