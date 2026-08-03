#!/usr/bin/env bash
# Public booking-inquiry widget intake (src/PublicInquiry.php) — the
# unauthenticated, CORS-open endpoint <panic-booking-inquiry> posts to.
# Checks: CORS preflight, required-field validation, the honeypot silently
# dropping a spam submission, and a real create landing in the Leads
# pipeline with source=website.
. "$(dirname "$0")/lib.sh"

[ -n "${ACCESS_TOKEN:-}" ] || fail "ACCESS_TOKEN not set (run after 40-verify.sh)"

# Whatever lead this script creates gets cleaned up here regardless of how the
# script exits (including a failed assertion later on) — this writes into the
# same production `leads` table every other test shares.
#
# "Cleaned up" means *archived*, not removed: DELETE /api/leads/{id} is a soft
# delete by design (see Leads::deleteLead — leads are business records, so the
# API deliberately offers no way to destroy one), and there is no harder delete
# to call. So each run leaves one archived row behind. That's out of the active
# pipeline and harmless, but it does accumulate, which is why the rows are
# named "CI TEST … (safe to delete)" — periodically sweeping
#   DELETE FROM leads WHERE contact_name LIKE 'CI TEST Public Inquiry%'
# is safe, and every lead_* child table cascades.
#
# Note this replaces lib.sh's own EXIT trap (bash EXIT traps overwrite rather
# than stack), so $RESP_BODY's temp file is removed here too.
lead_id=""
cleanup() {
    [ -n "$lead_id" ] && http_delete "/leads/$lead_id" >/dev/null 2>&1
    rm -f "$RESP_BODY"
    return 0
}
trap cleanup EXIT

marker="ci-test-$$-$(date +%s)"

# PublicInquiry rate-limits real submissions (per-IP 8 / 10 min, per-email
# 3 / hour), and that budget is shared by every run from this machine. Only
# the one genuine submission at the bottom of this script spends any of it:
# the honeypot case returns 202 before the limiter, and the two validation
# cases are rejected before it (see src/PublicInquiry.php — validation runs
# first precisely so malformed requests cost no quota).
#
# That ordering is what makes this script re-runnable. It used to spend 3
# units per run and send a *constant* invalid address, so the per-email
# bucket for that one string filled after a few runs and the "invalid email
# -> 422" assertion started coming back 429. Both halves are fixed now: the
# invalid address is per-run (still invalid — no @ — so it exercises the same
# branch), and validation failures no longer touch the limiter at all.
#
# A 429 can still happen if you genuinely submit 8 valid inquiries from this
# IP inside 10 minutes, so name that case rather than let it surface as a
# baffling "expected 422, got 429".
assert_inquiry_status() {
    local want="$1" got="$2"
    if [ "$got" = "429" ] && [ "$want" != "429" ]; then
        fail "public-inquiry throttled this IP (HTTP 429), so this assertion never ran.
      This script spends only 1 unit of the endpoint's 8-per-10-minute per-IP
      budget, so seeing this means ~8 valid inquiries came from this IP inside
      10 minutes. Wait out the window and re-run — a 429 here is the throttle
      working, not a regression."
    fi
    assert_status "$want" "$got"
}

# CORS preflight: 204 + the headers a cross-origin embed needs.
# Not a POST, so it costs no rate-limiter budget.
status="$(_curl -X OPTIONS "$API_URL/public/inquiries")"
assert_status 204 "$status"

# Missing required fields -> 422, not 500.
status="$(http_post /public/inquiries '{}')"
assert_inquiry_status 422 "$status"

# Invalid email -> 422. Per-run address so the per-email bucket stays fresh.
status="$(http_post /public/inquiries "$(printf '{"contact_name":"CI Bot","contact_email":"not-an-email-%s","message":"hi"}' "$marker")")"
assert_inquiry_status 422 "$status"

# Honeypot filled -> looks like accepted (202) but must NOT create a lead —
# tipping off a bot that it was caught only teaches it to try harder.
hp_email="${marker}-hp@example.com"
status="$(http_post /public/inquiries "$(printf '{"contact_name":"CI Bot","contact_email":"%s","message":"buy stuff now","company":"not blank"}' "$hp_email")")"
assert_inquiry_status 202 "$status"

status="$(http_get "/api/leads?source=website")"
assert_status 200 "$status"
if grep -q "$hp_email" "$RESP_BODY"; then
    fail "honeypot-filled submission created a lead anyway"
fi

# Real submission -> 202, and it shows up in the pipeline as source=website.
real_email="${marker}@example.com"
body="$(printf '{"contact_name":"CI TEST Public Inquiry (safe to delete)","contact_email":"%s","message":"Automated test submission - safe to delete.","event_type":"corporate"}' "$real_email")"
status="$(http_post /public/inquiries "$body")"
assert_inquiry_status 202 "$status"

status="$(http_get "/api/leads?source=website")"
assert_status 200 "$status"
lead_id="$(php -r '
    $d = json_decode(file_get_contents($argv[1]), true);
    foreach (($d["leads"] ?? []) as $l) {
        if ($l["contact_email"] === $argv[2]) { echo $l["id"]; exit; }
    }
' "$RESP_BODY" "$real_email")"
[ -n "$lead_id" ] || fail "created lead not found via GET /api/leads?source=website"
