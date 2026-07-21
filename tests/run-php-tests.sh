#!/usr/bin/env bash
# Run every *_test.php script under ./tests/ — hermetic, zero-dependency PHP
# assertion scripts. Each is its own process; look at any of them for the
# pattern (an ok()/throws() helper, a $passed/$failed tally, exit(0) iff
# nothing failed).
#
# This is the counterpart to ../run-tests.sh, which only picks up numbered
# *.sh integration scripts that need a live server + seeded DB. The scripts
# here need neither by default.
#
# Two scripts are skipped unless explicitly opted into, because they need a
# real MySQL connection:
#   - rate_limiter_test.php      RateLimiter's SQL (ON DUPLICATE KEY, NOW(6))
#                                 is MySQL-only.
#   - contract_signing_test.php  A manual/exploratory script that mutates
#                                 real rows against whatever DB is configured
#                                 and isn't written to be safely repeatable —
#                                 run it directly (php tests/contract_signing_test.php)
#                                 against a throwaway DB when you need it.
#
# Usage:
#   ./tests/run-php-tests.sh                # hermetic tests only (default)
#   RUN_DB_TESTS=1 ./tests/run-php-tests.sh  # also run the MySQL-backed ones
#                                            # (point .env at a throwaway DB first)

set -u
cd "$(dirname "$0")/.."

TESTS_DIR="./tests"
DB_TESTS=("rate_limiter_test.php" "process_versions_test.php" "process_runtime_test.php" "process_centerstage_handlers_test.php")
MANUAL_TESTS=("contract_signing_test.php")

shopt -s nullglob
scripts=("$TESTS_DIR"/*_test.php)
shopt -u nullglob

if [ "${#scripts[@]}" -eq 0 ]; then
    printf 'No *_test.php scripts found under %s\n' "$TESTS_DIR" >&2
    exit 2
fi

IFS=$'\n' scripts=($(printf '%s\n' "${scripts[@]}" | sort))
unset IFS

is_in() {
    local needle="$1"; shift
    for x in "$@"; do [ "$x" = "$needle" ] && return 0; done
    return 1
}

pass=0
fail=0
skip=0
failed_names=()

for t in "${scripts[@]}"; do
    name="$(basename "$t")"

    if is_in "$name" "${MANUAL_TESTS[@]}"; then
        printf '  %-32s skip (manual — run directly: php %s)\n' "$name" "$t"
        skip=$((skip + 1))
        continue
    fi
    if is_in "$name" "${DB_TESTS[@]}" && [ "${RUN_DB_TESTS:-0}" != "1" ]; then
        printf '  %-32s skip (needs DB — set RUN_DB_TESTS=1)\n' "$name"
        skip=$((skip + 1))
        continue
    fi

    printf '  %-32s ' "$name"
    if out="$(php "$t" 2>&1)"; then
        printf 'ok\n'
        pass=$((pass + 1))
    else
        printf 'FAIL\n'
        printf '%s\n' "$out" | sed 's/^/      /'
        fail=$((fail + 1))
        failed_names+=("$name")
    fi
done

total=$((pass + fail))
echo
if [ "$fail" -eq 0 ]; then
    printf 'All %d test(s) passed (%d skipped).\n' "$total" "$skip"
    exit 0
fi
printf '%d/%d failed: %s\n' "$fail" "$total" "${failed_names[*]}" >&2
exit 1
