<?php
declare(strict_types=1);

namespace Panic\Notifications;

/**
 * Firebase push configuration, read from the environment.
 *
 * Push is an entirely OPTIONAL integration: an install with no FIREBASE_*
 * values configured must behave exactly as it did before this feature
 * existed. Everything downstream therefore asks this class first —
 * PushNotifier no-ops when it says no, the API's config endpoint reports
 * `enabled: false`, and the Preferences UI degrades to "unavailable" instead
 * of showing a button that cannot work.
 *
 * The web values below (project id, web API key, sender id, app id, VAPID
 * PUBLIC key) are NOT secrets — Firebase ships them in client bundles by
 * design — so they may be returned to an authenticated browser. The service
 * account JSON is the opposite: it holds a private key, must live outside the
 * public web root, and is never read anywhere but FcmClient.
 */
final class PushConfig
{
    private function __construct(
        public readonly bool $enabled,
        public readonly string $projectId,
        public readonly string $webApiKey,
        public readonly string $messagingSenderId,
        public readonly string $appId,
        public readonly string $vapidPublicKey,
        public readonly string $serviceAccountFile,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $projectId  = self::env('FIREBASE_PROJECT_ID');
        $apiKey     = self::env('FIREBASE_WEB_API_KEY');
        $senderId   = self::env('FIREBASE_MESSAGING_SENDER_ID');
        $appId      = self::env('FIREBASE_APP_ID');
        $vapid      = self::env('FIREBASE_VAPID_PUBLIC_KEY');
        $account    = self::env('FIREBASE_SERVICE_ACCOUNT_FILE');

        // The kill switch is opt-IN: a blank FIREBASE_PUSH_ENABLED leaves push
        // off even if someone has half-filled the other values, so a partial
        // rollout can't start prompting users mid-configuration.
        $switch = strtolower(self::env('FIREBASE_PUSH_ENABLED'));
        $switchedOn = in_array($switch, ['1', 'true', 'yes', 'on'], true);

        // Every one of these is required to *register* a browser. Without the
        // whole set getToken() cannot succeed, so we report disabled rather
        // than let the client fail confusingly at permission time.
        $configured = $switchedOn
            && $projectId !== ''
            && $apiKey !== ''
            && $senderId !== ''
            && $appId !== ''
            && $vapid !== '';

        return new self($configured, $projectId, $apiKey, $senderId, $appId, $vapid, $account);
    }

    /**
     * Whether the SERVER can actually send. Registration only needs the web
     * config; sending additionally needs a readable service-account JSON.
     * Kept separate so a misplaced key file surfaces as "sends fail, with a
     * log line" rather than silently un-registering everybody's devices.
     */
    public function canSend(): bool
    {
        return $this->enabled
            && $this->serviceAccountFile !== ''
            && is_readable($this->serviceAccountFile);
    }

    /**
     * PUBLIC Firebase web config, safe to hand to an authenticated browser.
     * Note what is absent: the service-account path, and anything from it.
     *
     * @return array<string,string>
     */
    public function publicWebConfig(): array
    {
        return [
            'projectId'         => $this->projectId,
            'apiKey'            => $this->webApiKey,
            'messagingSenderId' => $this->messagingSenderId,
            'appId'             => $this->appId,
        ];
    }

    private static function env(string $key): string
    {
        $value = $_ENV[$key] ?? getenv($key);
        return is_string($value) ? trim($value) : '';
    }
}
