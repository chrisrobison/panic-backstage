<?php
declare(strict_types=1);

namespace Panic;

/**
 * Admin endpoint for managing login accounts.
 *
 *   GET    /api/users               list users (incl. pending access requests)
 *   POST   /api/users               create user (name, email, role, optional password)
 *   POST   /api/users/{id}/approve  approve a requested account (set role) + email login link
 *   PATCH  /api/users/{id}          update name/email/role and optionally reset password
 *   DELETE /api/users/{id}          delete user (also used to dismiss a request)
 *
 * All actions require the manage_users global capability (venue_admin).
 */
final class Users extends BaseEndpoint
{
    private const ROLES = [
        'venue_admin','event_owner','promoter','band','artist','designer','staff','viewer','global_viewer',
    ];

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireGlobalCapability('manage_users')) {
            return $denied;
        }
        $userId = $this->params['userId'] ?? null;
        $action = $this->params['action'] ?? null;
        if ($request->method() === 'POST' && $userId && $action === 'approve') {
            return $this->approve($request, (int) $userId);
        }
        return match ($request->method()) {
            'GET'    => $userId ? $this->show((int) $userId) : $this->index(),
            'POST'   => $this->create($request),
            'PATCH'  => $this->update($request, (int) $userId),
            'DELETE' => $this->delete((int) $userId),
            default  => Response::methodNotAllowed(),
        };
    }

    private function index(): Response
    {
        $users = $this->db->all(
            "SELECT u.id, u.name, u.email, u.phone, u.role, u.access_status, u.request_notes,
                    u.alt_emails, u.created_at, u.updated_at,
                    (u.password_hash IS NOT NULL) AS has_password,
                    (SELECT COUNT(*) FROM passkeys p WHERE p.user_id = u.id) AS passkey_count,
                    (SELECT COUNT(*) FROM events e WHERE e.owner_user_id = u.id) AS owned_event_count,
                    (SELECT COUNT(*) FROM event_collaborators c WHERE c.user_id = u.id) AS collaborator_event_count
             FROM users u
             ORDER BY (u.access_status = 'requested') DESC, u.name"
        );
        return $this->ok([
            'users' => $users,
            'roles' => self::ROLES,
        ]);
    }

    private function show(int $id): Response
    {
        $user = $this->db->one(
            "SELECT id, name, email, role, created_at, updated_at,
                    (password_hash IS NOT NULL) AS has_password
             FROM users WHERE id = ?",
            [$id]
        );
        if (!$user) {
            return $this->notFound('User not found');
        }
        return $this->ok(['user' => $user]);
    }

    private function create(Request $request): Response
    {
        $name  = trim((string) $request->body('name', ''));
        $email = strtolower(trim((string) $request->body('email', '')));
        $role  = (string) $request->body('role', 'viewer');
        $password = (string) $request->body('password', '');

        if ($name === '' || $email === '') {
            return Response::json(['error' => 'name and email are required'], 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::json(['error' => 'Invalid email'], 422);
        }
        if (!in_array($role, self::ROLES, true)) {
            return Response::json(['error' => 'Invalid role'], 422);
        }
        if ($this->db->one('SELECT id FROM users WHERE email = ?', [$email])) {
            return Response::json(['error' => 'A user with that email already exists'], 409);
        }
        $hash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null;
        $id = $this->db->insert(
            'INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)',
            [$name, $email, $hash, $role]
        );
        return $this->ok(['id' => $id]);
    }

    /**
     * Approve a pending access request: assign the chosen role, flip the
     * account to 'active', mint a 7-day magic link, and email it. Mirrors the
     * flow in scripts/bootstrap-admins.php.
     */
    private function approve(Request $request, int $id): Response
    {
        $user = $this->db->one(
            'SELECT id, name, email, access_status FROM users WHERE id = ?',
            [$id]
        );
        if (!$user) {
            return $this->notFound('User not found');
        }
        if ($user['access_status'] !== 'requested') {
            return Response::json(['error' => 'This account is not awaiting approval'], 409);
        }

        $role = (string) $request->body('role', 'viewer');
        if (!in_array($role, self::ROLES, true)) {
            return Response::json(['error' => 'Invalid role'], 422);
        }

        $this->db->run(
            "UPDATE users SET role = ?, access_status = 'active' WHERE id = ?",
            [$role, $id]
        );

        // Mint a 7-day login link and email it.
        $token = $this->auth->generateToken(24);
        $hash  = $this->auth->hashToken($token);
        $this->db->run(
            'INSERT INTO magic_link_tokens (email, token_hash, expires_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))',
            [$user['email'], $hash]
        );

        $appUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/');
        $link   = "{$appUrl}/login.html?token={$token}";
        $name   = (string) $user['name'];

        (new Mailer($this->root, $this->db))->sendTemplate(
            (string) $user['email'],
            'Your Backstage access has been approved',
            'access-approved',
            [
                'name'      => htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
                'login_url' => htmlspecialchars($link, ENT_QUOTES, 'UTF-8'),
            ]
        );

        return $this->ok(['ok' => true]);
    }

    private function update(Request $request, int $id): Response
    {
        if (!$id) {
            return $this->notFound();
        }
        $existing = $this->db->one('SELECT id, email FROM users WHERE id = ?', [$id]);
        if (!$existing) {
            return $this->notFound('User not found');
        }

        $name  = trim((string) $request->body('name', ''));
        $email = strtolower(trim((string) $request->body('email', '')));
        $role  = (string) $request->body('role', '');
        $password = (string) $request->body('password', '');

        if ($name === '' || $email === '') {
            return Response::json(['error' => 'name and email are required'], 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::json(['error' => 'Invalid email'], 422);
        }
        if (!in_array($role, self::ROLES, true)) {
            return Response::json(['error' => 'Invalid role'], 422);
        }
        $clash = $this->db->one('SELECT id FROM users WHERE email = ? AND id != ?', [$email, $id]);
        if ($clash) {
            return Response::json(['error' => 'Another user already uses that email'], 409);
        }

        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $this->db->run(
                'UPDATE users SET name = ?, email = ?, role = ?, password_hash = ? WHERE id = ?',
                [$name, $email, $role, $hash, $id]
            );
        } else {
            $this->db->run(
                'UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?',
                [$name, $email, $role, $id]
            );
        }
        return $this->ok(['ok' => true]);
    }

    private function delete(int $id): Response
    {
        if (!$id) {
            return $this->notFound();
        }
        if ($id === $this->userId()) {
            return Response::json(['error' => 'You cannot delete your own account'], 422);
        }
        $owned = (int) ($this->db->one('SELECT COUNT(*) c FROM events WHERE owner_user_id = ?', [$id])['c'] ?? 0);
        if ($owned > 0) {
            return Response::json([
                'error' => "Reassign this user's $owned event(s) before deleting them.",
            ], 409);
        }
        $this->db->run('DELETE FROM event_collaborators WHERE user_id = ?', [$id]);
        $this->db->run('DELETE FROM users WHERE id = ?', [$id]);
        return Response::noContent();
    }
}
