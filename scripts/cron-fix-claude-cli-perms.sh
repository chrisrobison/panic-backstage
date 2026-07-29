#!/usr/bin/env bash
# cron-fix-claude-cli-perms.sh
#
# Keeps ~/.claude/.credentials.json group-readable so the local `claude` CLI
# can authenticate when spawned by PHP-FPM's www-data pool user (see
# Ai\Assistant::runClaude() and Ai\ClaudeCli::prompt() — both shell out to
# the CLI to ride its OAuth/subscription session instead of a billed API
# key). www-data is a member of the `cdr` group, so `chmod g+r` is
# sufficient; no ACLs or setuid needed.
#
# Why this has to be a recurring sweep rather than a one-time fix: the
# `claude` CLI rewrites .credentials.json on its own (token refresh) and
# resets it to mode 600 (owner-only) each time, silently undoing the
# group-read grant. Only the file's owner (cdr) can chmod it back — a
# www-data-owned PHP process can't fix its own read access — so this runs
# frequently as cdr via cron to close the window quickly:
#
#   * * * * * /home/cdr/domains/panicbooking.com/www/backstage/scripts/cron-fix-claude-cli-perms.sh
#
# First diagnosed 2026-07-29: commit 847eb26 (2026-07-25) fixed this once by
# hand-running chmod g+r, but with no recurring enforcement the next token
# refresh silently reverted it and broke /api/ai/ask again days later.
#
# Behavior:
#   - Uses flock(1) so an overrun never overlaps.
#   - Only logs when a permission actually needed fixing (silent no-op
#     otherwise) to keep the log file meaningful.
#   - Sets PATH explicitly because cron starts with a minimal environment.

set -uo pipefail

export PATH="/home/cdr/.local/bin:/usr/local/bin:/usr/bin:/bin"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKSTAGE="$(dirname "$SCRIPT_DIR")"
LOG_DIR="$BACKSTAGE/storage/logs"
LOG_FILE="$LOG_DIR/fix-claude-cli-perms.log"
LOCK_FILE="$LOG_DIR/.fix-claude-cli-perms.lock"

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

CRED_FILE="$HOME/.claude/.credentials.json"

if [ -f "$CRED_FILE" ]; then
  PERMS="$(stat -c%a "$CRED_FILE" 2>/dev/null || echo "")"
  # Group-read bit (mode & 040) missing -> www-data can't read it.
  if [ -n "$PERMS" ] && [ $(( 0$PERMS & 040 )) -eq 0 ]; then
    if chmod g+r "$CRED_FILE" 2>>"$LOG_FILE"; then
      echo "[$(ts)] fixed $CRED_FILE (was $PERMS, now $(stat -c%a "$CRED_FILE"))" >> "$LOG_FILE"
    else
      echo "[$(ts)] FAILED to chmod $CRED_FILE (was $PERMS)" >> "$LOG_FILE"
    fi
  fi
fi

exit 0
