#!/usr/bin/env bash
# GET /api/realtime/stream — the Phase 2 realtime SSE endpoint (src/Realtime.php).
# Runs after 50-me-auth.sh has populated ACCESS_TOKEN in test state.
#
# The endpoint intentionally holds the connection open (up to ~55s by
# default), so this does NOT use lib.sh's http_get/_curl helpers (those wait
# for the request to fully complete). Instead it uses a short --max-time and
# treats curl's resulting timeout exit code (28) as expected, then inspects
# whatever headers/body were captured in that window.
. "$(dirname "$0")/lib.sh"

[ -n "${ACCESS_TOKEN:-}" ] || fail "ACCESS_TOKEN not set"

HEADERS_FILE="$(mktemp -t backstage-realtime-headers.XXXXXX)"
BODY_FILE="$(mktemp -t backstage-realtime-body.XXXXXX)"
trap 'rm -f "$HEADERS_FILE" "$BODY_FILE"' EXIT

# ── Unauthenticated: 401 before the stream ever opens ───────────────────────
# No -N/timeout tricks needed here — Kernel rejects this before Realtime is
# even instantiated, so it's an ordinary fast JSON response.
status="$(curl -sS -o "$BODY_FILE" -w '%{http_code}' --max-time 10 "$BASE_URL/api/realtime/stream")"
assert_status 401 "$status"

# ── Authenticated: connects, sends the right headers, starts the stream ─────
# --max-time 3 forces curl to abort mid-stream (the server intentionally
# holds the connection open far longer than that) — exit code 28 (timeout)
# is the success case here, not a failure.
set +e
curl -sS -N --max-time 3 \
    -D "$HEADERS_FILE" -o "$BODY_FILE" \
    -H "Authorization: Bearer $ACCESS_TOKEN" \
    "$BASE_URL/api/realtime/stream"
curl_exit=$?
set -e
[ "$curl_exit" -eq 0 ] || [ "$curl_exit" -eq 28 ] \
    || fail "curl exited $curl_exit (expected 0 or 28/timeout — the stream should stay open)"

grep -qi '^HTTP/.* 200' "$HEADERS_FILE" || fail "expected 200, got: $(head -1 "$HEADERS_FILE")"
grep -qi '^content-type: *text/event-stream' "$HEADERS_FILE" || fail "Content-Type is not text/event-stream: $(cat "$HEADERS_FILE")"
grep -qi '^cache-control: *no-cache' "$HEADERS_FILE" || fail "Cache-Control is not no-cache"
grep -qi '^x-accel-buffering: *no' "$HEADERS_FILE" || fail "X-Accel-Buffering: no header is missing (nginx would buffer this response)"

# The stream writes "retry: 2000\n\n" immediately on connect, before the
# poll loop even starts, so it should be present even in a very short window.
grep -q '^retry: 2000$' "$BODY_FILE" || fail "expected an initial 'retry: 2000' line, got: $(cat "$BODY_FILE")"

# ── Resume via Last-Event-ID: server accepts it and still streams cleanly ───
set +e
curl -sS -N --max-time 3 \
    -D "$HEADERS_FILE" -o "$BODY_FILE" \
    -H "Authorization: Bearer $ACCESS_TOKEN" \
    -H "Last-Event-ID: 1" \
    "$BASE_URL/api/realtime/stream"
curl_exit=$?
set -e
[ "$curl_exit" -eq 0 ] || [ "$curl_exit" -eq 28 ] \
    || fail "curl (Last-Event-ID resume) exited $curl_exit (expected 0 or 28/timeout)"
grep -qi '^HTTP/.* 200' "$HEADERS_FILE" || fail "Last-Event-ID resume: expected 200, got: $(head -1 "$HEADERS_FILE")"

# ── Never leaks raw audit data on the wire ───────────────────────────────────
grep -qi 'old_row\|new_row' "$BODY_FILE" && fail "stream body contains old_row/new_row — audit data must never reach the client"

exit 0
