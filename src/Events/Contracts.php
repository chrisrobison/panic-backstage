<?php
declare(strict_types=1);

namespace Panic\Events;

use Panic\BaseEndpoint;
use Panic\ContractRenderer;
use Panic\ContractService;
use Panic\Request;
use Panic\Response;
use function Panic\log_activity;

/**
 * Per-event contracts.
 *
 *   GET  /api/events/{id}/contracts    list contracts for the event (+ templates)
 *   POST /api/events/{id}/contracts    create a contract bound to the event
 *
 * Editing a contract happens via the top-level /api/contracts/{id} endpoint.
 */
final class Contracts extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        $eventId = $this->requireEventId();
        $capability = $request->method() === 'GET' ? 'view_contracts' : 'manage_contracts';
        if ($denied = $this->requireEventCapability($eventId, $capability)) {
            return $denied;
        }
        return match ($request->method()) {
            'GET'  => $this->ok([
                'contracts' => $this->db->all(
                    'SELECT c.id, c.title, c.contract_type, c.status, c.provider, c.counterparty_name, c.updated_at, c.current_version_id,
                            c.asset_id, ea.title AS asset_title, ea.file_path AS asset_file_path, ea.filename AS asset_filename
                     FROM contracts c
                     LEFT JOIN event_assets ea ON ea.id = c.asset_id
                     WHERE c.event_id = ? ORDER BY c.updated_at DESC',
                    [$eventId]
                ),
                'templates' => $this->db->all('SELECT id, name, contract_type, description FROM contract_templates WHERE is_active = 1 ORDER BY name'),
                'types'     => ContractRenderer::CONTRACT_TYPES,
            ]),
            'POST' => $this->create($request, $eventId),
            default => Response::methodNotAllowed(),
        };
    }

    private function create(Request $request, int $eventId): Response
    {
        $b = $request->body();
        $event = $this->db->one('SELECT venue_id, title FROM events WHERE id = ?', [$eventId]);

        // "Contract signed and attached" path: link an already-uploaded event
        // asset instead of generating a contract in-app. See
        // ContractService::attachUploaded() for why this is a normal
        // contracts row rather than a separate flag.
        if (!empty($b['asset_id'])) {
            $assetId = (int) $b['asset_id'];
            $asset = $this->db->one('SELECT id, title FROM event_assets WHERE id = ? AND event_id = ?', [$assetId, $eventId]);
            if (!$asset) {
                return Response::json(['error' => 'Asset not found for this event'], 422);
            }
            $id = ContractService::attachUploaded($this->db, [
                'event_id' => $eventId,
                'venue_id' => $event['venue_id'] ?? null,
                'asset_id' => $assetId,
                'title'    => trim((string) ($b['title'] ?? '')) ?: (($event['title'] ?? 'Event') . ' — Uploaded Contract'),
            ], $this->userId());
            log_activity($this->db, $eventId, $this->userId(), 'contract attached from asset', ['contract_id' => $id, 'asset_id' => $assetId]);
            return $this->ok(['id' => $id]);
        }

        $id = ContractService::create($this->db, [
            'event_id'           => $eventId,
            'venue_id'           => $event['venue_id'] ?? null,
            'template_id'        => $b['template_id'] ?? null,
            'contract_type'      => $b['contract_type'] ?? 'other',
            'title'              => trim((string) ($b['title'] ?? '')) ?: (($event['title'] ?? 'Event') . ' — Contract'),
            'counterparty_name'  => $b['counterparty_name'] ?? null,
            'counterparty_org'   => $b['counterparty_org'] ?? null,
            'counterparty_email' => $b['counterparty_email'] ?? null,
        ], $this->userId());
        log_activity($this->db, $eventId, $this->userId(), 'contract created', ['contract_id' => $id]);
        return $this->ok(['id' => $id]);
    }
}
