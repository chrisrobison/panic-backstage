<?php
declare(strict_types=1);

namespace Panic;

/**
 * Shared "which contacts match these criteria" WHERE-clause builder against
 * the unqualified `contacts` table. Extracted so the same matching logic can
 * be reused everywhere a set of contacts needs to be resolved from a filter
 * rather than picked one at a time:
 *
 *   - Contacts::index()               the Contacts browse/search page
 *   - MailingLists::addMembersByFilter() "add all N matching" bulk add
 *   - MailingLists::refreshSegment()     smart/segment list membership sync
 *
 * Criteria keys are all optional and are read as loosely-typed scalars (e.g.
 * straight off a Request's query/body array), so callers can pass query
 * params, decoded JSON, or a hand-built array interchangeably:
 *
 *   q           free text, LIKE across name/email/phone
 *   opted       '0'|'1' (or 0|1), exact match on marketing_opted_in
 *   min_spend   numeric, usd_spend >= value
 *   min_events  numeric, events_count >= value
 *   min_tickets numeric, tickets_count >= value
 */
final class ContactFilters
{
    /** @return array{where: string, params: array} */
    public static function buildWhere(array $criteria): array
    {
        $where = [];
        $params = [];

        $q = trim((string) ($criteria['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(first_name LIKE ? OR last_name LIKE ? OR CONCAT(first_name, " ", last_name) LIKE ? OR email LIKE ? OR phone LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }

        $opted = $criteria['opted'] ?? null;
        if ($opted === '0' || $opted === '1' || $opted === 0 || $opted === 1) {
            $where[] = 'marketing_opted_in = ?';
            $params[] = (int) $opted;
        }

        foreach ([
            'min_spend'   => 'usd_spend',
            'min_events'  => 'events_count',
            'min_tickets' => 'tickets_count',
        ] as $key => $column) {
            $value = $criteria[$key] ?? null;
            if ($value !== null && $value !== '' && is_numeric($value)) {
                $where[] = "{$column} >= ?";
                $params[] = (float) $value;
            }
        }

        return [
            'where'  => $where ? (' WHERE ' . implode(' AND ', $where)) : '',
            'params' => $params,
        ];
    }

    /** True if the criteria array has at least one recognized, non-empty rule. */
    public static function hasAnyRule(array $criteria): bool
    {
        return self::buildWhere($criteria)['where'] !== '';
    }
}
