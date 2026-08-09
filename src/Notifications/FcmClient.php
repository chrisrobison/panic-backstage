<?php
declare(strict_types=1);

namespace Panic\Notifications;

/**
 * Minimal native FCM HTTP v1 client — no Firebase Admin SDK, no Composer
 * runtime dependency, just cURL + OpenSSL (both already required by CI and by
 * the existing Promote adapters, whose defensive HTTP style this follows).
 *
 * Authentication is the standard service-account OAuth2 flow:
 *
 *   1. read the service-account JSON named by FIREBASE_SERVICE_ACCOUNT_FILE;
 *   2. build a JWT assertion (iss = client_email, aud = token_uri,
 *      scope = firebase.messaging, 1 hour lifetime);
 *   3. sign it RS256 with the account's private key via openssl_sign();
 *   4. exchange the assertion for a short-lived OAuth2 access token;
 *   5. cache that token in-process until shortly before it expires;
 *   6. POST messages to /v1/projects/{project}/messages:send.
 *
 * This is the ONLY class that knows what an FCM message looks like.
 * Everything upstream deals in PushMessage. Nothing here is ever logged that
 * could leak a credential: not the private key, not the access token, not the
 * Authorization header, not the registration token.
 */
final class FcmClient
{
    /** Delivered (or accepted by FCM). */
    public const OUTCOME_SENT = 'sent';
    /** The registration is dead/invalid — disable it, never retry it. */
    public const OUTCOME_DROP = 'drop';
    /** Rate limited or FCM is unwell — throw so JobQueue backs off. */
    public const OUTCOME_RETRY = 'retry';
    /** Our credentials/config are wrong — throw with a sanitized message. */
    public const OUTCOME_CONFIG = 'config';

    private const OAUTH_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    private const TOKEN_URI   = 'https://oauth2.googleapis.com/token';
    private const SEND_BASE   = 'https://fcm.googleapis.com/v1/projects/';
    private const TIMEOUT     = 15;

    /**
     * Access tokens keyed by service-account file, shared across FcmClient
     * instances for the life of the process. A worker draining 25 push jobs
     * performs ONE token exchange, not 25.
     *
     * @var array<string, array{token: string, expires_at: int}>
     */
    private static array $tokenCache = [];

    public function __construct(private readonly PushConfig $config)
    {
    }

    /**
     * Send one message to one registration token.
     *
     * Returns the classification rather than throwing for dead tokens: the
     * caller owns the subscription row and is the only thing that should
     * decide to disable it.
     *
     * @return array{outcome: string, error: ?string}
     * @throws FcmException on transient or configuration failures.
     */
    public function send(string $registrationToken, PushMessage $message): array
    {
        if (!$this->config->canSend()) {
            throw new FcmException('Firebase push is not fully configured (missing or unreadable service-account file).', false);
        }

        $accessToken = $this->accessToken();
        $url = self::SEND_BASE . rawurlencode($this->config->projectId) . '/messages:send';

        [$status, $body] = $this->httpPostJson(
            $url,
            ['message' => $this->buildMessage($registrationToken, $message)],
            $accessToken
        );

        $decoded = json_decode($body, true);
        $decoded = is_array($decoded) ? $decoded : [];
        $outcome = self::classify($status, $decoded);

        if ($outcome === self::OUTCOME_SENT) {
            return ['outcome' => $outcome, 'error' => null];
        }

        // Sanitized: FCM echoes nothing sensitive in `status`, and we
        // deliberately do NOT copy `error.message` verbatim — it quotes the
        // offending registration token back at you for INVALID_ARGUMENT.
        $reason = self::errorStatus($decoded) ?: ('HTTP ' . $status);

        if ($outcome === self::OUTCOME_RETRY) {
            throw new FcmException("FCM temporarily rejected a push ({$reason}).", true);
        }
        if ($outcome === self::OUTCOME_CONFIG) {
            // A 401 here almost always means the cached token went stale
            // against a rotated key; drop it so the next attempt re-mints.
            unset(self::$tokenCache[$this->config->serviceAccountFile]);
            throw new FcmException("FCM rejected a push for a configuration reason ({$reason}). Check FIREBASE_PROJECT_ID and the service account's Cloud Messaging permission.", false);
        }

        return ['outcome' => self::OUTCOME_DROP, 'error' => mb_substr($reason, 0, 255)];
    }

