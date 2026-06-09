<?php
declare(strict_types=1);

namespace Panic;

/**
 * Minimal, zero-dependency Google Sheets writer for two-way sync.
 *
 * Authenticates as a *service account* using the standard JWT-bearer flow
 * (RS256 signed with the account's private key via ext-openssl), exchanges the
 * JWT for an OAuth2 access token, and writes individual cells via the Sheets
 * REST API. No Composer packages required.
 *
 * Setup (one-time, done in Google Cloud + the sheet):
 *   1. GCP project -> enable "Google Sheets API".
 *   2. Create a service account, add a JSON key, download it.
 *   3. Share the target spreadsheet with the service account's email
 *      (the "client_email" in the JSON) as an *Editor*.
 *   4. Point .env at the key file + sheet:
 *        GOOGLE_SA_KEY_FILE=/home/cdr/domains/panicbooking.com/secrets/sheets-sa.json
 *        GOOGLE_SHEET_ID=1STS6et19iDHxtLvK2HVfqmAzs1HUa9GgF25KqBikRRE
 *        GOOGLE_SHEET_TAB=Tracker
 *
 * IMPORTANT (learned the hard way): the key file must be *readable by the web
 * user* (www-data) but not world-readable. Store it `-rw-r----- cdr www-data`
 * just like .env, or the push will silently fail with a permission error.
 *
 * Design: every public method swallows its own errors, logs to
 * storage/logs/sheet-sync.log, and returns false on failure so a Google
 * problem never breaks an auth/edit flow. The retry outbox (sheet_sync_queue)
 * picks up anything that returned false.
 *
 * Row matching: the preferred key is the immutable app event id, written into a
 * dedicated, hidden "App ID" column (APP_ID_COLUMN) and located via
 * findRowByAppId(). This survives in-app title/date edits. A legacy path still
 * locates rows by `external_id` (e.g. "EVT-1050") in column A. Backfill/admin of
 * the App ID column lives in scripts/app-id-sync.php.
 *
 * NOTE: FIELD_COLUMN letters assume the Tracker layout where Status=M, Ticket
 * Sys.=O, Contract=P, etc. If a column is inserted/removed in the sheet, these
 * mappings (and the importer's positional unpack) shift — keep them in sync.
 */
final class GoogleSheets
{
    private const SCOPE       = 'https://www.googleapis.com/auth/spreadsheets';
    private const TOKEN_URI   = 'https://oauth2.googleapis.com/token';
    private const API_BASE    = 'https://sheets.googleapis.com/v4/spreadsheets';

    /**
     * App field -> sheet column letter (1-based positions confirmed against
     * generate-import-sql.py). Only app-owned fields are listed; identity
     * columns (external_id, referral, promoter, title, date, room) are
     * deliberately omitted so the sheet stays source-of-truth for those.
     * `deposit_amount` has no sheet column and cannot be pushed.
     */
    public const FIELD_COLUMN = [
        // external_id holds the human-facing event code ("EVT-12"). It is
        // app-owned and pushed into the sheet's visible id column A; the import
        // never reads it back, so the code is stable against sheet edits.
        'external_id'        => 'A',
        'status'             => 'M',
        'potential_revenue'  => 'F',
        'ticket_system'      => 'O',
        'contract_url'       => 'P',
        'walkthrough_done'   => 'Q',
        'ticket_url'         => 'R',
        'settlement_doc_url' => 'S',
    ];

    /**
     * Columns written when APPENDING a brand-new row for an app-created event
     * that has no sheet row yet. Superset of FIELD_COLUMN: also includes the
     * identity columns (referral B, promoter C, title D, date E, capacity K,
     * room L) so the appended row is a complete, human-readable Tracker entry.
     * Positions match the importer's column map in generate-import-sql.py.
     */
    public const APPEND_COLUMN = [
        'external_id'        => 'A',
        'referral_source'    => 'B',
        'promoter_name'      => 'C',
        'title'              => 'D',
        'date'               => 'E',
        'potential_revenue'  => 'F',
        'capacity'           => 'K',
        'room'               => 'L',
        'status'             => 'M',
        'ticket_system'      => 'O',
        'contract_url'       => 'P',
        'walkthrough_done'   => 'Q',
        'ticket_url'         => 'R',
        'settlement_doc_url' => 'S',
    ];

    /**
     * Dedicated, immutable link key: the app's events.id is written into this
     * sheet column so write-back can locate a row even after its title/date
     * (and therefore its slug) is edited in-app. The column is hidden in the
     * sheet UI (see ensureAppIdColumn) but remains fully readable/writable via
     * the API. Kept well clear of the importer's data columns (A–W, 1–23).
     */
    public const APP_ID_COLUMN = 'Z';
    public const APP_ID_HEADER = 'App ID (system — do not edit)';

    /** Header row in the Tracker tab; data starts the row after. */
    public const HEADER_ROW = 2;

