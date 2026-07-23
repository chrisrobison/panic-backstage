#!/usr/bin/env bash
# cron-lead-sla-tick.sh
#
# Wrapper for lead-sla-tick.php — the Booking Inbox's claim/response SLA
# sweep. Intended to run every few minutes:
#
#   */5 * * * * /home/cdr/domains/panicbooking.com/www/backstage/scripts/cron-lead-sla-tick.sh
#
# Same shape as cron-process-tick.sh: flock(1) so an overrun never overlaps,
# timestamped output appended to storage/logs/lead-sla-tick.log, PATH set
# explicitly because cron starts with a minimal environment.
#
# Deliberately NOT added to this box's live crontab by this change — see
# docs/booking-inbox.md for why (this repo has no staging environment;
# enabling a new scheduled job against production is left as a deliberate,
# separate step for an operator to take).

set -uo pipefail

export PATH="/home/cdr/.local/bin:/usr/local/bin:/usr/bin:/bin"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKSTAGE="$(dirname "$SCRIPT_DIR")"
LOG_DIR="$BACKSTAGE/storage/logs"
LOG_FILE="$LOG_DIR/lead-sla-tick.log"
LOCK_FILE="$LOG_DIR/.lead-sla-tick.lock"

mkdir -p "$LOG_DIR"

exec 9>"$LOCK_FILE"
if ! flock -n 9; then
  exit 0
fi

ts() { date '+%Y-%m-%d %H:%M:%S'; }

if [ -f "$LOG_FILE" ] && [ "$(stat -c%s "$LOG_FILE" 2>/dev/null || echo 0)" -gt 1048576 ]; then
  tail -n 500 "$LOG_FILE" > "$LOG_FILE.tmp" && mv "$LOG_FILE.tmp" "$LOG_FILE"
fi

{
  echo "[$(ts)] lead-sla-tick start (pid=$$)"
  php "$SCRIPT_DIR/lead-sla-tick.php"
  rc=$?
  if [ $rc -eq 0 ]; then
    echo "[$(ts)] lead-sla-tick ok"
  else
    echo "[$(ts)] lead-sla-tick FAILED with exit $rc"
  fi
  exit $rc
} >> "$LOG_FILE" 2>&1