    // ── Message construction ─────────────────────────────────────────────────

    /**
     * Build the FCM v1 message body.
     *
     * Both halves are sent on purpose. `notification` gives every consumer a
     * sane title/body without parsing anything, while `data` carries the
     * structured fields — crucially the Backstage deep link — that public/sw.js
     * actually acts on. All `data` values are strings, which the v1 API
     * requires (see PushMessage::dataPayload()).
     *
     * @return array<string,mixed>
     */
    private function buildMessage(string $registrationToken, PushMessage $message): array
    {
        $body = [
            'token'        => $registrationToken,
            'notification' => [
                'title' => $message->title,
                'body'  => $message->body,
            ],
            'data'    => $message->dataPayload(),
            'webpush' => [
                // Operational alerts should wake a sleeping device.
                'headers' => ['Urgency' => 'high'],
            ],
        ];

        if ($message->dedupeKey !== null && $message->dedupeKey !== '') {
            // Collapse: a device that was offline through three updates to the
            // same lead gets the newest one, not a stack of stale ones.
            $body['webpush']['headers']['Topic'] = self::collapseKey($message->dedupeKey);
        }

        return $body;
    }

    /**
     * Web Push `Topic` headers must be <= 32 URL-safe base64 characters, which
     * an application dedupe key ("lead-assigned:12345") is not, so hash it.
     */
    public static function collapseKey(string $dedupeKey): string
    {
        return substr(self::base64UrlEncode(hash('sha256', $dedupeKey, true)), 0, 32);
    }

    // ── Response classification ──────────────────────────────────────────────

    /**
     * Map an FCM HTTP response onto one of the four OUTCOME_* behaviors.
     * Pure and public so the policy is covered by hermetic tests without any
     * network access.
     *
     * @param array<string,mixed> $body Decoded JSON response (empty if unparseable).
     */
    public static function classify(int $status, array $body): string
    {
        if ($status >= 200 && $status < 300) {
            return self::OUTCOME_SENT;
        }

        $errorStatus = self::errorStatus($body);

        // The registration is gone: the user cleared site data, uninstalled
        // the PWA, or the browser rotated the token. Retrying can never work.
        if ($status === 404 || $errorStatus === 'UNREGISTERED' || $errorStatus === 'NOT_FOUND') {
            return self::OUTCOME_DROP;
        }

        // The token is real but belongs to a different Firebase project —
        // equally unusable by us, and equally permanent.
        if ($errorStatus === 'SENDER_ID_MISMATCH') {
            return self::OUTCOME_DROP;
        }

        if ($status === 429 || $errorStatus === 'QUOTA_EXCEEDED'
            || $errorStatus === 'UNAVAILABLE' || $errorStatus === 'INTERNAL'
            || $status >= 500
        ) {
            return self::OUTCOME_RETRY;
        }

        // INVALID_ARGUMENT is ambiguous: it covers both a malformed
        // registration token and a malformed message (our bug). Only the
        // former should quietly disable somebody's device, so distinguish
        // them by which field FCM says was at fault.
        if ($status === 400 || $errorStatus === 'INVALID_ARGUMENT') {
            return self::blamesToken($body) ? self::OUTCOME_DROP : self::OUTCOME_CONFIG;
        }

        return self::OUTCOME_CONFIG;
    }

    /** @param array<string,mixed> $body */
    private static function errorStatus(array $body): string
    {
        $error = $body['error'] ?? null;
        if (!is_array($error)) {
            return '';
        }
        // FcmError detail is more specific than the generic gRPC status when
        // both are present (e.g. status=NOT_FOUND, errorCode=UNREGISTERED).
        foreach ((array) ($error['details'] ?? []) as $detail) {
            if (is_array($detail) && isset($detail['errorCode']) && is_string($detail['errorCode'])) {
                return $detail['errorCode'];
            }
        }
        return is_string($error['status'] ?? null) ? $error['status'] : '';
    }

