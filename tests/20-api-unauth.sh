#!/usr/bin/env bash
# Unauthenticated API behaviour: public endpoints return shape, protected ones 401.
. "$(dirname "$0")/lib.sh"

# /api/me is public; user must be null when no Bearer is sent.
status="$(http_get /api/me)"
assert_status 200 "$status"
user="$(json_get user < "$RESP_BODY" || true)"
[ "$user" = "null" ] || fail "/api/me leaked a user when unauthenticated: $user"

# /api/events requires auth → 401.
status="$(http_get /api/events)"
assert_status 401 "$status"

# Push registration is authenticated-only. Worth asserting explicitly: these
# endpoints are gated purely by Kernel's isPublic() allow-list (Push is
# deliberately absent from it), so an accidental addition there would silently
# expose device registration to anonymous callers.
status="$(http_get /api/push/config)"
assert_status 401 "$status"
status="$(http_get /api/push/subscriptions)"
assert_status 401 "$status"

# An unknown endpoint should be a clean 404 (Kernel::handle), not a 500.
status="$(http_get /api/does-not-exist-xyzzy)"
if [ "$status" = "500" ]; then
    fail "unknown endpoint returned 500 instead of 404"
fi
