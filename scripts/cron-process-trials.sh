#!/usr/bin/env bash
# cron-process-trials.sh
#
# Wrapper for process-trials.php — drains panicbackstage.com's trial-signup
# queue (storage/trials.ndjson.php in the sibling www/ site) and provisions
# each pending tenant.
#
#   */5 * * * * /home/cdr/domains/panicbackstage.com/app/scripts/cron-process-trials.sh
#
# Behavior:
#   - Uses flock(1) so an overrun never overlaps. If a previous run is
#     still going this invocation exits 0 silently.
#   - Appends timestamped output to app/storage/logs/process-trials.log.
#   - Sets PATH explicitly because cron starts with a minimal environment.
#   - Rows that fail provisioning are marked "failed" and are NOT retried
#     automatically — check the log, fix the cause, then rerun by hand with
#     --retry-failed (see process-trials.php header).

set -uo pipefail

export PATH="/home/cdr/.local/bin:/usr/local/bin:/usr/bin:/bin"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP="$(dirname "$SCRIPT_DIR")"
LOG_DIR="$APP/storage/logs"
LOG_FILE="$LOG_DIR/process-trials.log"
LOCK_FILE="$LOG_DIR/.process-trials.lock"

mkdir -p "$LOG_DIR"

exec 9>"$LOCK_FILE"
if ! flock -n 9; then
  exit 0
fi

ts() { date '+%Y-%m-%d %H:%M:%S'; }

# Trim the log if it grows past ~1 MB (keep the last 500 lines).
if [ -f "$LOG_FILE" ] && [ "$(stat -c%s "$LOG_FILE" 2>/dev/null || echo 0)" -gt 1048576 ]; then
  tail -n 500 "$LOG_FILE" > "$LOG_FILE.tmp" && mv "$LOG_FILE.tmp" "$LOG_FILE"
fi

{
  echo "[$(ts)] process-trials start (pid=$$)"
  php "$SCRIPT_DIR/process-trials.php"
  rc=$?
  if [ $rc -eq 0 ]; then
    echo "[$(ts)] process-trials ok"
  else
    echo "[$(ts)] process-trials FAILED with exit $rc"
  fi
  exit $rc
} >> "$LOG_FILE" 2>&1
