<?php
declare(strict_types=1);

namespace Panic;

abstract class BaseEndpoint implements Endpoint
{
    private const EVENT_CAPABILITIES = [
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

    private const EVENT_CAPABILITY_KEYS = [
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
    ];

    private const GLOBAL_CAPABILITIES = [
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

    private array $eventAccessCache = [];

    public function __construct(
        protected readonly Database $db,
        protected readonly Auth $auth,
        protected readonly array $params = [],
        protected readonly string $root = ''
    ) {}

    protected function userId(): ?int
    {
        return isset($this->auth->user()['id']) ? (int) $this->auth->user()['id'] : null;
    }

    protected function requireEventId(): int
    {
        $id = $this->params['eventId'] ?? null;
        if (!$id) {
            throw new \InvalidArgumentException('Event id is required');
        }
        return (int) $id;
    }

    protected function ok(array $payload = []): Response
    {
        return Response::json($payload);
    }

    protected function notFound(string $message = 'Not found'): Response
    {
        return Response::json(['error' => $message], 404);
    }

    protected function forbidden(string $message = 'Forbidden'): Response
    {
        return Response::json(['error' => $message], 403);
    }

    protected function role(): string
    {
        return (string) ($this->auth->user()['role'] ?? 'viewer');
    }

    protected function isVenueAdmin(): bool
    {
        return $this->role() === 'venue_admin';
    }

    protected function isGlobalViewer(): bool
    {
        return $this->role() === 'global_viewer';
    }

    protected function hasGlobalCapability(string $capability): bool
    {
        return in_array($capability, self::GLOBAL_CAPABILITIES[$this->role()] ?? [], true);
    }

    protected function globalCapabilities(): array
    {
        $roleCapabilities = self::GLOBAL_CAPABILITIES[$this->role()] ?? [];
        $capabilities = [];
        foreach (array_unique(array_merge(...array_values(self::GLOBAL_CAPABILITIES))) as $capability) {
            $capabilities[$capability] = in_array($capability, $roleCapabilities, true);
        }
        return $capabilities;
    }

    protected function requireAuth(string $message = 'Authentication required'): ?Response
    {
        return $this->userId() === null
            ? Response::json(['error' => $message], 401)
            : null;
    }

    protected function requireGlobalCapability(string $capability): ?Response
    {
        return $this->hasGlobalCapability($capability) ? null : $this->forbidden();
    }

    protected function eventAccess(int $eventId): ?array
    {
        if (array_key_exists($eventId, $this->eventAccessCache)) {
            return $this->eventAccessCache[$eventId];
        }

        $event = $this->db->one('SELECT id, owner_user_id FROM events WHERE id = ?', [$eventId]);
        if (!$event || !$this->userId()) {
            return $this->eventAccessCache[$eventId] = null;
        }

        $role = null;
        if ($this->isVenueAdmin()) {
            $role = 'venue_admin';
        } elseif ($this->isGlobalViewer()) {
            $role = 'global_viewer';
        } elseif ((int) ($event['owner_user_id'] ?? 0) === $this->userId()) {
            $role = 'event_owner';
        } else {
            $collaborator = $this->db->one('SELECT role FROM event_collaborators WHERE event_id = ? AND user_id = ? LIMIT 1', [$eventId, $this->userId()]);
            $role = $collaborator['role'] ?? null;
        }

        if (!$role) {
            return $this->eventAccessCache[$eventId] = null;
        }

        return $this->eventAccessCache[$eventId] = [
            'role' => $role,
            'capabilities' => $this->capabilitiesForEventRole($role),
        ];
    }

    protected function eventCapabilities(int $eventId): array
    {
        return $this->eventAccess($eventId)['capabilities'] ?? $this->emptyEventCapabilities();
    }

    protected function hasEventCapability(int $eventId, string $capability): bool
    {
        $access = $this->eventAccess($eventId);
        return (bool) ($access['capabilities'][$capability] ?? false);
    }

    protected function requireEventCapability(int $eventId, string $capability): ?Response
    {
        $access = $this->eventAccess($eventId);
        if (!$access) {
            return $this->notFound('Event not found');
        }
        return ($access['capabilities'][$capability] ?? false) ? null : $this->forbidden();
    }

    protected function eventScopeSql(string $eventAlias = 'e'): array
    {
        if ($this->isVenueAdmin() || $this->isGlobalViewer()) {
            return ['1=1', []];
        }
        return [
            "($eventAlias.owner_user_id = ? OR EXISTS (SELECT 1 FROM event_collaborators ec_scope WHERE ec_scope.event_id = $eventAlias.id AND ec_scope.user_id = ?))",
            [$this->userId(), $this->userId()],
        ];
    }

    /**
     * SQL WHERE fragment + bound params restricting a `leads`-aliased query
     * to rows a Restricted external booker may see (assigned/owned/claimed/
     * watched) — same shape and purpose as eventScopeSql() above, for the
     * Booking Inbox (src/LeadsInbox.php, src/Inbox.php). Venue admins,
     * global viewers, and anyone with manage_booking_inbox (Trusted booker)
     * see everything.
     *
     * @return array{0: string, 1: list<int>}
     */
    protected function leadScopeSql(string $leadAlias = 'l'): array
    {
        if ($this->isVenueAdmin() || $this->isGlobalViewer() || $this->hasGlobalCapability('manage_booking_inbox')) {
            return ['1=1', []];
        }
        $me = (int) $this->userId();
        return [
            "($leadAlias.assigned_to_user_id = ? OR $leadAlias.owner_user_id = ? OR $leadAlias.claimed_by_user_id = ?"
                . " OR $leadAlias.point_person_id = ? OR EXISTS (SELECT 1 FROM lead_watchers lw WHERE lw.lead_id = $leadAlias.id AND lw.user_id = ?))",
            [$me, $me, $me, $me, $me],
        ];
    }

    protected function assignmentUsersForEvent(int $eventId): array
    {
        if ($this->isVenueAdmin()) {
            return $this->db->all('SELECT id, name, email, role FROM users WHERE is_hidden = 0 ORDER BY name');
        }

        return $this->db->all(
            'SELECT DISTINCT u.id, u.name, u.email, COALESCE(ec.role, u.role) role
             FROM users u
             LEFT JOIN event_collaborators ec ON ec.user_id = u.id AND ec.event_id = ?
             JOIN events e ON e.id = ?
             WHERE u.id = e.owner_user_id OR ec.id IS NOT NULL
             ORDER BY u.name',
            [$eventId, $eventId]
        );
    }

    protected function accessibleUsers(): array
    {
        if ($this->isVenueAdmin()) {
            return $this->db->all('SELECT id, name, email, role FROM users WHERE is_hidden = 0 ORDER BY name');
        }

        return $this->db->all(
            'SELECT DISTINCT u.id, u.name, u.email, u.role
             FROM users u
             JOIN events e ON e.owner_user_id = u.id
             WHERE e.owner_user_id = ? OR EXISTS (SELECT 1 FROM event_collaborators ec WHERE ec.event_id = e.id AND ec.user_id = ?)
             UNION
             SELECT DISTINCT u.id, u.name, u.email, ec.role
             FROM users u
             JOIN event_collaborators ec ON ec.user_id = u.id
             JOIN events e ON e.id = ec.event_id
             WHERE e.owner_user_id = ? OR EXISTS (SELECT 1 FROM event_collaborators mine WHERE mine.event_id = e.id AND mine.user_id = ?)
             ORDER BY name',
            [$this->userId(), $this->userId(), $this->userId(), $this->userId()]
        );
    }

    private function capabilitiesForEventRole(string $role): array
    {
        $allowed = self::EVENT_CAPABILITIES[$role] ?? [];
        $capabilities = $this->emptyEventCapabilities();
        foreach ($allowed as $capability) {
            $capabilities[$capability] = true;
        }
        if (!($capabilities['view_public_page'] ?? false) && ($capabilities['publish_event'] ?? false)) {
            $capabilities['view_public_page'] = true;
        }
        return $capabilities;
    }

    private function emptyEventCapabilities(): array
    {
        return array_fill_keys(self::EVENT_CAPABILITY_KEYS, false);
    }
}
