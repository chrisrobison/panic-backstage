<?php
declare(strict_types=1);

namespace Panic\Promote\Adapters;

/**
 * Bluesky post adapter — AT Protocol.
 *
 * Creates a post (app.bsky.feed.post record) on bsky.social using the
 * AT Protocol lexicon.  Does NOT use a persisted access token: the adapter
 * authenticates with your handle + App Password each time to get a short-lived
 * access JWT, then creates the record.
 *
 * Supports rich text link-cards (facets) when a target URL is present, so the
 * ticket link is embedded as a clickable URL card in the feed.
 *
 * API base:  https://bsky.social/xrpc
 * Auth:      com.atproto.server.createSession (identifier + app_password)
 * Docs:      https://docs.bsky.app/docs/tutorials/creating-a-post
 *
 * ── One-time setup ────────────────────────────────────────────────────────────
 *   1. Log in to bsky.app with the account you want to post from.
 *   2. Go to Settings → Privacy and Security → App Passwords.
 *   3. Click "Add App Password", name it "Mabuhay Gardens Promote", and copy
 *      the generated password (format: xxxx-xxxx-xxxx-xxxx).
 *   4. Save your Bluesky handle (e.g. mabuhaygardens.bsky.social) and the
 *      App Password in Settings → Promote → Bluesky.
 *      IMPORTANT: use the App Password, NOT your main account password.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class BlueSkyAdapter
{
    private const BASE    = 'https://bsky.social/xrpc';
    private const TIMEOUT = 15;

    public function __construct(
        private readonly string $identifier,  // handle, e.g. mabuhaygardens.bsky.social
        private readonly string $appPassword, // App Password (NOT main password)
    ) {}

    /**
     * @param  array  $event    DB events row (with venue join)
     * @param  array  $post     DB promote_posts row
     * @param  string $text     Post text (≤ 300 chars; truncated by CopyGenerator)
     * @param  string $sendMode 'now' | 'scheduled'
     * @return array{status, external_url, error_message, response_json}
     */
    public function dispatch(
        array  $event,
        array  $post,
        string $text,
        string $sendMode,
    ): array {
        try {
            // 1. Authenticate (get DID + access JWT)
            $session  = $this->createSession();
            $did      = (string) ($session['did']       ?? '');
            $jwt      = (string) ($session['accessJwt'] ?? '');

            if (!$did || !$jwt) {
                throw new \RuntimeException(
                    'Bluesky createSession did not return a DID or accessJwt — check handle and App Password.'
                );
            }

            // 2. Build the record (with optional URL facets)
            $record  = $this->buildRecord($text, $post);

            // 3. Create the record
            $result  = $this->createRecord($jwt, $did, $record);
            $uri     = (string) ($result['uri'] ?? '');
            $link    = $this->uriToLink($uri, $this->identifier);

            return [
                'status'        => $sendMode === 'scheduled' ? 'queued' : 'sent',
                'external_url'  => $link,
                'error_message' => null,
                'response_json' => json_encode(['uri' => $uri, 'cid' => $result['cid'] ?? null]) ?: null,
            ];
        } catch (\Throwable $e) {
            return [
                'status'        => 'failed',
                'external_url'  => null,
                'error_message' => $e->getMessage(),
                'response_json' => null,
            ];
        }
    }

    // ── Session ───────────────────────────────────────────────────────────────

    private function createSession(): array
    {
        return $this->apiPost(
            '/com.atproto.server.createSession',
            ['identifier' => $this->identifier, 'password' => $this->appPassword],
            null  // no auth header for the login call itself
        );
    }

    // ── Record builder ────────────────────────────────────────────────────────

    /**
     * Build an app.bsky.feed.post record, including URL facets for any
     * ticket/target link embedded in the text.
     */
    private function buildRecord(string $text, array $post): array
    {
        $record = [
            '$type'     => 'app.bsky.feed.post',
            'text'      => $text,
            'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'langs'     => ['en'],
        ];

        // Detect URLs in the text and build facets so they are clickable in the feed
        $facets = $this->extractUrlFacets($text);
        if (!empty($facets)) {
            $record['facets'] = $facets;
        }

        return $record;
    }

    /**
     * Find all http/https URLs in the text and return Bluesky facet annotations
     * so they render as clickable links.
     *
     * Bluesky uses UTF-8 byte offsets, not character offsets.
     */
    private function extractUrlFacets(string $text): array
    {
        $facets = [];

        if (!preg_match_all('/https?:\/\/[^\s\]>,)]+/i', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return $facets;
        }

        foreach ($matches[0] as [$url, $byteStart]) {
            $byteEnd = $byteStart + strlen($url);
            $facets[] = [
                'index' => [
                    '$type'     => 'app.bsky.richtext.facet#byteSlice',
                    'byteStart' => $byteStart,
                    'byteEnd'   => $byteEnd,
                ],
                'features' => [[
                    '$type' => 'app.bsky.richtext.facet#link',
                    'uri'   => $url,
                ]],
            ];
        }

        return $facets;
    }

    // ── Record creation ───────────────────────────────────────────────────────

    private function createRecord(string $jwt, string $did, array $record): array
    {
        return $this->apiPost(
            '/com.atproto.repo.createRecord',
            [
                'repo'       => $did,
                'collection' => 'app.bsky.feed.post',
                'record'     => $record,
            ],
            $jwt
        );
    }

    // ── URL builder ───────────────────────────────────────────────────────────

    /**
     * Convert an AT URI (at://did:plc:.../app.bsky.feed.post/rkey) to a
     * bsky.app profile post link.
     */
    private function uriToLink(string $uri, string $handle): ?string
    {
        if (!$uri) {
            return null;
        }
        // at://did:plc:xxx/app.bsky.feed.post/rkey → https://bsky.app/profile/{handle}/post/{rkey}
        $parts = explode('/', $uri);
        $rkey  = end($parts);
        $slug  = ltrim($handle, '@');
        return "https://bsky.app/profile/{$slug}/post/{$rkey}";
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────────

    private function apiPost(string $path, array $payload, ?string $jwt): array
    {
        $ch = curl_init(self::BASE . $path);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        if ($jwt !== null) {
            $headers[] = 'Authorization: Bearer ' . $jwt;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $body   = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException("Bluesky cURL error on $path: $err");
        }

        $data = json_decode($body, true) ?? [];

        if ($status >= 400) {
            $msg    = $data['message'] ?? $data['error'] ?? "HTTP $status";
            $errKey = (string) ($data['error'] ?? '');
            $hint   = $this->translateError($errKey, $status);
            throw new \RuntimeException(
                "Bluesky error ($status) on $path: $msg" . ($hint ? " — $hint" : '')
            );
        }

        return $data;
    }

    private function translateError(string $errKey, int $status): string
    {
        return match (true) {
            $errKey === 'AuthenticationRequired'  => 'Invalid handle or App Password — verify them in Promote Settings.',
            $errKey === 'RateLimitExceeded'       => 'Bluesky rate limit exceeded — retry in a few minutes.',
            str_contains($errKey, 'InvalidRequest') => 'Post record was rejected — check text length (≤300 chars) and content.',
            $status === 401                        => 'App Password is invalid or expired — generate a new one in bsky.app settings.',
            default                                => '',
        };
    }
}
