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
        return match ($request->method()) {
            'GET' => $this->ok(['settlement' => $this->db->one('SELECT * FROM event_settlements WHERE event_id = ?', [$eventId])]),
            'POST', 'PATCH' => $this->save($request, $eventId),
            default => Response::methodNotAllowed()
        };
    }

    private function save(Request $request, int $eventId): Response
    {
        $b = $request->body();
        $this->db->run(
            'INSERT INTO event_settlements (event_id, gross_ticket_sales, tickets_sold, bar_sales, expenses, band_payouts, promoter_payout, venue_net, notes, settled_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE gross_ticket_sales=VALUES(gross_ticket_sales), tickets_sold=VALUES(tickets_sold), bar_sales=VALUES(bar_sales), expenses=VALUES(expenses), band_payouts=VALUES(band_payouts), promoter_payout=VALUES(promoter_payout), venue_net=VALUES(venue_net), notes=VALUES(notes), settled_by_user_id=VALUES(settled_by_user_id)',
            [$eventId, $b['gross_ticket_sales'] ?? 0, $b['tickets_sold'] ?? 0, $b['bar_sales'] ?? 0, $b['expenses'] ?? 0, $b['band_payouts'] ?? 0, $b['promoter_payout'] ?? 0, $b['venue_net'] ?? 0, $b['notes'] ?? null, $this->userId()]
        );
        log_activity($this->db, $eventId, $this->userId(), 'settlement saved');
        return $this->ok(['ok' => true]);
    }
}
