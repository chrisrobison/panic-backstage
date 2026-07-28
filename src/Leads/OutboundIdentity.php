<?php
declare(strict_types=1);

namespace Panic\Leads;

use Panic\Database;

/**
 * Resolves the Booking Inbox's outbound "From" identity from
 * `lead_inbox_settings` (migration 086_add_booking_inbox_outbound_identity.sql)
 * instead of the hard-coded 'bookings@themab.org' / 'Mabuhay Gardens Booking
 * Team' that used to live directly in Acknowledgment.php and
 * LeadsInbox.php's reply-send path. Both auto-acknowledgment and manual
 * staff replies now go through this one place, so a customer sees the same
 * sender identity regardless of which staff member (or which automated
 * step) actually sent the message, and `lead_messages.from_name`/
 * `from_email` always match what was actually sent.
 *
 * This app is still single-venue-per-database — each tenant gets its own
 * database (see Panic\Tenant\TenantProvisioner), not a shared multi-tenant
 * table — so "the current venue" is the same `ORDER BY id LIMIT 1` every
 * other Booking Inbox class already uses (SlaSettings, StatusMachine,
 * Onboarding, Acknowledgment). If this app ever becomes multi-venue within
 * one database, this is the one place that needs a real per-request venue
 * lookup instead.
 *
 * `from_email` is deliberately not free-form-safe: Mailer always sets
 * Reply-To to the same address as From, and the Exim ingestion pipe
 * (scripts/ingest-booking-email.php) only reads one fixed mailbox. An admin
 * changing `lead_inbox_settings.from_email` away from that mailbox will
 * stop customer replies from threading back in — see the migration's
 * comment and docs/booking-inbox.md.
 */
final class OutboundIdentity
{
    private const FALLBACK_FROM_EMAIL = 'bookings@themab.org';

    /** @return array{from_name:string, from_email:string, venue_name:string} */
    public static function resolve(Database $db): array
    {
        $row = $db->one(
            'SELECT v.name AS venue_name, s.from_name, s.from_email
             FROM venues v
             LEFT JOIN lead_inbox_settings s ON s.venue_id = v.id
             ORDER BY v.id LIMIT 1'
        );

        $venueName = trim((string) ($row['venue_name'] ?? ''));
        $fromEmail = trim((string) ($row['from_email'] ?? ''));
        if ($fromEmail === '') {
            $fromEmail = self::FALLBACK_FROM_EMAIL;
        }
        $fromName = trim((string) ($row['from_name'] ?? ''));
        if ($fromName === '') {
            $fromName = $venueName !== '' ? "{$venueName} Booking Team" : 'Booking Team';
        }

        return [
            'from_name' => $fromName,
            'from_email' => $fromEmail,
            'venue_name' => $venueName !== '' ? $venueName : 'our venue',
        ];
    }
}
