<?php
declare(strict_types=1);

/**
 * Two-way sync between staff_members and the 'Staff Contact' tab of the
 * MabEvents workbook. Intended to run from cron-sync.sh every 5 minutes.
 *
 *   php scripts/sync-staff.php            # reconcile
 *   php scripts/sync-staff.php --verbose
 *
 * Linking key: a hidden "App ID (Staff)" column (col Z) holds staff_members.id,
 * mirroring the Tracker tab's App ID column. This is what makes the sync stable
 * and duplicate-free across edits.
 *
 * Reconcile rules (per the agreed design):
 *   - Linked row (col Z set):  gap-fill both ways. A non-empty SHEET cell wins
 *     into the app; a non-empty APP value fills an EMPTY sheet cell. Nothing is
 *     ever blanked, so neither side destroys the other's data.
 *   - Unlinked sheet row:      imported only when it has a real email (not
 *     blank, not '*.local'); creates/links a staff_members row.
 *   - App staff not on a sheet: appended (real-email, active staff only).
 *
 * Staff Contact columns (header row 1, data row 2+). The tab is only 17 columns
 * wide (A–Q), so the App ID lives at K (not Z like the Tracker tab):
 *   A Department  B Fname  C Lname  D Pronoun  E Staffing Status
 *   F Phone  G Email  H Position  I Staffing Notes  J Hire Date  K App ID
 */

require __DIR__ . '/../src/bootstrap.php';

use Panic\Database;
use Panic\Env;
use Panic\GoogleSheets;

$root = dirname(__DIR__);
Env::load($root . '/.env');

$verbose = in_array('--verbose', array_slice($argv, 1), true);
$TAB        = getenv('GOOGLE_STAFF_TAB') ?: 'Staff Contact';
$HEADER_ROW = 1;
$DATA_START = 2;
$APPID_COL  = 'K';   // 'Staff Contact' is only 17 cols wide; Z is out of grid
$HIRE_COL   = 'J';

$db     = new Database();
$sheets = new GoogleSheets($root);

if (!$sheets->isConfigured()) {
    fwrite(STDERR, "GoogleSheets not configured. Nothing to do.\n");
    exit(0);
}

// ── helpers ──────────────────────────────────────────────────────────────────
$ROLES = ['manager','security','bartender','barback','door','sound','lighting','stagehand','runner','cleaner','other'];

$mapDept = function (string $dept) use ($ROLES): string {
    $d = strtolower(trim($dept));
    if ($d === '') return 'other';
    if (str_contains($d, 'barback')) return 'barback';
    if (str_contains($d, 'bar'))     return 'bartender';
    if (str_contains($d, 'sound'))   return 'sound';
    if (str_contains($d, 'light'))   return 'lighting';
    if (str_contains($d, 'secur'))   return 'security';
    if (str_contains($d, 'door'))    return 'door';
    if (str_contains($d, 'manage'))  return 'manager';
    if (str_contains($d, 'stage'))   return 'stagehand';
    if (str_contains($d, 'run'))     return 'runner';
    if (str_contains($d, 'clean'))   return 'cleaner';
    return in_array($d, $ROLES, true) ? $d : 'other';
};
$deptLabel = function (string $role): string {
    return [
        'bartender' => 'Bar', 'barback' => 'Barback', 'sound' => 'Sound', 'lighting' => 'Light',
        'security' => 'Security', 'door' => 'Door', 'manager' => 'Manager', 'stagehand' => 'Stage',
        'runner' => 'Runner', 'cleaner' => 'Cleaning', 'other' => '',
    ][$role] ?? '';
};
$splitName = function (string $name): array {
    $name = trim($name);
    if ($name === '') return ['', ''];
    $p = preg_split('/\s+/', $name, 2);
    return [$p[0], $p[1] ?? ''];
};
$parseDate = function (string $v): ?string {
    $v = trim($v);
    if ($v === '') return null;
    $ts = strtotime($v);
    return $ts !== false ? date('Y-m-d', $ts) : null;
};
$realEmail = function (string $email): bool {
    $e = strtolower(trim($email));
    return $e !== '' && str_contains($e, '@') && !str_ends_with($e, '.local');
};

// ── ensure the sheet has the App ID + Hire Date columns ──────────────────────
$sheets->ensureGridColumn($TAB, $HIRE_COL, 'Hire Date', $HEADER_ROW, false);
$sheets->ensureGridColumn($TAB, $APPID_COL, 'App ID (Staff) — do not edit', $HEADER_ROW, true);

// ── read the whole grid ──────────────────────────────────────────────────────
$grid = $sheets->readGrid($TAB, 'A1:Q');
if ($grid === null) {
    fwrite(STDERR, "Could not read '{$TAB}' — aborting (will retry next run).\n");
    exit(1);
}

$cell = fn (array $row, int $i): string => isset($row[$i]) ? trim((string) $row[$i]) : '';

$linked = [];        // app id => sheet row number
$cellWrites = [];    // batched sheet cell writes (linking + gap-fill)
$created = 0; $updated = 0; $skippedBlank = 0; $skippedNoEmail = 0; $appended = 0;

