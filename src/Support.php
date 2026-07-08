<?php
declare(strict_types=1);

namespace Panic;

function slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-') ?: 'item';
}

function boolish(mixed $value): int
{
    return in_array($value, [1, '1', true, 'true', 'on', 'yes'], true) ? 1 : 0;
}

function date_or_null(mixed $value): ?string
{
    return $value ? (string) $value : null;
}

/**
 * Parse a DATETIME/TIMESTAMP string read back from the DB into a Unix epoch,
 * treating it as UTC. The DB session is pinned to UTC (see Database.php /
 * Database/Connection.php), independent of the app's display timezone
 * (America/Los_Angeles, set in bootstrap.php for human-facing formatting).
 *
 * Use this — not bare strtotime() — anywhere a DB timestamp produced by
 * NOW()/CURRENT_TIMESTAMP is compared against time() or another epoch (token
 * expiry, rate-limit windows, etc). strtotime() on an unsuffixed string
 * parses it in the ambient default timezone, which would silently skew the
 * comparison by the offset between UTC and America/Los_Angeles.
 */
function db_timestamp_to_epoch(?string $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    $ts = strtotime($value . ' UTC');
    return $ts !== false ? $ts : null;
}

/**
 * Relative path (no host) to an event's public-facing page, keyed by the
 * event's stable numeric id rather than its slug. Slugs are regenerated
 * whenever an event's title or date changes (see Events::update()), which
 * silently broke any link built from the old slug — the id never changes.
 *
 * Callers that need an absolute URL should prefix this with their own
 * app-base URL (see Feed::eventUrl(), EventEmailComposer::eventUrl(),
 * PublicTickets::checkout(), Events\GenerateQr::publicUrl()).
 */
function event_public_path(array $event): string
{
    return 'event.html?id=' . rawurlencode((string) $event['id']);
}

function log_activity(Database $db, int $eventId, ?int $userId, string $action, array $details = []): void
{
    $db->run(
        'INSERT INTO event_activity_log (event_id, user_id, action, details_json) VALUES (?, ?, ?, ?)',
        [$eventId, $userId, $action, json_encode($details)]
    );
}
