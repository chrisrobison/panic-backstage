<?php
declare(strict_types=1);

namespace Panic;

/**
 * Translates one `db_history` row (see scripts/generate-audit-triggers.php)
 * into a safe, entity-scoped realtime invalidation for src/Realtime.php to
 * stream to the browser — see docs/realtime-data.md.
 *
 * SECURITY: this class may read `old_row`/`new_row` on the server ONLY to
 * recover a parent id (e.g. an `event_tasks` row's `event_id`). It must
 * NEVER return either of those, or any other column value, to its caller.
 * Realtime.php enforces that contract by construction — map() only ever
 * returns `{entity, id?}`.
 *
 * Three outcomes:
 *   - a known "direct" table (events, leads): {entity, id} from the row's
 *     own primary key.
 *   - a known "child" table (event_tasks, lead_messages, ...): {entity, id}
 *     of the PARENT, recovered from a foreign-key column in old_row/new_row.
 *   - anything else *that isn't explicitly ignored*: {entity: 'global'} —
 *     a safe, content-free "something changed" signal. This is the fallback
 *     the spec calls for rather than silently dropping unclassified tables;
 *     see the class docblock in src/Realtime.php for why 'global' is safe
 *     to broadcast to every connected client regardless of their
 *     permissions (it carries no identifying information at all).
 *
 * IGNORE lists tables that are both high-frequency and have zero UI signal
 * — auth/rate-limit plumbing that writes on nearly every request. Without
 * this, THOSE writes would dominate the 'global' fallback and turn every
 * connected client's cache into a near-constant-clear / cache-defeating
 * stream for no actual UI benefit. Extend this list rather than the CHILD
 * map if a newly-added high-frequency table turns out to need the same
 * treatment.
 */
final class RealtimeInvalidationMapper
{
    /** Tables whose own primary key IS the entity id. */
    private const DIRECT = [
        'events'        => 'event',
        'leads'         => 'lead',
        'opportunities' => 'opportunity',
    ];

    /**
     * Tables that carry a foreign key to their parent entity's id.
     * table_name => [entity, foreign-key column name]
     */
    private const CHILD = [
        // Events (see Kernel.php's `events` routing block for the same
        // family of sub-resources this mirrors).
        'event_activity_log'      => ['event', 'event_id'],
        'event_assets'            => ['event', 'event_id'],
        'event_blockers'          => ['event', 'event_id'],
        'event_closeout_state'    => ['event', 'event_id'],
        'event_collaborators'     => ['event', 'event_id'],
        'event_execution_records' => ['event', 'event_id'],
        'event_guest_list'        => ['event', 'event_id'],
        'event_invites'           => ['event', 'event_id'],
        'event_ledger_entries'    => ['event', 'event_id'],
        'event_lineup'            => ['event', 'event_id'],
        'event_payment_audit'     => ['event', 'event_id'],
        'event_payments'          => ['event', 'event_id'],
        'event_scanner_links'     => ['event', 'event_id'],
        'event_schedule_items'    => ['event', 'event_id'],
        'event_sessions'          => ['event', 'event_id'],
        'event_settlements'       => ['event', 'event_id'],
        'event_sheet_shadow'      => ['event', 'event_id'],
        'event_staffing'          => ['event', 'event_id'],
        'event_tasks'             => ['event', 'event_id'],
        'event_vendors'           => ['event', 'event_id'],
        'contracts'                => ['event', 'event_id'],
        // Leads / Booking Inbox (see src/LeadsInbox.php + Kernel.php's
        // `leads` routing block).
        'lead_messages'            => ['lead', 'lead_id'],
        'lead_notes'               => ['lead', 'lead_id'],
        'lead_deal_evaluations'    => ['lead', 'lead_id'],
        'lead_intake_emails'       => ['lead', 'lead_id'],
        'lead_watchers'            => ['lead', 'lead_id'],
        'lead_status_history'      => ['lead', 'lead_id'],
        'lead_approval_requests'   => ['lead', 'lead_id'],
        'lead_audit_log'           => ['lead', 'lead_id'],
        // Opportunities (Phase 5 — pipeline board + opportunity detail need
        // targeted refresh; see docs/OPPORTUNITIES-IMPLEMENTATION.md §1.9/§5
        // TODO). opportunity_signals is shared by three scopes (opportunity/
        // conference/company) but only ever has one FK column set per row —
        // fkFromRow() falls back to 'global' when opportunity_id is null,
        // which is exactly right for a signal scoped to a conference/company
        // instead.
        'opportunity_activities'      => ['opportunity', 'opportunity_id'],
        'opportunity_qualification'   => ['opportunity', 'opportunity_id'],
        'opportunity_decision_makers' => ['opportunity', 'opportunity_id'],
        'opportunity_signals'         => ['opportunity', 'opportunity_id'],
    ];

    /**
     * High-frequency, zero-UI-signal tables — never even emitted as
     * 'global'. See class docblock.
     */
    private const IGNORE = [
        'rate_limits',
        'refresh_tokens',
        'magic_link_tokens',
        'email_verification_tokens',
        'webauthn_challenges',
        'portal_tokens',
    ];

    /**
     * @param array<string,mixed> $row A db_history row: table_name,
     *                                 pk_value, old_row, new_row (JSON
     *                                 strings or already-decoded arrays).
     * @return array{entity:string,id?:int}|null Null only for IGNORE-listed
     *                                            tables (nothing is emitted
     *                                            for those at all).
     */
    public static function map(array $row): ?array
    {
        $table = (string) ($row['table_name'] ?? '');

        if (in_array($table, self::IGNORE, true)) {
            return null;
        }

        if (isset(self::DIRECT[$table])) {
            $id = self::pkFromRow($row);
            return $id !== null ? ['entity' => self::DIRECT[$table], 'id' => $id] : ['entity' => 'global'];
        }

        if (isset(self::CHILD[$table])) {
            [$entity, $fk] = self::CHILD[$table];
            $id = self::fkFromRow($row, $fk);
            return $id !== null ? ['entity' => $entity, 'id' => $id] : ['entity' => 'global'];
        }

        // Unmapped table: a safe, content-free "something changed" signal
        // rather than silence — see class docblock.
        return ['entity' => 'global'];
    }

    private static function pkFromRow(array $row): ?int
    {
        $value = $row['pk_value'] ?? null;
        return $value !== null && ctype_digit((string) $value) ? (int) $value : null;
    }

    private static function fkFromRow(array $row, string $column): ?int
    {
        // UPDATE rows have both; INSERT has only new_row; DELETE has only
        // old_row. Either one carries the same parent id in practice
        // (the FK itself is never the field being changed), so trying
        // new_row first and falling back to old_row covers all three.
        foreach (['new_row', 'old_row'] as $key) {
            $raw = $row[$key] ?? null;
            if ($raw === null) {
                continue;
            }
            $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);
            if (is_array($decoded) && isset($decoded[$column]) && is_numeric($decoded[$column])) {
                return (int) $decoded[$column];
            }
        }
        return null;
    }
}