    /**
     * Whether FCM pinned the failure on the registration token specifically.
     *
     * @param array<string,mixed> $body
     */
    private static function blamesToken(array $body): bool
    {
        $error = $body['error'] ?? null;
        if (!is_array($error)) {
            return false;
        }
        foreach ((array) ($error['details'] ?? []) as $detail) {
            if (!is_array($detail)) {
                continue;
            }
            foreach ((array) ($detail['fieldViolations'] ?? []) as $violation) {
                $field = is_array($violation) ? (string) ($violation['field'] ?? '') : '';
                if ($field === 'message.token' || str_ends_with($field, '.token')) {
                    return true;
                }
            }
        }
        $message = strtolower((string) ($error['message'] ?? ''));
        return str_contains($message, 'registration token') || str_contains($message, 'not a valid fcm');
    }

    // ── OAuth2 (service account → access token) ──────────────────────────────

    /** Cached access token, re-minted 60s before expiry so no request races it. */
    private function accessToken(): string
    {
        $file = $this->config->serviceAccountFile;
        $cached = self::$tokenCache[$file] ?? null;
        if ($cached !== null && $cached['expires_at'] > time() + 60) {
            return $cached['token'];
        }

        $account = $this->serviceAccount();
        $tokenUri = (string) ($account['token_uri'] ?? self::TOKEN_URI);
        $assertion = self::buildAssertion(
            (string) $account['client_email'],
            (string) $account['private_key'],
            $tokenUri,
            time()
        );

        $ch = curl_init($tokenUri);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $assertion,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $body   = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            throw new FcmException('Could not reach the Google OAuth token endpoint: ' . $err, true);
        }

        $data = json_decode($body, true);
        $data = is_array($data) ? $data : [];
        if ($status >= 500) {
            throw new FcmException("Google OAuth token endpoint returned HTTP {$status}.", true);
        }
        if ($status >= 400 || !is_string($data['access_token'] ?? null)) {
            // `error_description` is Google's own text about the assertion
            // ("Invalid JWT Signature", "Invalid grant") — no secret in it.
            $reason = (string) ($data['error_description'] ?? $data['error'] ?? "HTTP {$status}");
            throw new FcmException('Firebase service-account authentication failed: ' . mb_substr($reason, 0, 200), false);
        }

        $expiresIn = (int) ($data['expires_in'] ?? 3600);
        self::$tokenCache[$file] = [
            'token'      => $data['access_token'],
            'expires_at' => time() + max(60, $expiresIn),
        ];
        return $data['access_token'];
    }

    /** @return array<string,mixed> */
    private function serviceAccount(): array
    {
        $file = $this->config->serviceAccountFile;
        $raw = @file_get_contents($file);
        if ($raw === false) {
            throw new FcmException('Could not read the Firebase service-account file at the configured path.', false);
        }
        $account = json_decode($raw, true);
        if (!is_array($account)
            || !is_string($account['client_email'] ?? null)
            || !is_string($account['private_key'] ?? null)
        ) {
            throw new FcmException('The Firebase service-account file is not valid JSON with client_email and private_key.', false);
        }
        return $account;
    }

    /**
     * Build and RS256-sign the OAuth2 JWT assertion.
     *
     * Public and time-injectable purely so the encoding (base64url, segment
     * order, claim set) is testable offline against a throwaway key.
     */
    public static function buildAssertion(
        string $clientEmail,
        string $privateKeyPem,
        string $tokenUri,
        int $issuedAt
    ): string {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss'   => $clientEmail,
            'scope' => self::OAUTH_SCOPE,
            'aud'   => $tokenUri,
            'iat'   => $issuedAt,
            'exp'   => $issuedAt + 3600,
        ];

        $signingInput = self::base64UrlEncode(self::json($header))
            . '.' . self::base64UrlEncode(self::json($claims));

        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            // Deliberately says nothing about the key material itself.
            throw new FcmException('The Firebase service-account private key could not be parsed by OpenSSL.', false);
        }
        $signature = '';
        if (!openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new FcmException('Could not RS256-sign the Firebase OAuth assertion.', false);
        }

        return $signingInput . '.' . self::base64UrlEncode($signature);
    }

    public static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    // ── HTTP ─────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $payload
     * @return array{0: int, 1: string} [status, raw body]
     */
    private function httpPostJson(string $url, array $payload, string $accessToken): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => self::json($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                // Never logged, never echoed into an exception message.
                'Authorization: Bearer ' . $accessToken,
            ],
        ]);
        $body   = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            throw new FcmException('cURL error talking to FCM: ' . $err, true);
        }
        return [$status, $body];
    }

    /** @param array<string,mixed> $value */
    private static function json(array $value): string
    {
        return (string) json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
