<?php
declare(strict_types=1);

namespace Panic;

/**
 * Read-only cross-event gallery of every uploaded asset (flyers, band
 * photos, PDFs, etc.) — lets staff browse everything on the server without
 * opening each event's own Assets tab individually.
 *
 *   GET /api/asset-library
 *     ?q=               free-text match on asset title, original filename, or event title
 *     &asset_type=      event_assets.asset_type enum value
 *     &approval_status= event_assets.approval_status enum value
 *     &event_id=        restrict to one event
 *     &page= &limit=    pagination (default 60/page, max 200)
 *
 * Scoped exactly like the Dashboard/Events list: venue_admin/global_viewer
 * see assets across every event, everyone else only sees assets belonging
 * to events they own or collaborate on (see BaseEndpoint::eventScopeSql()).
 * No separate capability gate beyond that scoping — if you can already see
 * an event, you can already see its assets via that event's own Assets tab,
 * so this is just a different (cross-event) view of the same data.
 */
final class AssetLibrary extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        [$scopeSql, $scopeParams] = $this->eventScopeSql('e');
        $where  = [$scopeSql];
        $params = $scopeParams;

        if ($q = trim((string) $request->query('q', ''))) {
            $where[]  = '(a.title LIKE ? OR a.original_filename LIKE ? OR e.title LIKE ?)';
            $like     = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ($type = $request->query('asset_type')) {
            $where[]  = 'a.asset_type = ?';
            $params[] = $type;
        }
        if ($status = $request->query('approval_status')) {
            $where[]  = 'a.approval_status = ?';
            $params[] = $status;
        }
        if ($eventId = $request->query('event_id')) {
            $where[]  = 'a.event_id = ?';
            $params[] = (int) $eventId;
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $limit  = max(1, min(200, (int) ($request->query('limit') ?: 60)));
        $page   = max(1, (int) ($request->query('page') ?: 1));
        $offset = ($page - 1) * $limit;

        $total = (int) ($this->db->one(
            "SELECT COUNT(*) n FROM event_assets a JOIN events e ON e.id = a.event_id $whereSql",
            $params
        )['n'] ?? 0);

        $assets = $this->db->all(
            "SELECT a.*, e.title event_title, e.date event_date, e.status event_status
             FROM event_assets a
             JOIN events e ON e.id = a.event_id
             $whereSql
             ORDER BY a.created_at DESC
             LIMIT $limit OFFSET $offset",
            $params
        );

        return $this->ok([
            'assets' => $assets,
            'total'  => $total,
            'page'   => $page,
            'limit'  => $limit,
            'pages'  => (int) ceil($total / $limit),
        ]);
    }
}
