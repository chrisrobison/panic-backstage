#!/usr/bin/env bash
# Public booking-inquiry widget intake (src/PublicInquiry.php) — the
# unauthenticated, CORS-open endpoint <panic-booking-inquiry> posts to.
# Checks: CORS preflight, required-field validation, the honeypot silently
# dropping a spam submission, and a real create landing in the Leads
# pipeline with source=website.
. "$(dirname "$0")/lib.sh"

[ -n "${ACCESS_TOKEN:-}" ] || fail "ACCESS_TOKEN not set (run after 40-verify.sh)"

# Whatever lead this script creates gets deleted here regardless of how the
# script exits (including a failed assertion later on) — this writes into
# the same production `leads` table every other test shares.
lead_id=""
cleanup() {
    [ -n "$lead_id" ] && http_delete "/leads/$lead_id" >/dev/null 2>&1
    return 0
}
trap cleanup EXIT

# CORS preflight: 204 + the headers a cross-origin embed needs.
status="$(_curl -X OPTIONS "$API_URL/public/inquiries")"
assert_status 204 "$status"

# Missing required fields -> 422, not 500.
status="$(http_post /public/inquiries '{}')"
assert_status 422 "$status"

# Invalid email -> 422.
status="$(http_post /public/inquiries '{"contact_name":"CI Bot","contact_email":"not-an-email","message":"hi"}')"
assert_status 422 "$status"

marker="ci-test-$$-$(date +%s)"

# Honeypot filled -> looks like success (200) but must NOT create a lead —
# tipping off a bot that it was caught only teaches it to try harder.
hp_email="${marker}-hp@example.com"
status="$(http_post /public/inquiries "$(printf '{"contact_name":"CI Bot","contact_email":"%s","message":"buy stuff now","company":"not blank"}' "$hp_email")")"
assert_status 200 "$status"

status="$(http_get "/api/leads?source=website")"
assert_status 200 "$status"
if grep -q "$hp_email" "$RESP_BODY"; then
    fail "honeypot-filled submission created a lead anyway"
fi

# Real submission -> 200, and it shows up in the pipeline as source=website.
real_email="${marker}@example.com"
body="$(printf '{"contact_name":"CI TEST Public Inquiry (safe to delete)","contact_email":"%s","message":"Automated test submission - safe to delete.","event_type":"corporate"}' "$real_email")"
status="$(http_post /public/inquiries "$body")"
assert_status 200 "$status"

status="$(http_get "/api/leads?source=website")"
assert_status 200 "$status"
lead_id="$(php -r '
    $d = json_decode(file_get_contents($argv[1]), true);
    foreach (($d["leads"] ?? []) as $l) {
        if ($l["contact_email"] === $argv[2]) { echo $l["id"]; exit; }
    }
' "$RESP_BODY" "$real_email")"
[ -n "$lead_id" ] || fail "created lead not found via GET /api/leads?source=website"
