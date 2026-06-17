<?php
declare(strict_types=1);

/**
 * app-id-sync.php — manage the hidden "App ID" link column on the Tracker sheet.
 *
 * The App ID column stores each event's immutable events.id so write-back can
 * locate its row even after the title/date (and therefore the slug) is edited
 * in-app. The column is hidden in the sheet UI but read/written via the API.
 *
 * Subcommands:
 *   inspect                 Print spreadsheet metadata + App ID column state.
 *   ensure-column           Create/label + hide the App ID column (idempotent).
 *   backfill [--dry-run]    Match every event to its sheet row and write the
 *                           app id into the App ID column. --dry-run only reports.
 *   push <id> [<id> ...]    Push app-owned fields for the given event id(s).
 *
 * Matching priority during backfill:
 *   1. Manual overrides (see $OVERRIDES below) — authoritative.
 *   2. external_id  <-> sheet column A (exact).
 *   3. slug         <-> slugify(sheetTitle - sheetDate), truncated to 90.
 * Anything left unmatched is reported and skipped (likely app-native / no row).
 */

require __DIR__ . '/../src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\GoogleSheets;

use function Panic\slugify;

$root = dirname(__DIR__);
Env::load($root . '/.env');

// Manual event_id => sheet_row links for rows that can't be matched
// automatically (e.g. the title was renamed in-app so the slug drifted).
// Per operator decision: event 113 ("Immersive sound experience…") is the real
// occupant of row 112; event 622732 is its duplicate and is intentionally left
// unlinked (not pushed).
$OVERRIDES = [
    113 => 112,
];
$DUPLICATES = [622732]; // known duplicates: never auto-link, never push.

$cmd  = $argv[1] ?? 'inspect';
$args = array_slice($argv, 2);

$sheets = new GoogleSheets($root);
if (!$sheets->isConfigured()) {
    fwrite(STDERR, "GoogleSheets not configured (GOOGLE_SA_KEY_FILE / GOOGLE_SHEET_ID).\n");
    exit(1);
}
$db = new Database();

/** Sheets date serial (days since 1899-12-30) -> Y-m-d, or loose string parse. */
function sheet_date(mixed $v): ?string
{
    if ($v === null || $v === '') return null;
    if (is_int($v) || is_float($v)) {
        return gmdate('Y-m-d', (int) round(((float) $v - 25569) * 86400));
    }
    $ts = strtotime((string) $v);
    return $ts ? date('Y-m-d', $ts) : null;
}

function ev_slug(string $title, ?string $date): string
{
    return substr(slugify($title . '-' . ($date ?? '')), 0, 90);
}

