<?php
declare(strict_types=1);

namespace Panic\Events;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;

final class Invites extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        $eventId = $this->requireEventId();
        return match ($request->method()) {
            'GET' => $this->ok(['invites' => $this->db->all('SELECT * FROM event_invites WHERE event_id = ? ORDER BY created_at DESC', [$eventId])]),
            'POST' => $this->create($request, $eventId),
            default => Response::methodNotAllowed()
        };
    }

    private function create(Request $request, int $eventId): Response
    {
        $token = bin2hex(random_bytes(24));
        $this->db->insert('INSERT INTO event_invites (event_id, email, role, token, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 14 DAY))', [
            $eventId, $request->body('email'), $request->body('role', 'viewer'), $token
        ]);
        return $this->ok(['token' => $token, 'url' => '/invite.html?token=' . $token]);
    }
}
