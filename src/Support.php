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

function log_activity(Database $db, int $eventId, ?int $userId, string $action, array $details = []): void
{
    $db->run(
        'INSERT INTO event_activity_log (event_id, user_id, action, details_json) VALUES (?, ?, ?, ?)',
        [$eventId, $userId, $action, json_encode($details)]
    );
}
