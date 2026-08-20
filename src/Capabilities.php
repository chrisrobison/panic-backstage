<?php
declare(strict_types=1);

namespace Panic;

/**
 * Pure capability-checking logic: role → allowed global actions, and
 * (event id, acting user, role) → per-event role + capability map.
 *
 * Extracted out of BaseEndpoint (which is now a thin delegator to this
 * class — see its docblock) so a second, non-HTTP process can enforce the
 * exact same rules a normal request would. Concretely: scripts/ai-mcp-server.php
 * (the AI Assistant's MCP tool server) is spawned directly by the `claude`
 * CLI, not through Kernel — it has no Request/Endpoint/Auth/JWT to hang a
 * capability check off of, only a plain (user id, role) pair handed to it
 * via env vars by src/Ai/Assistant.php (which already validated the JWT for
 * this HTTP request). Without this extraction, that server would need its
 * own copy of EVENT_CAPABILITIES/GLOBAL_CAPABILITIES/eventAccess(), and the
 * two copies drifting apart over time is exactly the kind of bug that lets
 * an AI-driven request see more than the same admin could see by hand.
 *
 * Every method here is static and takes its inputs explicitly (Database,
 * event id, user id, role) rather than reading from $this — that's what
 * makes it callable from a process with no request/session state at all.
 */
final class Capabilities
{
    public const EVENT_CAPABILITIES = [
        'venue_admin' => [
            'read_event', 'edit_event', 'publish_event', 'delete_event',
            'manage_lineup', 'manage_tasks', 'manage_schedule', 'manage_open_items',
            'upload_assets', 'manage_assets', 'manage_invites',
            'manage_guest_list', 'manage_staffing',
            'view_settlement', 'edit_settlement',
            'view_contracts', 'manage_contracts', 'approve_contracts',
            'manage_ticketing',
            // New capabilities
            'manage_payments', 'waive_deposit',
            'manage_vendors',
            'manage_ledger', 'finalize_closeout',
            'view_execution', 'manage_execution',
            'view_incidents', 'manage_incidents',
            // Owner reassignment (issue #32 follow-up): venue admins only —
            // "we don't want that to be a feature a booker/anyone can edit".
            'reassign_owner',
        ],
        'event_owner' => [
            'read_event', 'edit_event', 'publish_event', 'delete_event',
            'manage_lineup', 'manage_tasks', 'manage_schedule', 'manage_open_items',
            'upload_assets', 'manage_assets', 'manage_invites',
            'manage_guest_list', 'manage_staffing',
            'view_settlement', 'edit_settlement',
            'view_contracts', 'manage_contracts', 'approve_contracts',
            'manage_ticketing',
            // New capabilities
            'manage_payments',
            'manage_vendors',
            'manage_ledger',
            'view_execution', 'manage_execution',
        ],
        'promoter' => [
            'read_event', 'edit_event', 'manage_lineup', 'manage_tasks', 'manage_schedule',
            'manage_open_items', 'manage_guest_list', 'manage_staffing', 'view_public_page',
            'view_contracts',
            'view_execution',
        ],
        'band' => ['read_event', 'upload_assets', 'view_assigned_tasks'],
        'artist' => ['read_event', 'upload_assets', 'view_assigned_tasks'],
        'designer' => ['read_event', 'upload_assets', 'manage_assets'],
        'staff' => [
            'read_event', 'manage_tasks', 'manage_schedule', 'manage_open_items',
            'manage_guest_list', 'manage_staffing',
            'view_execution', 'manage_execution',
        ],
        'viewer' => ['read_event'],
        // global_viewer: read-only access to every event — no edits, no publishing, no admin actions.
        'global_viewer' => ['read_event', 'view_settlement', 'view_contracts', 'view_public_page', 'view_execution'],
    ];

    public const EVENT_CAPABILITY_KEYS = [
        'read_event', 'edit_event', 'publish_event', 'delete_event',
        'manage_lineup', 'manage_tasks', 'manage_schedule', 'manage_open_items',
        'upload_assets', 'manage_assets', 'manage_invites',
        'manage_guest_list', 'manage_staffing',
        'view_settlement', 'edit_settlement', 'view_public_page', 'view_assigned_tasks',
        'view_contracts', 'manage_contracts', 'approve_contracts',
        'manage_ticketing',
        // New capabilities
        'manage_payments', 'waive_deposit',
        'manage_vendors',
        'manage_ledger', 'finalize_closeout',
        'view_execution', 'manage_execution',
        'view_incidents', 'manage_incidents',
        'reassign_owner',
    ];

