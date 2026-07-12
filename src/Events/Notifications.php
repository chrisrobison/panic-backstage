<?php
declare(strict_types=1);

namespace Panic\Events;

use Panic\Database;
use Panic\Mailer;
use Panic\NotificationPreferences;

/**
 * Status-change and private-event-inquiry email notifications — extracted
 * verbatim from Events::notifyStatusChange()/notifyPrivateEventCreated() so
 * the endpoint class stays focused on request handling instead of carrying
 * ~250 lines of email composition inline. Both methods are best-effort and
 * never throw: a notification failure must never fail the request that
 * triggered it.
 */
final class Notifications
{
    /** Human-readable status labels, shared by anything that displays event status. */
    public const STATUS_LABELS = [
        'empty'              => 'Empty',
        'proposed'           => 'Hold',
        'confirmed'          => 'Intake Complete',
        'booked'             => 'Booked',
        'needs_assets'       => 'Needs Assets',
        'ready_to_announce'  => 'Ready to Announce',
        'published'          => 'Published',
        'advanced'           => 'Advanced',
        'completed'          => 'Completed',
        'settled'            => 'Settled',
        'canceled'           => 'Canceled',
    ];

    public const STATUS_COLORS = [
        'empty'              => '#9ca3af',
        'proposed'           => '#6b7280',
        'confirmed'          => '#2563eb',
        'booked'             => '#16a34a',
        'needs_assets'       => '#d97706',
        'ready_to_announce'  => '#7c3aed',
        'published'          => '#0891b2',
        'advanced'           => '#0891b2',
        'completed'          => '#16a34a',
        'settled'            => '#16a34a',
        'canceled'           => '#dc2626',
    ];

