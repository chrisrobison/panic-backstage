<?php
declare(strict_types=1);

namespace Panic\Events;

use Panic\BaseEndpoint;
use Panic\Mailer;
use Panic\Request;
use Panic\Response;
use function Panic\log_activity;

/**
 *   GET    /api/events/{id}/invites                      list invites
 *   POST   /api/events/{id}/invites                      create invite (optionally email)
 *   POST   /api/events/{id}/invites/{inviteId}           resend email for an existing invite
 *
 * The create payload accepts a `send_email` flag (defaults to true for
 * backward compatibility). When false, the invite link is generated and
 * returned but no email is delivered — useful when an admin wants to copy
 * the link and share it manually.
 */
final class Invites extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        $eventId = $this->requireEventId();
        if ($denied = $this->requireEventCapability($eventId, 'manage_invites')) {
            return $denied;
        }
        $inviteId = $this->params['inviteId'] ?? null;
        return match ($request->method()) {
            'GET'  => $this->ok([
                'invites' => $this->db->all(
                    'SELECT * FROM event_invites WHERE event_id = ? ORDER BY created_at DESC',
                    [$eventId]
                ),
            ]),
            'POST' => $inviteId
                ? $this->resend($eventId, (int) $inviteId)
                : $this->create($request, $eventId),
            default => Response::methodNotAllowed(),
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

        // Reuse any active, unused invite for the same email + event so a
        // duplicate "add" doesn't create a second token to confuse the user.
        $existing = $this->db->one(
            'SELECT id, token FROM event_invites WHERE event_id = ? AND email = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1',
            [$eventId, $email]
        );
        if ($existing) {
            $token = $existing['token'];
            $inviteId = (int) $existing['id'];
        } else {
            $token = bin2hex(random_bytes(24));
            $inviteId = $this->db->insert(
                'INSERT INTO event_invites (event_id, email, role, token, expires_at)
                 VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 14 DAY))',
                [$eventId, $email, $role, $token]
            );
        }

        // `send_email` defaults to true so existing clients keep their old
        // behavior; the new UI sends an explicit value either way.
        $sendEmail = $this->boolish($request->body('send_email', true));
        $emailed   = false;
        if ($sendEmail) {
            $this->sendInviteEmail($eventId, $email, $token);
            $emailed = true;
            log_activity($this->db, $eventId, $this->userId(), 'invite emailed', [
                'invite_id' => $inviteId,
                'email'     => $email,
                'role'      => $role,
            ]);
        }

        return $this->ok([
            'id'      => $inviteId,
            'token'   => $token,
            'url'     => "invite.html?token={$token}",
            'emailed' => $emailed,
        ]);
    }

    private function resend(int $eventId, int $inviteId): Response
    {
        $invite = $this->db->one(
            'SELECT id, email, token, used_at, expires_at
             FROM event_invites WHERE id = ? AND event_id = ? LIMIT 1',
            [$inviteId, $eventId]
        );
        if (!$invite) {
            return $this->notFound('Invite not found');
        }
        if ($invite['used_at']) {
            return Response::json(['error' => 'This invite has already been accepted.'], 422);
        }
        if (strtotime((string) $invite['expires_at']) < time()) {
            return Response::json(['error' => 'This invite has expired. Create a new one.'], 422);
        }

        $this->sendInviteEmail($eventId, (string) $invite['email'], (string) $invite['token']);
        log_activity($this->db, $eventId, $this->userId(), 'invite re-emailed', [
            'invite_id' => $inviteId,
            'email'     => $invite['email'],
        ]);
        return $this->ok(['ok' => true, 'emailed' => true]);
    }

    /** Build and dispatch the standard invite email via the shared Mailer. */
    private function sendInviteEmail(int $eventId, string $email, string $token): void
    {
        $event  = $this->db->one('SELECT title FROM events WHERE id = ?', [$eventId]);
        $title  = (string) ($event['title'] ?? 'an event');
        $appUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/');
        $url    = "{$appUrl}/invite.html?token={$token}";

        (new Mailer($this->root))->sendTemplate(
            $email,
            "You're invited to collaborate on {$title}",
            'event-invite',
            [
                'event_title' => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
                'invite_url'  => htmlspecialchars($url,   ENT_QUOTES, 'UTF-8'),
            ]
        );
    }

    /** Accept the standard truthy variants the JSON body might carry. */
    private function boolish(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value))  return $value !== 0;
        if (is_string($value)) {
            return in_array(strtolower($value), ['1','true','on','yes'], true);
        }
        return false;
    }
}
