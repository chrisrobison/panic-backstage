<?php
declare(strict_types=1);

namespace Panic\Events;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;
use function Panic\log_activity;

/**
 * Live event execution records — incidents, change orders, bar notes, etc.
 *
 *   GET    /api/events/{id}/execution              list records (incidents filtered by capability)
 *   POST   /api/events/{id}/execution              create a record
 *   PATCH  /api/events/{id}/execution/{rid}        update a record
 *   DELETE /api/events/{id}/execution/{rid}        delete
 *
 * Incident records (record_type='incident' or is_restricted=1) are only
 * visible to users with the view_incidents capability.
 *
 * Change orders / overages with amount are linked to the financial closeout.
 *
 * Capabilities:
 *   read_event         — see non-incident records
 *   view_incidents     — also see incident/restricted records
 *   manage_execution   — create/edit any record
 *   manage_incidents   — create/edit incident/restricted records
 */
final class Execution extends BaseEndpoint
{
    private const TYPES = [
        'incident','change_order','bar_note','damage','overage',
        'checklist','deviation','safety_note','general',
    ];

    private const RESTRICTED_TYPES = ['incident','safety_note'];

    public function handle(Request $request): Response
    {
        $eventId  = $this->requireEventId();
        $recordId = $this->params['recordId'] ?? null;

        $isWrite = in_array($request->method(), ['POST','PATCH','DELETE'], true);
        if ($isWrite) {
            if ($denied = $this->requireEventCapability($eventId, 'manage_execution')) {
                return $denied;
            }
        } else {
            if ($denied = $this->requireEventCapability($eventId, 'read_event')) {
                return $denied;
            }
        }

        return match ($request->method()) {
            'GET'    => $recordId ? $this->show($eventId, (int) $recordId) : $this->index($request, $eventId),
            'POST'   => $this->create($request, $eventId),
            'PATCH'  => $this->update($request, $eventId, (int) $recordId),
            'DELETE' => $this->delete($eventId, (int) $recordId),
            default  => Response::methodNotAllowed(),
        };
    }

    private function canViewIncidents(int $eventId): bool
    {
        return $this->hasEventCapability($eventId, 'view_incidents')
            || $this->hasEventCapability($eventId, 'manage_incidents')
            || $this->isVenueAdmin();
    }

    private function index(Request $request, int $eventId): Response
    {
        $canViewRestricted = $this->canViewIncidents($eventId);

        $where  = ['r.event_id = ?'];
        $params = [$eventId];

        if (!$canViewRestricted) {
            $where[] = 'r.is_restricted = 0';
        }

        if ($request->query('type')) {
            $type = $request->query('type');
            if (in_array($type, self::TYPES, true)) {
                $where[] = 'r.record_type = ?';
                $params[] = $type;
            }
        }

        $records = $this->db->all(
            "SELECT r.*, u.name author_name FROM event_execution_records r
             LEFT JOIN users u ON u.id = r.author_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY COALESCE(r.occurred_at, r.created_at) DESC",
            $params
        );

        return $this->ok([
            'records'          => $records,
            'types'            => self::TYPES,
            'can_view_incidents' => $canViewRestricted,
        ]);
    }

    private function show(int $eventId, int $recordId): Response
    {
        $record = $this->db->one(
            'SELECT r.*, u.name author_name FROM event_execution_records r
             LEFT JOIN users u ON u.id = r.author_id
             WHERE r.id = ? AND r.event_id = ?',
            [$recordId, $eventId]
        );
        if (!$record) {
            return $this->notFound();
        }

        if ($record['is_restricted'] && !$this->canViewIncidents($eventId)) {
            return $this->forbidden('Incident records require elevated access');
        }

        return $this->ok(['record' => $record]);
    }

