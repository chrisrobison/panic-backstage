#!/usr/bin/env bash
# Install all tracked git hooks from scripts/hooks/ into .git/hooks/
set -euo pipefail

HOOKS_SRC="$(cd "$(dirname "$0")" && pwd)"
HOOKS_DST="$(git rev-parse --git-dir)/hooks"

for hook in "$HOOKS_SRC"/post-commit "$HOOKS_SRC"/pre-push; do
  [ -f "$hook" ] || continue
  name=$(basename "$hook")
  cp "$hook" "$HOOKS_DST/$name"
  chmod +x "$HOOKS_DST/$name"
  echo "✅  Installed $name → .git/hooks/$name"
done

echo "Done. Hooks active for this repository."
