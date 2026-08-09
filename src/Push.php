<?php
declare(strict_types=1);

namespace Panic;

use Panic\Notifications\PushConfig;
use Panic\Notifications\PushSubscriptions;

/**
 * Web push device registration for the signed-in user.
 *
 *   GET    /api/push/config                  Public Firebase web config + support flags
 *   GET    /api/push/subscriptions           This user's registered devices
 *   POST   /api/push/subscriptions           Register/refresh THIS browser (upsert)
 *   DELETE /api/push/subscriptions/{id}      Remove one of this user's devices
 *   DELETE /api/push/subscriptions           Remove by token (body: {token})
 *
 * Everything is scoped to the authenticated user. There is no user_id
 * parameter anywhere in this surface — not optional, not admin-only, none —
 * because the only correct value is the session's, and accepting one from the
 * browser is how push registries end up cross-linking accounts. Kernel already
 * rejects unauthenticated requests (this class is not in isPublic()).
 *
 * Registration tokens are write-only across this API: they are accepted on
 * POST and matched by SHA-256 digest, and no response ever contains one.
 */
final class Push extends BaseEndpoint
{
    /** Longest FCM registration token we will store (column is varchar(1024)). */
    private const MAX_TOKEN_LEN = 1024;

    public function handle(Request $request): Response
    {
        $action = (string) ($this->params['action'] ?? '');

        return match ($action) {
            'config'        => $request->method() === 'GET'
                ? $this->config()
                : Response::methodNotAllowed(),
            'subscriptions' => $this->subscriptions($request),
            default         => $this->notFound(),
        };
    }

    /**
     * PUBLIC Firebase configuration for the browser SDK.
     *
     * Returns `enabled: false` (and nothing else of substance) when Firebase
     * is unconfigured, which is what lets the Preferences UI degrade to
     * "Push notifications unavailable" instead of offering a button that
     * cannot work. The VAPID key here is the PUBLIC half — it is designed to
     * ship in client code. The service-account path and private key are never
     * part of this response.
     */
    private function config(): Response
    {
        $config = PushConfig::fromEnvironment();
        if (!$config->enabled) {
            return $this->ok(['enabled' => false]);
        }

        return $this->ok([
            'enabled'       => true,
            'firebase'      => $config->publicWebConfig(),
            'vapid_key'     => $config->vapidPublicKey,
            // The client needs an absolute, base-path-correct service worker
            // URL: Backstage may be mounted under APP_BASE_PATH, and a worker
            // registered at the wrong scope silently never receives a push.
            'service_worker' => $this->serviceWorkerUrl(),
        ]);
    }

    private function subscriptions(Request $request): Response
    {
        $userId = (int) $this->userId();

        return match ($request->method()) {
            'GET'    => $this->ok(['subscriptions' => PushSubscriptions::listForUser($this->db, $userId)]),
            'POST'   => $this->register($request, $userId),
            'DELETE' => $this->unregister($request, $userId),
            default  => Response::methodNotAllowed(),
        };
    }

    /**
     * Register or refresh this browser's FCM token.
     *
     * Idempotent: the browser re-registers on every enable and whenever the
     * SDK rotates its token, and each call updates the one row keyed by that
     * token's hash rather than accumulating duplicates. Several DIFFERENT
     * tokens for one user is the normal multi-device case and is allowed.
     */
    private function register(Request $request, int $userId): Response
    {
        $token = trim((string) $request->body('token', ''));
        if ($token === '' || strlen($token) > self::MAX_TOKEN_LEN) {
            return Response::json(['error' => 'A valid registration token is required'], 422);
        }

        $config = PushConfig::fromEnvironment();
        if (!$config->enabled) {
            // Refuse to bank tokens for a project that isn't configured —
            // they would be unusable and would only ever be stale.
            return Response::json(['error' => 'Push notifications are not configured'], 503);
        }

        $id = PushSubscriptions::upsert(
            $this->db,
            $userId,
            $token,
            $this->clip($request->body('device_label'), 120),
            $this->clip($request->body('platform'), 40),
            $this->clip($request->header('User-Agent'), 255)
        );

        return $this->ok([
            'ok'           => true,
            'subscription' => PushSubscriptions::findByToken($this->db, $userId, $token),
            'id'           => $id,
        ]);
    }

    /**
     * Remove a device — by id (from the Preferences device list) or by token
     * (how the current browser turns itself off without knowing its row id).
     *
     * Both paths are filtered by the session's user id, so an id belonging to
     * another user is simply "not found": no deletion, and no confirmation
     * that the row exists.
     */
    private function unregister(Request $request, int $userId): Response
    {
        $id = $this->params['subscriptionId'] ?? null;
        if ($id !== null) {
            return PushSubscriptions::deleteForUser($this->db, $userId, (int) $id)
                ? $this->ok(['ok' => true])
                : $this->notFound('Subscription not found');
        }

        $token = trim((string) $request->body('token', ''));
        if ($token === '') {
            return Response::json(['error' => 'A subscription id or token is required'], 422);
        }

        return PushSubscriptions::deleteByTokenForUser($this->db, $userId, $token)
            ? $this->ok(['ok' => true])
            : $this->notFound('Subscription not found');
    }

    /**
     * Absolute URL of public/sw.js, honoring APP_BASE_PATH so an install
     * mounted at /backstage registers the worker at /backstage/sw.js (scope
     * /backstage/) rather than at the domain root.
     */
    private function serviceWorkerUrl(): string
    {
        $appUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/');
        return ($appUrl !== '' ? $appUrl : '') . '/sw.js';
    }

    private function clip(mixed $value, int $max): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
