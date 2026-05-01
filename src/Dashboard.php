<?php
declare(strict_types=1);

namespace Panic;

final class Dashboard extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        [$scopeSql, $scopeParams] = $this->eventScopeSql('e');
        $events = $this->db->all(
            "SELECT e.*, u.name owner_name,
              (SELECT title FROM event_blockers b WHERE b.event_id = e.id AND b.status IN ('open','waiting') ORDER BY due_date, id LIMIT 1) primary_blocker,
              (SELECT COUNT(*) FROM event_tasks t WHERE t.event_id = e.id AND t.status NOT IN ('done','canceled')) incomplete_tasks,
              (SELECT COUNT(*) FROM event_blockers b WHERE b.event_id = e.id AND b.status IN ('open','waiting')) open_items,
              (SELECT COUNT(*) FROM event_assets a WHERE a.event_id = e.id AND a.asset_type = 'flyer' AND a.approval_status = 'approved') approved_flyers
             FROM events e
             LEFT JOIN users u ON u.id = e.owner_user_id
             WHERE e.date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)
               AND $scopeSql
             ORDER BY e.date, e.show_time",
            $scopeParams
        );
        [$settlementSql, $settlementParams] = $this->settlementScopeSql();
        $nextEmpty = $this->db->one("SELECT e.date FROM events e WHERE e.date >= CURDATE() AND e.status IN ('empty','hold') AND $scopeSql ORDER BY e.date LIMIT 1", $scopeParams);
        $oldestUnsettled = $this->db->one("SELECT e.id, e.title, e.date FROM events e LEFT JOIN event_settlements s ON s.event_id = e.id WHERE e.status = 'completed' AND s.id IS NULL AND $scopeSql AND $settlementSql ORDER BY e.date LIMIT 1", array_merge($scopeParams, $settlementParams));
        $events = array_map(fn ($event) => $event + ['capabilities' => $this->eventCapabilities((int) $event['id'])], $events);
        $cards = [
            'empty' => $this->count("SELECT COUNT(*) c FROM events e WHERE e.date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY) AND e.status IN ('empty','hold') AND $scopeSql", $scopeParams),
            'needsAssets' => $this->count("SELECT COUNT(*) c FROM events e WHERE e.status IN ('confirmed','needs_assets') AND e.id NOT IN (SELECT event_id FROM event_assets WHERE asset_type='flyer' AND approval_status='approved') AND $scopeSql", $scopeParams),
            'ready' => $this->count("SELECT COUNT(*) c FROM events e WHERE e.status = 'ready_to_announce' AND $scopeSql", $scopeParams),
            'blockers' => $this->count("SELECT COUNT(*) c FROM event_blockers b JOIN events e ON e.id = b.event_id WHERE b.status IN ('open','waiting') AND $scopeSql", $scopeParams),
            'urgentItems' => $this->count("SELECT COUNT(*) c FROM event_blockers b JOIN events e ON e.id = b.event_id WHERE b.status IN ('open','waiting') AND b.due_date <= DATE_ADD(CURDATE(), INTERVAL 2 DAY) AND $scopeSql", $scopeParams),
            'published' => $this->count("SELECT COUNT(*) c FROM events e WHERE e.status = 'published' AND e.date >= CURDATE() AND $scopeSql", $scopeParams),
            'unsettled' => $this->count("SELECT COUNT(*) c FROM events e LEFT JOIN event_settlements s ON s.event_id = e.id WHERE e.status = 'completed' AND s.id IS NULL AND $scopeSql AND $settlementSql", array_merge($scopeParams, $settlementParams)),
        ];
        return $this->ok([
            'cards' => $cards,
            'events' => $events,
            'highlights' => [
                'next_empty_date' => $nextEmpty['date'] ?? null,
                'oldest_unsettled' => $oldestUnsettled,
            ],
        ]);
    }

    private function count(string $sql, array $params = []): int
    {
        return (int) ($this->db->one($sql, $params)['c'] ?? 0);
    }

    private function settlementScopeSql(): array
    {
        if ($this->isVenueAdmin()) {
            return ['1=1', []];
        }
        return [
            "(e.owner_user_id = ? OR EXISTS (SELECT 1 FROM event_collaborators ec_settle WHERE ec_settle.event_id = e.id AND ec_settle.user_id = ? AND ec_settle.role IN ('venue_admin','event_owner')))",
            [$this->userId(), $this->userId()],
        ];
    }
}
