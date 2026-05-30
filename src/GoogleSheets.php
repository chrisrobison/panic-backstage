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
 * Row matching: events carry an `external_id` (e.g. "EVT-1050") which lives in
 * column A of the Tracker tab. We locate the row by scanning column A, then
 * write only the app-owned columns for that row.
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
        'status'             => 'M',
        'potential_revenue'  => 'F',
        'ticket_system'      => 'O',
        'contract_url'       => 'P',
        'walkthrough_done'   => 'Q',
        'ticket_url'         => 'R',
        'settlement_doc_url' => 'S',
    ];

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
            $this->log("skip: no pushable fields for {$externalId}");
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
            $this->log("ok: pushed {$externalId} row {$row} (" . implode(',', array_keys($fields)) . ')');
            return true;
        }
        $this->log("FAIL: push {$externalId} row {$row} -> HTTP {$code} {$resp}");
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
