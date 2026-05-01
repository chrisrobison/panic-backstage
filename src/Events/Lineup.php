<?php
declare(strict_types=1);

namespace Panic\Events;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;
use function Panic\log_activity;

final class Lineup extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        $eventId = $this->requireEventId();
        $lineupId = (int) ($this->params['lineupId'] ?? 0);
        return match ($request->method()) {
            'GET' => $this->ok(['lineup' => $this->db->all('SELECT * FROM event_lineup WHERE event_id = ? ORDER BY billing_order, set_time', [$eventId])]),
            'POST' => $this->create($request, $eventId),
            'PATCH' => $this->update($request, $eventId, $lineupId),
            'DELETE' => $this->delete($eventId, $lineupId),
            default => Response::methodNotAllowed()
        };
    }

    private function create(Request $request, int $eventId): Response
    {
        $bandId = null;
        $bandName = trim((string) $request->body('band_name', ''));
        if ($bandName !== '') {
            $band = $this->db->one('SELECT id FROM bands WHERE name = ? LIMIT 1', [$bandName]);
            $bandId = $band ? (int) $band['id'] : $this->db->insert('INSERT INTO bands (name) VALUES (?)', [$bandName]);
        }
        $displayName = $request->body('display_name') ?: $bandName;
        $id = $this->db->insert('INSERT INTO event_lineup (event_id, band_id, billing_order, display_name, set_time, set_length_minutes, payout_terms, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $eventId, $bandId, (int) $request->body('billing_order', 0), $displayName, $request->body('set_time') ?: null, $request->body('set_length_minutes') ?: null, $request->body('payout_terms'), $request->body('status', 'tentative'), $request->body('notes')
        ]);
        log_activity($this->db, $eventId, $this->userId(), 'lineup changed', ['action' => 'added']);
        return $this->ok(['id' => $id]);
    }

    private function update(Request $request, int $eventId, int $lineupId): Response
    {
        $this->db->run('UPDATE event_lineup SET billing_order=?, display_name=?, set_time=?, set_length_minutes=?, payout_terms=?, status=?, notes=? WHERE id=? AND event_id=?', [
            (int) $request->body('billing_order', 0), $request->body('display_name'), $request->body('set_time') ?: null, $request->body('set_length_minutes') ?: null, $request->body('payout_terms'), $request->body('status'), $request->body('notes'), $lineupId, $eventId
        ]);
        log_activity($this->db, $eventId, $this->userId(), 'lineup changed', ['action' => 'updated']);
        return $this->ok(['ok' => true]);
    }

    private function delete(int $eventId, int $lineupId): Response
    {
        $this->db->run('DELETE FROM event_lineup WHERE id=? AND event_id=?', [$lineupId, $eventId]);
        log_activity($this->db, $eventId, $this->userId(), 'lineup changed', ['action' => 'deleted']);
        return Response::noContent();
    }
}
