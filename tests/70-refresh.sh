#!/usr/bin/env bash
# Rotating the refresh token must issue a new pair and invalidate the old refresh.
. "$(dirname "$0")/lib.sh"

[ -n "${REFRESH_TOKEN:-}" ] || fail "REFRESH_TOKEN not set"

body="$(printf '{"refresh_token":"%s"}' "$REFRESH_TOKEN")"
status="$(http_post /auth/refresh "$body")"
assert_status 200 "$status"

new_access="$(json_get access_token  < "$RESP_BODY")"  || fail "refresh did not return access_token"
new_refresh="$(json_get refresh_token < "$RESP_BODY")" || fail "refresh did not return refresh_token"
[ "$new_refresh" != "$REFRESH_TOKEN" ] || fail "refresh token was not rotated"

# Replaying the now-revoked refresh token must fail.
status="$(http_post /auth/refresh "$body")"
[ "$status" = "401" ] || fail "old refresh token was not revoked (got $status)"

state_set ACCESS_TOKEN  "$new_access"
state_set REFRESH_TOKEN "$new_refresh"
