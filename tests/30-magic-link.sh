#!/usr/bin/env bash
# Request a magic-link email and verify a fresh .eml lands in storage/mail/.
. "$(dirname "$0")/lib.sh"

[ -n "${TEST_EMAIL:-}" ] || fail "TEST_EMAIL not set — did 00-config.sh run?"
[ -d "$MAIL_DIR" ] || fail "mail dir missing: $MAIL_DIR"

before="$(date +%s)"
# Sleep one full second so the filename timestamp (per-second) is strictly newer.
sleep 1

body="$(printf '{"email":"%s"}' "$TEST_EMAIL")"
status="$(http_post /auth/magic-link "$body")"
assert_status 200 "$status"

ok="$(json_get ok < "$RESP_BODY" || true)"
[ "$ok" = "true" ] || fail "magic-link did not return ok: $(cat "$RESP_BODY")"

# Locate the freshly written .eml. Mailer writes "<ts>_<email>.eml".
safe="$(mail_filename_for "$TEST_EMAIL")"
eml=""
for candidate in "$MAIL_DIR"/*_"$safe".eml; do
    [ -e "$candidate" ] || continue
    # mtime must be at/after our pre-request timestamp.
    mt="$(stat -c %Y "$candidate" 2>/dev/null || stat -f %m "$candidate" 2>/dev/null || echo 0)"
    if [ "$mt" -ge "$before" ]; then
        if [ -z "$eml" ] || [ "$mt" -gt "$(stat -c %Y "$eml" 2>/dev/null || stat -f %m "$eml" 2>/dev/null || echo 0)" ]; then
            eml="$candidate"
        fi
    fi
done

[ -n "$eml" ] || fail "no fresh .eml for $TEST_EMAIL after magic-link request"

state_set MAGIC_EML "$eml"
