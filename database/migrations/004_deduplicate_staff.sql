-- =============================================================
-- Migration 004: Deduplicate staff_members
--
-- Root cause: mabevents-import.sql was re-run after the first
-- import had created records with NULL emails (IDs 10-18).
-- The NOT EXISTS guard checked by email only, so records with
-- NULL email were not matched and new rows (IDs 20-28) were
-- inserted with *.mabuhay.local addresses.
--
-- Fix:
--   1. Copy .local emails onto the surviving (lower-ID) records.
--   2. Copy the user_id onto Chale (ID 10 had NULL user_id).
--   3. Reassign any event_staffing shifts (none exist, but safe
--      to run regardless).
--   4. Delete the duplicate records (IDs 20-28).
-- =============================================================

START TRANSACTION;

-- ── Reassign any event_staffing shifts to surviving records ────
UPDATE event_staffing SET staff_member_id = 10 WHERE staff_member_id = 20;
UPDATE event_staffing SET staff_member_id = 11 WHERE staff_member_id = 21;
UPDATE event_staffing SET staff_member_id = 12 WHERE staff_member_id = 22;
UPDATE event_staffing SET staff_member_id = 13 WHERE staff_member_id = 23;
UPDATE event_staffing SET staff_member_id = 14 WHERE staff_member_id = 24;
UPDATE event_staffing SET staff_member_id = 15 WHERE staff_member_id = 25;
UPDATE event_staffing SET staff_member_id = 16 WHERE staff_member_id = 26;
UPDATE event_staffing SET staff_member_id = 17 WHERE staff_member_id = 27;
UPDATE event_staffing SET staff_member_id = 18 WHERE staff_member_id = 28;

-- ── Backfill emails onto surviving records ─────────────────────
UPDATE staff_members SET email = 'chale@staff.mabuhay.local',            user_id = 47899 WHERE id = 10;
UPDATE staff_members SET email = 'will@staff.mabuhay.local'                               WHERE id = 11;
UPDATE staff_members SET email = 'max@staff.mabuhay.local'                                WHERE id = 12;
UPDATE staff_members SET email = 'valyre@staff.mabuhay.local'                             WHERE id = 13;
UPDATE staff_members SET email = 'case.newcomb@staff.mabuhay.local'                       WHERE id = 14;
UPDATE staff_members SET email = 'deanne@staff.mabuhay.local'                             WHERE id = 15;
UPDATE staff_members SET email = 'carmen.caruso@staff.mabuhay.local'                      WHERE id = 16;
UPDATE staff_members SET email = 'justin.vangegas@staff.mabuhay.local'                    WHERE id = 17;
UPDATE staff_members SET email = 'christopher.luigi@staff.mabuhay.local'                  WHERE id = 18;

-- ── Delete duplicate records ───────────────────────────────────
DELETE FROM staff_members WHERE id IN (20, 21, 22, 23, 24, 25, 26, 27, 28);

COMMIT;
