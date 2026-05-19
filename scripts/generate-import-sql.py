#!/usr/bin/env python3
"""
generate-import-sql.py

Reads MabEvents.xlsx (project root) and regenerates
backstage/database/mabevents-import.sql.

Usage:
    python3 backstage/scripts/generate-import-sql.py

Requires openpyxl:
    pip3 install openpyxl
"""

import openpyxl
import re
import sys
from datetime import datetime, date, time
from pathlib import Path

SCRIPT_DIR   = Path(__file__).resolve().parent
BACKSTAGE    = SCRIPT_DIR.parent
PROJECT_ROOT = BACKSTAGE.parent
XLSX_PATH    = PROJECT_ROOT / 'MabEvents.xlsx'
OUTPUT_SQL   = BACKSTAGE / 'database' / 'mabevents-import.sql'

# ── helpers ──────────────────────────────────────────────────────────────────

def esc(v):
    if v is None:
        return 'NULL'
    v = str(v).replace('\\', '\\\\').replace("'", "\\'").strip()
    return f"'{v}'"

def fmt_time(v):
    if isinstance(v, time):
        return f"'{v.strftime('%H:%M:%S')}'"
    if isinstance(v, datetime):
        return f"'{v.strftime('%H:%M:%S')}'"
    return 'NULL'

def slugify(s: str) -> str:
    s = re.sub(r'[^a-z0-9]+', '-', s.lower().strip())
    return re.sub(r'-+', '-', s).strip('-')

STATUS_MAP = {
    'booked': 'confirmed',
    'in negotiations': 'hold',
    'cancelled': 'canceled',
    'canceled': 'canceled',
    'prospect': 'proposed',
    'paid deposit': 'confirmed',
    'paid in full': 'confirmed',
    'archived': 'completed',
    'hold': 'hold',
}

def map_status(raw_status, ext_id_raw) -> str:
    if ext_id_raw and 'hold' in str(ext_id_raw).lower():
        return 'hold'
    if raw_status is None:
        return 'proposed'
    return STATUS_MAP.get(str(raw_status).strip().lower(), 'proposed')

def guess_event_type(genre, event_type_raw) -> str:
    if event_type_raw == 'Private':
        return 'private_event'
    g = (genre or '').lower()
    if 'karaoke' in g:
        return 'karaoke'
    if any(k in g for k in ['comedy', 'clown']):
        return 'comedy'
    if any(k in g for k in [' dj ', 'dj-', 'dj set', 'dj event']):
        return 'dj_night'
    if 'open mic' in g:
        return 'open_mic'
    if any(k in g for k in ['bachata', 'swing lesson', 'swing dancing', 'dance class']):
        return 'special_event'
    return 'live_music'

def clean_ext_id(v):
    if v is None:
        return None
    s = str(v).strip()
    return s if re.match(r'^EVT-\d+', s) else None

# ── Load workbook ─────────────────────────────────────────────────────────────

if not XLSX_PATH.exists():
    print(f"ERROR: {XLSX_PATH} not found.", file=sys.stderr)
    sys.exit(1)

wb = openpyxl.load_workbook(str(XLSX_PATH))

# ── Parse Tracker ─────────────────────────────────────────────────────────────

ws = wb['Tracker']
used_slugs: set[str] = set()

def unique_slug(base: str) -> str:
    slug = slugify(base)[:90]
    if slug not in used_slugs:
        used_slugs.add(slug)
        return slug
    i = 2
    while f"{slug}-{i}" in used_slugs:
        i += 1
    final = f"{slug}-{i}"
    used_slugs.add(final)
    return final

events_rows = []
skipped = 0

for row in ws.iter_rows(min_row=2, values_only=True):
    raw_ext_id, referral, organizer, genre, raw_date, potential_rev, \
        t_load_in, t_doors, t_close, t_lockup, crowd_size, venue_room, \
        status_raw, event_type_raw, ticket_sys, contract_link, invoice_link, \
        settlement_doc, notes = row[:19]

    if not isinstance(raw_date, (datetime, date)):
        skipped += 1
        continue
    if not genre and not organizer:
        skipped += 1
        continue

    title    = (str(genre).strip() if genre else str(organizer).strip())[:200]
    date_str = raw_date.strftime('%Y-%m-%d')
    slug     = unique_slug(title + '-' + date_str)

    ext_id = clean_ext_id(raw_ext_id)
    status = map_status(status_raw, raw_ext_id)
    etype  = guess_event_type(genre, event_type_raw)
    pub    = 1 if event_type_raw == 'Public' else 0

    room = None
    if venue_room:
        r = str(venue_room).strip().lower()
        if 'upstairs' in r:
            room = 'upstairs'
        elif 'downstairs' in r:
            room = 'downstairs'
        elif 'both' in r:
            room = 'both'

    int_parts = []
    if notes and str(notes).strip():
        int_parts.append(str(notes).strip())
    if ticket_sys and str(ticket_sys).strip():
        int_parts.append(f"Ticket system: {ticket_sys}")
    if potential_rev:
        try:
            int_parts.append(f"Potential revenue: ${float(potential_rev):,.0f}")
        except Exception:
            int_parts.append(f"Potential revenue: {potential_rev}")
    if contract_link and str(contract_link).strip():
        int_parts.append(f"Contract: {contract_link}")
    desc_int = '\n'.join(int_parts) if int_parts else None

    cap = None
    if crowd_size is not None:
        try:
            cap = int(float(str(crowd_size).replace(',', '').split('/')[0].strip()))
        except Exception:
            pass

    events_rows.append({
        'title': title, 'slug': slug, 'etype': etype, 'status': status,
        'desc_int': desc_int, 'date': date_str,
        'doors': fmt_time(t_doors), 'end_t': fmt_time(t_close),
        'cap': str(cap) if cap else 'NULL', 'pub': pub,
        'ext_id': ext_id,
        'ref': str(referral).strip() if referral else None,
        'prom': str(organizer).strip() if organizer else None,
        'room': room,
        'load_in': fmt_time(t_load_in),
        'lockup': fmt_time(t_lockup),
    })