    /** Best-effort — never throws. */
    public static function statusChanged(Database $db, string $root, int $eventId, string $oldStatus, string $newStatus): void
    {
        try {
            $event = $db->one(
                'SELECT e.title, e.date, e.end_date, e.show_time, e.event_type,
                        e.promoter_name, e.promoter_email,
                        e.booker_name, e.booker_email, v.name AS venue_name
                   FROM events e
              LEFT JOIN venues v ON v.id = e.venue_id
                  WHERE e.id = ? LIMIT 1',
                [$eventId]
            );
            if (!$event) return;

            $isPrivate = ($event['event_type'] ?? '') === 'private_event';
            $link      = rtrim((string) (getenv('APP_URL') ?: ''), '/') . "/#event-{$eventId}";

            $showTime = '';
            if (!empty($event['show_time'])) {
                $t = strtotime((string) $event['show_time']);
                $showTime = $t ? date('g:i A', $t) : (string) $event['show_time'];
            }

            $mailer = new Mailer($root, $db);

            $newLabel    = self::STATUS_LABELS[$newStatus] ?? ucwords(str_replace('_', ' ', $newStatus));
            $oldLabel    = self::STATUS_LABELS[$oldStatus] ?? ucwords(str_replace('_', ' ', $oldStatus));
            $statusColor = self::STATUS_COLORS[$newStatus] ?? '#6b7280';

            // ── Always notify admins on any status change ─────────────────────
            $admins = $db->all(
                "SELECT name, email, notify_event_updates FROM users
                  WHERE role = 'venue_admin'
                    AND email IS NOT NULL AND email != '' AND email NOT LIKE '%.local'"
            );

            // Include VENUE_MANAGER_EMAIL in the admin recipient list, deduped.
            $adminRecipients = [];
            foreach ($admins as $a) {
                $adminRecipients[strtolower(trim((string) $a['email']))] = $a;
            }
            $mgEmail = trim((string) (getenv('VENUE_MANAGER_EMAIL') ?: ''));
            $mgName  = trim((string) (getenv('VENUE_MANAGER_NAME') ?: 'Venue Manager'));
            if ($mgEmail && filter_var($mgEmail, FILTER_VALIDATE_EMAIL)) {
                $adminRecipients[strtolower($mgEmail)] ??= ['name' => $mgName, 'email' => $mgEmail];
            }

            if ($adminRecipients) {
                $eventLabel  = $isPrivate ? "Private Event — {$newLabel}" : $newLabel;
                $subject     = "[Backstage] Status changed to {$eventLabel}: {$event['title']}";
                $adminVars   = [
                    'event_name'      => htmlspecialchars((string) $event['title'],                                 ENT_QUOTES, 'UTF-8'),
                    'old_status'      => htmlspecialchars($oldLabel,                                                ENT_QUOTES, 'UTF-8'),
                    'new_status'      => htmlspecialchars($eventLabel,                                              ENT_QUOTES, 'UTF-8'),
                    'status_color'    => $statusColor,
                    'event_date'      => htmlspecialchars(!empty($event['end_date']) ? "{$event['date']} – {$event['end_date']}" : (string) $event['date'], ENT_QUOTES, 'UTF-8'),
                    'event_time'      => $showTime !== '' ? htmlspecialchars($showTime, ENT_QUOTES, 'UTF-8') : '—',
                    'event_venue'     => htmlspecialchars((string) ($event['venue_name'] ?? getenv('VENUE_NAME') ?: 'Venue'),     ENT_QUOTES, 'UTF-8'),
                    'promoter_name'   => htmlspecialchars((string) ($event['promoter_name'] ?? '—'),               ENT_QUOTES, 'UTF-8'),
                    'booker_name'     => $isPrivate
                        ? 'N/A (private event)'
                        : htmlspecialchars((string) ($event['booker_name'] ?? '—'), ENT_QUOTES, 'UTF-8'),
                    'event_admin_url' => htmlspecialchars($link,                                                    ENT_QUOTES, 'UTF-8'),
                ];
                foreach ($adminRecipients as $recipient) {
                    if (!NotificationPreferences::wants($recipient, NotificationPreferences::EVENT_UPDATES)) {
                        continue;
                    }
                    $mailer->sendTemplate($recipient['email'], $subject, 'status-changed', $adminVars);
                }
            }

            // ── Booked: also notify the client for private events ─────────────
            if ($newStatus === 'booked' && $isPrivate
                && !empty($event['promoter_email'])
                && filter_var($event['promoter_email'], FILTER_VALIDATE_EMAIL)
            ) {
                $clientVars = [
                    'event_name'      => htmlspecialchars((string) $event['title'],                             ENT_QUOTES, 'UTF-8'),
                    'old_status'      => 'Pending',
                    'new_status'      => 'Confirmed & Booked',
                    'status_color'    => '#16a34a',
                    'event_date'      => htmlspecialchars(!empty($event['end_date']) ? "{$event['date']} – {$event['end_date']}" : (string) $event['date'], ENT_QUOTES, 'UTF-8'),
                    'event_time'      => $showTime !== '' ? htmlspecialchars($showTime, ENT_QUOTES, 'UTF-8') : '—',
                    'event_venue'     => htmlspecialchars((string) ($event['venue_name'] ?? getenv('VENUE_NAME') ?: 'Venue'), ENT_QUOTES, 'UTF-8'),
                    'promoter_name'   => htmlspecialchars((string) ($event['promoter_name'] ?? 'You'),         ENT_QUOTES, 'UTF-8'),
                    'booker_name'     => getenv('VENUE_NAME') ?: 'Venue',
                    'event_admin_url' => htmlspecialchars($link,                                                ENT_QUOTES, 'UTF-8'),
                ];
                $mailer->sendTemplate(
                    $event['promoter_email'],
                    '[' . (getenv('VENUE_NAME') ?: 'Backstage') . "] Your event is confirmed: {$event['title']}",
                    'status-changed',
                    $clientVars
                );
            }

            // ── Needs Assets: notify producer/artist + booker (public events only) ──
            if ($newStatus === 'needs_assets' && !$isPrivate) {
                $assetsVars = [
                    'event_name'      => htmlspecialchars((string) $event['title'],                             ENT_QUOTES, 'UTF-8'),
                    'event_date'      => htmlspecialchars(!empty($event['end_date']) ? "{$event['date']} – {$event['end_date']}" : (string) $event['date'], ENT_QUOTES, 'UTF-8'),
                    'event_time'      => $showTime !== '' ? htmlspecialchars($showTime, ENT_QUOTES, 'UTF-8') : '—',
                    'event_venue'     => htmlspecialchars((string) ($event['venue_name'] ?? getenv('VENUE_NAME') ?: 'Venue'), ENT_QUOTES, 'UTF-8'),
                    'event_admin_url' => htmlspecialchars($link,                                                ENT_QUOTES, 'UTF-8'),
                ];
                $externalRecipients = array_filter([
                    $event['promoter_email'] ? ['name' => $event['promoter_name'] ?? 'Producer/Artist', 'email' => $event['promoter_email']] : null,
                    $event['booker_email']   ? ['name' => $event['booker_name']   ?? 'Booker',          'email' => $event['booker_email']]   : null,
                ]);
                foreach ($externalRecipients as $recipient) {
                    if (!filter_var($recipient['email'], FILTER_VALIDATE_EMAIL)) continue;
                    $mailer->sendTemplate(
                        $recipient['email'],
                        '[' . (getenv('VENUE_NAME') ?: 'Backstage') . "] Promo materials needed: {$event['title']}",
                        'needs-assets',
                        $assetsVars + ['recipient_name' => htmlspecialchars((string) $recipient['name'], ENT_QUOTES, 'UTF-8')]
                    );
                }
            }
        } catch (\Throwable $e) {
            @error_log("status-change notification failed for event {$eventId}: {$e->getMessage()}");
        }
    }

