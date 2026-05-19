#!/usr/bin/env bash
# Pull the token out of the magic-link email and exchange it for a JWT pair.
. "$(dirname "$0")/lib.sh"

[ -n "${MAGIC_EML:-}" ] || fail "MAGIC_EML not set — did 30-magic-link.sh run?"
[ -r "$MAGIC_EML" ] || fail "cannot read $MAGIC_EML"

token="$(grep -oE 'login\.html\?token=[A-Za-z0-9]+' "$MAGIC_EML" | head -1 | sed 's/.*token=//')"
[ -n "$token" ] || fail "no login token found in $MAGIC_EML"

body="$(printf '{"token":"%s"}' "$token")"
status="$(http_post /auth/verify "$body")"
assert_status 200 "$status"

access="$(json_get access_token  < "$RESP_BODY")" || fail "no access_token in verify response"
refresh="$(json_get refresh_token < "$RESP_BODY")" || fail "no refresh_token in verify response"
email="$(json_get user.email     < "$RESP_BODY")" || fail "no user.email in verify response"

[ "$email" = "$TEST_EMAIL" ] || fail "verify returned wrong email: $email vs $TEST_EMAIL"

state_set ACCESS_TOKEN  "$access"
state_set REFRESH_TOKEN "$refresh"

# Replaying the same token must now be rejected (it's single-use).
status="$(http_post /auth/verify "$body")"
[ "$status" = "401" ] || fail "magic-link token was not single-use (got $status)"
