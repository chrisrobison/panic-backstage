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
