<?php
declare(strict_types=1);

namespace Panic\Promote;

use Panic\Promote\Adapters\EventbriteAdapter;

/**
 * Broadcast adapter dispatcher.
 *
 * Routes each (destination_key, event, post) tuple to the appropriate
 * platform adapter (real API) or falls back to the status-stub for
 * manual / unconfigured / disabled destinations.
 *
 * Adding a new real adapter:
 *   1. Create  src/Promote/Adapters/FooAdapter.php  implementing ::dispatch()
 *   2. Add a case for the destination_key here in dispatch()
 *   3. Add the required env vars to .env (and document them in the adapter)
 */
final class BroadcastAdapters
{
    /**
     * Attempt a single broadcast result for one destination.
     *
     * @param  string $destKey    DB destination_key (e.g. 'eventbrite', 'facebook_page')
     * @param  string $destStatus DB destination status ('connected', 'needs_auth', 'manual_submission', 'disabled')
     * @param  string $sendMode   'now' | 'scheduled'
     * @param  array  $event      Full DB events row (joined with venues: venue_name, venue_city, etc.)
     * @param  array  $post       DB promote_posts row
     * @return array{status: string, external_url: string|null, error_message: string|null, response_json: string|null}
     */
    public function dispatch(
        string $destKey,
        string $destStatus,
        string $sendMode,
        array  $event,
        array  $post,
    ): array {
        return match ($destKey) {
            'eventbrite' => $this->eventbrite($sendMode, $event, $post),
            default      => $this->stub($destStatus, $sendMode),
        };
    }

    // ── Real adapters ─────────────────────────────────────────────────────────

    private function eventbrite(string $sendMode, array $event, array $post): array
    {
        $apiKey = (string) (getenv('EVENTBRITE_API_KEY') ?: '');
        $orgId  = (string) (getenv('EVENTBRITE_ORG_ID') ?: '');

        if (!$apiKey) {
            return $this->stub('needs_auth', $sendMode);
        }

        if (!$orgId) {
            return [
                'status'        => 'needs_auth',
                'external_url'  => null,
                'error_message' => 'EVENTBRITE_ORG_ID not set. Log in to eventbrite.com, create an Organizer, '
                                 . 'then call GET /api/promote/eventbrite/org to retrieve and store your org ID.',
                'response_json' => null,
            ];
        }

        return (new EventbriteAdapter($apiKey, $orgId))->dispatch($event, $post, $sendMode);
    }

    // ── Stub fallback ─────────────────────────────────────────────────────────

    /**
     * Status-only stub for unimplemented / manual destinations.
     * No external API calls are made.
     */
    public function stub(string $destinationStatus, string $sendMode): array
    {
        $status = match ($destinationStatus) {
            'connected'         => $sendMode === 'scheduled' ? 'queued' : 'sent',
            'needs_auth'        => 'needs_auth',
            'manual_submission' => 'manual_required',
            'disabled'          => 'skipped',
            default             => 'manual_required',
        };

        return [
            'status'        => $status,
            'external_url'  => null,
            'error_message' => null,
            'response_json' => null,
        ];
    }
}
