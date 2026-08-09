<?php
declare(strict_types=1);

namespace Panic\Notifications;

/**
 * The canonical, transport-agnostic representation of one Backstage
 * notification.
 *
 * Business code (a lead was assigned, a contract was signed) builds one of
 * these and hands it to PushNotifier. Nothing Firebase-shaped appears here or
 * anywhere upstream of FcmClient — that is what keeps "add a new notification
 * type" from meaning "learn the FCM message schema", and what would let a
 * second transport be added later without rewriting the callers.
 *
 * `url` is a Backstage deep link (a route hash such as `#inbox-unassigned`,
 * `#event-123`, `#contract-456`). It travels in the FCM *data* payload and is
 * applied by public/sw.js on notificationclick, so the service worker never
 * has to know anything about which entity types map to which screens.
 */
final class PushMessage
{
    /**
     * @param string      $category   PushPreferences key gating delivery.
     * @param string      $title      Notification title (short).
     * @param string      $body       Notification body (one line).
     * @param string      $url        Backstage deep link, e.g. '#inbox-unassigned'.
     * @param string|null $entityType 'lead' | 'contract' | 'event' | 'task' | null.
     * @param int|null    $entityId   Primary key of $entityType.
     * @param int|null    $eventId    Related event, when there is one.
     * @param string|null $dedupeKey  Collapses re-delivery of the same logical
     *                                notification: used as the browser
     *                                Notification tag AND as the FCM collapse
     *                                key, so a phone that was offline shows one
     *                                current notification rather than five stale
     *                                ones. NOT the JobQueue unique_key.
     */
    public function __construct(
        public readonly string $category,
        public readonly string $title,
        public readonly string $body,
        public readonly string $url,
        public readonly ?string $entityType = null,
        public readonly ?int $entityId = null,
        public readonly ?int $eventId = null,
        public readonly ?string $dedupeKey = null,
    ) {
    }

    /** @return array<string,mixed> Compact JSON-safe form for the job payload. */
    public function toArray(): array
    {
        return [
            'category'    => $this->category,
            'title'       => $this->title,
            'body'        => $this->body,
            'url'         => $this->url,
            'entity_type' => $this->entityType,
            'entity_id'   => $this->entityId,
            'event_id'    => $this->eventId,
            'dedupe_key'  => $this->dedupeKey,
        ];
    }

    /** @param array<string,mixed> $data Round-trips toArray() out of the job payload. */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['category'] ?? ''),
            (string) ($data['title'] ?? ''),
            (string) ($data['body'] ?? ''),
            (string) ($data['url'] ?? ''),
            isset($data['entity_type']) ? (string) $data['entity_type'] : null,
            isset($data['entity_id']) ? (int) $data['entity_id'] : null,
            isset($data['event_id']) ? (int) $data['event_id'] : null,
            isset($data['dedupe_key']) ? (string) $data['dedupe_key'] : null,
        );
    }

    /**
     * The FCM `data` payload for this message.
     *
     * FCM requires every data value to be a STRING — an int or null anywhere
     * in here is rejected by the v1 API with INVALID_ARGUMENT — so empty keys
     * are dropped rather than sent as "" or "null" for the service worker to
     * re-interpret.
     *
     * @return array<string,string>
     */
    public function dataPayload(): array
    {
        $data = [
            'category'    => $this->category,
            'title'       => $this->title,
            'body'        => $this->body,
            'url'         => $this->url,
            'entity_type' => $this->entityType ?? '',
            'entity_id'   => $this->entityId !== null ? (string) $this->entityId : '',
            'event_id'    => $this->eventId !== null ? (string) $this->eventId : '',
            'dedupe_key'  => $this->dedupeKey ?? '',
        ];
        return array_filter($data, static fn (string $value): bool => $value !== '');
    }
}
