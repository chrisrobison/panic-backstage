<?php
declare(strict_types=1);

namespace Panic;

/**
 * Passwordless authentication endpoints.
 *
 * POST /api/auth/magic-link          Request a magic-link email
 * POST /api/auth/verify              Exchange magic-link token → JWT pair
 * POST /api/auth/refresh             Exchange refresh token → new JWT pair (rotates token)
 * POST /api/auth/logout              Revoke a refresh token
 */
final class AuthEndpoint extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            return Response::methodNotAllowed();
        }
        return match ($this->params['action'] ?? '') {
            'magic-link' => $this->requestMagicLink($request),
            'verify'     => $this->verify($request),
            'refresh'    => $this->refresh($request),
            'logout'     => $this->logout($request),
            default      => $this->notFound(),
        };
    }

    // -------------------------------------------------------------------------
    // Request a magic link
    // -------------------------------------------------------------------------

    private function requestMagicLink(Request $request): Response
    {
        $email = trim(strtolower((string) $request->body('email', '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::json(['error' => 'Valid email is required'], 422);
        }

        $token = $this->auth->generateToken(24);
        $hash  = $this->auth->hashToken($token);

        $this->db->run(
            'INSERT INTO magic_link_tokens (email, token_hash, expires_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))',
            [$email, $hash]
        );

        $appUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/');
        $link   = "{$appUrl}/login.html?token={$token}";

        (new Mailer($this->root))->send(
            $email,
            'Your Backstage login link',
            "Here is your login link — it expires in 15 minutes.\n\n"
            . "  {$link}\n\n"
            . "If you did not request this you can safely ignore this email.\n"
        );

        // Always return ok so we don't reveal whether the address is registered
        return $this->ok(['ok' => true]);
    }

    // -------------------------------------------------------------------------
    // Verify a magic-link token → issue JWT pair
    // -------------------------------------------------------------------------

    private function verify(Request $request): Response
    {
        $token = trim((string) $request->body('token', ''));
        if ($token === '') {
            return Response::json(['error' => 'Token is required'], 422);
        }

        $hash = $this->auth->hashToken($token);
        $row  = $this->db->one(
            'SELECT * FROM magic_link_tokens
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1',
            [$hash]
        );

        if (!$row) {
            return Response::json(['error' => 'Invalid or expired token'], 401);
        }

        $this->db->run(
            'UPDATE magic_link_tokens SET used_at = NOW() WHERE id = ?',
            [$row['id']]
        );

        $user = $this->db->one('SELECT * FROM users WHERE email = ? LIMIT 1', [$row['email']]);
        if (!$user) {
            // First login for this address — create a minimal account
            $id   = $this->db->insert(
                'INSERT INTO users (name, email, role) VALUES (?, ?, ?)',
                [$row['email'], $row['email'], 'viewer']
            );
            $user = $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
        }

        return $this->ok($this->issueTokenPair($user));
    }

    // -------------------------------------------------------------------------
    // Refresh — rotate refresh token, issue new access token
    // -------------------------------------------------------------------------

    private function refresh(Request $request): Response
    {
        $token = trim((string) $request->body('refresh_token', ''));
        if ($token === '') {
            return Response::json(['error' => 'refresh_token is required'], 422);
        }

        $hash = $this->auth->hashToken($token);
        $row  = $this->db->one(
            'SELECT rt.id, rt.user_id,
                    u.name, u.email, u.role
             FROM   refresh_tokens rt
             JOIN   users u ON u.id = rt.user_id
             WHERE  rt.token_hash = ?
               AND  rt.revoked_at IS NULL
               AND  rt.expires_at > NOW()
             LIMIT  1',
            [$hash]
        );

        if (!$row) {
            return Response::json(['error' => 'Invalid or expired refresh token'], 401);
        }

        // Revoke the used token immediately (rotation prevents replay)
        $this->db->run(
            'UPDATE refresh_tokens SET revoked_at = NOW() WHERE id = ?',
            [$row['id']]
        );

        $user = [
            'id'    => (int) $row['user_id'],
            'name'  => $row['name'],
            'email' => $row['email'],
            'role'  => $row['role'],
        ];

        return $this->ok($this->issueTokenPair($user));
    }

    // -------------------------------------------------------------------------
    // Logout — revoke a refresh token
    // -------------------------------------------------------------------------

    private function logout(Request $request): Response
    {
        $token = trim((string) $request->body('refresh_token', ''));
        if ($token !== '') {
            $this->db->run(
                'UPDATE refresh_tokens SET revoked_at = NOW() WHERE token_hash = ?',
                [$this->auth->hashToken($token)]
            );
        }
        return $this->ok(['ok' => true]);
    }

    // -------------------------------------------------------------------------
    // Shared: mint a fresh access + refresh token pair
    // -------------------------------------------------------------------------

    private function issueTokenPair(array $user): array
    {
        $refreshToken = $this->auth->generateToken(32);

        $this->db->insert(
            'INSERT INTO refresh_tokens (user_id, token_hash, expires_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 60 DAY))',
            [(int) $user['id'], $this->auth->hashToken($refreshToken)]
        );

        // Populate auth so globalCapabilities() resolves correctly
        $this->auth->setUser($user);

        return [
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
        ];
    }
}
