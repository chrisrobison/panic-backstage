<?php
declare(strict_types=1);

namespace Panic\Ai;

use Panic\Capabilities;
use Panic\Database;

/**
 * Shared logic behind the AI Assistant's `propose_booker_update` tool and
 * its apply step — see docs/AI-ASSISTANT-PLAN.md, "Shared apply logic".
 *
 * Split into three pieces used by two different callers at two different
 * times:
 *   - matchEvents() + buildDiff(): called from scripts/ai-mcp-server.php
 *     (the propose tool) to compute what *would* change, read-only, before
 *     any human has approved anything.
 *   - apply(): called only from Ai\Assistant::applyBookerUpdate(), itself
 *     only reachable via a human clicking "Apply" on a stored proposal —
 *     never a model-callable tool. This is the one method in this class
 *     that writes to the database.
 *
 * ALLOWED_FIELDS is enforced here, server-side, regardless of what the
 * model's tool call or a stored proposal's args_json contains — a crafted
 * prompt asking to also change `status` or any other column can't reach
 * apply() with anything outside this list, because sanitizeFields() is the
 * only path apply() accepts field data through.
 */
final class BookerUpdate
{
    public const ALLOWED_FIELDS = [
        'promoter_name', 'promoter_email', 'promoter_phone',
        'client_org', 'booker_name', 'booker_email', 'booker_phone',
    ];

    /** Bounds both the blast radius of a mistaken bulk update and the size of the diff shown for review. */
    public const MAX_EVENTS = 25;

    /** Which keys in $fields are NOT in ALLOWED_FIELDS — used to reject a propose call outright with a clear message. */
    public static function disallowedFields(array $fields): array
    {
        return array_values(array_diff(array_keys($fields), self::ALLOWED_FIELDS));
    }

    /** Keep only allowlisted keys, coerced to trimmed strings. The one gate every write in this class ultimately passes through. */
    public static function sanitizeFields(array $fields): array
    {
        $out = [];
        foreach (self::ALLOWED_FIELDS as $key) {
            if (array_key_exists($key, $fields)) {
                $out[$key] = trim((string) $fields[$key]);
            }
        }
        return $out;
    }

    /**
     * Resolve the events a propose_booker_update call targets — by explicit
     * id list, or by a promoter-name substring filter — filtered down to
     * only the ones $userId (as $role) currently has edit_event on. An
     * event the actor can't edit is silently excluded here, never surfaced
     * as a partial-access error: the resulting proposal simply won't
     * mention it, exactly as if it didn't match the filter at all.
     *
     * @return list<array<string, mixed>> capped at MAX_EVENTS, deterministic
     *     order (most recent event date first)
     */
    public static function matchEvents(Database $db, string $role, int $userId, array $eventIds, ?string $promoterNameFilter): array
    {
        $cols = 'id, title, date, ' . implode(', ', self::ALLOWED_FIELDS);
        $eventIds = array_values(array_unique(array_filter(array_map('intval', $eventIds), static fn(int $id): bool => $id > 0)));

        if ($eventIds) {
            $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
            $rows = $db->all("SELECT {$cols} FROM events WHERE id IN ({$placeholders}) ORDER BY date DESC", $eventIds);
        } elseif ($promoterNameFilter !== null && trim($promoterNameFilter) !== '') {
            $rows = $db->all(
                "SELECT {$cols} FROM events WHERE promoter_name LIKE ? ORDER BY date DESC LIMIT " . self::MAX_EVENTS,
                ['%' . trim($promoterNameFilter) . '%']
            );
        } else {
            return [];
        }

        $editable = [];
        foreach ($rows as $row) {
            if (Capabilities::hasEvent($db, (int) $row['id'], $userId, $role, 'edit_event')) {
                $editable[] = $row;
            }
            if (count($editable) >= self::MAX_EVENTS) {
                break;
            }
        }
        return $editable;
    }

    /**
     * Before → after diff for each matched event, allowlisted fields only.
     * Purely a read of already-fetched rows — no DB access, no writes.
     *
     * @return list<array{event_id: int, title: string, fields: array<string, array{before: ?string, after: string}>}>
     */
    public static function buildDiff(array $matchedEvents, array $fields): array
    {
        $fields = self::sanitizeFields($fields);
        $diff = [];
        foreach ($matchedEvents as $event) {
            $changes = [];
            foreach ($fields as $key => $newValue) {
                $changes[$key] = ['before' => $event[$key] ?? null, 'after' => $newValue];
            }
            $diff[] = [
                'event_id' => (int) $event['id'],
                'title' => (string) $event['title'],
                'fields' => $changes,
            ];
        }
        return $diff;
    }

    /**
     * The actual write. Only ever called from Ai\Assistant::applyBookerUpdate(),
     * itself only reachable by a human clicking "Apply" against a stored,
     * not-yet-expired proposal — see the class docblock. $actingUserId is
     * recorded via log_activity() by the caller, not here.
     *
     * @return int number of event rows updated
     */
    public static function apply(Database $db, array $eventIds, array $fields): int
    {
        $fields = self::sanitizeFields($fields);
        $eventIds = array_values(array_unique(array_filter(array_map('intval', $eventIds), static fn(int $id): bool => $id > 0)));
        if (!$fields || !$eventIds) {
            return 0;
        }

        $setSql = implode(', ', array_map(static fn(string $key): string => "{$key} = ?", array_keys($fields)));
        $params = array_values($fields);
        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $params = array_merge($params, $eventIds);

        return $db->run("UPDATE events SET {$setSql} WHERE id IN ({$placeholders})", $params);
    }
}
