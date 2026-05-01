<?php
declare(strict_types=1);

namespace Panic;

final class Invites extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        $token = $this->params['token'] ?? null;
        if (!$token) {
            return $this->notFound('Invite not found');
        }
        return match ($request->method()) {
            'GET' => $this->show($token),
            'POST' => $this->accept($request, $token),
            default => Response::methodNotAllowed()
        };
    }

    private function show(string $token): Response
    {
        $invite = $this->db->one(
            'SELECT i.*, e.title event_title FROM event_invites i JOIN events e ON e.id = i.event_id WHERE i.token = ? AND i.used_at IS NULL AND i.expires_at > NOW()',
            [$token]
        );
        return $invite ? $this->ok(['invite' => $invite]) : $this->notFound('Invite unavailable');
    }

    private function accept(Request $request, string $token): Response
    {
        $invite = $this->db->one('SELECT * FROM event_invites WHERE token = ? AND used_at IS NULL AND expires_at > NOW()', [$token]);
        if (!$invite) {
            return $this->notFound('Invite unavailable');
        }
        $user = $this->db->one('SELECT * FROM users WHERE email = ? LIMIT 1', [$invite['email']]);
        if (!$user) {
            $id = $this->db->insert('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)', [
                $request->body('name') ?: $invite['email'],
                $invite['email'],
                password_hash((string) $request->body('password', bin2hex(random_bytes(8))), PASSWORD_DEFAULT),
                $invite['role'],
            ]);
            $user = $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
        }
        $this->db->run('INSERT IGNORE INTO event_collaborators (event_id, user_id, role) VALUES (?, ?, ?)', [$invite['event_id'], $user['id'], $invite['role']]);
        $this->db->run('UPDATE event_invites SET used_at = NOW() WHERE id = ?', [$invite['id']]);
        $this->auth->login($user);
        return $this->ok(['event_id' => (int) $invite['event_id'], 'user' => $this->auth->user(), 'csrf' => $this->auth->csrf()]);
    }
}