    /**
     * App status slug -> human label written into the sheet's Status column.
     *
     * This is the reverse of generate-import-sql.py's STATUS_MAP. It can't be a
     * perfect inverse (several sheet labels — "Booked"/"Paid Deposit"/"Paid in
     * Full" — all import to `confirmed`), so we pick the clearest single label
     * per app status. That's safe: status is preserve-local on import, so the
     * DB is never overwritten by a re-sync — the sheet label is display-only.
     * Round-trips that DO matter still work (Booked->confirmed->"Booked",
     * Prospect->proposed->"Prospect", Cancelled->canceled->"Cancelled").
     * Unknown/new statuses fall back to Title Case of the slug.
     */
    public const STATUS_SHEET_LABEL = [
        'empty'             => '',
        'proposed'          => 'Prospect',
        'hold'              => 'Hold',
        'confirmed'         => 'Booked',
        'needs_assets'      => 'Needs Assets',
        'ready_to_announce' => 'Ready to Announce',
        'published'         => 'Published',
        'advanced'          => 'Advanced',
        'completed'         => 'Completed',
        'settled'           => 'Settled',
        'canceled'          => 'Cancelled',
    ];

    private string $logFile;
    private string $cacheFile;
    private ?string $keyFile;
    private string $sheetId;
    private string $tab;

    /** @var array{client_email:string,private_key:string,token_uri:string}|null */
    private ?array $key = null;

    public function __construct(string $root)
    {
        $this->logFile   = $root . '/storage/logs/sheet-sync.log';
        $this->cacheFile = sys_get_temp_dir() . '/backstage-sheets-token.json';
        $this->keyFile   = getenv('GOOGLE_SA_KEY_FILE') ?: null;
        $this->sheetId   = (string) (getenv('GOOGLE_SHEET_ID') ?: '');
        $this->tab       = (string) (getenv('GOOGLE_SHEET_TAB') ?: 'Tracker');

        @mkdir(dirname($this->logFile), 0755, true);
    }

    /** True only when a key file + sheet id are configured and the key loads. */
    public function isConfigured(): bool
    {
        return $this->sheetId !== '' && $this->loadKey() !== null;
    }

    /**
     * Push the app-owned fields of one event to its row in the sheet.
     *
     * @param string               $externalId e.g. "EVT-1050" (column A key)
     * @param array<string,mixed>  $fields     subset of FIELD_COLUMN keys -> values
     * @return bool true on success; false (logged) on any failure so the caller can retry.
     */
    public function pushEvent(string $externalId, array $fields): bool
    {
        if (!$this->isConfigured()) {
            $this->log("skip: not configured (event {$externalId})");
            return false;
        }
        $externalId = trim($externalId);
        if ($externalId === '') {
            $this->log('skip: event has no external_id (not a sheet row)');
            return false;
        }

        $row = $this->findRowByExternalId($externalId);
        if ($row === null) {
            $this->log("skip: external_id {$externalId} not found in sheet column A");
            return false;
        }

        return $this->writeFields($row, $fields, "EVT {$externalId}");
    }

    /**
     * Push the app-owned fields of one event located by its immutable app id.
     *
     * This is the preferred write-back path: the app id never changes (unlike
     * title/date-derived slugs or hand-typed external codes), so the row stays
     * findable even after the title is edited in-app. Requires the App ID
     * column to have been backfilled for this event's row (see ensureAppIdColumn
     * + writeAppId, or scripts/app-id-sync.php backfill).
     *
     * @param int                 $appId  events.id (lives in APP_ID_COLUMN)
     * @param array<string,mixed> $fields subset of FIELD_COLUMN keys -> values
     */
    public function pushEventByAppId(int $appId, array $fields): bool
    {
        if (!$this->isConfigured()) {
            $this->log("skip: not configured (app id {$appId})");
            return false;
        }
        $row = $this->findRowByAppId($appId);
        if ($row === null) {
            $this->log('skip: app id ' . $appId . ' not found in sheet column ' . self::APP_ID_COLUMN
                . ' (row not linked — backfill or append needed)');
            return false;
        }

        return $this->writeFields($row, $fields, "app id {$appId}");
    }

    /**
     * Batched write of the app-owned subset of $fields into one sheet row.
     *
     * @param int                 $row    1-based sheet row
     * @param array<string,mixed> $fields field => value (non-pushable keys skipped)
     * @param string              $label  used in log lines to identify the event
     */
    private function writeFields(int $row, array $fields, string $label): bool
    {
        // Build batched cell updates for the app-owned fields only.
        $data = [];
        foreach ($fields as $field => $value) {
            $col = self::FIELD_COLUMN[$field] ?? null;
            if ($col === null) {
                continue; // not a pushable field (e.g. deposit_amount)
            }
            $data[] = [
                'range'  => "{$this->tab}!{$col}{$row}",
                'values' => [[$this->formatValue($field, $value)]],
            ];
        }
        if (!$data) {
            $this->log("skip: no pushable fields for {$label}");
            return false;
        }

        $token = $this->accessToken();
        if ($token === null) {
            return false;
        }

        [$code, $resp] = $this->http(
            'POST',
            self::API_BASE . '/' . rawurlencode($this->sheetId) . '/values:batchUpdate',
            $token,
            ['valueInputOption' => 'USER_ENTERED', 'data' => $data]
        );

        if ($code >= 200 && $code < 300) {
            $this->log("ok: pushed {$label} row {$row} (" . implode(',', array_keys($fields)) . ')');
            return true;
        }
        $this->log("FAIL: push {$label} row {$row} -> HTTP {$code} {$resp}");
        return false;
    }

    // ─── Row lookup ────────────────────────────────────────────────────────────

