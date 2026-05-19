#!/usr/bin/env bash
# Run every numbered shell script under ./tests/ in sorted order.
#
# Each script is its own process; they share state via tests/.state
# (written by state_set in tests/lib.sh) so an earlier script can hand
# tokens etc. to later ones. Output is quiet on success — failing
# scripts have their captured stdout+stderr printed verbatim.
#
# Usage:
#   ./run-tests.sh                     # run against $APP_URL from .env
#   TEST_BASE_URL=http://localhost ./run-tests.sh
#   TEST_EMAIL=me@example.com ./run-tests.sh

set -u
cd "$(dirname "$0")"

TESTS_DIR="./tests"
STATE_FILE="$TESTS_DIR/.state"

if [ ! -d "$TESTS_DIR" ]; then
    printf 'No tests/ directory found.\n' >&2
    exit 2
fi

# Fresh state per run.
: > "$STATE_FILE"

shopt -s nullglob
scripts=("$TESTS_DIR"/[0-9]*.sh)
shopt -u nullglob

if [ "${#scripts[@]}" -eq 0 ]; then
    printf 'No test scripts found under %s\n' "$TESTS_DIR" >&2
    exit 2
fi

# Sort by filename so the numeric prefix dictates execution order.
IFS=$'\n' scripts=($(printf '%s\n' "${scripts[@]}" | sort))
unset IFS

pass=0
fail=0
failed_names=()

for t in "${scripts[@]}"; do
    name="$(basename "$t" .sh)"
    printf '  %-32s ' "$name"
    if out="$(bash "$t" 2>&1)"; then
        printf 'ok\n'
        pass=$((pass + 1))
    else
        printf 'FAIL\n'
        if [ -n "$out" ]; then
            printf '%s\n' "$out" | sed 's/^/      /'
        fi
        fail=$((fail + 1))
        failed_names+=("$name")
    fi
done

total=$((pass + fail))
echo
if [ "$fail" -eq 0 ]; then
    printf 'All %d test(s) passed.\n' "$total"
    exit 0
fi
printf '%d/%d failed: %s\n' "$fail" "$total" "${failed_names[*]}" >&2
exit 1
