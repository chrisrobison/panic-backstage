<?php
declare(strict_types=1);

namespace Panic;

final class Dashboard extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        $events = $this->db->all(
            "SELECT e.*, u.name owner_name,
              (SELECT title FROM event_blockers b WHERE b.event_id = e.id AND b.status IN ('open','waiting') ORDER BY due_date, id LIMIT 1) primary_blocker,
              (SELECT COUNT(*) FROM event_tasks t WHERE t.event_id = e.id AND t.status NOT IN ('done','canceled')) incomplete_tasks,
              (SELECT COUNT(*) FROM event_assets a WHERE a.event_id = e.id AND a.asset_type = 'flyer' AND a.approval_status = 'approved') approved_flyers
             FROM events e
             LEFT JOIN users u ON u.id = e.owner_user_id
             WHERE e.date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)
             ORDER BY e.date, e.show_time"
        );
        $cards = [
            'empty' => $this->count("SELECT COUNT(*) c FROM events WHERE date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY) AND status IN ('empty','hold')"),
            'needsAssets' => $this->count("SELECT COUNT(*) c FROM events WHERE status IN ('confirmed','needs_assets') AND id NOT IN (SELECT event_id FROM event_assets WHERE asset_type='flyer' AND approval_status='approved')"),
            'ready' => $this->count("SELECT COUNT(*) c FROM events WHERE status = 'ready_to_announce'"),
            'blockers' => $this->count("SELECT COUNT(DISTINCT event_id) c FROM event_blockers WHERE status IN ('open','waiting')"),
            'published' => $this->count("SELECT COUNT(*) c FROM events WHERE status = 'published' AND date >= CURDATE()"),
            'unsettled' => $this->count("SELECT COUNT(*) c FROM events e LEFT JOIN event_settlements s ON s.event_id = e.id WHERE e.status = 'completed' AND s.id IS NULL"),
        ];
        return $this->ok(['cards' => $cards, 'events' => $events]);
    }

    private function count(string $sql): int
    {
        return (int) ($this->db->one($sql)['c'] ?? 0);
    }
}