    /** Scan column A of the tab and return the 1-based row number for an external_id. */
    private function findRowByExternalId(string $externalId): ?int
    {
        $token = $this->accessToken();
        if ($token === null) {
            return null;
        }
        [$code, $resp] = $this->http(
            'GET',
            self::API_BASE . '/' . rawurlencode($this->sheetId)
                . '/values/' . rawurlencode($this->tab . '!A:A'),
            $token,
            null
        );
        if ($code < 200 || $code >= 300) {
            $this->log("FAIL: read column A -> HTTP {$code} {$resp}");
            return null;
        }
        $json = json_decode($resp, true);
        $values = $json['values'] ?? [];
        foreach ($values as $i => $cells) {
            $cell = isset($cells[0]) ? trim((string) $cells[0]) : '';
            if ($cell !== '' && strcasecmp($cell, $externalId) === 0) {
                return $i + 1; // values are 0-indexed from row 1
            }
        }
        return null;
    }

    /** Scan the App ID column and return the 1-based row whose value == $appId. */
    public function findRowByAppId(int $appId): ?int
    {
        $col = self::APP_ID_COLUMN;
        $values = $this->readColumn($col);
        if ($values === null) {
            return null;
        }
        foreach ($values as $i => $cells) {
            $cell = isset($cells[0]) ? trim((string) $cells[0]) : '';
            if ($cell !== '' && ctype_digit($cell) && (int) $cell === $appId) {
                return $i + 1; // values are 0-indexed from row 1
            }
        }
        return null;
    }

    /**
     * Every App ID currently present in the App ID column of the tab, as a set
     * keyed by id. One API read. Returns null on read/auth error so a failed
     * read is never mistaken for "nothing present" (which would trigger spurious
     * re-pushes). Used by the queue reconciliation sweep to detect events marked
     * done that have vanished from the sheet (older buggy done-marking, or a row
     * deleted by hand).
     *
     * @return array<int,true>|null
     */
    public function presentAppIds(): ?array
    {
        $r = $this->readColumnResult(self::APP_ID_COLUMN);
        if (!$r['ok']) {
            return null;
        }
        $set = [];
        foreach ($r['values'] as $cells) {
            $cell = isset($cells[0]) ? trim((string) $cells[0]) : '';
            if ($cell !== '' && ctype_digit($cell)) {
                $set[(int) $cell] = true;
            }
        }
        return $set;
    }

    /** GET a single column's values (rows from row 1). Returns null on API error. */
    private function readColumn(string $col): ?array
    {
        $r = $this->readColumnResult($col);
        return $r['ok'] ? $r['values'] : null;
    }

    /**
     * GET a column, distinguishing "read failed" from "read ok, empty/no match".
     * @return array{ok:bool,values:array} ok=false means an API/auth error — the
     *         caller must NOT treat a not-found as definitive (avoids a spurious
     *         append when the lookup itself failed).
     */
    private function readColumnResult(string $col): array
    {
        $token = $this->accessToken();
        if ($token === null) {
            return ['ok' => false, 'values' => []];
        }
        [$code, $resp] = $this->http(
            'GET',
            self::API_BASE . '/' . rawurlencode($this->sheetId)
                . '/values/' . rawurlencode("{$this->tab}!{$col}:{$col}"),
            $token,
            null
        );
        if ($code < 200 || $code >= 300) {
            $this->log("FAIL: read column {$col} -> HTTP {$code} {$resp}");
            return ['ok' => false, 'values' => []];
        }
        $json = json_decode($resp, true);
        return ['ok' => true, 'values' => $json['values'] ?? []];
    }

    /**
     * Locate the first row whose cell in $col satisfies $match.
     * @return array{ok:bool,row:?int} ok=false on read error; row=null = no match.
     */
    private function locateInColumn(string $col, callable $match): array
    {
        $r = $this->readColumnResult($col);
        if (!$r['ok']) {
            return ['ok' => false, 'row' => null];
        }
        foreach ($r['values'] as $i => $cells) {
            $cell = isset($cells[0]) ? trim((string) $cells[0]) : '';
            if ($cell !== '' && $match($cell)) {
                return ['ok' => true, 'row' => $i + 1]; // values are 0-indexed from row 1
            }
        }
        return ['ok' => true, 'row' => null];
    }

    /** @return array{ok:bool,row:?int} row of the App ID column matching $appId. */
    public function locateAppId(int $appId): array
    {
        return $this->locateInColumn(self::APP_ID_COLUMN, fn ($c) => ctype_digit($c) && (int) $c === $appId);
    }

    /** @return array{ok:bool,row:?int} row of column A matching the external id. */
    public function locateExternalId(string $externalId): array
    {
        $externalId = trim($externalId);
        if ($externalId === '') {
            return ['ok' => true, 'row' => null];
        }
        return $this->locateInColumn('A', fn ($c) => strcasecmp($c, $externalId) === 0);
    }

    // ─── Two-way write: update existing, link legacy, or append new ──────────────

