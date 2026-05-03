<?php
declare(strict_types=1);

namespace Panic;

/**
 * Top-level invite acceptance (no event-scoped auth required).
 *
 * GET  /api/invite/:token   Preview invite details
 * POST /api/invite/:token   Accept — creates account if needed, issues JWT pair
 */
final class Invites extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        $token = $this->params['token'] ?? null;
        if (!$token) {
            return $this->notFound('Invite not found');
        }
        return match ($request->method()) {
            'GET'  => $this->show($token),
            'POST' => $this->accept($request, $token),
            default => Response::methodNotAllowed(),
        };
    }

    private function show(string $token): Response
    {
        $invite = $this->db->one(
            'SELECT i.id, i.email, i.role, i.expires_at, e.title event_title, e.date event_date
             FROM   event_invites i
             JOIN   events e ON e.id = i.event_id
             WHERE  i.token = ?
               AND  i.used_at IS NULL
               AND  i.expires_at > NOW()',
            [$token]
        );
        return $invite
            ? $this->ok(['invite' => $invite])
            : $this->notFound('Invite unavailable or already used');
    }

    private function accept(Request $request, string $token): Response
    {
        $invite = $this->db->one(
            'SELECT * FROM event_invites
             WHERE  token = ? AND used_at IS NULL AND expires_at > NOW()',
            [$token]
        );
        if (!$invite) {
            return $this->notFound('Invite unavailable or already used');
        }

        // Get or create the user — no password needed
        $user = $this->db->one('SELECT * FROM users WHERE email = ? LIMIT 1', [$invite['email']]);
        if (!$user) {
            $name = trim((string) $request->body('name', ''));
            $id   = $this->db->insert(
                'INSERT INTO users (name, email, role) VALUES (?, ?, ?)',
                [$name !== '' ? $name : $invite['email'], $invite['email'], $invite['role']]
            );
            $user = $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
        }

        // Add to event (safe to call multiple times)
        $this->db->run(
            'INSERT IGNORE INTO event_collaborators (event_id, user_id, role) VALUES (?, ?, ?)',
            [$invite['event_id'], $user['id'], $invite['role']]
        );

        $this->db->run(
            'UPDATE event_invites SET used_at = NOW() WHERE id = ?',
            [$invite['id']]
        );

        // Issue JWT pair
        $refreshToken = $this->auth->generateToken(32);
        $this->db->insert(
            'INSERT INTO refresh_tokens (user_id, token_hash, expires_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 60 DAY))',
            [(int) $user['id'], $this->auth->hashToken($refreshToken)]
        );

        $this->auth->setUser($user);

        return $this->ok([
            'event_id'      => (int) $invite['event_id'],
            'access_token'  => $this->auth->issueAccessToken($user),
            'refresh_token' => $refreshToken,
            'expires_in'    => 3600,
            'user'          => [
                'id'    => (int) $user['id'],
                'name'  => $user['name'],
                'email' => $user['email'],
                'role'  => $user['role'],
            ],
            'capabilities'  => $this->globalCapabilities(),
        ]);
    }
}
