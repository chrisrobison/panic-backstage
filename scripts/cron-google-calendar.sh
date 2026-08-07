#!/usr/bin/env bash
# cron-google-calendar.sh
#
# Wrapper for sync-google-calendar.php, intended to be invoked by cron nightly.
#
#   45 4 * * * /home/cdr/domains/panicbooking.com/www/backstage/scripts/cron-google-calendar.sh
#
# Behavior:
#   - Uses flock(1) so a slow sweep never overlaps the next tick. If a previous
#     run is still going, this invocation exits 0 silently.
#   - Appends timestamped output to backstage/storage/logs/calendar-sync.log.
#   - Sets PATH explicitly because cron starts with a minimal environment.
#
# Nightly is deliberate: the mirror's freshness target is "within a day". If
# that ever needs to be minutes, add an inline push on event write rather than
# hammering this sweep every few minutes.

set -uo pipefail

export PATH="/home/cdr/.local/bin:/usr/local/bin:/usr/bin:/bin"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKSTAGE="$(dirname "$SCRIPT_DIR")"
LOG_DIR="$BACKSTAGE/storage/logs"
LOG_FILE="$LOG_DIR/calendar-sync.log"
LOCK_FILE="$LOG_DIR/.calendar-sync.lock"

mkdir -p "$LOG_DIR"

# Master kill switch: GCAL_SYNC_ENABLED=0 in backstage/.env disables the mirror
# without touching crontab. Read straight from .env because cron gives us no app
# environment. Absent/blank means enabled. An exported OS env var wins.
if [ -z "${GCAL_SYNC_ENABLED:-}" ] && [ -f "$BACKSTAGE/.env" ]; then
  GCAL_SYNC_ENABLED="$(grep -m1 '^[[:space:]]*GCAL_SYNC_ENABLED=' "$BACKSTAGE/.env" \
    | cut -d= -f2- | tr -d ' "'\''' )"
fi
case "${GCAL_SYNC_ENABLED:-1}" in
  0|false|FALSE|off|OFF|no|NO)
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] calendar sync skipped (GCAL_SYNC_ENABLED=${GCAL_SYNC_ENABLED})" >> "$LOG_FILE"
    exit 0
    ;;
esac

# Acquire a non-blocking exclusive lock on fd 9. If the previous run still holds
# it, skip this tick — overlap protection.
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
  echo "[$(ts)] calendar sync start (pid=$$)"
  php "$SCRIPT_DIR/sync-google-calendar.php"
  rc=$?
  if [ $rc -eq 0 ]; then
    echo "[$(ts)] calendar sync ok"
  else
    echo "[$(ts)] calendar sync FAILED with exit $rc"
  fi
  exit $rc
} >> "$LOG_FILE" 2>&1
