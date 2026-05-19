#!/usr/bin/env bash
# Validate the test environment and record the chosen email for later scripts.
#
#   TEST_BASE_URL  override the site URL (defaults to APP_URL from .env)
#   TEST_EMAIL     email used for the magic-link round-trip (default below)
. "$(dirname "$0")/lib.sh"

require_cmd curl php grep sed

TEST_EMAIL="${TEST_EMAIL:-backstage-test@example.com}"

# Sanity: BASE_URL must be reachable and serve /api/me as JSON.
status="$(http_get /api/me)"
assert_status 200 "$status"
# The body is JSON with a "capabilities" key whether or not we're signed in.
json_get capabilities.view_all_events < "$RESP_BODY" >/dev/null \
    || fail "/api/me did not return expected shape"

state_set BASE_URL "$BASE_URL"
state_set TEST_EMAIL "$TEST_EMAIL"
