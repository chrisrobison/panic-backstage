#!/usr/bin/env bash
# Revoke the active refresh token so the test run leaves no live session behind.
. "$(dirname "$0")/lib.sh"

[ -n "${REFRESH_TOKEN:-}" ] || fail "REFRESH_TOKEN not set"

body="$(printf '{"refresh_token":"%s"}' "$REFRESH_TOKEN")"
status="$(http_post /auth/logout "$body")"
assert_status 200 "$status"

# A second refresh attempt with the same token must now fail.
status="$(http_post /auth/refresh "$body")"
[ "$status" = "401" ] || fail "logout did not revoke the refresh token (got $status)"
