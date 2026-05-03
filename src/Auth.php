<?php
declare(strict_types=1);

namespace Panic;

/**
 * JWT authentication — HS256, no external library.
 *
 * Responsibilities:
 *   - Parse + validate a Bearer token from an incoming request
 *   - Issue signed access tokens (60-minute JWT)
 *   - Generate and hash opaque tokens (magic links, refresh tokens)
 *
 * Refresh-token DB operations live in AuthEndpoint to keep this class
 * stateless with respect to the database.
 */
final class Auth
{
    private ?array $currentUser = null;
    private string $secret;

    public function __construct()
    {
        $this->secret = (string) (getenv('JWT_SECRET') ?: '');
    }

    // -------------------------------------------------------------------------
    // Request authentication
    // -------------------------------------------------------------------------

    /**
     * Read Authorization: Bearer <token> from the request and, if valid,
     * populate the current user.  Called once per request in the Kernel.
     */
    public function authenticate(Request $request): void
    {
        $header = $request->header('Authorization') ?? '';
        if (!str_starts_with($header, 'Bearer ')) {
            return;
        }
        $payload = $this->validateAccessToken(substr($header, 7));
        if ($payload !== null) {
            $this->currentUser = [
                'id'    => (int) ($payload['sub'] ?? 0),
                'name'  => (string) ($payload['name'] ?? ''),
                'email' => (string) ($payload['email'] ?? ''),
                'role'  => (string) ($payload['role'] ?? 'viewer'),
            ];
        }
    }

    /**
     * Directly set the authenticated user (used after a successful
     * magic-link verify or invite accept so the same request can
     * call capability helpers without re-issuing a token).
     */
    public function setUser(array $user): void
    {
        $this->currentUser = [
            'id'    => (int) $user['id'],
            'name'  => (string) $user['name'],
            'email' => (string) $user['email'],
            'role'  => (string) $user['role'],
        ];
    }

    /** Returns the currently authenticated user, or null. */
    public function user(): ?array
    {
        return $this->currentUser;
    }

    // -------------------------------------------------------------------------
    // Token helpers
    // -------------------------------------------------------------------------

    /** Issue a signed HS256 access token valid for 60 minutes. */
    public function issueAccessToken(array $user): string
    {
        return $this->buildJwt([
            'sub'   => (int) $user['id'],
            'name'  => $user['name'],
            'email' => $user['email'],
            'role'  => $user['role'],
            'iat'   => time(),
            'exp'   => time() + 3600,
        ]);
    }

    /** Generate a cryptographically random opaque token (hex string). */
    public function generateToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /** One-way hash for storing tokens in the DB. */
    public function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    // -------------------------------------------------------------------------
    // Internal JWT primitives
    // -------------------------------------------------------------------------

    private function buildJwt(array $payload): string
    {
        if ($this->secret === '') {
            throw new \RuntimeException('JWT_SECRET is not configured');
        }
        $header = $this->b64u((string) json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $body   = $this->b64u((string) json_encode($payload));
        $sig    = $this->b64u(hash_hmac('sha256', "$header.$body", $this->secret, true));
        return "$header.$body.$sig";
    }

    private function validateAccessToken(string $token): ?array
    {
        if ($this->secret === '') {
            return null;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$header, $body, $sig] = $parts;
        $expected = $this->b64u(hash_hmac('sha256', "$header.$body", $this->secret, true));
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $pad  = strlen($body) % 4 === 0 ? 0 : 4 - (strlen($body) % 4);
        $data = json_decode(base64_decode(strtr($body, '-_', '+/') . str_repeat('=', $pad)), true);
        if (!is_array($data) || (int) ($data['exp'] ?? 0) < time()) {
            return null;
        }
        return $data;
    }

    private function b64u(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
