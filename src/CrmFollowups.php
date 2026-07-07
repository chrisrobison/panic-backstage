<?php
declare(strict_types=1);

namespace Panic;

/**
 * Cron-callable endpoint that sends reminder emails for CRM follow-up tasks
 * that are due today or overdue (up to 7 days past due).
 *
 *   POST  /api/crm-followups
 *
 * Access: venue_admin session OR a valid X-Cron-Secret header matching the
 * CRON_SECRET environment variable.
 *
 * Designed to be called daily via cron:
 *   curl -X POST -H "X-Cron-Secret: $CRON_SECRET" https://yourdomain.com/api/crm-followups
 */
final class CrmFollowups extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        // Allow venue_admin session OR a matching cron secret header
        $cronSecret = getenv('CRON_SECRET') ?: '';
        $authHeader = $request->header('X-Cron-Secret') ?? '';

        if (!$this->isVenueAdmin() && ($cronSecret === '' || !hash_equals($cronSecret, $authHeader))) {
            return $this->forbidden('venue_admin role or cron secret required');
        }

        $count = CrmProfiles::sendFollowupReminders($this->db, $this->root);

        return $this->ok([
            'reminders_sent' => $count,
            'timestamp'      => date('c'),
        ]);
    }
}
