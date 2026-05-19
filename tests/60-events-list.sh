#!/usr/bin/env bash
# Authenticated GET /api/events should respond 200 with a JSON array.
. "$(dirname "$0")/lib.sh"

[ -n "${ACCESS_TOKEN:-}" ] || fail "ACCESS_TOKEN not set"

status="$(http_get /api/events)"
assert_status 200 "$status"

# Body should parse as JSON. Some endpoints return {events: [...]}, some a bare array;
# accept either, and just confirm the shape isn't an error object.
php -r '
    $b = stream_get_contents(STDIN);
    $d = json_decode($b, true);
    if ($d === null && trim($b) !== "null") { fwrite(STDERR, "not json: ".substr($b,0,120)."\n"); exit(1); }
    if (is_array($d) && isset($d["error"])) { fwrite(STDERR, "error key: ".$d["error"]."\n"); exit(1); }
' < "$RESP_BODY"