    /** Notify all venue_admins immediately when a new private event inquiry is created. Best-effort — never throws. */
    public static function privateEventCreated(Database $db, string $root, int $eventId): void
    {
        try {
            $admins = $db->all("SELECT name, email, notify_event_updates FROM users WHERE role = 'venue_admin' AND email IS NOT NULL AND email != '' AND email NOT LIKE '%.local'");
            if (!$admins) return;

            $event = $db->one(
                'SELECT e.title, e.date, e.end_date, e.doors_time, e.show_time, e.end_time,
                        e.promoter_name, e.promoter_email, e.promoter_phone,
                        e.client_org, e.estimated_guests, e.capacity,
                        e.av_requirements, e.catering_notes,
                        v.name AS venue_name
                   FROM events e
              LEFT JOIN venues v ON v.id = e.venue_id
                  WHERE e.id = ? LIMIT 1',
                [$eventId]
            );
            if (!$event) return;

            $link    = rtrim((string) (getenv('APP_URL') ?: ''), '/') . "/#event-{$eventId}";
            $subject = "[Backstage] New private event inquiry: {$event['title']}";

            $showTime = '';
            if (!empty($event['doors_time'])) {
                $t = strtotime((string) $event['doors_time']);
                $showTime = $t ? date('g:i A', $t) : (string) $event['doors_time'];
            }

            $vars = [
                'event_name'       => htmlspecialchars((string) $event['title'],                       ENT_QUOTES, 'UTF-8'),
                'event_date'       => htmlspecialchars(!empty($event['end_date']) ? "{$event['date']} – {$event['end_date']}" : (string) $event['date'], ENT_QUOTES, 'UTF-8'),
                'event_time'       => $showTime !== '' ? htmlspecialchars($showTime, ENT_QUOTES, 'UTF-8') : '—',
                'event_venue'      => htmlspecialchars((string) ($event['venue_name'] ?? getenv('VENUE_NAME') ?: 'Venue'), ENT_QUOTES, 'UTF-8'),
                'client_name'      => htmlspecialchars((string) ($event['promoter_name'] ?? '—'),     ENT_QUOTES, 'UTF-8'),
                'client_email'     => htmlspecialchars((string) ($event['promoter_email'] ?? '—'),    ENT_QUOTES, 'UTF-8'),
                'client_phone'     => htmlspecialchars((string) ($event['promoter_phone'] ?? '—'),    ENT_QUOTES, 'UTF-8'),
                'client_org'       => htmlspecialchars((string) ($event['client_org'] ?? '—'),        ENT_QUOTES, 'UTF-8'),
                'estimated_guests' => htmlspecialchars((string) ($event['estimated_guests'] ?? '—'),  ENT_QUOTES, 'UTF-8'),
                'av_requirements'  => htmlspecialchars((string) ($event['av_requirements'] ?? 'None noted'), ENT_QUOTES, 'UTF-8'),
                'catering_notes'   => htmlspecialchars((string) ($event['catering_notes'] ?? 'None noted'),  ENT_QUOTES, 'UTF-8'),
                'event_admin_url'  => htmlspecialchars($link,                                         ENT_QUOTES, 'UTF-8'),
            ];

            $mailer = new Mailer($root, $db);
            foreach ($admins as $admin) {
                if (!NotificationPreferences::wants($admin, NotificationPreferences::EVENT_UPDATES)) {
                    continue;
                }
                $mailer->sendTemplate($admin['email'], $subject, 'private-event-inquiry', $vars);
            }
        } catch (\Throwable $e) {
            @error_log("private-event-created notification failed for event {$eventId}: {$e->getMessage()}");
        }
    }
}