    private function create(Request $request, int $eventId): Response
    {
        $b = $request->body();

        $type = (string) ($b['record_type'] ?? 'general');
        if (!in_array($type, self::TYPES, true)) {
            $type = 'general';
        }

        $isRestricted = in_array($type, self::RESTRICTED_TYPES, true) || !empty($b['is_restricted']);

        // Incident creation requires higher capability
        if ($isRestricted && !$this->canViewIncidents($eventId)) {
            return $this->forbidden('Creating incident records requires elevated access');
        }

        $title = trim((string) ($b['title'] ?? ''));
        if ($title === '') {
            return Response::json(['error' => 'title is required'], 422);
        }

        $occurredAt = $b['occurred_at'] ?? null;
        if (!$occurredAt) {
            $occurredAt = date('Y-m-d H:i:s');
        }

        $id = $this->db->insert(
            'INSERT INTO event_execution_records
             (event_id, record_type, title, body, occurred_at, amount, client_approved,
              approved_by, is_restricted, author_id, author_role)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [
                $eventId,
                $type,
                $title,
                $b['body']           ?? null,
                $occurredAt,
                isset($b['amount'])  ? (float) $b['amount'] : null,
                !empty($b['client_approved']) ? 1 : 0,
                $b['approved_by']    ?? null,
                $isRestricted ? 1 : 0,
                $this->userId(),
                $this->role(),
            ]
        );

        log_activity($this->db, $eventId, $this->userId(), "execution record added: $type", [
            'record_id' => $id,
            'type'      => $type,
        ]);

        // Link to ledger if this is a chargeable change order/overage
        if (in_array($type, ['change_order','overage','damage'], true) && !empty($b['amount'])) {
            $category = match($type) {
                'overage'      => 'overtime_charge',
                'damage'       => 'other_revenue',
                'change_order' => 'other_revenue',
                default        => 'other_revenue',
            };
            $ledgerEntryId = $this->db->insert(
                'INSERT INTO event_ledger_entries
                 (event_id, category, line_type, amount, description, source, source_ref_id, created_by_id)
                 VALUES (?,?,?,?,?,?,?,?)',
                [
                    $eventId,
                    $category,
                    'revenue',
                    (float) $b['amount'],
                    "$type: $title",
                    'change_order_link',
                    $id,
                    $this->userId(),
                ]
            );
            $this->db->run(
                'UPDATE event_execution_records SET linked_ledger_entry_id = ? WHERE id = ?',
                [$ledgerEntryId, $id]
            );
        }

        return $this->ok(['id' => $id]);
    }

    private function update(Request $request, int $eventId, int $recordId): Response
    {
        $record = $this->db->one(
            'SELECT * FROM event_execution_records WHERE id = ? AND event_id = ?',
            [$recordId, $eventId]
        );
        if (!$record) {
            return $this->notFound();
        }

        if ($record['is_restricted'] && !$this->canViewIncidents($eventId)) {
            return $this->forbidden();
        }

        $b      = $request->body();
        $sets   = [];
        $params = [];

        foreach (['title','body','occurred_at','amount','client_approved','approved_by'] as $f) {
            if (!array_key_exists($f, $b)) continue;
            $val      = $f === 'client_approved' ? (!empty($b[$f]) ? 1 : 0) : $b[$f];
            $sets[]   = "$f = ?";
            $params[] = $val;
        }

        if (empty($sets)) {
            return $this->ok(['ok' => true]);
        }

        $params[] = $recordId;
        $this->db->run('UPDATE event_execution_records SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);

        return $this->ok(['ok' => true]);
    }

    private function delete(int $eventId, int $recordId): Response
    {
        $record = $this->db->one(
            'SELECT is_restricted FROM event_execution_records WHERE id = ? AND event_id = ?',
            [$recordId, $eventId]
        );
        if (!$record) {
            return $this->notFound();
        }
        if ($record['is_restricted'] && !$this->canViewIncidents($eventId)) {
            return $this->forbidden();
        }
        $this->db->run(
            'DELETE FROM event_execution_records WHERE id = ? AND event_id = ?',
            [$recordId, $eventId]
        );
        return Response::noContent();
    }
}