switch ($cmd) {
    case 'inspect': {
        $meta = $sheets->spreadsheetMeta();
        echo "Tabs:\n";
        foreach (($meta['sheets'] ?? []) as $s) {
            $p = $s['properties'] ?? [];
            $g = $p['gridProperties'] ?? [];
            printf("  - %-20s gid=%-12s %dx%d\n", $p['title'] ?? '?', (string)($p['sheetId'] ?? '?'),
                $g['rowCount'] ?? 0, $g['columnCount'] ?? 0);
        }
        $cols = $sheets->batchGetColumns(['A', GoogleSheets::APP_ID_COLUMN]);
        $appCol = $cols[GoogleSheets::APP_ID_COLUMN] ?? [];
        $filled = count(array_filter($appCol, fn ($v) => trim((string) $v) !== ''));
        echo "App ID column: " . GoogleSheets::APP_ID_COLUMN
            . " — {$filled} non-empty cell(s) (incl. header)\n";
        break;
    }

    case 'ensure-column': {
        $ok = $sheets->ensureAppIdColumn();
        echo $ok ? "ok: App ID column ensured + hidden\n" : "FAIL: see sheet-sync.log\n";
        exit($ok ? 0 : 1);
    }

    case 'backfill': {
        $dry = in_array('--dry-run', $args, true);

        $events = $db->all(
            "SELECT id, external_id, slug FROM events ORDER BY id"
        );
        // Read the sheet: A=external_id, D=title, E=date (unformatted serial),
        // plus the App ID column itself so we only write cells that changed.
        $cols = $sheets->batchGetColumns(['A', 'D', 'E', GoogleSheets::APP_ID_COLUMN]);
        if ($cols === null) {
            fwrite(STDERR, "FAIL: could not read sheet columns\n");
            exit(1);
        }
        $colA = $cols['A'] ?? [];
        $colD = $cols['D'] ?? [];
        $colE = $cols['E'] ?? [];
        $maxRows = max(count($colA), count($colD), count($colE));

        // Build lookup maps keyed by row number (1-based).
        $extIdToRow = [];   // upper(extid) => row
        $slugToRow  = [];   // slug => row
        for ($i = 0; $i < $maxRows; $i++) {
            $row = $i + 1;
            if ($row <= GoogleSheets::HEADER_ROW) continue;
            $ext = trim((string) ($colA[$i] ?? ''));
            if ($ext !== '') {
                $extIdToRow[strtoupper($ext)] = $row;
            }
            $title = trim((string) ($colD[$i] ?? ''));
            $date  = sheet_date($colE[$i] ?? null);
            if ($title !== '' && $date !== null) {
                $sl = ev_slug($title, $date);
                // First occurrence wins; collisions are rare and reported below.
                if (!isset($slugToRow[$sl])) $slugToRow[$sl] = $row;
            }
        }

        $rowToId = [];   // row => event_id  (final mapping to write)
        $byMethod = ['override' => 0, 'external_id' => 0, 'slug' => 0];
        $unmatched = [];
        $rowConflicts = [];

        $dupSet = array_flip($DUPLICATES);

        foreach ($events as $e) {
            $id = (int) $e['id'];
            if (isset($dupSet[$id])) {
                continue; // known duplicate: never link
            }
            $row = null; $via = null;

            if (isset($OVERRIDES[$id])) {
                $row = $OVERRIDES[$id]; $via = 'override';
            } else {
                $ext = strtoupper(trim((string) ($e['external_id'] ?? '')));
                if ($ext !== '' && isset($extIdToRow[$ext])) {
                    $row = $extIdToRow[$ext]; $via = 'external_id';
                } elseif (($e['slug'] ?? '') !== '' && isset($slugToRow[$e['slug']])) {
                    $row = $slugToRow[$e['slug']]; $via = 'slug';
                }
            }

            if ($row === null) {
                $unmatched[] = $id;
                continue;
            }
            if (isset($rowToId[$row])) {
                // Two events claim one row. Override/external_id beats slug.
                $rowConflicts[] = ['row' => $row, 'keep' => $rowToId[$row], 'drop' => $id, 'via' => $via];
                continue;
            }
            $rowToId[$row] = $id;
            $byMethod[$via]++;
        }

        printf("Backfill plan: %d events -> rows  (override=%d, external_id=%d, slug=%d)\n",
            count($rowToId), $byMethod['override'], $byMethod['external_id'], $byMethod['slug']);
        printf("Unmatched (no sheet row — likely app-native): %d\n", count($unmatched));
        if ($unmatched) echo '  ids: ' . implode(', ', $unmatched) . "\n";
        if ($rowConflicts) {
            echo "Row conflicts (kept first, dropped dup):\n";
            foreach ($rowConflicts as $c) {
                echo "  row {$c['row']}: kept #{$c['keep']}, dropped #{$c['drop']} (via {$c['via']})\n";
            }
        }
        echo "Duplicates intentionally skipped: " . implode(', ', $DUPLICATES) . "\n";

        // Only write cells that are missing or wrong, so this is cheap to run
        // every sync (typically 0 writes once everything is linked).
        $current = $cols[GoogleSheets::APP_ID_COLUMN] ?? null;
        if ($current === null) {
            $current = ($sheets->batchGetColumns([GoogleSheets::APP_ID_COLUMN])[GoogleSheets::APP_ID_COLUMN] ?? []);
        }
        $toWrite = [];
        foreach ($rowToId as $row => $id) {
            $existing = trim((string) ($current[$row - 1] ?? ''));
            if ($existing !== (string) $id) {
                $toWrite[$row] = $id;
            }
        }

        if ($dry) {
            echo "\n[dry-run] no writes performed. " . count($toWrite) . " cell(s) would change.\n";
            $sample = array_slice($rowToId, 0, 8, true);
            foreach ($sample as $row => $id) echo "  row {$row} <- event {$id}\n";
            break;
        }

        if (!$toWrite) {
            echo "ok: all " . count($rowToId) . " links already current — no writes needed\n";
            break;
        }
        $written = $sheets->writeAppIds($toWrite);
        if ($written < 0) {
            fwrite(STDERR, "FAIL: batch write failed (see sheet-sync.log)\n");
            exit(1);
        }
        echo "ok: wrote {$written} app ids (of " . count($rowToId) . " links) into column " . GoogleSheets::APP_ID_COLUMN . "\n";
        break;
    }

    case 'push': {
        if (!$args) { fwrite(STDERR, "usage: push <id> [<id> ...]\n"); exit(1); }
        $pushable = array_keys(GoogleSheets::FIELD_COLUMN);
        foreach ($args as $idArg) {
            $id = (int) $idArg;
            $ev = $db->one(
                'SELECT status, potential_revenue, ticket_system, contract_url,
                        walkthrough_done, ticket_url, settlement_doc_url
                 FROM events WHERE id = ? LIMIT 1',
                [$id]
            );
            if (!$ev) { echo "  ✗ event #{$id}: not found\n"; continue; }
            $fields = [];
            foreach ($pushable as $f) {
                if (array_key_exists($f, $ev)) $fields[$f] = $ev[$f];
            }
            $ok = $sheets->pushEventByAppId($id, $fields);
            // Keep the outbox consistent with the result.
            if ($ok) {
                $db->run(
                    "INSERT INTO sheet_sync_queue (event_id, status, attempts, pushed_at)
                     VALUES (?, 'done', 1, NOW())
                     ON DUPLICATE KEY UPDATE status='done', attempts=attempts+1, last_error=NULL, pushed_at=NOW()",
                    [$id]
                );
            }
            echo ($ok ? "  ✓" : "  ✗") . " event #{$id}\n";
        }
        break;
    }

    case 'assign-codes': {
        // Assign the next sequential EVT-N code to every event that currently
        // has no external_id. This happens when a sheet row has a blank column A
        // at import time — the importer faithfully copies NULL, and only app-
        // created events call assignEventCode() automatically.
        //
        // Events are processed oldest-first (date ASC, id ASC) so newly-minted
        // codes appear in roughly chronological order. Each assignment re-reads
        // the current MAX to stay race-safe even if another process runs
        // concurrently.  After this completes, run `push-codes` to write the
        // new codes back to column A of the Tracker sheet.

        $missing = $db->all(
            "SELECT id, title, date FROM events
             WHERE external_id IS NULL OR external_id = ''
             ORDER BY date ASC, id ASC"
        );

        if (!$missing) {
            echo "ok: all events already have an EVT code — nothing to assign\n";
            break;
        }

        $assigned = 0; $failed = 0;
        foreach ($missing as $ev) {
            $id = (int) $ev['id'];
            $ok = false;
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $row  = $db->one("SELECT COALESCE(MAX(CAST(SUBSTRING(external_id, 5) AS UNSIGNED)), 0) AS m FROM events WHERE external_id LIKE 'EVT-%'");
                $code = 'EVT-' . (((int) ($row['m'] ?? 0)) + 1);
                try {
                    $db->run('UPDATE events SET external_id = ? WHERE id = ? AND (external_id IS NULL OR external_id = \'\')', [$code, $id]);
                    $ok = true;
                    break;
                } catch (\Throwable $e) {
                    // Unique-index collision — another process grabbed that number; retry.
                }
            }
            if ($ok) {
                $assigned++;
                echo "  assigned {$code} → event #{$id} ({$ev['date']} — " . mb_substr((string) $ev['title'], 0, 50) . ")\n";
            } else {
                $failed++;
                fwrite(STDERR, "  FAIL: could not assign code for event #{$id}\n");
            }
        }

        printf("assign-codes: %d assigned, %d failed (of %d)\n", $assigned, $failed, count($missing));
        if ($failed > 0) exit(1);
        echo "  → run 'push-codes' next to write these codes to column A of the sheet\n";
        break;
    }

    case 'push-codes': {
        // Populate the visible "Event ID" column A with each event's EVT-N code,
        // and relabel its header. Rows are located via the hidden App ID column.
        $cols = $sheets->batchGetColumns(['A', GoogleSheets::APP_ID_COLUMN]);
        if ($cols === null) { fwrite(STDERR, "FAIL: could not read sheet columns\n"); exit(1); }
        $colA = $cols['A'] ?? [];
        $appCol = $cols[GoogleSheets::APP_ID_COLUMN] ?? [];

        // id -> EVT code
        $code = [];
        foreach ($db->all("SELECT id, external_id FROM events WHERE external_id IS NOT NULL") as $r) {
            $code[(int) $r['id']] = (string) $r['external_id'];
        }

        $rowToCode = [];
        foreach ($appCol as $i => $cell) {
            $row = $i + 1;
            $id  = trim((string) $cell);
            if ($id === '' || !ctype_digit($id)) continue;
            $want = $code[(int) $id] ?? null;
            if ($want === null) continue;
            $have = trim((string) ($colA[$i] ?? ''));
            if ($have !== $want) $rowToCode[$row] = $want;
        }

        // Relabel the header cell A{HEADER_ROW} -> "Event ID".
        $sheets->writeColumn('A', [GoogleSheets::HEADER_ROW => 'Event ID']);

        if (!$rowToCode) { echo "ok: all event codes already current in column A\n"; break; }
        $n = $sheets->writeColumn('A', $rowToCode);
        if ($n < 0) { fwrite(STDERR, "FAIL: write codes (see sheet-sync.log)\n"); exit(1); }
        echo "ok: wrote {$n} EVT codes into column A (header relabeled 'Event ID')\n";
        break;
    }

    case 'link-imports': {
        // Write the App ID back into the EXACT sheet row each freshly-created
        // event came from (recorded in sheet_import_links by the importer), then
        // confirm the link. This is the precise, retry-safe counterpart to the
        // slug-heuristic `backfill`: a row anchor + title/date snapshot, never a
        // fuzzy slug guess. Unconfirmed links are retried on every sync, so a
        // transient write failure self-heals without ever creating a duplicate.

        // Be safe if run standalone before the migration/dumper created the table.
        $db->run(
            'CREATE TABLE IF NOT EXISTS sheet_import_links (
                event_id     INT          NOT NULL PRIMARY KEY,
                sheet_row    INT          NOT NULL,
                title_snap   VARCHAR(200) NOT NULL DEFAULT \'\',
                date_snap    DATE         NULL,
                linked       TINYINT(1)   NOT NULL DEFAULT 0,
                created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                confirmed_at TIMESTAMP    NULL,
                KEY idx_unlinked (linked)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pending = $db->all(
            'SELECT event_id, sheet_row, title_snap, date_snap
             FROM sheet_import_links WHERE linked = 0 ORDER BY event_id'
        );
        if (!$pending) {
            echo "ok: no pending import links to write back\n";
            break;
        }

        // Read C (organizer) and D (genre): the event title comes from D, or
        // falls back to C when D is blank (see generate-import-sql.py), so a row
        // is identified by date + (C or D matching the snapshot), never D alone.
        $cols = $sheets->batchGetColumns(['C', 'D', 'E', GoogleSheets::APP_ID_COLUMN]);
        if ($cols === null) {
            fwrite(STDERR, "FAIL: could not read sheet columns\n");
            exit(1);
        }
        $colC   = $cols['C'] ?? [];
        $colD   = $cols['D'] ?? [];
        $colE   = $cols['E'] ?? [];
        $appCol = $cols[GoogleSheets::APP_ID_COLUMN] ?? [];

        $zAt    = fn (int $row): string => trim((string) ($appCol[$row - 1] ?? ''));
        $dateAt = fn (int $row): string => (string) (sheet_date($colE[$row - 1] ?? null) ?? '');
        // The row's title matches the snapshot if either the genre (D) or the
        // organizer (C) equals it — mirrors the importer's title fallback.
        $titleMatches = function (int $row, string $snap) use ($colC, $colD): bool {
            $d = trim((string) ($colD[$row - 1] ?? ''));
            $c = trim((string) ($colC[$row - 1] ?? ''));
            return $snap !== '' && ($d === $snap || $c === $snap);
        };

        // Index still-blank data rows by date for relocation when a captured row
        // shifted; title (C or D) disambiguates rows sharing a date.
        $maxRows = max(count($colC), count($colD), count($colE), count($appCol));
        $blankByDate = []; // date => [rows with empty App ID cell]
        for ($i = 0; $i < $maxRows; $i++) {
            $row = $i + 1;
            if ($row <= GoogleSheets::HEADER_ROW) continue;
            if ($zAt($row) !== '') continue;
            $blankByDate[$dateAt($row)][] = $row;
        }

        $toWrite = [];   // row => event_id (cells to write)
        $confirm = [];   // event_ids whose App ID is already present in the sheet
        $deferred = 0;   // couldn't resolve a row this run — retried next sync
        foreach ($pending as $p) {
            $id    = (int) $p['event_id'];
            $row   = (int) $p['sheet_row'];
            $tSnap = trim((string) ($p['title_snap'] ?? ''));
            $dSnap = $p['date_snap'] !== null ? (string) $p['date_snap'] : '';

            // Already linked in the sheet (write-back previously succeeded, or a
            // human pasted it): just confirm.
            if ($zAt($row) === (string) $id) { $confirm[] = $id; continue; }

            // Captured row still blank AND its date matches (title via C-or-D) →
            // write here. Date is the strong key; title is the tiebreaker.
            if ($zAt($row) === '' && $dateAt($row) === $dSnap && $titleMatches($row, $tSnap)) {
                $toWrite[$row] = $id;
                continue;
            }

            // Row shifted — relocate among still-blank rows sharing the date,
            // disambiguated by the title (C or D) matching the snapshot.
            $cands = array_values(array_filter(
                $blankByDate[$dSnap] ?? [],
                fn ($r) => !isset($toWrite[$r]) && $titleMatches($r, $tSnap)
            ));
            if (count($cands) === 1) {
                $toWrite[$cands[0]] = $id;
            } else {
                // 0 candidates (row gone/renamed) or ambiguous — leave unconfirmed.
                $deferred++;
            }
        }

        $written = 0;
        if ($toWrite) {
            $written = $sheets->writeAppIds($toWrite);
            if ($written < 0) {
                fwrite(STDERR, "FAIL: batch write failed (see sheet-sync.log); will retry next sync\n");
                // Still confirm any already-present links below.
            } else {
                $confirm = array_merge($confirm, array_values($toWrite));
            }
        }

        if ($confirm) {
            $in = implode(',', array_map('intval', $confirm));
            $db->run(
                "UPDATE sheet_import_links SET linked = 1, confirmed_at = NOW()
                 WHERE event_id IN ($in)"
            );
        }

        printf(
            "link-imports: %d pending, %d written, %d already-present, %d deferred (retry next sync)\n",
            count($pending),
            $written < 0 ? 0 : $written,
            count($confirm) - ($written < 0 ? 0 : $written),
            $deferred
        );
        if ($written < 0) exit(1);
        break;
    }

    default:
        fwrite(STDERR, "unknown command: {$cmd}\n");
        fwrite(STDERR, "commands: inspect | ensure-column | backfill [--dry-run] | link-imports | assign-codes | push-codes | push <id>...\n");
        exit(1);
}
