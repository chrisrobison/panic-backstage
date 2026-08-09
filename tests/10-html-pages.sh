#!/usr/bin/env bash
# Each public HTML page should return 200 with non-trivial body.
. "$(dirname "$0")/lib.sh"

for page in /index.html /login.html /event.html /invite.html; do
    status="$(http_get "$page")"
    assert_status 200 "$status"
    size="$(wc -c < "$RESP_BODY")"
    if [ "$size" -lt 200 ]; then
        fail "$page returned only $size bytes — likely a broken page"
    fi
    # Catch common Apache/PHP error pages that still return 200 via a custom handler.
    if grep -qiE 'fatal error|parse error|stack trace' "$RESP_BODY"; then
        fail "$page contains a PHP error string"
    fi
done

# The push service worker must be served from the application ROOT as real
# JavaScript. It is a static file under public/, but every unmatched path
# falls through to the SPA catch-all in .htaccess — so without an explicit
# rewrite this silently returns index.html with a text/html content type and
# the browser refuses to register the worker. That failure is invisible from
# the app itself (push just never arrives), which is exactly why it is
# asserted here.
sw_status="$(http_get /sw.js)"
assert_status 200 "$sw_status"
if ! grep -q "notificationclick" "$RESP_BODY"; then
    fail "/sw.js did not return the service worker (likely the SPA HTML shell)"
fi
if grep -qi "<!doctype html" "$RESP_BODY"; then
    fail "/sw.js returned HTML — the app-root rewrite for the service worker is missing"
fi

# The web app manifest backs Home Screen installation, which iOS requires
# before it will deliver web push at all.
manifest_status="$(http_get /assets/favicon/site.webmanifest)"
assert_status 200 "$manifest_status"
if ! grep -q '"display"' "$RESP_BODY"; then
    fail "site.webmanifest did not return a web app manifest"
fi
