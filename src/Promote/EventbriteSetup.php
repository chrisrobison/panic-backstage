<?php
declare(strict_types=1);

namespace Panic\Promote;

use Panic\BaseEndpoint;
use Panic\Request;
use Panic\Response;

/**
 * Eventbrite one-time setup helper.
 *
 *   GET /api/promote/eventbrite/org
 *
 * Calls the Eventbrite API with the configured EVENTBRITE_API_KEY and returns
 * the list of organizations this account belongs to.
 *
 * Use this once after creating an Organizer on eventbrite.com:
 *   1. Visit eventbrite.com → Create & Manage Events → set up an Organizer.
 *   2. Call this endpoint — it returns your org IDs.
 *   3. Set EVENTBRITE_ORG_ID=<id> in .env.
 *   4. Eventbrite broadcasts will now post live events.
 */
final class EventbriteSetup extends BaseEndpoint
{
    private const EB_BASE = 'https://www.eventbriteapi.com/v3';

    public function handle(Request $request): Response
    {
        if ($request->method() !== 'GET') {
            return Response::methodNotAllowed();
        }

        // Only admins / users with manage_users capability may use this
        if ($denied = $this->requireCapability('manage_users')) {
            return $denied;
        }

        $apiKey = (string) (getenv('EVENTBRITE_API_KEY') ?: '');
        if (!$apiKey) {
            return Response::json(['error' => 'EVENTBRITE_API_KEY is not set in .env'], 503);
        }

        // Fetch user identity
        $me = $this->ebGet('/users/me/', $apiKey);
        if (isset($me['error'])) {
            return Response::json(['error' => 'Eventbrite API error: ' . ($me['error_description'] ?? $me['error'])], 502);
        }

        // Fetch organizations
        $orgsResponse = $this->ebGet('/users/me/organizations/', $apiKey);
        $orgs = $orgsResponse['organizations'] ?? [];

        $currentOrgId = (string) (getenv('EVENTBRITE_ORG_ID') ?: '');

        return $this->ok([
            'eventbrite_user' => [
                'id'    => $me['id'] ?? null,
                'name'  => $me['name'] ?? null,
                'email' => $me['emails'][0]['email'] ?? null,
            ],
            'organizations'  => array_map(fn ($o) => [
                'id'   => $o['id'],
                'name' => $o['name'],
            ], $orgs),
            'current_org_id' => $currentOrgId ?: null,
            'instructions'   => empty($orgs)
                ? 'No organizations found. Log in to eventbrite.com and create an Organizer profile first, then call this endpoint again.'
                : 'Copy the "id" of the organization you want to use and set EVENTBRITE_ORG_ID=<id> in .env.',
        ]);
    }

    private function ebGet(string $path, string $apiKey): array
    {
        $ch = curl_init(self::EB_BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Accept: application/json',
            ],
        ]);
        $body = (string) curl_exec($ch);
        curl_close($ch);
        return json_decode($body, true) ?? [];
    }
}
