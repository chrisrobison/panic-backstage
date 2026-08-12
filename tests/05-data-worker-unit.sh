#!/usr/bin/env bash
# Hermetic unit tests for public/assets/data-worker.js (see
# tests/data-worker_test.mjs) — mocked Worker global scope, no browser, no
# DB, no live server needed. Runs early (numeric prefix 05) since it has no
# dependency on the auth/server state the later numbered scripts build up.
set -euo pipefail
cd "$(dirname "$0")/.."
node tests/data-worker_test.mjs