    public const GLOBAL_CAPABILITIES = [
        'venue_admin' => [
            'view_all_events', 'create_events', 'manage_templates', 'manage_users',
            'manage_staff_roster', 'manage_contract_library', 'view_all_contracts',
            'manage_contacts', 'manage_campaigns',
            // New global capabilities
            'manage_leads', 'view_leads',
            'manage_crm_profiles',
            'manage_venue_policy',
            'manage_systems_inventory',
            'admin_credential_encryption',
            'reopen_settlement',
            'manage_db_history',
            'view_reports',
            'manage_navigation',
            'manage_settings',
            'manage_processes',
            'view_processes',
            'manage_tasks_app',
            'view_tasks_app',
            // AI Assistant drawer (Phase 1: read-only Q&A) — venue_admin only.
            'use_ai_assistant',
            // Booking Inbox — Venue administrator: full pipeline control
            'view_booking_inbox', 'manage_booking_inbox', 'manage_assigned_leads',
            'claim_leads', 'override_lead_claims', 'manage_lead_routing',
            'decline_high_value_leads', 'export_leads', 'view_lead_audit',
            'manage_social_queue', 'view_social_queue', 'publish_social',
        ],
        'event_owner' => [
            'view_leads', 'manage_tasks_app', 'view_tasks_app',
            // Booking Inbox — Trusted booker
            'view_booking_inbox', 'manage_booking_inbox', 'claim_leads',
            'decline_high_value_leads',
            'view_social_queue', 'manage_social_queue', 'publish_social',
        ],
        'promoter' => [
            // Booking Inbox — Restricted external booker: row-scoped (assigned/
            // owned/watched only — enforced in SQL WHERE, not by this flag
            // alone) claim + limited write access; no routing, no override,
            // no export, no audit view, no unrestricted decline.
            'view_booking_inbox', 'claim_leads', 'manage_assigned_leads',
        ],
        'band' => [],
        'artist' => [],
        'designer' => [],
        'staff' => [
            'view_leads', 'view_processes', 'manage_tasks_app', 'view_tasks_app',
            // Booking Inbox — Trusted booker
            'view_booking_inbox', 'manage_booking_inbox', 'claim_leads',
            'decline_high_value_leads',
            'view_social_queue', 'manage_social_queue', 'publish_social',
        ],
        'viewer' => [],
        'global_viewer' => [
            'view_all_events', 'view_leads', 'view_reports', 'view_processes', 'view_tasks_app',
            'view_booking_inbox', 'view_lead_audit', 'view_social_queue',
        ],
    ];

    public static function hasGlobal(string $role, string $capability): bool
    {
        return in_array($capability, self::GLOBAL_CAPABILITIES[$role] ?? [], true);
    }

    /** Full {capability: bool} map for $role — used by /api/me to hand the frontend a gating payload. */
    public static function globalCapabilities(string $role): array
    {
        $roleCapabilities = self::GLOBAL_CAPABILITIES[$role] ?? [];
        $capabilities = [];
        foreach (array_unique(array_merge(...array_values(self::GLOBAL_CAPABILITIES))) as $capability) {
            $capabilities[$capability] = in_array($capability, $roleCapabilities, true);
        }
        return $capabilities;
    }

    /**
     * Resolve $userId's per-event role + capability map for $eventId, given
     * their global $role. Returns null when the event doesn't exist or the
     * user has no standing on it at all (not a "denied" — a "not visible").
     *
     * @return array{role: string, capabilities: array<string, bool>}|null
     */
    public static function eventAccess(Database $db, int $eventId, ?int $userId, string $role): ?array
    {
        $event = $db->one('SELECT id, owner_user_id FROM events WHERE id = ?', [$eventId]);
        if (!$event || !$userId) {
            return null;
        }

        $eventRole = null;
        if ($role === 'venue_admin') {
            $eventRole = 'venue_admin';
        } elseif ($role === 'global_viewer') {
            $eventRole = 'global_viewer';
        } elseif ((int) ($event['owner_user_id'] ?? 0) === $userId) {
            $eventRole = 'event_owner';
        } else {
            $collaborator = $db->one(
                'SELECT role FROM event_collaborators WHERE event_id = ? AND user_id = ? LIMIT 1',
                [$eventId, $userId]
            );
            $eventRole = $collaborator['role'] ?? null;
        }

        if (!$eventRole) {
            return null;
        }

        return [
            'role' => $eventRole,
            'capabilities' => self::capabilitiesForEventRole($eventRole),
        ];
    }

    /** Convenience: does $userId (as $role) have $capability on $eventId? */
    public static function hasEvent(Database $db, int $eventId, ?int $userId, string $role, string $capability): bool
    {
        $access = self::eventAccess($db, $eventId, $userId, $role);
        return (bool) ($access['capabilities'][$capability] ?? false);
    }

    public static function capabilitiesForEventRole(string $role): array
    {
        $allowed = self::EVENT_CAPABILITIES[$role] ?? [];
        $capabilities = self::emptyEventCapabilities();
        foreach ($allowed as $capability) {
            $capabilities[$capability] = true;
        }
        if (!($capabilities['view_public_page'] ?? false) && ($capabilities['publish_event'] ?? false)) {
            $capabilities['view_public_page'] = true;
        }
        return $capabilities;
    }

    public static function emptyEventCapabilities(): array
    {
        return array_fill_keys(self::EVENT_CAPABILITY_KEYS, false);
    }
}