for ($i = $DATA_START - 1; $i < count($grid); $i++) {
    $row = is_array($grid[$i] ?? null) ? $grid[$i] : [];
    $sheetRow = $i + 1;

    $dept   = $cell($row, 0);
    $fname  = $cell($row, 1);
    $lname  = $cell($row, 2);
    $pronoun= $cell($row, 3);
    $phone  = $cell($row, 5);
    $email  = strtolower($cell($row, 6));
    $position = $cell($row, 7);
    $notes  = $cell($row, 8);
    $hire   = $parseDate($cell($row, 9));
    $appIdRaw = $cell($row, 10); // column K

    $name = trim("$fname $lname");
    $role = $mapDept($dept);

    // ── linked row: gap-fill both directions ────────────────────────────────
    if (ctype_digit($appIdRaw)) {
        $appId = (int) $appIdRaw;
        $st = $db->one('SELECT * FROM staff_members WHERE id = ?', [$appId]);
        if (!$st) {
            continue; // app deleted this staff; leave the sheet row alone
        }
        $linked[$appId] = $sheetRow;

        // sheet -> app: non-empty sheet cell wins
        $set = []; $vals = [];
        $apply = function (string $col, $val) use (&$set, &$vals) { $set[] = "$col = ?"; $vals[] = $val; };
        if ($name !== '')     $apply('name', $name);
        if ($dept !== '')     $apply('default_role', $role);
        if ($pronoun !== '')  $apply('pronoun', $pronoun);
        if ($phone !== '')    $apply('phone', $phone);
        if ($email !== '')    $apply('email', $email);
        if ($position !== '') $apply('position', $position);
        if ($notes !== '')    $apply('notes', $notes);
        if ($hire !== null)   $apply('hire_date', $hire);
        if ($set) {
            $vals[] = $appId;
            $db->run('UPDATE staff_members SET ' . implode(', ', $set) . ' WHERE id = ?', $vals);
            $updated++;
        }

        // app -> sheet: fill EMPTY sheet cells from app values
        if ($fname === '' && trim((string) $st['name']) !== '') {
            [$f, $l] = $splitName((string) $st['name']);
            $cellWrites['B' . $sheetRow] = $f;
            if ($l !== '') $cellWrites['C' . $sheetRow] = $l;
        }
        if ($dept === '' && $st['default_role']) { $lbl = $deptLabel((string) $st['default_role']); if ($lbl !== '') $cellWrites['A' . $sheetRow] = $lbl; }
        if ($pronoun === '' && $st['pronoun'])   $cellWrites['D' . $sheetRow] = (string) $st['pronoun'];
        if ($phone === '' && $st['phone'])       $cellWrites['F' . $sheetRow] = (string) $st['phone'];
        if ($email === '' && $realEmail((string) $st['email'])) $cellWrites['G' . $sheetRow] = (string) $st['email'];
        if ($position === '' && $st['position']) $cellWrites['H' . $sheetRow] = (string) $st['position'];
        if ($notes === '' && $st['notes'])       $cellWrites['I' . $sheetRow] = (string) $st['notes'];
        if ($cell($row, 9) === '' && $st['hire_date']) $cellWrites['J' . $sheetRow] = (string) $st['hire_date'];
        continue;
    }

    // ── unlinked sheet row: adopt an existing record (by email, then name),
    //    or create a new one only when the row has a real email ───────────────
    if ($name === '') { $skippedBlank++; continue; }

    $match = null;
    if ($realEmail($email)) {
        $match = $db->one('SELECT id FROM staff_members WHERE email = ? LIMIT 1', [$email]);
    }
    if (!$match) {
        // Name match links pre-existing rows (e.g. an earlier import) that have
        // no real email yet — without it they could never be linked.
        $match = $db->one('SELECT id FROM staff_members WHERE LOWER(name) = LOWER(?) LIMIT 1', [$name]);
    }

    if ($match) {
        $appId = (int) $match['id'];
    } elseif ($realEmail($email)) {
        $appId = $db->insert(
            'INSERT INTO staff_members (name, email, phone, pronoun, default_role, position, notes, hire_date, active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)',
            [$name, $email, $phone ?: null, $pronoun ?: null, $role, $position ?: null, $notes ?: null, $hire]
        );
        $created++;
    } else {
        $skippedNoEmail++; // no existing record + no real email -> don't import junk
        continue;
    }
    $cellWrites[$APPID_COL . $sheetRow] = (string) $appId; // link the row
    $linked[$appId] = $sheetRow;
    if ($verbose) echo "  + sheet row {$sheetRow} -> staff #{$appId} ({$name})\n";
}

// flush sheet writes (links + gap-fill)
if ($cellWrites) {
    $sheets->batchWriteCells($TAB, $cellWrites);
}

// ── app -> sheet: append real-email active staff not already on a sheet row ──
$appStaff = $db->all('SELECT * FROM staff_members WHERE active = 1');
foreach ($appStaff as $st) {
    $id = (int) $st['id'];
    if (isset($linked[$id])) continue;
    if (!$realEmail((string) $st['email'])) continue; // only real-email staff go to the sheet
    [$f, $l] = $splitName((string) $st['name']);
    $colValues = array_filter([
        'A' => $deptLabel((string) $st['default_role']),
        'B' => $f,
        'C' => $l,
        'D' => (string) ($st['pronoun'] ?? ''),
        'F' => (string) ($st['phone'] ?? ''),
        'G' => strtolower((string) $st['email']),
        'H' => (string) ($st['position'] ?? ''),
        'I' => (string) ($st['notes'] ?? ''),
        'J' => (string) ($st['hire_date'] ?? ''),
        'K' => (string) $id,
    ], fn ($v) => $v !== '');
    if ($sheets->appendGridRow($TAB, $colValues, 'A' . $HEADER_ROW . ':Q')) {
        $appended++;
        if ($verbose) echo "  ^ staff #{$id} ({$st['email']}) appended to sheet\n";
    }
}

printf(
    "staff sync: %d created, %d updated, %d appended; skipped %d blank + %d no-real-email rows\n",
    $created, $updated, $appended, $skippedBlank, $skippedNoEmail
);
