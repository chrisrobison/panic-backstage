#!/usr/bin/env bash
# cron-expire-ai-proposals.sh
#
# Wrapper for expire-ai-proposals.php, intended to run every 15 minutes —
# the AI Assistant's proposals expire after 30 minutes (see
# src/Ai/Assistant.php), so a 15-minute sweep keeps ai_action_proposals.status
# reasonably fresh without being excessive:
#
#   */15 * * * * /home/cdr/domains/panicbooking.com/www/backstage/scripts/cron-expire-ai-proposals.sh
#
# Behavior:
#   - Uses flock(1) so an overrun never overlaps. If a previous run is
#     still going this invocation exits 0 silently.
#   - Appends timestamped output to backstage/storage/logs/expire-ai-proposals.log.
#   - Sets PATH explicitly because cron starts with a minimal environment.

set -uo pipefail

export PATH="/home/cdr/.local/bin:/usr/local/bin:/usr/bin:/bin"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKSTAGE="$(dirname "$SCRIPT_DIR")"
LOG_DIR="$BACKSTAGE/storage/logs"
LOG_FILE="$LOG_DIR/expire-ai-proposals.log"
LOCK_FILE="$LOG_DIR/.expire-ai-proposals.lock"

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
  echo "[$(ts)] expire-ai-proposals start (pid=$$)"
  php "$SCRIPT_DIR/expire-ai-proposals.php"
  rc=$?
  if [ $rc -eq 0 ]; then
    echo "[$(ts)] expire-ai-proposals ok"
  else
    echo "[$(ts)] expire-ai-proposals FAILED with exit $rc"
  fi
  exit $rc
} >> "$LOG_FILE" 2>&1
