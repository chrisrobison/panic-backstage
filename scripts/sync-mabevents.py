#!/usr/bin/env python3
"""
sync-mabevents.py

Idempotent sync of the live MabEvents Google Sheet into panic_backstage.

Pipeline:
  1. Download the sheet as .xlsx via the public Google Sheets export URL
     → writes <project root>/MabEvents.xlsx (overwrites)
  2. Re-run generate-import-sql.py to regenerate database/mabevents-import.sql
     with idempotent UPSERT statements
  3. Run import-mabevents.php to apply the SQL to the local DB

Re-running this script is safe: events are UPSERTed by slug (status is
preserved), staff are UPSERTed by email, and load-in/curfew schedule rows
are refreshed per event. Rows that disappear from the sheet are left alone.

Usage:
  python3 backstage/scripts/sync-mabevents.py            # full sync
  python3 backstage/scripts/sync-mabevents.py --dry-run  # download + generate SQL, skip DB apply
  python3 backstage/scripts/sync-mabevents.py --no-download   # use existing local MabEvents.xlsx
  python3 backstage/scripts/sync-mabevents.py --sheet-id <ID> # override default sheet

Requires:
  pip3 install openpyxl
  php available on PATH
"""

from __future__ import annotations

import argparse
import shutil
import subprocess
import sys
import urllib.error
import urllib.request
from pathlib import Path

# ── Paths ─────────────────────────────────────────────────────────────────────
SCRIPT_DIR   = Path(__file__).resolve().parent
BACKSTAGE    = SCRIPT_DIR.parent
PROJECT_ROOT = BACKSTAGE.parent
XLSX_PATH    = PROJECT_ROOT / 'MabEvents.xlsx'
GENERATE_PY  = SCRIPT_DIR / 'generate-import-sql.py'
IMPORT_PHP   = SCRIPT_DIR / 'import-mabevents.php'
DUMP_PHP     = SCRIPT_DIR / 'dump-sheet-shadow.php'
APPID_PHP    = SCRIPT_DIR / 'app-id-sync.php'

# ── Default sheet ─────────────────────────────────────────────────────────────
# Source of truth: the shared "MabEvents" Google Sheet.
# Override on the CLI with --sheet-id <ID> or --url <full-export-url>.
DEFAULT_SHEET_ID = '1STS6et19iDHxtLvK2HVfqmAzs1HUa9GgF25KqBikRRE'

def export_url(sheet_id: str) -> str:
    return f'https://docs.google.com/spreadsheets/d/{sheet_id}/export?format=xlsx'

# ── Steps ─────────────────────────────────────────────────────────────────────

def download(url: str, dest: Path) -> None:
    print(f"→ Downloading sheet …")
    print(f"    from: {url}")
    print(f"    to  : {dest}")
    tmp = dest.with_suffix(dest.suffix + '.tmp')
    try:
        req = urllib.request.Request(url, headers={'User-Agent': 'mabevents-sync/1.0'})
        with urllib.request.urlopen(req, timeout=60) as resp, open(tmp, 'wb') as out:
            shutil.copyfileobj(resp, out)
    except urllib.error.HTTPError as e:
        sys.exit(
            f"ERROR: HTTP {e.code} downloading sheet.\n"
            f"  Confirm the sheet is shared with 'Anyone with the link – Viewer'\n"
            f"  and that the sheet ID is correct."
        )
    except urllib.error.URLError as e:
        sys.exit(f"ERROR: network error downloading sheet: {e.reason}")

    # Sanity check: xlsx files start with the ZIP signature 'PK\x03\x04'.
    with open(tmp, 'rb') as fh:
        magic = fh.read(4)
    if magic[:2] != b'PK':
        tmp.unlink(missing_ok=True)
        sys.exit(
            "ERROR: downloaded file is not an .xlsx (got HTML, likely a sign-in page).\n"
            "  The sheet is probably not publicly shared. Update its sharing to\n"
            "  'Anyone with the link – Viewer' and retry."
        )
    tmp.replace(dest)
    size_kb = dest.stat().st_size / 1024
    print(f"    ok   ({size_kb:,.1f} KB)")

def dump_shadow() -> None:
    print(f"→ Dumping sheet-shadow baseline via {DUMP_PHP.name} …")
    result = subprocess.run(['php', str(DUMP_PHP)])
    if result.returncode != 0:
        sys.exit(f"ERROR: {DUMP_PHP.name} exited {result.returncode}")

def generate_sql() -> None:
    print(f"→ Regenerating SQL via {GENERATE_PY.name} …")
    result = subprocess.run([sys.executable, str(GENERATE_PY)])
    if result.returncode != 0:
        sys.exit(f"ERROR: {GENERATE_PY.name} exited {result.returncode}")

def backfill_app_ids() -> None:
    # Links any newly-inserted events back to their sheet rows (writes the App ID
    # column). Best-effort: a failure here doesn't invalidate the import.
    print(f"→ Backfilling App IDs via {APPID_PHP.name} …")
    result = subprocess.run(['php', str(APPID_PHP), 'backfill'])
    if result.returncode != 0:
        print(f"  WARNING: {APPID_PHP.name} backfill exited {result.returncode} (non-fatal)")

def apply_sql() -> None:
    print(f"→ Applying SQL via {IMPORT_PHP.name} …")
    result = subprocess.run(['php', str(IMPORT_PHP)])
    if result.returncode != 0:
        sys.exit(f"ERROR: {IMPORT_PHP.name} exited {result.returncode}")

# ── Main ──────────────────────────────────────────────────────────────────────

def main() -> None:
    p = argparse.ArgumentParser(description=__doc__.split('\n\n')[0].strip())
    p.add_argument('--sheet-id', default=DEFAULT_SHEET_ID,
                   help='Google Sheet ID (default: hardcoded MabEvents sheet)')
    p.add_argument('--url', default=None,
                   help='Full export URL override (takes precedence over --sheet-id)')
    p.add_argument('--no-download', action='store_true',
                   help='Skip download; use existing local MabEvents.xlsx')
    p.add_argument('--dry-run', action='store_true',
                   help='Download and regenerate SQL but skip the DB apply step')
    args = p.parse_args()

    if not args.no_download:
        url = args.url or export_url(args.sheet_id)
        download(url, XLSX_PATH)
    else:
        if not XLSX_PATH.exists():
            sys.exit(f"ERROR: --no-download given but {XLSX_PATH} does not exist")
        print(f"→ Skipping download; using existing {XLSX_PATH}")

    dump_shadow()
    generate_sql()

    if args.dry_run:
        print("→ --dry-run set; skipping DB apply.")
        print("  Generated SQL is at backstage/database/mabevents-import.sql")
        return

    apply_sql()
    backfill_app_ids()
    print("✓ Sync complete.")

if __name__ == '__main__':
    main()
