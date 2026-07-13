#!/usr/bin/env bash
# cron-expire-holds.sh
#
# Wrapper for expire-holds.php intended to be invoked once a day.
#
#   20 4 * * * /home/cdr/domains/panicbooking.com/www/backstage/scripts/cron-expire-holds.sh
#
# The underlying script is a no-op until HOLD_EXPIRY_ENABLED is set in .env
# (see expire-holds.php and issue #17 — the reporter asked for a ~2 month
# runway after filing before this goes live), so it's safe to wire into cron
# now: it'll just log "feature is off" every night until it's switched on.
#
# Behavior:
#   - Uses flock(1) so an overrun never overlaps. If a previous run is
#     still going this invocation exits 0 silently.
#   - Appends timestamped output to backstage/storage/logs/expire-holds.log.
#   - Sets PATH explicitly because cron starts with a minimal environment.

set -uo pipefail

export PATH="/home/cdr/.local/bin:/usr/local/bin:/usr/bin:/bin"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKSTAGE="$(dirname "$SCRIPT_DIR")"
LOG_DIR="$BACKSTAGE/storage/logs"
LOG_FILE="$LOG_DIR/expire-holds.log"
LOCK_FILE="$LOG_DIR/.expire-holds.lock"

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
  echo "[$(ts)] expire-holds start (pid=$$)"
  php "$SCRIPT_DIR/expire-holds.php"
  rc=$?
  if [ $rc -eq 0 ]; then
    echo "[$(ts)] expire-holds ok"
  else
    echo "[$(ts)] expire-holds FAILED with exit $rc"
  fi
  exit $rc
} >> "$LOG_FILE" 2>&1
