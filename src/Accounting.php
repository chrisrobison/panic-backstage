<?php
declare(strict_types=1);

namespace Panic;

/**
 * Accounting integration service.
 *
 * Currently a framework — QBO/Xero OAuth flows are implemented as stubs
 * pending credential setup. The sync is triggered by Ledger::finalize().
 *
 * To enable:
 *   1. Set QUICKBOOKS_* or XERO_* env vars in .env
 *   2. Set accounting_provider = 'qbo'|'xero' and accounting_sync_enabled = 1
 *      in the promote_settings row (Admin → Settings → Accounting, coming soon)
 *   3. Populate accounting_coa_map with your chart of accounts codes
 */
final class Accounting
{
    public function __construct(
        private readonly Database $db,
        private readonly string $root
    ) {}

    /**
     * Called by Ledger::finalize() after a closeout is finalized.
     * Queues a pending sync record and attempts an immediate sync if configured.
     */
    public function onCloseoutFinalized(int $eventId): void
    {
        $settings = $this->db->one(
            'SELECT accounting_provider, accounting_sync_enabled FROM promote_settings LIMIT 1'
        );

        $provider = $settings['accounting_provider'] ?? 'none';
        $enabled  = (bool) ($settings['accounting_sync_enabled'] ?? false);

        if (!$enabled || $provider === 'none') {
            return;
        }

        // Create a pending sync record so the attempt is always auditable.
        $this->db->insert(
            'INSERT INTO accounting_sync_log (event_id, provider, status) VALUES (?, ?, ?)',
            [$eventId, $provider, 'pending']
        );

        $this->sync($eventId, $provider);
    }

    /**
     * Build the journal payload and push it to the configured provider.
     * Returns true only when the provider reports a successful sync.
     */
    public function sync(int $eventId, string $provider): bool
    {
        $entries = $this->db->all(
            'SELECT * FROM event_ledger_entries WHERE event_id = ? AND is_void = 0',
            [$eventId]
        );

        $coaMap  = $this->buildCoaMap($provider);
        $payload = $this->buildJournalPayload($eventId, $entries, $coaMap);

        $result = match ($provider) {
            'qbo'   => $this->syncToQbo($eventId, $payload),
            'xero'  => $this->syncToXero($eventId, $payload),
            default => ['status' => 'skipped', 'external_id' => null, 'error' => 'Unknown provider: ' . $provider],
        };

        // Update the most-recent pending record for this event.
        $this->db->run(
            "UPDATE accounting_sync_log
             SET status = ?, external_id = ?, error = ?, synced_at = NOW(), payload_json = ?
             WHERE event_id = ? AND status = 'pending'
             ORDER BY id DESC LIMIT 1",
            [
                $result['status'],
                $result['external_id'] ?? null,
                $result['error']       ?? null,
                json_encode($payload),
                $eventId,
            ]
        );

        return $result['status'] === 'synced';
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildCoaMap(string $provider): array
    {
        $rows = $this->db->all(
            'SELECT ledger_category, account_code FROM accounting_coa_map WHERE provider = ?',
            [$provider]
        );
        return array_column($rows, 'account_code', 'ledger_category');
    }

    private function buildJournalPayload(int $eventId, array $entries, array $coa): array
    {
        $event = $this->db->one('SELECT title, show_time FROM events WHERE id = ?', [$eventId]);

        $lines = [];
        foreach ($entries as $e) {
            $lines[] = [
                'account_code' => $coa[$e['category']] ?? 'UNMAPPED',
                'category'     => $e['category'],
                'line_type'    => $e['line_type'],
                'amount'       => (float) $e['amount'],
                'description'  => $e['description'] ?? $e['category'],
            ];
        }

        return [
            'event_id'    => $eventId,
            'event_title' => $event['title'] ?? 'Event #' . $eventId,
            'event_date'  => $event['show_time'] ?? null,
            'lines'       => $lines,
        ];
    }

    /**
     * @return array{status: string, external_id: ?string, error: ?string}
     *
     * STUB: QBO OAuth requires CLIENT_ID + CLIENT_SECRET + refresh token flow.
     * When QUICKBOOKS_* env vars are configured:
     *   1. Exchange QUICKBOOKS_REFRESH_TOKEN for a short-lived access token
     *      (POST https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer)
     *   2. POST to https://quickbooks.api.intuit.com/v3/company/{QUICKBOOKS_REALM_ID}/journalentry
     *   3. Map $payload['lines'] to QBO JournalEntry line items using PostingType
     *      (Debit / Credit) based on line_type
     *   4. Store the returned Id as external_id; update status to 'synced'
     */
    private function syncToQbo(int $eventId, array $payload): array
    {
        $clientId = getenv('QUICKBOOKS_CLIENT_ID') ?: '';
        if (!$clientId) {
            error_log("Accounting: QBO sync skipped for event {$eventId} — QUICKBOOKS_CLIENT_ID not set.");
            return ['status' => 'skipped', 'external_id' => null, 'error' => 'QBO credentials not configured'];
        }

        // TODO: implement full OAuth token refresh + JournalEntry POST when
        // credentials are available. See docblock above for the call sequence.
        error_log("Accounting: QBO sync stub called for event {$eventId}. Implement token refresh to enable.");
        return ['status' => 'skipped', 'external_id' => null, 'error' => 'QBO sync not yet implemented'];
    }

    /**
     * @return array{status: string, external_id: ?string, error: ?string}
     *
     * STUB: Xero OAuth2 requires XERO_CLIENT_ID + XERO_CLIENT_SECRET + refresh token.
     * When XERO_* env vars are configured:
     *   1. Exchange XERO_REFRESH_TOKEN for an access token
     *      (POST https://identity.xero.com/connect/token)
     *   2. POST to https://api.xero.com/api.xro/2.0/ManualJournals
     *      with Xero-tenant-id: XERO_TENANT_ID header
     *   3. Map $payload['lines'] to Xero JournalLines (LineAmountTypes, AccountCode)
     *   4. Store the returned ManualJournalID as external_id; update status to 'synced'
     */
    private function syncToXero(int $eventId, array $payload): array
    {
        $clientId = getenv('XERO_CLIENT_ID') ?: '';
        if (!$clientId) {
            error_log("Accounting: Xero sync skipped for event {$eventId} — XERO_CLIENT_ID not set.");
            return ['status' => 'skipped', 'external_id' => null, 'error' => 'Xero credentials not configured'];
        }

        // TODO: implement full OAuth token refresh + ManualJournals POST when
        // credentials are available. See docblock above for the call sequence.
        error_log("Accounting: Xero sync stub called for event {$eventId}. Implement token refresh to enable.");
        return ['status' => 'skipped', 'external_id' => null, 'error' => 'Xero sync not yet implemented'];
    }
}
