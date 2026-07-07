#!/usr/bin/env bash
# prune-db-history.sh
#
# Deletes db_history rows older than 30 days, in small batches so a large
# backlog never holds one long-running DELETE/lock. Meant to run once a day.
#
#   30 4 * * * /home/cdr/domains/panicbooking.com/www/backstage/scripts/prune-db-history.sh
#
# The 5-minute DB snapshot repo (/home/cdr/db-backups/panic-snapshots) gives
# longer-term, whole-table point-in-time recovery; db_history is for recent,
# row-level "what changed and how do I undo it" investigation, so 30 days is
# plenty without letting it grow unbounded on a disk that's already tight.

set -uo pipefail
export PATH="/home/cdr/.local/bin:/usr/local/bin:/usr/bin:/bin"

BACKSTAGE="/home/cdr/domains/panicbooking.com/www/backstage"
LOG_DIR="$BACKSTAGE/storage/logs"
LOG_FILE="$LOG_DIR/prune-db-history.log"
LOCK_FILE="$LOG_DIR/.prune-db-history.lock"
RETENTION_DAYS=30
BATCH_SIZE=5000

mkdir -p "$LOG_DIR"
exec 9>"$LOCK_FILE"
flock -n 9 || exit 0

ts() { date '+%Y-%m-%d %H:%M:%S'; }

{
  echo "[$(ts)] prune start (retention=${RETENTION_DAYS}d, batch=${BATCH_SIZE})"
  total=0
  while :; do
    deleted=$(mysql -u root panic_backstage -N -B -e "
      DELETE FROM db_history WHERE created_at < NOW() - INTERVAL ${RETENTION_DAYS} DAY LIMIT ${BATCH_SIZE};
      SELECT ROW_COUNT();
    " | tail -1)
    total=$((total + deleted))
    if [ "$deleted" -lt "$BATCH_SIZE" ]; then
      break
    fi
  done
  echo "[$(ts)] prune done, deleted ${total} row(s)"
} >> "$LOG_FILE" 2>&1
