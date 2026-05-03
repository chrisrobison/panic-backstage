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
        if ($denied = $this->requireEventCapability($eventId, 'manage_invites')) {
            return $denied;
        }
        return match ($request->method()) {
            'GET' => $this->ok(['invites' => $this->db->all('SELECT * FROM event_invites WHERE event_id = ? ORDER BY created_at DESC', [$eventId])]),
            'POST' => $this->create($request, $eventId),
            default => Response::methodNotAllowed()
        };
    }

    private function create(Request $request, int $eventId): Response
    {
        $email = trim(strtolower((string) $request->body('email', '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::json(['error' => 'Valid email is required'], 422);
        }
        $role = (string) $request->body('role', 'viewer');
        $roles = $this->isVenueAdmin()
            ? ['event_owner','promoter','band','artist','designer','staff','viewer']
            : ['promoter','band','artist','designer','staff','viewer'];
        if (!in_array($role, $roles, true)) {
            return Response::json(['error' => 'Invalid invite role'], 422);
        }

        // Check for an existing un-used invite for this address + event
        $existing = $this->db->one(
            'SELECT token FROM event_invites WHERE event_id = ? AND email = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1',
            [$eventId, $email]
        );
        if ($existing) {
            $token = $existing['token'];
        } else {
            $token = bin2hex(random_bytes(24));
            $this->db->insert(
                'INSERT INTO event_invites (event_id, email, role, token, expires_at)
                 VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 14 DAY))',
                [$eventId, $email, $role, $token]
            );
        }

        $event  = $this->db->one('SELECT title FROM events WHERE id = ?', [$eventId]);
        $title  = $event['title'] ?? 'an event';
        $appUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/');
        $url    = "{$appUrl}/invite.html?token={$token}";

        (new \Panic\Mailer($this->root))->send(
            $email,
            "You're invited to collaborate on {$title}",
            "You've been invited to join the team for {$title}.\n\n"
            . "Click the link below to accept your invitation:\n\n"
            . "  {$url}\n\n"
            . "This invitation expires in 14 days.\n"
        );

        return $this->ok(['token' => $token, 'url' => "invite.html?token={$token}"]);
    }
}
