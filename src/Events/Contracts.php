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
 *
 * Series-wide contracts: a contract's `series_id`, when set, marks it as
 * covering every occurrence in that recurring series — not just the one
 * event named by `event_id` (the event it was actually generated/uploaded
 * from). index() below surfaces those alongside this event's own contracts;
 * create() sets series_id when the caller passes apply_to_series=true and
 * this event is itself part of a series. See Events::hasExecutedContract()
 * for the matching read-side gate used by readiness/status-transition checks.
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
            'GET'  => $this->index($eventId),
            'POST' => $this->create($request, $eventId),
            default => Response::methodNotAllowed(),
        };
    }

    private function index(int $eventId): Response
    {
        $event = $this->db->one('SELECT series_id FROM events WHERE id = ?', [$eventId]);
        $seriesId = !empty($event['series_id']) ? (int) $event['series_id'] : null;

        $contracts = $this->db->all(
            'SELECT c.id, c.title, c.contract_type, c.status, c.provider, c.counterparty_name, c.updated_at, c.current_version_id,
                    c.event_id, c.series_id,
                    c.asset_id, ea.title AS asset_title, ea.file_path AS asset_file_path, ea.filename AS asset_filename
             FROM contracts c
             LEFT JOIN event_assets ea ON ea.id = c.asset_id
             WHERE c.event_id = ? OR (c.series_id IS NOT NULL AND c.series_id = ?)
             ORDER BY c.updated_at DESC',
            [$eventId, $seriesId]
        );

        $seriesInfo = null;
        if ($seriesId !== null) {
            $count = $this->db->one('SELECT COUNT(*) AS n FROM events WHERE series_id = ?', [$seriesId]);
            $seriesInfo = ['id' => $seriesId, 'occurrence_count' => (int) ($count['n'] ?? 0)];
        }

        return $this->ok([
            'contracts' => $contracts,
            'templates' => $this->db->all('SELECT id, name, contract_type, description FROM contract_templates WHERE is_active = 1 ORDER BY name'),
            'types'     => ContractRenderer::CONTRACT_TYPES,
            'series'    => $seriesInfo,
        ]);
    }

    private function create(Request $request, int $eventId): Response
    {
        $b = $request->body();
        $event = $this->db->one('SELECT venue_id, title, series_id FROM events WHERE id = ?', [$eventId]);
        // Only takes effect when this event is actually part of a series —
        // an apply_to_series flag on a standalone event is silently ignored
        // rather than erroring, since the UI checkbox that sends it is only
        // ever shown when the event has a series to apply to.
        $seriesId = (!empty($b['apply_to_series']) && !empty($event['series_id'])) ? (int) $event['series_id'] : null;

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
                'event_id'  => $eventId,
                'series_id' => $seriesId,
                'venue_id'  => $event['venue_id'] ?? null,
                'asset_id'  => $assetId,
                'title'     => trim((string) ($b['title'] ?? '')) ?: (($event['title'] ?? 'Event') . ' — Uploaded Contract'),
            ], $this->userId());
            log_activity($this->db, $eventId, $this->userId(), 'contract attached from asset', ['contract_id' => $id, 'asset_id' => $assetId, 'series_id' => $seriesId]);
            return $this->ok(['id' => $id]);
        }

        $id = ContractService::create($this->db, [
            'event_id'           => $eventId,
            'series_id'          => $seriesId,
            'venue_id'           => $event['venue_id'] ?? null,
            'template_id'        => $b['template_id'] ?? null,
            'contract_type'      => $b['contract_type'] ?? 'other',
            'title'              => trim((string) ($b['title'] ?? '')) ?: (($event['title'] ?? 'Event') . ' — Contract'),
            'counterparty_name'  => $b['counterparty_name'] ?? null,
            'counterparty_org'   => $b['counterparty_org'] ?? null,
            'counterparty_email' => $b['counterparty_email'] ?? null,
        ], $this->userId());
        log_activity($this->db, $eventId, $this->userId(), 'contract created', ['contract_id' => $id, 'series_id' => $seriesId]);
        return $this->ok(['id' => $id]);
    }
}
