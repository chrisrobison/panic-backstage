<?php
declare(strict_types=1);

namespace Panic\Events;

use Panic\BaseEndpoint;
use Panic\DbHistorySupport;
use Panic\DbHistoryUndoPrecondition;
use Panic\Request;
use Panic\Response;
use function Panic\log_activity;

/**
 * Event-scoped window onto the generic db_history audit trail (see
 * src/DbHistory.php and scripts/generate-audit-triggers.php) — lets anyone
 * who can edit an event browse the writes that touched it and undo any one
 * of them, in any order, without needing the venue-admin-only
 * manage_db_history capability that the full Database History admin screen
 * requires.
 *
 *   GET  /api/events/{id}/history             list entries touching this event
 *   POST /api/events/{id}/history/{hid}/undo  undo one entry (must belong to this event)
 *
 * Gated by edit_event — the same capability that lets you edit the event's
 * own fields at all. Scoping every query to just this event's rows (see
 * scopeSql()) is what makes it safe to hand to event owners/collaborators
 * instead of requiring venue_admin: nobody can browse or undo history for a
 * table/row outside this event through this endpoint.
 */
final class History extends BaseEndpoint
{
    /**
     * Tables (besides `events` itself) whose db_history rows are considered
     * part of "this event's" history, matched via the event_id recorded in
     * old_row/new_row. Deliberately narrower than "every table with an
     * event_id column" — internal sync/plumbing tables (event_sheet_shadow,
     * event_payment_audit, accounting_sync_log, sheet_sync_queue,
     * pos_location_map, email_campaign_events, portal_tokens, promote_*,
     * event_activity_log itself) are left out because their writes aren't
     * actions a user would recognize or want to undo from this menu. Add
     * more here as real undo needs come up.
     */
    private const RELATED_TABLES = [
        'event_execution_records',
        'event_ledger_entries',
        'event_payments',
        'event_tasks',
        'event_blockers',
        'event_vendors',
        'event_lineup',
        'event_schedule_items',
        'event_sessions',
        'event_staffing',
        'event_guest_list',
        'event_invites',
        'event_assets',
        'contracts',
        'ticket_types',
        'tickets',
        'ticket_orders',
        'event_settlements',
        'event_closeout_state',
    ];

    private const LIMIT = 150;

    public function handle(Request $request): Response
    {
        $eventId = $this->requireEventId();
        if ($denied = $this->requireEventCapability($eventId, 'edit_event')) {
            return $denied;
        }

        $historyId = $this->params['historyId'] ?? null;
        $action    = $this->params['action'] ?? null;

        if ($historyId !== null && $action === 'undo') {
            if ($request->method() !== 'POST') {
                return Response::methodNotAllowed();
            }
            return $this->undo($eventId, (int) $historyId);
        }

        if ($request->method() !== 'GET') {
            return Response::methodNotAllowed();
        }
        return $this->list($eventId);
    }

    private function list(int $eventId): Response
    {
        [$where, $params] = $this->scopeSql($eventId);

        $rows = $this->db->all(
            "SELECT id, table_name, pk_column, pk_value, action, actor,
                    undone_at, undone_by_actor, undo_of_id, created_at, old_row, new_row
               FROM db_history
              WHERE $where
              ORDER BY id DESC
              LIMIT ?",
            [...$params, self::LIMIT]
        );

        return $this->ok(['entries' => array_map([DbHistorySupport::class, 'summarize'], $rows)]);
    }

    private function undo(int $eventId, int $historyId): Response
    {
        [$where, $params] = $this->scopeSql($eventId);

        // Confirm this history entry actually belongs to this event before
        // touching it — otherwise an edit_event holder on event A could pass
        // an arbitrary db_history id belonging to event B (or an unrelated
        // table entirely) and undo that instead.
        $belongs = $this->db->one("SELECT id FROM db_history WHERE id = ? AND ($where)", [$historyId, ...$params]);
        if (!$belongs) {
            return $this->notFound('History entry not found for this event');
        }

        $actor = 'user:' . $this->userId();
        try {
            $result = DbHistorySupport::undo($this->db, $historyId, $actor);
        } catch (DbHistoryUndoPrecondition $e) {
            return $e->getCode() === 404
                ? $this->notFound($e->getMessage())
                : Response::json(['error' => $e->getMessage()], 409);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'Undo failed: ' . $e->getMessage()], 500);
        }

        log_activity($this->db, $eventId, $this->userId(), 'history entry undone', [
            'history_id'       => $historyId,
            'result_entry_id'  => $result['result_entry_id'],
        ]);

        return $this->ok([
            'ok'              => true,
            'undone_id'       => $historyId,
            'result_entry_id' => $result['result_entry_id'],
        ]);
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private function scopeSql(int $eventId): array
    {
        $placeholders = implode(',', array_fill(0, count(self::RELATED_TABLES), '?'));
        $sql = "(table_name = 'events' AND pk_value = ?)
             OR (table_name IN ($placeholders) AND (
                   JSON_UNQUOTE(JSON_EXTRACT(old_row, '\$.event_id')) = ?
                OR JSON_UNQUOTE(JSON_EXTRACT(new_row, '\$.event_id')) = ?
             ))";

        $eventIdStr = (string) $eventId;
        return [$sql, [$eventIdStr, ...self::RELATED_TABLES, $eventIdStr, $eventIdStr]];
    }
}
