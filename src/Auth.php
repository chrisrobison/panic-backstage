<?php
declare(strict_types=1);

namespace Panic;

/**
 * JWT authentication — HS256, no external library.
 *
 * Responsibilities:
 *   - Parse + validate a Bearer token from an incoming request
 *   - Issue short-lived signed access tokens (1 hour by default)
 *   - Generate and hash opaque tokens (magic links, refresh tokens)
 *
 * Refresh-token DB operations live in AuthEndpoint to keep this class
 * stateless with respect to the database.
 */
final class Auth
{
    public const ACCESS_COOKIE = 'backstage_access';
    public const REFRESH_COOKIE = 'backstage_refresh';
    private const DEFAULT_ACCESS_TTL_SECONDS = 3600;

    private ?array $currentUser = null;
    private ?string $currentAccessToken = null;
    private ?string $authenticationSource = null;
    private string $secret;

    public function __construct()
    {
        $this->secret = (string) (getenv('JWT_SECRET') ?: '');
    }

    // -------------------------------------------------------------------------
    // Request authentication
    // -------------------------------------------------------------------------

    /**
     * Read a Bearer token or the HttpOnly browser access cookie and, if
     * valid, populate the current user. Called once per request in Kernel.
     */
    public function authenticate(Request $request): void
    {
        $header = $request->header('Authorization') ?? '';
        $token = str_starts_with($header, 'Bearer ')
            ? trim(substr($header, 7))
            : '';
        $source = 'bearer';

        // Browser sessions use HttpOnly cookies. Bearer authentication stays
        // available for API clients and for migrating pre-cookie sessions.
        if ($token === '' || $this->validateAccessToken($token) === null) {
            $token = trim((string) $request->cookie(self::ACCESS_COOKIE, ''));
            $source = 'cookie';
        }
        $payload = $token !== '' ? $this->validateAccessToken($token) : null;
        if ($payload !== null) {
            $this->currentAccessToken = $token;
            $this->authenticationSource = $source;
            $this->currentUser = [
                'id'            => (int) ($payload['sub'] ?? 0),
                'name'          => (string) ($payload['name'] ?? ''),
                'email'         => (string) ($payload['email'] ?? ''),
                'role'          => (string) ($payload['role'] ?? 'viewer'),
                'token_version' => (int) ($payload['tv'] ?? 0),
            ];
        }
    }

    /**
     * Discard the populated user, e.g. because Kernel found the token's
     * embedded token_version no longer matches the current DB value
     * (revoked — see the `tv` claim comment on issueAccessToken()).
     */
    public function clearUser(): void
    {
        $this->currentUser = null;
        $this->currentAccessToken = null;
        $this->authenticationSource = null;
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
        $this->authenticationSource = 'direct';
    }

    /** Returns the currently authenticated user, or null. */
    public function user(): ?array
    {
        return $this->currentUser;
    }

    public function authenticationSource(): ?string
    {
        return $this->authenticationSource;
    }

    public function accessToken(): ?string
    {
        return $this->currentAccessToken;
    }

    // -------------------------------------------------------------------------
    // Token helpers
    // -------------------------------------------------------------------------

    /**
     * Issue a short-lived signed HS256 access token.
     *
     * Embeds the user's current token_version as the `tv` claim so the token
     * can be revoked before its natural expiry: Kernel::handle() re-checks
     * `tv` against users.token_version on every request and drops the
     * session on a mismatch. Bump token_version (e.g. on password change) to
     * invalidate every access token issued before that point.
     */
    public function issueAccessToken(array $user): string
    {
        return $this->buildJwt([
            'sub'   => (int) $user['id'],
            'name'  => $user['name'],
            'email' => $user['email'],
            'role'  => $user['role'],
            'tv'    => (int) ($user['token_version'] ?? 0),
            'iat'   => time(),
            'exp'   => time() + self::accessTtlSeconds(),
        ]);
    }

    public static function accessTtlSeconds(): int
    {
        $configured = (int) (getenv('ACCESS_TOKEN_TTL_SECONDS') ?: self::DEFAULT_ACCESS_TTL_SECONDS);
        return max(300, min(86400, $configured));
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
        $headerData = $this->decodePart($header);
        if (!is_array($headerData)
            || ($headerData['alg'] ?? null) !== 'HS256'
            || ($headerData['typ'] ?? null) !== 'JWT'
        ) {
            return null;
        }
        $expected = $this->b64u(hash_hmac('sha256', "$header.$body", $this->secret, true));
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $data = $this->decodePart($body);
        if (!is_array($data)
            || (int) ($data['sub'] ?? 0) <= 0
            || (int) ($data['exp'] ?? 0) <= time()
            || (int) ($data['iat'] ?? 0) > time() + 60
        ) {
            return null;
        }
        return $data;
    }

    private function decodePart(string $part): ?array
    {
        $pad = strlen($part) % 4 === 0 ? 0 : 4 - (strlen($part) % 4);
        $raw = base64_decode(strtr($part, '-_', '+/') . str_repeat('=', $pad), true);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function b64u(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
