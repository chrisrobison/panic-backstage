<?php
declare(strict_types=1);

namespace Panic\Promote;

/**
 * Broadcast adapter layer — MVP stub.
 *
 * Maps destination connection status + send mode to a broadcast result status.
 * No external API calls are made. Future adapters (e.g., Facebook Graph API,
 * Mailchimp) would extend this class or implement an interface.
 *
 * Status mapping (per PROMOTE-PLAN.md):
 *   needs_auth        → needs_auth
 *   manual_submission → manual_required
 *   connected + now   → sent
 *   connected + sched → queued
 *   disabled          → skipped
 */
final class BroadcastAdapters
{
    public function resolveStatus(string $destinationStatus, string $sendMode): string
    {
        return match ($destinationStatus) {
            'connected'         => $sendMode === 'scheduled' ? 'queued' : 'sent',
            'needs_auth'        => 'needs_auth',
            'manual_submission' => 'manual_required',
            'disabled'          => 'skipped',
            default             => 'manual_required',
        };
    }
}
