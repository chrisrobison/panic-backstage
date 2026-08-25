<?php
declare(strict_types=1);

namespace Panic\Events;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;
use function Panic\log_activity;

final class Settlement extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        $eventId = $this->requireEventId();
        if ($denied = $this->requireEventCapability($eventId, $request->method() === 'GET' ? 'view_settlement' : 'edit_settlement')) {
            return $denied;
        }
        return match ($request->method()) {
            'GET' => $this->ok(['settlement' => $this->db->one('SELECT * FROM event_settlements WHERE event_id = ?', [$eventId])]),
            'POST', 'PATCH' => $this->save($request, $eventId),
            default => Response::methodNotAllowed()
        };
    }

    private const FIELDS = [
        'gross_ticket_sales', 'tickets_sold', 'bar_sales', 'expenses',
        'band_payouts', 'promoter_payout', 'venue_net', 'notes',
    ];

    /**
     * Partial update: only columns present in the request body are touched.
     *
     * This used to be a blind upsert that defaulted every omitted field to
     * 0/null — harmless while the old Settlement tab's full 7-field form was
     * the only caller (it always submitted everything together), but the
     * Closeout tab's "door sales entered manually" fallback only ever
     * submits tickets_sold/gross_ticket_sales, and a blind upsert would have
     * silently zeroed out bar_sales/expenses/band_payouts/promoter_payout on
     * any event that had a fuller settlement recorded earlier. See
     * dev-environment memory / commit history around the Settlement ->
     * Closeout tab consolidation for the full story.
     */
    private function save(Request $request, int $eventId): Response
    {
        $b = $request->body();

        $provided = array_intersect_key($b, array_flip(self::FIELDS));
        if (empty($provided)) {
            return Response::json(['error' => 'No settlement fields provided'], 422);
        }

        // Ensure a row exists without touching any column on an existing one
        // — event_id is UNIQUE, so this is a harmless no-op once a row is
        // already there.
        $this->db->run(
            'INSERT INTO event_settlements (event_id) VALUES (?) ON DUPLICATE KEY UPDATE event_id = event_id',
            [$eventId]
        );

        $sets   = [];
        $params = [];
        foreach ($provided as $field => $value) {
            // Safe to interpolate: $field is drawn only from the fixed
            // self::FIELDS whitelist via array_intersect_key() above, never
            // from $b's keys directly.
            $sets[]   = "$field = ?";
            $params[] = $value;
        }
        $sets[]   = 'settled_by_user_id = ?';
        $params[] = $this->userId();
        $params[] = $eventId;

        $this->db->run(
            'UPDATE event_settlements SET ' . implode(', ', $sets) . ' WHERE event_id = ?',
            $params
        );

        log_activity($this->db, $eventId, $this->userId(), 'settlement saved', ['fields' => array_keys($provided)]);
        return $this->ok(['ok' => true]);
    }
}
