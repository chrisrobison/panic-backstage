#!/usr/bin/env bash
# Durable application jobs. Install once per minute:
# * * * * * /home/cdr/domains/panicbooking.com/www/backstage/scripts/cron-background-jobs.sh

set -uo pipefail

export PATH="/home/cdr/.local/bin:/usr/local/bin:/usr/bin:/bin"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKSTAGE="$(dirname "$SCRIPT_DIR")"
LOG_DIR="$BACKSTAGE/storage/logs"
LOG_FILE="$LOG_DIR/background-jobs.log"
LOCK_FILE="$LOG_DIR/.background-jobs.lock"

mkdir -p "$LOG_DIR"
exec 9>"$LOCK_FILE"
flock -n 9 || exit 0

if [ -f "$LOG_FILE" ] && [ "$(stat -c%s "$LOG_FILE" 2>/dev/null || echo 0)" -gt 1048576 ]; then
  tail -n 500 "$LOG_FILE" > "$LOG_FILE.tmp" && mv "$LOG_FILE.tmp" "$LOG_FILE"
fi

{
  date '+[%Y-%m-%d %H:%M:%S] background jobs start'
  if [ -n "${SUPER_DB_NAME:-}" ]; then
    php "$SCRIPT_DIR/run-background-jobs.php" tenants --limit=50
  else
    php "$SCRIPT_DIR/run-background-jobs.php" single --limit=50
  fi
} >> "$LOG_FILE" 2>&1