# ── Parse Staff Contact ───────────────────────────────────────────────────────

ws2 = wb['Staff Contact']
staff_rows = []

for row in ws2.iter_rows(min_row=2, values_only=True):
    dept, fname, lname, pronoun, st, phone, email, position, n2 = row[:9]
    if not fname:
        continue
    full = (str(fname).strip() + (' ' + str(lname).strip() if lname else '')).strip()
    if not full:
        continue
    if not email:
        email = re.sub(r'[^a-z0-9]', '.', full.lower()).strip('.') + '@staff.mabuhay.local'
    staff_rows.append({
        'name': full,
        'email': str(email).strip(),
        'dept': str(dept).strip() if dept else None,
        'phone': str(phone).strip() if phone else None,
    })

# ── Build SQL ─────────────────────────────────────────────────────────────────

out = [
    "-- =============================================================",
    "-- MabEvents.xlsx import (idempotent UPSERT)",
    "-- Run AFTER: schema.sql, migration 001, migration 002",
    f"-- Events: {len(events_rows)}  |  Staff: {len(staff_rows)}",
    "--",
    "-- Idempotency keys:",
    "--   users  : email (UNIQUE)            — name refreshed; role/password preserved",
    "--   events : slug  (UNIQUE)            — all fields refreshed EXCEPT status",
    "--                                        (local workflow state wins)",
    "--   event_schedule_items : delete+reinsert per event for ('load_in','curfew')",
    "-- =============================================================",
    "",
    "START TRANSACTION;",
    "",
    "-- Resolve venue and default owner",
    "SET @venue_id = (SELECT id FROM venues WHERE slug = 'mabuhay-gardens' LIMIT 1);",
    "-- Owner: legacy seed admin if present, else the lowest-id venue_admin.",
    "SET @owner_id = COALESCE(",
    "  (SELECT id FROM users WHERE email = 'admin@mabuhay.local' LIMIT 1),",
    "  (SELECT id FROM users WHERE role = 'venue_admin' ORDER BY id LIMIT 1)",
    ");",
    "",
    "-- ── Staff ──────────────────────────────────────────────────────",
]

for s in staff_rows:
    out.append(
        f"INSERT INTO users (name, email, password_hash, role) VALUES "
        f"({esc(s['name'])}, {esc(s['email'])}, NULL, 'staff') "
        f"ON DUPLICATE KEY UPDATE name = VALUES(name);"
    )

out.extend(["", "-- ── Events ─────────────────────────────────────────────────────"])

# Columns updated on an existing row (everything from the sheet EXCEPT status,
# which the user asked us to preserve so local workflow progress isn't reverted).
EVENT_UPDATE_COLS = [
    'venue_id', 'title', 'event_type', 'description_internal',
    'date', 'doors_time', 'end_time', 'capacity', 'public_visibility',
    'owner_user_id', 'external_id', 'referral_source', 'promoter_name', 'room',
]
event_update_clause = ',\n    '.join(
    [f"{c} = VALUES({c})" for c in EVENT_UPDATE_COLS]
)

for ev in events_rows:
    out.append(
        f"INSERT INTO events (venue_id, title, slug, event_type, status, "
        f"description_internal, date, doors_time, end_time, capacity, "
        f"public_visibility, owner_user_id, external_id, referral_source, "
        f"promoter_name, room) VALUES ("
        f"@venue_id, {esc(ev['title'])}, {esc(ev['slug'])}, "
        f"'{ev['etype']}', '{ev['status']}', {esc(ev['desc_int'])}, "
        f"'{ev['date']}', {ev['doors']}, {ev['end_t']}, {ev['cap']}, "
        f"{ev['pub']}, @owner_id, {esc(ev['ext_id'])}, "
        f"{esc(ev['ref'])}, {esc(ev['prom'])}, {esc(ev['room'])}"
        f")\n  ON DUPLICATE KEY UPDATE\n    id = LAST_INSERT_ID(id),\n    "
        f"{event_update_clause};"
    )
    # Always resolve the event id (works whether inserted or updated thanks to LAST_INSERT_ID(id) trick).
    out.append("SET @eid = LAST_INSERT_ID();")
    # Wipe prior load_in/curfew rows for this event so removed times in the sheet propagate.
    out.append(
        "DELETE FROM event_schedule_items "
        "WHERE event_id = @eid AND item_type IN ('load_in','curfew');"
    )
    if ev['load_in'] != 'NULL':
        out.append(
            f"INSERT INTO event_schedule_items (event_id, title, item_type, start_time) "
            f"VALUES (@eid, 'Load-in', 'load_in', {ev['load_in']});"
        )
    if ev['lockup'] != 'NULL':
        out.append(
            f"INSERT INTO event_schedule_items (event_id, title, item_type, start_time) "
            f"VALUES (@eid, 'Lock-up / Curfew', 'curfew', {ev['lockup']});"
        )

out.extend(["", "COMMIT;"])

sql = '\n'.join(out)
OUTPUT_SQL.write_text(sql)

print(f"Written: {OUTPUT_SQL}")
print(f"Events:  {len(events_rows)}  (skipped {skipped} rows without a parseable date)")
print(f"Staff:   {len(staff_rows)}")
