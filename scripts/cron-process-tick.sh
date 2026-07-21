#!/usr/bin/env bash
# cron-process-tick.sh
#
# Wrapper for process-tick.php — the Automation process runtime's timeout
# and escalation sweep. Intended to run every few minutes:
#
#   */5 * * * * /home/cdr/domains/panicbooking.com/www/backstage/scripts/cron-process-tick.sh
#
# Same shape as cron-expire-holds.sh: flock(1) so an overrun never overlaps,
# timestamped output appended to storage/logs/process-tick.log, PATH set
# explicitly because cron starts with a minimal environment. Safe to add to
# cron immediately — process-tick.php only ever touches rows created by a
# real runtime-started instance (Runtime/Engine.php::startInstance()), and
# is a silent no-op ("0 wait(s) past their timeout.") until the first one
# exists.

set -uo pipefail

export PATH="/home/cdr/.local/bin:/usr/local/bin:/usr/bin:/bin"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKSTAGE="$(dirname "$SCRIPT_DIR")"
LOG_DIR="$BACKSTAGE/storage/logs"
LOG_FILE="$LOG_DIR/process-tick.log"
LOCK_FILE="$LOG_DIR/.process-tick.lock"

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
  echo "[$(ts)] process-tick start (pid=$$)"
  php "$SCRIPT_DIR/process-tick.php"
  rc=$?
  if [ $rc -eq 0 ]; then
    echo "[$(ts)] process-tick ok"
  else
    echo "[$(ts)] process-tick FAILED with exit $rc"
  fi
  exit $rc
} >> "$LOG_FILE" 2>&1