    /**
     * Reconcile one app event with the sheet. Order of resolution:
     *   1. App ID (col Z) match        → update app-owned fields in place.
     *   2. external_id (col A) match    → link the row (write App ID), then update.
     *   3. no match                     → append a complete new Tracker row.
     *
     * The link step (2) is what prevents duplicate rows for events that came
     * from the sheet originally (they have EVT-N in col A) but were never linked.
     *
     * @param array<string,mixed> $event row with at least array_keys(APPEND_COLUMN)
     * @return array{ok:bool,action:string} action ∈ updated|linked|appended|error|not_configured
     */
    public function syncEventRow(int $appId, array $event): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'action' => 'not_configured'];
        }
        $appFields = [];
        foreach (array_keys(self::FIELD_COLUMN) as $f) {
            if (array_key_exists($f, $event)) {
                $appFields[$f] = $event[$f];
            }
        }

        $byId = $this->locateAppId($appId);
        if (!$byId['ok']) {
            return ['ok' => false, 'action' => 'error'];
        }
        if ($byId['row'] !== null) {
            return ['ok' => $this->writeFields($byId['row'], $appFields, "app id {$appId}"), 'action' => 'updated'];
        }

        // Not linked yet — try the legacy external_id key before appending so we
        // adopt an existing Tracker row instead of duplicating it.
        $ext = trim((string) ($event['external_id'] ?? ''));
        if ($ext !== '') {
            $byExt = $this->locateExternalId($ext);
            if (!$byExt['ok']) {
                return ['ok' => false, 'action' => 'error'];
            }
            if ($byExt['row'] !== null) {
                $this->writeAppId($byExt['row'], $appId); // link for next time
                return ['ok' => $this->writeFields($byExt['row'], $appFields, "app id {$appId} (linked)"), 'action' => 'linked'];
            }
        }

        return ['ok' => $this->appendEventRow($appId, $event), 'action' => 'appended'];
    }

    /**
     * Append a complete new Tracker row for an app-created event, including its
     * immutable App ID (so the next sync finds it by id and updates in place).
     *
     * @param array<string,mixed> $event row with array_keys(APPEND_COLUMN)
     */
    public function appendEventRow(int $appId, array $event): bool
    {
        if (!$this->isConfigured()) {
            $this->log("skip append: not configured (app id {$appId})");
            return false;
        }
        $colToValue = [];
        foreach (self::APPEND_COLUMN as $field => $col) {
            if (array_key_exists($field, $event)) {
                $colToValue[$col] = $this->formatValue($field, $event[$field]);
            }
        }
        $colToValue[self::APP_ID_COLUMN] = (string) $appId;

        // Keep the Tracker in date order (oldest at top): place the new row at
        // its chronological position instead of at the bottom. Fall back to a
        // plain bottom append when the event has the latest date, has no
        // parseable date, or the date column can't be read.
        $dateCol   = self::APPEND_COLUMN['date'] ?? null;
        $newDate   = trim((string) ($event['date'] ?? ''));
        $insertRow = ($dateCol !== null && $newDate !== '')
            ? $this->dateSortedInsertRow($dateCol, $newDate)
            : null;

        $ok = $insertRow !== null
            ? $this->insertRowAt($insertRow, $colToValue)
            : $this->appendRow($colToValue);

        $this->log(($ok ? 'ok' : 'FAIL') . ': '
            . ($insertRow !== null ? "insert at row {$insertRow}" : 'append')
            . " app id {$appId} (EVT " . ($event['external_id'] ?? '?') . ')');
        return $ok;
    }

    /**
     * 1-based sheet row at which a new event dated $newDate should be inserted to
     * keep the date column ascending (oldest at top): the first DATA row whose
     * date is strictly later than $newDate. Returns null to mean "append at the
     * bottom" — no later row exists, the new date is unparseable, or the column
     * read failed. Rows with blank/unparseable dates sort earliest and are
     * skipped over (they stay above the dated rows, as they are today).
     */
    private function dateSortedInsertRow(string $dateCol, string $newDate): ?int
    {
        $newTs = strtotime($newDate);
        if ($newTs === false) {
            return null;
        }
        $r = $this->readColumnResult($dateCol);
        if (!$r['ok']) {
            return null; // can't read — append rather than guess a position
        }
        $values = $r['values'];
        // $values[$i] is sheet row ($i + 1); data begins after the header row.
        for ($i = self::HEADER_ROW; $i < count($values); $i++) {
            $cell = isset($values[$i][0]) ? trim((string) $values[$i][0]) : '';
            if ($cell === '') {
                continue;
            }
            $ts = strtotime($cell);
            if ($ts !== false && $ts > $newTs) {
                return $i + 1; // insert before this row (1-based)
            }
        }
        return null; // latest date — append at the end
    }

    /**
     * Insert one blank row at $row1Based (shifting rows below down by one) and
     * write $colToValue into it. Inherits formatting from the row above so date/
     * number cells render consistently. Used to place a new event in date order.
     */
    public function insertRowAt(int $row1Based, array $colToValue): bool
    {
        $token = $this->accessToken();
        if ($token === null) {
            return false;
        }
        $gid = $this->tabSheetId();
        if ($gid === null) {
            return false;
        }

        // 1. Structural insert of one empty row.
        [$code, $resp] = $this->http(
            'POST',
            self::API_BASE . '/' . rawurlencode($this->sheetId) . ':batchUpdate',
            $token,
            ['requests' => [[
                'insertDimension' => [
                    'range' => [
                        'sheetId'    => $gid,
                        'dimension'  => 'ROWS',
                        'startIndex' => $row1Based - 1, // 0-based, inclusive
                        'endIndex'   => $row1Based,     // exclusive
                    ],
                    'inheritFromBefore' => true,
                ],
            ]]]
        );
        if ($code < 200 || $code >= 300) {
            $this->log("FAIL: insert row {$row1Based} -> HTTP {$code} {$resp}");
            return false;
        }

        // 2. Write the cells into the freshly-inserted (empty) row.
        $cells  = [];
        $maxIdx = 0;
        foreach ($colToValue as $col => $val) {
            $idx          = self::colIndex($col);
            $cells[$idx]  = (string) $val;
            $maxIdx       = max($maxIdx, $idx);
        }
        $rowVals = [];
        for ($i = 0; $i <= $maxIdx; $i++) {
            $rowVals[$i] = $cells[$i] ?? '';
        }
        $range = "{$this->tab}!A{$row1Based}:" . self::APP_ID_COLUMN . $row1Based;
        [$code, $resp] = $this->http(
            'PUT',
            self::API_BASE . '/' . rawurlencode($this->sheetId)
                . '/values/' . rawurlencode($range)
                . '?valueInputOption=USER_ENTERED',
            $token,
            ['values' => [$rowVals]]
        );
        if ($code >= 200 && $code < 300) {
            return true;
        }
        $this->log("FAIL: write inserted row {$row1Based} -> HTTP {$code} {$resp}");
        return false;
    }

    /**
     * Delete a single row by 1-based number (shifting rows below up by one).
     * Symmetric counterpart to insertRowAt; used by tests and for removing a
     * row from the sheet.
     */
    public function deleteRow(int $row1Based): bool
    {
        $token = $this->accessToken();
        if ($token === null) {
            return false;
        }
        $gid = $this->tabSheetId();
        if ($gid === null) {
            return false;
        }
        [$code, $resp] = $this->http(
            'POST',
            self::API_BASE . '/' . rawurlencode($this->sheetId) . ':batchUpdate',
            $token,
            ['requests' => [[
                'deleteDimension' => [
                    'range' => [
                        'sheetId'    => $gid,
                        'dimension'  => 'ROWS',
                        'startIndex' => $row1Based - 1,
                        'endIndex'   => $row1Based,
                    ],
                ],
            ]]]
        );
        if ($code >= 200 && $code < 300) {
            return true;
        }
        $this->log("FAIL: delete row {$row1Based} -> HTTP {$code} {$resp}");
        return false;
    }

    /**
     * Append a single row to the Tracker table. $colToValue maps column letters
     * to values; gaps are written blank. Uses the API's append (INSERT_ROWS) so
     * Google places the row after the last data row of the table.
     *
     * @param array<string,string> $colToValue e.g. ['A' => 'EVT-9', 'D' => 'Title', 'Z' => '123']
     */
    public function appendRow(array $colToValue): bool
    {
        $token = $this->accessToken();
        if ($token === null) {
            return false;
        }
        $cells = [];
        $maxIdx = 0;
        foreach ($colToValue as $col => $val) {
            $idx = self::colIndex($col);
            $cells[$idx] = (string) $val;
            $maxIdx = max($maxIdx, $idx);
        }
        $row = [];
        for ($i = 0; $i <= $maxIdx; $i++) {
            $row[$i] = $cells[$i] ?? '';
        }

        $range = "{$this->tab}!A" . self::HEADER_ROW . ':' . self::APP_ID_COLUMN;
        [$code, $resp] = $this->http(
            'POST',
            self::API_BASE . '/' . rawurlencode($this->sheetId)
                . '/values/' . rawurlencode($range)
                . ':append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS',
            $token,
            ['values' => [$row]]
        );
        if ($code >= 200 && $code < 300) {
            return true;
        }
        $this->log("FAIL: append row -> HTTP {$code} {$resp}");
        return false;
    }

    // ─── App ID column management (backfill / linking) ───────────────────────────

    /**
     * Write the immutable app id into the App ID column for a given row.
     * Used by the backfill to link existing sheet rows to app events.
     */
    public function writeAppId(int $row, int $appId): bool
    {
        $token = $this->accessToken();
        if ($token === null) {
            return false;
        }
        $range = self::APP_ID_COLUMN . $row;
        [$code, $resp] = $this->http(
            'PUT',
            self::API_BASE . '/' . rawurlencode($this->sheetId)
                . '/values/' . rawurlencode("{$this->tab}!{$range}")
                . '?valueInputOption=RAW',
            $token,
            ['values' => [[(string) $appId]]]
        );
        if ($code >= 200 && $code < 300) {
            return true;
        }
        $this->log("FAIL: write app id {$appId} -> {$range} -> HTTP {$code} {$resp}");
        return false;
    }

    /**
     * Batch-write app ids: [rowNumber => appId, ...] in a single API call.
     * Returns [okCount, false-on-error]. Used by the backfill.
     */
    public function writeAppIds(array $rowToId): int
    {
        return $this->writeColumn(self::APP_ID_COLUMN, $rowToId);
    }

    /**
     * Batch-write values into one column: [rowNumber => value, ...] in a single
     * API call (RAW). Returns the number written, or -1 on error.
     */
    public function writeColumn(string $col, array $rowToValue): int
    {
        if (!$rowToValue) {
            return 0;
        }
        $token = $this->accessToken();
        if ($token === null) {
            return -1;
        }
        $data = [];
        foreach ($rowToValue as $row => $val) {
            $data[] = [
                'range'  => "{$this->tab}!{$col}" . (int) $row,
                'values' => [[(string) $val]],
            ];
        }
        [$code, $resp] = $this->http(
            'POST',
            self::API_BASE . '/' . rawurlencode($this->sheetId) . '/values:batchUpdate',
            $token,
            ['valueInputOption' => 'RAW', 'data' => $data]
        );
        if ($code >= 200 && $code < 300) {
            $this->log('ok: wrote ' . count($data) . ' cells into column ' . $col);
            return count($data);
        }
        $this->log("FAIL: write column {$col} -> HTTP {$code} {$resp}");
        return -1;
    }

    /** Delete one whole column (0-based index) from the configured tab. */
    public function deleteColumn(int $index0): bool
    {
        $token = $this->accessToken();
        if ($token === null) {
            return false;
        }
        $gid = $this->tabSheetId();
        if ($gid === null) {
            return false;
        }
        [$code, $resp] = $this->http(
            'POST',
            self::API_BASE . '/' . rawurlencode($this->sheetId) . ':batchUpdate',
            $token,
            ['requests' => [[
                'deleteDimension' => [
                    'range' => [
                        'sheetId'    => $gid,
                        'dimension'  => 'COLUMNS',
                        'startIndex' => $index0,
                        'endIndex'   => $index0 + 1,
                    ],
                ],
            ]]]
        );
        if ($code >= 200 && $code < 300) {
            $this->log('ok: deleted column index ' . $index0);
            return true;
        }
        $this->log("FAIL: delete column {$index0} -> HTTP {$code} {$resp}");
        return false;
    }

    /** Clear the given A1 ranges (values only). */
    public function clearRanges(array $a1Ranges): bool
    {
        $token = $this->accessToken();
        if ($token === null) {
            return false;
        }
        $ranges = array_map(fn ($r) => "{$this->tab}!{$r}", $a1Ranges);
        [$code, $resp] = $this->http(
            'POST',
            self::API_BASE . '/' . rawurlencode($this->sheetId) . '/values:batchClear',
            $token,
            ['ranges' => $ranges]
        );
        if ($code >= 200 && $code < 300) {
            $this->log('ok: cleared ' . implode(',', $a1Ranges));
            return true;
        }
        $this->log("FAIL: clear ranges -> HTTP {$code} {$resp}");
        return false;
    }

    /**
     * Batch-read several whole columns at once (UNFORMATTED so dates come back as
     * serial numbers, immune to locale display formatting). Returns
     * [colLetter => array<rowIndex0, value>] or null on error.
     */
    public function batchGetColumns(array $cols): ?array
    {
        $token = $this->accessToken();
        if ($token === null) {
            return null;
        }
        $qs = 'valueRenderOption=UNFORMATTED_VALUE&dateTimeRenderOption=SERIAL_NUMBER';
        foreach ($cols as $c) {
            $qs .= '&ranges=' . rawurlencode("{$this->tab}!{$c}:{$c}");
        }
        [$code, $resp] = $this->http(
            'GET',
            self::API_BASE . '/' . rawurlencode($this->sheetId) . '/values:batchGet?' . $qs,
            $token,
            null
        );
        if ($code < 200 || $code >= 300) {
            $this->log("FAIL: batchGet columns -> HTTP {$code} {$resp}");
            return null;
        }
        $json = json_decode($resp, true);
        $out = [];
        foreach (($json['valueRanges'] ?? []) as $i => $vr) {
            $col = $cols[$i] ?? (string) $i;
            $flat = [];
            foreach (($vr['values'] ?? []) as $rowCells) {
                $flat[] = $rowCells[0] ?? null;
            }
            $out[$col] = $flat;
        }
        return $out;
    }

    /**
     * Ensure the App ID column has its header and is hidden in the sheet UI.
     * Idempotent: safe to run repeatedly. Returns true on success.
     */
    public function ensureAppIdColumn(): bool
    {
        $token = $this->accessToken();
        if ($token === null) {
            return false;
        }

        // 1. Write the header label (idempotent — RAW overwrite).
        $hdrRange = self::APP_ID_COLUMN . self::HEADER_ROW;
        [$code, $resp] = $this->http(
            'PUT',
            self::API_BASE . '/' . rawurlencode($this->sheetId)
                . '/values/' . rawurlencode("{$this->tab}!{$hdrRange}")
                . '?valueInputOption=RAW',
            $token,
            ['values' => [[self::APP_ID_HEADER]]]
        );
        if ($code < 200 || $code >= 300) {
            $this->log("FAIL: write App ID header -> HTTP {$code} {$resp}");
            return false;
        }

        // 2. Hide the column (structural batchUpdate; needs the tab's numeric id).
        $gid = $this->tabSheetId();
        if ($gid === null) {
            return false;
        }
        $colIndex = self::colIndex(self::APP_ID_COLUMN); // 0-based
        [$code, $resp] = $this->http(
            'POST',
            self::API_BASE . '/' . rawurlencode($this->sheetId) . ':batchUpdate',
            $token,
            ['requests' => [[
                'updateDimensionProperties' => [
                    'range' => [
                        'sheetId'    => $gid,
                        'dimension'  => 'COLUMNS',
                        'startIndex' => $colIndex,
                        'endIndex'   => $colIndex + 1,
                    ],
                    'properties' => ['hiddenByUser' => true],
                    'fields'     => 'hiddenByUser',
                ],
            ]]]
        );
        if ($code < 200 || $code >= 300) {
            $this->log("FAIL: hide App ID column -> HTTP {$code} {$resp}");
            return false;
        }
        $this->log('ok: ensured + hid App ID column ' . self::APP_ID_COLUMN);
        return true;
    }

    /** Numeric sheetId (gid) of the configured tab, or null on error. */
    public function tabSheetId(): ?int
    {
        return $this->tabSheetIdFor($this->tab);
    }

    /** Numeric sheetId (gid) of a named tab, or null on error. */
    public function tabSheetIdFor(string $tab): ?int
    {
        $token = $this->accessToken();
        if ($token === null) {
            return null;
        }
        [$code, $resp] = $this->http(
            'GET',
            self::API_BASE . '/' . rawurlencode($this->sheetId)
                . '?fields=' . rawurlencode('sheets(properties(sheetId,title,gridProperties/columnCount))'),
            $token,
            null
        );
        if ($code < 200 || $code >= 300) {
            $this->log("FAIL: read spreadsheet metadata -> HTTP {$code} {$resp}");
            return null;
        }
        $json = json_decode($resp, true);
        foreach (($json['sheets'] ?? []) as $sheet) {
            $props = $sheet['properties'] ?? [];
            if (($props['title'] ?? '') === $tab) {
                return (int) ($props['sheetId'] ?? 0);
            }
        }
        $this->log("FAIL: tab '{$tab}' not found in spreadsheet");
        return null;
    }

    // ─── Generic, tab-aware grid toolkit (used by the staff sync) ───────────────

    /** Read a 2-D A1 range from any tab. Rows of cell-arrays, or null on error. */
    public function readGrid(string $tab, string $a1Range): ?array
    {
        $token = $this->accessToken();
        if ($token === null) {
            return null;
        }
        [$code, $resp] = $this->http(
            'GET',
            self::API_BASE . '/' . rawurlencode($this->sheetId)
                . '/values/' . rawurlencode("{$tab}!{$a1Range}"),
            $token,
            null
        );
        if ($code < 200 || $code >= 300) {
            $this->log("FAIL: read grid {$tab}!{$a1Range} -> HTTP {$code} {$resp}");
            return null;
        }
        return json_decode($resp, true)['values'] ?? [];
    }

    /** Append one row to a tab. $colToValue maps column letters to values. */
    public function appendGridRow(string $tab, array $colToValue, string $anchorRange): bool
    {
        $token = $this->accessToken();
        if ($token === null) {
            return false;
        }
        $cells = [];
        $maxIdx = 0;
        foreach ($colToValue as $col => $val) {
            $idx = self::colIndex($col);
            $cells[$idx] = (string) $val;
            $maxIdx = max($maxIdx, $idx);
        }
        $row = [];
        for ($i = 0; $i <= $maxIdx; $i++) {
            $row[$i] = $cells[$i] ?? '';
        }
        [$code, $resp] = $this->http(
            'POST',
            self::API_BASE . '/' . rawurlencode($this->sheetId)
                . '/values/' . rawurlencode("{$tab}!{$anchorRange}")
                . ':append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS',
            $token,
            ['values' => [$row]]
        );
        if ($code >= 200 && $code < 300) {
            return true;
        }
        $this->log("FAIL: append {$tab} row -> HTTP {$code} {$resp}");
        return false;
    }

    /** Write specific cells on a tab: ['A3' => 'x', 'Z3' => '5']. */
    public function batchWriteCells(string $tab, array $cellToValue): bool
    {
        if (!$cellToValue) {
            return true;
        }
        $token = $this->accessToken();
        if ($token === null) {
            return false;
        }
        $data = [];
        foreach ($cellToValue as $a1 => $val) {
            $data[] = ['range' => "{$tab}!{$a1}", 'values' => [[(string) $val]]];
        }
        [$code, $resp] = $this->http(
            'POST',
            self::API_BASE . '/' . rawurlencode($this->sheetId) . '/values:batchUpdate',
            $token,
            ['valueInputOption' => 'USER_ENTERED', 'data' => $data]
        );
        if ($code >= 200 && $code < 300) {
            return true;
        }
        $this->log("FAIL: batchWrite {$tab} -> HTTP {$code} {$resp}");
        return false;
    }

    /** Ensure a header label in a column on a tab; optionally hide the column. Idempotent. */
    public function ensureGridColumn(string $tab, string $col, string $header, int $headerRow, bool $hide): bool
    {
        $token = $this->accessToken();
        if ($token === null) {
            return false;
        }
        [$code, $resp] = $this->http(
            'PUT',
            self::API_BASE . '/' . rawurlencode($this->sheetId)
                . '/values/' . rawurlencode("{$tab}!{$col}{$headerRow}") . '?valueInputOption=RAW',
            $token,
            ['values' => [[$header]]]
        );
        if ($code < 200 || $code >= 300) {
            $this->log("FAIL: header {$tab}!{$col}{$headerRow} -> HTTP {$code} {$resp}");
            return false;
        }
        if ($hide) {
            $gid = $this->tabSheetIdFor($tab);
            if ($gid === null) {
                return false;
            }
            $ci = self::colIndex($col);
            [$code, $resp] = $this->http(
                'POST',
                self::API_BASE . '/' . rawurlencode($this->sheetId) . ':batchUpdate',
                $token,
                ['requests' => [['updateDimensionProperties' => [
                    'range'      => ['sheetId' => $gid, 'dimension' => 'COLUMNS', 'startIndex' => $ci, 'endIndex' => $ci + 1],
                    'properties' => ['hiddenByUser' => true],
                    'fields'     => 'hiddenByUser',
                ]]]]
            );
            if ($code < 200 || $code >= 300) {
                $this->log("FAIL: hide {$tab} col {$col} -> HTTP {$code} {$resp}");
                return false;
            }
        }
        return true;
    }

    /** Raw spreadsheet metadata (sheets + properties), for inspection/tooling. */
    public function spreadsheetMeta(): ?array
    {
        $token = $this->accessToken();
        if ($token === null) {
            return null;
        }
        [$code, $resp] = $this->http(
            'GET',
            self::API_BASE . '/' . rawurlencode($this->sheetId)
                . '?fields=' . rawurlencode('sheets(properties(sheetId,title,gridProperties))'),
            $token,
            null
        );
        if ($code < 200 || $code >= 300) {
            $this->log("FAIL: read spreadsheet metadata -> HTTP {$code} {$resp}");
            return null;
        }
        return json_decode($resp, true);
    }

    /** 0-based column index for an A1 column letter (A->0, Z->25, AA->26). */
    public static function colIndex(string $col): int
    {
        $col = strtoupper($col);
        $n = 0;
        for ($i = 0, $len = strlen($col); $i < $len; $i++) {
            $n = $n * 26 + (ord($col[$i]) - 64);
        }
        return $n - 1;
    }

    // ─── Value coercion ──────────────────────────────────────────────────────────

    /** Render a DB value the way the sheet expects it. */
    private function formatValue(string $field, mixed $value): string
    {
        if ($field === 'walkthrough_done') {
            return (boolish_truthy($value)) ? 'Yes' : '';
        }
        if ($field === 'status') {
            $slug = strtolower(trim((string) $value));
            if ($slug === '') {
                return '';
            }
            // Known slug -> curated label; otherwise Title Case the slug.
            return self::STATUS_SHEET_LABEL[$slug]
                ?? ucwords(str_replace('_', ' ', $slug));
        }
        if ($field === 'room') {
            $room = strtolower(trim((string) $value));
            return $room === '' ? '' : ucfirst($room); // upstairs -> Upstairs, both -> Both
        }
        if ($value === null) {
            return '';
        }
        return (string) $value;
    }

    // ─── OAuth2 (service account JWT-bearer) ─────────────────────────────────────

    private function accessToken(): ?string
    {
        // Reuse a cached token if it has > 60s left.
        $cached = @json_decode((string) @file_get_contents($this->cacheFile), true);
        if (is_array($cached) && ($cached['exp'] ?? 0) > time() + 60 && !empty($cached['token'])) {
            return (string) $cached['token'];
        }

        $key = $this->loadKey();
        if ($key === null) {
            return null;
        }

        $now = time();
        $claim = [
            'iss'   => $key['client_email'],
            'scope' => self::SCOPE,
            'aud'   => $key['token_uri'],
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];
        $jwt = $this->signJwt($claim, $key['private_key']);
        if ($jwt === null) {
            return null;
        }

        [$code, $resp] = $this->httpForm(self::TOKEN_URI, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]);
        $json = json_decode($resp, true);
        if ($code < 200 || $code >= 300 || empty($json['access_token'])) {
            $this->log("FAIL: token exchange -> HTTP {$code} {$resp}");
            return null;
        }

        $token = (string) $json['access_token'];
        $exp   = $now + (int) ($json['expires_in'] ?? 3600);
        @file_put_contents($this->cacheFile, json_encode(['token' => $token, 'exp' => $exp]));
        @chmod($this->cacheFile, 0600);
        return $token;
    }

    /** @return array{client_email:string,private_key:string,token_uri:string}|null */
    private function loadKey(): ?array
    {
        if ($this->key !== null) {
            return $this->key;
        }
        if (!$this->keyFile) {
            $this->log('skip: GOOGLE_SA_KEY_FILE not set');
            return null;
        }
        if (!is_readable($this->keyFile)) {
            $this->log("FAIL: key file not readable: {$this->keyFile} (check perms — must be readable by www-data)");
            return null;
        }
        $json = json_decode((string) file_get_contents($this->keyFile), true);
        if (!is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
            $this->log('FAIL: key file missing client_email/private_key');
            return null;
        }
        return $this->key = [
            'client_email' => (string) $json['client_email'],
            'private_key'  => (string) $json['private_key'],
            'token_uri'    => (string) ($json['token_uri'] ?? self::TOKEN_URI),
        ];
    }

    private function signJwt(array $claim, string $privateKey): ?string
    {
        $segments = [
            $this->b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->b64u(json_encode($claim)),
        ];
        $input = implode('.', $segments);
        $sig   = '';
        if (!openssl_sign($input, $sig, $privateKey, OPENSSL_ALGO_SHA256)) {
            $this->log('FAIL: openssl_sign on JWT (bad private key?)');
            return null;
        }
        $segments[] = $this->b64u($sig);
        return implode('.', $segments);
    }

    private function b64u(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // ─── HTTP ────────────────────────────────────────────────────────────────────

    /** @return array{0:int,1:string} [httpCode, body] */
    private function http(string $method, string $url, string $token, ?array $jsonBody): array
    {
        $ch = curl_init($url);
        $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 15,
        ];
        if ($jsonBody !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($jsonBody);
            $headers[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return [0, "curl: {$err}"];
        }
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$code, (string) $body];
    }

    /** @return array{0:int,1:string} [httpCode, body] */
    private function httpForm(string $url, array $form): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($form),
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return [0, "curl: {$err}"];
        }
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$code, (string) $body];
    }

    private function log(string $msg): void
    {
        @file_put_contents($this->logFile, sprintf("[%s] %s\n", date('c'), $msg), FILE_APPEND);
    }
}

/** Local truthiness helper (mirrors the app's boolish()) without a hard dependency. */
function boolish_truthy(mixed $v): bool
{
    if (is_bool($v)) return $v;
    if (is_int($v)) return $v !== 0;
    $s = strtolower(trim((string) $v));
    return in_array($s, ['1', 'true', 'yes', 'y', 'on'], true);
}
