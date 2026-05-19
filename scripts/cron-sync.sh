#!/usr/bin/env bash
# cron-sync.sh
#
# Wrapper for sync-mabevents.py intended to be invoked by cron every 5 minutes.
#
#   */5 * * * * /home/cdr/domains/panicbooking.com/www/backstage/scripts/cron-sync.sh
#
# Behavior:
#   - Uses flock(1) so an overrunning sync never overlaps with the next tick.
#     If a previous run is still going, this invocation exits 0 silently.
#   - Appends timestamped output to backstage/storage/logs/sync-mabevents.log.
#   - Sets PATH explicitly because cron starts with a minimal environment and
#     python3 / php live outside /usr/bin on this host.

set -uo pipefail

# Make python3, php, flock, and friends discoverable under cron's minimal env.
export PATH="/home/cdr/.local/bin:/usr/local/bin:/usr/bin:/bin"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKSTAGE="$(dirname "$SCRIPT_DIR")"
LOG_DIR="$BACKSTAGE/storage/logs"
LOG_FILE="$LOG_DIR/sync-mabevents.log"
LOCK_FILE="$LOG_DIR/.sync-mabevents.lock"

mkdir -p "$LOG_DIR"

# Acquire a non-blocking exclusive lock on fd 9. If the previous run still
# holds it, skip this tick — overlap protection.
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
  echo "[$(ts)] sync start (pid=$$)"
  python3 "$SCRIPT_DIR/sync-mabevents.py"
  rc=$?
  if [ $rc -eq 0 ]; then
    echo "[$(ts)] sync ok"
  else
    echo "[$(ts)] sync FAILED with exit $rc"
  fi
  exit $rc
} >> "$LOG_FILE" 2>&1
