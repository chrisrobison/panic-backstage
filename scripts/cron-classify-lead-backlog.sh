#!/usr/bin/env bash
set -uo pipefail

export PATH="/home/cdr/.local/bin:/usr/local/bin:/usr/bin:/bin"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKSTAGE="$(dirname "$SCRIPT_DIR")"
LOG_DIR="$BACKSTAGE/storage/logs"
LOG_FILE="$LOG_DIR/classify-lead-backlog.log"
LOCK_FILE="$LOG_DIR/.classify-lead-backlog.lock"

mkdir -p "$LOG_DIR"
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
  exit 0
fi

if [ -f "$LOG_FILE" ] && [ "$(stat -c%s "$LOG_FILE" 2>/dev/null || echo 0)" -gt 1048576 ]; then
  tail -n 500 "$LOG_FILE" > "$LOG_FILE.tmp" && mv "$LOG_FILE.tmp" "$LOG_FILE"
fi

{
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] classification backlog start (pid=$$)"
  php "$SCRIPT_DIR/classify-lead-backlog.php" --apply --limit=2
  rc=$?
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] classification backlog exit=$rc"
  exit $rc
} >> "$LOG_FILE" 2>&1
